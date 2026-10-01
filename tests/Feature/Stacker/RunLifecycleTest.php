<?php

/*
| Blockfill runs over HTTP (plan "Blockfill", P2): issue, start, submit, and
| what the verifier's verdict does to a run. The verifier is a fake here
| (Tests\Support\FakeStackerVerifier); the real Node verifier has its own
| file (VerifierCliTest). Few tests on purpose: each one boots the app and
| migrates, so related checks share one.
*/

use App\Enums\StackerRunStatus;
use App\Jobs\VerifyStackerRun;
use App\Models\StackerRun;
use App\Models\User;
use App\Support\Stacker\StackerRuns;
use App\Support\Stacker\StackerVerdict;
use App\Support\Stacker\Verifier;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Support\BlockfillOn;
use Tests\Support\FakeStackerVerifier;

beforeEach(function () {
    $this->freezeTime();
    $this->verifier = new FakeStackerVerifier;
    $this->app->instance(Verifier::class, $this->verifier);
});

/**
 * Issues a run for `$user` (logged in from here on), starts it unless told not to, and returns its JSON.
 *
 * @return array{token: string, seed: string, engine: string}
 */
function issueRun(User $user, bool $start = true): array
{
    $issued = test()->actingAs($user)->postJson(route('stacker.runs.issue'))->assertCreated()->json();

    if ($start) {
        test()->postJson(route('stacker.runs.start', $issued['token']))->assertNoContent();
    }

    return $issued;
}

/**
 * The forty-lines reference run submitted under `$token`, after its played time plus `$extraMs`.
 *
 * @param  array<string, mixed>  $overrides
 */
function submitForty(string $token, int $extraMs = 500, array $overrides = []): TestResponse
{
    $forty = BlockfillOn::fixture('forty-lines');
    test()->travel(intdiv($forty['expected']['ticks'] * 1000, 60) + $extraMs)->milliseconds();

    return test()->postJson(route('stacker.runs.submit', $token), $overrides + [
        'replay' => $forty['replay'],
        'ticks' => $forty['expected']['ticks'],
        'hash' => $forty['expected']['stateHash'],
    ]);
}

function runOf(array $issued): StackerRun
{
    return StackerRun::query()->where('seed', $issued['seed'])->sole();
}

test('switched off, Blockfill has no route', function () {
    expect(Route::has('stacker.runs.issue'))->toBeFalse()
        ->and(Route::has('stacker.runs.start'))->toBeFalse()
        ->and(Route::has('stacker.runs.submit'))->toBeFalse();

    $this->actingAs(User::factory()->create())->postJson('/stacker/runs')->assertStatus(405);
    expect(StackerRun::query()->count())->toBe(0);
});

test('a player gets a one-time token and a fresh seed, only the token\'s hash is stored, and issuing is throttled', function () {
    BlockfillOn::play();
    $user = User::factory()->create();

    $this->postJson(route('stacker.runs.issue'))->assertUnauthorized();
    $issued = issueRun($user, start: false);

    $run = runOf($issued);
    expect($issued['token'])->toMatch('/^[A-Za-z0-9]{40}$/')
        ->and($issued['seed'])->toMatch('/^[0-9a-f]{32}$/')
        ->and($issued['engine'])->toBe('bf1')
        ->and($issued['limits'])->toBe(['ticks' => 36000, 'inputs' => 20000, 'bytes' => 65536, 'inputs_per_tick' => 1, 'input_slack' => 64])
        ->and($run->token_hash)->toBe(hash('sha256', $issued['token']))
        ->and(json_encode($run->getAttributes()))->not->toContain($issued['token'])
        ->and($run->status)->toBe(StackerRunStatus::Issued);

    // one issue per 2 s
    $this->postJson(route('stacker.runs.issue'))->assertTooManyRequests();
    $this->travel(2)->seconds();
    issueRun($user, start: false);
});

