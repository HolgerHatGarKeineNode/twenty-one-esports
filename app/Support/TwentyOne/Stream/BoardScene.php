<?php

namespace App\Support\TwentyOne\Stream;

use App\Enums\BoardGameStatus;
use App\Games\BoardGame as BoardGameDefinition;
use App\Games\GameRegistry;
use App\Models\BoardGame;
use App\Models\User;
use App\Support\Board\BoardGameService;
use App\Support\Board\BoardRules;

/**
 * The data of the "board live" scene (d5, plan "Mühle und Dame", P7): the
 * board games next to chess on the stream. While one runs, the oldest live
 * board game with both players, their clocks and the position; without one,
 * the start position of every board game that is switched on, as a teaser.
 *
 * The board is drawn from the rules' own view (BoardRules::view(): lines,
 * cells, points, pieces), scaled here to the scene's pixels, so the view
 * knows no game: nine men's morris and checkers draw the same way the
 * board page (resources/js/boardGame.js) draws them.
 *
 * States for the rotation (RotationPlanner): `off` while no board game is
 * switched on (no scene), `live` while one runs, `idle` otherwise.
 */
class BoardScene
{
    public const OFF = 'off';

    public const IDLE = 'idle';

    public const LIVE = 'live';

    /** Piece colours of the board page (resources/js/boardGame.js PIECE_FILL / PIECE_STROKE). */
    public const PIECE_FILL = ['w' => '#F4F4F5', 'b' => '#09090B'];

    public const PIECE_STROKE = ['w' => '#A1A1AA', 'b' => '#D4D4D8'];

    /**
     * Pieces drawn as bars instead of discs, as on the board page
     * (resources/js/boardGame.js BARS): Blockli's blocks on the board span
     * two squares and the groove between them, the blocks a side has left
     * stand short in its tray. `length` is `board` for a full block, else a
     * multiple of the piece radius.
     *
     * @var array<string, array{horizontal: bool, length: 'board'|float}>
     */
    public const BARS = [
        'block-h' => ['horizontal' => true, 'length' => 'board'],
        'block-v' => ['horizontal' => false, 'length' => 'board'],
        'handle-h' => ['horizontal' => true, 'length' => 2.2],
        'handle-v' => ['horizontal' => false, 'length' => 2.2],
        'spare' => ['horizontal' => false, 'length' => 1.5],
    ];

    public function __construct(
        private GameRegistry $games,
        private BoardGameService $boards,
        private StreamImages $images,
    ) {}

    /**
     * The board games switched on, by slug.
     *
     * @return list<string>
     */
    public function slugs(): array
    {
        return array_values(array_map(fn ($game): string => $game->slug(), $this->games->boards()));
    }

    /**
     * The board game the scene shows: the oldest live one of a board game
     * that is switched on; null when none runs (or none is switched on).
     */
    public function liveGame(): ?BoardGame
    {
        $slugs = $this->slugs();

        if ($slugs === []) {
            return null;
        }

        return BoardGame::query()->where('status', BoardGameStatus::Active)->whereIn('game', $slugs)
            ->with(['white', 'black'])->oldest('id')->first();
    }

    /**
     * `off`, `idle` or `live`, for the rotation.
     */
    public function state(): string
    {
        if ($this->slugs() === []) {
            return self::OFF;
        }

        return $this->liveGame() === null ? self::IDLE : self::LIVE;
    }

    /**
     * The scene's data, as resources/views/stream/rotation/d5-board.blade.php describes it.
     *
     * @param  array<string, mixed>  $stats  StreamStats::all()
     * @return array{board: array<string, mixed>|null, boards: list<array{name: string, view: array<string, mixed>, last: list<string>}>, stats: array<string, mixed>, backdrop: string|null}
     */
    public function data(int $nowMs, array $stats): array
    {
        $game = $this->liveGame();
        $rules = $game === null ? null : $this->boards->rulesOf($game);

        if ($game !== null && $rules !== null) {
            return [
                'board' => $this->live($game, $rules, $nowMs),
                'boards' => [],
                'stats' => $stats,
                'backdrop' => $this->images->backdrop($game->game),
            ];
        }

        $boards = [];

        foreach ($this->games->boards() as $definition) {
            if ($definition instanceof BoardGameDefinition) {
                $start = $definition->rules();
                $boards[] = ['name' => $this->credited($definition->slug()), 'view' => $start->view($start->start()), 'last' => []];
            }
        }

        return ['board' => null, 'boards' => $boards, 'stats' => $stats, 'backdrop' => $this->images->backdrop(StreamImages::BRAND)];
    }

    /**
     * @param  BoardRules<mixed>  $rules
     * @return array<string, mixed>
     */
    private function live(BoardGame $game, BoardRules $rules, int $nowMs): array
    {
        $clocks = $this->boards->clocks($game, $nowMs);
        $toMove = $game->isActive() && $game->clocksRunning() ? $game->turn : null;
        $last = $game->ply > 0 ? $game->moves()->where('ply', $game->ply)->first() : null;
        $name = $this->credited($game->game);
        $mode = $this->games->mode($game->game, $game->mode)->name ?? $game->mode;

        return [
            'game' => $name,
            // Sentence case like the chess arena's mode line (RotationKit::modeLabel()): "Checkers · Blitz 5+3, casual".
            'mode' => $name.' · '.$mode.($game->rated ? '' : ', casual'),
            'white' => $this->side($game->white, $clocks['w'], $toMove === 'w'),
            'black' => $this->side($game->black, $clocks['b'], $toMove === 'b'),
            // The rules' view and the last move's points; the scene scales them with drawing().
            'view' => $rules->view($rules->deserialize($game->position)),
            'last' => $last === null ? [] : $rules->path($last->move),
        ];
    }

