<?php

/*
|--------------------------------------------------------------------------
| Proof of Pong on the stream (plan "Proof of Pong", P4)
|--------------------------------------------------------------------------
|
| No live gameplay on the stream (user, 2026-10-09: the encoder takes no live frame rate): a won match of the last
| day gets the result slide (p1, PongScene) once a round for 20 s, the recent results taking turns; none without a
| recent result or with the game off. The slide shows the winner's victory pose, both players with their figure,
| the score, the Elo change, a still of the arena and the way to play, and renders at 1280 x 720 with every text
| inside the frame.
|
*/

use App\Enums\PongEndReason;
use App\Enums\PongMatchStatus;
use App\Models\PongMatch;
use App\Models\User;
use App\Support\TwentyOne\Stream\BoardScene;
use App\Support\TwentyOne\Stream\PongScene;
use App\Support\TwentyOne\Stream\RotationKit;
use App\Support\TwentyOne\Stream\RotationPlanner;
use App\Support\TwentyOne\Stream\SceneRenderer;
use App\Support\TwentyOne\Stream\SceneSource;
use Illuminate\Support\Facades\Queue;
use Tests\Support\PongOn;

beforeEach(function () {
    Queue::fake();
});

/**
 * The rotation over `$seconds` as "start kind scene[:id]" lines, one per slot.
 *
 * @param  Closure(float): list<array{id: int}>  $pong
 * @return list<string>
 */
function pongRotation(RotationPlanner $planner, float $seconds, Closure $pong): array
{
    $log = [];
    $last = null;

    for ($t = 0.0; $t < $seconds; $t += 0.5) {
        $slot = $planner->at($t, [], [], BoardScene::OFF, [], 'off', null, [], $pong($t));
        $key = $slot['kind'].' '.$slot['scene'].' '.$slot['gameId'];

        if ($key !== $last) {
            $log[] = sprintf('%g %s %s', $t, $slot['kind'], $slot['scene'] ?? '-').($slot['kind'] === RotationPlanner::PONG ? ':'.$slot['gameId'] : '');
            $last = $key;
        }
    }

    return $log;
}

/** As the Hyperbitcoinization tests' planner; a Proof of Pong result 20 s. */
function pongPlanner(): RotationPlanner
{
    return new RotationPlanner(45, 60, 20, 12, 3, 3, 30, 15, false, false, 90, 120, 60, 180, 20);
}

/** A match won by the left player `$minutesAgo` ago, rated, with both figures. */
function pongStreamResult(int $minutesAgo = 5, array $score = [21, 17], array $figures = ['saylor', 'turm'], string $left = 'Anna Pleb', string $right = 'Bert Hodler'): PongMatch
{
    $leftUser = User::factory()->create(['name' => $left]);

    return PongMatch::factory()->finished(0, $score)->create([
        'left_id' => $leftUser->id, 'right_id' => User::factory()->create(['name' => $right])->id, 'winner_id' => $leftUser->id,
        'started_at' => now()->subMinutes($minutesAgo + 6), 'ended_at' => now()->subMinutes($minutesAgo),
        'left_rating_before' => 1000, 'left_rating_after' => 1016, 'right_rating_before' => 1000, 'right_rating_after' => 984,
        'state' => ['ref' => null, 'speed' => 1, 'seen' => [null, null], 'rematch' => [false, false], 'next' => null, 'version' => 9, 'figures' => $figures],
    ]);
}

/** @return array{0: array<string, mixed>, 1: string} the slide's data and SVG */
function pongSlide(?int $matchId): array
{
    $data = app(SceneSource::class)->rotation(PongScene::SCENE, $matchId, [], 0, (int) now()->getTimestampMs(), []);

    return [$data, SceneRenderer::fromConfig()->svg([...$data, 'viewers' => 7], RotationPlanner::VIEWS[PongScene::SCENE])];
}

test('a recent result stands 20 s once a round, results take turns, and none shows without one', function () {
    $one = fn (float $t): array => [['id' => 7]];
    $two = fn (float $t): array => [['id' => 7], ['id' => 9]];

    expect(array_slice(pongRotation(pongPlanner(), 200, $one), 0, 3))->toBe(['0 loop -', '30 pong p1:7', '50 teaser d1']);

    $slots = array_values(array_filter(pongRotation(pongPlanner(), 1200, $two), fn (string $line): bool => str_contains($line, 'pong')));
    expect(array_map(fn (string $line): string => substr($line, strpos($line, 'p1:')), array_slice($slots, 0, 3)))->toBe(['p1:7', 'p1:9', 'p1:7'])
        ->and(implode("\n", pongRotation(pongPlanner(), 300, fn (float $t): array => [])))->not->toContain('pong');
});

