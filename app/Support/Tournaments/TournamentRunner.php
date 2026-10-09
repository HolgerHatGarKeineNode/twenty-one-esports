<?php

namespace App\Support\Tournaments;

use App\Enums\BoardEndReason;
use App\Enums\BoardGameStatus;
use App\Enums\ChessEndReason;
use App\Enums\ChessGameStatus;
use App\Enums\HyperMatchStatus;
use App\Enums\SeriesResolution;
use App\Enums\SeriesStatus;
use App\Enums\TournamentFormat;
use App\Enums\TournamentStatus;
use App\Events\TournamentChanged;
use App\Games\BoardGame as BoardGameDefinition;
use App\Games\GameRegistry;
use App\Models\BoardGame;
use App\Models\ChessGame;
use App\Models\HyperMatch;
use App\Models\HyperSeat;
use App\Models\SeriesMatch;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentParticipant;
use App\Models\TournamentResultEntry;
use App\Models\TournamentRound;
use App\Models\TournamentStage;
use App\Models\User;
use App\Support\Chess\Broadcasts;
use App\Support\Hyper\HyperCups;
use App\Support\Rating\RatingService;
use App\Support\SeasonChain\SeasonChains;
use App\Support\Series\SeriesService;
use App\Support\Tournaments\Engine\Advancement;
use App\Support\Tournaments\Engine\MatchResult;
use App\Support\Tournaments\Engine\Swiss;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * A running tournament (P8b): results come in, the P8a engine moves the
 * bracket (Advancement), Swiss pairs its next round once a round is
 * complete, and every match that becomes ready starts as a normal match
 * (TournamentMatchMaker).
 *
 * Results come from two places:
 *
 * - **Players** (the default): the normal match ends as it always does (the
 *   series is confirmed or an admin decides it, the chess game finishes) and
 *   its result is copied here. Rounds close by themselves once every result
 *   is in. A drawn knockout game is replayed with the colours swapped.
 * - **Tournament directors** (TOURNAMENT-FORMATS.md, section 6): the creator
 *   and the named directors enter each result of the open round and may
 *   correct it until they close the round; every entry and correction goes
 *   to the append-only log with name and time. A director who plays may not
 *   enter their own match. Closing the round locks its results, writes them
 *   into the normal matches (a finished game record, a decided series) and
 *   only then applies Elo and the league attestation, so a correction never
 *   has to take a rating change back. The next round opens after it.
 *
 * Tournament matches never mine season-chain blocks (SeasonChains refuses
 * them as candidates); they count for Elo like any match.
 *
 * Nothing hangs in players mode (P18): a side whose players have no account
 * left is withdrawn and loses each match it would still play by forfeit; a
 * chess game whose first move was missed is a forfeit, or, when both sides
 * missed it, restarted once and then decided by the double no-show rule; a
 * knockout draw is replayed at most `drawn_replays` times, then the higher
 * seed advances; a voided series is played again. The league's own
 * decisions are unrated (`by` = `league`, TournamentMatch).
 */
final class TournamentRunner
{
    public function __construct(
        private TournamentBrackets $brackets,
        private TournamentMatchMaker $maker,
        private RatingService $ratings,
        private SeasonChains $chains,
        private GameRegistry $games,
    ) {}

    /* ---------- Moving the bracket ---------------------------------------------------------------------------- */

    /**
     * Bring the stored bracket in line with the results: fill the slots,
     * set every match's status, close complete rounds (players mode), pair
     * the next Swiss round, end the tournament once nothing is left, then
     * start the normal matches of whatever became ready. The TV view hears
     * about it after the commit (TournamentChanged, `$reason` says what moved).
     */
    public function sync(Tournament $tournament, string $reason = 'bracket'): void
    {
        if ($tournament->status !== TournamentStatus::Running) {
            return;
        }

        DB::transaction(function () use ($tournament): void {
            $locked = Tournament::query()->lockForUpdate()->findOrFail($tournament->id);

            if ($locked->status !== TournamentStatus::Running) {
                return;
            }

            $this->applyState($locked);

            // A disqualified entry loses in director mode too (P18); a withdrawn one only where the players report.
            while ($this->forfeitWithdrawn($locked)) {
                $this->applyState($locked);
            }

            if (! $locked->isDirectorMode()) {
                $this->closeCompleteRounds($locked);
            }

            if ($this->pairSwissRound($locked)) {
                $this->applyState($locked);
            }

            if ($this->isComplete($locked)) {
                $locked->forceFill(['status' => TournamentStatus::Finished])->save();
            }
        });

        $this->maker->startReady($tournament->refresh());

        Broadcasts::send(new TournamentChanged($tournament->id, $reason));
    }

    private function applyState(Tournament $tournament): void
    {
        $bracket = $this->brackets->load($tournament);
        $state = Advancement::resolve($bracket, $this->brackets->results($tournament), $tournament->formatOptions());

        foreach (TournamentMatch::query()->where('tournament_id', $tournament->id)->with('slots')->get() as $match) {
            if ($match->bracket === 'bye' || ! isset($state[$match->key])) {
                continue;
            }

            $status = $state[$match->key]['status'];

            if ($match->status !== $status) {
                $match->forceFill(['status' => $status])->save();
            }

            foreach ($match->slots as $slot) {
                $entrant = $state[$match->key]['entrants'][$slot->slot] ?? null;

                if ($slot->tournament_participant_id !== $entrant) {
                    $slot->forceFill(['tournament_participant_id' => $entrant])->save();
                }
            }
        }
    }

    private function closeCompleteRounds(Tournament $tournament): void
    {
        // A closed round whose match a correction or a restart opened again (P18) is open again until it is decided.
        TournamentRound::query()->where('status', 'closed')
            ->whereHas('stage', fn ($query) => $query->where('tournament_id', $tournament->id))
            ->whereHas('matches', fn ($query) => $query->whereNotIn('status', ['done', 'skipped']))
            ->update(['status' => 'open', 'closed_at' => null]);

        foreach ($this->openRounds($tournament) as $round) {
            if ($this->isRoundComplete($round)) {
                $round->forceFill(['status' => 'closed', 'closed_at' => now()])->save();
            }
        }
    }

    /**
     * Swiss knows one round at a time: once the last round is closed and more
     * are planned, pair the next one from the results so far.
     */
    private function pairSwissRound(Tournament $tournament): bool
    {
        if ($tournament->format !== TournamentFormat::Swiss) {
            return false;
        }

        $stage = TournamentStage::query()->where('tournament_id', $tournament->id)->where('number', 1)->first();
        $last = $stage === null ? null : TournamentRound::query()->where('tournament_stage_id', $stage->id)->orderByDesc('number')->first();

        if ($stage === null || $last === null || $last->status !== 'closed' || $last->number >= $this->swissRounds($tournament)) {
            return false;
        }

        $options = $tournament->formatOptions();
        $seeds = array_values($tournament->participants()->whereNotNull('seed')->orderBy('seed')->pluck('id')->map(intval(...))->all());
        $matches = Swiss::pairRound($last->number + 1, $seeds, $this->games($tournament), $options->pointsWin, $options->pointsTie, $options->pointsBye);
        $round = TournamentRound::query()->create(['tournament_stage_id' => $stage->id, 'number' => $last->number + 1]);

        foreach ($matches as $match) {
            $row = $tournament->matches()->create([
                'tournament_round_id' => $round->id,
                'key' => $match->key,
                'group' => null,
                'bracket' => $match->bracket,
                'position' => $match->position,
                'status' => $match->bracket === 'bye' ? 'done' : 'ready',
            ]);

            foreach ($match->slots as $index => $slot) {
                $row->slots()->create(['slot' => $index, 'source' => $slot->toArray(), 'tournament_participant_id' => $slot->entrant]);
            }
        }

        return true;
    }

