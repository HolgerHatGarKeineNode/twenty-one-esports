<?php

namespace App\Support\Invites;

use App\Enums\InviteLinkType;
use App\Games\Blockfill;
use App\Games\GameKind;
use App\Games\GameRegistry;
use App\Games\ScoreGame;
use App\Models\BoardGame;
use App\Models\User;
use App\Support\GameNames;
use App\Support\Navigation\ShellNavigation;
use App\Support\Stacker\BlockfillWeeks;
use App\Support\Stacker\StackerRuns;

/**
 * Which game an invite is for, read from the registry, so a new game shows
 * up in the invite picker (/invite) and in the invite module by its kind
 * alone, with no list to keep:
 *
 *  - chess: a daily or a blitz link (InviteLinkType::Daily, ::Blitz);
 *  - a board game (nine men's morris, checkers): a board link in its own
 *    modes, correspondence first when the game has it (InviteLinkType::Board);
 *  - a series game: the team challenge, made on the challenge page with a
 *    lineup and start times (InviteLinkType::Series), so the picker leads there;
 *  - a score game such as Blockfill: "beat my time" (InviteLinkType::Score).
 *    No seat, no opponent: the link opens the game with the inviter's best.
 *
 * The game an invite starts on is the shell's active game (ShellNavigation:
 * the page's game, else the game opened last, else the last played): the
 * same game the context bar names, so "invite" never means chess while the
 * player is in Blockfill.
 *
 * @phpstan-type InviteGame array{slug: string, name: string, kind: 'chess'|'board'|'series'|'score', modes: list<string>}
 */
final class InviteGames
{
    /** The board game modes a link can start, in the order offered (correspondence first, as daily chess). */
    public const BOARD_MODES = [BoardGame::CORRESPONDENCE, 'blitz'];

    /** The chess link modes, in the order offered (the module made daily links before the picker). */
    public const CHESS_MODES = ['daily', 'blitz'];

    public function __construct(private GameRegistry $registry) {}

    /**
     * Every game a player can invite a friend to, in registry order.
     *
     * @return array<string, InviteGame>
     */
    public function all(): array
    {
        $games = [];

        foreach ($this->registry->all() as $slug => $game) {
            $entry = match ($game->kind()) {
                GameKind::Chess => ['kind' => 'chess', 'modes' => self::CHESS_MODES],
                GameKind::Board => ['kind' => 'board', 'modes' => array_values(array_filter(self::BOARD_MODES, fn (string $mode): bool => $game->mode($mode) !== null))],
                GameKind::Series => ['kind' => 'series', 'modes' => []],
                GameKind::Score => ['kind' => 'score', 'modes' => []],
            };

            // A board game without a mode a link can start has nothing to invite to.
            if ($entry['kind'] === 'board' && $entry['modes'] === []) {
                continue;
            }

            $games[$slug] = ['slug' => $slug, 'name' => GameNames::game($slug), ...$entry];
        }

        return $games;
    }

    /** @return InviteGame|null */
    public function find(?string $slug): ?array
    {
        return $slug === null ? null : ($this->all()[$slug] ?? null);
    }

    /**
     * The game the player is in: the shell's active game when it takes
     * invites, else chess, else the first that does.
     */
    public function contextGame(): string
    {
        $games = $this->all();
        $active = ShellNavigation::current()->activeGame()['slug'];

        return match (true) {
            isset($games[$active]) => $active,
            isset($games['chess']) => 'chess',
            default => (string) array_key_first($games),
        };
    }

    /**
     * The link type a game and mode make, or null for a series game (its
     * challenge needs a lineup and times: the challenge page makes it).
     */
    public function linkType(string $slug, ?string $mode = null): ?InviteLinkType
    {
        return match ($this->find($slug)['kind'] ?? null) {
            'chess' => $mode === 'blitz' ? InviteLinkType::Blitz : InviteLinkType::Daily,
            'board' => InviteLinkType::Board,
            'score' => InviteLinkType::Score,
            default => null,
        };
    }

    /**
     * The player's best of this week in a score game, in the game's unit
     * (milliseconds for a time), or null without one. Blockfill reads its
     * verified runs of the week on the week's rules (the weekly hunt starts
     * afresh on Monday; a time of other blocks is no best of these);
     * another score game has no best the league can name yet.
     */
    public function best(User $user, string $slug): ?int
    {
        if ($slug !== Blockfill::SLUG || ! $this->registry->isScore($slug)) {
            return null;
        }

        $ticks = app(StackerRuns::class)->best($user, StackerRuns::weekOf(now()), app(BlockfillWeeks::class)->difficultyAt());

        return $ticks === null ? null : Blockfill::milliseconds($ticks);
    }

    /** The best as people read it ("1:23.333"), or null without one. */
    public function bestLabel(User $user, string $slug): ?string
    {
        $best = $this->best($user, $slug);
        $game = $this->registry->find($slug);

        if ($best === null || ! $game instanceof ScoreGame) {
            return null;
        }

        $mode = $game->modes()[array_key_first($game->modes())];

        return $game->metric($mode)->format($best);
    }
}
