<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Moderation\SiteModeration;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EndBannedSessions
{
    /**
     * A key an admin banned from the site (SiteModeration) is logged out on
     * its next request, wherever it is, and the request goes on as a guest:
     * a page that needs a login sends it to the login, which refuses the key.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && SiteModeration::isBanned($user->pubkey)) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return $next($request);
    }
}