test('one active run per player, a token expires 10 s unstarted, and a run starts once', function () {
    BlockfillOn::play();
    $user = User::factory()->create();

    $first = issueRun($user, start: false);
    $this->travel(3)->seconds();
    $second = issueRun($user, start: false);
    expect(runOf($first))->status->toBe(StackerRunStatus::Abandoned)->reason->toBe('replaced');
    $this->postJson(route('stacker.runs.start', $first['token']))->assertStatus(410)->assertJson(['reason' => 'replaced']);

    $this->travel(11)->seconds();
    $this->postJson(route('stacker.runs.start', $second['token']))->assertStatus(410)->assertJson(['status' => 'abandoned', 'reason' => 'expired']);
    expect(runOf($second)->status)->toBe(StackerRunStatus::Abandoned);

    $third = issueRun($user, start: false);
    $this->travel(9)->seconds();
    $this->postJson(route('stacker.runs.start', $third['token']))->assertNoContent();
    $this->postJson(route('stacker.runs.start', $third['token']))->assertConflict();

    // an unstarted token expires for a submission as well: the run has to be started
    $this->travel(3)->seconds();
    $fourth = issueRun($user, start: false);
    submitForty($fourth['token'])->assertStatus(410)->assertJson(['reason' => 'expired']);
    expect($this->verifier->asked)->toBe([]);
});

test('a token is single-use and only its own player\'s: verified once on the verifier\'s queue, outside any transaction', function () {
    BlockfillOn::play();
    $user = User::factory()->create();
    $issued = issueRun($user, start: true);
    $outsideLevel = DB::transactionLevel();

    $this->actingAs(User::factory()->create())->postJson(route('stacker.runs.submit', $issued['token']))->assertNotFound();

    $this->actingAs($user);
    submitForty($issued['token'])->assertAccepted()->assertJson(['status' => 'verifying']);
    submitForty($issued['token'])->assertConflict()->assertJson(['reason' => 'used']);

    $run = runOf($issued);
    expect($run->status)->toBe(StackerRunStatus::Verified)
        ->and($run->ticks)->toBe(958)
        ->and($run->state_hash)->toBe('6102773e')
        ->and($run->settings)->toBe(['das' => 8, 'arr' => 1, 'sdf' => 20])
        ->and($this->verifier->asked)->toBe([$run->id])
        ->and($this->verifier->transactionLevels)->toBe([$outsideLevel])
        ->and((new VerifyStackerRun($run->id))->queue)->toBe('stacker-verify')
        ->and((new VerifyStackerRun($run->id))->tries)->toBe(1);
});

test('the wall clock from start to submission covers the played time and at most 20 s more', function () {
    BlockfillOn::play();

    $cases = [
        'faster than real time' => [-1, true, 'rejected', 'clock'],
        'exactly the played time' => [0, true, 'verifying', null],
        '20 s later' => [20000, true, 'verifying', null],
        'more than 20 s later' => [20001, true, 'rejected', 'clock'],
    ];
    foreach ($cases as $case => [$extraMs, $start, $status, $reason]) {
        $issued = issueRun(User::factory()->create(), $start);
        $asked = count($this->verifier->asked);

        submitForty($issued['token'], $extraMs)->assertAccepted()->assertJson(['status' => $status, 'reason' => $reason]);
        expect(count($this->verifier->asked) - $asked)->toBe($status === 'verifying' ? 1 : 0, $case)
            ->and(runOf($issued)->replay === null)->toBe($status === 'rejected', "{$case}: a rejected run keeps no replay");
    }
});

test('only a time that beats the player\'s verified best is verified, the rest is kept as practice', function () {
    BlockfillOn::play();
    $user = User::factory()->create();
    StackerRun::factory()->for($user)->verified(900)->create();

    $issued = issueRun($user);
    submitForty($issued['token'])->assertAccepted()->assertJson(['status' => 'practice']);

    expect($this->verifier->asked)->toBe([])
        ->and(runOf($issued)->replay)->toBeNull();
});

