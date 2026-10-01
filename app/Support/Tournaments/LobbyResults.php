<?php

namespace App\Support\Tournaments;

use App\Enums\TournamentStatus;
use App\Events\TournamentChanged;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentMatchSlot;
use App\Models\TournamentParticipant;
use App\Models\TournamentResultEntry;
use App\Models\User;
use App\Support\Chess\Broadcasts;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

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
 * - A director or admin without a stake in the lobby (TournamentInterest)
 *   confirms the report, or rejects it with a reason, or enters the places
 *   themselves; that works after the deadline too, so a lobby nobody
 *   reported never holds the tournament up.
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

    public function __construct(private TournamentRunner $runner) {}

    /** Whether the user plays in this lobby. */
    public static function plays(TournamentMatch $match, User $user): bool
    {
        return $match->slots->contains(fn (TournamentMatchSlot $slot): bool => in_array($user->id, $slot->participant?->memberIds() ?? [], true));
    }

    /**
     * Whether the user may confirm, reject or enter this lobby's places: a
     * director of the tournament or an admin, without a stake in the lobby.
     */
    public static function mayDecide(Tournament $tournament, TournamentMatch $match, ?User $user): bool
    {
        return $user !== null && Gate::forUser($user)->allows('direct-tournament', $tournament)
            && ! TournamentInterest::of($tournament, $match, $user, followAppointers: ! $user->isAdmin());
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
     * A player reports the places with the end screen's screenshot.
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

        if (! in_array($screenshot->getMimeType(), ['image/png', 'image/jpeg', 'image/webp'], true) || $screenshot->getSize() > self::SCREENSHOT_MAX_KB * 1024) {
            throw new TournamentRuleViolation('screenshot', __('Add a screenshot of the end screen: PNG, JPEG or WebP, up to 8 MB.'));
        }

        $path = $screenshot->store("lobby-results/{$match->id}", 'local');

        if (! is_string($path)) {
            throw new TournamentRuleViolation('screenshot', __('The screenshot could not be saved. Try again.'));
        }

        $replaced = DB::transaction(function () use ($match, $user, $parsed, $path): ?string {
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

        if ($replaced !== null) {
            Storage::disk('local')->delete($replaced);
        }

        Broadcasts::send(new TournamentChanged($match->tournament_id, 'result'));
    }

    /**
     * A director confirms the report as it stands.
     *
     * @throws TournamentRuleViolation
     */
    public function confirm(TournamentMatch $match, User $director): void
    {
        $match = $this->open($match);
        $this->assertDecider($match, $director);
        $report = $match->lobby_report;

        if ($report === null) {
            throw new TournamentRuleViolation('no_report', __('Nobody has reported this lobby yet.'));
        }

        $this->decide($match, $director, $report['places'], $report);
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
        $this->decide($match, $director, $places, is_array($match->lobby_report) ? $match->lobby_report : null);
    }

    /**
     * A director rejects the report: it is gone, the players see the reason
     * and may report again while the time to report runs.
     *
     * @throws TournamentRuleViolation
     */
    public function reject(TournamentMatch $match, User $director, string $reason): void
    {
        $match = $this->open($match);
        $this->assertDecider($match, $director);
        $reason = trim($reason);

        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 300) {
            throw new TournamentRuleViolation('reason', __('Give a reason of 3 to 300 characters: the players see it.'));
        }

        $screenshot = $match->lobby_report['screenshot'] ?? null;

        if (! is_array($match->lobby_report)) {
            throw new TournamentRuleViolation('no_report', __('Nobody has reported this lobby yet.'));
        }

        TournamentMatch::query()->whereKey($match->id)->update([
            'lobby_report' => null,
            'lobby' => json_encode([...(array) $match->lobby, 'rejected' => ['name' => mb_substr($director->displayName(), 0, 80), 'at' => now()->toIso8601String(), 'reason' => $reason]]),
        ]);

        if (is_string($screenshot)) {
            Storage::disk('local')->delete($screenshot);
        }

        Broadcasts::send(new TournamentChanged($match->tournament_id, 'result'));
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
            throw new TournamentRuleViolation('interested', __('You have an interest in this lobby (you play in it, belong to a clan in it, or were named by someone who does), so another director or an admin has to decide it.'));
        }
    }

    /**
     * Store the places as the lobby's result, log the entry, move the bracket.
     *
     * @param  array<int|string, mixed>  $places
     * @param  array<string, mixed>|null  $report
     *
     * @throws TournamentRuleViolation
     */
    private function decide(TournamentMatch $match, User $director, array $places, ?array $report): void
    {
        $parsed = self::places($match, $places);
        $names = $match->slots->mapWithKeys(fn (TournamentMatchSlot $slot): array => [(int) $slot->tournament_participant_id => (string) $slot->participant?->name])->all();
        $winners = array_keys(array_filter($parsed['places'], fn (int $place): bool => $place === 1));
        $winnerSlot = array_search(1, $parsed['ranks'], true);
        $now = now();

        $result = [
            'winner' => is_int($winnerSlot) ? $winnerSlot : null,
            'ranks' => $parsed['ranks'],
            'unplaced' => $parsed['unplaced'],
            'games_won' => [],
            'points' => [],
            'forfeit' => false,
            // The kind and the names, worded at render time in the viewer's language (describe()); `label` stays English for the logs.
            'lobby_label' => count($winners) > 1 ? 'shared' : 'single',
            'winner_names' => array_map(fn (int $id): string => $names[$id] ?? '', $winners),
            'label' => (count($winners) > 1 ? 'Shared place 1: ' : 'Place 1: ').implode(', ', array_map(fn (int $id): string => $names[$id] ?? '', $winners)),
            'by' => 'director',
            'user_id' => $director->id,
            'name' => mb_substr($director->displayName(), 0, 80),
            'at' => $now->toIso8601String(),
            // Who reported, when, and the end screen the director saw (private; never published).
            'reported' => $report === null ? null : ['user_id' => $report['user_id'] ?? null, 'name' => $report['name'] ?? null, 'at' => $report['at'] ?? null, 'screenshot' => $report['screenshot'] ?? null],
        ];

        DB::transaction(function () use ($match, $director, $result, $now): void {
            Tournament::query()->lockForUpdate()->findOrFail($match->tournament_id);
            $locked = TournamentMatch::query()->lockForUpdate()->findOrFail($match->id);

            if ($locked->result !== null) {
                throw new TournamentRuleViolation('decided', __('This lobby is decided already.'));
            }

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
