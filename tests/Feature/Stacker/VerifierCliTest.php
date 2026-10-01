<?php

/*
| The real Node verifier (App\Support\Stacker\NodeVerifier running
| resources/js/stacker/verify.mjs) on the reference runs of
| tests/Fixtures/stacker: it accepts the 40-line run at its exact time and
| rejects everything else. The only tests that start Node for a run; the
| lifecycle tests use a fake. The timeout case waits about one second (the
| smallest timeout the process API takes) on a script that sleeps.
*/

use App\Enums\StackerRunStatus;
use App\Jobs\VerifyStackerRun;
use App\Models\StackerRun;
use App\Support\Stacker\NodeVerifier;
use App\Support\Stacker\StackerVerdict;
use Tests\Support\BlockfillOn;

/**
 * A submitted run of reference run `$name`, as the verifier sees it.
 *
 * @param  array<string, mixed>  $overrides
 */
function referenceRun(string $name, array $overrides = []): StackerRun
{
    $fixture = BlockfillOn::fixture($name);

    return StackerRun::factory()->create($overrides + [
        'seed' => $fixture['seed'],
        'status' => StackerRunStatus::Verifying,
        'replay' => $fixture['replay'],
        'ticks' => $fixture['expected']['ticks'],
        'state_hash' => $fixture['expected']['stateHash'],
        'submitted_at' => now(),
    ]);
}

test('the 40-line reference run is verified at its exact time, with its settings', function () {
    $run = referenceRun('forty-lines');

    VerifyStackerRun::dispatchSync($run->id);

    expect($run->refresh())
        ->status->toBe(StackerRunStatus::Verified)
        ->ticks->toBe(958)
        ->state_hash->toBe('6102773e')
        ->settings->toBe(['das' => 8, 'arr' => 1, 'sdf' => 20])
        ->reason->toBeNull();
});

test('the verifier rejects what does not replay to the claim', function (string $name, array $overrides, array $config, string $reason) {
    // a script path in the dataset is relative to the repo (the app is not booted when datasets are built)
    if (isset($config['esports.blockfill.verifier.script'])) {
        $config['esports.blockfill.verifier.script'] = base_path($config['esports.blockfill.verifier.script']);
    }
    config($config);

    $verdict = app(NodeVerifier::class)->verify(referenceRun($name, $overrides));

    expect($verdict->outcome)->toBe(StackerVerdict::REJECTED)
        ->and($verdict->reason)->toBe($reason);
})->with([
    'a claim one tick off' => ['forty-lines', ['ticks' => 959], [], 'mismatch'],
    'fewer than 40 lines (the top-out run)' => ['top-out', [], [], 'unfinished'],
    'an engine it does not know' => ['forty-lines', ['engine' => 'bf9'], [], 'engine'],
    'a replay over the size limit' => ['forty-lines', [], ['esports.blockfill.limits.bytes' => 1000], 'oversize'],
    'a verifier that runs out of time' => ['forty-lines', [], ['esports.blockfill.verifier.timeout_seconds' => 1, 'esports.blockfill.verifier.script' => 'tests/Fixtures/stacker/slow-verifier.mjs'], 'timeout'],
    'a verifier that crashes' => ['forty-lines', [], ['esports.blockfill.verifier.script' => 'tests/Fixtures/stacker/crashing-verifier.mjs'], 'crash'],
]);

test('without a Node binary the verifier is unavailable and the run stays pending', function () {
    config(['esports.blockfill.verifier.node' => '/nonexistent/node']);
    $run = referenceRun('forty-lines');

    VerifyStackerRun::dispatchSync($run->id);

    expect($run->refresh())
        ->status->toBe(StackerRunStatus::Pending)
        ->reason->toBe('verifier-unavailable')
        ->verified_at->toBeNull();
});
