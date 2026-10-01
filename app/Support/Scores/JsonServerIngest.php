<?php

namespace App\Support\Scores;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * The league's own finish format for a server of ours (plan "AoE2 und
 * Trackmania", P4): whatever a game server speaks (XML-RPC, a results file),
 * a small bridge next to it posts this JSON. Game-agnostic; no game server
 * speaks it by itself.
 *
 *     {"events": [{"id": "…", "mode": "…", "course": "…", "account": "…",
 *                  "value": 83456, "achieved_at": 1790812047, "raw": {…}}]}
 *
 * `value` in the mode's unit (ms or points), `achieved_at` in unix seconds or
 * ISO 8601, `raw` optional (kept for admins, never shown). At most 500
 * events per request.
 */
final class JsonServerIngest extends ServerIngest
{
    public const MAX_EVENTS = 500;

    public function parse(array $payload): array
    {
        $events = $payload['events'] ?? null;

        if (! is_array($events) || ! array_is_list($events) || count($events) > self::MAX_EVENTS) {
            return ['payload'];
        }

        return array_map(fn (mixed $entry): FinishEvent|string => $this->event($entry), $events);
    }

    private function event(mixed $entry): FinishEvent|string
    {
        if (! is_array($entry)) {
            return 'entry';
        }

        foreach (['id', 'mode', 'course', 'account'] as $field) {
            if (! is_string($entry[$field] ?? null) && ! is_int($entry[$field] ?? null)) {
                return $field;
            }
        }

        if (! is_int($entry['value'] ?? null)) {
            return 'value';
        }

        $at = $entry['achieved_at'] ?? null;

        try {
            $achievedAt = match (true) {
                is_int($at) => CarbonImmutable::createFromTimestamp($at),
                is_string($at) && $at !== '' => CarbonImmutable::parse($at),
                default => null,
            };
        } catch (Throwable) {
            $achievedAt = null;
        }

        if ($achievedAt === null) {
            return 'achieved_at';
        }

        $raw = $entry['raw'] ?? null;

        return new FinishEvent(
            trim((string) $entry['id']),
            trim((string) $entry['mode']),
            trim((string) $entry['course']),
            trim((string) $entry['account']),
            $entry['value'],
            $achievedAt,
            is_array($raw) ? $raw : null,
        );
    }
}
