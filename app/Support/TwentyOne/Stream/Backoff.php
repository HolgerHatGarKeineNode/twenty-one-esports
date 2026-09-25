<?php

namespace App\Support\TwentyOne\Stream;

/**
 * Restart delay for the stream supervisor: initial, doubling up to max, and
 * back to initial after a run that proved healthy.
 */
final class Backoff
{
    private int $next;

    public function __construct(private int $initialSeconds = 5, private int $maxSeconds = 300)
    {
        $this->next = $initialSeconds;
    }

    /**
     * The delay to wait now; the one after it doubles.
     */
    public function next(): int
    {
        $delay = $this->next;
        $this->next = min($this->next * 2, $this->maxSeconds);

        return $delay;
    }

    public function reset(): void
    {
        $this->next = $this->initialSeconds;
    }
}
