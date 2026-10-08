<?php

namespace App\Games;

use App\Games\Contracts\Game;

/**
 * Hyperbitcoinization, the league's own strategy game (plan "Hyperbitcoinization"): Risk with currency
 * spaces for 2 to 6 seats on the server's rules core (App\Support\Hyper). Registered only while
 * `esports.hyper.enabled` is on (AppServiceProvider), so the registry knows it from P2 on; the league's
 * surfaces (lists, navigation, sitemap, stream, cover) take it up in P6; the lobby is on its own page (P3).
 *
 * A match is no two-sided result: it ends with a place per seat. Its kind is its own (GameKind::Strategy),
 * so no switch that serves chess, a series, a board game or a score game takes it for one of those.
 */
final class Hyperbitcoinization implements Game
{
    public const SLUG = 'hyperbitcoinization';

    public function slug(): string
    {
        return self::SLUG;
    }

    public function name(): string
    {
        return 'Hyperbitcoinization';
    }

    public function kind(): GameKind
    {
        return GameKind::Strategy;
    }

    public function modes(): array
    {
        return [
            // Live with a 90-second turn (P2), correspondence with a day per turn (P3).
            'live' => new GameMode('live', 'Live', 1, [], [], 'player', false),
            'correspondence' => new GameMode('correspondence', 'Correspondence', 1, [], [], 'player', false),
        ];
    }

    public function mode(string $slug): ?GameMode
    {
        return $this->modes()[$slug] ?? null;
    }

    public function resultSchema(GameMode $mode): array
    {
        return ['places' => 'list of seat places, 1 = winner'];
    }

    public function validateResult(GameMode $mode, array $result): array
    {
        $places = $result['places'] ?? null;

        if (! is_array($places) || count($places) < 2 || count($places) > 6) {
            return ['places'];
        }

        $sorted = $places;
        sort($sorted);

        return $sorted === range(1, count($places)) ? [] : ['places'];
    }

    public function assets(): GameAssets
    {
        return new GameAssets('flag', 'var(--color-btc)', 'var(--color-btc-deep)', 'Hyper');
    }
}