test('an oversized or malformed submission is rejected without the verifier', function () {
    BlockfillOn::play();

    $cases = [
        'body over 64 KB' => [['replay' => str_repeat('A', 65536)], 'oversize'],
        'replay not base64url' => [['replay' => 'not base64!'], 'malformed'],
        'ticks as a string' => [['ticks' => '958'], 'malformed'],
        'ticks over the limit' => [['ticks' => 36001], 'malformed'],
        'hash not 8 hex digits' => [['hash' => 'XYZ'], 'malformed'],
    ];
    foreach ($cases as $case => [$overrides, $reason]) {
        $issued = issueRun(User::factory()->create());
        submitForty($issued['token'], 500, $overrides)->assertAccepted()->assertJson(['status' => 'rejected', 'reason' => $reason]);
        expect(runOf($issued))->status->toBe(StackerRunStatus::Rejected, $case)->replay->toBeNull();
    }

    expect($this->verifier->asked)->toBe([]);
});

test('a rejection keeps the verifier\'s reason; a verifier that is unavailable or fails leaves the run pending', function () {
    BlockfillOn::play();

    $cases = [
        'rejected' => [fn (FakeStackerVerifier $verifier) => $verifier->verdict = StackerVerdict::rejected('mismatch'), StackerRunStatus::Rejected, 'mismatch'],
        'unavailable' => [fn (FakeStackerVerifier $verifier) => $verifier->verdict = StackerVerdict::unavailable('verifier-unavailable'), StackerRunStatus::Pending, 'verifier-unavailable'],
        'throws' => [fn (FakeStackerVerifier $verifier) => $verifier->throws = true, StackerRunStatus::Pending, 'verifier-failed'],
    ];
    foreach ($cases as $case => [$breakVerifier, $status, $reason]) {
        $breakVerifier($this->verifier);
        $issued = issueRun(User::factory()->create());

        submitForty($issued['token'])->assertAccepted();

        expect(runOf($issued))
            ->status->toBe($status, $case)
            ->reason->toBe($reason)
            ->verified_at->toBeNull()
            // a rejected run keeps no replay; a pending one keeps it for the next try
            ->and(runOf($issued)->replay === null)->toBe($status === StackerRunStatus::Rejected, $case);
    }
});

test('a practice run keeps no replay; a verified run keeps the replay the verifier answered with, never the submitted bytes', function () {
    BlockfillOn::play();
    $user = User::factory()->create();
    StackerRun::factory()->for($user)->verified(900)->create();

    $practice = issueRun($user);
    submitForty($practice['token'])->assertAccepted()->assertJson(['status' => 'practice']);
    expect(runOf($practice)->replay)->toBeNull();

    $this->verifier->verdict = StackerVerdict::verified(['das' => 8, 'arr' => 1, 'sdf' => 20], 'CanonicalFromTheVerifier');
    $faster = issueRun(User::factory()->create());
    submitForty($faster['token'])->assertAccepted();
    expect(runOf($faster))->status->toBe(StackerRunStatus::Verified)->replay->toBe('CanonicalFromTheVerifier');
});

test('an IPv4 address written as IPv6 (::ffff:a.b.c.d) shares its IPv4 network\'s limit', function () {
    expect(StackerRuns::network('::ffff:192.0.2.10'))->toBe('192.0.2.10')
        ->and(StackerRuns::network('::FFFF:c000:020a'))->toBe('192.0.2.10')
        ->and(StackerRuns::network('2001:db8:1:2::1'))->toBe(StackerRuns::network('2001:db8:1:2:ffff::9'))
        ->and(config('esports.blockfill.issue_global_per_minute'))->toBe(3000);

    BlockfillOn::play();
    config(['esports.blockfill.issue_per_ip_per_hour' => 1]);
    $this->actingAs(User::factory()->create())->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])->postJson(route('stacker.runs.issue'))->assertCreated();
    $this->actingAs(User::factory()->create())->withServerVariables(['REMOTE_ADDR' => '::ffff:192.0.2.10'])->postJson(route('stacker.runs.issue'))->assertTooManyRequests();
});

