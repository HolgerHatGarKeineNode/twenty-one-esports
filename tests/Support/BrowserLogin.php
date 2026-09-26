<?php

namespace Tests\Support;

use App\Models\User;

/**
 * Where a browser test's page helper logs its context in. The helpers
 * register their init scripts (error collectors, signer stubs) after the
 * first navigation and then load the page under test again, so the login
 * lands on a static file: a real page there was rendered and thrown away,
 * a few hundred milliseconds per context.
 */
final class BrowserLogin
{
    /** Served by the test server straight from public/, no app request. */
    public const LANDING = '/robots.txt';

    public static function url(User $user): string
    {
        return route('testing.login', ['user' => $user, 'to' => self::LANDING]);
    }
}
