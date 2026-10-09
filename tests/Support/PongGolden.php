<?php

namespace Tests\Support;

use App\Support\Hyper\HyperRng;
use App\Support\Pong\PongBot;
use App\Support\Pong\PongGame;
use App\Support\Pong\PongRally;
use App\Support\Pong\PongRules;

/**
 * Proof of Pong's golden runs (plan "Proof of Pong", P1): what the server's physics, rules and bot compute for fixed
 * inputs, committed as tests/Fixtures/pong/golden.json. tests/Feature/Pong/PongPhysicsTest.php checks that the PHP
 * side still computes the fixture, and tests/js/pongPhysics.test.mjs that the browser's copy computes it too, from
 * the inputs in the same file. Rewrite it with `PONG_GOLDEN_WRITE=1` after a deliberate change of the rules.
 *
 * - rng: the first draws of a few seeds;
 * - rallies: 50 rallies between two bots, every event and level pair, each from its game seed and number;
 * - events: the event of every 21st rally for a few seeds;
 * - games: whole games between two bot levels (PongGame::bots()).
 */
final class PongGolden
{
    public const string PATH = 'tests/Fixtures/pong/golden.json';

    private const array RALLY_EVENTS = [null, PongRules::HALVING, PongRules::BRRR, PongRules::PIZZA, PongRules::DIFFICULTY];

    /**
     * @return array<string, mixed>
     */
    public static function compute(): array
    {
        $rules = new PongRules;
        $rng = [];

        foreach ([0, 1, 12345, 4294967295] as $seed) {
            $generator = HyperRng::seeded($seed);
            $rng[] = ['seed' => $seed, 'next' => array_map(fn (): int => $generator->next(), range(1, 6)), 'below9' => array_map(fn (): int => $generator->below(9), range(1, 6))];
        }

        $rallies = [];

        foreach (range(0, 49) as $i) {
            $seed = ($i * 2654435761 + 12345) & 0xFFFFFFFF;
            $number = 1 + $i * 7;
            $event = self::RALLY_EVENTS[$i % 5];
            $levels = [1 + $i % 4, 1 + intdiv($i, 4) % 4];
            $rallySeed = PongRules::rallySeed($seed, $number);
            $rally = new PongRally($rallySeed, $event, [PongBot::speed($levels[0]), PongBot::speed($levels[1])]);
            $bots = [new PongBot($levels[0], 0, $rallySeed), new PongBot($levels[1], 1, $rallySeed)];

            while (! $rally->over) {
                $rally->step([$bots[0]->target($rally), $bots[1]->target($rally)]);
            }

            $rallies[] = ['seed' => $seed, 'rally' => $number, 'event' => $event, 'levels' => $levels, 'rally_seed' => $rallySeed, 'result' => $rally->toArray()];
        }

        $events = [];

        foreach ([7, 99, 4294967295] as $seed) {
            $events[] = ['seed' => $seed, 'events' => array_map(fn (int $k): ?string => $rules->eventOf($seed, 21 * $k), range(1, 12))];
        }

        $games = [];

        foreach ([[1, [1, 2]], [21, [3, 4]], [2100000, [4, 4]], [4294967295, [2, 1]]] as [$seed, $levels]) {
            $games[] = ['seed' => $seed, 'levels' => $levels, 'result' => PongGame::bots($seed, $levels, $rules)];
        }

        return ['rng' => $rng, 'rallies' => $rallies, 'events' => $events, 'games' => $games];
    }

    public static function encode(mixed $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
    }
}
