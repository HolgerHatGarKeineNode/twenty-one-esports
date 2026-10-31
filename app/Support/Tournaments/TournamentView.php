<?php

namespace App\Support\Tournaments;

use App\Enums\BoardGameStatus;
use App\Enums\TournamentFormat;
use App\Models\BoardGame;
use App\Models\Clan;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentParticipant;
use App\Models\TournamentStage;
use App\Support\Tournaments\Engine\Standings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

/**
 * The public view of a running or finished tournament, built from the stored
 * bracket and results (TournamentShow.dc.html, TournamentFormats.dc.html):
 * per stage either a bracket (knockout parts in columns by round), or a
 * table with its pairings (Swiss, round robin, round-robin groups), or heats.
 * Director results carry their marker ("Entered by the tournament director").
 */
final class TournamentView
{
    /** @var Collection<int, TournamentParticipant> */
    private Collection $participants;

    /** @var array<int, array{points: array{0: float, 1: float}, label: string, game: int, legs: list<array{0: float, 1: float}>}|null> */
    private array $legs = [];

    /** @var Collection<int, Collection<int, BoardGame>>|null the board games of every match, read once by stages() */
    private ?Collection $boardGames = null;

    public function __construct(private Tournament $tournament)
    {
        $this->participants = $tournament->participants()->with('lineup.clan')->get()->keyBy('id');
    }

    /**
     * @return list<array{number: int, format: TournamentFormat, title: string, parts: list<array<string, mixed>>}>
     */
    public function stages(): array
    {
        $matches = TournamentMatch::query()->where('tournament_id', $this->tournament->id)
            ->with(['round', 'slots', 'seriesMatch', 'chessGame', 'boardGame'])->orderBy('id')->get();
        $this->boardGames = BoardGame::query()->whereIn('tournament_match_id', $matches->modelKeys())->where('status', '!=', BoardGameStatus::Aborted)
            ->orderBy('id')->get(['id', 'tournament_match_id', 'status', 'result', 'white_id'])->groupBy('tournament_match_id');
        $stages = [];

        foreach (TournamentStage::query()->where('tournament_id', $this->tournament->id)->orderBy('number')->get() as $stage) {
            $own = $matches->filter(fn (TournamentMatch $match): bool => $match->round->tournament_stage_id === $stage->id);
            $parts = [];

            foreach ($own->groupBy(fn (TournamentMatch $match): string => (string) ($match->group ?? 0)) as $group => $groupMatches) {
                $parts[] = $this->part($stage, (int) $group, $groupMatches);
            }

            $stages[] = [
                'number' => $stage->number,
                'format' => $stage->format,
                'title' => $this->tournament->format === TournamentFormat::TwoStage ? ($stage->number === 1 ? __('Group stage') : __('Final stage')) : $stage->format->label(),
                'parts' => $parts,
            ];
        }

        return $stages;
    }

    /**
     * @param  Collection<int, TournamentMatch>  $matches
     * @return array<string, mixed>
     */
    private function part(TournamentStage $stage, int $group, Collection $matches): array
    {
        $title = $group > 0 ? __('Group :group', ['group' => self::letter($group)]) : null;

        if ($stage->format === TournamentFormat::Swiss || $stage->format === TournamentFormat::RoundRobin) {
            $rounds = $matches->groupBy(fn (TournamentMatch $match): int => $match->round->number)
                ->map(fn (Collection $round): array => array_values($round->map(fn (TournamentMatch $match): array => $this->box($match, $stage))->all()))
                ->all();

            return [
                'kind' => 'table',
                'title' => $title,
                'rows' => $this->table($stage, $group, $matches),
                'rounds' => $rounds,
                // A two-leg game (Blockli) ranks pairings, and a pairing enters the table only after both legs: the table
                // says so and lists the pairings under way with their standing (Nidan21 2026-10-17: a won and a lost
                // game showed as one draw, a lost first leg nowhere). Unless the organizer chose one game per pairing,
                // or points per game: then every game is in the table once it ends (FormatOptions::$legs, $legPoints).
                'two_legs' => $this->twoLegs($stage),
                'per_leg' => $this->perLeg($stage),
                'open' => array_values(array_map(fn (array $box): array => [
                    'a' => $box['sides'][0]['name'] ?? '?',
                    'b' => $box['sides'][1]['name'] ?? '?',
                    'score' => $box['legs']['label'],
                ], array_filter(array_merge(...array_values($rounds)), fn (array $box): bool => ($box['legs'] ?? null) !== null))),
            ];
        }

        if ($matches->contains(fn (TournamentMatch $match): bool => in_array($match->bracket, ['heat', 'board'], true))) {
            return ['kind' => 'heats', 'title' => $title, 'heats' => array_values($matches->map(fn (TournamentMatch $match): array => $this->box($match, $stage))->all())];
        }

        $sections = [];

        foreach ($matches->groupBy(fn (TournamentMatch $match): string => $this->section($match->bracket)) as $section => $sectionMatches) {
            $sections[] = [
                'title' => match ($section) {
                    'upper' => __('Upper bracket'),
                    'lower' => __('Lower bracket'),
                    'grand-final' => __('Grand final'),
                    default => null,
                },
                'columns' => array_values($sectionMatches->groupBy(fn (TournamentMatch $match): int => $match->round->number)
                    ->sortKeys()
                    ->map(fn (Collection $round, int $number): array => [
                        'label' => $this->roundLabel($number, $sectionMatches),
                        'matches' => array_values($round->sortBy('position')->map(fn (TournamentMatch $match): array => $this->box($match, $stage))->all()),
                    ])->all()),
            ];
        }

        return ['kind' => 'bracket', 'title' => $title, 'sections' => $sections];
    }

