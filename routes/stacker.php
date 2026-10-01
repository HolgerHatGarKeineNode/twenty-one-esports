<?php

use App\Http\Controllers\StackerRunController;
use App\Support\Stacker\StackerRuns;
use Illuminate\Support\Facades\Route;

/*
 * Blockfill (plan "Blockfill"): the game page (P3), and its runs (P2):
 * issue a run (one-time token and seed), mark its start, submit its replay
 * for the verifier, read its status (App\Support\Stacker\StackerRuns). Loaded by routes/web.php only while
 * `esports.blockfill.enabled` is on, so with the switch off none of these
 * routes exists. A cached route table keeps the switch as it was when it was
 * cached: flipping it needs the deploy's `optimize`, like every config change.
 */
// The game page (P3): practice for everyone, guests included; ranked runs need a login (the routes below).
Route::livewire('blockfill', 'pages::stacker.play')->name('stacker.play');
// Every replay the viewer may watch in one place: their own, an ended week's first ten, for admins the held ones
// (StackerReplays::ofPlayer(), top(), held()). Public; `?player=<npub>` narrows it to one player's.
Route::livewire('blockfill/replays', 'pages::stacker.replays')->name('stacker.replays');
// P5: the replay of one run, for its player, admins (runs with cheat hints) and everybody once its week has ended
// among the board's first ten (StackerReplays::canView(), checked in the page).
Route::livewire('blockfill/replays/{run}', 'pages::stacker.replay')->whereNumber('run')->name('stacker.replay');

// P5: runs held for cheat hints, for an admin's look (approve or reject).
Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::livewire('blockfill', 'pages::admin.blockfill')->name('blockfill');
});

Route::middleware('auth')->group(function () {
    Route::post('stacker/runs', [StackerRunController::class, 'issue'])->middleware('throttle:stacker-issue')->name('stacker.runs.issue');
    Route::post('stacker/runs/{token}/start', [StackerRunController::class, 'start'])->where('token', '[A-Za-z0-9]{'.StackerRuns::TOKEN_LENGTH.'}')->middleware('throttle:stacker-submit')->name('stacker.runs.start');
    Route::get('stacker/runs/{token}', [StackerRunController::class, 'show'])->where('token', '[A-Za-z0-9]{'.StackerRuns::TOKEN_LENGTH.'}')->middleware('throttle:stacker-status')->name('stacker.runs.show');
    Route::post('stacker/runs/{token}', [StackerRunController::class, 'submit'])->where('token', '[A-Za-z0-9]{'.StackerRuns::TOKEN_LENGTH.'}')->middleware('throttle:stacker-submit')->name('stacker.runs.submit');
});

// A player's Blockfill moment (a personal best, a new first place of the week, a week place): the page a share post links last; its card is cards.blockfill.
Route::livewire('scores/blockfill/moment/{run}', 'pages::stacker.moment')->where('run', '[0-9]{1,18}')->name('stacker.moment');
