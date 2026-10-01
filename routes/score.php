<?php

use App\Http\Controllers\ScoreIngestController;
use Illuminate\Support\Facades\Route;

/*
 * Score games (plan "AoE2 und Trackmania", P4): highscore and time attack
 * leaderboards. Loaded by routes/web.php only while a score game is
 * registered (`esports.score_games`: the demo behind its own switch, off by
 * default), so with none the routes do not exist at all. A cached route
 * table keeps the switch as it was when it was cached: flipping it needs the
 * deploy's `optimize`, like every config change.
 */

// A score game's page: its leaderboards (open, running, finished) and its points ladder. Guests too.
Route::livewire('scores/{game}', 'pages::scores.show')->where('game', '[a-z][a-z0-9-]*')->name('scores.show');

// A leaderboard tournament's values: the table for everyone, the submission form for its players, and the course,
// corrections and the end for its directors and admins.
Route::livewire('tournaments/{tournament}/scores', 'pages::scores.tournament')->whereNumber('tournament')->name('tournaments.scores');

// Manual submissions waiting for an admin.
Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::livewire('scores', 'pages::admin.scores')->name('scores');
});

// Finishes from our own dedicated servers: bearer token per server, JSON, no session.
Route::post('api/scores/ingest', ScoreIngestController::class)->withoutMiddleware('web')->middleware('throttle:score-ingest')->name('scores.ingest');
