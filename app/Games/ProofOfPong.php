<?php

namespace App\Games;

use App\Games\Contracts\Game;

/**
 * Proof of Pong, the league's own arcade game (plan "Proof of Pong"): classic Pong to 21 points in Bitcoin meme
 * culture, on a deterministic physics core the server and the browser both run (App\Support\Pong). Registered only
 * while `esports.pong.enabled` is on (AppServiceProvider); P1 plays against a bot, P2 adds live 1v1 with Elo, and
 * the league's surfaces (lists, ladder, sitemap, rules, share cards, stream, tournaments) take it up in P4.
 *
 * A game is a two-sided result in points (21:17, or 23:21 after 20:20). Its kind is its own (GameKind::Arcade), so
 * no switch that serves chess, a series, a board game, a score game or Hyperbitcoinization takes it for one of those.
 */
final class ProofOfPong implements Game
{
    public const SLUG = 'proof-of-pong';

    /** The defaults of `esports.pong` (game classes read no config): a game to 21, two points ahead. */
    public const POINTS_TO_WIN = 21;

    public const WIN_BY = 2;

    public function slug(): string
    {
        return self::SLUG;
    }

    public function name(): string
    {
        return 'Proof of Pong';
    }

    public function kind(): GameKind
    {
        return GameKind::Arcade;
    }

    public function modes(): array
    {
        return [
            // Live only: Pong has no correspondence (plan, "Out").
            'live' => new GameMode('live', 'Live', 1, [], [], 'player', false),
        ];
    }

    public function mode(string $slug): ?GameMode
    {
        return $this->modes()[$slug] ?? null;
    }

    public function resultSchema(GameMode $mode): array
    {
        return ['score' => 'two points [own, opponent], one side at 21 or more and two ahead'];
    }

    public function validateResult(GameMode $mode, array $result): array
    {
        $score = $result['score'] ?? null;

        if (! is_array($score) || ! array_is_list($score) || count($score) !== 2 || ! is_int($score[0]) || ! is_int($score[1])) {
            return ['score'];
        }

        [$a, $b] = $score;
        $high = max($a, $b);
        $low = min($a, $b);

        if ($low < 0 || $high < self::POINTS_TO_WIN || $high - $low < self::WIN_BY) {
            return ['score'];
        }

        // A game stops at the first score that wins it. Past 21 a point (or a Halving's two) only ends it from a
        // tie or a one-point lead, so the loser stood at least at the winner's score before it minus one.
        if ($high > self::POINTS_TO_WIN + 1 && $high - $low > self::WIN_BY + 1) {
            return ['score'];
        }

        return [];
    }

    public function assets(): GameAssets
    {
        // The cover is the title art (public/pong/art/key-title) cut to 16:9 (P4).
        return new GameAssets('play', 'var(--color-btc)', 'var(--color-btc-deep)', 'Pong', new GameCover(self::SLUG));
    }
}
