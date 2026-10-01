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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

test('a practice run keeps no replay; a verified run keeps the verifier\'s canonical replay, never the submitted bytes', function () {
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

test('a player\'s stored replays stay within the byte budget, oldest first, the personal best always kept', function () {
    BlockfillOn::play();
    config(['esports.blockfill.replay_bytes_per_player' => 3000]);
    $user = User::factory()->create();
    $kilobyte = str_repeat('A', 1000);
    $oldest = StackerRun::factory()->for($user)->verified(1000)->create(['replay' => $kilobyte]);
    $middle = StackerRun::factory()->for($user)->verified(1100)->create(['replay' => $kilobyte]);
    $latest = StackerRun::factory()->for($user)->verified(1050)->create(['replay' => $kilobyte]);

    // a new personal best (958 ticks) is verified with a replay of 1000 bytes: 4000 > 3000, the oldest goes
    $this->verifier->verdict = StackerVerdict::verified(['das' => 8, 'arr' => 1, 'sdf' => 20], $kilobyte);
    $best = issueRun($user);
    submitForty($best['token'])->assertAccepted();

    expect(runOf($best))->status->toBe(StackerRunStatus::Verified)->replay->toBe($kilobyte)
        ->and($oldest->refresh()->replay)->toBeNull()
        ->and($middle->refresh()->replay)->toBe($kilobyte)
        ->and($latest->refresh()->replay)->toBe($kilobyte);

    // a budget smaller than the best replay alone: every other replay goes, the best stays
    config(['esports.blockfill.replay_bytes_per_player' => 500]);
    app(StackerRuns::class)->enforceReplayBudget($user->id);
    expect(StackerRun::query()->where('user_id', $user->id)->whereNotNull('replay')->pluck('seed')->all())->toBe([$best['seed']]);
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

    $stuck = StackerRun::factory()->for($user)->create(['status' => StackerRunStatus::Verifying, 'submitted_at' => now()->subMinutes(11), 'replay' => 'AQ']);
    $fresh = StackerRun::factory()->for($user)->create(['status' => StackerRunStatus::Verifying, 'submitted_at' => now()->subMinutes(9), 'replay' => 'AQ']);
    $this->artisan('stacker:sweep')->assertSuccessful();
    expect($stuck->refresh())->status->toBe(StackerRunStatus::Pending)->reason->toBe('verifier-stale')
        ->and($fresh->refresh()->status)->toBe(StackerRunStatus::Verifying);

    $this->artisan('stacker:reverify')->assertSuccessful();
    expect($stuck->refresh()->status)->toBe(StackerRunStatus::Verifying);
    Queue::assertPushedOn('stacker-verify', VerifyStackerRun::class, fn (VerifyStackerRun $job): bool => $job->runId === $stuck->id);

    $scheduled = collect(app(Schedule::class)->events())->map(fn ($event): string => (string) $event->command)->implode("\n");
    expect($scheduled)->toContain('stacker:sweep')->toContain('model:prune');
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

test('above the league-wide replay ceiling only personal bests keep their replay, the oldest others go first', function () {
    BlockfillOn::play();
    config(['esports.blockfill.replay_bytes_total' => 4000]);
    Log::spy();
    $kilobyte = str_repeat('A', 1000);
    [$anna, $bert] = User::factory()->count(2)->create();
    $annaBest = StackerRun::factory()->for($anna)->verified(900)->create(['replay' => $kilobyte]);
    $annaOld = StackerRun::factory()->for($anna)->verified(1000)->create(['replay' => $kilobyte]);
    $bertOld = StackerRun::factory()->for($bert)->verified(1100)->create(['replay' => $kilobyte]);
    $bertNewer = StackerRun::factory()->for($bert)->verified(1050)->create(['replay' => $kilobyte]);

    // Bert's new best (958 ticks, 1000 bytes): 5000 > 4000, Anna's older run goes first
    $this->verifier->verdict = StackerVerdict::verified(['das' => 8, 'arr' => 1, 'sdf' => 20], $kilobyte);
    $best = issueRun($bert);
    submitForty($best['token'])->assertAccepted();

    expect(runOf($best)->replay)->toBe($kilobyte)
        ->and($annaOld->refresh()->replay)->toBeNull()
        ->and($annaBest->refresh()->replay)->toBe($kilobyte)
        ->and($bertOld->refresh()->replay)->toBe($kilobyte)
        ->and($bertNewer->refresh()->replay)->toBe($kilobyte);

    // a ceiling below the personal bests alone: every other replay goes, both bests stay; one warning a day
    config(['esports.blockfill.replay_bytes_total' => 1500]);
    app(StackerRuns::class)->enforceReplayCeiling();
    app(StackerRuns::class)->enforceReplayCeiling();
    expect(StackerRun::query()->whereNotNull('replay')->pluck('id')->sort()->values()->all())->toBe([$annaBest->id, runOf($best)->id]);
    Log::shouldHaveReceived('warning')->once();
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