    private function section(string $bracket): string
    {
        return match ($bracket) {
            'upper' => 'upper',
            'lower' => 'lower',
            'grand-final', 'reset' => 'grand-final',
            default => 'main',
        };
    }

    /**
     * @param  Collection<int, TournamentMatch>  $matches
     */
    private function roundLabel(int $number, Collection $matches): string
    {
        $last = (int) $matches->max(fn (TournamentMatch $match): int => $match->round->number);

        return match (true) {
            $number === $last && $matches->contains(fn (TournamentMatch $match): bool => in_array($match->bracket, ['main', 'grand-final', 'reset'], true)) => __('Final'),
            $number === $last - 1 && $matches->every(fn (TournamentMatch $match): bool => $match->bracket === 'main' || $match->bracket === 'third-place') => __('Semifinal'),
            default => __('Round :round', ['round' => $number]),
        };
    }

    /** Whether this stage's pairings play two legs ({@see TournamentRunner::stagePlaysTwoLegs()}). */
    private function twoLegs(TournamentStage $stage): bool
    {
        return TournamentRunner::stagePlaysTwoLegs($this->tournament, $stage->format);
    }

    /** Whether this stage's table counts the two legs game by game (FormatOptions::$legPoints, round-robin tables only). */
    private function perLeg(TournamentStage $stage): bool
    {
        return TournamentRunner::choosesLegs((string) $this->tournament->game, $stage->format) && $this->tournament->formatOptions()->perLeg();
    }

