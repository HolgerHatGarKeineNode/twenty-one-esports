<?php

namespace App\Support\Dock;

use App\Games\GameRegistry;
use App\Models\BoardChallenge;
use App\Models\BoardGame;
use App\Models\BoardInvite;
use App\Models\ChessChallenge;
use App\Models\ChessGame;
use App\Models\ChessInvite;
use App\Models\Clan;
use App\Models\ClanInvite;
use App\Models\SeriesInvite;
use App\Models\SeriesMatch;
use App\Models\User;

/**
 * One tab of the match dock (MatchDock.dc.html, "Anatomy of a tab"): who the
 * other side is, the state in words, the one number that matters and where
 * the tab leads. Built by App\Support\Dock\OpenMatches, never by hand.
 *
 * `group` orders the dock: `live` (live blitz and live series) first, then
 * `need` (on you) and `wait` (on them), each by `deadlineMs`.
 *
 * `tick` lets the browser count the number down: `endsAt` in Unix ms, the
 * `format` ('clock' = m:ss, 'hm' = "7 h 28"), the `total` the fuse is a
 * share of, and `redUnder` in ms. Null means the number does not run.
 */
final readonly class DockItem
{
    /**
     * @param  'blitz'|'daily'|'series'|'blitz_invite'|'daily_challenge'|'clan_invite'|'casual_invite'|'board'|'board_invite'  $kind
     * @param  'live'|'need'|'wait'  $group
     * @param  'your_move'|'their_move'|'answer'|'invite'|'starts'|'live'|'accept'|'waiting'|'dispute'|'ready'  $phase
     * @param  array{endsAt: int, format: 'clock'|'hm', total: int, redUnder: int}|null  $tick
     */
    public function __construct(
        public string $key,
        public string $kind,
        public string $group,
        public string $phase,
        public bool $needsYou,
        public string $name,
        public ?User $face,
        public ?string $tag,
        public string $number,
        public string $href,
        public string $title,
        public string $state,
        public string $trailing,
        public string $line,
        public string $sentence,
        public ?string $action,
        public ?int $deadlineMs,
        public ?array $tick,
        public ChessGame|SeriesMatch|ChessInvite|ChessChallenge|ClanInvite|SeriesInvite|BoardGame|BoardInvite|BoardChallenge $model,
        public ?Clan $clan = null,
    ) {}

    public function isChess(): bool
    {
        return in_array($this->kind, ['blitz', 'daily', 'blitz_invite', 'daily_challenge'], true);
    }

    /**
     * The icon of a board game item (plan "Mühle und Dame", P5): the board
     * game's own, next to chess's; null for every other item.
     */
    public function boardIcon(): ?string
    {
        $game = $this->model instanceof BoardGame || $this->model instanceof BoardInvite || $this->model instanceof BoardChallenge ? $this->model->game : null;

        return $game === null ? null : (app(GameRegistry::class)->find($game)?->assets()->icon ?? 'grid');
    }

    /** The two-letter game mark of a non-chess item: RL, FC. */
    public function gameMark(): string
    {
        $game = $this->model instanceof SeriesMatch || $this->model instanceof SeriesInvite ? $this->model->game : null;
        $short = $game === null ? null : app(GameRegistry::class)->find($game)?->assets()->shortLabel;

        return mb_substr($short ?? 'RL', 0, 2);
    }

    public function isLive(): bool
    {
        return $this->group === 'live';
    }

    /**
     * The chess game behind the tab, for the live updates of its board.
     */
    public function gameId(): ?int
    {
        return $this->model instanceof ChessGame ? $this->model->id : null;
    }

    /**
     * Share of the time left, 0..1, for the fuse; null when nothing runs.
     */
    public function fuseShare(int $nowMs): ?float
    {
        if ($this->tick === null || $this->tick['total'] <= 0) {
            return null;
        }

        return max(0.0, min(1.0, ($this->tick['endsAt'] - $nowMs) / $this->tick['total']));
    }

    public function isUrgent(int $nowMs): bool
    {
        return $this->tick !== null && $this->tick['redUnder'] > $this->tick['endsAt'] - $nowMs;
    }
}
