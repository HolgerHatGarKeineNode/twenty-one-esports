<?php

namespace App\Support\Chess;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Sends a broadcast once the surrounding transaction has committed (right
 * away if there is none), so no client hears about a game before it can be
 * read, and never lets a websocket problem undo game state: the database is
 * already the truth, and a client that missed the push catches up from the
 * server (reconnect snapshot, polling fallback). So a failing Reverb is
 * reported and swallowed (fail-open for the push, never for the game).
 *
 * Dispatched through the event bus (not Broadcast::queue()), so tests see it
 * with Event::fake().
 */
final class Broadcasts
{
    public static function send(object $event): void
    {
        DB::afterCommit(function () use ($event): void {
            try {
                event($event);
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }
}
