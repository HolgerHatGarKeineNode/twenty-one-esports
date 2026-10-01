<?php

/*
| Blockfill's slide set on the stream: the week's board (f2), the fresh
| blocks (f3), the new #1 moment (f4) and the call to play (f5), in the
| regular rotation while a week runs, one slot a round, the moment first in
| line once; switched off, none of them (and not the f1 teaser either).
*/

use App\Enums\StackerRunStatus;
use App\Jobs\VerifyStackerRun;
use App\Models\StackerRun;
use App\Models\User;
use App\Support\Stacker\Verifier;
use App\Support\TwentyOne\Stream\BlockfillSlides;
use App\Support\TwentyOne\Stream\RotationPlanner;
use App\Support\TwentyOne\Stream\SceneRenderer;
use App\Support\TwentyOne\Stream\SceneSource;
use App\Support\TwentyOne\Stream\StreamStats;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Tests\Support\BlockfillOn;
use Tests\Support\FakeStackerVerifier;

beforeEach(function () {
    Cache::flush();
    $this->app->instance(Verifier::class, new FakeStackerVerifier);
    // A Wednesday: the week started on Monday 2026-10-05 00:00 Berlin (CEST) and ends Monday 2026-10-12 00:00 Berlin.
    $this->travelTo(CarbonImmutable::parse('2026-10-07 12:00:00'));
});

/** A run of `$user` with `$ticks` (60 a second) handed in `$minutesAgo` ago, through the real verdict job. */
function bfsRun(User $user, int $ticks, int $minutesAgo = 60): StackerRun
{
    $at = CarbonImmutable::now()->subMinutes($minutesAgo);
    $run = StackerRun::factory()->for($user)->create([
        'status' => StackerRunStatus::Verifying, 'issued_at' => $at, 'started_at' => $at, 'submitted_at' => $at,
        'ticks' => $ticks, 'state_hash' => '00000000', 'replay' => 'AAAA', 'created_at' => $at,
    ]);
    test()->travelTo($at);
    VerifyStackerRun::dispatchSync($run->id);
    test()->travelBack();
    test()->travelTo(CarbonImmutable::parse('2026-10-07 12:00:00'));

    return $run->refresh();
}

/** A run still waiting for the verifier: handed in, never replayed. */
function bfsPending(User $user, int $ticks, int $minutesAgo = 2): StackerRun
{
    $at = CarbonImmutable::now()->subMinutes($minutesAgo);

    return StackerRun::factory()->for($user)->create([
        'status' => StackerRunStatus::Pending, 'issued_at' => $at, 'started_at' => $at, 'submitted_at' => $at,
        'ticks' => $ticks, 'state_hash' => '00000000', 'replay' => 'AAAA', 'created_at' => $at,
    ]);
}

/**
 * This week's board with `$players` (name => ticks), each run an hour ago.
 *
 * @param  array<string, int>  $players
 * @return array<string, User>
 */
function bfsWeek(array $players): array
{
    BlockfillOn::play();
    $users = [];
    foreach ($players as $name => $ticks) {
        $users[$name] = User::factory()->create(['name' => $name]);
        bfsRun($users[$name], $ticks);
    }

    return $users;
}

/** A slide of the set as the stream renders it, read fresh. */
function bfsSvg(string $scene): string
{
    Cache::flush();

    return SceneRenderer::fromConfig()->svg([...app(SceneSource::class)->rotation($scene, null, [], 0, (int) (now()->getTimestampMs()), app(StreamStats::class)->all()), 'viewers' => null], RotationPlanner::VIEWS[$scene]);
}

/**
 * The scenes a planner shows over `$seconds`, one entry per slot: [start, scene, length], with
 * `$games` on show and Blockfill's state as `$state($t)` reports it.
 *
 * @param  list<array{id: int, blitz: bool}>  $games
 * @param  Closure(float): array{week: string, moment: string|null}  $state
 * @return list<array{0: float, 1: string|null, 2: float}>
 */
function bfsRotation(RotationPlanner $planner, float $seconds, array $games, Closure $state, float $from = 0): array
{
    $slots = [];
    $last = null;
    for ($t = $from; $t < $from + $seconds; $t += 1) {
        ['week' => $week, 'moment' => $moment] = $state($t);
        $slot = $planner->at($t, $games, [], 'off', [], $week, $moment);
        $key = $slot['kind'].$slot['scene'].$slot['until'];
        if ($key !== $last) {
            if ($slots !== []) {
                $slots[count($slots) - 1][2] = $t - $slots[count($slots) - 1][0];
            }
            $slots[] = [$t, $slot['scene'], 0.0];
            $last = $key;
        }
    }
    $slots[count($slots) - 1][2] = $from + $seconds - $slots[count($slots) - 1][0];

    return $slots;
}