test('issuing is also limited per network (IPv6 by /64) and in total per minute', function () {
    BlockfillOn::play();
    config(['esports.blockfill.issue_per_ip_per_hour' => 2, 'esports.blockfill.issue_global_per_minute' => 5]);
    $issueFrom = fn (string $ip) => $this->actingAs(User::factory()->create())
        ->withServerVariables(['REMOTE_ADDR' => $ip])
        ->postJson(route('stacker.runs.issue'));

    $issueFrom('2001:db8:1:2::1')->assertCreated();
    $issueFrom('2001:db8:1:2:ffff::9')->assertCreated();
    $issueFrom('2001:db8:1:2::77')->assertTooManyRequests();
    $issueFrom('2001:db8:1:3::1')->assertCreated();
    $issueFrom('192.0.2.10')->assertCreated();
    $issueFrom('192.0.2.11')->assertCreated();
    // the sixth issue of the minute, from a fresh network
    $issueFrom('198.51.100.1')->assertTooManyRequests();

    expect(StackerRun::query()->count())->toBe(5);
});

test('old runs without a verified time are pruned, a stuck verification goes back to pending, and pending runs can be sent again', function () {
    Queue::fake();
    $user = User::factory()->create();
    $old = StackerRun::factory()->for($user)->create(['status' => StackerRunStatus::Rejected, 'created_at' => now()->subDays(31)]);
    $oldVerified = StackerRun::factory()->for($user)->verified(900)->create(['created_at' => now()->subDays(31)]);
    $recent = StackerRun::factory()->for($user)->create(['status' => StackerRunStatus::Practice, 'created_at' => now()->subDays(29)]);

    $this->artisan('model:prune', ['--model' => StackerRun::class])->assertSuccessful();
    expect(StackerRun::query()->pluck('id')->sort()->values()->all())->toBe([$oldVerified->id, $recent->id]);

    $stuck = StackerRun::factory()->for($user)->create(['status' => StackerRunStatus::Verifying, 'submitted_at' => now()->subMinutes(11), 'updated_at' => now()->subMinutes(11), 'replay' => 'AQ']);
    $fresh = StackerRun::factory()->for($user)->create(['status' => StackerRunStatus::Verifying, 'submitted_at' => now()->subMinutes(9), 'updated_at' => now()->subMinutes(9), 'replay' => 'AQ']);
    expect(app(StackerRuns::class)->sweepStale(now()))->toBe(1);
    expect($stuck->refresh())->status->toBe(StackerRunStatus::Pending)->reason->toBe('verifier-stale')
        ->and($fresh->refresh()->status)->toBe(StackerRunStatus::Verifying);

    // the scheduled sweep sends it again by itself (a probe of one: no verdict yet), by hand works too
    $this->artisan('stacker:sweep')->assertSuccessful();
    expect($stuck->refresh()->status)->toBe(StackerRunStatus::Verifying);
    Queue::assertPushedOn('stacker-verify', VerifyStackerRun::class, fn (VerifyStackerRun $job): bool => $job->runId === $stuck->id);
    Queue::assertPushed(VerifyStackerRun::class, 1);

    // routes/console.php schedules Blockfill's jobs only while it is registered (P6); the test app booted with it off.
    BlockfillOn::play();
    require base_path('routes/console.php');
    $scheduled = collect(app(Schedule::class)->events())->map(fn ($event): string => (string) $event->command)->implode("\n");
    expect($scheduled)->toContain('stacker:sweep')->toContain(StackerRun::class);
});

test('a submission with more inputs than its played time allows is refused before the verifier, and nothing is stored', function () {
    BlockfillOn::play();

    foreach (['forty-lines-noop-prefix', 'forty-lines-finishing-tick'] as $name) {
        $issued = issueRun(User::factory()->create());
        $replay = trim((string) file_get_contents(base_path("tests/Fixtures/stacker/{$name}.replay")));

        submitForty($issued['token'], 500, ['replay' => $replay])->assertAccepted()->assertJson(['status' => 'rejected', 'reason' => 'oversize']);
        expect(runOf($issued)->replay)->toBeNull($name);
    }

    expect($this->verifier->asked)->toBe([]);
});

