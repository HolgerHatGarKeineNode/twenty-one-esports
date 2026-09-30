<?php

use App\Games\GameRegistry;

test('the registry holds chess with its modes and Rocket League with three lineup modes', function () {
    $registry = app(GameRegistry::class);

    $rocketLeague = $registry->get('rocket-league');
    $chess = $registry->get('chess');

    expect(array_keys($registry->all()))->toBe(['chess', 'rocket-league', 'ea-sports-fc-27', 'ea-sports-fc-26', 'age-of-empires-2'])
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

test('the registry holds EA Sports FC 27 and 26 as series games with a 1v1 player ladder and a 2v2 lineup, best of 1 or 3', function (string $slug, string $name) {
    $registry = app(GameRegistry::class);
    $game = $registry->get($slug);

    expect($game->name())->toBe($name)
        ->and($registry->isSeries($slug))->toBeTrue()
        ->and(array_map(fn ($mode) => [$mode->teamSize, $mode->bestOf, $mode->rates, $mode->allowsDraws], $game->modes()))->toBe([
            '1v1' => [1, [1, 3], 'player', false],
            '2v2' => [2, [1, 3], 'lineup', false],
        ])
        ->and($registry->cover($slug)?->name)->toBe($slug)
        // A best of 1 is one game; the series validator is the one Rocket League uses.
        ->and($game->validateResult($game->mode('1v1'), ['bo' => 1, 'games' => [['winner' => 'challenged', 'challenger' => 1, 'challenged' => 2]]]))->toBe([])
        ->and($game->validateResult($game->mode('1v1'), ['bo' => 5, 'games' => [['winner' => 'challenger']]]))->toBe(['bo_not_allowed'])
        ->and($game->validateResult($game->mode('1v1'), ['bo' => 3, 'games' => [['winner' => 'challenger', 'challenger' => 2, 'challenged' => 1, 'flags' => ['ot']]]]))->toContain('game_1_flag');
})->with([
    'FC 27' => ['ea-sports-fc-27', 'EA Sports FC 27'],
    'FC 26' => ['ea-sports-fc-26', 'EA Sports FC 26'],
]);

test('the registry holds Age of Empires II as a series game without goals: 1v1 player ladder, 2v2 and 3v3 lineups, best of 1 or 3', function () {
    $registry = app(GameRegistry::class);
    $game = $registry->get('age-of-empires-2');
    $winners = fn (string ...$sides): array => array_map(fn (string $side): array => ['winner' => $side, 'challenger' => null, 'challenged' => null], $sides);

    expect($game->name())->toBe('Age of Empires II: Definitive Edition')
        ->and($registry->isSeries('age-of-empires-2'))->toBeTrue()
        ->and($registry->hasGoals('age-of-empires-2'))->toBeFalse()
        ->and($registry->hasGoals('rocket-league'))->toBeTrue()
        ->and($game->assets()->shortLabel)->toBe('AoE2')
        ->and(array_map(fn ($mode) => [$mode->teamSize, $mode->bestOf, $mode->rates, $mode->allowsDraws], $game->modes()))->toBe([
            '1v1' => [1, [1, 3], 'player', false],
            '2v2' => [2, [1, 3], 'lineup', false],
            '3v3' => [3, [1, 3], 'lineup', false],
        ])
        // A game has a winner and no goals: winners only is a valid series, a score on it is not.
        ->and($game->validateResult($game->mode('3v3'), ['bo' => 3, 'games' => $winners('challenger', 'challenged', 'challenger')]))->toBe([])
        ->and($game->validateResult($game->mode('1v1'), ['bo' => 1, 'games' => [['winner' => 'challenged', 'challenger' => 1, 'challenged' => 2]]]))->toBe(['game_1_goals'])
        ->and($game->validateResult($game->mode('1v1'), ['bo' => 5, 'games' => $winners('challenger')]))->toBe(['bo_not_allowed']);
});

test('every registered game has cover files on disk, and an unknown game has none', function () {
    $registry = app(GameRegistry::class);

    foreach (array_keys($registry->all()) as $slug) {
        $cover = $registry->cover($slug);

        expect($cover)->not->toBeNull();

        foreach ($cover->widths as $width) {
            foreach (['webp', 'jpg'] as $format) {
                $file = public_path($cover->path($width, $format));

                expect(is_file($file))->toBeTrue("missing {$file}")
                    ->and(getimagesize($file)[0])->toBe($width)
                    ->and(getimagesize($file)[1])->toBe((int) round($width * 9 / 16))
                    ->and(filesize($file))->toBeLessThan(80_000);
            }
        }
    }

    // The share cards draw the largest JPEG (GD reads it everywhere).
    expect($registry->coverPath('ea-sports-fc-27'))->toBe(public_path('images/games/ea-sports-fc-27-1280.jpg'))
        ->and($registry->coverPath('ea-sports-fc-26'))->toBe(public_path('images/games/ea-sports-fc-26-480.jpg'))
        ->and($registry->coverPath('rocket-league'))->toBe(public_path('images/games/rocket-league-382.jpg'))
        ->and($registry->coverPath('age-of-empires-2'))->toBe(public_path('images/games/age-of-empires-2-800.jpg'))
        ->and($registry->cover('tetris'))->toBeNull()
        ->and($registry->coverPath('tetris'))->toBeNull();
});
