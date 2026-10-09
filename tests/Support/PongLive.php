<?php

namespace Tests\Support;

use App\Models\PongMatch;
use App\Models\User;
use App\Support\Pong\PongBot;
use App\Support\Pong\PongMatches;
use App\Support\Pong\PongPhysics;
use App\Support\Pong\PongRally;
use App\Support\Pong\PongRules;

/**
 * A live Proof of Pong match for a test (plan "Proof of Pong", P2): two players, both pages "there" (the match
 * started), and a whole game played through the referee the way two autoplaying pages play it: each rally on
 * PongRally with a bot per side, every contact reported by its defender.
 */
final class PongLive
{
    /**
     * A match between two new players, started (both synced once).
     *
     * @param  list<int>  $plainRallies
     * @return array{PongMatch, User, User}
     */
    public static function started(array $plainRallies = []): array
    {
        $left = User::factory()->create();
        $right = User::factory()->create();
        $matches = app(PongMatches::class);
        $rules = PongRules::fromConfig((array) config('esports.pong'));

        // A test that judges single contacts asks for rallies without a meme event (since P10 nine of every 21
        // rallies carry one, e.g. Pizza Day's two balls): draw matches until those rallies are plain.
        do {
            $match = $matches->create($left, $right);
            $plain = collect($plainRallies)->every(fn (int $rally): bool => $rules->eventOf($match->seed, $rally) === null);

            if (! $plain) {
                $match->delete();
            }
        } while (! $plain);
        $matches->sync($match, $left);
        $matches->sync($match, $right);

        return [$match->refresh(), $left, $right];
    }

    /**
     * Plays the match to its end with bots of `$levels` reporting every contact. Returns the number of reports.
     *
     * @param  array{int, int}  $levels
     */
    public static function play(PongMatch $match, array $levels, int $maxRallies = 500): int
    {
        $matches = app(PongMatches::class);
        $rules = PongRules::fromConfig((array) config('esports.pong'));
        $players = [$match->left, $match->right];
        $reports = 0;
        $snapshot = $matches->snapshot($match);

        while ($snapshot['status'] === 'active' && $snapshot['ref']['rally'] <= $maxRallies) {
            $number = $snapshot['ref']['rally'];
            $seed = PongRules::rallySeed($match->seed, $number);
            $rally = new PongRally($seed, $rules->eventOf($match->seed, $number), [PongBot::speed($levels[0]), PongBot::speed($levels[1])]);
            $bots = [new PongBot($levels[0], 0, $seed), new PongBot($levels[1], 1, $seed)];

            while (! $rally->over && $snapshot['status'] === 'active' && $snapshot['ref']['rally'] === $number) {
                $before = array_map(fn (array $ball): ?int => PongPhysics::approaches($ball, $ball[2] < 0 ? 0 : 1) ? ($ball[2] < 0 ? 0 : 1) : null, $rally->balls);
                $logged = count($rally->events);
                $rally->step([$bots[0]->target($rally), $bots[1]->target($rally)]);
                $hits = [];

                foreach (array_slice($rally->events, $logged) as $event) {
                    if ($event[0] === 'hit') {
                        $hits[$event[3]] = true;
                    }
                }

                foreach ($rally->balls as $index => $ball) {
                    $side = $before[$index];

                    if ($side === null || ! (isset($hits[$index]) || PongPhysics::crossed($ball, $side)) || $snapshot['status'] !== 'active' || $snapshot['ref']['rally'] !== $number) {
                        continue;
                    }

                    $snapshot = $matches->report($match, $players[$side], [
                        'rally' => $number, 'ball' => $index, 'tick' => $rally->tick,
                        'kind' => isset($hits[$index]) ? 'hit' : 'goal', 'y' => isset($hits[$index]) ? $rally->paddles[$side] : null,
                    ])['snapshot'];
                    $reports++;
                }
            }

            // A rally past the tick cap: the referee's clock ends it.
            if ($snapshot['status'] === 'active' && $snapshot['ref']['rally'] === $number) {
                $snapshot = $matches->sync($match, $players[0]);
            }
        }

        return $reports;
    }
}
