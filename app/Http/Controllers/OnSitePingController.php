<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Notifications\OnSite;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;

/**
 * POST /presence/ping from a visible page (resources/js/onSite.js, every
 * 30 s). While the player is on the site, notifications are not sent by push or
 * DM; the bell and the toast reach them (OnSite, Notifier).
 *
 * Always 204, never an error status, so a tab left open after logging out
 * writes no error to the console: `X-On-Site: signed-out` tells the page to
 * stop, `X-On-Site: slow` (with Retry-After) to wait longer. The route has
 * no `auth` middleware for the same reason; the CSRF check still runs (a
 * same-origin fetch passes it by its Sec-Fetch-Site header, even with the
 * token of an ended session).
 */
class OnSitePingController extends Controller
{
    public const PER_MINUTE = 30;

    public function __invoke(Request $request, OnSite $onSite): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return response()->noContent()->header('X-On-Site', 'signed-out');
        }

        $key = 'on-site-ping:'.$user->id;

        if (RateLimiter::tooManyAttempts($key, self::PER_MINUTE)) {
            return response()->noContent()->header('X-On-Site', 'slow')->header('Retry-After', (string) RateLimiter::availableIn($key));
        }

        RateLimiter::hit($key, 60);

        $onSite->seen($user);

        return response()->noContent();
    }
}