test('issuing is also limited per network and minute, so a burst from a few networks cannot reach the league-wide breaker', function () {
    BlockfillOn::play();
    config(['esports.blockfill.issue_per_ip_per_minute' => 2]);
    $issueFrom = fn (string $ip) => $this->actingAs(User::factory()->create())->withServerVariables(['REMOTE_ADDR' => $ip])->postJson(route('stacker.runs.issue'));

    $issueFrom('203.0.113.5')->assertCreated();
    $issueFrom('203.0.113.5')->assertCreated();
    $issueFrom('203.0.113.5')->assertTooManyRequests();
    $issueFrom('203.0.113.6')->assertCreated();
    expect(config('esports.blockfill.issue_per_ip_per_minute'))->toBe(2);
});

/**
 * A submission of `$replay` claiming `$ticks` and `$hash`, after the played time plus 500 ms.
 */
function submitReplay(string $token, string $replay, int $ticks, string $hash): TestResponse
{
    test()->travel(intdiv($ticks * 1000, 60) + 500)->milliseconds();

    return test()->postJson(route('stacker.runs.submit', $token), ['replay' => $replay, 'ticks' => $ticks, 'hash' => $hash]);
}

test('a verified run keeps its replay only among the week\'s fastest; a run stretched to 36,000 ticks outside them stores none', function () {
    BlockfillOn::play();
    config(['esports.blockfill.replay_keep_top' => 2]);
    $kilobyte = str_repeat('A', 1000);
    $first = StackerRun::factory()->verified(900)->create(['replay' => $kilobyte]);
    $second = StackerRun::factory()->verified(1000)->create(['replay' => $kilobyte]);

    $padded = json_decode((string) file_get_contents(base_path('tests/Fixtures/stacker/padded-36k.json')), true);
    $issued = issueRun(User::factory()->create());
    submitReplay($issued['token'], trim((string) file_get_contents(base_path('tests/Fixtures/stacker/padded-36k.replay'))), 36000, $padded['hash'])->assertAccepted();

    expect(runOf($issued))->status->toBe(StackerRunStatus::Verified)->ticks->toBe(36000)->replay->toBeNull()
        ->and($first->refresh()->replay)->toBe($kilobyte)
        ->and($second->refresh()->replay)->toBe($kilobyte);
});

test('a run among the week\'s fastest keeps its replay until faster runs push it out; another week is not touched', function () {
    BlockfillOn::play();
    config(['esports.blockfill.replay_keep_top' => 2]);
    $kilobyte = str_repeat('A', 1000);
    $lastWeek = StackerRun::factory()->verified(800)->create(['replay' => $kilobyte, 'submitted_at' => now()->subWeek(), 'week' => StackerRuns::weekOf(now()->subWeek())]);
    $fast = StackerRun::factory()->verified(900)->create(['replay' => $kilobyte]);
    $slow = StackerRun::factory()->verified(1100)->create(['replay' => $kilobyte]);

    // 958 ticks: second of the week, it keeps its replay; 1100 falls out
    $this->verifier->verdict = StackerVerdict::verified(['das' => 8, 'arr' => 1, 'sdf' => 20], $kilobyte);
    $issued = issueRun(User::factory()->create());
    submitForty($issued['token'])->assertAccepted();

    expect(runOf($issued))->status->toBe(StackerRunStatus::Verified)->replay->toBe($kilobyte)
        ->and($fast->refresh()->replay)->toBe($kilobyte)
        ->and($slow->refresh()->replay)->toBeNull()
        ->and($lastWeek->refresh()->replay)->toBe($kilobyte);

    // two faster runs this week: the 958 run falls out as well
    StackerRun::factory()->verified(850)->create(['replay' => $kilobyte]);
    app(StackerRuns::class)->keepWeekTop(StackerRuns::weekOf(now()));
    expect(runOf($issued)->replay)->toBeNull()
        ->and($fast->refresh()->replay)->toBe($kilobyte)
        ->and(StackerRun::query()->where('week', StackerRuns::weekOf(now()))->whereNotNull('replay')->count())->toBe(2);
});

