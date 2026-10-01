<?php

namespace App\Support\Tournaments;

use App\Enums\NotificationKind;
use App\Enums\TournamentStatus;
use App\Events\TournamentChanged;
use App\Models\Admin;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentMatchSlot;
use App\Models\TournamentModerationEntry;
use App\Models\TournamentParticipant;
use App\Models\TournamentResultEntry;
use App\Models\User;
use App\Support\Chess\Broadcasts;
use App\Support\Notifications\Notice;
use App\Support\Notifications\Notifier;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * The result of a lobby (plan "AoE2 und Trackmania", P10, Lobbies): places,
 * not a winner of two sides. Place 1 may be shared (the allies still
 * standing at the end); everyone else has a place of their own, in the order
 * they were defeated, numbered as a shared place counts (two share place 1,
 * the next is place 3).
 *
 * - A player of the lobby reports the places with a screenshot of the end
 *   screen (kept on the private `local` disk, shown to the directors only),
 *   until the lobby's `report_by` ({@see Lobbies::reportBy()}). A later
 *   report replaces the earlier one.
 * - A director or admin without a stake in ANY entry of the tournament
 *   (TournamentInterest::ofTournament(): the lobbies share one pot) confirms
 *   the report they were shown, or rejects it with a reason, or enters the
 *   places themselves; after the deadline too. A report swapped after the
 *   director saw it is refused (reportIdentity()).
 * - At the deadline the directors (a cup: the admins) are told once; a lobby
 *   still undecided NO_RESULT_AFTER_HOURS later is closed without a result
 *   (nobody of it placed, a moderation log line), so nothing waits forever
 *   (tick(), from TournamentScheduler). A player report is never confirmed
 *   by the league.
 * - End screens are stored re-encoded (no EXIF) and deleted
 *   SCREENSHOT_KEEP_DAYS after the lobby was decided or called off.
 * - A lobby with at most one entry left that is not out (disqualified, or
 *   withdrawn where the players report) is decided by the league
 *   ({@see leagueDecision()}, TournamentRunner::forfeitWithdrawn()).
 *
 * The stored result is the engine's ranked result (`ranks` per slot) with
 * `unplaced` for the entries that are out: they win nothing
 * (App\Support\Payouts\TournamentPlacements). Lobby results move no Elo and
 * are not attested: a rating is between two sides.
 */
final class LobbyResults
{
    /** Largest screenshot taken, in kilobytes. */
    public const SCREENSHOT_MAX_KB = 8192;

    /** Largest screenshot taken, in pixels (a 4K end screen is 8.3 million): no decompression bomb reaches GD. */
    public const SCREENSHOT_MAX_PIXELS = 40_000_000;

    /** A lobby past its report deadline is closed without a result this many hours later (security audit P10, F3). */
    public const NO_RESULT_AFTER_HOURS = 24;

    /** End screens are deleted this many days after their lobby was decided or its tournament called off. */
    public const SCREENSHOT_KEEP_DAYS = 30;

    public function __construct(private TournamentRunner $runner, private Notifier $notifier) {}

    /**
     * Whether the user plays in this lobby and may report it: a member of
     * an entry of it that is not out (disqualified, or withdrawn where the
     * players report).
     */
    public static function plays(TournamentMatch $match, User $user): bool
    {
        $tournament = $match->tournament;

        return $match->slots->contains(fn (TournamentMatchSlot $slot): bool => $slot->participant !== null
            && in_array($user->id, $slot->participant->memberIds(), true) && ! self::isOut($tournament, $slot->participant));
    }

    /**
     * Whether the user may confirm, reject or enter this lobby's places: a
     * director of the tournament or an admin, without a stake in ANY entry
     * of the tournament (security audit P10, F1): every lobby pays from the
     * one pot, so a player of lobby A deciding lobby B moves his own share.
     */
    public static function mayDecide(Tournament $tournament, TournamentMatch $match, ?User $user): bool
    {
        return $user !== null && Gate::forUser($user)->allows('direct-tournament', $tournament)
            && ! TournamentInterest::ofTournament($tournament, $user, followAppointers: ! $user->isAdmin());
    }