test('while a week runs, its board, its fresh blocks and the call to play come in the regular rotation, one slot a round', function () {
    bfsWeek(['Ada' => 2900, 'Ben' => 3000]);
    $state = app(BlockfillSlides::class)->state();
    expect($state['week'])->toBe(BlockfillSlides::RUNNING);

    $slots = bfsRotation(RotationPlanner::fromConfig(60), 1200, [['id' => 7, 'blitz' => true]], fn (): array => $state);
    $scenes = array_column($slots, 1);
    $rounds = count(array_intersect($scenes, ['a1', 'b1', 'c1']));
    $set = array_values(array_filter($scenes, fn (?string $scene): bool => in_array($scene, [BlockfillSlides::BOARD, BlockfillSlides::FRESH, BlockfillSlides::PLAY], true)));

    // Every round with a match has exactly one of them, the three in turn.
    expect(array_slice($set, 0, 3))->toBe([BlockfillSlides::BOARD, BlockfillSlides::FRESH, BlockfillSlides::PLAY])
        ->and(count($set))->toBeGreaterThanOrEqual($rounds - 1)->toBeLessThanOrEqual($rounds);

    // An open week nobody is on yet: only the call to play.
    $empty = bfsRotation(RotationPlanner::fromConfig(60), 1200, [['id' => 7, 'blitz' => true]], fn (): array => ['week' => BlockfillSlides::EMPTY, 'moment' => null]);
    $calls = array_values(array_intersect(array_column($empty, 1), BlockfillSlides::SCENES));
    expect($calls)->not->toBe([])->each->toBe(BlockfillSlides::PLAY);
});

test('switched off, none of the Blockfill slides comes, the f1 teaser neither, and a slide on show ends', function () {
    $state = app(BlockfillSlides::class)->state();
    expect($state)->toBe(['week' => BlockfillSlides::OFF, 'moment' => null])
        ->and(app(BlockfillSlides::class)->data())->toBeNull();

    $scenes = array_column(bfsRotation(RotationPlanner::fromConfig(60), 1800, [], fn (): array => $state), 1);
    expect(array_intersect($scenes, [...BlockfillSlides::SCENES, 'f1']))->toBe([]);

    // On show when the switch goes off: gone at the next poll.
    BlockfillOn::play();
    $planner = RotationPlanner::fromConfig(60);
    $seen = null;
    for ($t = 0.0; $t < 1200 && $seen === null; $t += 1) {
        $slot = $planner->at($t, [['id' => 7, 'blitz' => true]], [], 'off', [], BlockfillSlides::RUNNING, null);
        $seen = in_array($slot['scene'], BlockfillSlides::SCENES, true) ? $t : null;
    }
    expect($seen)->not->toBeNull()
        ->and($planner->at($seen + 1, [['id' => 7, 'blitz' => true]], [], 'off', [], BlockfillSlides::OFF, null)['scene'])->not->toBeIn(BlockfillSlides::SCENES);
});

test('a run waiting for the replay is a block marked unconfirmed, without its time', function () {
    $users = bfsWeek(['Ada' => 2900]);
    bfsPending(User::factory()->create(['name' => 'Waits']), 2880);

    $fresh = app(BlockfillSlides::class)->data()['fresh'];
    expect($fresh[0]['name'])->toBe('Waits')
        ->and($fresh[0]['waiting'])->toBeTrue()
        ->and($fresh[0]['time'])->toBeNull()
        ->and($fresh[1]['name'])->toBe('Ada')
        ->and($fresh[1]['time'])->toBe('0:48.333');

    $svg = bfsSvg(BlockfillSlides::FRESH);
    // 2880 ticks would read 0:48.000; it must not reach the stream before the league replayed it.
    expect($svg)->toContain('unconfirmed')->toContain('Waits')->toContain('0:48.333')->not->toContain('0:48.000');
});

