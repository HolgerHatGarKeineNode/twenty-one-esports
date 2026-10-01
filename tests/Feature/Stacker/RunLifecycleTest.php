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
use App\Support\Stacker\StackerVerdict;
use App\Support\Stacker\Verifier;
use Illuminate\Support\Facades\DB;
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
 * Issues a run for `$user` (logged in from here on) and returns its JSON.
 *
 * @return array{token: string, seed: string, engine: string}
 */
function issueRun(User $user, bool $start = false): array
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
    $issued = issueRun($user);

    $run = runOf($issued);
    expect($issued['token'])->toMatch('/^[A-Za-z0-9]{40}$/')
        ->and($issued['seed'])->toMatch('/^[0-9a-f]{32}$/')
        ->and($issued['engine'])->toBe('bf1')
        ->and($issued['limits'])->toBe(['ticks' => 36000, 'inputs' => 20000, 'bytes' => 65536])
        ->and($run->token_hash)->toBe(hash('sha256', $issued['token']))
        ->and(json_encode($run->getAttributes()))->not->toContain($issued['token'])
        ->and($run->status)->toBe(StackerRunStatus::Issued);

    // one issue per 2 s
    $this->postJson(route('stacker.runs.issue'))->assertTooManyRequests();
    $this->travel(2)->seconds();
    issueRun($user);
});

test('one active run per player, a token expires 2 minutes unstarted, and a run starts once', function () {
    BlockfillOn::play();
    $user = User::factory()->create();

    $first = issueRun($user);
    $this->travel(3)->seconds();
    $second = issueRun($user);
    expect(runOf($first))->status->toBe(StackerRunStatus::Abandoned)->reason->toBe('replaced');
    $this->postJson(route('stacker.runs.start', $first['token']))->assertStatus(410)->assertJson(['reason' => 'replaced']);

    $this->travel(121)->seconds();
    $this->postJson(route('stacker.runs.start', $second['token']))->assertStatus(410)->assertJson(['status' => 'abandoned', 'reason' => 'expired']);
    expect(runOf($second)->status)->toBe(StackerRunStatus::Abandoned);

    $third = issueRun($user);
    $this->travel(119)->seconds();
    $this->postJson(route('stacker.runs.start', $third['token']))->assertNoContent();
    $this->postJson(route('stacker.runs.start', $third['token']))->assertConflict();

    // an unstarted token expires for a submission as well
    $this->travel(3)->seconds();
    $fourth = issueRun($user);
    $this->travel(121)->seconds();
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

test('the wall clock from start (or issue) to submission covers the played time and at most 20 s more', function () {
    BlockfillOn::play();

    $cases = [
        'faster than real time' => [-1, true, 'rejected', 'clock'],
        'exactly the played time' => [0, true, 'verifying', null],
        '20 s later' => [20000, true, 'verifying', null],
        'more than 20 s later' => [20001, true, 'rejected', 'clock'],
        'never started, 30 s waited after the issue' => [30000, false, 'rejected', 'clock'],
    ];
    foreach ($cases as $case => [$extraMs, $start, $status, $reason]) {
        $issued = issueRun(User::factory()->create(), $start);
        $asked = count($this->verifier->asked);

        submitForty($issued['token'], $extraMs)->assertAccepted()->assertJson(['status' => $status, 'reason' => $reason]);
        expect(count($this->verifier->asked) - $asked)->toBe($status === 'verifying' ? 1 : 0, $case);
    }
});

test('only a time that beats the player\'s verified best is verified, the rest is kept as practice', function () {
    BlockfillOn::play();
    $user = User::factory()->create();
    StackerRun::factory()->for($user)->verified(900)->create();

    $issued = issueRun($user);
    submitForty($issued['token'])->assertAccepted()->assertJson(['status' => 'practice']);

    expect($this->verifier->asked)->toBe([])
        ->and(runOf($issued)->replay)->not->toBeNull();
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
            ->verified_at->toBeNull();
    }
});
