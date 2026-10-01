<?php

namespace App\Support\Stacker;

use App\Models\StackerRun;
use App\Support\TwentyOne\Stream\ChildEnvironment;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Replays a Blockfill run in Node on the shared engine
 * (resources/js/stacker/verify.mjs): heap capped, a hard timeout, no secret
 * in the child's environment. Never call it inside a database transaction;
 * the job (App\Jobs\VerifyStackerRun) holds none while it waits.
 *
 * Outcomes: the verifier's own answer (verified or rejected with its
 * reason); a timeout or a crash of the replay (non-zero exit, no answer)
 * rejects the run, since the same input would fail the same way again; a
 * verifier that cannot be started at all (no Node binary, exit 126/127, or
 * an exception before the run) is unavailable, so the run stays pending.
 */
final class NodeVerifier implements Verifier
{
    /** Reasons verify.mjs may give; anything else is treated as a crash. */
    private const REASONS = ['malformed', 'oversize', 'engine', 'seed', 'unfinished', 'mismatch'];

    public function verify(StackerRun $run): StackerVerdict
    {
        $config = (array) config('esports.blockfill');
        $verifier = (array) $config['verifier'];
        $script = (string) $verifier['script'];
        $script = str_starts_with($script, '/') ? $script : resource_path($script);

        $input = json_encode([
            'replay' => (string) $run->replay,
            'seed' => $run->seed,
            'engine' => $run->engine,
            'claimed' => ['ticks' => (int) $run->ticks, 'hash' => (string) $run->state_hash],
            'limits' => [
                'ticks' => (int) $config['limits']['ticks'],
                'inputs' => (int) $config['limits']['inputs'],
                'bytes' => (int) $config['limits']['bytes'],
            ],
        ], JSON_THROW_ON_ERROR);

        try {
            $result = Process::timeout((int) $verifier['timeout_seconds'])
                ->env(ChildEnvironment::withoutSecrets())
                ->input($input)
                ->run([(string) $verifier['node'], '--max-old-space-size='.(int) $verifier['heap_mb'], $script]);
        } catch (ProcessTimedOutException) {
            return StackerVerdict::rejected('timeout');
        } catch (Throwable) {
            return StackerVerdict::unavailable('verifier-unavailable');
        }

        if (in_array($result->exitCode(), [126, 127], true)) {
            return StackerVerdict::unavailable('verifier-unavailable');
        }

        $answer = $result->successful() ? json_decode(trim($result->output()), true) : null;

        if (! is_array($answer)) {
            return StackerVerdict::rejected('crash');
        }

        if (($answer['ok'] ?? null) === true) {
            $settings = $answer['settings'] ?? null;
            $exact = ($answer['ticks'] ?? null) === $run->ticks && ($answer['hash'] ?? null) === $run->state_hash;

            return $exact && is_array($settings) ? StackerVerdict::verified([
                'das' => (int) ($settings['das'] ?? 0),
                'arr' => (int) ($settings['arr'] ?? 0),
                'sdf' => (int) ($settings['sdf'] ?? 0),
            ]) : StackerVerdict::rejected('mismatch');
        }

        $reason = $answer['reason'] ?? null;

        return StackerVerdict::rejected(in_array($reason, self::REASONS, true) ? $reason : 'crash');
    }
}