test('a replay whose header lies about its input count is refused before the verifier, with no bytes stored', function () {
    BlockfillOn::play();
    $issued = issueRun(User::factory()->create());

    submitForty($issued['token'], 500, ['replay' => trim((string) file_get_contents(base_path('tests/Fixtures/stacker/forty-lines-lying-header.replay')))])
        ->assertAccepted()->assertJson(['status' => 'rejected', 'reason' => 'malformed']);

    expect(runOf($issued)->replay)->toBeNull()
        ->and($this->verifier->asked)->toBe([]);
});

test('the week of a run starts on Monday 00:00 Berlin time, summer and winter', function () {
    expect(StackerRuns::weekOf(Carbon::parse('2026-10-04 21:59:59', 'UTC')))->toBe('2026-09-28')
        ->and(StackerRuns::weekOf(Carbon::parse('2026-10-04 22:00:00', 'UTC')))->toBe('2026-10-05')
        ->and(StackerRuns::weekOf(Carbon::parse('2026-12-06 22:59:59', 'UTC')))->toBe('2026-11-30')
        ->and(StackerRuns::weekOf(Carbon::parse('2026-12-06 23:00:00', 'UTC')))->toBe('2026-12-07');
});

test('at most replay_inflight_max runs wait for the verifier with a replay; beyond that a submission is turned away and its token kept', function () {
    BlockfillOn::play();
    config(['esports.blockfill.replay_inflight_max' => 1]);
    $waiting = StackerRun::factory()->create(['status' => StackerRunStatus::Pending, 'reason' => 'verifier-unavailable', 'replay' => 'AQ', 'submitted_at' => now()]);
    $issued = issueRun(User::factory()->create());

    submitForty($issued['token'])->assertStatus(503)->assertJson(['status' => 'busy'])->assertHeader('Retry-After', '10');
    expect(runOf($issued))->status->toBe(StackerRunStatus::Issued)->replay->toBeNull()->submitted_at->toBeNull()
        ->and($this->verifier->asked)->toBe([]);

    // once the queue has room, the same token is taken
    $waiting->forceFill(['replay' => null])->save();
    submitForty($issued['token'])->assertAccepted()->assertJson(['status' => 'verifying']);
    expect(runOf($issued)->status)->toBe(StackerRunStatus::Verified);
});

test('a late job still decides a run the sweep gave up as stale', function () {
    BlockfillOn::play();
    $run = StackerRun::factory()->create([
        'status' => StackerRunStatus::Pending, 'reason' => 'verifier-stale', 'replay' => BlockfillOn::fixture('forty-lines')['replay'],
        'ticks' => 958, 'state_hash' => '6102773e', 'submitted_at' => now()->subMinutes(20),
    ]);

    VerifyStackerRun::dispatchSync($run->id);

    expect($run->refresh())->status->toBe(StackerRunStatus::Verified)->reason->toBeNull()
        ->and($this->verifier->asked)->toBe([$run->id]);
});