    /**
     * The table of a Swiss stage, a round robin or one group.
     *
     * @param  Collection<int, TournamentMatch>  $matches
     * @return list<array{rank: int, name: string, tag: string|null, clan: Clan|null, mix: bool, points: string, wins: int, ties: int, losses: int, games: string, buchholz: string}>
     */
    private function table(TournamentStage $stage, int $group, Collection $matches): array
    {
        $options = $this->tournament->formatOptions();
        $members = $group > 0
            ? $this->participants->where('group', $group)->sortBy('seed')->keys()->all()
            : $this->participants->whereNotNull('seed')->sortBy('seed')->keys()->all();
        $games = [];

        foreach ($matches->where('status', 'done') as $match) {
            $a = $match->slots[0]->tournament_participant_id ?? null;
            $b = $match->slots[1]->tournament_participant_id ?? null;
            $result = $match->matchResult();

            if ($a !== null && $match->bracket === 'bye') {
                $games[] = [(int) $a, null, Engine\MatchResult::win(0)];
            } elseif ($a !== null && $b !== null && $result !== null) {
                $games[] = [(int) $a, (int) $b, $result];
            }
        }

        // Game by game: the legs already played of a pairing under way count at once (DerCaddy, 2026-10-18).
        $perLeg = $this->perLeg($stage);

        foreach ($perLeg ? $matches->where('status', 'ready')->whereNull('result') : [] as $match) {
            $a = $match->slots[0]->tournament_participant_id ?? null;
            $b = $match->slots[1]->tournament_participant_id ?? null;
            $played = $a !== null && $b !== null ? ($this->legs($match, $stage)['legs'] ?? []) : [];

            if ($played !== []) {
                $games[] = [(int) $a, (int) $b, new Engine\MatchResult(null, legs: $played)];
            }
        }

        $swiss = $stage->format === TournamentFormat::Swiss;
        $custom = $options->rankBy === 'custom';
        $rows = Standings::table(
            array_values(array_map(intval(...), $members)),
            $games,
            $swiss || $custom ? $options->pointsWin : 1.0,
            $swiss || $custom ? $options->pointsTie : 0.5,
            $swiss ? $options->pointsBye : 0.0,
            $swiss ? 'points' : $options->rankBy,
            $swiss ? $options->swissTieBreaks : $options->roundRobinTieBreaks,
            $perLeg,
        );
        $rows = Standings::outLast($rows, $this->participants->whereNotNull('disqualified_at')->keys()->map(intval(...))->values()->all());

        return array_map(function ($row): array {
            $participant = $this->participants->get($row->entrant);

            return [
                'rank' => $row->rank,
                'name' => $participant->name ?? '?',
                'tag' => $participant?->lineup?->clan->clantag,
                'clan' => $participant?->lineup?->clan,
                'mix' => $participant?->isMixTeam() ?? false,
                'points' => self::number($row->points),
                'wins' => $row->wins,
                'ties' => $row->ties,
                'losses' => $row->losses,
                'games' => self::number($row->gameWins).':'.self::number($row->gameLosses),
                'buchholz' => self::number($row->medianBuchholz),
                'out' => $participant?->disqualified_at !== null,
            ];
        }, $rows);
    }

    /**
     * One match as the views draw it. A caller that holds the match's stage passes it (TournamentRunner::twoLegs()).
     *
     * @return array<string, mixed>
     */
    public function box(TournamentMatch $match, ?TournamentStage $stage = null): array
    {
        $result = $match->result;
        $winner = $result['winner'] ?? null;
        // One game per match (chess, the board games of plan "Mühle und Dame", P5, Proof of Pong, plan "Proof of Pong", P4): 1, ½, 0.
        $chess = $this->tournament->profile()->isChess() || $this->tournament->profile()->isBoard() || $this->tournament->profile()->isPong();
        // A two-leg pairing under way (Blockli): the legs so far stand in the box until the match is decided.
        $legs = $result === null && $match->status === 'ready' ? $this->legs($match, $stage) : null;
        $sides = [];

        foreach ($match->slots as $slot) {
            $participant = $slot->tournament_participant_id === null ? null : $this->participants->get($slot->tournament_participant_id);
            $index = $slot->slot;
            $score = null;

            $rank = isset($result['ranks'][$index]) && ! in_array($slot->tournament_participant_id, (array) ($result['unplaced'] ?? []), true) ? (int) $result['ranks'][$index] : null;

            if ($result !== null && $match->bracket !== 'bye') {
                $won = $result['games_won'][$index] ?? null;
                $score = $chess ? (($result['double_loss'] ?? false) ? '0' : ($winner === null ? '½' : ($winner === $index ? '1' : '0'))) : ($won === null ? null : self::number((float) $won));
            }

            if ($legs !== null) {
                $score = self::number($legs['points'][$index] ?? 0.0);
            }

            // A heat or a lobby (P10) shows each side's place; a shared place 1 is won by all who hold it.
            if ($result !== null && isset($result['ranks']) && $match->bracket === 'heat') {
                $score = $rank === null ? '–' : '#'.$rank;
            }

            $sides[] = [
                'name' => $participant->name ?? self::source($slot->source),
                'known' => $participant !== null,
                'tag' => $participant?->lineup?->clan->clantag,
                'clan' => $participant?->lineup?->clan,
                'mix' => $participant?->isMixTeam() ?? false,
                'score' => $score,
                'won' => $result !== null && (isset($result['ranks']) && $match->bracket === 'heat' ? $rank === 1 : $winner === $index),
                'seed' => $participant?->seed,
            ];
        }

        return [
            'key' => $match->key,
            'bracket' => $match->bracket,
            'status' => $match->status,
            'sides' => $sides,
            // A lobby's result (P10) is worded in the viewer's language, not the one it was entered in.
            // A lobby's, and a Hyperbitcoinization table's (P5, `lobby_label`), in the viewer's language.
            // A drawn decider settled by the race standing (Blockli, draw_decider `standing`) with its margin, worded anew too.
            'label' => ($match->lobby !== null || isset($result['lobby_label'])) && is_array($result) ? LobbyResults::describe($result)
                : (($result['decided'] ?? null) === 'standing' && isset($result['standing']['margin'])
                    ? TournamentRunner::standingLabel((string) ($result['standing']['legs'] ?? ''), (float) $result['standing']['margin'])
                    : ($result['label'] ?? null)),
            'number' => $match->seriesMatch !== null ? $match->seriesMatch->number : $match->chessGame?->number,
            'href' => $match->seriesMatch !== null
                ? route('matches.show', $match->seriesMatch)
                : ($match->chessGame !== null ? route('games.show', $match->chessGame)
                    // The director page's matches come without their board game: read only for a match that has neither.
                    : ($match->loadMissing('boardGame')->boardGame !== null && Route::has('board.show') ? route('board.show', $match->boardGame) : null)),
            // A result set on the tournament control (P18) is marked like a director's.
            'director' => $match->isDirectorResult() || ($result['by'] ?? null) === 'control' ? self::marker((array) $result) : null,
            'held' => $match->held !== null,
            // Settled by the race standing (Blockli's drawn decider): the box says so with the margin.
            'decision' => ($result['decided'] ?? null) === 'standing' && isset($result['standing']['margin'])
                ? TournamentRunner::standingLabel((string) ($result['standing']['legs'] ?? ''), (float) $result['standing']['margin']) : null,
            'if_needed' => $match->if_needed,
            'legs' => $legs,
            'games' => $this->games($match),
        ];
    }