    public function swissRounds(Tournament $tournament): int
    {
        $n = $tournament->participants()->whereNotNull('seed')->count();

        return max(1, min($tournament->formatOptions()->swissRounds ?? Estimator::swissDefault($n), Estimator::swissMax($n)));
    }

    /**
     * Every finished match as the tables read it: `[a, b, result]`, `b` null
     * for a Swiss bye.
     *
     * @return list<array{0: int, 1: int|null, 2: MatchResult}>
     */
    public function games(Tournament $tournament, ?int $group = null, ?int $stage = null): array
    {
        $games = [];
        $matches = TournamentMatch::query()->where('tournament_id', $tournament->id)->where('status', 'done')
            ->when($group !== null, fn ($query) => $query->where('group', $group))
            ->when($stage !== null, fn ($query) => $query->whereHas('round.stage', fn ($q) => $q->where('number', $stage)))
            ->with('slots')->orderBy('id')->get();

        foreach ($matches as $match) {
            $a = $match->slots[0]->tournament_participant_id ?? null;

            if ($a === null) {
                continue;
            }

            if ($match->bracket === 'bye') {
                $games[] = [$a, null, MatchResult::win(0)];

                continue;
            }

            $b = $match->slots[1]->tournament_participant_id ?? null;
            $result = $match->matchResult();

            if ($b !== null && $result !== null && count($match->slots) === 2) {
                $games[] = [$a, $b, $result];
            }
        }

        return $games;
    }

    private function isComplete(Tournament $tournament): bool
    {
        // A leaderboard ends when ScoreLeaderboards::finalize() wrote its board's result, never for want of an open match:
        // a league week opens before anyone joined and its board match comes with the first player (2026-10-05: the
        // minute tick's sync() ended the new Blockfill and TMNF weeks within a minute, so no run reached a board).
        if ($tournament->format === TournamentFormat::Leaderboard) {
            return TournamentMatch::query()->where('tournament_id', $tournament->id)->where('bracket', 'board')->where('status', 'done')->exists();
        }

        $open = TournamentMatch::query()->where('tournament_id', $tournament->id)->whereNotIn('status', ['done', 'skipped'])->exists();

        if ($open) {
            return false;
        }

        if ($tournament->format === TournamentFormat::Swiss) {
            return TournamentRound::query()->whereHas('stage', fn ($query) => $query->where('tournament_id', $tournament->id))->count() >= $this->swissRounds($tournament)
                && ! TournamentRound::query()->whereHas('stage', fn ($query) => $query->where('tournament_id', $tournament->id))->where('status', 'open')->exists();
        }

        return true;
    }

    /**
     * @return Collection<int, TournamentRound>
     */
    private function openRounds(Tournament $tournament): Collection
    {
        return TournamentRound::query()->where('status', 'open')
            ->whereHas('stage', fn ($query) => $query->where('tournament_id', $tournament->id))
            ->with('stage')->get()
            ->sortBy(fn (TournamentRound $round): array => [$round->stage->number, $round->number])->values();
    }

    private function isRoundComplete(TournamentRound $round): bool
    {
        $matches = TournamentMatch::query()->where('tournament_round_id', $round->id)->pluck('status');

        return $matches->isNotEmpty() && $matches->every(fn (string $status): bool => in_array($status, ['done', 'skipped'], true));
    }

    /**
     * The round a director enters results in: the earliest open one.
     */
    public static function currentRound(Tournament $tournament): ?TournamentRound
    {
        return TournamentRound::query()->where('status', 'open')
            ->whereHas('stage', fn ($query) => $query->where('tournament_id', $tournament->id))
            ->with('stage')->get()
            ->sortBy(fn (TournamentRound $round): array => [$round->stage->number, $round->number])->first();
    }

    /**
     * A draw decides a Swiss or round-robin match; a knockout needs a winner.
     */
    public static function allowsDraw(TournamentMatch $match): bool
    {
        $format = $match->round->stage->format;

        return $format === TournamentFormat::Swiss || $format === TournamentFormat::RoundRobin;
    }

    /**
     * The last round of a knockout: the final (and the match for 3rd place
     * next to it), the grand final and its reset.
     */
    public static function isFinal(TournamentMatch $match): bool
    {
        if (in_array($match->bracket, ['grand-final', 'reset', 'third-place'], true)) {
            return true;
        }

        $stage = $match->round->stage;

        if ($stage->format !== TournamentFormat::SingleElimination || $match->group !== null) {
            return false;
        }

        return $match->round->number === (int) TournamentRound::query()->where('tournament_stage_id', $stage->id)->max('number')
            && TournamentStage::query()->where('tournament_id', $stage->tournament_id)->max('number') === $stage->number;
    }

    /* ---------- Results from the normal matches (players mode) ------------------------------------------------- */

    public function seriesFinished(int $seriesMatchId): void
    {
        $series = SeriesMatch::query()->with('tournamentMatch.tournament')->find($seriesMatchId);
        $match = $series?->tournamentMatch;

        // A series the league voided or superseded (P18, TournamentControl) decides nothing here any more.
        if ($series === null || $match === null || $match->tournament->isDirectorMode() || $match->result !== null || $match->isReplaced($series->id)) {
            return;
        }

        // A casual cup's voided series (nobody checked in, nobody reported, P25 S3) is not replayed:
        // the cup's rule decides it (who tried to play, else the draw of lots or the double loss).
        if ($series->resolution === SeriesResolution::Void && $match->tournament->isCasualCup()) {
            $match->load(['slots.participant', 'round.stage']);
            $this->store($match, CasualCups::decision($match->tournament, $match) + ['number' => $series->number]);
            $this->sync($match->tournament);

            return;
        }

        // A voided series is played again (P18): the next sync starts a new attempt.
        if ($series->resolution === SeriesResolution::Void && $series->id === $match->seriesMatch?->id) {
            $this->sync($match->tournament);

            return;
        }

        if (! $series->status->hasResult() || $series->resolution === SeriesResolution::Void || ! in_array($series->winner, SeriesMatch::SIDES, true)) {
            return;
        }

        $score = SeriesMatch::seriesScore($series->result_games);
        $goals = $this->goals($series->result_games ?? []);
        // A forfeit has no games to count (P18); a decision nobody made by hand is the league's (a deadline).
        $forfeit = $series->resolution === SeriesResolution::Forfeit;

        $this->store($match, [
            'winner' => $series->winner === 'challenger' ? 0 : 1,
            'games_won' => $forfeit ? ($series->winner === 'challenger' ? [1.0, 0.0] : [0.0, 1.0]) : [(float) $score['challenger'], (float) $score['challenged']],
            'points' => $goals,
            'games' => $series->result_games ?? [],
            'forfeit' => $forfeit,
            'label' => $forfeit ? __('forfeit') : $score['challenger'].' : '.$score['challenged'],
            'by' => $series->resolution !== SeriesResolution::Confirmed && $series->resolved_by_id === null ? 'league' : 'players',
            'number' => $series->number,
        ]);

        $this->sync($match->tournament);
    }