    /**
     * How long and thick a block is in view units, as the board page reads it
     * (boardGame.js barSize()): two cells and the groove between them long, a
     * little thinner than the groove; null when the cells have no grooves
     * between them (checkers), where no piece is a bar.
     *
     * @param  list<array{x: int, y: int, size: int}>  $cells
     * @return array{length: float, thickness: float}|null
     */
    private static function barSize(array $cells): ?array
    {
        if (count($cells) < 2) {
            return null;
        }

        $size = $cells[0]['size'];
        $steps = array_filter(array_map(fn (array $cell): int => abs($cell['x'] - $cells[0]['x']), $cells), fn (int $dx): bool => $dx > 0);
        $pitch = $steps === [] ? null : min($steps);

        return $pitch !== null && $pitch > $size ? ['length' => (float) ($pitch + $size), 'thickness' => ($pitch - $size) * 0.8] : null;
    }

    /** The name with its credit (plan "Blockli", P3): "Blockli by DerCaddy"; the plain name for a game without one. */
    private function credited(string $slug): string
    {
        $credit = $this->games->find($slug)?->assets()->credit;

        return $this->games->name($slug).($credit === null ? '' : ' '.$credit);
    }

    /**
     * @return array{name: string, clockMs: int, toMove: bool, avatar: string|null}
     */
    private function side(?User $user, int $clockMs, bool $toMove): array
    {
        return ['name' => $user?->displayName() ?? '', 'clockMs' => $clockMs, 'toMove' => $toMove, 'avatar' => $this->images->forUser($user)];
    }

    /**
     * The rules' view of a position scaled into a `size` x `size` square at
     * (x, y), centred when the view is not square (Blockli's 880 x 1020 with
     * its trays): lines, cells, the points as small dots, the pieces with the
     * page's radius (a little over a third of the closest distance between
     * two points), blocks as bars (BARS) and the points of the last move.
     *
     * @param  array{width: int, height: int, lines: list<array{0: int, 1: int, 2: int, 3: int}>, cells: list<array{x: int, y: int, size: int}>, points: list<array{id: string, x: int, y: int}>, pieces: array<string, array{side: 'w'|'b', kind: string}>}  $view
     * @param  list<string>  $lastPath  point ids of the last move
     * @return array{x: float, y: float, size: float, lines: list<array{0: float, 1: float, 2: float, 3: float}>, cells: list<array{0: float, 1: float, 2: float}>, dots: list<array{0: float, 1: float}>, pieces: list<array{x: float, y: float, side: string, king: bool}>, bars: list<array{x: float, y: float, w: float, h: float, r: float, side: string, kind: string}>, last: list<array{0: float, 1: float}>, radius: float, stroke: float, dot: float}
     */
    public static function drawing(array $view, array $lastPath, float $x, float $y, float $size): array
    {
        $scale = $size / max(1, $view['width'], $view['height']);
        $offsetX = ($size - $view['width'] * $scale) / 2;
        $offsetY = ($size - $view['height'] * $scale) / 2;
        $at = fn (float $px, float $py): array => [round($x + $offsetX + $px * $scale, 2), round($y + $offsetY + $py * $scale, 2)];
        $closest = INF;
        $points = $view['points'];

        foreach ($points as $i => $a) {
            foreach (array_slice($points, $i + 1) as $b) {
                $closest = min($closest, hypot($a['x'] - $b['x'], $a['y'] - $b['y']));
            }
        }

        $radius = (is_finite($closest) ? $closest * 0.36 : 20) * $scale;
        $byId = array_column($points, null, 'id');
        $bar = self::barSize($view['cells']);
        $pieces = [];
        $bars = [];

        foreach ($view['pieces'] as $id => $piece) {
            if (! isset($byId[$id])) {
                continue;
            }

            [$px, $py] = $at($byId[$id]['x'], $byId[$id]['y']);
            $shape = $bar === null ? null : (self::BARS[$piece['kind']] ?? null);

            if ($shape === null) {
                // The ring only for a king (checkers): a Blockli pawn or a morris man is a plain disc.
                $pieces[] = ['x' => $px, 'y' => $py, 'side' => $piece['side'], 'king' => $piece['kind'] === 'king'];

                continue;
            }

            $full = $shape['length'] === 'board';
            $long = $full ? $bar['length'] * $scale : $radius * (float) $shape['length'];
            $thick = ($full ? $bar['thickness'] : $bar['thickness'] * 0.8) * $scale;
            [$w, $h] = $shape['horizontal'] ? [$long, $thick] : [$thick, $long];
            $bars[] = ['x' => round($px - $w / 2, 2), 'y' => round($py - $h / 2, 2), 'w' => round($w, 2), 'h' => round($h, 2), 'r' => round($thick / 2, 2), 'side' => $piece['side'], 'kind' => $piece['kind']];
        }

        return [
            'x' => $x,
            'y' => $y,
            'size' => $size,
            'lines' => array_map(fn (array $line): array => [...$at($line[0], $line[1]), ...$at($line[2], $line[3])], $view['lines']),
            'cells' => array_map(fn (array $cell): array => [...$at($cell['x'], $cell['y']), round($cell['size'] * $scale, 2)], $view['cells']),
            'dots' => array_map(fn (array $point): array => $at($point['x'], $point['y']), $points),
            'pieces' => $pieces,
            'bars' => $bars,
            'last' => array_values(array_map(fn (string $id): array => $at($byId[$id]['x'], $byId[$id]['y']), array_filter($lastPath, fn (string $id): bool => isset($byId[$id])))),
            'radius' => round($radius, 2),
            'stroke' => round(max(1.5, $radius * 0.12), 2),
            'dot' => round(max(2, $radius * 0.14), 2),
        ];
    }
}
