<?php

namespace App\Support\TwentyOne\Stream;

use App\Enums\ChessEndReason;
use App\Enums\ChessGameStatus;
use App\Games\GameRegistry;
use App\Models\ChessGame;
use App\Models\ChessQueueEntry;
use App\Models\Clan;
use App\Support\Chess\ChessGameService;

/**
 * What the stream scene shows, read from the database: the one live blitz
 * game (the oldest active one when several run), or the one that ended in
 * the last minute, turned into the data contract of
 * resources/views/stream/scene.blade.php.
 *
 * Names are the public profile names the lobby shows (User::displayName()).
 * Stats are real counts only; nothing here is a placeholder or a rating.
 */
final class SceneSource
{
    public function __construct(
        private ChessGameService $chess,
        private GameRegistry $games,
    ) {}

    public function liveGame(): ?ChessGame
    {
        return ChessGame::query()->live()->where('status', ChessGameStatus::Active)
            ->with(['white', 'black'])->oldest('id')->first();
    }

    /**
     * The live game that ended most recently, if within `$seconds`.
     */
    public function endedGame(int $seconds): ?ChessGame
    {
        return ChessGame::query()->live()->whereIn('status', [ChessGameStatus::Finished, ChessGameStatus::Aborted])
            ->where('ended_at', '>=', now()->subSeconds($seconds))
            ->with(['white', 'black'])->latest('ended_at')->latest('id')->first();
    }

    /**
     * @return array{white: array{name: string, clockMs: int, toMove: bool}, black: array{name: string, clockMs: int, toMove: bool}, fen: string, lastMove: array{from: string, to: string}|null, mode: string, stats: string, url: string, result: string|null}
     */
    public function scene(ChessGame $game, int $nowMs): array
    {
        $clocks = $this->chess->clocks($game, $nowMs);
        $toMove = $game->isActive() && $game->clocksRunning() ? $game->turn() : null;
        $last = $game->ply > 0 ? $game->moves()->where('ply', $game->ply)->first() : null;
        $label = $this->games->mode('chess', $game->mode)->name ?? $game->mode;

        return [
            'white' => ['name' => $game->white->displayName(), 'clockMs' => $clocks['w'], 'toMove' => $toMove === 'w'],
            'black' => ['name' => $game->black->displayName(), 'clockMs' => $clocks['b'], 'toMove' => $toMove === 'b'],
            'fen' => $game->fen,
            'lastMove' => $last === null ? null : ['from' => substr($last->uci, 0, 2), 'to' => substr($last->uci, 2, 2)],
            'mode' => 'LIVE · CHESS '.mb_strtoupper($label).($game->rated ? '' : ' · CASUAL'),
            'stats' => $this->stats(),
            'url' => (string) config('twentyone.stream.scene.url'),
            'result' => $game->isActive() ? null : $this->result($game),
        ];
    }

    /**
     * One line of real counts: finished games (as the footer counts them),
     * live games, clans, and the blitz queue when someone waits.
     */
    public function stats(): string
    {
        $played = ChessGame::query()->where('status', ChessGameStatus::Finished)->count();
        $live = ChessGame::query()->live()->where('status', ChessGameStatus::Active)->count();
        $clans = Clan::query()->count();
        $queue = ChessQueueEntry::query()->count();

        $parts = [
            $played.' '.($played === 1 ? 'game' : 'games').' played',
            $live.' live',
            $clans.' '.($clans === 1 ? 'clan' : 'clans'),
        ];

        if ($queue > 0) {
            $parts[] = $queue.' in queue';
        }

        return implode(' · ', $parts);
    }

    /**
     * The header line after the game: "0-1 · White ran out of time".
     */
    private function result(ChessGame $game): string
    {
        if ($game->status === ChessGameStatus::Aborted || $game->end_reason === ChessEndReason::Aborted) {
            return 'Game aborted';
        }

        $loser = match ($game->result) {
            '1-0' => 'Black',
            '0-1' => 'White',
            default => null,
        };

        $reason = match ($game->end_reason) {
            ChessEndReason::Timeout => $loser !== null ? $loser.' ran out of time' : 'Draw on time',
            ChessEndReason::Resignation => ($loser ?? 'A player').' resigned',
            ChessEndReason::Abandoned => ($loser ?? 'A player').' left the game',
            null => 'Game over',
            default => $game->end_reason->label(),
        };

        $score = $game->result === '1/2-1/2' ? '½-½' : $game->result;

        return $score === null ? $reason : $score.' · '.$reason;
    }
}