    public function chessGameFinished(int $chessGameId): void
    {
        $game = ChessGame::query()->with('tournamentMatch.tournament', 'tournamentMatch.round.stage', 'tournamentMatch.slots.participant')->find($chessGameId);
        $match = $game?->tournamentMatch;

        if ($game === null || $match === null || $match->tournament->isDirectorMode() || $match->result !== null || $game->status !== ChessGameStatus::Finished || $match->isReplaced($game->id)) {
            return;
        }

        $whiteSlot = in_array((int) $game->white_id, $match->slots[0]->participant?->memberIds() ?? [], true) ? 0 : 1;

        if ($game->result === '1/2-1/2' && ! self::allowsDraw($match) && $match->tournament->isCasualCup()) {
            // A casual cup (P25): the colours swapped, then Armageddon, where a draw advances Black.
            if (self::drawnGames($match) > CasualCups::ARMAGEDDON_AFTER_DRAWS) {
                $winner = 1 - $whiteSlot;

                $this->store($match, [
                    'winner' => $winner,
                    'games_won' => $winner === 0 ? [1.0, 0.0] : [0.0, 1.0],
                    'points' => [],
                    'forfeit' => false,
                    'decided' => 'armageddon',
                    'label' => __('½–½ Armageddon, Black advances'),
                    'by' => 'players',
                    'number' => $game->number,
                ]);
            }

            $this->sync($match->tournament);

            return;
        }

        if ($game->result === '1/2-1/2' && ! self::allowsDraw($match)) {
            // A knockout needs a winner: the game is replayed (TournamentMatchMaker::needsGame()), and
            // after the last allowed replay the higher seed advances (P18; Armageddon is slice 2).
            if (self::drawnGames($match) > self::drawnReplays()) {
                $this->store($match, $this->seedDecision($match, 'seed') + ['number' => $game->number]);
            }

            $this->sync($match->tournament);

            return;
        }

        $winner = match ($game->result) {
            '1-0' => $whiteSlot,
            '0-1' => 1 - $whiteSlot,
            default => null,
        };

        $forfeit = $game->end_reason === ChessEndReason::Forfeit;

        $this->store($match, [
            'winner' => $winner,
            'games_won' => $winner === null ? [0.5, 0.5] : ($winner === 0 ? [1.0, 0.0] : [0.0, 1.0]),
            'points' => [],
            'forfeit' => $forfeit,
            'label' => $forfeit ? __('forfeit') : self::chessLabel($winner),
            'by' => 'players',
            'number' => $game->number,
        ]);

        $this->sync($match->tournament);
    }

    /**
     * Both sides missed the first move of a tournament game (P18): it is
     * started again `first_move_restarts` times (TournamentMatchMaker::needsGame()),
     * then the double no-show rule decides the match.
     */
    public function chessGameAborted(int $chessGameId): void
    {
        $game = ChessGame::query()->with('tournamentMatch.tournament', 'tournamentMatch.round.stage', 'tournamentMatch.slots.participant')->find($chessGameId);
        $match = $game?->tournamentMatch;

        if ($game === null || $match === null || $match->tournament->isDirectorMode() || $match->result !== null || $game->status !== ChessGameStatus::Aborted || $match->isReplaced($game->id)) {
            return;
        }

        if (self::abortedGames($match) > self::firstMoveRestarts()) {
            $this->store($match, $this->doubleNoShow($match) + ['number' => $game->number]);
        }

        $this->sync($match->tournament);
    }

    /**
     * A board game of a tournament match ended (plan "Mühle und Dame", P5):
     * as chessGameFinished(), except that a drawn knockout game is replayed
     * (colours swapped) up to `drawn_replays` times, a casual cup's too, and
     * then the higher seed advances: the board games know no Armageddon.
     */
    public function boardGameFinished(int $boardGameId): void
    {
        $game = BoardGame::query()->with('tournamentMatch.tournament', 'tournamentMatch.round.stage', 'tournamentMatch.slots.participant')->find($boardGameId);
        $match = $game?->tournamentMatch;

        if ($game === null || $match === null || $match->tournament->isDirectorMode() || $match->result !== null || $game->status !== BoardGameStatus::Finished || $match->isReplaced($game->id)) {
            return;
        }

        if (self::playsTwoLegs($game->game)) {
            $this->twoLegsFinished($match);
            $this->sync($match->tournament);

            return;
        }

        $whiteSlot = in_array((int) $game->white_id, $match->slots[0]->participant?->memberIds() ?? [], true) ? 0 : 1;

        if ($game->result === '1/2-1/2' && ! self::allowsDraw($match)) {
            if (self::drawnBoardGames($match) > self::drawnReplays($match->tournament)) {
                $this->store($match, $this->seedDecision($match, 'seed'));
            }

            $this->sync($match->tournament);

            return;
        }

        $winner = match ($game->result) {
            '1-0' => $whiteSlot,
            '0-1' => 1 - $whiteSlot,
            default => null,
        };

        $forfeit = $game->end_reason === BoardEndReason::Forfeit->value;

        $this->store($match, [
            'winner' => $winner,
            'games_won' => $winner === null ? [0.5, 0.5] : ($winner === 0 ? [1.0, 0.0] : [0.0, 1.0]),
            'points' => [],
            'forfeit' => $forfeit,
            'label' => $forfeit ? __('forfeit') : self::chessLabel($winner),
            'by' => 'players',
        ]);

        $this->sync($match->tournament);
    }

