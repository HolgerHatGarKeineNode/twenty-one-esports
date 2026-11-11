<?php

namespace App\Support\Tournaments;

use App\Enums\BoardGameStatus;
use App\Enums\ChessGameStatus;
use App\Enums\SeriesStatus;
use App\Enums\TournamentFormat;
use App\Models\BoardGame;
use App\Models\ChessGame;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentParticipant;
use App\Models\TournamentStage;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

/**
 * The live page's overview of every round robin group and Swiss stage as a cross table (user 2026-10-15: "Ein
 * Turnier-Admin muss eine ewiglange Liste durchscrollen … Eine Matrix aller Spiele wäre doch genial mit
 * Ampel-System, wo was steht und wo nachhak-Bedarf besteht"): one row and one column per player, a cell per
 * pairing with its score from the row's side and a traffic light.
 *
 * - RED, the direction should step in: a result held, a reported or disputed series, one with the admins; a board
 *   or chess game without a first move after RED_FIRST_MOVE_HOURS, a game restarted for a missing first move that
 *   still has none, a move deadline less than RED_DEADLINE_HOURS away.
 * - YELLOW, keep an eye on it: a move deadline less than YELLOW_DEADLINE_HOURS away, a ready match without a game yet.
 *
 * Every cell under way shows the time left to its move deadline (user 2026-10-15: "jetzt sehen die Admins nicht mehr
 * die ablaufenden Timeouts … ALLES IST zu textlich") and whether the row's player is to move; `clock` lists the
 * pairings by the deadline that comes first, for the tiles above the tables.
 * - GREEN: under way and moving. DONE: decided. WAITING: its sides are not known yet, or it was not needed.
 *
 * Reads every game of the tournament in one query per kind, never one per match.
 */
final class TournamentMatrix
{
    public const RED = 'red';

    public const YELLOW = 'yellow';

    public const GREEN = 'green';

    public const DONE = 'done';

    public const WAITING = 'waiting';

    /** No first move this long after the game started: red. */
    public const RED_FIRST_MOVE_HOURS = 6;

    /** The move deadline is less than this away: red. */
    public const RED_DEADLINE_HOURS = 6;

    /** The move deadline is less than this away: yellow. */
    public const YELLOW_DEADLINE_HOURS = 12;

    /** @var Collection<int, TournamentParticipant> */
    private Collection $participants;

    /** @var array<int|string, EloquentCollection<int, BoardGame>> board games by tournament match */
    private array $boardGames = [];

    /** @var array<int|string, EloquentCollection<int, ChessGame>> chess games by tournament match */
    private array $chessGames = [];

    public function __construct(private Tournament $tournament)
    {
        $this->participants = $tournament->participants()->get()->keyBy('id');
    }