test('a new #1 of the last minutes is the next slide, once, and the fresh blocks name it', function () {
    $users = bfsWeek(['Ben' => 3000]);
    $run = bfsRun(User::factory()->create(['name' => 'Ada']), 2887, 3);

    $data = app(BlockfillSlides::class)->data();
    expect(app(BlockfillSlides::class)->state())->toBe(['week' => BlockfillSlides::RUNNING, 'moment' => 'run-'.$run->id])
        ->and($data['moment']['name'])->toBe('Ada')
        ->and($data['moment']['time'])->toBe('0:48.116')
        ->and($data['moment']['before'])->toBe(['name' => 'Ben', 'time' => '0:50.000'])
        ->and($data['moment']['by'])->toBe('1.884 s')
        // Both took first place when they came in: Ben's the week's first time, Ada's beat it, so Ben's was #1.
        ->and($data['fresh'][0]['badge'])->toBe('top')
        ->and($data['fresh'][1]['badge'])->toBe('was');

    // A personal best below the top: marked, but no moment.
    bfsRun($users['Ben'], 2950, 1);
    $data = app(BlockfillSlides::class)->data();
    expect($data['fresh'][0]['badge'])->toBe('best')->and($data['moment']['name'])->toBe('Ada');

    // In the planner: the moment comes at the next slot, before the round's own slots, and only once per key.
    $planner = RotationPlanner::fromConfig(60);
    $games = [['id' => 7, 'blitz' => true]];
    $first = $planner->at(0, $games, [], 'off', [], BlockfillSlides::RUNNING, null);
    $moment = $planner->at($first['until'], $games, [], 'off', [], BlockfillSlides::RUNNING, 'run-'.$run->id);
    expect($first['scene'])->toBe('a1')->and($moment['scene'])->toBe(BlockfillSlides::MOMENT);

    $later = array_column(bfsRotation($planner, 1200, $games, fn (): array => ['week' => BlockfillSlides::RUNNING, 'moment' => 'run-'.$run->id], $moment['until']), 1);
    expect(array_keys($later, BlockfillSlides::MOMENT))->toBe([]);

    // Ten minutes on, the moment is over.
    $this->travelTo(CarbonImmutable::parse('2026-10-07 12:11:00'));
    expect(app(BlockfillSlides::class)->state()['moment'])->toBeNull();
});

test('the share of Blockfill stays bounded: under a fifth of the airtime even with a new #1 every minute, matches always longer', function () {
    BlockfillOn::play();
    $share = function (array $games, Closure $state): array {
        $slots = bfsRotation(RotationPlanner::fromConfig(60), 4 * 3600, $games, $state);
        $time = fn (Closure $is): float => array_sum(array_map(fn (array $slot): float => $is($slot[1]) ? $slot[2] : 0, $slots));
        $blockfill = $time(fn (?string $scene): bool => $scene !== null && $scene[0] === 'f');

        return [$blockfill / (4 * 3600), $blockfill, $time(fn (?string $scene): bool => in_array($scene, ['a1', 'b1', 'c1'], true))];
    };
    $flood = fn (float $t): array => ['week' => BlockfillSlides::RUNNING, 'moment' => 'run-'.intdiv((int) $t, 60)];

    [$withGames, $blockfill, $matches] = $share([['id' => 7, 'blitz' => true], ['id' => 8, 'blitz' => false]], $flood);
    [$idle] = $share([], $flood);
    [$quiet] = $share([['id' => 7, 'blitz' => true]], fn (): array => ['week' => BlockfillSlides::RUNNING, 'moment' => null]);

    expect($withGames)->toBeGreaterThan(0.05)->toBeLessThan(0.2)
        ->and($matches)->toBeGreaterThan(2 * $blockfill)
        ->and($idle)->toBeLessThan(0.2)
        ->and($quiet)->toBeGreaterThan(0.05)->toBeLessThan(0.15);
});

test('the board lists the top ten with the gap to first, counts everyone and counts down to Monday 00:00 Berlin', function () {
    $players = [];
    foreach (range(1, 20) as $i) {
        $players['Player '.$i] = 2880 + $i * 12;
    }
    bfsWeek($players);

    $data = app(BlockfillSlides::class)->data(CarbonImmutable::parse('2026-10-07 12:00:00'));
    expect($data['players'])->toBe(20)
        ->and(count($data['board']))->toBe(BlockfillSlides::BOARD_PLACES)
        ->and($data['board'][0])->toMatchArray(['place' => 1, 'name' => 'Player 1', 'time' => '0:48.200', 'gap' => null])
        ->and($data['board'][1])->toMatchArray(['place' => 2, 'gap' => '+0.200'])
        ->and($data['board'][9]['gap'])->toBe('+1.800')
        // Monday 2026-10-12 00:00 CEST is Sunday 22:00 UTC: 4 days 10 hours from Wednesday 12:00 UTC.
        ->and($data['countdown'])->toBe('4d 10:00:00')
        ->and($data['closes'])->toBe('Monday 00:00 Berlin');

    $svg = bfsSvg(BlockfillSlides::BOARD);
    expect($svg)->toContain('Player 10')->not->toContain('Player 11<')->toContain('+1.800')->toContain('20 players');
});