    /**
     * A tournament's Hyperbitcoinization match ended (plan "Hyperbitcoinization", P5; HyperMatches, after its
     * commit): the places of its seats are the match's result, per slot, closed up among the entries (a cup's bots
     * hold places too, but no slot). A clan bracket's team match (P5b) places each entry by its team's best seat, so
     * the team win is the bracket's winner. A table of two is a 1v1 with a winner; a larger table a heat whose best move on
     * (Advancement). Stored in either results mode, since the league's server played it; a match already decided
     * (a director's result, a correction) or one the league voided since is left alone.
     */
    public function hyperMatchFinished(int $hyperMatchId): void
    {
        $played = HyperMatch::query()->with('seats.user', 'tournamentMatch.tournament', 'tournamentMatch.slots.participant')->find($hyperMatchId);
        $match = $played?->tournamentMatch;

        if ($played === null || $match === null || $match->result !== null || $played->status !== HyperMatchStatus::Finished || $match->isReplaced($played->id)) {
            return;
        }

        $places = [];
        $teams = $played->isTeamMatch();
        $forfeit = false;

        foreach ($match->slots->sortBy('slot')->values() as $team => $slot) {
            // A clan bracket's team match (P5b): slot 0 played as team 0, and the team's best place is the entry's.
            $members = $slot->participant?->memberIds() ?? [];
            $seats = $teams
                ? $played->seats->filter(fn (HyperSeat $seat): bool => $seat->team === $team)
                : $played->seats->filter(fn (HyperSeat $seat): bool => $seat->user_id !== null && in_array((int) $seat->user_id, $members, true))->take(1);
            $places[$slot->slot] = $seats->min('place') ?? PHP_INT_MAX;
            // Decided by forfeit: every player of the entry forfeited (a single member of a team who did, plays on as a bot).
            $humans = $seats->filter(fn (HyperSeat $seat): bool => $seat->user_id !== null);
            $forfeit = $forfeit || ($humans->isNotEmpty() && $humans->every(fn (HyperSeat $seat): bool => $seat->takeover === HyperSeat::TAKEOVER_FORFEIT));
        }

        ksort($places);
        $ranks = [];

        foreach ($places as $slot => $place) {
            // Competition ranking among the entries: a shared place stays shared.
            $ranks[$slot] = 1 + count(array_filter($places, fn (int $other): bool => $other < $place));
        }

        $winners = array_keys(array_filter($ranks, fn (int $rank): bool => $rank === 1));
        $winner = count($winners) === 1 ? $winners[0] : null;
        $names = array_map(fn (int $slot): string => (string) ($match->slots->firstWhere('slot', $slot)->participant->name ?? ''), $winners);
        $result = [
            'winner' => $winner,
            'games_won' => [],
            'points' => [],
            'forfeit' => $forfeit,
            // Worded at render time in the viewer's language (LobbyResults::describe()); `label` stays English for the logs.
            'lobby_label' => count($winners) > 1 ? 'shared' : 'single',
            'winner_names' => $names,
            'label' => (count($winners) > 1 ? 'Shared place 1: ' : 'Place 1: ').implode(', ', $names),
            'by' => 'players',
            'hyper' => $played->ulid,
        ];

        if (count($ranks) === 2 && $winner !== null) {
            $result['games_won'] = $winner === 0 ? [1.0, 0.0] : [0.0, 1.0];
        } else {
            $result['ranks'] = array_values($ranks);
        }

        $this->store($match, $result);
        $this->sync($match->tournament);

        // The cup's winner badge (HyperCups::wins()) reads a new champion at once.
        if ($match->tournament->refresh()->status === TournamentStatus::Finished && HyperCups::isCup($match->tournament)) {
            HyperCups::forgetWins();
        }
    }