test('a pending run drops its replay after pending_replay_hours, and is then never sent to the verifier again', function () {
    Queue::fake();
    $old = StackerRun::factory()->create(['status' => StackerRunStatus::Pending, 'reason' => 'verifier-unavailable', 'replay' => 'AQ', 'submitted_at' => now()->subHours(25)]);
    $timedOut = StackerRun::factory()->create(['status' => StackerRunStatus::Pending, 'reason' => 'verifier-timeout', 'replay' => 'AQ', 'submitted_at' => now()->subHours(25)]);
    $recent = StackerRun::factory()->create(['status' => StackerRunStatus::Pending, 'reason' => 'verifier-unavailable', 'replay' => 'AQ', 'submitted_at' => now()->subHours(23)]);

    $this->artisan('stacker:sweep')->assertSuccessful();

    expect($old->refresh())->replay->toBeNull()->reason->toBe('replay-dropped')->status->toBe(StackerRunStatus::Pending)
        ->and($timedOut->refresh()->replay)->toBeNull()
        ->and($recent->refresh()->replay)->toBe('AQ');

    $this->artisan('stacker:reverify')->assertSuccessful();
    expect($old->refresh()->status)->toBe(StackerRunStatus::Pending)
        ->and($recent->refresh()->status)->toBe(StackerRunStatus::Verifying);
    Queue::assertPushed(VerifyStackerRun::class, 1);
});

test('sending a pending run again keeps the time it was submitted, so it stays in its week', function () {
    Queue::fake();
    $submitted = now()->subDays(8);
    $run = StackerRun::factory()->create(['status' => StackerRunStatus::Pending, 'reason' => 'verifier-stale', 'replay' => 'AQ', 'submitted_at' => $submitted]);

    $this->artisan('stacker:reverify')->assertSuccessful();

    expect($run->refresh()->status)->toBe(StackerRunStatus::Verifying)
        ->and($run->submitted_at->format('Y-m-d H:i:s'))->toBe($submitted->format('Y-m-d H:i:s'));
    // the sweep measures a fresh verification from when it was sent again, not from the old submission
    $this->artisan('stacker:sweep')->assertSuccessful();
    expect($run->refresh()->status)->toBe(StackerRunStatus::Verifying);
});

test('after an outage the sweep sends the waiting runs again by itself: one probe first, then the rest, and the slots are free again', function () {
    BlockfillOn::play();
    config(['esports.blockfill.replay_inflight_max' => 2]);
    $forty = BlockfillOn::fixture('forty-lines');

    // the outage: two runs end up pending with their replays, the slots are full
    $this->verifier->verdict = StackerVerdict::unavailable('verifier-unavailable');
    foreach ([User::factory()->create(), User::factory()->create()] as $player) {
        submitForty(issueRun($player)['token'])->assertAccepted();
    }
    expect(StackerRun::query()->where('status', StackerRunStatus::Pending)->whereNotNull('replay')->count())->toBe(2);
    submitForty(issueRun(User::factory()->create())['token'])->assertStatus(503);

    // recovery: the verifier answers again; the next sweeps re-send the runs, no command by hand
    $this->verifier->verdict = null;
    $this->artisan('stacker:sweep')->assertSuccessful();
    expect(StackerRun::query()->where('status', StackerRunStatus::Verified)->count())->toBe(1);
    $this->travel(5)->minutes();
    $this->artisan('stacker:sweep')->assertSuccessful();
    expect(StackerRun::query()->where('status', StackerRunStatus::Pending)->count())->toBe(0)
        ->and(StackerRun::query()->where('status', StackerRunStatus::Verified)->count())->toBe(2);

    $this->travel(3)->seconds();
    submitForty(issueRun(User::factory()->create())['token'])->assertAccepted();
});

test('one account holds at most inflight_per_account waiting runs, one network at most inflight_per_network', function () {
    BlockfillOn::play();
    config(['esports.blockfill.inflight_per_account' => 1, 'esports.blockfill.inflight_per_network' => 2]);
    $this->verifier->verdict = StackerVerdict::unavailable('verifier-unavailable');
    $submitFrom = function (User $player, string $ip) {
        $issued = test()->actingAs($player)->withServerVariables(['REMOTE_ADDR' => $ip])->postJson(route('stacker.runs.issue'))->assertCreated()->json();
        test()->withServerVariables(['REMOTE_ADDR' => $ip])->postJson(route('stacker.runs.start', $issued['token']))->assertNoContent();

        return submitForty($issued['token']);
    };

    $anna = User::factory()->create();
    $submitFrom($anna, '198.51.100.1')->assertAccepted();
    $this->travel(3)->seconds();
    $submitFrom($anna, '198.51.100.2')->assertStatus(503);

    $submitFrom(User::factory()->create(), '198.51.100.3')->assertAccepted();
    $submitFrom(User::factory()->create(), '198.51.100.3')->assertAccepted();
    $submitFrom(User::factory()->create(), '198.51.100.3')->assertStatus(503);
    $submitFrom(User::factory()->create(), '203.0.113.9')->assertAccepted();
});