    /**
     * One entry per round robin group or Swiss stage: its players (in seed order) and the cells by row and column
     * participant, plus how many pairings stand at each light.
     *
     * @return array{groups: list<array{title: string|null, players: list<array{id: int, name: string}>, cells: array<int, array<int, array{state: string, score: string, left: string|null, toMove: bool, flag: string|null, reason: string|null, href: string|null, key: string}>>}>, counts: array<string, int>, clock: list<array{state: string, left: string|null, mover: string|null, against: string|null, names: string, group: string|null, flag: string|null, reason: string|null, href: string|null, key: string}>}
     */
    public function overview(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $stages = TournamentStage::query()->where('tournament_id', $this->tournament->id)
            ->whereIn('format', [TournamentFormat::RoundRobin, TournamentFormat::Swiss])->orderBy('number')->get();
        $counts = array_fill_keys([self::RED, self::YELLOW, self::GREEN, self::DONE], 0);
        $empty = ['groups' => [], 'counts' => $counts, 'clock' => []];

        if ($stages->isEmpty()) {
            return $empty;
        }

        $matches = TournamentMatch::query()->where('tournament_id', $this->tournament->id)
            ->whereHas('round', fn ($query) => $query->whereIn('tournament_stage_id', $stages->pluck('id')))
            ->with(['round', 'slots', 'seriesMatch'])->orderBy('id')->get();
        $ids = $matches->pluck('id');
        $this->boardGames = BoardGame::query()->whereIn('tournament_match_id', $ids)->orderBy('id')->get()->groupBy('tournament_match_id')->all();
        $this->chessGames = ChessGame::query()->whereIn('tournament_match_id', $ids)->orderBy('id')->get()->groupBy('tournament_match_id')->all();
        $groups = [];
        $clock = [];

        foreach ($stages as $stage) {
            $own = $matches->filter(fn (TournamentMatch $match): bool => $match->round->tournament_stage_id === $stage->id);

            foreach ($own->groupBy(fn (TournamentMatch $match): int => (int) ($match->group ?? 0))->sortKeys() as $group => $groupMatches) {
                $title = (int) $group > 0 ? (string) __('Group :group', ['group' => chr(64 + max(1, min(26, (int) $group)))]) : ($stages->count() > 1 ? $stage->format->label() : null);
                $players = $this->players($groupMatches);
                $cells = [];

                foreach ($groupMatches as $match) {
                    $slots = $match->slots->sortBy('slot')->values();
                    $a = $slots[0]->tournament_participant_id ?? null;
                    $b = $slots[1]->tournament_participant_id ?? null;

                    if ($a === null || $b === null) {
                        continue;
                    }

                    $light = $this->light($match, $now);
                    $state = $light['state'];
                    [$scoreA, $scoreB] = $this->scores($match);
                    $score = fn (string $own, string $other): string => $own === '–' ? '' : $own.'–'.$other;
                    $left = $light['deadline'] === null ? null : self::left($light['deadline'] - $now->getTimestampMs());
                    $base = ['state' => $state, 'left' => $left, 'flag' => $light['flag'], 'reason' => $light['reason'], 'href' => $this->href($match), 'key' => strtoupper($match->key)];
                    $cells[$a][$b] = [...$base, 'score' => $score($scoreA, $scoreB), 'toMove' => $light['mover'] === $a];
                    $cells[$b][$a] = [...$base, 'score' => $score($scoreB, $scoreA), 'toMove' => $light['mover'] === $b];

                    if (isset($counts[$state])) {
                        $counts[$state]++;
                    }

                    if ($light['deadline'] !== null || $state === self::RED) {
                        $mover = $light['mover'];
                        $clock[] = [...$base, 'at' => $light['deadline'] ?? 0, 'mover' => $mover === null ? null : $this->name($mover),
                            'against' => $mover === null ? null : $this->name($mover === $a ? $b : $a), 'names' => $this->name($a).' – '.$this->name($b), 'group' => $title];
                    }
                }

                $groups[] = ['title' => $title, 'players' => $players, 'cells' => $cells];
            }
        }

        // The deadline that comes first first; a red pairing without a deadline (held, disputed) before all.
        usort($clock, fn (array $x, array $y): int => $x['at'] <=> $y['at']);
        $clock = array_map(function (array $entry): array {
            unset($entry['at']);

            return $entry;
        }, $clock);

        return ['groups' => $groups, 'counts' => $counts, 'clock' => $clock];
    }

    /**
     * The players of a group's matches, in seed order.
     *
     * @param  Collection<int, TournamentMatch>  $matches
     * @return list<array{id: int, name: string}>
     */
    private function players(Collection $matches): array
    {
        $ids = $matches->flatMap(fn (TournamentMatch $match) => $match->slots->pluck('tournament_participant_id'))->filter()->unique();

        return array_values($ids->map(fn (int $id): ?TournamentParticipant => $this->participants->get($id))->filter()
            ->sortBy(fn (TournamentParticipant $participant): array => [$participant->seed ?? PHP_INT_MAX, $participant->id])
            ->map(fn (TournamentParticipant $participant): array => ['id' => $participant->id, 'name' => (string) $participant->name])->all());
    }

