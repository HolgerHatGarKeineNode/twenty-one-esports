<?php

use App\Support\Hyper\HyperRng;

/*
| The dice of Hyperbitcoinization: xoshiro128++ seeded by splitmix32, word for word what
| tools/hbsim prints (`hbsim rng 1 6`, `hbsim rng 4294967295 3`).
*/

it('draws the simulator\'s numbers for the same seed, up to the largest 32-bit seed', function (): void {
    $draw = function (int $seed, int $count): array {
        $rng = HyperRng::seeded($seed);

        return array_map(fn (): int => $rng->next(), range(1, $count));
    };

    expect($draw(1, 6))->toBe([3187778163, 3899520863, 270678034, 2950105546, 3415064141, 3665258436])
        ->and($draw(4294967295, 3))->toBe([2846478547, 2130249462, 456585916]);
});

it('resumes from its saved state and keeps dice in range', function (): void {
    $rng = HyperRng::seeded(42);
    $rng->next();
    $copy = HyperRng::fromState($rng->state());
    $dice = array_map(fn (): int => $rng->die(), range(1, 600));

    expect(array_map(fn (): int => $copy->die(), range(1, 600)))->toBe($dice)
        ->and(min($dice))->toBe(1)
        ->and(max($dice))->toBe(6)
        ->and($rng->float())->toBeGreaterThanOrEqual(0.0)->toBeLessThan(1.0);
});

it('refuses seeds and states outside 32 bits', function (mixed $make): void {
    expect($make)->toThrow(InvalidArgumentException::class);
})->with([
    'negative seed' => fn () => fn () => HyperRng::seeded(-1),
    'seed above 2^32-1' => fn () => fn () => HyperRng::seeded(4294967296),
    'three words' => fn () => fn () => HyperRng::fromState([1, 2, 3]),
    'a word above 32 bits' => fn () => fn () => HyperRng::fromState([1, 2, 3, 4294967296]),
]);