test('the rotation gets the matches won within the last day, newest first, and none while the game is off', function () {
    $old = pongStreamResult(minutesAgo: 25 * 60);
    $earlier = pongStreamResult(minutesAgo: 90);
    $newest = pongStreamResult(minutesAgo: 2);
    PongMatch::factory()->active()->create();
    PongMatch::factory()->aborted()->create();
    // A tournament no-show: won, never played.
    pongStreamResult(minutesAgo: 1)->forceFill(['started_at' => null, 'end_reason' => PongEndReason::Forfeit])->save();

    expect(app(PongScene::class)->entries())->toBe([]);

    PongOn::play();

    expect(app(PongScene::class)->entries())->toBe([['id' => $newest->id], ['id' => $earlier->id]])
        ->and($old->status)->toBe(PongMatchStatus::Finished);
});

test('the result slide shows the winner\'s pose, both players and figures, the score, the Elo change, the arena still and the way to play, at 1280 x 720', function () {
    PongOn::play();
    $match = pongStreamResult(5, [23, 21], ['saylor', 'turm'], 'Satoshis Very Long Display Name For The Stream', 'Bert Hodler');

    [$data, $svg] = pongSlide($match->id);

    expect($data['pong']['sides'][0]['figure'])->toBe('Michael Saylor')
        ->and($data['pong']['sides'][0]['picture'])->toStartWith('data:image/png;base64,')
        ->and($data['pong']['sides'][1]['picture'])->toStartWith('data:image/jpeg;base64,')
        ->and($data['art']['still'])->toStartWith('data:image/jpeg;base64,')
        ->and($svg)->toContain('>23:21<', '>as Michael Saylor<', '>as Markus Turm<', '>Bert Hodler<', 'Elo 1016 (+16)', 'Elo 984 (-16)', '>WINNER<', '>PLAY NOW<', '>esports.einundzwanzig.space/proof-of-pong<', 'data-unit="still-art"', 'data-unit="pose-art"')
        ->not->toContain('Satoshis Very Long Display Name For The Stream', '→', '−');

    // Every text inside the frame, clear of the LIVE badge (x >= 1040, y < 112), and the middle column clear of the right one.
    preg_match_all('#<text[^>]*\sx="([\d.]+)"[^>]*\sy="([\d.]+)"[^>]*font-family="(Unbounded|JetBrains Mono)"[^>]*font-size="([\d.]+)"[^>]*>([^<]+)</text>#', $svg, $texts, PREG_SET_ORDER);
    expect(count($texts))->toBeGreaterThan(10);

    foreach ($texts as [$tag, $x, $y, $font, $size, $text]) {
        $width = RotationKit::width(html_entity_decode($text), $font === 'Unbounded' ? RotationKit::DISPLAY : RotationKit::MONO, (float) $size);
        $left = match (true) {
            str_contains($tag, 'text-anchor="middle"') => (float) $x - $width / 2,
            str_contains($tag, 'text-anchor="end"') => (float) $x - $width,
            default => (float) $x,
        };

        expect($left)->toBeGreaterThanOrEqual(0, $text)
            ->and($left + $width)->toBeLessThanOrEqual(1280, $text)
            ->and((float) $y)->toBeLessThanOrEqual(720, $text);

        if ((float) $y < 112) {
            expect($left + $width)->toBeLessThan(1040, $text);
        }

        if ($left >= 400 && $left < 870) {
            expect($left + $width)->toBeLessThanOrEqual(870, $text);
        }
    }

    if (is_executable((string) (exec('command -v '.escapeshellarg((string) config('twentyone.stream.scene.rsvg_convert'))) ?: ''))) {
        $png = SceneRenderer::fromConfig()->png([...$data, 'viewers' => 7], RotationPlanner::VIEWS[PongScene::SCENE]);
        $size = getimagesizefromstring($png);

        expect([$size[0], $size[1]])->toBe([1280, 720]);

        if (is_string($dir = getenv('PONG_SHOTS')) && $dir !== '') {
            file_put_contents($dir.'/pong-stream-1280x720.png', $png);
        }
    }
});

test('without its match, or with the game off, the slide says no result is on and still invites to play', function () {
    PongOn::play();
    $match = pongStreamResult();

    expect(pongSlide(null)[1])->toContain('No result yet today.', '>PLAY NOW<');

    config(['esports.pong.enabled' => false]);

    expect(pongSlide($match->id)[0]['pong'])->toBeNull();
});
