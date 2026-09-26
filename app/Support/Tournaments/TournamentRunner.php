<?php

namespace App\Support\Tournaments;

use App\Enums\ChessEndReason;
use App\Enums\ChessGameStatus;
use App\Enums\SeriesResolution;
use App\Enums\SeriesStatus;
use App\Enums\TournamentFormat;
use App\Enums\TournamentStatus;
use App\Games\GameRegistry;
use App\Models\ChessGame;
use App\Models\SeriesMatch;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentResultEntry;
use App\Models\TournamentRound;
use App\Models\TournamentStage;
use App\Models\User;
use App\Support\Rating\RatingService;
use App\Support\SeasonChain\SeasonChains;
use App\Support\Series\SeriesService;
use App\Support\Tournaments\Engine\Advancement;
use App\Support\Tournaments\Engine\MatchResult;
use App\Support\Tournaments\Engine\Swiss;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

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
     * start the normal matches of whatever became ready.
     */
    public function sync(Tournament $tournament): void
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

        if ($series === null || $match === null || $match->tournament->isDirectorMode() || $match->result !== null) {
            return;
        }

        if (! $series->status->hasResult() || $series->resolution === SeriesResolution::Void || ! in_array($series->winner, SeriesMatch::SIDES, true)) {
            return;
        }

        $score = SeriesMatch::seriesScore($series->result_games);
        $goals = $this->goals($series->result_games ?? []);

        $this->store($match, [
            'winner' => $series->winner === 'challenger' ? 0 : 1,
            'games_won' => [(float) $score['challenger'], (float) $score['challenged']],
            'points' => $goals,
            'games' => $series->result_games ?? [],
            'label' => $score['challenger'].' : '.$score['challenged'],
            'by' => 'players',
            'number' => $series->number,
        ]);

        $this->sync($match->tournament);
    }

    public function chessGameFinished(int $chessGameId): void
    {
        $game = ChessGame::query()->with('tournamentMatch.tournament', 'tournamentMatch.round.stage', 'tournamentMatch.slots.participant')->find($chessGameId);
        $match = $game?->tournamentMatch;

        if ($game === null || $match === null || $match->tournament->isDirectorMode() || $match->result !== null || $game->status !== ChessGameStatus::Finished) {
            return;
        }

        $whiteSlot = in_array((int) $game->white_id, $match->slots[0]->participant?->memberIds() ?? [], true) ? 0 : 1;

        if ($game->result === '1/2-1/2' && ! self::allowsDraw($match)) {
            // A knockout needs a winner: the game is replayed (TournamentMatchMaker::needsGame()).
            $this->sync($match->tournament);

            return;
        }

        $winner = match ($game->result) {
            '1-0' => $whiteSlot,
            '0-1' => 1 - $whiteSlot,
            default => null,
        };

        $this->store($match, [
            'winner' => $winner,
            'games_won' => $winner === null ? [0.5, 0.5] : ($winner === 0 ? [1.0, 0.0] : [0.0, 1.0]),
            'points' => [],
            'label' => self::chessLabel($winner),
            'by' => 'players',
            'number' => $game->number,
        ]);

        $this->sync($match->tournament);
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
        DB::transaction(function () use ($match, $director, $input): void {
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

            $result = $tournament->profile()->isChess()
                ? $this->chessInput($match, $input)
                : $this->seriesInput($tournament, $match, $input);

            $previous = $match->isDirectorResult() ? $match->result : null;

            if ($previous !== null && $previous['label'] === $result['label'] && ($previous['games'] ?? null) === ($result['games'] ?? null)) {
                return;
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
        });
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
                if ($match->isDirectorResult()) {
                    $locked->profile()->isChess() ? $this->finishChess($locked, $match) : $this->finishSeries($locked, $match);
                }
            }
        });

        $this->sync($tournament->refresh());
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
     * @param  array<string, mixed>  $result
     */
    private function store(TournamentMatch $match, array $result): void
    {
        TournamentMatch::query()->whereKey($match->id)->update(['result' => json_encode($result), 'status' => 'done']);
        $match->refresh();
    }
}
