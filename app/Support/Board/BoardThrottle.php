<?php

namespace App\Support\Board;

use Illuminate\Support\Facades\RateLimiter;

/**
 * A ceiling on the board pages' costly answers, per visitor and per address: a game's whole record
 * (pages.board.show fetchState()) and the practice pages' judge (open() and play()). Each is a method of a page
 * component that any guest may call, and one Livewire request may carry many calls: measured, 50 records of a go game
 * of 250 moves in one request cost 3.5 s, and 140 components with 50 judge calls each 14.8 s. A visitor is the
 * signed-in player or a guest's session; the guests of one address share ten times a visitor's room, as the
 * spectators of a meetup share one.
 *
 * The page's short answers have one too (security N1, 2026-11-14): the state without the record, the clock check and
 * a refused action each read the position by the game's rules, which a position built for it makes dear (a go game
 * of 559 plies with one group of 280 stones: 50 ms an answer before GoRules found its groups in one pass).
 */
final class BoardThrottle
{
    /** Whole records a minute: a board without a live connection asks every three seconds at most. */
    public const HISTORY_PER_MINUTE = 30;

    /** Judged practice moves a minute: a player and a bot move a few times a second at the very most. */
    public const PRACTICE_PER_MINUTE = 240;

    /**
     * States without the record a minute (fetchState()): a board asks every three seconds without a live connection
     * and every four with one (boardGame.js syncPolling(), HEARTBEAT_MS), 20 a minute and tab at the most.
     */
    public const STATES_PER_MINUTE = 120;

    /**
     * Clock checks a minute: a board whose clock shows zero asks every two seconds until the server ends the game
     * (boardGame.js tick()), 30 a minute and tab at the very most and in practice once or twice a game.
     */
    public const CLOCK_CHECKS_PER_MINUTE = 60;

    /** Refused actions a minute that are answered with the state: a player's board is behind or misclicked now and then. */
    public const REFUSALS_PER_MINUTE = 30;

    /**
     * Whether this visitor may have one more of `what` this minute. A signed-in player is counted on his own alone: the
     * address is shared (a carrier's NAT, a campus, a VPN's exit), and one script there used up its room for every
     * player behind it (security N3, 2026-11-14). A guest is counted by session and by address, and a call the session
     * was refused takes nothing of the address's room.
     */
    public static function allows(string $what, int $perMinute): bool
    {
        if (auth()->id() !== null) {
            return RateLimiter::hit("board:{$what}:user:".auth()->id(), 60) <= $perMinute;
        }

        $request = request();
        $session = $request->hasSession() ? $request->session()->getId() : $request->ip();

        return RateLimiter::hit("board:{$what}:session:{$session}", 60) <= $perMinute
            && RateLimiter::hit("board:{$what}:ip:".$request->ip(), 60) <= $perMinute * 10;
    }
}