    /**
     * The identity of a report as a director saw it: places, reporter, time
     * and end screen (security audit P10, F2). Confirm and reject carry it;
     * a report swapped since is refused.
     *
     * @param  array<string, mixed>|null  $report
     */
    public static function reportIdentity(?array $report): string
    {
        if ($report === null) {
            return '';
        }

        $places = (array) ($report['places'] ?? []);
        ksort($places);

        return hash('sha256', (string) json_encode([$places, $report['user_id'] ?? null, $report['at'] ?? null, $report['screenshot'] ?? null]));
    }

    /** Until when the players report; null for a lobby without a deadline. */
    public static function reportBy(TournamentMatch $match): ?CarbonImmutable
    {
        $at = $match->lobby['report_by'] ?? null;

        return is_string($at) ? CarbonImmutable::parse($at) : null;
    }

    public static function reportOpen(TournamentMatch $match): bool
    {
        $by = self::reportBy($match);

        return $by === null || $by->isFuture();
    }

    /**
     * A player reports the places with the end screen's screenshot. The
     * picture is stored re-encoded (no EXIF, no GPS) and deleted again when
     * the report is refused, so no file outlives a refusal.
     *
     * @param  array<int|string, mixed>  $places  participant id => place
     *
     * @throws TournamentRuleViolation
     */
    public function report(TournamentMatch $match, User $user, array $places, UploadedFile $screenshot): void
    {
        $match = $this->open($match);

        if (! self::plays($match, $user)) {
            throw new TournamentRuleViolation('not_in_lobby', __('Only a player of this lobby can report its places.'));
        }

        if (! self::reportOpen($match)) {
            throw new TournamentRuleViolation('report_closed', __('The time to report this lobby is over. A tournament director decides it now.'));
        }

        $parsed = self::places($match, $places);
        $path = self::storeScreenshot($match, $screenshot);

        try {
            $replaced = DB::transaction(function () use ($match, $user, $parsed, $path): ?string {
                Tournament::query()->lockForUpdate()->findOrFail($match->tournament_id);
                $locked = TournamentMatch::query()->lockForUpdate()->findOrFail($match->id);

                if ($locked->result !== null) {
                    throw new TournamentRuleViolation('decided', __('This lobby is decided already.'));
                }

                $previous = $locked->lobby_report['screenshot'] ?? null;
                $locked->forceFill(['lobby_report' => [
                    'places' => array_map(intval(...), $parsed['places']),
                    'user_id' => $user->id,
                    'name' => mb_substr($user->displayName(), 0, 80),
                    'at' => now()->toIso8601String(),
                    'screenshot' => $path,
                ]])->save();

                return is_string($previous) ? $previous : null;
            });
        } catch (Throwable $e) {
            // Not accepted: the new file goes with it (N1, no orphans).
            Storage::disk('local')->delete($path);

            throw $e;
        }

        if ($replaced !== null) {
            Storage::disk('local')->delete($replaced);
        }

        Broadcasts::send(new TournamentChanged($match->tournament_id, 'result'));
    }

    /**
     * A director confirms the report they were shown (`$shown`, its
     * reportIdentity()); a report changed since is refused.
     *
     * @throws TournamentRuleViolation
     */
    public function confirm(TournamentMatch $match, User $director, string $shown): void
    {
        $match = $this->open($match);
        $this->assertDecider($match, $director);
        $this->decide($match, $director, null, $shown);
    }

    /**
     * A director enters (or overrides) the places, with or without a report;
     * after the players' deadline too.
     *
     * @param  array<int|string, mixed>  $places
     *
     * @throws TournamentRuleViolation
     */
    public function enter(TournamentMatch $match, User $director, array $places): void
    {
        $match = $this->open($match);
        $this->assertDecider($match, $director);
        $this->decide($match, $director, $places, null);
    }

