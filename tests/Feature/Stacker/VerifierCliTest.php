<?php

/*
| The real Node verifier (App\Support\Stacker\NodeVerifier running
| resources/js/stacker/verify.mjs) on the reference runs of
| tests/Fixtures/stacker: it accepts the 40-line run at its exact time and
| rejects everything else; a verifier that does not answer leaves the run
| pending. The only tests that start Node for a run; the
| lifecycle tests use a fake. The timeout case waits about one second (the
| smallest timeout the process API takes) on a script that sleeps; a timeout
| leaves the run pending.
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

test('the 40-line reference run is a program at 6.45 pieces per second: replayed exactly, held for review with its hint (P5)', function () {
    $run = referenceRun('forty-lines');

    VerifyStackerRun::dispatchSync($run->id);

    expect($run->refresh())
        ->status->toBe(StackerRunStatus::Review)
        ->verified_at->toBeNull()
        ->ticks->toBe(958)
        ->settings->toBe(['das' => 8, 'arr' => 1, 'sdf' => 20])
        ->replay->toBe(BlockfillOn::fixture('forty-lines')['replay'])
        ->and($run->flags['hints'])->toMatchArray(['flags' => ['pps'], 'pps' => 6.45, 'maxPressesPerTick' => 1]);
});

test('the 40-line reference run is verified at its exact time, with its settings', function () {
    // P5: the reference run is a program at 6.45 pieces per second; under a bound of 7 it carries no hint
    config(['esports.blockfill.hints' => ['pps' => 7]]);
    $run = referenceRun('forty-lines');

    VerifyStackerRun::dispatchSync($run->id);

    expect($run->refresh())
        ->status->toBe(StackerRunStatus::Verified)
        ->ticks->toBe(958)
        ->state_hash->toBe('6102773e')
        ->settings->toBe(['das' => 8, 'arr' => 1, 'sdf' => 20])
        ->reason->toBeNull()
        // the canonical replay as the verifier re-encoded it: the reference run's 1.1 KB, not more
        ->replay->toBe(BlockfillOn::fixture('forty-lines')['replay']);
    expect(strlen((string) $run->replay))->toBeLessThan(1200);
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
    'inputs after the run ended (padding)' => ['forty-lines', ['replay' => trim((string) file_get_contents(__DIR__.'/../../Fixtures/stacker/forty-lines-padded.replay'))], [], 'trailing'],
    'no-op inputs before the finish (the re-audit probe, 20,000 inputs)' => ['forty-lines', ['replay' => trim((string) file_get_contents(__DIR__.'/../../Fixtures/stacker/forty-lines-noop-prefix.replay'))], [], 'oversize'],
    'inputs inside the finishing tick, after the last lock' => ['forty-lines', ['replay' => trim((string) file_get_contents(__DIR__.'/../../Fixtures/stacker/forty-lines-finishing-tick.replay'))], [], 'oversize'],
    'an overlong varint (padding inside the bytes)' => ['forty-lines', ['replay' => trim((string) file_get_contents(__DIR__.'/../../Fixtures/stacker/forty-lines-overlong.replay'))], [], 'malformed'],
    'a replay the engine throws on (answered by verify.mjs itself)' => ['forty-lines', [], ['esports.blockfill.verifier.script' => 'tests/Fixtures/stacker/throwing-engine-verifier.mjs'], 'crash'],
]);

test('a verifier that gives no valid answer leaves the run pending, never rejected', function () {
    // a timeout too: the worst crafted replay takes well under 100 ms, so 5 s means an overloaded host, not a bad run
    $cases = [
        'it crashes before answering' => ['tests/Fixtures/stacker/crashing-verifier.mjs', 'verifier-unavailable'],
        'its script does not parse' => ['tests/Fixtures/stacker/syntax-error-verifier.mjs', 'verifier-unavailable'],
        'its script is missing' => ['tests/Fixtures/stacker/no-such-verifier.mjs', 'verifier-unavailable'],
        'it runs out of time' => ['tests/Fixtures/stacker/slow-verifier.mjs', 'verifier-timeout'],
    ];
    foreach ($cases as $case => [$script, $reason]) {
        config(['esports.blockfill.verifier.script' => base_path($script), 'esports.blockfill.verifier.timeout_seconds' => 1]);
        $run = referenceRun('forty-lines');

        VerifyStackerRun::dispatchSync($run->id);

        expect($run->refresh())
            ->status->toBe(StackerRunStatus::Pending, $case)
            ->reason->toBe($reason, $case)
            ->replay->not->toBeNull();
        $run->delete();
    }
});

test('the verifier runs from a symlinked path, as in a release directory', function () {
    $dir = sys_get_temp_dir().'/stacker-link-'.bin2hex(random_bytes(4));
    mkdir($dir);
    symlink(resource_path('js/stacker/verify.mjs'), $dir.'/verify.mjs');
    config(['esports.blockfill.verifier.script' => $dir.'/verify.mjs']);

    try {
        $verdict = app(NodeVerifier::class)->verify(referenceRun('forty-lines'));
    } finally {
        unlink($dir.'/verify.mjs');
        rmdir($dir);
    }

    expect($verdict->outcome)->toBe(StackerVerdict::VERIFIED);
});

test('the verifier process sees no secret from the league\'s environment', function () {
    // as Laravel loads .env: process environment and $_ENV/$_SERVER (Symfony Process passes on what is in both)
    foreach (['STACKER_PROBE_TOKEN' => 'do-not-pass-me', 'STACKER_PROBE_PLAIN' => 'visible'] as $name => $value) {
        putenv("{$name}={$value}");
        $_ENV[$name] = $_SERVER[$name] = $value;
    }
    config(['esports.blockfill.verifier.script' => base_path('tests/Fixtures/stacker/env-verifier.mjs')]);

    try {
        $verdict = app(NodeVerifier::class)->verify(referenceRun('forty-lines'));
    } finally {
        foreach (['STACKER_PROBE_TOKEN', 'STACKER_PROBE_PLAIN'] as $name) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
    }

    // env-verifier.mjs answers `engine` if it sees the token, `oversize` if it misses the plain one
    expect($verdict->reason)->toBe('seed');
});

test('without a Node binary the verifier is unavailable and the run stays pending', function () {
    config(['esports.blockfill.verifier.node' => '/nonexistent/node']);
    $run = referenceRun('forty-lines');

    VerifyStackerRun::dispatchSync($run->id);

    expect($run->refresh())
        ->status->toBe(StackerRunStatus::Pending)
        ->reason->toBe('verifier-unavailable')
        ->verified_at->toBeNull();
});
