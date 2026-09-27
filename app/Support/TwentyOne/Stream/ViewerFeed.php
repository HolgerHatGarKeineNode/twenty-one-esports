<?php

namespace App\Support\TwentyOne\Stream;

use Closure;
use Illuminate\Support\Str;
use Throwable;

/**
 * The live viewer count of the stream daemon: binds the ViewerSocket and,
 * once per loop turn, drains it into a ViewerCounter.
 *
 * When binding or reading fails the count is off (null) and the socket is
 * bound again after a backoff (config twentyone.stream.viewers.rebind_*:
 * initial, doubling up to max, back to initial once it works). Only the
 * first failure of a series and the recovery are logged, never each retry.
 */
final class ViewerFeed
{
    private ?ViewerSocket $socket = null;

    /** Unix second of the next bind attempt; null: bind at the next count(). */
    private ?int $retryAt = null;

    /** Whether the count is off after a failure (the next success logs the recovery). */
    private bool $failing = false;

    /**
     * @param  Closure(): ViewerSocket  $bind
     * @param  Closure(string): void  $log
     */
    public function __construct(
        private Closure $bind,
        private ViewerCounter $counter,
        private Backoff $backoff,
        private Closure $log,
    ) {}

    /**
     * @param  Closure(string): void  $log
     */
    public static function fromConfig(Closure $log): self
    {
        return new self(
            fn (): ViewerSocket => ViewerSocket::bind((string) config('twentyone.stream.viewers.dir'), (string) config('twentyone.stream.viewers.nginx_user')),
            ViewerCounter::fromConfig(),
            new Backoff(
                max(1, (int) config('twentyone.stream.viewers.rebind_initial_seconds', 30)),
                max(1, (int) config('twentyone.stream.viewers.rebind_max_seconds', 600)),
            ),
            $log,
        );
    }

    /**
     * Read the pending playlist requests (at most `$limit`) and count the
     * viewers at `$now`; null while the count is off. Binds the socket first
     * when it is not bound and its retry is due.
     */
    public function count(int $now, int $limit): ?int
    {
        if ($this->socket === null && ! $this->open($now)) {
            return null;
        }

        try {
            $this->socket?->drain($this->counter, $now, $limit);

            return $this->counter->count($now);
        } catch (Throwable $e) {
            $this->fail($e, $now);

            return null;
        }
    }

    /**
     * Close and remove the socket (on shutdown).
     */
    public function close(): void
    {
        $this->socket?->close();
        $this->socket = null;
    }

    private function open(int $now): bool
    {
        if ($this->retryAt !== null && $now < $this->retryAt) {
            return false;
        }

        try {
            $this->socket = ($this->bind)();
        } catch (Throwable $e) {
            $this->fail($e, $now);

            return false;
        }

        ($this->log)(($this->failing ? 'viewer count back on ' : 'viewer count on ').$this->socket->path);
        $this->failing = false;
        $this->retryAt = null;
        $this->backoff->reset();

        return true;
    }

    private function fail(Throwable $e, int $now): void
    {
        $this->close();
        $delay = $this->backoff->next();
        $this->retryAt = $now + $delay;

        if (! $this->failing) {
            ($this->log)('viewer count off: '.$e::class.': '.Str::limit(strtok($e->getMessage(), "\n") ?: '', 200).'; binding again in '.$delay.' s');
        }

        $this->failing = true;
    }
}
