<?php

namespace App\Http\Controllers;

use App\Support\TwentyOne\LiveStatus;
use Illuminate\Http\JsonResponse;

/**
 * GET /stream/status (P20b): whether the stream is on air and how many
 * watch, for the site's one poller (resources/js/liveFeed.js). Public, no
 * session, no cookie, rate-limited per IP; the answer is LiveStatus's own
 * 5-second cache, and browsers and proxies may keep it as long.
 * `viewers` is null when the stream shares no count, and off air (LiveStatus
 * reads no count without a fresh playlist).
 * Not under /live/: nginx serves the HLS files there.
 */
final class LiveStatusController
{
    public function __invoke(): JsonResponse
    {
        $status = LiveStatus::current();

        return response()
            ->json(['live' => $status->live, 'viewers' => $status->viewers])
            ->header('Cache-Control', 'public, max-age='.LiveStatus::CACHE_SECONDS);
    }
}