    /**
     * A director rejects the report they were shown: it is gone, the players
     * see the reason and may report again while the time to report runs.
     * Under the same locks as a confirmation, so of a confirm and a reject at
     * once only one applies.
     *
     * @throws TournamentRuleViolation
     */
    public function reject(TournamentMatch $match, User $director, string $reason, string $shown): void
    {
        $match = $this->open($match);
        $this->assertDecider($match, $director);
        $reason = trim($reason);

        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 300) {
            throw new TournamentRuleViolation('reason', __('Give a reason of 3 to 300 characters: the players see it.'));
        }

        $screenshot = DB::transaction(function () use ($match, $director, $reason, $shown): ?string {
            Tournament::query()->lockForUpdate()->findOrFail($match->tournament_id);
            $locked = TournamentMatch::query()->lockForUpdate()->findOrFail($match->id);
            $this->assertSameReport($locked, $shown);

            $screenshot = $locked->lobby_report['screenshot'] ?? null;
            $locked->forceFill([
                'lobby_report' => null,
                'lobby' => [...(array) $locked->lobby, 'rejected' => ['name' => mb_substr($director->displayName(), 0, 80), 'at' => now()->toIso8601String(), 'reason' => $reason]],
            ])->save();

            return is_string($screenshot) ? $screenshot : null;
        });

        if ($screenshot !== null) {
            Storage::disk('local')->delete($screenshot);
        }

