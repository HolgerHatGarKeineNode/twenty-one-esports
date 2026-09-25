<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Membership;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

use function Illuminate\Support\defer;

class RefreshStaleMembership
{
    public function __construct(private Membership $membership) {}

    /**
     * Re-check a stale membership after the response is sent, so a slow or
     * unreachable Verein API never delays a page.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $this->membership->isStale($user)) {
            defer(fn () => $this->membership->refreshIfStale($user));
        }

        return $next($request);
    }
}