    private function name(int $participantId): string
    {
        return (string) ($this->participants->get($participantId)->name ?? '?');
    }

    /**
     * The light of one pairing and why, the move deadline under way (unix ms) and the entry to move.
     *
     * @return array{state: string, reason: string|null, deadline: int|null, mover: int|null, flag: string|null}
     */
    private function light(TournamentMatch $match, CarbonImmutable $now): array
    {
        $light = fn (string $state, ?string $reason = null, ?int $deadline = null, ?int $mover = null, ?string $flag = null): array => ['state' => $state, 'reason' => $reason, 'deadline' => $deadline, 'mover' => $mover, 'flag' => $flag];

        if ($match->held !== null) {
            return $light(self::RED, (string) __('Result on hold: decide it on the control'), flag: 'held');
        }

        if ($match->result !== null || $match->status === 'done') {
            return $light(self::DONE);
        }

        if ($match->status !== 'ready') {
            return $light(self::WAITING);
        }

        $series = $match->seriesMatch;

        if ($series !== null) {
            return match (true) {
                $series->overdue_at !== null => $light(self::RED, (string) __('No result by the deadline: with the admins'), flag: 'overdue'),
                $series->status === SeriesStatus::Disputed => $light(self::RED, (string) __('Result disputed'), flag: 'disputed'),
                $series->status === SeriesStatus::Reported => $light(self::YELLOW, (string) __('Result reported, waiting for the confirmation'), flag: 'reported'),
                default => $light(self::GREEN),
            };
        }

        $board = ($this->boardGames[$match->id] ?? new EloquentCollection)->filter(fn (BoardGame $game): bool => $game->id > $match->boardReplacedThrough());
        $chess = ($this->chessGames[$match->id] ?? new EloquentCollection);
        $active = $board->first(fn (BoardGame $game): bool => $game->status === BoardGameStatus::Active)
            ?? $chess->first(fn (ChessGame $game): bool => $game->status === ChessGameStatus::Active);

        if ($active === null) {
            return $light(self::YELLOW, (string) __('No game started yet'), flag: 'nogame');
        }

        $nowMs = $now->getTimestampMs();
        $whiteToMove = $active instanceof BoardGame ? $active->turn === 'w' : $active->ply % 2 === 0;
        $mover = $this->moverId($match, (int) ($whiteToMove ? $active->white_id : $active->black_id));
        $deadline = $active->deadline_ms === null ? null : (int) $active->deadline_ms;
        $left = $deadline === null ? null : $deadline - $nowMs;
        $restarted = $board->contains(fn (BoardGame $game): bool => $game->status === BoardGameStatus::Aborted);
        $name = $mover === null ? '?' : $this->name($mover);

        if ($active->ply === 0 && $restarted) {
            return $light(self::RED, (string) __('Restarted for a missing first move, still none: :name to move', ['name' => $name]), $deadline, $mover, 'restart');
        }

        if ($active->ply === 0 && $now->diffInHours($active->created_at, true) >= self::RED_FIRST_MOVE_HOURS) {
            return $light(self::RED, (string) __('No first move for :hours h: :name to move', ['hours' => (int) floor($now->diffInHours($active->created_at, true)), 'name' => $name]), $deadline, $mover, 'nofirst');
        }

        if ($left !== null && $left < self::RED_DEADLINE_HOURS * 3_600_000) {
            return $light(self::RED, (string) __(':name to move', ['name' => $name]), $deadline, $mover);
        }

        if ($left !== null && $left < self::YELLOW_DEADLINE_HOURS * 3_600_000) {
            return $light(self::YELLOW, (string) __(':name to move', ['name' => $name]), $deadline, $mover);
        }

        return $light(self::GREEN, (string) __(':name to move', ['name' => $name]), $deadline, $mover);
    }

