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
 * threw on this replay, caught and reported by verify.mjs itself).
 * Everything else is no answer and says nothing about the run (no Node
 * binary, a missing or broken script, a non-zero exit, death by a signal,
 * output that is not the verdict format, and a timeout: the engine stops at
 * `limits.ticks` and the worst crafted replay measured well under 100 ms, so
 * running out of the 5 s means an overloaded host). Unavailable: the run
 * stays pending, never scores, and `stacker:reverify` can send it again; a
 * deploy defect or a busy host never rejects an honest run.
 */
final class NodeVerifier implements Verifier
{
    /** Reasons verify.mjs may give; an answer with any other is no answer. */
    private const REASONS = ['malformed', 'oversize', 'engine', 'seed', 'unfinished', 'trailing', 'mismatch', 'crash'];

    /** Cheat hints verify.mjs may give (P5, resources/js/stacker/hints.js); any other is dropped. */
    public const HINTS = ['pps', 'same-tick', 'timing', 'finesse'];

    public function verify(StackerRun $run): StackerVerdict
    {
        $config = (array) config('esports.blockfill');
        $verifier = (array) $config['verifier'];
        $script = (string) $verifier['script'];
        $script = str_starts_with($script, '/') ? $script : resource_path($script);

        $input = json_encode(array_filter([
            'replay' => (string) $run->replay,
            'seed' => $run->seed,
            'engine' => $run->engine,
            'claimed' => ['ticks' => (int) $run->ticks, 'hash' => (string) $run->state_hash],
            'limits' => [
                'ticks' => (int) $config['limits']['ticks'],
                'inputs' => (int) $config['limits']['inputs'],
                'bytes' => (int) $config['limits']['bytes'],
                'inputsPerTick' => (float) $config['limits']['inputs_per_tick'],
                'inputSlack' => (int) $config['limits']['input_slack'],
            ],
            // P5: other bounds for the cheat hints than hints.js's own (e.g. {"pps": 7}); unset in config/esports.php
            'hints' => is_array($config['hints'] ?? null) ? $config['hints'] : null,
        ], fn (mixed $value): bool => $value !== null), JSON_THROW_ON_ERROR);

        try {
            $result = Process::timeout((int) $verifier['timeout_seconds'])
                ->env(ChildEnvironment::withoutSecrets())
                ->input($input)
                ->run([(string) $verifier['node'], '--max-old-space-size='.(int) $verifier['heap_mb'], $script]);
        } catch (ProcessTimedOutException) {
            return StackerVerdict::unavailable('verifier-timeout');
        } catch (Throwable) {
            return StackerVerdict::unavailable('verifier-unavailable');
        }

        $answer = $result->exitCode() === 0 ? json_decode(trim($result->output()), true) : null;

        if (! is_array($answer) || ! is_bool($answer['ok'] ?? null)) {
            return StackerVerdict::unavailable('verifier-unavailable');
        }

        if ($answer['ok'] === true) {
            $settings = $answer['settings'] ?? null;
            $replay = $answer['replay'] ?? null;

            // the replay the verifier answers with is what the league keeps: without one the answer is incomplete
            if (! is_string($replay) || $replay === '' || strlen($replay) > (int) $config['limits']['bytes'] || preg_match('/^[A-Za-z0-9_-]+$/', $replay) !== 1) {
                return StackerVerdict::unavailable('verifier-unavailable');
            }

            // P5: the cheat hints belong to the answer too: without them a run could pass unlooked at
            $hints = self::hints($answer['hints'] ?? null);

            if ($hints === null) {
                return StackerVerdict::unavailable('verifier-unavailable');
            }

            $exact = ($answer['ticks'] ?? null) === $run->ticks && ($answer['hash'] ?? null) === $run->state_hash;

            return $exact && is_array($settings) ? StackerVerdict::verified([
                'das' => (int) ($settings['das'] ?? 0),
                'arr' => (int) ($settings['arr'] ?? 0),
                'sdf' => (int) ($settings['sdf'] ?? 0),
            ], $replay, $hints) : StackerVerdict::rejected('mismatch');
        }

        $reason = $answer['reason'] ?? null;

        return in_array($reason, self::REASONS, true)
            ? StackerVerdict::rejected($reason)
            : StackerVerdict::unavailable('verifier-unavailable');
    }

    /**
     * The hints of an answer, kept to known flags and plain numbers; null when
     * the answer has none in the expected shape.
     *
     * @return array{flags: list<string>, pps: float, maxPressesPerTick: int, timingCv: float|null, finesse: array{perfect: int, of: int}}|null
     */
    private static function hints(mixed $hints): ?array
    {
        if (! is_array($hints) || ! is_array($hints['flags'] ?? null) || ! is_array($hints['finesse'] ?? null)) {
            return null;
        }

        $timing = $hints['timingCv'] ?? null;

        return [
            'flags' => array_values(array_intersect(self::HINTS, $hints['flags'])),
            'pps' => round((float) ($hints['pps'] ?? 0), 2),
            'maxPressesPerTick' => (int) ($hints['maxPressesPerTick'] ?? 0),
            'timingCv' => is_int($timing) || is_float($timing) ? round((float) $timing, 2) : null,
            'finesse' => ['perfect' => (int) ($hints['finesse']['perfect'] ?? 0), 'of' => (int) ($hints['finesse']['of'] ?? 0)],
        ];
    }
}
