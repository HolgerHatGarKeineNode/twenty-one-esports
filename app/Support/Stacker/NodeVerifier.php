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
 * Outcomes (security audit F2): only an answer of verify.mjs decides a
 * run. Verified or rejected with its reason, `crash` included (the engine
 * threw on this replay, caught and reported by verify.mjs itself). A
 * timeout rejects as well: the engine stops at `limits.ticks`, so a replay
 * that runs out of time is the input's doing (plan DoD P2). Everything else
 * is no answer and says nothing about the run (no Node binary, a missing or
 * broken script, a non-zero exit, death by a signal, output that is not
 * the verdict format): unavailable, so the run stays pending and a deploy
 * defect never rejects an honest run.
 */
final class NodeVerifier implements Verifier
{
    /** Reasons verify.mjs may give; an answer with any other is no answer. */
    private const REASONS = ['malformed', 'oversize', 'engine', 'seed', 'unfinished', 'mismatch', 'crash'];

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

        $answer = $result->exitCode() === 0 ? json_decode(trim($result->output()), true) : null;

        if (! is_array($answer) || ! is_bool($answer['ok'] ?? null)) {
            return StackerVerdict::unavailable('verifier-unavailable');
        }

        if ($answer['ok'] === true) {
            $settings = $answer['settings'] ?? null;
            $exact = ($answer['ticks'] ?? null) === $run->ticks && ($answer['hash'] ?? null) === $run->state_hash;

            return $exact && is_array($settings) ? StackerVerdict::verified([
                'das' => (int) ($settings['das'] ?? 0),
                'arr' => (int) ($settings['arr'] ?? 0),
                'sdf' => (int) ($settings['sdf'] ?? 0),
            ]) : StackerVerdict::rejected('mismatch');
        }

        $reason = $answer['reason'] ?? null;

        return in_array($reason, self::REASONS, true)
            ? StackerVerdict::rejected($reason)
            : StackerVerdict::unavailable('verifier-unavailable');
    }
}