    /** The entry a user plays for in this match. */
    private function moverId(TournamentMatch $match, int $userId): ?int
    {
        foreach ($match->slots as $slot) {
            $participant = $this->participants->get((int) $slot->tournament_participant_id);

            if ($participant !== null && in_array($userId, $participant->memberIds(), true)) {
                return $participant->id;
            }
        }

        return null;
    }

    /** The time left to a deadline, short: "48m" under an hour, "5h12" under ten, else "14h"; "0m" once passed. */
    public static function left(int $ms): string
    {
        $minutes = intdiv(max(0, $ms), 60_000);

        return match (true) {
            $minutes < 60 => $minutes.'m',
            $minutes < 600 => intdiv($minutes, 60).'h'.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT),
            default => intdiv($minutes, 60).'h',
        };
    }

    /**
     * Each side's score: the stored result, the legs so far of a two-leg pairing under way, else a dash.
     *
     * @return array{0: string, 1: string}
     */
    private function scores(TournamentMatch $match): array
    {
        $result = $match->result;

        if ($result !== null) {
            if (($result['double_loss'] ?? false) === true) {
                return ['0', '0'];
            }

            $won = $result['games_won'] ?? [];

            if (count($won) === 2) {
                return [self::number((float) $won[0]), self::number((float) $won[1])];
            }

            $winner = $result['winner'] ?? null;

            return $winner === null ? ['½', '½'] : ($winner === 0 ? ['1', '0'] : ['0', '1']);
        }

        $finished = ($this->boardGames[$match->id] ?? new EloquentCollection)
            ->filter(fn (BoardGame $game): bool => $game->id > $match->boardReplacedThrough() && $game->status === BoardGameStatus::Finished)->values()->all();

        // A combined tournament (FormatOptions::$games): its chess legs count too, all of its sequence in the order played.
        if ($this->tournament->formatOptions()->sequence()) {
            $chess = ($this->chessGames[$match->id] ?? new EloquentCollection)
                ->filter(fn (ChessGame $game): bool => $game->id > (int) $match->replaced_through && $game->status === ChessGameStatus::Finished)->values()->all();
            $finished = TournamentRunner::inLegOrder($this->tournament, [...$finished, ...$chess]);
        }

        if ($finished === []) {
            return ['–', '–'];
        }

        $members = $this->participants->get((int) $match->slots->firstWhere('slot', 0)?->tournament_participant_id)?->memberIds() ?? [];
        $points = [0.0, 0.0];

        foreach (array_slice($finished, 0, TournamentRunner::legCount($this->tournament)) as $leg) {
            $whiteSlot = in_array((int) $leg->white_id, $members, true) ? 0 : 1;
            [$white, $black] = match ($leg->result) {
                '1-0' => [1.0, 0.0],
                '0-1' => [0.0, 1.0],
                default => [0.5, 0.5],
            };
            $points[$whiteSlot] += $white;
            $points[1 - $whiteSlot] += $black;
        }

        return [self::number($points[0]), self::number($points[1])];
    }

    /** Where a cell leads: the game on now (or the last one), the series room. */
    private function href(TournamentMatch $match): ?string
    {
        if ($match->seriesMatch !== null) {
            return route('matches.show', $match->seriesMatch);
        }

        $board = ($this->boardGames[$match->id] ?? new EloquentCollection);
        $game = $board->first(fn (BoardGame $game): bool => $game->status === BoardGameStatus::Active) ?? $board->last();

        if ($game !== null) {
            return Route::has('board.show') ? route('board.show', $game) : null;
        }

        $chess = ($this->chessGames[$match->id] ?? new EloquentCollection);
        $game = $chess->first(fn (ChessGame $game): bool => $game->status === ChessGameStatus::Active) ?? $chess->last();

        return $game !== null ? route('games.show', $game) : null;
    }

    private static function number(float $value): string
    {
        $whole = (int) floor($value);
        $half = $value - $whole >= 0.5;

        return $half ? ($whole === 0 ? '½' : $whole.'½') : (string) $whole;
    }
}
