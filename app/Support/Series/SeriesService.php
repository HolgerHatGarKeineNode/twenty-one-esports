<?php

namespace App\Support\Series;

use App\Enums\LineupRole;
use App\Enums\NotificationKind;
use App\Enums\ReportStatus;
use App\Enums\SeriesResolution;
use App\Enums\SeriesStatus;
use App\Enums\TournamentStatus;
use App\Events\SeriesMatchChanged;
use App\Games\GameRegistry;
use App\Jobs\PublishNostrEvent;
use App\Models\DisputeEvidence;
use App\Models\FalseReport;
use App\Models\Lineup;
use App\Models\LineupSeat;
use App\Models\MatchNumber;
use App\Models\NostrEvent;
use App\Models\SeriesMatch;
use App\Models\SeriesReport;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\User;
use App\Support\Chess\Broadcasts;
use App\Support\FairPlay\FairPlay;
use App\Support\Nostr\NostrKeys;
use App\Support\Nostr\RejectedEvent;
use App\Support\Nostr\SignedEvent;
use App\Support\Nostr\SignedEventGate;
use App\Support\Notifications\CasualNotifications;
use App\Support\Notifications\Notice;
use App\Support\Notifications\Notifier;
use App\Support\Rating\RatingService;
use App\Support\SeasonChain\GatePin;
use App\Support\SeasonChain\RatedTrustGate;
use App\Support\SeasonChain\SeasonChains;
use App\Support\SeasonChain\Seasons;
use App\Support\Tournaments\TournamentRunner;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * The series match flow of docs/nips/esports.md (rev. 5), for lineups:
 * challenge (2150), answer (2151), result report (2152), result response
 * (2153), and the admin decision after a dispute or a no-show.
 *
 * Every signed step comes in two halves, as in App\Support\Clans\ClanService:
 *
 *   prepareX(...)  -> the unsigned events the captain has to sign
 *   x(..., $signed) -> the plan rebuilt from the current state, every signed
 *                      event checked against it (SignedEventGate), then the
 *                      state written and the events published
 *
 * A CASUAL series signs nothing: the NIP gives casual games no match-flow
 * events ("Game registry": "Casual (unrated) games never produce match-flow
 * events"), so every plan of a casual match has zero templates and the
 * browser submits an empty list. It still takes a league match number
 * (NIP "Terminology": "casual games take numbers too"). Rated play needs an
 * open ladder ({@see Ladders}); before Block 0 there is none.
 *
 * Never signed at all: the lobby, the live score per game (public, shown as
 * provisional), who played while the series runs, no-show reports, evidence
 * and admin decisions (the league attestation 2154 comes with P7).
 */
final class SeriesService
{
    public const REASON_MAX = 280;

    public function __construct(
        private SignedEventGate $gate,
        private GameRegistry $games,
        private Notifier $notifier,
        private RatingService $ratings,
        private SeasonChains $chains,
        private RatedTrustGate $trustGate,
        private CasualNotifications $casualNotifications,
    ) {}

    /* ---------- Challenge (2150) ------------------------------------------------------------------------------ */

    /**
     * @return array{number: int, templates: list<array<string, mixed>>}
     *
     * @throws SeriesRuleViolation
     */
    public function prepareChallenge(User $author, ChallengeDraft $draft): array
    {
        $plan = $this->challengePlan($author, $draft);

        return ['number' => $plan['match']->number, 'templates' => $plan['templates']];
    }

    /**
     * @param  list<mixed>  $signed
     *
     * @throws SeriesRuleViolation|RejectedEvent
     */
    public function challenge(User $author, ChallengeDraft $draft, array $signed): SeriesMatch
    {
        $plan = $this->challengePlan($author, $draft);
        $events = $this->verify($signed, $plan['templates'], $author);
        $match = $plan['match'];

        $match = $this->persist($events, function (array $stored) use ($match): SeriesMatch {
            $claimed = MatchNumber::query()->whereKey($match->number)->whereNull('used_at')->update(['used_at' => now()]);

            if ($claimed !== 1) {
                throw new SeriesRuleViolation('number_used', __('Something changed in between. Please send the challenge again.'));
            }

            $match->challenge_event_id = $stored[0]->id ?? null;
            $match->save();

            return $match;
        });

        $this->notifyChallenged($match);
        $this->broadcastChange($match);

        return $match;
    }

    /**
     * @return array{match: SeriesMatch, templates: list<array<string, mixed>>}
     */
    private function challengePlan(User $author, ChallengeDraft $draft): array
    {
        $challenger = $this->loadLineup($draft->challengerLineupId);
        $challenged = $this->loadLineup($draft->challengedLineupId);

        if ($challenger === null || $challenged === null) {
            throw new SeriesRuleViolation('lineup_missing', __('This lineup does not exist.'));
        }

        if (! $challenger->isActingCaptain($author)) {
            throw new SeriesRuleViolation('not_captain', __('Only a captain of the lineup can send a challenge.'));
        }

        $mode = $this->games->mode($challenger->game, $challenger->mode);

        if ($mode === null || $mode->bestOf === [] || $challenged->game !== $challenger->game || $challenged->mode !== $challenger->mode) {
            throw new SeriesRuleViolation('mode_mismatch', __('Both lineups have to play the same game and mode.'));
        }

        if ($challenged->clan_id === $challenger->clan_id) {
            throw new SeriesRuleViolation('same_clan', __('You cannot challenge your own clan.'));
        }

        if (! $mode->allowsBestOf($draft->bestOf)) {
            throw new SeriesRuleViolation('bo_not_allowed', __('This format is not allowed.'));
        }

        if (! $challenger->isReady() || ! $challenged->isReady()) {
            throw new SeriesRuleViolation('lineup_not_ready', __('Both lineups need enough active players.'));
        }

        if ($this->openBetween($challenger, $challenged)) {
            throw new SeriesRuleViolation('already_open', __('There is already an open or running match between these two lineups.'));
        }

        $ladder = null;

        if ($draft->rated) {
            $ladder = Ladders::address($challenger->game, $challenger->mode)
                ?? throw new SeriesRuleViolation('rated_not_open', Seasons::restMessage($author));

            $refusal = $this->trustGate->forChallenge($challenger, $challenged, $author, $this->captainPubkeys($challenged));

            if ($refusal !== null) {
                throw new SeriesRuleViolation($refusal, RatedTrustGate::message($refusal, [$author->pubkey, ...$this->captainPubkeys($challenged)]));
            }
        }

        ['proposals' => $proposals, 'message' => $message] = self::schedule($draft->proposals, $draft->respondBy, $draft->message);

        $match = new SeriesMatch([
            'number' => $this->reserveNumber($author),
            'game' => $challenger->game,
            'mode' => $challenger->mode,
            'best_of' => $draft->bestOf,
            'rated' => $draft->rated,
            'challenger_lineup_id' => $challenger->id,
            'challenged_lineup_id' => $challenged->id,
            'challenger_name' => $challenger->clan->name,
            'challenged_name' => $challenged->clan->name,
            'challenger_tag' => $challenger->clan->clantag,
            'challenged_tag' => $challenged->clan->clantag,
            'challenger_lineup_address' => $challenger->address(),
            'challenged_lineup_address' => $challenged->address(),
            'ladder_address' => $ladder,
            'created_by_id' => $author->id,
            'status' => SeriesStatus::Open,
            'proposals' => $proposals,
            'respond_by' => now()->setTimestamp($draft->respondBy),
            'message' => $message === '' ? null : $message,
        ]);

        $templates = $match->rated
            ? [SeriesEvents::challenge($match, $this->captainPubkeys($challenged))]
            : [];

        return ['match' => $match, 'templates' => $templates];
    }

    /**
     * The schedule of a challenge (a lineup's, or a casual 1v1's, P23 S4):
     * one to three suggested starts in the future, at most `plan_max_days`
     * ahead; a reply deadline in the future, at most `respond_max_days`
     * ahead and not after the first start; a message of up to 140
     * characters. Returns the starts sorted and without duplicates.
     *
     * @param  list<int>  $proposals  unix seconds
     * @return array{proposals: list<int>, message: string}
     *
     * @throws SeriesRuleViolation
     */
    public static function schedule(array $proposals, int $respondBy, ?string $message): array
    {
        $now = now()->getTimestamp();
        $proposals = array_values(array_unique(array_map(intval(...), $proposals)));
        sort($proposals);
        $planLimit = $now + (int) config('esports.series.plan_max_days', 14) * 86400;

        if ($proposals === [] || count($proposals) > 3) {
            throw new SeriesRuleViolation('proposals_count', __('Suggest one to three times.'));
        }

        foreach ($proposals as $start) {
            if ($start <= $now || $start > $planLimit) {
                throw new SeriesRuleViolation('proposal_time', __('Every suggested time has to lie in the future, at most :days days ahead.', ['days' => (int) config('esports.series.plan_max_days', 14)]));
            }
        }

        $respondLimit = $now + (int) config('esports.series.respond_max_days', 7) * 86400;

        if ($respondBy <= $now || $respondBy > $respondLimit || $respondBy > $proposals[0]) {
            throw new SeriesRuleViolation('respond_by', __('The reply deadline has to be in the future, at the latest at the first suggested time.'));
        }

        $message = trim((string) $message);

        if (mb_strlen($message) > 140) {
            throw new SeriesRuleViolation('message', __('The message can be at most 140 characters.'));
        }

        return ['proposals' => $proposals, 'message' => $message];
    }

    /**
     * The author's reserved, still unused number, or a new one. Reusing it
     * keeps the number stable between preparing and signing (NIP: "a positive
     * integer the league reserved for this author").
     */
    private function reserveNumber(User $author): int
    {
        $reserved = MatchNumber::query()->where('user_id', $author->id)->whereNull('used_at')->orderBy('id')->first();

        return ($reserved ?? MatchNumber::query()->create(['user_id' => $author->id]))->id;
    }

    private function openBetween(Lineup $a, Lineup $b): bool
    {
        $running = [SeriesStatus::Open, SeriesStatus::Accepted, SeriesStatus::Reported, SeriesStatus::Disputed];

        return SeriesMatch::query()
            ->whereIn('status', $running)
            ->where(fn ($query) => $query
                ->where(fn ($q) => $q->where('challenger_lineup_id', $a->id)->where('challenged_lineup_id', $b->id))
                ->orWhere(fn ($q) => $q->where('challenger_lineup_id', $b->id)->where('challenged_lineup_id', $a->id)))
            ->exists();
    }

    /* ---------- Answer (2151): accept, decline, withdraw -------------------------------------------------------- */

    /**
     * @param  'accepted'|'declined'|'withdrawn'  $status
     * @return list<array<string, mixed>>
     *
     * @throws SeriesRuleViolation
     */
    public function prepareAnswer(SeriesMatch $match, User $user, string $status, ?int $start = null): array
    {
        return $this->answerPlan($match, $user, $status, $start);
    }

    /**
     * @param  'accepted'|'declined'|'withdrawn'  $status
     * @param  list<mixed>  $signed
     *
     * @throws SeriesRuleViolation|RejectedEvent
     */
    public function answer(SeriesMatch $match, User $user, string $status, ?int $start, array $signed): void
    {
        $events = $this->verify($signed, $this->answerPlan($match, $user, $status, $start), $user);
        $pin = $status === 'accepted' && $match->rated ? $this->pinGate($match, $user) : null;

        $this->persist($events, function (array $stored) use ($match, $user, $status, $start, $pin): void {
            $updated = SeriesMatch::query()->whereKey($match->id)->where('status', SeriesStatus::Open)->update([
                'status' => match ($status) {
                    'accepted' => SeriesStatus::Accepted,
                    'declined' => SeriesStatus::Declined,
                    default => SeriesStatus::Withdrawn,
                },
                'answered_by_id' => $user->id,
                'answered_at' => now(),
                'start_at' => $status === 'accepted' ? now()->setTimestamp((int) $start) : null,
                'clans_at_accept' => $status === 'accepted' ? json_encode($this->clansOf($this->fresh($match))) : null,
                'gate_at_accept' => $pin === null ? null : json_encode($pin->toArray()),
                // The rated entities as the accept saw them: a lineup gone later is still rated (security gate F3).
                'rated_subjects' => $pin === null ? null : json_encode(['challenger' => 'lineup:'.$match->challenger_lineup_id, 'challenged' => 'lineup:'.$match->challenged_lineup_id]),
                'finished_at' => $status === 'accepted' ? null : now(),
                'answer_event_id' => $stored[0]->id ?? null,
            ]);

            if ($updated !== 1) {
                throw new SeriesRuleViolation('not_open', __('This challenge is no longer open.'));
            }
        });

        $match->refresh();
        $this->broadcastChange($match);
    }

    /**
     * The trust gate pinned with a rated accept, checked once more on the
     * values it pins, so the stored gate is the one that passed.
     *
     * @throws SeriesRuleViolation
     */
    private function pinGate(SeriesMatch $match, User $user): GatePin
    {
        if (! $this->trustGate->isAvailable()) {
            throw new SeriesRuleViolation(RatedTrustGate::NOT_COMPUTED, RatedTrustGate::message(RatedTrustGate::NOT_COMPUTED));
        }

        $fresh = $this->fresh($match);
        $gatekeepers = [(string) $fresh->createdBy?->pubkey, $user->pubkey];

        // Fair play (P41): a barred captain is left out of the pin; say why, with the end of the bar.
        if (FairPlay::barred($gatekeepers) !== []) {
            throw new SeriesRuleViolation(RatedTrustGate::FAIR_PLAY, RatedTrustGate::message(RatedTrustGate::FAIR_PLAY, $gatekeepers));
        }

        $pin = $this->trustGate->pinForAccept($fresh, $user);
        $refusal = $pin->refusal()
            ?? ($fresh->challengerLineup === null || $fresh->challengedLineup === null
                ? RatedTrustGate::NOT_TRUSTED
                : RatedTrustGate::sidesRefusal($pin, $fresh->challengerLineup, $fresh->challengedLineup));

        if ($refusal !== null || $fresh->challengerLineup === null || $fresh->challengedLineup === null) {
            $refusal ??= RatedTrustGate::NOT_TRUSTED;

            throw new SeriesRuleViolation($refusal, RatedTrustGate::message($refusal));
        }

        return $pin->withSides([
            'challenger' => self::eligibleEntries($pin, $fresh->challengerLineup),
            'challenged' => self::eligibleEntries($pin, $fresh->challengedLineup),
        ]);
    }

    /**
     * The lineup's active players eligible in the pin, as the pin stores them.
     *
     * @return list<array{user_id: int, pubkey: string, name: string, role: string}>
     */
    private static function eligibleEntries(GatePin $pin, Lineup $lineup): array
    {
        $entries = [];

        foreach ($lineup->activeSeats() as $seat) {
            if ($pin->isEligible($seat->user->pubkey)) {
                $entries[] = ['user_id' => $seat->user_id, 'pubkey' => $seat->user->pubkey, 'name' => $seat->user->displayName(), 'role' => $seat->role->value];
            }
        }

        return $entries;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function answerPlan(SeriesMatch $match, User $user, string $status, ?int $start): array
    {
        $match = $this->fresh($match);

        // A casual 1v1 challenge (P23 S4) is answered with a platform: CasualChallenges.
        if ($match->isCasualPairing()) {
            throw CasualMatches::refuse('casual_match');
        }

        if ($match->status !== SeriesStatus::Open) {
            throw new SeriesRuleViolation('not_open', __('This challenge is no longer open.'));
        }

        if ($match->respond_by->isPast()) {
            $this->expireOne($match);

            throw new SeriesRuleViolation('expired', __('This challenge has expired.'));
        }

        $side = $status === 'withdrawn' ? 'challenger' : 'challenged';

        if (! in_array($status, ['accepted', 'declined', 'withdrawn'], true) || $match->captainSideOf($user) !== $side) {
            throw new SeriesRuleViolation('not_captain', $status === 'withdrawn'
                ? __('Only a captain of the challenging lineup can withdraw it.')
                : __('Only a captain of the challenged lineup can answer.'));
        }

        if ($status === 'accepted') {
            if ($start === null || ! in_array($start, $match->proposals, true)) {
                throw new SeriesRuleViolation('start_not_proposed', __('Pick one of the suggested times.'));
            }

            if (! $match->challengerLineup?->isReady() || ! $match->challengedLineup?->isReady()) {
                throw new SeriesRuleViolation('lineup_not_ready', __('Both lineups need enough active players.'));
            }

            $refusal = $match->rated ? $this->trustGate->forAccept($match, $user) : null;

            if ($refusal !== null) {
                throw new SeriesRuleViolation($refusal, RatedTrustGate::message($refusal, [(string) $match->createdBy?->pubkey, $user->pubkey]));
            }
        }

        if (! $match->rated) {
            return [];
        }

        $notify = $status === 'withdrawn'
            ? ($this->captainPubkeys($match->challengedLineup)[0] ?? '')
            : (string) $match->challengeEvent?->pubkey;

        return [SeriesEvents::answer($match, $this->requireEvent($match->challengeEvent), $status, $status === 'accepted' ? $start : null, $notify)];
    }

    /* ---------- Expiry ----------------------------------------------------------------------------------------- */

    /**
     * Every open challenge whose reply deadline passed ends as expired. No
     * event is signed for this (NIP state machine: "open | time | expired").
     * A casual 1v1 challenge (P23 S4) is left to `casual:tick`, which tells
     * the challenger (CasualChallenges::expireDue()).
     */
    public function expireDue(): int
    {
        return SeriesMatch::query()
            ->where('status', SeriesStatus::Open)
            ->whereNull('origin')
            ->where('respond_by', '<', now())
            ->update(['status' => SeriesStatus::Expired, 'finished_at' => now()]);
    }

    private function expireOne(SeriesMatch $match): void
    {
        SeriesMatch::query()->whereKey($match->id)->where('status', SeriesStatus::Open)
            ->update(['status' => SeriesStatus::Expired, 'finished_at' => now()]);
    }

    /* ---------- Room: lobby, live score, who played, no-show -------------------------------------------------- */

    /**
     * Lobby name and password for the two lineups. Stored encrypted; never
     * part of an event, a notification or any page outside the room.
     */
    public function setLobby(SeriesMatch $match, User $user, string $name, string $password, ?string $region): void
    {
        $match = $this->fresh($match);

        if ($match->captainSideOf($user) === null) {
            throw new SeriesRuleViolation('not_captain', __('Only a captain can change the lobby.'));
        }

        // A casual 1v1 (P23) never stores its lobby: it is shared in the encrypted match chat.
        if ($match->isCasualPairing()) {
            throw CasualMatches::refuse('casual_match');
        }

        if (! $match->status->isRunning()) {
            throw new SeriesRuleViolation('not_running', __('The lobby opens once the challenge is accepted.'));
        }

        $name = trim($name);
        $password = trim($password);

        if ($name === '' || mb_strlen($name) > 32 || mb_strlen($password) > 32) {
            throw new SeriesRuleViolation('lobby_invalid', __('Lobby name (required) and password can be at most 32 characters.'));
        }

        if ($region !== null && ! in_array($region, config('esports.series.regions', []), true)) {
            throw new SeriesRuleViolation('lobby_region', __('Pick a region from the list.'));
        }

        $match->update(['lobby_name' => $name, 'lobby_password' => $password === '' ? null : $password, 'lobby_region' => $region, 'lobby_updated_by_id' => $user->id]);
        $this->broadcastChange($match);
    }

    /**
     * Enter or clear the score of one game on the live sheet (public, marked
     * provisional). With both goals known the winner follows from them; with
     * goals unknown the captain picks the winner (NIP `score`: points unknown).
     */
    public function saveLiveGame(SeriesMatch $match, User $user, int $index, ?int $challengerGoals, ?int $challengedGoals, ?string $winner): void
    {
        $match = $this->fresh($match);
        $this->assertPlayersReport($match);

        if ($match->captainSideOf($user) === null) {
            throw new SeriesRuleViolation('not_captain', __('Only a captain can enter the score.'));
        }

        $this->assertNoCasualClaim($match);

        if (! in_array($match->status, [SeriesStatus::Accepted, SeriesStatus::Disputed], true)) {
            throw new SeriesRuleViolation('sheet_closed', __('The score cannot be changed right now.'));
        }

        if ($match->start_at?->isFuture()) {
            throw new SeriesRuleViolation('not_started', __('The match has not started yet.'));
        }

        if ($index < 0 || $index >= $match->best_of) {
            throw new SeriesRuleViolation('game_index', __('This game does not exist in this format.'));
        }

        $known = $challengerGoals !== null || $challengedGoals !== null;

        // A game without goals (Age of Empires II) has a winner only.
        if ($known && ! $match->hasGoals()) {
            throw new SeriesRuleViolation('goals', __('Game :number has a score, but this game records only its winner.', ['number' => $index + 1]));
        }

        if ($known) {
            if ($challengerGoals === null || $challengedGoals === null || $challengerGoals < 0 || $challengedGoals < 0 || $challengerGoals > 99 || $challengedGoals > 99 || $challengerGoals === $challengedGoals) {
                throw new SeriesRuleViolation('goals', __('Enter both goal counts; a game cannot end in a draw.'));
            }

            $winner = $challengerGoals > $challengedGoals ? 'challenger' : 'challenged';
        } elseif ($winner !== null && ! in_array($winner, SeriesMatch::SIDES, true)) {
            throw new SeriesRuleViolation('winner', __('Pick the winner of this game.'));
        }

        $sheet = $match->live_games ?? [];

        for ($i = count($sheet); $i <= $index; $i++) {
            $sheet[$i] = ['challenger' => null, 'challenged' => null, 'winner' => null];
        }

        $sheet[$index] = ['challenger' => $challengerGoals, 'challenged' => $challengedGoals, 'winner' => $winner];

        $match->update(['live_games' => $sheet]);
        $this->broadcastChange($match);
    }

    /**
     * Who played for the captain's own side (MatchRoom "Who played").
     *
     * @param  list<int>  $userIds
     */
    public function setRoster(SeriesMatch $match, User $user, array $userIds): void
    {
        $match = $this->fresh($match);
        $side = $match->captainSideOf($user);

        if ($side === null) {
            throw new SeriesRuleViolation('not_captain', __('Only a captain can set who played.'));
        }

        if (! in_array($match->status, [SeriesStatus::Accepted, SeriesStatus::Disputed], true)) {
            throw new SeriesRuleViolation('sheet_closed', __('Who played cannot be changed right now.'));
        }

        $ids = array_values(array_unique(array_map(intval(...), $userIds)));

        if (! $match->rated && array_diff($ids, array_map(fn (LineupSeat $seat) => $seat->user_id, $match->lineup($side)?->activeSeats() ?? [])) !== []) {
            throw new SeriesRuleViolation('roster_foreign', __('Only active players of your lineup can be on the list.'));
        }

        $allowed = array_map(fn (LineupSeat $seat) => $seat->user_id, $this->rosterChoices($match, $side));

        if (array_diff($ids, $allowed) !== []) {
            throw new SeriesRuleViolation('roster_not_eligible', __('Only players who were in the lineup and Trusted when the match was accepted can play this rated match.'));
        }

        // Security gate F2: an empty or short list would make every report fail and leave the
        // winner unpaid; a rated side lists at least the mode's team size.
        if ($match->rated && count($ids) < $match->gameMode()->teamSize) {
            throw new SeriesRuleViolation('roster_short', __('":clan" needs at least :count players in "Who played".', ['clan' => $match->sideName($side), 'count' => $match->gameMode()->teamSize]));
        }

        $rosters = $match->rosters ?? [];
        $rosters[$side] = array_values(array_filter($allowed, fn (int $id) => in_array($id, $ids, true)));
        $match->update(['rosters' => $rosters]);
        $this->broadcastChange($match);
    }

    /**
     * Who played for a side: the captain's list, or the regulars (captain and
     * player seats) until the captain changes it, chosen from
     * {@see rosterChoices()}. In a rated match a list that falls short of the
     * team size (stored before the check) gives way to the pinned regulars,
     * then to every pinned player, so one captain cannot block the result
     * (security gate F2).
     *
     * @return list<LineupSeat>
     */
    public function rosterSeats(SeriesMatch $match, string $side): array
    {
        $seats = $this->rosterChoices($match, $side);
        $chosen = $match->rosters[$side] ?? null;
        $regulars = array_values(array_filter($seats, fn (LineupSeat $seat) => $seat->role !== LineupRole::Substitute));
        $roster = $chosen === null ? $regulars : array_values(array_filter($seats, fn (LineupSeat $seat) => in_array($seat->user_id, $chosen, true)));

        if (! $match->rated || count($roster) >= $match->gameMode()->teamSize) {
            return $roster;
        }

        return count($regulars) >= $match->gameMode()->teamSize ? $regulars : $seats;
    }

    /**
     * Who can be on a side's "Who played": the lineup's active seats in a
     * casual match; in a rated match the side's eligible players as the accept
     * pinned them (NIP condition 3), built from the pin and not from today's
     * seats, so a seat removed, a lineup saved without a player or an account
     * deleted after the accept cannot shrink the side (security re-check,
     * F2). The seats are not stored; a deleted account keeps its pinned name.
     * A rated match pinned before the sides were stored falls back to the
     * eligible active seats. In a tournament a lineup's seats are only the
     * players its entry fielded, without blocked ones.
     *
     * @return list<LineupSeat>
     */
    public function rosterChoices(SeriesMatch $match, string $side): array
    {
        $lineup = $match->lineup($side);
        $seats = $lineup?->activeSeats() ?? self::rosterSideSeats($match, $side);
        $tournament = $match->tournament_match_id === null ? null : $match->tournamentMatch?->tournament;

        // A tournament lineup plays with the players its entry fielded, never a blocked one (Tournament::entryPlayersOf).
        if ($lineup !== null && $tournament !== null) {
            $entry = $tournament->entryPlayersOf($lineup->id) ?? [];
            $seats = array_values(array_filter($seats, fn (LineupSeat $seat): bool => in_array($seat->user_id, $entry, true)));
        }

        $pinned = $match->rated ? (GatePin::fromArray($match->gate_at_accept)->sides[$side] ?? null) : null;

        if ($pinned === null) {
            return $this->eligibleSeats($match, $seats);
        }

        $users = User::query()->whereIn('id', array_column($pinned, 'user_id'))->get()->keyBy('id');

        return array_map(function (array $entry) use ($users): LineupSeat {
            $seat = new LineupSeat(['user_id' => $entry['user_id'], 'role' => LineupRole::from($entry['role'])]);
            $seat->setRelation('user', $users->get($entry['user_id'])
                ?? (new User)->forceFill(['id' => $entry['user_id'], 'name' => $entry['name'], 'pubkey' => $entry['pubkey'], 'npub' => NostrKeys::hexToNpub($entry['pubkey'])]));

            return $seat;
        }, $pinned);
    }

    /**
     * The players of a roster side as seats (they have no lineup).
     *
     * @return list<LineupSeat>
     */
    private static function rosterSideSeats(SeriesMatch $match, string $side): array
    {
        $users = User::query()->whereIn('id', $match->rosterSide($side))->get()->keyBy('id');

        return array_values(array_filter(array_map(function (int $id) use ($users): ?LineupSeat {
            $user = $users->get($id);

            if ($user === null) {
                return null;
            }

            $seat = new LineupSeat(['user_id' => $id, 'role' => LineupRole::Player]);
            $seat->setRelation('user', $user);

            return $seat;
        }, $match->rosterSide($side))));
    }

    /**
     * NIP "Trust gate", condition 3: a rated roster may list only players the
     * accept pinned with a rank at or above the minimum, so a player seated
     * after the accept (or below the minimum then) cannot play it. A rated
     * match without a pin has no eligible player (fail closed). A casual
     * match keeps every active seat.
     *
     * @param  list<LineupSeat>  $seats
     * @return list<LineupSeat>
     */
    private function eligibleSeats(SeriesMatch $match, array $seats): array
    {
        if (! $match->rated) {
            return $seats;
        }

        $pin = GatePin::fromArray($match->gate_at_accept);

        return array_values(array_filter($seats, fn (LineupSeat $seat): bool => $pin?->isEligible($seat->user->pubkey) ?? false));
    }

    /**
     * "Opponent didn't show": from `noshow_minutes` after the start (a
     * tournament's own, pinned at the pairing), while no game is entered and
     * nobody reported. An admin decides (forfeit/void); in a players-mode
     * tournament the league forfeits it once the other side let the response
     * deadline pass ({@see forfeitNoShow()}).
     */
    public function reportNoShow(SeriesMatch $match, User $user): void
    {
        $match = $this->fresh($match);
        $this->assertPlayersReport($match);
        $side = $match->captainSideOf($user);

        if ($side === null) {
            throw new SeriesRuleViolation('not_captain', __('Only a captain can report a no-show.'));
        }

        // A casual 1v1 (P23) has its own no-show deadlines (CasualMatches::claimNoShow()).
        if ($match->isCasualPairing()) {
            throw CasualMatches::refuse('casual_match');
        }

        $from = $match->noshowReportableAt();

        if ($match->status !== SeriesStatus::Accepted || $from === null || $from->isFuture()) {
            throw new SeriesRuleViolation('noshow_early', __('A no-show can be reported :minutes minutes after the start.', ['minutes' => $match->noshowMinutes()]));
        }

        if ($match->currentGames() !== [] || $match->noshow_reported_at !== null) {
            throw new SeriesRuleViolation('noshow_games', __('Games are entered already, so this is not a no-show.'));
        }

        $match->update(['noshow_side' => $side, 'noshow_reported_at' => now()]);
        $this->broadcastChange($match);
    }

    /* ---------- Lobby check-in (tournament series) ------------------------------------------------------------- */

    /**
     * "I'm in the lobby" (user, 2026-10-04): a tournament series' side says it is there, a signal for the other side and
     * the direction (TournamentWaits). From the start on; once per side. A casual 1v1 has its own check-in.
     */
    public function checkInLobby(SeriesMatch $match, User $user): void
    {
        $match = $this->fresh($match);

        if ($match->tournament_match_id === null || $match->isCasualPairing()) {
            throw new SeriesRuleViolation('not_tournament', __('Only a tournament match has a lobby check-in.'));
        }

        $side = $match->captainSideOf($user);

        if ($side === null) {
            throw new SeriesRuleViolation('not_captain', __('Only a player of this match can check in.'));
        }

        if ($match->status !== SeriesStatus::Accepted || $match->start_at === null || $match->start_at->isFuture()) {
            throw new SeriesRuleViolation('checkin_closed', __('The check-in opens when the match starts.'));
        }

        if ($match->readyAt($side) !== null) {
            return;
        }

        // Checking in answers a no-show reported against this side (by the league or the other side): it is there now.
        $answers = $match->noshow_reported_at !== null && $match->noshow_side === SeriesMatch::otherSide($side);
        SeriesMatch::query()->whereKey($match->id)->whereNull('ready_at_'.$side)
            ->update(['ready_at_'.$side => now(), ...($answers ? ['noshow_side' => null, 'noshow_reported_at' => null] : [])]);
        $this->broadcastChange($match->refresh());
    }

    /**
     * One side checked in, the other did not within `auto_noshow_minutes` of the start (pauses do not count) and nothing
     * is entered: the league reports the no-show for the side that is there. The absent side answers within the response
     * deadline, or forfeitNoShow() decides (user, 2026-10-04: "EINCHECKEN nicht klicken nach 30min = NO-SHOW").
     */
    public function autoNoShow(SeriesMatch $match): bool
    {
        $match = $this->fresh($match);

        if ($match->tournament_match_id === null || $match->isCasualPairing() || $match->status !== SeriesStatus::Accepted || $match->start_at === null
            || $match->noshow_reported_at !== null || $match->overdue_at !== null || $match->currentGames() !== [] || $match->autoNoshowAt() === null
            || self::isDirectorEntered($match)) {
            return false;
        }

        $in = array_values(array_filter(SeriesMatch::SIDES, fn (string $side): bool => $match->readyAt($side) !== null));

        // Nobody checked in: after the check-in no-show time plus the response time (a side may still check in, then
        // the other one is the no-show), the double no-show rule decides the match (TournamentRunner).
        if ($in === []) {
            // autoNoshowAt() is not null here (guard above); the runner checks it again under the lock.
            $due = $match->autoNoshowAt()->copy()->addMinutes((int) $match->responseMinutes());

            return ! $due->isFuture() && app(TournamentRunner::class)->decideNobodyCheckedIn($match);
        }

        if (count($in) !== 1) {
            return false;
        }

        $absent = SeriesMatch::otherSide($in[0]);

        // A conditional update under the tournament's lock, like every deadline the tick applies (TournamentScheduler):
        // a game entered, a report, a check-in or a pause after the tick read the series wins.
        $updated = DB::transaction(function () use ($match, $in, $absent): int {
            $locked = $this->lockForDeadline($match);
            $due = $locked?->autoNoshowAt();

            if ($locked === null || $due === null || $due->isFuture() || $locked->currentGames() !== []) {
                return 0;
            }

            return self::notPaused(SeriesMatch::query()->whereKey($match->id)->where('status', SeriesStatus::Accepted)
                ->whereNull('noshow_reported_at')->whereNull('overdue_at')->whereNotNull('ready_at_'.$in[0])->whereNull('ready_at_'.$absent))
                ->update(['noshow_side' => $in[0], 'noshow_reported_at' => now()]);
        });

        if ($updated === 1) {
            $this->broadcastChange($match);
        }

        return $updated === 1;
    }

    /* ---------- Result report (2152) -------------------------------------------------------------------------- */

    /**
     * What "Submit final score" would send: the games of the live sheet and
     * both rosters, checked against the game's rules. With `$side` only that
     * side's roster has to reach the team size ({@see rosterFor()}).
     *
     * @return array{games: list<array{winner: string, challenger: int|null, challenged: int|null}>, roster: list<array{user_id: int, pubkey: string, name: string, side: string, role: string}>}
     *
     * @throws SeriesRuleViolation
     */
    public function draftReport(SeriesMatch $match, ?string $side = null): array
    {
        $games = [];

        foreach ($match->live_games ?? [] as $index => $game) {
            if (($game['winner'] ?? null) === null) {
                if (array_filter(array_slice($match->live_games ?? [], $index + 1), fn (array $later) => ($later['winner'] ?? null) !== null) !== []) {
                    throw new SeriesRuleViolation('game_gap', __('Game :number has no result, but a later game has one.', ['number' => $index + 1]));
                }

                break;
            }

            $games[] = ['winner' => (string) $game['winner'], 'challenger' => $game['challenger'] ?? null, 'challenged' => $game['challenged'] ?? null];
        }

        $errors = $this->games->get($match->game)->validateResult($match->gameMode(), ['bo' => $match->best_of, 'games' => $games]);

        if ($errors !== []) {
            throw new SeriesRuleViolation('series_invalid', $this->seriesError($errors[0], $match->best_of, $match->hasGoals()));
        }

        return ['games' => $games, 'roster' => $this->rosterFor($match, $side)];
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws SeriesRuleViolation
     */
    public function prepareReport(SeriesMatch $match, User $user): array
    {
        return $this->reportPlan($match, $user)['templates'];
    }

    /**
     * @param  list<mixed>  $signed
     *
     * @throws SeriesRuleViolation|RejectedEvent
     */
    public function report(SeriesMatch $match, User $user, array $signed): SeriesReport
    {
        $plan = $this->reportPlan($match, $user);
        $events = $this->verify($signed, $plan['templates'], $user);

        return $this->persist($events, function (array $stored) use ($match, $user, $plan): SeriesReport {
            $updated = SeriesMatch::query()->whereKey($match->id)->whereIn('status', [SeriesStatus::Accepted, SeriesStatus::Disputed])
                // A casual no-show claim that came in meanwhile is answered first (P23).
                ->where(fn (Builder $query) => $query->whereNull('origin')->orWhereNull('noshow_reported_at'))
                ->update(['status' => SeriesStatus::Reported, 'new_report_requested_at' => null]);

            if ($updated !== 1) {
                throw new SeriesRuleViolation('not_reportable', __('A result cannot be submitted right now.'));
            }

            SeriesReport::query()->where('series_match_id', $match->id)->whereIn('status', [ReportStatus::Open, ReportStatus::Disputed])
                ->update(['status' => ReportStatus::Superseded]);

            // Sent after the commit (Broadcasts), like every series change.
            $this->broadcastChange($match);

            $report = SeriesReport::query()->create([
                'series_match_id' => $match->id,
                'user_id' => $user->id,
                'side' => $plan['side'],
                'games' => $plan['games'],
                'roster' => $plan['roster'],
                'status' => ReportStatus::Open,
                'event_id' => $stored[0]->id ?? null,
            ]);

            // A casual 1v1 (P23) runs on short deadlines: the other player hears of it at once.
            if ($match->isCasualPairing()) {
                $this->casualNotifications->reportToConfirm($match->refresh());
            }

            return $report;
        });
    }

    /**
     * @return array{side: string, games: list<array{winner: string, challenger: int|null, challenged: int|null}>, roster: list<array{user_id: int, pubkey: string, name: string, side: string, role: string}>, templates: list<array<string, mixed>>}
     */
    private function reportPlan(SeriesMatch $match, User $user): array
    {
        $match = $this->fresh($match);
        $this->assertPlayersReport($match);
        $side = $match->captainSideOf($user);

        if ($side === null) {
            throw new SeriesRuleViolation('not_captain', __('Only a captain can submit the final score.'));
        }

        $this->assertNoCasualClaim($match);

        if (! in_array($match->status, [SeriesStatus::Accepted, SeriesStatus::Disputed], true)) {
            throw new SeriesRuleViolation('not_reportable', __('A result cannot be submitted right now.'));
        }

        if ($match->start_at === null || $match->start_at->isFuture()) {
            throw new SeriesRuleViolation('not_started', __('The match has not started yet.'));
        }

        ['games' => $games, 'roster' => $roster] = $this->draftReport($match, $side);

        $templates = [];

        if ($match->rated) {
            $other = $this->captainPubkeys($match->lineup(SeriesMatch::otherSide($side)))[0] ?? null;
            $templates[] = SeriesEvents::report($match, $this->requireEvent($match->challengeEvent), $games, $roster, $other);
        }

        return ['side' => $side, 'games' => $games, 'roster' => $roster, 'templates' => $templates];
    }

    /**
     * Both rosters of the report. The team size is judged on the reporting
     * side only (`$reporting`; null = both, where no side reports): a side
     * that came short, say an entered player left the clan, is that side's
     * problem and never blocks the other side's report of a result it won.
     *
     * @return list<array{user_id: int, pubkey: string, name: string, side: string, role: string}>
     */
    private function rosterFor(SeriesMatch $match, ?string $reporting = null): array
    {
        $roster = [];

        foreach (SeriesMatch::SIDES as $side) {
            $seats = $this->rosterSeats($match, $side);

            if (($reporting === null || $reporting === $side) && count($seats) < $match->gameMode()->teamSize) {
                throw new SeriesRuleViolation('roster_short', __('":clan" needs at least :count players in "Who played".', ['clan' => $match->sideName($side), 'count' => $match->gameMode()->teamSize]));
            }

            foreach ($seats as $seat) {
                $roster[] = ['user_id' => $seat->user_id, 'pubkey' => $seat->user->pubkey, 'name' => $seat->user->displayName(), 'side' => $side, 'role' => $seat->role->value];
            }
        }

        return $roster;
    }

    private function seriesError(string $code, int $bestOf, bool $hasGoals = true): string
    {
        return match (true) {
            $code === 'series_not_finished' => __('The series is not finished: one side needs :wins game wins in a best of :bo.', ['wins' => intdiv($bestOf, 2) + 1, 'bo' => $bestOf]),
            $code === 'no_games' => __('Enter the result of each game first.'),
            str_ends_with($code, '_after_series_end') => __('The series was already decided before game :number.', ['number' => (int) filter_var($code, FILTER_SANITIZE_NUMBER_INT)]),
            str_ends_with($code, '_goals') && ! $hasGoals => __('Game :number has a score, but this game records only its winner.', ['number' => (int) filter_var($code, FILTER_SANITIZE_NUMBER_INT)]),
            str_ends_with($code, '_goals') => __('The goals of game :number do not match its winner.', ['number' => (int) filter_var($code, FILTER_SANITIZE_NUMBER_INT)]),
            default => __('This result is not valid for the format.'),
        };
    }

    /* ---------- Result response (2153): accept or report a problem ------------------------------------------- */

    /**
     * @param  'confirmed'|'disputed'  $status
     * @return list<array<string, mixed>>
     *
     * @throws SeriesRuleViolation
     */
    public function prepareResponse(SeriesMatch $match, User $user, string $status, string $reason = ''): array
    {
        return $this->responsePlan($match, $user, $status, $reason)['templates'];
    }

    /**
     * @param  'confirmed'|'disputed'  $status
     * @param  list<mixed>  $signed
     *
     * @throws SeriesRuleViolation|RejectedEvent
     */
    public function respond(SeriesMatch $match, User $user, string $status, string $reason, array $signed): void
    {
        $plan = $this->responsePlan($match, $user, $status, $reason);
        $events = $this->verify($signed, $plan['templates'], $user);
        $report = $plan['report'];

        $this->persist($events, function (array $stored) use ($match, $user, $status, $reason, $report): void {
            $claimed = SeriesReport::query()->whereKey($report->id)->where('status', ReportStatus::Open)->update([
                'status' => $status === 'confirmed' ? ReportStatus::Confirmed : ReportStatus::Disputed,
                'responded_by_id' => $user->id,
                'response_reason' => $status === 'disputed' ? trim($reason) : null,
                'response_event_id' => $stored[0]->id ?? null,
                'responded_at' => now(),
            ]);

            if ($claimed !== 1) {
                throw new SeriesRuleViolation('report_answered', __('This result was answered already.'));
            }

            $wins = $report->score();

            // Only while the match still waits for this answer: an admin who
            // decided it in the meantime wins, and nothing here is written.
            $updated = SeriesMatch::query()->whereKey($match->id)->where('status', SeriesStatus::Reported)->update($status === 'confirmed' ? [
                'status' => SeriesStatus::Confirmed,
                'result_games' => json_encode($report->games),
                'winner' => $wins['challenger'] > $wins['challenged'] ? 'challenger' : 'challenged',
                'resolution' => SeriesResolution::Confirmed,
                'finished_at' => now(),
            ] : ['status' => SeriesStatus::Disputed]);

            if ($updated !== 1) {
                throw new SeriesRuleViolation('already_decided', __('This match was decided in between. Please look again.'));
            }

            if ($status === 'confirmed') {
                $this->rateAndAttest($match);
            }
        });

        $match->refresh();
        $this->broadcastChange($match);
        $this->notifyCasualResult($match);
    }

    /**
     * @return array{report: SeriesReport, templates: list<array<string, mixed>>}
     */
    private function responsePlan(SeriesMatch $match, User $user, string $status, string $reason): array
    {
        $match = $this->fresh($match);
        $this->assertPlayersReport($match);
        $report = $match->latestReport;

        if ($match->status !== SeriesStatus::Reported || $report === null || $report->status !== ReportStatus::Open) {
            throw new SeriesRuleViolation('nothing_to_answer', __('There is no result to answer.'));
        }

        $side = $match->captainSideOf($user);

        if ($side === null || $side === $report->side) {
            throw new SeriesRuleViolation('not_other_captain', __('Only a captain of the other lineup can answer this result.'));
        }

        if (! in_array($status, ['confirmed', 'disputed'], true)) {
            throw new SeriesRuleViolation('status', __('That did not work, please try again.'));
        }

        $reason = trim($reason);

        if ($status === 'disputed' && ($reason === '' || mb_strlen($reason) > self::REASON_MAX)) {
            throw new SeriesRuleViolation('reason', __('Say what went wrong, in up to :max characters.', ['max' => self::REASON_MAX]));
        }

        $templates = $match->rated
            ? [SeriesEvents::response($match, $this->requireEvent($report->event), $this->requireEvent($match->challengeEvent), $status, $reason)]
            : [];

        return ['report' => $report, 'templates' => $templates];
    }

    /**
     * A screenshot for the dispute: private disk, admins only, never published.
     */
    public function addEvidence(SeriesMatch $match, User $user, UploadedFile $file): DisputeEvidence
    {
        $match = $this->fresh($match);

        if ($match->participantSideOf($user) === null || ! in_array($match->status, [SeriesStatus::Reported, SeriesStatus::Disputed], true)) {
            throw new SeriesRuleViolation('evidence_closed', __('Evidence can be added while a result is open or disputed.'));
        }

        if ($match->evidence()->count() >= 10) {
            throw new SeriesRuleViolation('evidence_full', __('This match has enough screenshots.'));
        }

        return DisputeEvidence::query()->create([
            'series_match_id' => $match->id,
            'user_id' => $user->id,
            'path' => $file->store('dispute-evidence/'.$match->number, 'local'),
            'name' => mb_substr($file->getClientOriginalName(), 0, 120),
        ]);
    }

    /* ---------- Admin ------------------------------------------------------------------------------------------ */

    /**
     * An admin decides a disputed series, a report not yet confirmed, a
     * no-show, or an accepted tournament series nobody reported (P18; see
     * isDecidable()) with NIP `resolution` admin, forfeit or void; the reason
     * is public. Admins never decide a case of their own clan or one they play
     * in (AdminDisputes.dc.html, security gate P8c F1).
     *
     * `false_report` (P41) decides a dispute against the captain who reported:
     * the disputed report was false, so his side loses by forfeit, and he gets
     * a confirmed false report ({@see FalseReport}); enough of them bar him
     * from rated play for a while ({@see FairPlay::lockedUntil()}).
     *
     * @param  array{type: 'report', report: int}|array{type: 'result', games: list<array{winner: string, challenger: int|null, challenged: int|null}>}|array{type: 'forfeit', winner: string}|array{type: 'void'}|array{type: 'false_report', report: int}  $decision
     *
     * @throws SeriesRuleViolation
     */
    public function decide(SeriesMatch $match, User $admin, array $decision, string $reason): void
    {
        $match = $this->fresh($match);
        $this->assertCanDecide($match, $admin);

        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 500) {
            throw new SeriesRuleViolation('reason', __('Give a public reason, up to :max characters.', ['max' => 500]));
        }

        [$resolution, $winner, $games] = match ($decision['type']) {
            'report' => $this->decideByReport($match, (int) $decision['report']),
            'result' => $this->decideByResult($match, $decision['games']),
            'forfeit' => in_array($decision['winner'], SeriesMatch::SIDES, true)
                ? [SeriesResolution::Forfeit, $decision['winner'], $match->latestReport?->games]
                : throw new SeriesRuleViolation('winner', __('Pick the side that wins by forfeit.')),
            'void' => [SeriesResolution::Void, 'none', $match->latestReport?->games],
            'false_report' => $this->decideFalseReport($match, (int) $decision['report']),
        };

        $roster = $this->decisionRoster($match, $decision);
        $falseReport = $decision['type'] === 'false_report' ? $match->reports()->whereKey((int) $decision['report'])->first() : null;

        // The decision and its rating change commit together.
        DB::transaction(function () use ($match, $admin, $resolution, $winner, $games, $reason, $roster, $falseReport): void {
            $updated = SeriesMatch::query()->whereKey($match->id)->where('status', $match->status)->update([
                'status' => SeriesStatus::Resolved,
                'resolution' => $resolution,
                'winner' => $winner,
                'result_games' => $games === null ? null : json_encode($games),
                'resolved_roster' => $roster === null ? null : json_encode($roster),
                'resolution_reason' => $reason,
                'resolved_by_id' => $admin->id,
                'finished_at' => now(),
            ]);

            if ($updated !== 1) {
                throw new SeriesRuleViolation('changed', __('This match changed in between. Please look again.'));
            }

            if ($falseReport?->user !== null) {
                FalseReport::query()->create([
                    'user_id' => $falseReport->user->id,
                    'pubkey' => $falseReport->user->pubkey,
                    'series_match_id' => $match->id,
                    'series_report_id' => $falseReport->id,
                    'decided_by_id' => $admin->id,
                ]);
            }

            $this->rateAndAttest($match);
        });

        $this->broadcastChange($match);
        $this->notifyCasualResult($match);
    }

    /**
     * Each active player's clan now (pubkey => clan address): stored at the
     * accept for consensus rule 3 and the 2154 `clan` rows.
     *
     * @return array<string, string>
     */
    private function clansOf(SeriesMatch $match): array
    {
        $clans = [];

        foreach ([$match->challengerLineup, $match->challengedLineup] as $lineup) {
            foreach ($lineup === null ? [] : $lineup->activeSeats() as $seat) {
                $clans[$seat->user->pubkey] = $lineup->clan->address();
            }
        }

        return $clans;
    }

    /**
     * Tell every player of both lineups and both clan owners that the series
     * changed, after the commit (App\Support\Chess\Broadcasts: a websocket
     * failure never undoes the change). Their match dock refreshes on it.
     */
    private function broadcastChange(SeriesMatch $match): void
    {
        $fresh = $match->fresh(['challengerLineup.clan', 'challengedLineup.clan']);

        if ($fresh === null) {
            return;
        }

        $users = LineupSeat::query()->whereIn('lineup_id', array_filter([$fresh->challenger_lineup_id, $fresh->challenged_lineup_id]))
            ->whereNotNull('accepted_at')->pluck('user_id')->all();

        foreach ([$fresh->challengerLineup, $fresh->challengedLineup] as $lineup) {
            if ($lineup !== null) {
                $users[] = $lineup->clan->owner_id;
            }
        }

        $users = array_values(array_unique(array_map(intval(...), [...$users, ...$fresh->rosterSide('challenger'), ...$fresh->rosterSide('challenged')])));

        if ($users !== []) {
            Broadcasts::send(new SeriesMatchChanged($users, $fresh->number, $fresh->status->value));
        }
    }

    /**
     * Inside the result's transaction: the rating change first, then the
     * league attestation, which copies the Elo rows and is checked by the
     * season chain's consensus rules (P7c).
     */
    private function rateAndAttest(SeriesMatch $match): void
    {
        $this->ratings->applySeries(SeriesMatch::query()->findOrFail($match->id));
        $this->chains->attestSeries(SeriesMatch::query()->findOrFail($match->id));

        // A tournament series moves its bracket once the result is committed (P8b).
        if ($match->tournament_match_id !== null) {
            $id = $match->id;
            DB::afterCommit(fn () => app(TournamentRunner::class)->seriesFinished($id));
        }
    }

    /**
     * In a tournament whose directors enter the results, the players report,
     * confirm and score nothing themselves (TOURNAMENT-FORMATS.md, section 6).
     *
     * @throws SeriesRuleViolation
     */
    private function assertPlayersReport(SeriesMatch $match): void
    {
        if (self::isDirectorEntered($match)) {
            throw new SeriesRuleViolation('director_results', __('Results are entered by the tournament directors.'));
        }
    }

    /**
     * A pending casual no-show claim (P23) is answered by contesting it:
     * the accused side neither scores nor reports around it.
     *
     * @throws SeriesRuleViolation
     */
    private function assertNoCasualClaim(SeriesMatch $match): void
    {
        if ($match->isCasualPairing() && $match->noshow_reported_at !== null) {
            throw CasualMatches::refuse('noshow_pending');
        }

        // A scheduled casual 1v1 (P23 S4) is played once both checked in.
        if ($match->isCasualPairing() && $match->status === SeriesStatus::Accepted && ! $match->casualUnderWay()) {
            throw CasualMatches::refuse('not_checked_in');
        }
    }

    public static function isDirectorEntered(SeriesMatch $match): bool
    {
        return $match->tournament_match_id !== null
            && ($match->tournamentMatch?->tournament?->isDirectorMode() ?? false);
    }

    public function requestNewReport(SeriesMatch $match, User $admin): void
    {
        $match = $this->fresh($match);
        $this->assertCanDecide($match, $admin);

        if ($match->status !== SeriesStatus::Disputed) {
            throw new SeriesRuleViolation('not_disputed', __('Only a disputed match can get a new report.'));
        }

        $match->update(['new_report_requested_at' => now()]);
        $this->broadcastChange($match);
    }

    /**
     * Open cases for the admins, listed in the disputes queue: disputed, a
     * no-show, a report nobody answered for `unanswered_report_hours` (P18;
     * same rule as the SeriesMatch::openCase() scope).
     */
    public static function isOpenCase(SeriesMatch $match): bool
    {
        return $match->status === SeriesStatus::Disputed
            || ($match->status === SeriesStatus::Accepted && (($match->noshow_reported_at !== null && ! $match->isCasualPairing()) || $match->overdue_at !== null))
            || $match->isUnansweredReport();
    }

    /* ---------- Tournament deadlines (P18, slice 2; TournamentScheduler) -------------------------------------- */

    /**
     * A tournament series nobody reported by its report deadline joins the
     * admin queue (`overdue_at`, SeriesMatch::openCase()). Set once: the
     * update only takes a row still accepted, without a no-show and not yet
     * overdue, so a second run finds nothing. True if this call moved it.
     */
    public function markOverdue(SeriesMatch $match): bool
    {
        if (self::isDirectorEntered($match)) {
            return false;
        }

        $updated = DB::transaction(function () use ($match): int {
            // Re-read under the tournament's lock: a pause, or a resume that moved the deadline, after the tick read the series wins.
            $locked = $this->lockForDeadline($match);
            $due = $locked?->reportDueAt();

            if ($due === null || $due->isFuture()) {
                return 0;
            }

            return self::notPaused(SeriesMatch::query()->whereKey($match->id)->where('status', SeriesStatus::Accepted)
                ->whereNull('noshow_reported_at')->whereNull('overdue_at'))
                ->update(['overdue_at' => now()]);
        });

        if ($updated === 1) {
            $this->broadcastChange($match);
        }

        return $updated === 1;
    }

    /**
     * A reported no-show the other side did not answer (no game entered, no
     * report) by the response deadline: the league forfeits the series to
     * the side that showed up, unrated (P18: the league's own decisions move
     * no Elo; a rated series is attested as `forfeit` without `elo`).
     */
    public function forfeitNoShow(SeriesMatch $match): bool
    {
        $match = $this->fresh($match);
        $due = $match->noshowForfeitAt();

        if ($due === null || $due->isFuture() || $match->status !== SeriesStatus::Accepted || $match->currentGames() !== []
            || self::isDirectorEntered($match) || ! in_array($match->noshow_side, SeriesMatch::SIDES, true)) {
            return false;
        }

        return $this->leagueDecides($match, SeriesStatus::Accepted, [
            'resolution' => SeriesResolution::Forfeit,
            'winner' => $match->noshow_side,
            'result_games' => null,
            'resolved_roster' => null,
            'resolution_reason' => 'No-show reported; the other side did not answer within '.$match->responseMinutes().' minutes.',
        ], fn (SeriesMatch $locked): bool => $locked->noshowForfeitAt()?->isPast() ?? false);
    }

    /**
     * A report the other side did not answer by the response deadline: the
     * league confirms it, unrated (CEO default P18: silence earns no Elo).
     * The result is the league's decision (resolution `admin`) with the
     * report's games and roster; the report itself stays as sent.
     */
    public function autoConfirm(SeriesMatch $match): bool
    {
        $match = $this->fresh($match);
        $due = $match->responseDueAt();
        $report = $match->latestReport;

        if ($due === null || $due->isFuture() || $report === null || self::isDirectorEntered($match)) {
            return false;
        }

        $wins = $report->score();

        return $this->leagueDecides($match, SeriesStatus::Reported, [
            'resolution' => SeriesResolution::Admin,
            'winner' => $wins['challenger'] > $wins['challenged'] ? 'challenger' : 'challenged',
            'result_games' => json_encode($report->games),
            'resolved_roster' => json_encode($report->roster),
            'resolution_reason' => 'Report not answered within '.$match->responseMinutes().' minutes; confirmed by the league, unrated.',
        ], fn (SeriesMatch $locked): bool => $locked->responseDueAt()?->isPast() ?? false);
    }

    /**
     * The league closes a running tournament series on an organizer's or an
     * admin's word (P18, TournamentControl): `void` (no result: a round
     * restart, a pairing a correction changed, the tournament called off)
     * or the result they set (resolution `admin`, `forfeit` for a no-show).
     * Unrated like the league's other decisions, and attested as they are.
     * The reason is public, as an admin's. Once only: false when the series
     * is no longer running (it ended, or a concurrent request closed it).
     *
     * A deadline's own check (`$stillDue`, the casual 1v1 scheduler of
     * P23) runs on the series as it is inside the decision's transaction,
     * as for the tournament deadlines ({@see leagueDecides()}).
     *
     * @param  array{resolution: SeriesResolution, winner: string, games: list<array<string, mixed>>|null}  $decision
     * @param  (Closure(SeriesMatch): bool)|null  $stillDue
     */
    public function leagueClose(SeriesMatch $match, array $decision, string $reason, ?User $by, ?Closure $stillDue = null): bool
    {
        $match = $this->fresh($match);

        if (! $match->status->isRunning()) {
            return false;
        }

        $result = $decision['resolution'] === SeriesResolution::Admin;

        return $this->leagueDecides($match, $match->status, [
            'resolution' => $decision['resolution'],
            'winner' => $decision['winner'],
            'result_games' => $decision['games'] === null ? null : json_encode($decision['games']),
            // As an admin decision: a result names who played by the latest report; forfeit and void name nobody.
            'resolved_roster' => $result && $match->latestReport !== null ? json_encode($match->latestReport->roster) : null,
            'resolution_reason' => mb_substr($reason, 0, 500),
            'resolved_by_id' => $by?->id,
        ], $stillDue);
    }

    /**
     * The league decides a series at a deadline, exactly once: the update
     * only takes the row while it is still in `$from`, so a concurrent run
     * (or an admin or a player who acted first) leaves it untouched and this
     * returns false. No rating change; the attestation and the bracket follow
     * as for any result.
     *
     * A deadline (`$stillDue`, the tick) is re-checked on the series as it
     * is under the tournament's lock, and never applied while the
     * tournament is paused (P18): the tick read its batch before, and a
     * pause or a resume may have come in between. An organizer's or
     * admin's own decision (leagueClose()) passes no deadline and works in
     * a pause too.
     *
     * @param  array<string, mixed>  $values
     * @param  (Closure(SeriesMatch): bool)|null  $stillDue
     */
    private function leagueDecides(SeriesMatch $match, SeriesStatus $from, array $values, ?Closure $stillDue = null): bool
    {
        $decided = DB::transaction(function () use ($match, $from, $values, $stillDue): bool {
            $query = SeriesMatch::query()->whereKey($match->id)->where('status', $from);

            if ($stillDue !== null) {
                $locked = $this->lockForDeadline($match);

                if ($locked === null || ! $stillDue($locked)) {
                    return false;
                }

                $query = self::notPaused($query);
            }

            $updated = $query->update($values + ['status' => SeriesStatus::Resolved, 'resolved_by_id' => null, 'finished_at' => now()]);

            if ($updated !== 1) {
                return false;
            }

            $this->chains->attestSeries(SeriesMatch::query()->findOrFail($match->id));

            if ($match->tournament_match_id !== null) {
                $id = $match->id;
                DB::afterCommit(fn () => app(TournamentRunner::class)->seriesFinished($id));
            }

            return true;
        });

        if ($decided) {
            $this->broadcastChange($match);
            $this->notifyCasualResult($match);
        }

        return $decided;
    }

    /**
     * Both players of a casual 1v1 (P23) hear of its result, however it
     * came about: confirmed, decided by an admin, or by the league at a
     * deadline.
     */
    private function notifyCasualResult(SeriesMatch $match): void
    {
        $match = SeriesMatch::query()->find($match->id);

        if ($match !== null && $match->isCasualPairing() && $match->status->hasResult()) {
            $this->casualNotifications->result($match);
        }
    }

    /**
     * Inside a deadline's transaction: lock the tournament row (the lock
     * TournamentControl's pause() and resume() take) and then the series
     * row, both read anew. Null when the tournament is paused or no longer
     * running: no deadline applies now.
     */
    private function lockForDeadline(SeriesMatch $match): ?SeriesMatch
    {
        if ($match->tournament_match_id !== null) {
            $tournament = Tournament::query()->whereKey(TournamentMatch::query()->whereKey($match->tournament_match_id)->select('tournament_id'))
                ->lockForUpdate()->first();

            if ($tournament === null || $tournament->status !== TournamentStatus::Running || $tournament->isPaused()) {
                return null;
            }
        }

        return SeriesMatch::query()->with('latestReport')->lockForUpdate()->find($match->id);
    }

    /**
     * The guarded update of a deadline takes only a series whose tournament
     * is not paused, read at the update itself (P18).
     *
     * @param  Builder<SeriesMatch>  $query
     * @return Builder<SeriesMatch>
     */
    private static function notPaused(Builder $query): Builder
    {
        return $query->where(fn (Builder $query) => $query->whereNull('tournament_match_id')
            ->orWhereHas('tournamentMatch.tournament', fn (Builder $tournament) => $tournament->whereNull('paused_at')));
    }

    /**
     * What an admin may decide: an open case, any report not yet confirmed,
     * and an accepted tournament series of a players-mode tournament with
     * neither a report nor a no-show (P18: it would block its bracket).
     */
    public static function isDecidable(SeriesMatch $match): bool
    {
        // A casual 1v1 (P23) runs on the players and its clock; an admin steps in only once it is disputed.
        if ($match->isCasualPairing()) {
            return $match->status === SeriesStatus::Disputed;
        }

        return self::isOpenCase($match)
            || $match->status === SeriesStatus::Reported
            || ($match->status === SeriesStatus::Accepted && $match->tournament_match_id !== null && ! self::isDirectorEntered($match));
    }

    private function assertCanDecide(SeriesMatch $match, User $admin): void
    {
        if (! $admin->isAdmin()) {
            throw new SeriesRuleViolation('not_admin', __('Only admins can decide a match.'));
        }

        $clanIds = array_filter([$match->challengerLineup?->clan_id, $match->challengedLineup?->clan_id]);
        // A side without a lineup (a tournament's RL 1v1 player) counts with its player's clan at the pairing.
        $ownClan = $admin->clanMember !== null && (in_array($admin->clanMember->clan_id, $clanIds, true)
            || in_array($admin->clanMember->clan->address(), array_values($match->clans_at_accept ?? []), true));

        if ($ownClan) {
            throw new SeriesRuleViolation('own_clan', __('You cannot decide a case involving your own clan.'));
        }

        // A party decides nothing, clan or no clan (security gate P8c F1): a roster side's player,
        // a seat of either lineup, or a rated subject pinned at the accept or pairing.
        $party = in_array($admin->id, [...$match->rosterSide('challenger'), ...$match->rosterSide('challenged')], true)
            || in_array('user:'.$admin->id, array_values($match->rated_subjects ?? []), true)
            || LineupSeat::query()->whereIn('lineup_id', array_filter([$match->challenger_lineup_id, $match->challenged_lineup_id]))->where('user_id', $admin->id)->exists();

        if ($party) {
            throw new SeriesRuleViolation('own_case', __('You cannot decide a case you play in.'));
        }

        if (! self::isDecidable($match)) {
            throw new SeriesRuleViolation('not_open_case', __('This match has nothing to decide.'));
        }
    }

    /**
     * The roster of an admin decision (NIP: with `admin` roster and score are
     * the league's decision): the chosen report's for a decision by report;
     * for a result of the admin's own, the latest report's or, without any,
     * who played as the room has it (the pinned eligible seats in a rated
     * match), so a result decided without a report still names its winners
     * (security gate F2). Forfeit and void copy the latest report, if any.
     *
     * @param  array<string, mixed>  $decision
     * @return list<array{user_id: int, pubkey: string, name: string, side: string, role: string}>|null
     */
    private function decisionRoster(SeriesMatch $match, array $decision): ?array
    {
        if ($decision['type'] === 'report') {
            return $match->reports()->whereKey((int) $decision['report'])->first()?->roster;
        }

        if ($decision['type'] !== 'result') {
            return null;
        }

        if ($match->latestReport !== null) {
            return $match->latestReport->roster;
        }

        try {
            return $this->rosterFor($match);
        } catch (SeriesRuleViolation) {
            return null;
        }
    }

    /**
     * @return array{0: SeriesResolution, 1: string, 2: list<array<string, mixed>>}
     */
    private function decideByReport(SeriesMatch $match, int $reportId): array
    {
        $report = $match->reports()->whereKey($reportId)->first()
            ?? throw new SeriesRuleViolation('report_missing', __('This report does not belong to the match.'));
        $wins = $report->score();

        return [SeriesResolution::Admin, $wins['challenger'] > $wins['challenged'] ? 'challenger' : 'challenged', $report->games];
    }

    /**
     * A disputed report was false (P41): the reporting side loses by forfeit.
     * Only the report the other captain disputed, while the match is still
     * disputed.
     *
     * @return array{0: SeriesResolution, 1: string, 2: null}
     *
     * @throws SeriesRuleViolation
     */
    private function decideFalseReport(SeriesMatch $match, int $reportId): array
    {
        $report = $match->reports()->whereKey($reportId)->first()
            ?? throw new SeriesRuleViolation('report_missing', __('This report does not belong to the match.'));

        if ($match->status !== SeriesStatus::Disputed || $report->status !== ReportStatus::Disputed) {
            throw new SeriesRuleViolation('not_disputed', __('Only a report the other captain disputed can be ruled false.'));
        }

        return [SeriesResolution::Forfeit, SeriesMatch::otherSide($report->side), null];
    }

    /**
     * @param  list<array<string, mixed>>  $games
     * @return array{0: SeriesResolution, 1: string, 2: list<array<string, mixed>>}
     */
    private function decideByResult(SeriesMatch $match, array $games): array
    {
        $errors = $this->games->get($match->game)->validateResult($match->gameMode(), ['bo' => $match->best_of, 'games' => $games]);

        if ($errors !== []) {
            throw new SeriesRuleViolation('series_invalid', $this->seriesError($errors[0], $match->best_of, $match->hasGoals()));
        }

        $wins = SeriesMatch::seriesScore($games);

        return [SeriesResolution::Admin, $wins['challenger'] > $wins['challenged'] ? 'challenger' : 'challenged', $games];
    }

    /* ---------- Helpers ---------------------------------------------------------------------------------------- */

    private function loadLineup(int $id): ?Lineup
    {
        return Lineup::query()->with(['clan', 'seats.user.clanMember'])->find($id);
    }

    private function fresh(SeriesMatch $match): SeriesMatch
    {
        return SeriesMatch::query()
            ->with(['challengerLineup.clan', 'challengerLineup.seats.user.clanMember', 'challengedLineup.clan', 'challengedLineup.seats.user.clanMember', 'latestReport.event', 'challengeEvent', 'createdBy'])
            ->findOrFail($match->id);
    }

    /**
     * @return list<string>
     */
    public function captainPubkeys(?Lineup $lineup): array
    {
        if ($lineup === null) {
            return [];
        }

        $owner = $lineup->clan->owner_pubkey;
        $captains = $lineup->seats->filter(fn (LineupSeat $seat) => $seat->role === LineupRole::Captain && $seat->accepted_at !== null)
            ->map(fn (LineupSeat $seat) => $seat->user->pubkey)->all();

        return array_values(array_unique([$owner, ...$captains]));
    }

    private function requireEvent(?NostrEvent $event): NostrEvent
    {
        return $event ?? throw new SeriesRuleViolation('event_missing', __('The signed record of this match is missing.'));
    }

    private function notifyChallenged(SeriesMatch $match): void
    {
        $lineup = $match->challengedLineup;

        if ($lineup === null) {
            return;
        }

        foreach ($lineup->seats->filter(fn (LineupSeat $seat) => $lineup->isActingCaptain($seat->user)) as $seat) {
            $locale = $seat->user->locale ?? config('app.locale');

            $this->notifier->send($seat->user, NotificationKind::Challenge, new Notice(
                __('New challenge from :clan', ['clan' => $match->challenger_name], $locale),
                __(':mode, best of :bo, match :number. Answer by :time.', [
                    'mode' => $this->games->name($match->game).' '.$match->mode,
                    'bo' => $match->best_of,
                    'number' => $match->label(),
                    'time' => $match->respond_by->copy()->timezone($seat->user->timezone ?? config('esports.preseason.display_timezone'))->format('D H:i'),
                ], $locale),
                route('matches.room', $match),
                $match->number,
            ), sender: $match->createdBy);
        }
    }

    /**
     * @param  list<mixed>  $signed
     * @param  list<array<string, mixed>>  $templates
     * @return list<SignedEvent>
     *
     * @throws RejectedEvent
     */
    private function verify(array $signed, array $templates, User $author): array
    {
        if (count($signed) !== count($templates)) {
            throw new RejectedEvent('event_count');
        }

        $events = [];

        foreach ($templates as $index => $template) {
            /** @var array{kind: int, tags: list<list<string>>, content: string} $template */
            $events[] = $this->gate->check($signed[$index] ?? null, $template, $author);
        }

        return $events;
    }

    /**
     * Store the signed events and write the state in one transaction, then
     * publish after the commit (PublishNostrEvent).
     *
     * @template T
     *
     * @param  list<SignedEvent>  $events
     * @param  callable(list<NostrEvent>): T  $apply
     * @return T
     */
    private function persist(array $events, callable $apply): mixed
    {
        return DB::transaction(function () use ($events, $apply) {
            $stored = array_map(fn (SignedEvent $event) => NostrEvent::fromSigned($event), $events);
            $result = $apply($stored);

            foreach ($stored as $event) {
                PublishNostrEvent::dispatch($event);
            }

            return $result;
        });
    }
}
