<?php

use App\Http\Middleware\EndBannedSessions;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\RefreshStaleMembership;
use App\Http\Middleware\SetLocale;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            // Before anything reads the viewer: a banned key is a guest from its next request on (SiteModeration).
            EndBannedSessions::class,
            SetLocale::class,
            RefreshStaleMembership::class,
        ]);

        // Ahead of `auth` and `can:` in every route: a banned key is logged out first, so a page that needs a login sends it there.
        $middleware->prependToPriorityList(before: AuthenticatesRequests::class, prepend: EndBannedSessions::class);

        $middleware->alias([
            'admin' => EnsureUserIsAdmin::class,
        ]);

        // nginx hands requests to PHP-FPM directly, so REMOTE_ADDR is the client. Without an explicit
        // list Laravel trusts every proxy on *.on-forge.com hosts, and a forged X-Forwarded-For would
        // then pick request()->ip() and every per-IP limit (security re-gate R1, 2026-09-27).
        // Never an empty list: that falls through to the on-forge rule.
        $middleware->trustProxies(at: ['127.0.0.1', '::1']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
