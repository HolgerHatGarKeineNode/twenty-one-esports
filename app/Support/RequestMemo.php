<?php

namespace App\Support;

use Closure;
use Illuminate\Http\Request;

/**
 * A value worked out once per HTTP request (performance plan P2): the shell,
 * the match dock and the page body ask the same questions about the viewer
 * (their lineups, their open series, their sign-ups), and each asked the
 * database again.
 *
 * Kept in the attributes of the current request, like CupMatchNow::for(), so
 * every request starts empty (a test's next `get()` too). Only a routed
 * request that is still being handled memoizes: outside one (a queue worker,
 * a command, the body of a test) `request()` is one object for the whole
 * process, and a memo there would serve a value from an earlier job or an
 * earlier step of the test. After the response {@see close()} empties it
 * (AppServiceProvider, on RequestHandled), because a test keeps the last
 * request bound and reads on after it. There the closure runs on every call,
 * as before.
 */
final class RequestMemo
{
    private const PREFIX = 'memo.';

    private const CLOSED = 'memo-closed';

    /**
     * @template T
     *
     * @param  Closure(): T  $compute
     * @return T
     */
    public static function remember(string $key, Closure $compute): mixed
    {
        $request = request();

        if ($request->route() === null || $request->attributes->get(self::CLOSED) === true) {
            return $compute();
        }

        $key = self::PREFIX.$key;

        if ($request->attributes->has($key)) {
            return $request->attributes->get($key);
        }

        $value = $compute();
        $request->attributes->set($key, $value);

        return $value;
    }

    /** The response is out: drop what this request kept, and keep nothing more. */
    public static function close(Request $request): void
    {
        foreach (array_keys($request->attributes->all()) as $key) {
            if (str_starts_with((string) $key, self::PREFIX)) {
                $request->attributes->remove((string) $key);
            }
        }

        $request->attributes->set(self::CLOSED, true);
    }
}
