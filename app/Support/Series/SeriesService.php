<?php

namespace App\Support\Series;

use App\Enums\LineupRole;
use App\Enums\NotificationKind;
use App\Enums\ReportStatus;
use App\Enums\SeriesResolution;
use App\Enums\SeriesStatus;
use App\Games\GameRegistry;
use App\Jobs\PublishNostrEvent;
use App\Models\DisputeEvidence;
use App\Models\Lineup;
use App\Models\LineupSeat;
use App\Models\MatchNumber;
use App\Models\NostrEvent;
use App\Models\SeriesMatch;
use App\Models\SeriesReport;
use App\Models\User;
use App\Support\Nostr\RejectedEvent;
use App\Support\Nostr\SignedEvent;
use App\Support\Nostr\SignedEventGate;
use App\Support\Notifications\Notice;
use App\Support\Notifications\Notifier;
use App\Support\Rating\RatingService;
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
                ?? throw new SeriesRuleViolation('rated_not_open', __('Rated matches start at Block 0. Until then every match is casual.'));
        }

        $now = now()->getTimestamp();
        $proposals = array_values(array_unique(array_map(intval(...), $draft->proposals)));
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

        if ($draft->respondBy <= $now || $draft->respondBy > $respondLimit || $draft->respondBy > $proposals[0]) {
            throw new SeriesRuleViolation('respond_by', __('The reply deadline has to be in the future, at the latest at the first suggested time.'));
        }

        $message = trim((string) $draft->message);

        if (mb_strlen($message) > 140) {
            throw new SeriesRuleViolation('message', __('The message can be at most 140 characters.'));
        }

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

        $this->persist($events, function (array $stored) use ($match, $user, $status, $start): void {
            $updated = SeriesMatch::query()->whereKey($match->id)->where('status', SeriesStatus::Open)->update([
                'status' => match ($status) {
                    'accepted' => SeriesStatus::Accepted,
                    'declined' => SeriesStatus::Declined,
                    default => SeriesStatus::Withdrawn,
                },
                'answered_by_id' => $user->id,
                'answered_at' => now(),
                'start_at' => $status === 'accepted' ? now()->setTimestamp((int) $start) : null,
                'finished_at' => $status === 'accepted' ? null : now(),
                'answer_event_id' => $stored[0]->id ?? null,
            ]);

            if ($updated !== 1) {
                throw new SeriesRuleViolation('not_open', __('This challenge is no longer open.'));
            }
        });

        $match->refresh();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function answerPlan(SeriesMatch $match, User $user, string $status, ?int $start): array
    {
        $match = $this->fresh($match);

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
     */
    public function expireDue(): int
    {
        return SeriesMatch::query()
            ->where('status', SeriesStatus::Open)
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
    }

    /**
     * Enter or clear the score of one game on the live sheet (public, marked
     * provisional). With both goals known the winner follows from them; with
     * goals unknown the captain picks the winner (NIP `score`: points unknown).
     */
    public function saveLiveGame(SeriesMatch $match, User $user, int $index, ?int $challengerGoals, ?int $challengedGoals, ?string $winner): void
    {
        $match = $this->fresh($match);

        if ($match->captainSideOf($user) === null) {
            throw new SeriesRuleViolation('not_captain', __('Only a captain can enter the score.'));
        }

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

        $allowed = array_map(fn (LineupSeat $seat) => $seat->user_id, $match->lineup($side)?->activeSeats() ?? []);
        $ids = array_values(array_unique(array_map(intval(...), $userIds)));

        if (array_diff($ids, $allowed) !== []) {
            throw new SeriesRuleViolation('roster_foreign', __('Only active players of your lineup can be on the list.'));
        }

        $rosters = $match->rosters ?? [];
        $rosters[$side] = array_values(array_filter($allowed, fn (int $id) => in_array($id, $ids, true)));
        $match->update(['rosters' => $rosters]);
    }

    /**
     * Who played for a side: the captain's list, or the regulars (captain and
     * player seats) until the captain changes it.
     *
     * @return list<LineupSeat>
     */
    public function rosterSeats(SeriesMatch $match, string $side): array
    {
        $seats = $match->lineup($side)?->activeSeats() ?? [];
        $chosen = $match->rosters[$side] ?? null;

        return array_values(array_filter($seats, fn (LineupSeat $seat) => $chosen === null
            ? $seat->role !== LineupRole::Substitute
            : in_array($seat->user_id, $chosen, true)));
    }

    /**
     * "Opponent didn't show": from `noshow_minutes` after the start, while no
     * game is entered and nobody reported. An admin decides (forfeit/void).
     */
    public function reportNoShow(SeriesMatch $match, User $user): void
    {
        $match = $this->fresh($match);
        $side = $match->captainSideOf($user);

        if ($side === null) {
            throw new SeriesRuleViolation('not_captain', __('Only a captain can report a no-show.'));
        }

        $from = $match->start_at?->copy()->addMinutes((int) config('esports.series.noshow_minutes', 15));

        if ($match->status !== SeriesStatus::Accepted || $from === null || $from->isFuture()) {
            throw new SeriesRuleViolation('noshow_early', __('A no-show can be reported :minutes minutes after the start.', ['minutes' => (int) config('esports.series.noshow_minutes', 15)]));
        }

        if ($match->currentGames() !== [] || $match->noshow_reported_at !== null) {
            throw new SeriesRuleViolation('noshow_games', __('Games are entered already, so this is not a no-show.'));
        }

        $match->update(['noshow_side' => $side, 'noshow_reported_at' => now()]);
    }

    /* ---------- Result report (2152) -------------------------------------------------------------------------- */

    /**
     * What "Submit final score" would send: the games of the live sheet and
     * both rosters, checked against the game's rules.
     *
     * @return array{games: list<array{winner: string, challenger: int|null, challenged: int|null}>, roster: list<array{user_id: int, pubkey: string, name: string, side: string, role: string}>}
     *
     * @throws SeriesRuleViolation
     */
    public function draftReport(SeriesMatch $match): array
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
            throw new SeriesRuleViolation('series_invalid', $this->seriesError($errors[0], $match->best_of));
        }

        return ['games' => $games, 'roster' => $this->rosterFor($match)];
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
                ->update(['status' => SeriesStatus::Reported, 'new_report_requested_at' => null]);

            if ($updated !== 1) {
                throw new SeriesRuleViolation('not_reportable', __('A result cannot be submitted right now.'));
            }

            SeriesReport::query()->where('series_match_id', $match->id)->whereIn('status', [ReportStatus::Open, ReportStatus::Disputed])
                ->update(['status' => ReportStatus::Superseded]);

            return SeriesReport::query()->create([
                'series_match_id' => $match->id,
                'user_id' => $user->id,
                'side' => $plan['side'],
                'games' => $plan['games'],
                'roster' => $plan['roster'],
                'status' => ReportStatus::Open,
                'event_id' => $stored[0]->id ?? null,
            ]);
        });
    }

    /**
     * @return array{side: string, games: list<array{winner: string, challenger: int|null, challenged: int|null}>, roster: list<array{user_id: int, pubkey: string, name: string, side: string, role: string}>, templates: list<array<string, mixed>>}
     */
    private function reportPlan(SeriesMatch $match, User $user): array
    {
        $match = $this->fresh($match);
        $side = $match->captainSideOf($user);

        if ($side === null) {
            throw new SeriesRuleViolation('not_captain', __('Only a captain can submit the final score.'));
        }

        if (! in_array($match->status, [SeriesStatus::Accepted, SeriesStatus::Disputed], true)) {
            throw new SeriesRuleViolation('not_reportable', __('A result cannot be submitted right now.'));
        }

        if ($match->start_at === null || $match->start_at->isFuture()) {
            throw new SeriesRuleViolation('not_started', __('The match has not started yet.'));
        }

        ['games' => $games, 'roster' => $roster] = $this->draftReport($match);

        $templates = [];

        if ($match->rated) {
            $other = $this->captainPubkeys($match->lineup(SeriesMatch::otherSide($side)))[0] ?? null;
            $templates[] = SeriesEvents::report($match, $this->requireEvent($match->challengeEvent), $games, $roster, $other);
        }

        return ['side' => $side, 'games' => $games, 'roster' => $roster, 'templates' => $templates];
    }

    /**
     * @return list<array{user_id: int, pubkey: string, name: string, side: string, role: string}>
     */
    private function rosterFor(SeriesMatch $match): array
    {
        $roster = [];

        foreach (SeriesMatch::SIDES as $side) {
            $seats = $this->rosterSeats($match, $side);

            if (count($seats) < $match->gameMode()->teamSize) {
                throw new SeriesRuleViolation('roster_short', __('":clan" needs at least :count players in "Who played".', ['clan' => $match->sideName($side), 'count' => $match->gameMode()->teamSize]));
            }

            foreach ($seats as $seat) {
                $roster[] = ['user_id' => $seat->user_id, 'pubkey' => $seat->user->pubkey, 'name' => $seat->user->displayName(), 'side' => $side, 'role' => $seat->role->value];
            }
        }

        return $roster;
    }

    private function seriesError(string $code, int $bestOf): string
    {
        return match (true) {
            $code === 'series_not_finished' => __('The series is not finished: one side needs :wins game wins in a best of :bo.', ['wins' => intdiv($bestOf, 2) + 1, 'bo' => $bestOf]),
            $code === 'no_games' => __('Enter the result of each game first.'),
            str_ends_with($code, '_after_series_end') => __('The series was already decided before game :number.', ['number' => (int) filter_var($code, FILTER_SANITIZE_NUMBER_INT)]),
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
                $this->ratings->applySeries(SeriesMatch::query()->findOrFail($match->id));
            }
        });

        $match->refresh();
    }

    /**
     * @return array{report: SeriesReport, templates: list<array<string, mixed>>}
     */
    private function responsePlan(SeriesMatch $match, User $user, string $status, string $reason): array
    {
        $match = $this->fresh($match);
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
     * An admin decides a disputed series, a stale report or a no-show (NIP
     * `resolution` admin, forfeit or void; the reason is public). Admins never
     * decide a case of their own clan (AdminDisputes.dc.html).
     *
     * @param  array{type: 'report', report: int}|array{type: 'result', games: list<array{winner: string, challenger: int|null, challenged: int|null}>}|array{type: 'forfeit', winner: string}|array{type: 'void'}  $decision
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
        };

        // The decision and its rating change commit together.
        DB::transaction(function () use ($match, $admin, $resolution, $winner, $games, $reason): void {
            $updated = SeriesMatch::query()->whereKey($match->id)->where('status', $match->status)->update([
                'status' => SeriesStatus::Resolved,
                'resolution' => $resolution,
                'winner' => $winner,
                'result_games' => $games === null ? null : json_encode($games),
                'resolution_reason' => $reason,
                'resolved_by_id' => $admin->id,
                'finished_at' => now(),
            ]);

            if ($updated !== 1) {
                throw new SeriesRuleViolation('changed', __('This match changed in between. Please look again.'));
            }

            $this->ratings->applySeries(SeriesMatch::query()->findOrFail($match->id));
        });
    }

    public function requestNewReport(SeriesMatch $match, User $admin): void
    {
        $match = $this->fresh($match);
        $this->assertCanDecide($match, $admin);

        if ($match->status !== SeriesStatus::Disputed) {
            throw new SeriesRuleViolation('not_disputed', __('Only a disputed match can get a new report.'));
        }

        $match->update(['new_report_requested_at' => now()]);
    }

    /**
     * Open cases for the admins: disputed, a report nobody answers, a no-show.
     */
    public static function isOpenCase(SeriesMatch $match): bool
    {
        return $match->status === SeriesStatus::Disputed
            || ($match->status === SeriesStatus::Accepted && $match->noshow_reported_at !== null);
    }

    private function assertCanDecide(SeriesMatch $match, User $admin): void
    {
        if (! $admin->isAdmin()) {
            throw new SeriesRuleViolation('not_admin', __('Only admins can decide a match.'));
        }

        $clanIds = array_filter([$match->challengerLineup?->clan_id, $match->challengedLineup?->clan_id]);

        if ($admin->clanMember !== null && in_array($admin->clanMember->clan_id, $clanIds, true)) {
            throw new SeriesRuleViolation('own_clan', __('You cannot decide a case involving your own clan.'));
        }

        if (! self::isOpenCase($match) && $match->status !== SeriesStatus::Reported) {
            throw new SeriesRuleViolation('not_open_case', __('This match has nothing to decide.'));
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
     * @param  list<array<string, mixed>>  $games
     * @return array{0: SeriesResolution, 1: string, 2: list<array<string, mixed>>}
     */
    private function decideByResult(SeriesMatch $match, array $games): array
    {
        $errors = $this->games->get($match->game)->validateResult($match->gameMode(), ['bo' => $match->best_of, 'games' => $games]);

        if ($errors !== []) {
            throw new SeriesRuleViolation('series_invalid', $this->seriesError($errors[0], $match->best_of));
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
    private function captainPubkeys(?Lineup $lineup): array
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
                    'mode' => 'Rocket League '.$match->mode,
                    'bo' => $match->best_of,
                    'number' => $match->label(),
                    'time' => $match->respond_by->copy()->timezone($seat->user->timezone ?? config('esports.preseason.display_timezone'))->format('D H:i'),
                ], $locale),
                route('matches.room', $match),
                $match->number,
            ));
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