    /**
     * Every game of a pairing that plays more than one (Blockli's two legs and its deciders), each with its own
     * link: the box's key opens only the latest (DerCaddy 2026-10-30: the first leg could not be watched again
     * from the bracket). The score is from the first side's view, as in the box; an empty list for a single game.
     *
     * @return list<array{n: int, href: string, score: string|null, live: bool}>
     */
    private function games(TournamentMatch $match): array
    {
        $first = $match->slots->firstWhere('slot', 0)?->tournament_participant_id;
        $members = $first === null ? [] : ($this->participants->get($first)?->memberIds() ?? []);

        return array_values(array_map(fn (array $game): array => array_diff_key($game, ['id' => 0, 'won' => 0]),
            self::pairingGames($match, $members, $this->boardGames?->get($match->id, collect()))));
    }

    /**
     * The board games of a pairing that plays more than one, in the order played, without aborted ones and without
     * those a correction replaced (`replaced_through`): each with its number, its board, its score from the view of
     * slot 0 (whose members are passed), the slot that won it (null for a draw or a game not over) and whether it is
     * on now. The bracket box and a game's tournament banner both list them (DerCaddy 2026-10-30). Empty for a single
     * game. A caller that read the match's games already passes them.
     *
     * @param  list<int>  $firstMembers
     * @param  Collection<int, BoardGame>|null  $games
     * @return list<array{n: int, id: int, href: string, score: string|null, won: int|null, live: bool}>
     */
    public static function pairingGames(TournamentMatch $match, array $firstMembers, ?Collection $games = null): array
    {
        if (! Route::has('board.show')) {
            return [];
        }

        $games = ($games ?? BoardGame::query()->where('tournament_match_id', $match->id)->where('status', '!=', BoardGameStatus::Aborted)
            ->orderBy('id')->get(['id', 'tournament_match_id', 'status', 'result', 'white_id']))
            ->filter(fn (BoardGame $game): bool => $game->id > (int) $match->replaced_through)->values();

        if ($games->count() < 2) {
            return [];
        }

        return array_values($games->map(function (BoardGame $game, int $index) use ($firstMembers): array {
            $white = $game->white_id !== null && in_array((int) $game->white_id, $firstMembers, true);
            $won = match ($game->status === BoardGameStatus::Finished ? $game->result : null) {
                '1-0' => $white ? 0 : 1,
                '0-1' => $white ? 1 : 0,
                default => null,
            };
            $draw = $game->status === BoardGameStatus::Finished && $game->result === '1/2-1/2';

            return [
                'n' => $index + 1,
                'id' => (int) $game->id,
                'href' => route('board.show', $game->id),
                'score' => $draw ? '½–½' : ($won === null ? null : ($won === 0 ? '1–0' : '0–1')),
                'won' => $won,
                'live' => $game->status === BoardGameStatus::Active,
            ];
        })->all());
    }

