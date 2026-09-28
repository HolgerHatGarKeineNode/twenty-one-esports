<?php

namespace App\Support\Nostr;

use Phrity\Net\SocketStream;

/**
 * A websocket's socket with a hard end (P45 security audit F1): every read
 * below the websocket library checks an absolute deadline and a byte budget
 * for the whole connection, and never waits past the deadline.
 *
 * Why here: phrity's FrameHandler reads the payload length a frame announces
 * in one call, so a relay that announced 1e9 bytes made PHP allocate them (a
 * fatal "memory exhausted" that takes the queue worker down), and a relay
 * that sent one byte a second held the worker 49 s and more, because the
 * caller looked at its deadline only between frames. With this stream both
 * limits hold inside a frame and during the HTTP upgrade.
 */
final class BoundedSocketStream extends SocketStream
{
    private int $bytesRead = 0;

    /**
     * @param  resource  $stream
     */
    public function __construct($stream, private readonly float $deadline, private readonly int $maxBytes)
    {
        parent::__construct($stream);
    }

    /**
     * A read of exactly `$length` bytes (a frame's header or its announced
     * payload): refused before anything is allocated when it would exceed
     * the budget.
     */
    public function read(int $length): string
    {
        if ($this->bytesRead + $length > $this->maxBytes) {
            throw new RelayLimitExceeded('relay frame larger than the '.$this->maxBytes.' bytes allowed');
        }

        $this->waitAtMostUntilDeadline();
        $data = parent::read($length);
        $this->bytesRead += strlen($data);

        return $data;
    }

    /**
     * A line of the HTTP upgrade, read byte by byte: fgets() waits for the
     * line's end and restarts its timeout with every byte, so a relay that
     * drips the "101 Switching Protocols" held it 33 s (measured). Here the
     * deadline and the budget are checked before every byte. Null at EOF
     * with nothing read, as fgets().
     */
    public function readLine(int $length): ?string
    {
        $line = '';

        while (strlen($line) < $length - 1 && ! str_ends_with($line, "\n")) {
            if ($this->bytesRead >= $this->maxBytes) {
                throw new RelayLimitExceeded('relay sent more than the '.$this->maxBytes.' bytes allowed');
            }

            $this->waitAtMostUntilDeadline();
            $byte = parent::read(1);

            if ($byte === '') {
                if ($this->eof()) {
                    break;
                }

                throw new RelayLimitExceeded('relay read past its deadline');
            }

            $line .= $byte;
            $this->bytesRead++;
        }

        return $line === '' ? null : $line;
    }

    /**
     * The library sets its own timeout per operation; it never outlasts the deadline.
     */
    public function setTimeout(int|float $timeout, ?int $microseconds = null): bool
    {
        return parent::setTimeout(max(0.01, min((float) $timeout, $this->deadline - microtime(true))));
    }

    private function waitAtMostUntilDeadline(): void
    {
        $left = $this->deadline - microtime(true);

        if ($left <= 0) {
            throw new RelayLimitExceeded('relay read past its deadline');
        }

        parent::setTimeout($left);
    }
}
