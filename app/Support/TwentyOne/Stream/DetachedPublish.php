<?php

namespace App\Support\TwentyOne\Stream;

use App\Support\TwentyOne\RelayPublisher;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Sends one already-signed kind-30311 event to the relays in a child process.
 *
 * The supervisor must not wait on name resolution or a relay: a hung connect
 * used to stall the encoder loop long enough for the watchdog to kill ffmpeg.
 * The child runs {@see self::SCRIPT} (autoload only, no Laravel boot, no
 * session file) and the parent reaps it between frames. A child still running
 * after the cap is killed; the encoder is never stopped for it.
 */
final class DetachedPublish
{
    public const SCRIPT = 'app/Support/TwentyOne/Stream/deliver-event.php';

    /** Seconds past the publish budget before a still-running child is killed. */
    private const KILL_GRACE_SECONDS = 3.0;

    private ?InvokedProcess $process = null;

    private float $startedAt = 0.0;

    private float $killAfter = 0.0;

    /** @var array{status: string, starts: int, id: string, created_at: int}|null */
    private ?array $event = null;

    public function running(): bool
    {
        return $this->process !== null && $this->process->running();
    }

    /**
     * @param  array{id: string, pubkey: string, created_at: int, kind: int, tags: list<list<string>>, content: string, sig: string}  $event
     * @param  list<string>  $relays
     */
    public function start(array $event, array $relays, float $timeoutSeconds, string $status, int $starts): void
    {
        $payload = json_encode([
            'event' => $event,
            'relays' => $relays,
            'timeout' => $timeoutSeconds,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $this->event = [
            'status' => $status,
            'starts' => $starts,
            'id' => $event['id'],
            'created_at' => $event['created_at'],
        ];
        $this->startedAt = microtime(true);
        $this->killAfter = $timeoutSeconds + self::KILL_GRACE_SECONDS;
        $this->process = Process::forever()
            ->input($payload)
            ->env(ChildEnvironment::withoutSecrets())
            ->start([PHP_BINARY, base_path(self::SCRIPT)]);
    }

    /**
     * The child's result, once it has exited. Null while it is still running.
     * A child past the cap is killed and reported as abandoned.
     *
     * @return array{accepted: int, total: int, summary: string, abandoned: bool, status: string, starts: int, id: string, created_at: int}|null
     */
    public function reap(): ?array
    {
        if ($this->process === null || $this->event === null) {
            return null;
        }

        if ($this->process->running()) {
            if (microtime(true) - $this->startedAt < $this->killAfter) {
                return null;
            }

            $this->halt();

            return $this->take([
                'accepted' => 0,
                'total' => 0,
                'summary' => 'abandoned: still running after '.number_format($this->killAfter, 0).' s',
                'abandoned' => true,
            ]);
        }

        $raw = $this->process->output();

        try {
            $decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return $this->take([
                'accepted' => 0,
                'total' => 0,
                'summary' => 'unreadable result',
                'abandoned' => true,
            ]);
        }

        if (! is_array($decoded)) {
            return $this->take([
                'accepted' => 0,
                'total' => 0,
                'summary' => 'unreadable result',
                'abandoned' => true,
            ]);
        }

        $accepted = 0;
        $parts = [];

        foreach ($decoded as $row) {
            if (! is_array($row)) {
                continue;
            }

            $ok = ($row['accepted'] ?? false) === true;
            $accepted += $ok ? 1 : 0;
            $parts[] = (string) ($row['relay'] ?? '?').' '.($ok ? 'ok' : 'failed: '.(string) ($row['message'] ?? ''));
        }

        return $this->take([
            'accepted' => $accepted,
            'total' => count($parts),
            'summary' => implode('; ', $parts),
            'abandoned' => false,
        ]);
    }

    /**
     * Deliver the stdin job and return the JSON result line. Used by {@see self::SCRIPT}.
     */
    public static function deliver(string $raw): string
    {
        $job = json_decode($raw, true);

        if (! is_array($job)) {
            return "[]\n";
        }

        $event = self::event($job['event'] ?? null);
        $relays = self::relays($job['relays'] ?? null);

        if ($event === null || $relays === null) {
            return "[]\n";
        }

        $timeout = is_numeric($job['timeout'] ?? null) ? (float) $job['timeout'] : 5.0;
        $out = [];

        foreach ((new RelayPublisher)->publish($event, $relays, $timeout) as $relay => $result) {
            $out[] = [
                'relay' => $relay,
                'accepted' => $result->accepted,
                'message' => $result->message,
            ];
        }

        return json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
    }

    public function stop(): void
    {
        $this->halt();
        $this->process = null;
        $this->event = null;
    }

    /**
     * @param  array{accepted: int, total: int, summary: string, abandoned: bool}  $result
     * @return array{accepted: int, total: int, summary: string, abandoned: bool, status: string, starts: int, id: string, created_at: int}
     */
    private function take(array $result): array
    {
        $event = $this->event ?? ['status' => 'live', 'starts' => 0, 'id' => '', 'created_at' => 0];
        $this->process = null;
        $this->event = null;

        return [...$result, ...$event];
    }

    private function halt(): void
    {
        if ($this->process === null || ! $this->process->running()) {
            return;
        }

        try {
            $this->process->signal(SIGTERM);
        } catch (Throwable) {
            // already gone
        }

        $deadline = microtime(true) + 0.3;

        while ($this->process->running() && microtime(true) < $deadline) {
            usleep(20_000);
        }

        if (! $this->process->running()) {
            return;
        }

        try {
            $this->process->signal(SIGKILL);
        } catch (Throwable) {
            // already gone
        }

        $deadline = microtime(true) + 0.2;

        while ($this->process->running() && microtime(true) < $deadline) {
            usleep(20_000);
        }
    }

    /**
     * @return array{id: string, pubkey: string, created_at: int, kind: int, tags: list<list<string>>, content: string, sig: string}|null
     */
    private static function event(mixed $event): ?array
    {
        if (! is_array($event) || ! is_string($event['id'] ?? null) || ! is_string($event['pubkey'] ?? null)
            || ! is_int($event['created_at'] ?? null) || ! is_int($event['kind'] ?? null)
            || ! is_array($event['tags'] ?? null) || ! is_string($event['content'] ?? null)
            || ! is_string($event['sig'] ?? null)) {
            return null;
        }

        $tags = [];

        foreach ($event['tags'] as $tag) {
            if (! is_array($tag)) {
                return null;
            }

            $row = [];

            foreach ($tag as $part) {
                if (! is_string($part)) {
                    return null;
                }

                $row[] = $part;
            }

            $tags[] = $row;
        }

        return [
            'id' => $event['id'],
            'pubkey' => $event['pubkey'],
            'created_at' => $event['created_at'],
            'kind' => $event['kind'],
            'tags' => $tags,
            'content' => $event['content'],
            'sig' => $event['sig'],
        ];
    }

    /**
     * @return list<string>|null
     */
    private static function relays(mixed $relays): ?array
    {
        if (! is_array($relays)) {
            return null;
        }

        $urls = [];

        foreach ($relays as $relay) {
            if (! is_string($relay)) {
                return null;
            }

            $urls[] = $relay;
        }

        return $urls;
    }
}
