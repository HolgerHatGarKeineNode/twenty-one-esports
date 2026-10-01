<?php

namespace App\Support\Series;

use App\Enums\Platform;
use App\Enums\SeriesResolution;
use App\Enums\SeriesStatus;
use App\Events\SeriesMatchChanged;
use App\Games\GameRegistry;
use App\Models\ChessQueueEntry;
use App\Models\MatchNumber;
use App\Models\SeriesMatch;
use App\Models\User;
use App\Support\Board\BoardGameService;
use App\Support\Chess\Broadcasts;
use App\Support\Chess\ChessGameService;
use App\Support\Notifications\CasualNotifications;
use App\Support\Settings\LeagueSettings;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * A casual 1v1 without a clan (P23, slice S1) for the games of
 * `esports.casual.games`: a SeriesMatch with two roster sides of one player
 * each (as a tournament's RL 1v1), `origin` queue or invite, never rated.
 * The room, the report, the answer and the admin decision are the series'
 * own (SeriesService); this class adds what happens around them:
 *
 *   paired -> ready check (`ready_by`) -> started (`start_at`)
 *          -> host shares the lobby (`lobby_shared_at`, by `lobby_minutes`)
 *          -> guest joins (`joined_at`, by `join_minutes`)
 *          -> report (by `report_minutes`) -> confirm (by `confirm_minutes`)
 *
 * The server stores only flags, never the lobby: it travels in the
 * end-to-end encrypted match chat as a card (slice S2), and the guest's
 * client reports that it opened one (`lobby_seen_at`). The host side is
 * drawn at the pairing; a host whose signer fails hands it to the guest
 * once, before sharing ({@see swapHost()}).
 *
 * No-show: once the host let the lobby deadline pass the guest may claim a
 * no-show, once the guest let the join deadline pass the host may. One
 * claim per match. The accused side contests within `contest_minutes`
 * (the claim is withdrawn and the match goes on), else the league forfeits
 * the match to the claimer (CasualScheduler). Forfeited no-shows lock a
 * player out of casual play for a while ({@see lockedUntil()}).
 *
 * Every write is a conditional update on the row's own state, so a double
 * click, a second tab or the scheduler racing a player changes a match
 * once (SQLite takes the write lock at BEGIN, IMMEDIATE mode; the
 * conditions keep it right under any engine). Every change is announced
 * with SeriesMatchChanged on both players' channels.
 */
final class CasualMatches
{
    /** The states in which a casual 1v1 keeps its players busy (a disputed one waits for an admin). */
    public const RUNNING = [SeriesStatus::Accepted, SeriesStatus::Reported];

    public function __construct(
        private GameRegistry $games,
        private ChessGameService $chess,
        private CasualNotifications $notifications,
    ) {}

    /* ---------- Rules shared by the queue and the invites ---------------------------------------------------- */

    /**
     * @throws SeriesRuleViolation for a game without casual 1v1
     */
    public function assertGame(string $game): void
    {
        // Never a score game (plan "AoE2 und Trackmania", P4), even listed by mistake: it has no lobby and no pairing.
        if (! in_array($game, (array) config('esports.casual.games'), true) || $this->games->isScore($game) || $this->games->mode($game, self::mode()) === null) {
            throw self::refuse('unknown_game');
        }
    }

    public static function mode(): string
    {
        return (string) config('esports.casual.mode', '1v1');
    }

    /**
     * Same platform, or both allow crossplay and neither is a platform the
     * game never plays cross-platform on (`esports.casual.crossplay_excluded`).
     */
    public static function compatible(string $game, Platform $a, bool $crossplayA, Platform $b, bool $crossplayB): bool
    {
        if ($a === $b) {
            return true;
        }

        $excluded = (array) config('esports.casual.crossplay_excluded.'.$game, []);

        return $crossplayA && $crossplayB && ! in_array($a->value, $excluded, true) && ! in_array($b->value, $excluded, true);
    }

    /**
     * One live game at a time: a live chess game, a live board game (plan
     * "Mühle und Dame", P5) or a running casual 1v1 keeps a player out of
     * the queue and the invites. Null when free.
     */
    public function busyReason(User $user): ?string
    {
        if ($this->chess->activeGameOf($user) !== null || $this->activeMatchOf($user) !== null || app(BoardGameService::class)->activeGameOf($user) !== null) {
            return 'already_playing';
        }

        return null;
    }

    /**
     * @throws SeriesRuleViolation when the player is locked out or plays already
     */
    public function assertMayPlay(User $user): void
    {
        $until = $this->lockedUntil($user);

        if ($until !== null) {
            throw self::refuse('queue_locked', ['time' => $until->copy()->timezone($user->timezone ?? config('esports.preseason.display_timezone'))->format('H:i')]);
        }

        if (($reason = $this->busyReason($user)) !== null) {
            throw self::refuse($reason);
        }
    }

    /**
     * The casual 1v1 this player is in right now: in the ready check,
     * started, or waiting for the answer to a report. A disputed match waits
     * for an admin and blocks nothing. A scheduled one (P23 S4) counts from
     * the opening of its check-in window on; before, the player is free.
     */
    public function activeMatchOf(User $user): ?SeriesMatch
    {
        return self::runningMatchOf($user);
    }

    /**
     * {@see activeMatchOf()} for the chess side, which cannot take this
     * class as a dependency (it depends on ChessGameService): a player in a
     * running casual 1v1 starts no live chess game (`casual_playing`).
     */
    public static function runningMatchOf(User $user, ?int $except = null): ?SeriesMatch
    {
        return self::playedBy(SeriesMatch::query()->whereNotNull('origin'), $user)
            ->whereIn('status', self::RUNNING)
            ->where(fn (Builder $query) => $query->whereNotIn('origin', SeriesMatch::SCHEDULED_ORIGINS)
                ->orWhere('start_at', '<=', now()->addMinutes((int) config('esports.casual.checkin_before_minutes', 10))))
            ->when($except !== null, fn (Builder $query) => $query->whereKeyNot($except))
            ->latest('id')
            ->first();
    }

    /**
     * The end of this player's lock: `lock.noshows` forfeited no-shows in
     * the last `lock.window_hours` lock them out for `lock.minutes` after
     * the latest one. Null when not locked.
     */
    public function lockedUntil(User $user): ?CarbonInterface
    {
        $config = (array) LeagueSettings::get('esports.casual.lock');
        $noshows = max(1, (int) ($config['noshows'] ?? 2));

        // A casual cup's no-shows are the cup's business (P25 S3): they never lock the queue.
        $forfeits = SeriesMatch::query()->whereNotNull('origin')->where('origin', '!=', SeriesMatch::ORIGIN_CUP)
            ->where('resolution', SeriesResolution::Forfeit)
            ->where('finished_at', '>=', now()->subHours((int) ($config['window_hours'] ?? 24)))
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $q) => $q->where('winner', 'challenged')->whereIn('id', self::onSide($user, 'challenger')))
                ->orWhere(fn (Builder $q) => $q->where('winner', 'challenger')->whereIn('id', self::onSide($user, 'challenged'))))
            ->orderByDesc('finished_at')
            ->limit($noshows)
            ->get(['id', 'finished_at']);

        if ($forfeits->count() < $noshows) {
            return null;
        }

        $until = $forfeits->first()?->finished_at?->copy()->addMinutes((int) ($config['minutes'] ?? 30));

        return $until !== null && $until->isFuture() ? $until : null;
    }

    /**
     * @param  Builder<SeriesMatch>  $query
     * @return Builder<SeriesMatch>
     */
    public static function playedBy(Builder $query, User $user): Builder
    {
        return $query->whereIn('id', self::onSide($user));
    }

    /**
     * The ids of the series with this player on a roster side (or on the
     * given one): `series_match_players` mirrors `sides`, so this is an
     * index lookup instead of a JSON scan (as OpenMatches::involving()).
     */
    public static function onSide(User $user, ?string $side = null): QueryBuilder
    {
        return DB::table('series_match_players')->where('user_id', $user->id)
            ->when($side !== null, fn (QueryBuilder $query) => $query->where('side', $side))
            ->select('series_match_id');
    }

    /* ---------- Pairing ------------------------------------------------------------------------------------- */

    /**
     * The match of a pairing, in the ready check. Called inside the
     * pairing's transaction (CasualQueue, CasualInvites); the found-match
     * notification goes out with it. The match claims both players
     * ({@see claim()}), so a pairing that passed its busy check before
     * another one committed is refused (`already_playing`) and its
     * transaction rolls back.
     *
     * @param  array<string, array{platform: string, crossplay: bool}>  $choices  each side's platform and crossplay
     *
     * @throws SeriesRuleViolation when a player is claimed by another running match
     */
    public function create(User $challenger, User $challenged, string $game, string $origin, array $choices, ?User $createdBy = null): SeriesMatch
    {
        $now = now();
        $config = (array) LeagueSettings::get('esports.casual');

        $match = SeriesMatch::query()->create([
            ...$this->attributes($challenger, $challenged, $game, $origin, $createdBy),
            'status' => SeriesStatus::Accepted,
            'proposals' => [$now->getTimestamp()],
            'respond_by' => $now,
            'start_at' => null,
            'answered_at' => $now,
            'ready_by' => $now->copy()->addSeconds((int) $config['ready_seconds']),
            // Pinned now: a later config change reaches only later matches.
            'casual' => [...self::pinned(), 'queue' => $choices],
        ]);

        self::claim($match, [$challenger->id, $challenged->id]);

        // One intent at a time: a paired player stops searching blitz.
        ChessQueueEntry::query()->whereIn('user_id', [$challenger->id, $challenged->id])->delete();

        $this->notifications->matchFound($match);
        $this->announce($match);

        return $match;
    }

    /**
     * One running casual 1v1 per player, held by `casual_claims` (unique
     * user id) rather than by the busy check alone, which is a read: two
     * accepts of crossing invites, or an accept and a queue pairing, can
     * both read the player as free. A claim whose match left
     * {@see RUNNING} is taken over here, so no path that ends a match has
     * to release it, and a claim never outlives its match's hold on the
     * player.
     *
     * @param  list<int>  $userIds
     *
     * @throws SeriesRuleViolation when a player is claimed by another running match
     */
    private static function claim(SeriesMatch $match, array $userIds): void
    {
        DB::table('casual_claims')->whereIn('user_id', $userIds)
            ->whereNotIn('series_match_id', SeriesMatch::query()->select('id')->whereIn('status', self::RUNNING))
            ->delete();

        try {
            DB::table('casual_claims')->insert(array_map(fn (int $userId): array => ['user_id' => $userId, 'series_match_id' => $match->id, 'created_at' => now()], $userIds));
        } catch (UniqueConstraintViolationException) {
            throw self::refuse('already_playing');
        }
    }

    /**
     * An open scheduled challenge (P23 S4, CasualChallenges): the
     * challenger's platform choice is stored now, the opponent's with the
     * accept, when the deadlines are pinned too.
     *
     * @param  list<int>  $proposals  unix seconds, checked by SeriesService::schedule()
     * @param  array{platform: string, crossplay: bool}  $choice
     */
    public function createChallenge(User $challenger, User $challenged, string $game, array $proposals, CarbonInterface $respondBy, string $message, array $choice): SeriesMatch
    {
        $match = SeriesMatch::query()->create([
            ...$this->attributes($challenger, $challenged, $game, SeriesMatch::ORIGIN_CHALLENGE, $challenger),
            'status' => SeriesStatus::Open,
            'proposals' => $proposals,
            'respond_by' => $respondBy,
            'message' => $message === '' ? null : $message,
            'casual' => ['queue' => ['challenger' => $choice]],
        ]);

        $this->announce($match);

        return $match;
    }

    /**
     * The casual deadlines as they are now, pinned on a match.
     *
     * @return array<string, int>
     */
    public static function pinned(): array
    {
        $config = (array) LeagueSettings::get('esports.casual');

        return array_map(intval(...), array_intersect_key($config, array_flip([
            'ready_seconds', 'lobby_minutes', 'join_minutes', 'contest_minutes', 'report_minutes', 'confirm_minutes', 'checkin_before_minutes', 'checkin_after_minutes',
        ])));
    }

    /**
     * What every casual 1v1 carries: two player sides, unrated, the
     * shortest series of the mode, a host drawn at random.
     *
     * @return array<string, mixed>
     */
    private function attributes(User $challenger, User $challenged, string $game, string $origin, ?User $createdBy): array
    {
        $mode = $this->games->mode($game, self::mode()) ?? throw self::refuse('unknown_game');

        return [
            'number' => MatchNumber::query()->create(['user_id' => $challenger->id, 'used_at' => now()])->id,
            'game' => $game,
            'mode' => $mode->slug,
            // A casual 1v1 is the shortest series the mode allows (RL best of 3, FC best of 1).
            'best_of' => $mode->bestOf === [] ? 1 : min($mode->bestOf),
            'rated' => false,
            'challenger_lineup_id' => null,
            'challenged_lineup_id' => null,
            'challenger_name' => mb_substr($challenger->displayName(), 0, 255),
            'challenged_name' => mb_substr($challenged->displayName(), 0, 255),
            'challenger_tag' => self::tag($challenger),
            'challenged_tag' => self::tag($challenged),
            'challenger_lineup_address' => '',
            'challenged_lineup_address' => '',
            'created_by_id' => $createdBy?->id,
            'sides' => ['challenger' => [$challenger->id], 'challenged' => [$challenged->id]],
            'origin' => $origin,
            'host_side' => SeriesMatch::SIDES[random_int(0, 1)],
        ];
    }

    /* ---------- Check-in (scheduled, P23 S4) ------------------------------------------------------------------ */

    /**
     * This player is here for the agreed start: from `checkin_before_minutes`
     * before it until `checkin_after_minutes` after (`ready_by`). A player
     * who plays something else at that moment finishes it first. Once both
     * are in, the match starts (`start_at` now) and runs as an instant one.
     * Checking in twice changes nothing.
     *
     * @throws SeriesRuleViolation
     */
    public function checkIn(SeriesMatch $match, User $user): SeriesMatch
    {
        $match = $match->fresh() ?? $match;
        $side = $this->sideOf($match, $user);

        if (! $match->awaitsCheckIn()) {
            throw self::refuse('not_in_checkin');
        }

        if ($match->readyAt($side) !== null) {
            return $match;
        }

        if ($match->checkInOpensAt()?->isFuture() ?? true) {
            throw self::refuse('checkin_not_open', ['time' => $match->checkInOpensAt()?->copy()->timezone($user->timezone ?? config('esports.preseason.display_timezone'))->format('H:i') ?? '']);
        }

        if ($this->chess->activeGameOf($user) !== null || self::runningMatchOf($user, except: $match->id) !== null || app(BoardGameService::class)->activeGameOf($user) !== null) {
            throw self::refuse('already_playing');
        }

        $done = DB::transaction(function () use ($match, $side): bool {
            $marked = SeriesMatch::query()->whereKey($match->id)->where('status', SeriesStatus::Accepted)
                ->whereNull('ready_at_'.$side)->where('ready_by', '>', now())
                ->update(['ready_at_'.$side => now()]);

            if ($marked !== 1) {
                return false;
            }

            SeriesMatch::query()->whereKey($match->id)
                ->whereNotNull('ready_at_challenger')->whereNotNull('ready_at_challenged')
                ->update(['start_at' => now()]);

            return true;
        });

        $match->refresh();

        if (! $done && $match->readyAt($side) === null) {
            throw self::refuse('checkin_closed');
        }

        if ($done) {
            $this->announce($match);
        }

        return $match;
    }

    /* ---------- Ready check, lobby, join ------------------------------------------------------------------- */

    /**
     * This player is ready. Once both are, the match starts (`start_at` now)
     * and the lobby deadline runs. Pressing twice changes nothing.
     *
     * @throws SeriesRuleViolation
     */
    public function ready(SeriesMatch $match, User $user): SeriesMatch
    {
        $match = $match->fresh() ?? $match;
        $side = $this->sideOf($match, $user);

        if (! $match->awaitsReady()) {
            throw self::refuse('not_in_ready_check');
        }

        if ($match->readyAt($side) !== null) {
            return $match;
        }

        $done = DB::transaction(function () use ($match, $side): bool {
            $marked = SeriesMatch::query()->whereKey($match->id)->where('status', SeriesStatus::Accepted)->whereNull('start_at')
                ->whereNull('ready_at_'.$side)->where('ready_by', '>', now())
                ->update(['ready_at_'.$side => now()]);

            if ($marked !== 1) {
                return false;
            }

            SeriesMatch::query()->whereKey($match->id)->whereNull('start_at')
                ->whereNotNull('ready_at_challenger')->whereNotNull('ready_at_challenged')
                ->update(['start_at' => now()]);

            return true;
        });

        $match->refresh();

        if (! $done && $match->readyAt($side) === null) {
            throw self::refuse('ready_missed');
        }

        if ($done) {
            $this->announce($match);
        }

        return $match;
    }

    /**
     * The host shared the lobby in the match chat. Only the flag is stored.
     *
     * @throws SeriesRuleViolation
     */
    public function shareLobby(SeriesMatch $match, User $user): SeriesMatch
    {
        $match = $match->fresh() ?? $match;
        $side = $this->sideOf($match, $user);
        $this->assertStarted($match);

        if ($side !== $match->host_side) {
            throw self::refuse('not_host');
        }

        if ($match->lobby_shared_at !== null) {
            return $match;
        }

        $marked = SeriesMatch::query()->whereKey($match->id)->where('status', SeriesStatus::Accepted)->whereNotNull('start_at')
            ->whereNull('lobby_shared_at')->whereNull('noshow_reported_at')
            ->update(['lobby_shared_at' => now()]);

        $match->refresh();

        if ($marked !== 1) {
            throw self::refuse($match->noshow_reported_at !== null ? 'noshow_pending' : 'changed');
        }

        $this->notifications->lobbyShared($match);
        $this->announce($match);

        return $match;
    }

    /**
     * The guest joined the host's lobby. Only the flag is stored.
     *
     * @throws SeriesRuleViolation
     */
    public function markJoined(SeriesMatch $match, User $user): SeriesMatch
    {
        $match = $match->fresh() ?? $match;
        $side = $this->sideOf($match, $user);
        $this->assertStarted($match);

        if ($side === $match->host_side) {
            throw self::refuse('not_guest');
        }

        if ($match->lobby_shared_at === null) {
            throw self::refuse('no_lobby_yet');
        }

        if ($match->joined_at !== null) {
            return $match;
        }

        $marked = SeriesMatch::query()->whereKey($match->id)->where('status', SeriesStatus::Accepted)->whereNotNull('lobby_shared_at')
            ->whereNull('joined_at')->whereNull('noshow_reported_at')
            ->update(['joined_at' => now()]);

        $match->refresh();

        if ($marked !== 1) {
            throw self::refuse($match->noshow_reported_at !== null ? 'noshow_pending' : 'changed');
        }

        $this->notifications->opponentJoined($match);
        $this->announce($match);

        return $match;
    }

    /**
     * The guest's client opened a valid card from the host (NIP "Lobby and
     * account cards", `lobby_seen_at`). Only the flag is stored: the call
     * carries nothing about the card. Needs the host's flag first, so the
     * two land in their order; the client asks again if it came too early.
     *
     * @throws SeriesRuleViolation
     */
    public function seeLobby(SeriesMatch $match, User $user): SeriesMatch
    {
        $match = $match->fresh() ?? $match;
        $side = $this->sideOf($match, $user);
        $this->assertStarted($match);

        if ($side === $match->host_side) {
            throw self::refuse('not_guest');
        }

        if ($match->lobby_shared_at === null) {
            throw self::refuse('no_lobby_yet');
        }

        if ($match->lobby_seen_at !== null) {
            return $match;
        }

        $marked = SeriesMatch::query()->whereKey($match->id)->where('status', SeriesStatus::Accepted)->where('host_side', $match->host_side)
            ->whereNotNull('lobby_shared_at')->whereNull('lobby_seen_at')
            ->update(['lobby_seen_at' => now()]);

        $match->refresh();

        if ($marked !== 1) {
            throw self::refuse('changed');
        }

        $this->announce($match);

        return $match;
    }

    /**
     * "Can't share, swap host": the host's signer failed (extension locked,
     * bunker unreachable), so the host seat goes to the guest instead of a
     * no-show. Only the host, only before the lobby is shared and before its
     * deadline, once per match; the new host gets the full lobby time again
     * (SeriesMatch::casualLobbyDueAt()).
     *
     * @throws SeriesRuleViolation
     */
    public function swapHost(SeriesMatch $match, User $user): SeriesMatch
    {
        $match = $match->fresh() ?? $match;
        $side = $this->sideOf($match, $user);
        $this->assertStarted($match);

        if ($side !== $match->host_side) {
            throw self::refuse('not_host');
        }

        if ($match->lobby_shared_at !== null) {
            throw self::refuse('lobby_shared');
        }

        if ($match->host_swapped_at !== null) {
            throw self::refuse('swapped_once');
        }

        if ($match->noshow_reported_at !== null) {
            throw self::refuse('noshow_pending');
        }

        if ($match->casualLobbyDueAt()?->isFuture() !== true) {
            throw self::refuse('swap_late');
        }

        $swapped = SeriesMatch::query()->whereKey($match->id)->where('status', SeriesStatus::Accepted)->where('host_side', $side)
            ->whereNull('lobby_shared_at')->whereNull('host_swapped_at')->whereNull('noshow_reported_at')
            ->where('start_at', '>', now()->subMinutes($match->casualSetting('lobby_minutes')))
            ->update(['host_side' => SeriesMatch::otherSide($side), 'host_swapped_at' => now(), 'lobby_seen_at' => null]);

        $match->refresh();

        if ($swapped !== 1) {
            throw self::refuse('changed');
        }

        $this->announce($match);

        return $match;
    }

    /* ---------- No-show ------------------------------------------------------------------------------------- */

    /**
     * The guest claims the host never shared the lobby (after the lobby
     * deadline), or the host claims the guest never joined (after the join
     * deadline). One claim per match.
     *
     * @throws SeriesRuleViolation
     */
    public function claimNoShow(SeriesMatch $match, User $user): SeriesMatch
    {
        $match = $match->fresh() ?? $match;
        $side = $this->sideOf($match, $user);
        $this->assertStarted($match);

        if ($match->noshow_reported_at !== null || $match->noshow_contested_at !== null) {
            throw self::refuse('noshow_once');
        }

        $host = $side === $match->host_side;
        $due = $host ? $match->casualJoinDueAt() : $match->casualLobbyDueAt();
        $missing = $host ? 'joined_at' : 'lobby_shared_at';

        if ($match->{$missing} !== null || $due === null || $due->isFuture()) {
            throw self::refuse('noshow_early');
        }

        $claimed = SeriesMatch::query()->whereKey($match->id)->where('status', SeriesStatus::Accepted)
            ->whereNull('noshow_reported_at')->whereNull('noshow_contested_at')->whereNull($missing)
            ->update(['noshow_side' => $side, 'noshow_reported_at' => now()]);

        $match->refresh();

        if ($claimed !== 1) {
            throw self::refuse('changed');
        }

        $this->notifications->noShowClaimed($match);
        $this->announce($match);

        return $match;
    }

    /**
     * The accused side is here: the claim is withdrawn and the match goes
     * on, while the contest deadline has not passed. No new claim follows.
     *
     * @throws SeriesRuleViolation
     */
    public function contestNoShow(SeriesMatch $match, User $user): SeriesMatch
    {
        $match = $match->fresh() ?? $match;
        $side = $this->sideOf($match, $user);
        $claimer = $match->noshow_side;

        if ($match->status !== SeriesStatus::Accepted || $match->noshow_reported_at === null || $claimer === null || $claimer === $side) {
            throw self::refuse('no_claim');
        }

        if ($match->casualContestDueAt()?->isPast() ?? true) {
            throw self::refuse('contest_late');
        }

        $contested = SeriesMatch::query()->whereKey($match->id)->where('status', SeriesStatus::Accepted)
            ->where('noshow_side', $claimer)->whereNotNull('noshow_reported_at')->where('noshow_reported_at', '>', now()->subMinutes($match->casualSetting('contest_minutes')))
            ->update(['noshow_side' => null, 'noshow_reported_at' => null, 'noshow_contested_at' => now()]);

        $match->refresh();

        if ($contested !== 1) {
            throw self::refuse('contest_late');
        }

        $this->announce($match);

        return $match;
    }

    /* ---------- Helpers ------------------------------------------------------------------------------------ */

    /**
     * Tell both players the match changed, after the commit, so their docks
     * and rooms refresh (SeriesMatchChanged).
     */
    public function announce(SeriesMatch $match): void
    {
        $users = array_values(array_unique([...$match->rosterSide('challenger'), ...$match->rosterSide('challenged')]));

        if ($users !== []) {
            Broadcasts::send(new SeriesMatchChanged($users, $match->number, $match->status->value));
        }
    }

    /**
     * @throws SeriesRuleViolation
     */
    private function sideOf(SeriesMatch $match, User $user): string
    {
        foreach (SeriesMatch::SIDES as $side) {
            if ($match->isCasualPairing() && $match->isRosterSideMember($side, $user)) {
                return $side;
            }
        }

        throw self::refuse('not_player');
    }

    /**
     * @throws SeriesRuleViolation
     */
    private function assertStarted(SeriesMatch $match): void
    {
        if (! $match->casualUnderWay()) {
            throw self::refuse($match->awaitsCheckIn() ? 'not_checked_in' : 'not_running');
        }
    }

    private static function tag(User $user): string
    {
        return mb_strtoupper(mb_substr(preg_replace('/[^A-Za-z0-9]/', '', $user->displayName()) ?: 'P', 0, 4));
    }

    /**
     * A casual refusal: `reason` is the stable code, the message the
     * player reads.
     *
     * @param  array<string, string>  $replace
     */
    public static function refuse(string $reason, array $replace = []): SeriesRuleViolation
    {
        return new SeriesRuleViolation($reason, match ($reason) {
            'unknown_game' => __('There is no casual 1v1 for this game.'),
            'queue_locked' => __('You did not show up to your recent matches. You can play casual 1v1 again at :time.', $replace),
            'already_playing' => __('Finish your current game first.'),
            'invite_self' => __('You cannot invite yourself.'),
            'not_looking' => __(':name is not looking for a game right now.', $replace),
            'invite_closed' => __('This invite is no longer open.'),
            'not_searching' => __('This player is not searching right now.'),
            'opponent_playing' => __('Your opponent is playing another game right now.'),
            'accept_while_playing' => __('Finish your current game first, then accept.'),
            'platforms_incompatible' => __('Your platforms cannot play each other. Turn on crossplay or pick the same platform.'),
            'not_player' => __('Only the two players of this match can do that.'),
            'not_in_ready_check' => __('The ready check of this match is over.'),
            'ready_missed' => __('The ready check ran out.'),
            'not_running' => __('This match is not running.'),
            'not_host' => __('Only the host shares the lobby.'),
            'not_guest' => __('Only the guest joins the lobby.'),
            'no_lobby_yet' => __('The host has not shared the lobby yet.'),
            'lobby_shared' => __('The lobby is shared already, so the host stays.'),
            'swapped_once' => __('The host was swapped once already in this match.'),
            'swap_late' => __('The time to share the lobby is over, so the host can no longer be swapped.'),
            'noshow_pending' => __('A no-show was claimed; answer it first.'),
            'not_checked_in' => __('Both players check in first.'),
            'not_in_checkin' => __('This match has no check-in open.'),
            'checkin_not_open' => __('The check-in opens at :time.', $replace),
            'checkin_closed' => __('The check-in has closed.'),
            'challenge_limit' => __('You have sent enough challenges for today. Try again tomorrow.'),
            'challenge_closed' => __('This challenge is no longer open.'),
            'challenge_expired' => __('This challenge has expired.'),
            'start_not_proposed' => __('Pick one of the suggested times.'),
            'start_past' => __('This time has passed. Pick a later one.'),
            'not_challenged' => __('Only the challenged player can answer.'),
            'not_challenger' => __('Only the challenger can withdraw it.'),
            'noshow_once' => __('A no-show was claimed in this match already.'),
            'noshow_early' => __('A no-show can be claimed only after your opponent missed their deadline.'),
            'no_claim' => __('There is no no-show claim to contest.'),
            'contest_late' => __('The time to contest has run out.'),
            'rematch_closed' => __('A rematch can be sent only right after a match with a result.'),
            'casual_match' => __('Casual 1v1 matches share the lobby in the match chat, and no-shows go by their own deadlines.'),
            default => __('This match changed in between. Please look again.'),
        });
    }
}