test('a waiting run stores a keyed hash of its network, never the address, and drops it once decided or after pending_replay_hours', function () {
    BlockfillOn::play();
    $submitFrom = function (string $ip) {
        $issued = test()->actingAs(User::factory()->create())->withServerVariables(['REMOTE_ADDR' => $ip])->postJson(route('stacker.runs.issue'))->assertCreated()->json();
        test()->withServerVariables(['REMOTE_ADDR' => $ip])->postJson(route('stacker.runs.start', $issued['token']))->assertNoContent();
        submitForty($issued['token'])->assertAccepted();

        return runOf($issued);
    };

    $this->verifier->verdict = StackerVerdict::unavailable('verifier-unavailable');
    $waiting = $submitFrom('198.51.100.7');
    $waitingV6 = $submitFrom('2001:db8:1:2::1');
    expect($waiting)->status->toBe(StackerRunStatus::Pending)
        ->network->toBe(StackerRuns::networkKey('198.51.100.7'))
        ->network->not->toContain('198.51.100')
        ->and($waitingV6->network)->toBe(StackerRuns::networkKey(StackerRuns::network('2001:db8:1:2::1')))
        ->and($waitingV6->network)->not->toContain('2001');

    $this->verifier->verdict = StackerVerdict::rejected('mismatch');
    expect($submitFrom('198.51.100.8'))->status->toBe(StackerRunStatus::Rejected)->network->toBeNull();
    $this->verifier->verdict = StackerVerdict::verified(['das' => 10, 'arr' => 2, 'sdf' => 20], BlockfillOn::fixture('forty-lines')['replay']);
    expect($submitFrom('198.51.100.9'))->status->toBe(StackerRunStatus::Verified)->network->toBeNull();

    // a waiting run that is never decided loses it with its replay
    $this->travel(25)->hours();
    app(StackerRuns::class)->dropOldPendingReplays(now());
    expect($waiting->refresh())->reason->toBe('replay-dropped')->network->toBeNull();
});

test('a failed job on a run that is decided already does not tell the sweep the verifier is down', function () {
    BlockfillOn::play();
    Cache::put(StackerRuns::LAST_VERDICT_KEY, ['at' => now()->getTimestamp(), 'answered' => true], now()->addDay());
    $verified = StackerRun::factory()->verified(900)->create();

    (new VerifyStackerRun($verified->id))->failed(null);

    expect(Cache::get(StackerRuns::LAST_VERDICT_KEY)['answered'])->toBeTrue()
        ->and($verified->refresh()->status)->toBe(StackerRunStatus::Verified);

    $verifying = StackerRun::factory()->create(['status' => StackerRunStatus::Verifying, 'replay' => 'AQ', 'submitted_at' => now()]);
    (new VerifyStackerRun($verifying->id))->failed(null);

    expect(Cache::get(StackerRuns::LAST_VERDICT_KEY)['answered'])->toBeFalse()
        ->and($verifying->refresh())->status->toBe(StackerRunStatus::Pending)->reason->toBe('verifier-failed');
});

test('on SQLite the week top update finds the runs holding a replay through the partial index', function () {
    $plan = collect(DB::select('EXPLAIN QUERY PLAN '.app(StackerRuns::class)->weekReplayHolders('2026-09-28')->toSql(), ['2026-09-28', 'verified']))
        ->pluck('detail')->implode(' | ');

    expect(DB::connection()->getDriverName())->toBe('sqlite')
        ->and($plan)->toContain('stacker_runs_week_replay_index');
});
