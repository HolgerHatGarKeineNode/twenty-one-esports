<?php

namespace App\Support\TwentyOne\Stream;

/**
 * How many people watch the stream right now, from nginx's access log lines
 * for the HLS playlist (see ViewerSocket and config twentyone.stream.viewers).
 *
 * A player reloads the live playlist about once per segment (6 s), so a
 * viewer is a distinct (IP, user agent) pair that fetched it within
 * `windowSeconds`. Only a hash of the pair is kept, in memory, with the
 * second it was last seen (the daemon's clock, not the syslog timestamp,
 * which has no year and no zone); no IP or user agent is stored or logged.
 * Only 200/206/304 answers count, and agents matching `excludeAgents` (our
 * own probes, crawlers, monitors) never do. At most `maxKeys` pairs are kept:
 * a flood of new pairs pushes out the oldest ones instead of growing the map.
 */
final class ViewerCounter
{
    public const EXCLUDE_AGENTS = '/HeadlessChrome|Playwright|curl|Wget|python-requests|Go-http-client|bot|spider|monitor/i';

    /** HTTP answers that delivered the playlist to a player. */
    private const COUNTED_STATUSES = ['200', '206', '304'];

    /**
     * RFC 3164 frame as nginx sends it: `<PRI>Mmm dd hh:mm:ss [host ]tag: message`
     * (nginx pads a one-digit day with a space; `nohostname` drops the host).
     */
    private const SYSLOG_FRAME = '/^<\d{1,3}>[A-Z][a-z]{2} [ \d]\d \d{2}:\d{2}:\d{2} (?:\S+ )?[^\s:]+: (.*)$/s';

    /**
     * nginx's built-in `combined` format, for a site whose config cannot define a
     * log_format (Forge edits only the server block): `$remote_addr - $remote_user
     * [$time_local] "$request" $status $body_bytes_sent "$http_referer"
     * "$http_user_agent"`. nginx escapes a quote inside a value as \x22.
     */
    private const COMBINED = '/^(\S+) - \S+ \[[^\]]*\] "[^"]*" (\d{3}) \S+ "[^"]*" "([^"]*)"$/';

    /** @var array<string, int> sha1(ip|ua) => last seen, oldest first */
    private array $lastSeen = [];

    public function __construct(
        private int $windowSeconds = 20,
        private ?string $excludeAgents = self::EXCLUDE_AGENTS,
        private int $maxKeys = 10_000,
    ) {}

    public static function fromConfig(): self
    {
        $exclude = config('twentyone.stream.viewers.exclude_agents', self::EXCLUDE_AGENTS);

        return new self(
            max(1, (int) config('twentyone.stream.viewers.window_seconds', 20)),
            is_string($exclude) && $exclude !== '' ? $exclude : null,
            max(1, (int) config('twentyone.stream.viewers.max_keys', 10_000)),
        );
    }

    /**
     * One datagram from the socket: a syslog frame around either
     * `$remote_addr|$http_user_agent|$status` or nginx's `combined` line.
     * Anything else is ignored.
     *
     * @return bool whether it counted as a viewer
     */
    public function record(string $datagram, int $now): bool
    {
        if (preg_match(self::SYSLOG_FRAME, rtrim($datagram, "\r\n\0"), $frame) !== 1) {
            return false;
        }

        $message = $frame[1];

        if (preg_match(self::COMBINED, $message, $combined) === 1) {
            [, $address, $status, $agent] = $combined;
        } else {
            $first = strpos($message, '|');
            $last = strrpos($message, '|');

            // The user agent may contain "|" itself: the address is before the first, the status after the last.
            if ($first === false || $first === $last) {
                return false;
            }

            $address = substr($message, 0, $first);
            $agent = substr($message, $first + 1, $last - $first - 1);
            $status = substr($message, $last + 1);
        }

        if (filter_var($address, FILTER_VALIDATE_IP) === false || ! in_array($status, self::COUNTED_STATUSES, true)) {
            return false;
        }

        if ($this->excludeAgents !== null && preg_match($this->excludeAgents, $agent) === 1) {
            return false;
        }

        $key = sha1($address.'|'.$agent);
        // Re-inserted at the end, so the map stays ordered by last sighting.
        unset($this->lastSeen[$key]);

        if (count($this->lastSeen) >= $this->maxKeys) {
            $this->evict($now);
        }

        while (count($this->lastSeen) >= $this->maxKeys) {
            unset($this->lastSeen[array_key_first($this->lastSeen)]);
        }

        $this->lastSeen[$key] = $now;

        return true;
    }

    /**
     * The distinct viewers seen within the window before `$now`.
     */
    public function count(int $now): int
    {
        $this->evict($now);

        return count($this->lastSeen);
    }

    /**
     * Pairs held in memory (expired ones included until the next count()).
     */
    public function size(): int
    {
        return count($this->lastSeen);
    }

    /**
     * Drop the pairs last seen `windowSeconds` or more ago (they are the
     * oldest, so the scan stops at the first one still inside).
     */
    private function evict(int $now): void
    {
        foreach ($this->lastSeen as $key => $seenAt) {
            if ($now - $seenAt < $this->windowSeconds) {
                break;
            }

            unset($this->lastSeen[$key]);
        }
    }
}