        Broadcasts::send(new TournamentChanged($match->tournament_id, 'result'));
    }

    /**
     * The scheduler's step (TournamentScheduler::tick()): a lobby past its
     * report deadline tells the people who decide it once; one still
     * undecided NO_RESULT_AFTER_HOURS later is closed without a result, so
     * the tournament (and a cup series) moves on; end screens past their
     * keeping time are deleted. A waiting player report is never confirmed
     * by the league.
     *
     * @return array{overdue: int, closed: int, pruned: int}
     */
    public function tick(): array
    {
        $done = ['overdue' => 0, 'closed' => 0, 'pruned' => 0];
        $open = TournamentMatch::query()->whereNotNull('lobby')->whereNull('result')->where('status', 'ready')
            ->whereHas('tournament', fn ($query) => $query->where('status', TournamentStatus::Running)->whereNull('paused_at'))
            ->with(['tournament', 'slots.participant'])->orderBy('id')->get();

        foreach ($open as $match) {
            $by = self::reportBy($match);

            if ($by === null || $by->isFuture()) {
                continue;
            }

            try {
                if ($by->addHours(self::NO_RESULT_AFTER_HOURS)->isPast()) {
                    $done['closed'] += $this->closeWithoutResult($match) ? 1 : 0;
                } elseif (! isset($match->lobby['overdue_at'])) {
                    $done['overdue'] += $this->tellOverdue($match) ? 1 : 0;
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        // The end screens: at most once an hour is plenty for a 30-day keep.
        if (Cache::add('lobby-results:pruned', true, now()->addHour())) {
            $done['pruned'] = self::pruneScreenshots();
        }

        return $done;
    }

    /**
     * Tell the people who decide an overdue lobby, once: the admins for a
     * casual cup (the league runs it), the directors otherwise.
     */
    private function tellOverdue(TournamentMatch $match): bool
    {
        $marked = DB::transaction(function () use ($match): bool {
            $locked = TournamentMatch::query()->lockForUpdate()->findOrFail($match->id);

            if ($locked->result !== null || isset($locked->lobby['overdue_at'])) {
                return false;
            }

            $locked->forceFill(['lobby' => [...(array) $locked->lobby, 'overdue_at' => now()->toIso8601String()]])->save();

            return true;
        });

        if (! $marked) {
            return false;
        }

        $tournament = $match->tournament;
        $deciders = $tournament->isCasualCup()
            ? User::query()->whereIn('pubkey', Admin::query()->pluck('pubkey'))->get()
            : User::query()->whereKey(array_filter([$tournament->created_by_id, ...$tournament->directors()->pluck('users.id')->all()]))->get();
        $closesAt = self::reportBy($match)?->addHours(self::NO_RESULT_AFTER_HOURS);

        foreach ($deciders as $user) {
            $locale = (string) ($user->locale ?? config('app.locale'));
            $this->notifier->send($user, NotificationKind::TournamentNews, new Notice(
                __(':tournament: lobby :number waits for a decision', ['tournament' => $tournament->name, 'number' => $match->position], $locale),
                __('Its players had until now to report. Confirm their report or enter the places on its card; otherwise the league closes the lobby without a result at :time.', [
                    'time' => $closesAt?->setTimezone((string) ($user->timezone ?? config('esports.preseason.display_timezone')))->format('D j M, H:i') ?? '',
                ], $locale),
                route('tournaments.show', $tournament).'#bracket', null, __('Open tournament', [], $locale)));
        }

        return true;
    }

    /**
     * Close a lobby nobody decided in time: nobody of it is placed (no share
     * of the pot comes from it), a line goes to the moderation log, and the
     * bracket moves, so the tournament can finish.
     */
    private function closeWithoutResult(TournamentMatch $match): bool
    {
        $closed = DB::transaction(function () use ($match): bool {
            $tournament = Tournament::query()->lockForUpdate()->findOrFail($match->tournament_id);
            $locked = TournamentMatch::query()->with('slots')->lockForUpdate()->findOrFail($match->id);

            if ($locked->result !== null || $locked->status !== 'ready') {
                return false;
            }

            $ranks = [];
            $next = 0;

            foreach ($locked->slots as $slot) {
                $ranks[$slot->slot] = ++$next;
            }

            ksort($ranks);
            $this->runner->store($locked, [
                'winner' => null,
                'ranks' => array_values($ranks),
                'unplaced' => array_values(array_filter($locked->slots->pluck('tournament_participant_id')->map(fn ($id): ?int => $id === null ? null : (int) $id)->all())),
                'games_won' => [],
                'points' => [],
                'forfeit' => true,
                'decided' => 'no_result',
                'label' => 'closed without a result',
                'lobby_label' => 'none',
                'by' => 'league',
            ]);

            TournamentModerationEntry::query()->create([
                'tournament_id' => $tournament->id,
                'user_id' => null,
                'user_name' => 'League',
                'action' => 'lobby_no_result',
                'subject' => mb_substr(__('Lobby :number', ['number' => $locked->position]), 0, 80),
                'reason' => 'Nobody decided the lobby within '.self::NO_RESULT_AFTER_HOURS.' hours after its report deadline; nobody of it is placed.',
                'details' => null,
                'created_at' => now(),
            ]);

            return true;
        });

        if ($closed) {
            $this->runner->sync($match->tournament->refresh(), 'result');
        }

        return $closed;
    }

    /**
     * Delete the end screens of lobbies decided, or of tournaments called
     * off, more than SCREENSHOT_KEEP_DAYS ago. Idempotent: a deleted folder
     * is gone, a second run finds nothing.
     */
    public static function pruneScreenshots(): int
    {
        $before = now()->subDays(self::SCREENSHOT_KEEP_DAYS);
        $pruned = 0;
        $matches = TournamentMatch::query()->whereNotNull('lobby')
            ->where(fn ($query) => $query->where(fn ($q) => $q->whereNotNull('result')->where('updated_at', '<', $before))
                ->orWhereHas('tournament', fn ($q) => $q->where('status', TournamentStatus::Cancelled)->where('updated_at', '<', $before)))
            ->pluck('id');

        foreach ($matches as $id) {
            $directory = "lobby-results/{$id}";

            if (Storage::disk('local')->directoryExists($directory)) {
                Storage::disk('local')->deleteDirectory($directory);
                $pruned++;
            }
        }

        return $pruned;
    }

    /**
     * The end screen stored on the private disk, re-encoded as PNG with GD so
     * no EXIF or GPS of the player's device is kept. Without GD the bytes are
     * stored as sent (logged once a day, so it is seen).
     *
     * @throws TournamentRuleViolation
     */
    private static function storeScreenshot(TournamentMatch $match, UploadedFile $screenshot): string
    {
        $refused = new TournamentRuleViolation('screenshot', __('Add a screenshot of the end screen: PNG, JPEG or WebP, up to 8 MB.'));

        if (! in_array($screenshot->getMimeType(), ['image/png', 'image/jpeg', 'image/webp'], true) || $screenshot->getSize() > self::SCREENSHOT_MAX_KB * 1024) {
            throw $refused;
        }

        $size = @getimagesize((string) $screenshot->getRealPath());

        if ($size === false || $size[0] < 1 || $size[1] < 1 || $size[0] * $size[1] > self::SCREENSHOT_MAX_PIXELS) {
            throw $refused;
        }

        $directory = "lobby-results/{$match->id}";

        if (! function_exists('imagecreatefromstring')) {
            if (Cache::add('lobby-results:no-gd-logged', true, now()->addDay())) {
                Log::warning('Lobby end screens are stored as sent: GD is missing, so EXIF data is not stripped.');
            }

            $path = $screenshot->store($directory, 'local');

            if (! is_string($path)) {
                throw new TournamentRuleViolation('screenshot', __('The screenshot could not be saved. Try again.'));
            }

            return $path;
        }

        $image = @imagecreatefromstring((string) file_get_contents((string) $screenshot->getRealPath()));

        if ($image === false) {
            throw $refused;
        }

        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        imagedestroy($image);

        $path = $directory.'/'.Str::random(40).'.png';

        if (! Storage::disk('local')->put($path, $png)) {
            throw new TournamentRuleViolation('screenshot', __('The screenshot could not be saved. Try again.'));
        }

        return $path;
    }

    /**
     * @throws TournamentRuleViolation
     */
    private function assertSameReport(TournamentMatch $locked, string $shown): void
    {
        if ($locked->result !== null) {
            throw new TournamentRuleViolation('decided', __('This lobby is decided already.'));
        }

        if ($locked->lobby_report === null) {
            throw new TournamentRuleViolation('no_report', __('Nobody has reported this lobby yet.'));
        }

        if (! hash_equals(self::reportIdentity($locked->lobby_report), $shown)) {
            throw new TournamentRuleViolation('report_changed', __('The report changed — look again.'));
        }
    }

    /**
     * The places as the engine stores them, checked: every entry of the
     * lobby that is not out gets a place; place 1 at least once; every other
     * place once, numbered after the shared ones (competition ranking: two
     * on place 1, then 3, 4 …). Entries that are out are left out of the
     * check and come last, unplaced.
     *
     * @param  array<int|string, mixed>  $places  participant id => place
     * @return array{places: array<int, int>, ranks: list<int>, unplaced: list<int>}
     *
     * @throws TournamentRuleViolation
     */
    public static function places(TournamentMatch $match, array $places): array
    {
        $tournament = $match->tournament;
        $active = [];
        $unplaced = [];

        foreach ($match->slots as $slot) {
            $participant = $slot->participant;

            if ($participant === null) {
                continue;
            }

            if (self::isOut($tournament, $participant)) {
                $unplaced[] = $participant->id;

                continue;
            }

            $value = $places[$participant->id] ?? $places[(string) $participant->id] ?? null;

            if (! is_numeric($value) || (int) $value < 1 || (int) $value > count($match->slots)) {
                throw new TournamentRuleViolation('places', __('Give every player of the lobby a place.'));
            }

            $active[$participant->id] = (int) $value;
        }

        $winners = count(array_filter($active, fn (int $place): bool => $place === 1));
        $others = array_values(array_filter($active, fn (int $place): bool => $place !== 1));
        sort($others);

        if ($winners === 0 || ($others !== [] && $others !== range($winners + 1, $winners + count($others)))) {
            throw new TournamentRuleViolation('places', __('Place 1 can be shared; every other place once, in order after the winners (two winners: then 3, 4 …).'));
        }

        // The entries that are out rank after everyone, in slot order: they are unplaced anyway.
        $next = count($active);
        $ranks = [];

        foreach ($match->slots as $slot) {
            $id = $slot->tournament_participant_id;
            $ranks[$slot->slot] = $id !== null && isset($active[$id]) ? $active[$id] : ++$next;
        }

        ksort($ranks);

        return ['places' => $active, 'ranks' => array_values($ranks), 'unplaced' => $unplaced];
    }

    /**
     * The league decides a lobby with at most one entry left that is not
     * out: that one alone has place 1, the others are unplaced.
     *
     * @param  list<int>  $outSlots
     * @return array<string, mixed>
     */
    public static function leagueDecision(TournamentMatch $match, array $outSlots): array
    {
        $ranks = [];
        $unplaced = [];
        $next = 1;

        foreach ($match->slots as $slot) {
            $ranks[$slot->slot] = in_array($slot->slot, $outSlots, true) ? 0 : 1;
        }

        foreach ($ranks as $slot => $rank) {
            if ($rank === 0) {
                $ranks[$slot] = ++$next;
                $id = $match->slots->firstWhere('slot', $slot)?->tournament_participant_id;

                if ($id !== null) {
                    $unplaced[] = $id;
                }
            }
        }

        ksort($ranks);
        $ranks = array_values($ranks);
        $winner = array_search(1, $ranks, true);

        return [
            'winner' => is_int($winner) ? $winner : null,
            'ranks' => $ranks,
            'unplaced' => $unplaced,
            'games_won' => [],
            'points' => [],
            'forfeit' => true,
            'decided' => 'withdrawn',
            // Stored in English for the logs; every page words it in the viewer's language (describe()).
            'label' => 'decided by the league',
            'lobby_label' => 'league',
            'by' => 'league',
        ];
    }

    /**
     * A lobby result in words, in the current language: "Shared place 1:
     * A, B", "Place 1: A", "decided by the league". Results stored before
     * the kind was kept fall back to their stored label.
     *
     * @param  array<string, mixed>  $result
     */
    public static function describe(array $result): string
    {
        $names = implode(', ', array_map(strval(...), (array) ($result['winner_names'] ?? [])));

        return match ($result['lobby_label'] ?? null) {
            'shared' => __('Shared place 1: :names', ['names' => $names]),
            'single' => __('Place 1: :name', ['name' => $names]),
            'league' => __('decided by the league'),
            'none' => __('closed without a result'),
            default => (string) ($result['label'] ?? ''),
        };
    }

    /**
     * The match, read anew with its slots, if its places can still be set.
     *
     * @throws TournamentRuleViolation
     */
    private function open(TournamentMatch $match): TournamentMatch
    {
        $match = TournamentMatch::query()->with(['slots.participant', 'tournament'])->findOrFail($match->id);

        if ($match->lobby === null) {
            throw new TournamentRuleViolation('not_lobby', __('This match is no lobby.'));
        }

        if ($match->tournament->status !== TournamentStatus::Running || $match->tournament->isPaused()) {
            throw new TournamentRuleViolation('not_running', __('This tournament is not running.'));
        }

        if ($match->result !== null || $match->status !== 'ready') {
            throw new TournamentRuleViolation('decided', __('This lobby is decided already.'));
        }

        return $match;
    }

    /**
     * @throws TournamentRuleViolation
     */
    private function assertDecider(TournamentMatch $match, User $user): void
    {
        if (! Gate::forUser($user)->allows('direct-tournament', $match->tournament)) {
            throw new TournamentRuleViolation('not_director', __('Only the tournament directors can decide a lobby.'));
        }

        if (! self::mayDecide($match->tournament, $match, $user)) {
            throw new TournamentRuleViolation('interested', __('You have an interest in this tournament (you play in it, belong to a clan in it, or were named by someone who does): its lobbies share one pot, so another director or an admin has to decide it.'));
        }
    }

    /**
     * Store the places as the lobby's result, log the entry, move the
     * bracket. `$shown` set: the report the director was shown, compared
     * under the lock, and its places are the result; else `$places`.
     *
     * @param  array<int|string, mixed>|null  $places
     *
     * @throws TournamentRuleViolation
     */
    private function decide(TournamentMatch $match, User $director, ?array $places, ?string $shown): void
    {
        $names = $match->slots->mapWithKeys(fn (TournamentMatchSlot $slot): array => [(int) $slot->tournament_participant_id => (string) $slot->participant?->name])->all();
        $now = now();

        DB::transaction(function () use ($match, $director, $places, $shown, $names, $now): void {
            Tournament::query()->lockForUpdate()->findOrFail($match->tournament_id);
            $locked = TournamentMatch::query()->lockForUpdate()->findOrFail($match->id);

            if ($locked->result !== null) {
                throw new TournamentRuleViolation('decided', __('This lobby is decided already.'));
            }

            if ($shown !== null) {
                $this->assertSameReport($locked, $shown);
            }

            $report = $locked->lobby_report;
            $parsed = self::places($match, $shown !== null && $report !== null ? $report['places'] : (array) $places);
            $winners = array_keys(array_filter($parsed['places'], fn (int $place): bool => $place === 1));
            $winnerSlot = array_search(1, $parsed['ranks'], true);
            $winnerNames = array_map(fn (int $id): string => $names[$id] ?? '', $winners);

            $result = [
                'winner' => is_int($winnerSlot) ? $winnerSlot : null,
                'ranks' => $parsed['ranks'],
                'unplaced' => $parsed['unplaced'],
                'games_won' => [],
                'points' => [],
                'forfeit' => false,
                // The kind and the names, worded at render time in the viewer's language (describe()); `label` stays English for the logs.
                'lobby_label' => count($winners) > 1 ? 'shared' : 'single',
                'winner_names' => $winnerNames,
                'label' => (count($winners) > 1 ? 'Shared place 1: ' : 'Place 1: ').implode(', ', $winnerNames),
                'by' => 'director',
                'user_id' => $director->id,
                'name' => mb_substr($director->displayName(), 0, 80),
                'at' => $now->toIso8601String(),
                // Who reported, when, and the end screen the director saw (private; never published).
                'reported' => $report === null ? null : ['user_id' => $report['user_id'], 'name' => $report['name'], 'at' => $report['at'], 'screenshot' => $report['screenshot']],
            ];

            $this->runner->store($locked, $result);
            TournamentMatch::query()->whereKey($locked->id)->update(['lobby_report' => null]);

            TournamentResultEntry::query()->create([
                'tournament_id' => $locked->tournament_id,
                'tournament_match_id' => $locked->id,
                'user_id' => $director->id,
                'user_name' => mb_substr($director->displayName(), 0, 80),
                'result' => $result,
                'replaced' => null,
                'created_at' => $now,
            ]);
        });

        $this->runner->sync($match->tournament->refresh(), 'result');
    }

    /** Disqualified, or withdrawn (no account left) where the players report. */
    private static function isOut(Tournament $tournament, TournamentParticipant $participant): bool
    {
        return $participant->isDisqualified() || (! $tournament->isDirectorMode() && TournamentRunner::isWithdrawn($participant));
    }
}
