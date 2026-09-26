<?php

use App\Games\GameRegistry;

test('the registry holds chess with its modes and Rocket League with three lineup modes', function () {
    $registry = app(GameRegistry::class);

    $rocketLeague = $registry->get('rocket-league');
    $chess = $registry->get('chess');

    expect(array_keys($registry->all()))->toBe(['chess', 'rocket-league'])
        ->and(array_keys($rocketLeague->modes()))->toBe(['1v1', '2v2', '3v3'])
        ->and(array_map(fn ($mode) => [$mode->teamSize, $mode->bestOf, $mode->rates], $rocketLeague->modes()))->toBe([
            '1v1' => [1, [3, 5], 'player'],
            '2v2' => [2, [3, 5], 'lineup'],
            '3v3' => [3, [3, 5], 'lineup'],
        ])
        ->and(array_map(fn ($mode) => [$mode->timeControl, $mode->boards, $mode->rates, $mode->lineupMinimum()], $chess->modes()))->toBe([
            'blitz' => ['300+3', [2, 3], 'player', 2],
            'correspondence' => ['1/86400', [2, 3], 'player', 2],
        ]);
});