    /**
     * The finished legs of a two-leg pairing under way ({@see TournamentRunner::legsSoFar()}), read once per match: its
     * box and its stage's table both show them.
     *
     * @return array{points: array{0: float, 1: float}, label: string, game: int, legs: list<array{0: float, 1: float}>}|null
     */
    private function legs(TournamentMatch $match, ?TournamentStage $stage): ?array
    {
        if (! array_key_exists($match->id, $this->legs)) {
            $this->legs[$match->id] = TournamentRunner::legsSoFar($match, $this->tournament, $stage);
        }

        return $this->legs[$match->id];
    }

    /**
     * The public marker of a director result (TOURNAMENT-FORMATS.md, section 7).
     *
     * @param  array<string, mixed>  $result
     * @return array{corrected: bool, control: bool, lines: list<string>}
     */
    public static function marker(array $result): array
    {
        $at = fn (?string $time): string => $time === null ? '' : Carbon::parse($time)->timezone((string) config('esports.preseason.display_timezone'))->format('H:i');
        // Set or corrected on the tournament control (P18): by the organizer or an admin, unrated.
        if (($result['by'] ?? null) === 'control') {
            $who = $result['corrected'] ?? $result;
            $line = isset($result['corrected'])
                ? __('Corrected by :name at :time, was :old.', ['name' => (string) ($who['name'] ?? '?'), 'time' => $at($who['at'] ?? null), 'old' => (string) ($result['was'] ?? '')])
                : __('Set by :name at :time.', ['name' => (string) ($who['name'] ?? '?'), 'time' => $at($who['at'] ?? null)]);

            $effect = TournamentControl::storedElo($result['elo'] ?? null);
            $elo = $effect === null ? null : TournamentControl::describeElo($effect);

            return ['corrected' => isset($result['corrected']), 'control' => true, 'lines' => [$line, $elo === null
                ? __('Decided by the organizer or an admin; unrated.')
                : __('Decided by the organizer or an admin; the Elo of the played result was corrected: :elo.', ['elo' => $elo])]];
        }

        $lines = [__('Entered by :name at :time.', ['name' => (string) ($result['name'] ?? '?'), 'time' => $at($result['at'] ?? null)])];

        if (isset($result['corrected'])) {
            $lines[] = __('Corrected by :name at :time, was :old.', ['name' => (string) ($result['corrected']['name'] ?? '?'), 'time' => $at($result['corrected']['at'] ?? null), 'old' => (string) ($result['was'] ?? '')]);
        }

        $lines[] = __('Not confirmed by the players.');

        return ['corrected' => isset($result['corrected']), 'control' => false, 'lines' => $lines];
    }

    /**
     * Where an unknown side comes from, in words.
     *
     * @param  array<string, mixed>  $source
     */
    private static function source(array $source): string
    {
        return match ($source['take'] ?? null) {
            'winner' => __('Winner of :match', ['match' => self::matchName((string) ($source['match'] ?? ''))]),
            'loser' => __('Loser of :match', ['match' => self::matchName((string) ($source['match'] ?? ''))]),
            'group-rank' => __(':place. of group :group', ['place' => (int) ($source['rank'] ?? 1), 'group' => self::letter((int) ($source['group'] ?? 1))]),
            'rank' => __(':place. of :match', ['place' => (int) ($source['rank'] ?? 1), 'match' => self::matchName((string) ($source['match'] ?? ''))]),
            default => __('open'),
        };
    }

    /** Group 1 is A, group 2 B (26 groups at most). */
    private static function letter(int $group): string
    {
        return chr(64 + max(1, min(26, $group)));
    }

    private static function matchName(string $key): string
    {
        return strtoupper($key);
    }

    private static function number(float $value): string
    {
        if (abs($value - round($value)) < 0.001) {
            return (string) (int) round($value);
        }

        return abs($value - floor($value) - 0.5) < 0.001 ? (floor($value) > 0 ? (int) floor($value).'½' : '½') : number_format($value, 1);
    }
}