test('no slide of the set says fee, hashtag or face, in English and with the German locale', function () {
    bfsWeek(['Ada' => 2900, 'Ben' => 3000]);
    bfsPending(User::factory()->create(['name' => 'Waits']), 2880);

    foreach (['en', 'de'] as $locale) {
        app()->setLocale($locale);
        foreach (BlockfillSlides::SCENES as $scene) {
            preg_match_all('/<text[^>]*>([^<]*)<\/text>/', bfsSvg($scene), $m);
            $copy = implode("\n", $m[1]);
            expect($copy)->not->toMatch('/#[A-Za-z]|\bfees?\b|\bface\b|Gesicht/i');
        }
        expect(bfsSvg(BlockfillSlides::PLAY))->toContain('esports.einundzwanzig.space/blockfill')->toContain('shape-rendering="crispEdges"');
    }
});

test('runs verified in the same second keep their order: the slower one is no new #1, and a name without printable letters keeps its place', function () {
    // All four verified at 11:00:00; SQLite keeps the second with milliseconds, which an `=` on the bound second never matches.
    bfsWeek(['Ada' => 2900, 'ナカモト' => 2950, 'Cy' => 3000, 'Dee' => 3300]);

    $data = app(BlockfillSlides::class)->data();
    expect(array_column($data['fresh'], 'badge'))->toBe([null, null, null, 'top'])
        ->and(array_column($data['board'], 'place'))->toBe([1, 2, 3, 4]);

    preg_match_all('/data-unit="place-name-(\d+)"[^>]*>([^<]*)</', bfsSvg(BlockfillSlides::BOARD), $m);
    expect(array_combine($m[1], $m[2]))->toBe(['1' => 'Ada', '2' => 'Player', '3' => 'Cy', '4' => 'Dee']);
});

test('every slide of the set shows it is Blockfill: the game\'s cover as its mark and the name in the copy', function () {
    bfsWeek(['Ben' => 3000]);
    bfsRun(User::factory()->create(['name' => 'Ada']), 2887, 3);

    foreach (BlockfillSlides::SCENES as $scene) {
        $svg = bfsSvg($scene);
        preg_match_all('/<text[^>]*>([^<]*)<\/text>/', $svg, $m);
        expect($svg)->toMatch('/<g data-unit="game-mark"[^>]*>\s*(<[^>]+>\s*)*<image [^>]*xlink:href="data:image\/jpeg;base64,/')
            ->and(implode("\n", $m[1]))->toMatch('/\bBlockfill\b/');
    }
});

test('the fresh blocks call only the current #1 "New #1": a #1 beaten since says "Was #1"', function () {
    bfsWeek(['Ben' => 3000]);
    bfsRun(User::factory()->create(['name' => 'Ada']), 2887, 3);

    expect(array_column(app(BlockfillSlides::class)->data()['fresh'], 'badge'))->toBe(['top', 'was']);

    preg_match_all('/data-unit="block-label-(\d+)"[^>]*>([^<]*)</', bfsSvg(BlockfillSlides::FRESH), $m);
    expect(array_combine($m[1], $m[2]))->toBe(['0' => 'New #1', '1' => 'Was #1']);
});

test('with two runs, the fresh blocks show no empty placeholder: the rest of the row is the call to beat the time, with the QR code', function () {
    bfsWeek(['Ben' => 3000]);
    bfsRun(User::factory()->create(['name' => 'Ada']), 2887, 3);

    $svg = bfsSvg(BlockfillSlides::FRESH);
    // The open seats were dashed blocks (stroke-dasharray="6 4"); the defs' chess pieces carry "stroke-dasharray:none".
    expect($svg)->not->toMatch('/stroke-dasharray="[^"n]/')
        ->not->toContain('data-unit="block-2"')
        ->toContain('data-unit="call"')
        ->toMatch('/data-unit="call-time"[^>]*>Beat 0:48\.116</')
        ->toContain('shape-rendering="crispEdges"');
});
