<?php

namespace App\Support\Board;

use Illuminate\Support\Facades\RateLimiter;

/**
 * A ceiling on the board pages' costly answers, per visitor and per address: a game's whole record
 * (pages.board.show fetchState()) and the practice pages' judge (open() and play()). Each is a method of a page
 * component that any guest may call, and one Livewire request may carry many calls: measured, 50 records of a go game
 * of 250 moves in one request cost 3.5 s, and 140 components with 50 judge calls each 14.8 s. A visitor is the
 * signed-in player or the session; the address has ten times the room, as the players of a meetup share one.
 */
final class BoardThrottle
{
    /** Whole records a minute: a board without a live connection asks every three seconds at most. */
    public const HISTORY_PER_MINUTE = 30;

    /** Judged practice moves a minute: a player and a bot move a few times a second at the very most. */
    public const PRACTICE_PER_MINUTE = 240;

    /** Whether this visitor may have one more of `what` this minute; every call counts. */
    public static function allows(string $what, int $perMinute): bool
    {
        $request = request();
        $visitor = auth()->id() !== null ? 'user:'.auth()->id() : 'session:'.($request->hasSession() ? $request->session()->getId() : $request->ip());
        $own = RateLimiter::hit("board:{$what}:{$visitor}", 60) <= $perMinute;
        $shared = RateLimiter::hit("board:{$what}:ip:".$request->ip(), 60) <= $perMinute * 10;

        return $own && $shared;
    }
}