    /**
     * The sweep's second chance for the bracket (plan "Hyperbitcoinization", P5c; `hyper:check-clocks`): every
     * finished tournament table whose places did not reach its match (the report after the commit failed) is
     * reported again. Only tables that ended `$graceSeconds` ago or more, so the report of the request that ended
     * one is not raced; only a running tournament's match with no result, not held, and not superseded by the
     * league. Each table on its own: one that fails is reported and the others still move. Returns how many it
     * reported.
     */
    public function reportUnreportedHyperMatches(int $graceSeconds = 30): int
    {
        $due = HyperMatch::query()->where('status', HyperMatchStatus::Finished)->whereNotNull('tournament_match_id')
            ->where('ended_at', '<=', now()->subSeconds($graceSeconds))
            ->whereHas('tournamentMatch', fn ($match) => $match->whereNull('result')->whereNull('held')
                ->where(fn ($replaced) => $replaced->whereNull('replaced_through')->orWhereColumn('tournament_matches.replaced_through', '<', 'hyper_matches.id'))
                ->whereHas('tournament', fn ($tournament) => $tournament->where('status', TournamentStatus::Running)))
            ->orderBy('id')->pluck('id');
        $reported = 0;

        foreach ($due as $id) {
            try {
                $this->hyperMatchFinished((int) $id);
                $reported++;
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $reported;
    }

    /**
     * White missed the first move of a tournament board game: it is started
     * again `first_move_restarts` times, then the double no-show rule decides.
     */
    public function boardGameAborted(int $boardGameId): void
    {
        $game = BoardGame::query()->with('tournamentMatch.tournament', 'tournamentMatch.round.stage', 'tournamentMatch.slots.participant')->find($boardGameId);
        $match = $game?->tournamentMatch;

        if ($game === null || $match === null || $match->tournament->isDirectorMode() || $match->result !== null || $game->status !== BoardGameStatus::Aborted || $match->isReplaced($game->id)) {
            return;
        }

        if (self::abortedBoardGames($match) > self::firstMoveRestarts()) {
            $this->store($match, $this->doubleNoShow($match));
        }

        $this->sync($match->tournament);
    }

    /**
     * Whether a board game plays each tournament pairing as two games with the
     * colours swapped (plan "Blockli", P4). Takes the slug, so a caller never
     * has to load the match's tournament for it.
     */
    public static function playsTwoLegs(string $slug): bool
    {
        $game = app(GameRegistry::class)->find($slug);

        return $game instanceof BoardGameDefinition && $game->playsTwoLegs();
    }

    /**
     * The finished board games of a match in the order they were played
     * (since the league last voided or superseded its games): the two legs,
     * then any deciding games.
     *
     * @return Collection<int, BoardGame>
     */
    public static function finishedBoardGames(TournamentMatch $match): Collection
    {
        return BoardGame::query()->where('tournament_match_id', $match->id)->where('id', '>', (int) $match->replaced_through)
            ->where('status', BoardGameStatus::Finished)->orderBy('id')->get();
    }

    /**
     * A game of a two-leg pairing ended (plan "Blockli", P4). After both legs
     * the points decide (a win 1, a draw ½ each); level points are a draw
     * where the format allows one, else a blitz game with drawn colours
     * decides ({@see TournamentMatchMaker::startBoardGame()}). A drawn decider
     * is replayed with the colours swapped up to `drawn_replays` times, then
     * the higher seed advances. Until then the match stays open and the next
     * run starts the next game.
     */
    private function twoLegsFinished(TournamentMatch $match): void
    {
        $games = self::finishedBoardGames($match);

        if ($games->count() < 2) {
            return;
        }

        $points = [0.0, 0.0];

        foreach ($games->take(2) as $leg) {
            $whiteSlot = in_array((int) $leg->white_id, $match->slots[0]->participant?->memberIds() ?? [], true) ? 0 : 1;
            [$white, $black] = match ($leg->result) {
                '1-0' => [1.0, 0.0],
                '0-1' => [0.0, 1.0],
                default => [0.5, 0.5],
            };
            $points[$whiteSlot] += $white;
            $points[1 - $whiteSlot] += $black;
        }

        $label = fn (array $score): string => self::points($score[0]).'–'.self::points($score[1]);

        if ($points[0] !== $points[1] || self::allowsDraw($match)) {
            $winner = $points[0] === $points[1] ? null : ($points[0] > $points[1] ? 0 : 1);
            $this->store($match, ['winner' => $winner, 'games_won' => $points, 'points' => [], 'forfeit' => false, 'decided' => 'legs', 'label' => $label($points), 'by' => 'players']);

            return;
        }

        $deciders = $games->slice(2)->values();
        $last = $deciders->last();

        if ($last === null) {
            return;
        }

        if ($last->result === '1/2-1/2') {
            if ($deciders->where('result', '1/2-1/2')->count() > self::drawnReplays($match->tournament)) {
                $this->store($match, $this->seedDecision($match, 'seed'));
            }

            return;
        }

        $whiteSlot = in_array((int) $last->white_id, $match->slots[0]->participant?->memberIds() ?? [], true) ? 0 : 1;
        $winner = $last->result === '1-0' ? $whiteSlot : 1 - $whiteSlot;

        $this->store($match, ['winner' => $winner, 'games_won' => $points, 'points' => [], 'forfeit' => false, 'decided' => 'decider',
            'label' => __(':legs, decider :decider', ['legs' => $label($points), 'decider' => self::chessLabel($winner)]), 'by' => 'players']);
    }

    /** "1", "½", "1½": a score of whole and half points. */
    private static function points(float $points): string
    {
        $whole = (int) floor($points);
        $half = $points - $whole >= 0.5;

        return $whole === 0 && $half ? '½' : $whole.($half ? '½' : '');
    }

    /** Knockout draws of a board game match so far (since the league last voided or superseded its games). */
    public static function drawnBoardGames(TournamentMatch $match): int
    {
        return BoardGame::query()->where('tournament_match_id', $match->id)->where('id', '>', (int) $match->replaced_through)
            ->where('status', BoardGameStatus::Finished)->where('result', '1/2-1/2')->count();
    }

    /** Games of a board game match aborted because White missed the first move. */
    public static function abortedBoardGames(TournamentMatch $match): int
    {
        return BoardGame::query()->where('tournament_match_id', $match->id)->where('id', '>', (int) $match->replaced_through)
            ->where('status', BoardGameStatus::Aborted)->count();
    }

    /** Knockout draws of a chess match so far (since the league last voided or superseded its games, P18). */
    public static function drawnGames(TournamentMatch $match): int
    {
        return ChessGame::query()->where('tournament_match_id', $match->id)->where('id', '>', (int) $match->replaced_through)
            ->where('status', ChessGameStatus::Finished)->where('result', '1/2-1/2')->count();
    }

    /** Games of a chess match aborted because both sides missed the first move (players cannot abort one; the league's voids do not count). */
    public static function abortedGames(TournamentMatch $match): int
    {
        return ChessGame::query()->where('tournament_match_id', $match->id)->where('id', '>', (int) $match->replaced_through)
            ->where('status', ChessGameStatus::Aborted)->count();
    }

    /**
     * Replays of a drawn knockout game; a casual cup always has two (the
     * colours swapped, then Armageddon, P25).
     */
    public static function drawnReplays(?Tournament $tournament = null): int
    {
        return $tournament?->isCasualCup() ? CasualCups::ARMAGEDDON_AFTER_DRAWS : max(0, (int) config('esports.tournaments.drawn_replays', 2));
    }

    public static function firstMoveRestarts(): int
    {
        return max(0, (int) config('esports.tournaments.first_move_restarts', 1));
    }

    /* ---------- The league's own decisions (P18) ------------------------------------------------------------ */

    /**
     * Whether no player of this entry has an account any more: it is
     * withdrawn and loses every match it would still play by forfeit.
     */
    public static function isWithdrawn(?TournamentParticipant $participant): bool
    {
        return $participant !== null && ! User::query()->whereIn('id', $participant->memberIds())->exists();
    }

    /**
     * Whether this entry loses every match it would still play by forfeit:
     * disqualified (P18, both results modes), or withdrawn where the players
     * report.
     */
    private static function isOut(Tournament $tournament, ?TournamentParticipant $participant): bool
    {
        return $participant !== null && ($participant->isDisqualified() || (! $tournament->isDirectorMode() && self::isWithdrawn($participant)));
    }

    /**
     * Decide every ready match with a withdrawn side (players mode): the
     * other side wins by forfeit, and when both are withdrawn the double
     * no-show rule applies. A match already under way is left to its normal
     * match: a chess game still running ends on its own clock, a series
     * already reported or disputed goes to the confirmation or an admin (its
     * rated subjects stay pinned, P7d F3). An accepted series nobody reported
     * is decided by forfeit, unrated. True if a match was decided.
     */
    private function forfeitWithdrawn(Tournament $tournament): bool
    {
        $decided = false;
        $matches = TournamentMatch::query()->where('tournament_id', $tournament->id)->where('status', 'ready')
            ->where('bracket', '!=', 'bye')->whereNull('result')
            ->with(['round.stage', 'slots.participant', 'seriesMatch', 'chessGame', 'boardGame', 'hyperMatch'])->orderBy('id')->get();

        foreach ($matches as $match) {
            // A lobby (P10) with at most one entry left that is not out: the league decides it (LobbyResults).
            if ($match->lobby !== null) {
                $out = array_values(array_filter($match->slots->all(), fn ($slot): bool => self::isOut($tournament, $slot->participant)));

                if ($out !== [] && count($match->slots) - count($out) <= 1) {
                    $this->store($match, LobbyResults::leagueDecision($match, array_map(fn ($slot): int => $slot->slot, $out)));
                    $decided = true;
                }

                continue;
            }

            // A score leaderboard (plan "AoE2 und Trackmania", P4) is no duel: a withdrawn or disqualified entry stays
            // without a place when it is finalized (ScoreRuns), even with two entries.
            if (count($match->slots) !== 2 || $match->bracket === 'board') {
                continue;
            }

            $gone = [self::isOut($tournament, $match->slots[0]->participant), self::isOut($tournament, $match->slots[1]->participant)];

            if (! $gone[0] && ! $gone[1]) {
                continue;
            }

            if ($match->chessGame !== null && $match->chessGame->status === ChessGameStatus::Active && ! $match->isReplaced($match->chessGame->id)) {
                continue;
            }

            if ($match->boardGame !== null && $match->boardGame->status === BoardGameStatus::Active && ! $match->isReplaced($match->boardGame->id)) {
                continue;
            }

            // A running Hyperbitcoinization table: TournamentControl::forfeitUnderWay() voids it first, or it decides itself.
            if ($match->hyperMatch !== null && $match->hyperMatch->isActive() && ! $match->isReplaced($match->hyperMatch->id)) {
                continue;
            }

            // The current series: the latest attempt, unless the league voided or superseded it (P18).
            $series = $match->seriesMatch !== null && ! $match->isReplaced($match->seriesMatch->id) ? $match->seriesMatch : null;

            if ($series !== null && $series->resolution !== SeriesResolution::Void && $series->status !== SeriesStatus::Accepted) {
                continue;
            }

            $disqualified = ($match->slots[0]->participant?->isDisqualified() ?? false) || ($match->slots[1]->participant?->isDisqualified() ?? false);
            $result = $gone[0] && $gone[1] ? $this->doubleNoShow($match) : [
                'winner' => $gone[0] ? 1 : 0,
                'games_won' => $gone[0] ? [0.0, 1.0] : [1.0, 0.0],
                'points' => [],
                'forfeit' => true,
                'decided' => $disqualified ? 'disqualified' : 'withdrawn',
                'label' => __('forfeit'),
                'by' => 'league',
            ];

            if ($series !== null && $series->status === SeriesStatus::Accepted) {
                $this->forfeitSeries($series, $result['winner'], $disqualified
                    ? 'Disqualified from the tournament by its organizer or an admin.'
                    : 'Withdrawn from the tournament: no player of the side has an account any more.');
                $result['number'] = $series->number;
            }

            $this->store($match, $result);
            $decided = true;
        }

        return $decided;
    }

    /**
     * Neither side checked in to the lobby by the check-in no-show time plus the response time, and nothing is
     * entered (user, 2026-10-04: "beide nicht eingecheckt = Doppel-No-Show"): the double no-show rule decides the
     * match, the series ends like a withdrawn side's. False when anything changed meanwhile.
     */
    public function decideNobodyCheckedIn(SeriesMatch $series): bool
    {
        // Under the tournament's lock and with the series and the match read anew, like the tick's other deadlines:
        // a check-in, a game, a report, a result set by hand or a pause after the tick read the series wins.
        $decided = DB::transaction(function () use ($series): ?Tournament {
            $tournament = Tournament::query()->whereKey(TournamentMatch::query()->whereKey($series->tournament_match_id)->select('tournament_id'))->lockForUpdate()->first();

            if ($tournament === null || $tournament->status !== TournamentStatus::Running || $tournament->isPaused() || $tournament->isCasualCup() || $tournament->isDirectorMode()) {
                return null;
            }

            $locked = SeriesMatch::query()->lockForUpdate()->find($series->id);
            $match = TournamentMatch::query()->with(['tournament', 'slots.participant', 'round.stage', 'seriesMatch'])->lockForUpdate()->find($series->tournament_match_id);
            $due = $locked?->autoNoshowAt()?->copy()->addMinutes((int) $locked->responseMinutes());

            if ($locked === null || $match === null || $match->result !== null || $match->isReplaced($locked->id) || $due === null || $due->isFuture()
                || $locked->status !== SeriesStatus::Accepted || $locked->noshow_reported_at !== null || $locked->overdue_at !== null
                || $locked->ready_at_challenger !== null || $locked->ready_at_challenged !== null || $locked->currentGames() !== [] || count($match->slots) !== 2) {
                return null;
            }

            $result = $this->doubleNoShow($match) + ['number' => $locked->number];
            $this->forfeitSeries($locked, is_int($result['winner'] ?? null) ? $result['winner'] : null, 'Neither side checked in to the lobby in time.');
            $this->store($match, $result);

            return $match->tournament;
        });

        if ($decided === null) {
            return false;
        }

        $this->sync($decided);

        return true;
    }

    /**
     * An accepted tournament series of a withdrawn side, decided by the league
     * as a forfeit: unrated like a director forfeit (no rating change, so the
     * attestation carries `forfeit` and no `elo`), void when both withdrew.
     */
    private function forfeitSeries(SeriesMatch $series, ?int $winner, string $reason): void
    {
        $series->forceFill([
            'status' => SeriesStatus::Resolved,
            'resolution' => $winner === null ? SeriesResolution::Void : SeriesResolution::Forfeit,
            'winner' => $winner === null ? 'none' : ($winner === 0 ? 'challenger' : 'challenged'),
            'resolution_reason' => $reason,
            'finished_at' => now(),
        ])->save();

        $this->chains->attestSeries($series->fresh() ?? $series);
    }

    /**
     * The double no-show rule (CEO default, P18): in a Swiss or round-robin
     * match both sides lose (no points); in a knockout the higher seed advances.
     *
     * @return array<string, mixed>
     */
    private function doubleNoShow(TournamentMatch $match): array
    {
        // A casual cup decides as at its deadlines (P25): who tried to play, else a draw of lots.
        if ($match->loadMissing('tournament')->tournament->isCasualCup()) {
            return CasualCups::decision($match->tournament, $match);
        }

        if (! self::allowsDraw($match)) {
            return $this->seedDecision($match, 'noshow');
        }

        return ['winner' => null, 'double_loss' => true, 'games_won' => [0.0, 0.0], 'points' => [], 'forfeit' => true, 'decided' => 'noshow', 'label' => __('double no-show'), 'by' => 'league'];
    }

    /**
     * The higher seed (lower seed number) advances, without a game.
     *
     * @param  'seed'|'noshow'  $decided
     * @return array<string, mixed>
     */
    private function seedDecision(TournamentMatch $match, string $decided): array
    {
        $seeds = [$match->slots[0]->participant->seed ?? PHP_INT_MAX, $match->slots[1]->participant->seed ?? PHP_INT_MAX];
        $winner = $seeds[1] < $seeds[0] ? 1 : 0;

        return [
            'winner' => $winner,
            'games_won' => $winner === 0 ? [1.0, 0.0] : [0.0, 1.0],
            'points' => [],
            'forfeit' => $decided === 'noshow',
            'decided' => $decided,
            'label' => __('higher seed'),
            'by' => 'league',
        ];
    }

    /* ---------- Tournament directors ------------------------------------------------------------------------ */

    /**
     * Enter or correct the result of a match of the open round.
     *
     * Chess: `['result' => '1-0' | '1/2-1/2' | '0-1' | 'noshow-0' | 'noshow-1']`
     * (`noshow-n`: the player in slot n did not show up, the other wins by
     * forfeit). Rocket League: `['games' => [[a, b], …]]` with the goals of
     * each game, or `['winners' => [0, 1, …]]` when the goals are unknown, or
     * `['noshow' => n]`.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws TournamentRuleViolation
     */
    public function enterResult(TournamentMatch $match, User $director, array $input): void
    {
        $changed = DB::transaction(function () use ($match, $director, $input): bool {
            $tournament = Tournament::query()->lockForUpdate()->findOrFail($match->tournament_id);
            $match = TournamentMatch::query()->with(['round.stage', 'slots.participant'])->lockForUpdate()->findOrFail($match->id);

            $this->assertDirector($tournament, $director);

            if ($match->bracket === 'bye' || ! in_array($match->status, ['ready', 'done'], true)) {
                throw new TournamentRuleViolation('not_playable', __('This match has no two sides yet.'));
            }

            if ($match->round->status !== 'open' || self::currentRound($tournament)?->id !== $match->tournament_round_id) {
                throw new TournamentRuleViolation('round_closed', __('Round :round is closed, so its results are locked. If a result there is wrong, report a problem on the match; an admin can fix it.', ['round' => $match->round->number]));
            }

            if (TournamentInterest::of($tournament, $match, $director, followAppointers: ! $director->isAdmin())) {
                throw new TournamentRuleViolation('interested', __('You have an interest in this match (you play in it, belong to a clan in it, or were named by someone who does), so another director or an admin has to enter its result.'));
            }

            // A board game's result is entered as a chess result (one game, 1-0 / ½-½ / 0-1).
            $result = $this->parseResult($tournament, $match, $input);

            $previous = $match->isDirectorResult() ? $match->result : null;

            if ($previous !== null && $previous['label'] === $result['label'] && ($previous['games'] ?? null) === ($result['games'] ?? null)) {
                return false;
            }

            $now = now();
            $who = ['user_id' => $director->id, 'name' => $director->displayName(), 'at' => $now->toIso8601String()];
            $result += ['by' => 'director'] + ($previous === null
                ? $who
                : ['user_id' => $previous['user_id'], 'name' => $previous['name'], 'at' => $previous['at'], 'corrected' => $who, 'was' => $previous['label']]);

            $this->store($match, $result);

            TournamentResultEntry::query()->create([
                'tournament_id' => $tournament->id,
                'tournament_match_id' => $match->id,
                'user_id' => $director->id,
                'user_name' => mb_substr($director->displayName(), 0, 80),
                'result' => $result,
                'replaced' => $previous,
                'created_at' => $now,
            ]);

            $this->applyState($tournament);

            return true;
        });

        if ($changed) {
            Broadcasts::send(new TournamentChanged($match->tournament_id, 'result'));
        }
    }

    /**
     * Close the open round once every result is in: its results are locked,
     * written into the normal matches and rated, and the next round opens.
     *
     * @throws TournamentRuleViolation
     */
    public function closeRound(TournamentRound $round, User $director): void
    {
        $tournament = $round->stage()->firstOrFail()->tournament()->firstOrFail();

        DB::transaction(function () use ($tournament, $round, $director): void {
            $locked = Tournament::query()->lockForUpdate()->findOrFail($tournament->id);
            $this->assertDirector($locked, $director);
            $round = TournamentRound::query()->lockForUpdate()->findOrFail($round->id);

            if ($round->status !== 'open' || self::currentRound($locked)?->id !== $round->id) {
                throw new TournamentRuleViolation('round_closed', __('This round is closed already.'));
            }

            if (! $this->isRoundComplete($round)) {
                $left = TournamentMatch::query()->where('tournament_round_id', $round->id)->whereNotIn('status', ['done', 'skipped'])->count();

                throw new TournamentRuleViolation('round_open', __(':count boards are still playing. You can close the round once every result is in.', ['count' => $left]));
            }

            $round->forceFill(['status' => 'closed', 'closed_at' => now()])->save();

            $matches = TournamentMatch::query()->where('tournament_round_id', $round->id)->where('status', 'done')
                ->where('bracket', '!=', 'bye')->with(['slots.participant', 'seriesMatch', 'round.stage'])->get();

            foreach ($matches as $match) {
                // A board game played elsewhere has no game record here and rates nothing (P5); a lobby (P10) neither.
                if ($match->isDirectorResult() && ! $locked->profile()->isBoard() && $match->lobby === null) {
                    $locked->profile()->isChess() ? $this->finishChess($locked, $match) : $this->finishSeries($locked, $match);
                }
            }
        });

        $this->sync($tournament->refresh(), 'round');
    }

    /**
     * The finished game record of a director's chess result, rated and
     * attested like a game played here (with the director named).
     */
    private function finishChess(Tournament $tournament, TournamentMatch $match): void
    {
        $white = User::query()->find($match->slots[0]->participant?->memberIds()[0] ?? 0);
        $black = User::query()->find($match->slots[1]->participant?->memberIds()[0] ?? 0);

        if ($white === null || $black === null) {
            return;
        }

        // Rated as read at the pairing (TournamentMatchMaker::pinPairing), never later.
        $gate = $match->pairing['gate'] ?? null;
        $winner = $match->result['winner'] ?? null;
        $forfeit = (bool) ($match->result['forfeit'] ?? false);

        $game = ChessGame::query()->create([
            'mode' => $tournament->mode,
            'rated' => $gate !== null,
            // Pinned at the pairing with the gate; a pairing without one is never rated (fail closed).
            'ladder_address' => $gate === null ? null : ($match->pairing['ladder'] ?? null),
            'gate_at_accept' => $gate,
            'clans_at_accept' => $gate === null ? null : ($match->pairing['clans'] ?? []),
            'white_id' => $white->id,
            'black_id' => $black->id,
            'status' => ChessGameStatus::Finished,
            'result' => $winner === null ? '1/2-1/2' : ($winner === 0 ? '1-0' : '0-1'),
            'end_reason' => ChessEndReason::Director,
            'fen' => ChessGame::START_FEN,
            'ply' => 0,
            'initial_ms' => 0,
            'increment_ms' => 0,
            'white_ms' => 0,
            'black_ms' => 0,
            'turn_started_ms' => (int) now()->getTimestampMs(),
            'ended_at' => now(),
            'tournament_match_id' => $match->id,
            'tournament_game' => ChessGame::query()->where('tournament_match_id', $match->id)->count() + 1,
        ]);

        // A director's no-show moves no Elo (security gate P8b); it is attested as `forfeit`.
        if (! $forfeit) {
            $this->ratings->applyChessGame($game);
        }

        $this->chains->attestChessGame($game);
    }

    /**
     * The director's series result decides the series in the room (the
     * league's decision, resolution `admin`, or `forfeit` for a no-show).
     */
    private function finishSeries(Tournament $tournament, TournamentMatch $match): void
    {
        $series = $match->seriesMatch;

        if ($series === null) {
            [$a, $b] = [$match->slots[0]->participant, $match->slots[1]->participant];

            if ($a === null || $b === null) {
                return;
            }

            $series = $this->maker->createSeries($tournament, $match, $a, $b);
        }

        $winner = ($match->result['winner'] ?? 0) === 0 ? 'challenger' : 'challenged';
        $forfeit = (bool) ($match->result['forfeit'] ?? false);

        $series->forceFill([
            'status' => SeriesStatus::Resolved,
            'resolution' => ($match->result['forfeit'] ?? false) ? SeriesResolution::Forfeit : SeriesResolution::Admin,
            'winner' => $winner,
            'result_games' => $match->result['games'] ?? null,
            'resolution_reason' => 'Entered by the tournament director.',
            'resolved_by_id' => $match->result['corrected']['user_id'] ?? $match->result['user_id'] ?? null,
            'finished_at' => now(),
            // Who played (NIP "Director results": the roster comes from the director's entry): the
            // pinned eligible regulars of each side; a no-show forfeit has none.
            'resolved_roster' => $forfeit ? null : $this->directorRoster($series),
        ])->save();

        // A director's no-show moves no Elo (security gate P8b); it is attested as `forfeit`.
        if (! $forfeit) {
            $this->ratings->applySeries($series->fresh() ?? $series);
        }

        $this->chains->attestSeries($series->fresh() ?? $series);
    }

    /**
     * @return list<array{user_id: int, pubkey: string, name: string, side: string, role: string}>
     */
    private function directorRoster(SeriesMatch $series): array
    {
        $roster = [];
        $service = app(SeriesService::class);

        foreach (SeriesMatch::SIDES as $side) {
            foreach ($service->rosterSeats($series, $side) as $seat) {
                $roster[] = ['user_id' => $seat->user_id, 'pubkey' => $seat->user->pubkey, 'name' => $seat->user->displayName(), 'side' => $side, 'role' => $seat->role->value];
            }
        }

        return $roster;
    }

    /**
     * @throws TournamentRuleViolation
     */
    private function assertDirector(Tournament $tournament, User $director): void
    {
        if (! Gate::forUser($director)->allows('direct-tournament', $tournament)) {
            throw new TournamentRuleViolation('not_director', __('Only the tournament directors can enter results.'));
        }

        if (! $tournament->isDirectorMode()) {
            throw new TournamentRuleViolation('players_report', __('In this tournament the players report their results.'));
        }

        if ($tournament->status !== TournamentStatus::Running) {
            throw new TournamentRuleViolation('not_running', __('This tournament is not running.'));
        }
    }

    /**
     * A result typed on the director desk or the tournament control (P18),
     * in the stored shape without who entered it: the input as enterResult()
     * reads it, checked the same way.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws TournamentRuleViolation
     */
    public function parseResult(Tournament $tournament, TournamentMatch $match, array $input): array
    {
        // A score leaderboard (plan "AoE2 und Trackmania", P4) has no match result to enter: its values are entered and
        // corrected per player, with a reason (ScoreLeaderboards::correct()), and the league writes the end.
        if ($tournament->profile()->isScore()) {
            throw new TournamentRuleViolation('score', __('A leaderboard has no match result. Enter or correct the players\' values on its scores page.'));
        }

        // A lobby (P10) has places, not a winner of two sides: they are entered on its lobby card (LobbyResults).
        if ($match->lobby !== null) {
            throw new TournamentRuleViolation('lobby', __('A lobby has places, not a winner. Enter them on its lobby card on the tournament page.'));
        }

        return $tournament->profile()->isChess() || $tournament->profile()->isBoard() ? $this->chessInput($match, $input) : $this->seriesInput($tournament, $match, $input);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function chessInput(TournamentMatch $match, array $input): array
    {
        $value = (string) ($input['result'] ?? '');

        [$winner, $forfeit] = match ($value) {
            '1-0' => [0, false],
            '0-1' => [1, false],
            '1/2-1/2' => [null, false],
            'noshow-0' => [1, true],
            'noshow-1' => [0, true],
            default => throw new TournamentRuleViolation('result', __('Pick a result.')),
        };

        if ($winner === null && ! self::allowsDraw($match)) {
            throw new TournamentRuleViolation('draw_in_knockout', __('A knockout match needs a winner.'));
        }

        return [
            'winner' => $winner,
            'games_won' => $winner === null ? [0.5, 0.5] : ($winner === 0 ? [1.0, 0.0] : [0.0, 1.0]),
            'points' => [],
            'forfeit' => $forfeit,
            'label' => self::chessLabel($winner),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function seriesInput(Tournament $tournament, TournamentMatch $match, array $input): array
    {
        $bestOf = $match->seriesMatch !== null ? $match->seriesMatch->best_of : (self::isFinal($match) ? $tournament->formatOptions()->finalBestOf : $tournament->formatOptions()->bestOf);

        if (isset($input['noshow']) && in_array((int) $input['noshow'], [0, 1], true)) {
            $winner = 1 - (int) $input['noshow'];

            return ['winner' => $winner, 'games_won' => $winner === 0 ? [1.0, 0.0] : [0.0, 1.0], 'points' => [], 'games' => null, 'forfeit' => true, 'label' => __('forfeit')];
        }

        $games = [];

        foreach ((array) ($input['games'] ?? []) as $pair) {
            [$a, $b] = [is_numeric($pair[0] ?? null) ? (int) $pair[0] : null, is_numeric($pair[1] ?? null) ? (int) $pair[1] : null];

            if ($a === null && $b === null) {
                break;
            }

            if ($a === null || $b === null || $a < 0 || $b < 0 || $a > 99 || $b > 99 || $a === $b) {
                throw new TournamentRuleViolation('goals', __('Enter both goal counts; a game cannot end in a draw.'));
            }

            $games[] = ['winner' => $a > $b ? 'challenger' : 'challenged', 'challenger' => $a, 'challenged' => $b];
        }

        // A game without goals (Age of Empires II) is entered by its winners only.
        if ($games !== [] && ! $this->games->hasGoals($tournament->game)) {
            throw new TournamentRuleViolation('goals', __('Game :number has a score, but this game records only its winner.', ['number' => 1]));
        }

        if ($games === []) {
            foreach ((array) ($input['winners'] ?? []) as $winner) {
                if (! in_array((int) $winner, [0, 1], true)) {
                    throw new TournamentRuleViolation('winner', __('Pick the winner of this game.'));
                }

                $games[] = ['winner' => (int) $winner === 0 ? 'challenger' : 'challenged', 'challenger' => null, 'challenged' => null];
            }
        }

        $mode = $this->games->mode($tournament->game, $tournament->mode);

        if ($games === [] || $mode === null || $this->games->get($tournament->game)->validateResult($mode, ['bo' => $bestOf, 'games' => $games]) !== []) {
            throw new TournamentRuleViolation('series_invalid', __('The series is not finished: one side needs :wins game wins in a best of :bo.', ['wins' => intdiv($bestOf, 2) + 1, 'bo' => $bestOf]));
        }

        $score = SeriesMatch::seriesScore($games);
        $winner = $score['challenger'] > $score['challenged'] ? 0 : 1;

        return [
            'winner' => $winner,
            'games_won' => [(float) $score['challenger'], (float) $score['challenged']],
            'points' => $this->goals($games),
            'games' => $games,
            'forfeit' => false,
            'label' => $score['challenger'].' : '.$score['challenged'],
        ];
    }

    /**
     * Goals per side, only when every game has them (unknown goals never count as 0).
     *
     * @param  list<array<string, mixed>>  $games
     * @return list<float>
     */
    private function goals(array $games): array
    {
        $sums = [0.0, 0.0];

        foreach ($games as $game) {
            if (($game['challenger'] ?? null) === null || ($game['challenged'] ?? null) === null) {
                return [];
            }

            $sums[0] += (float) $game['challenger'];
            $sums[1] += (float) $game['challenged'];
        }

        return $games === [] ? [] : $sums;
    }

    public static function chessLabel(?int $winner): string
    {
        return match ($winner) {
            0 => '1–0',
            1 => '0–1',
            default => '½–½',
        };
    }

    /**
     * Write a match's result (the caller moves the bracket, sync()).
     *
     * @param  array<string, mixed>  $result
     */
    public function store(TournamentMatch $match, array $result): void
    {
        // A decided match is no longer held (P18: a held match is decided by a result or a restart).
        TournamentMatch::query()->whereKey($match->id)->update(['result' => json_encode($result), 'status' => 'done', 'held' => null]);
        $match->refresh();
    }
}
