<?php

namespace Tests\Support;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps the browser tests' in-process server from sending a body behind a 204.
 *
 * Symfony strips Content-Length from a 204, and amphp/http-server (v3.4.6,
 * Http1Driver::send) then frames every response of status >= 200 without a
 * Content-Length as chunked: the headers go out in one write and the closing
 * "0\r\n\r\n" in a second one. A 204 has no body, so Chromium treats the
 * response as complete after the headers and reuses the socket at once; when
 * the PHP process is busy between the two writes (it is the test process
 * itself), the request that follows meets a stray "0\r\n\r\n" where it expects
 * its response, and fetch() rejects with "Failed to fetch"
 * (net::ERR_INVALID_HTTP_RESPONSE, -370). Measured 2026-10-04 in Chromium's
 * net-log on StackerTest's run start (204) followed 20 ms later by the run's
 * submission: the terminator arrived at the very millisecond the submission
 * was sent, 23 ms after the headers. The server had answered the submission
 * 202 and the league had verified the run; only the page never saw it.
 *
 * An explicit "Content-Length: 0" makes amphp write the headers alone. Only
 * 204: a 304 carrying a length of 0 would overwrite the cached entry's real
 * length. Guarded by tests/Browser/BodylessResponseFramingTest.php.
 */
final class BrowserBodylessFraming
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($response->getStatusCode() === Response::HTTP_NO_CONTENT) {
            $response->headers->set('Content-Length', '0');
        }

        return $response;
    }
}
