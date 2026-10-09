<?php

/*
|--------------------------------------------------------------------------
| Proof of Pong's physics on both sides (plan "Proof of Pong", P1)
|--------------------------------------------------------------------------
|
| The server's physics, rules and bot compute the committed golden runs (tests/Fixtures/pong/golden.json: 50 rallies,
| every event (the five of P7 included), every level pair, four whole games), and the browser's copy (resources/js/pong/*.js) computes the
| same runs from the same inputs under Node: every serve, hit and goal in the same tick. Rewrite the fixture after a
| deliberate change with `PONG_GOLDEN_WRITE=1 vendor/bin/pest tests/Feature/Pong/PongPhysicsTest.php`.
|
*/

use App\Support\Pong\PongRules;
use Illuminate\Support\Facades\Process;
use Tests\Support\PongGolden;

test('the server computes the golden rallies, events and games of the fixture', function () {
    $computed = PongGolden::encode(PongGolden::compute());

    if (getenv('PONG_GOLDEN_WRITE') === '1') {
        file_put_contents(base_path(PongGolden::PATH), $computed);
    }

    $fixture = json_decode((string) file_get_contents(base_path(PongGolden::PATH)), true, flags: JSON_THROW_ON_ERROR);

    expect($fixture['rallies'])->toHaveCount(50)
        ->and(collect($fixture['rallies'])->pluck('event')->unique()->values()->all())->toEqualCanonicalizing([null, ...PongRules::EVENTS])
        // Every rally ends with a goal, not at the tick cap: the fixture shows hits and goals, not a void.
        ->and(collect($fixture['rallies'])->flatMap(fn (array $rally): array => array_column($rally['result']['events'], 0))->countBy()->all())
        ->toHaveKeys(['serve', 'hit', 'goal'])->not->toHaveKey('void')
        ->and($computed)->toBe(PongGolden::encode($fixture));
});

test('the browser\'s physics plays the same 50 golden rallies, events and games tick for tick (Node)', function () {
    $run = Process::path(base_path())->timeout(60)->run(['node', '--test', 'tests/js/pongPhysics.test.mjs']);

    expect($run->successful())->toBeTrue($run->output().$run->errorOutput())
        ->and($run->output())->toContain('ℹ pass 6')->toContain('ℹ fail 0')->toContain('ℹ skipped 0');
});
