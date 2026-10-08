<?php

use App\Http\Controllers\HyperMatchController;
use Illuminate\Support\Facades\Route;

/*
 * Hyperbitcoinization (plan "Hyperbitcoinization", P2): a live match's page, its snapshot and event catch-up
 * (players, spectators and guests alike), and for a seated player the actions, emotes and leaving; plus the
 * start page with a quick start against bots until the lobby comes (P3), and the table chat's name lookup.
 * Loaded by routes/web.php only while `esports.hyper.enabled` is on, so with the switch off none of these
 * routes exists. A cached route table keeps the switch as it was when it was cached: flipping it needs the
 * deploy's `optimize`.
 */
Route::prefix('hyperbitcoinization')->name('hyper.')->group(function () {
    Route::get('/', [HyperMatchController::class, 'index'])->name('index');
    // The table chat's names (league accounts only); public like the game channels' lookup.
    Route::get('people', [HyperMatchController::class, 'people'])->middleware('throttle:60,1')->name('people');
    Route::get('m/{match}', [HyperMatchController::class, 'show'])->whereUlid('match')->name('match');
    Route::get('m/{match}/snapshot', [HyperMatchController::class, 'snapshot'])->whereUlid('match')->middleware('throttle:120,1')->name('snapshot');
    Route::get('m/{match}/events', [HyperMatchController::class, 'events'])->whereUlid('match')->middleware('throttle:120,1')->name('events');

    Route::middleware('auth')->group(function () {
        Route::post('matches', [HyperMatchController::class, 'quick'])->middleware('throttle:10,1')->name('quick');
        Route::post('m/{match}/act', [HyperMatchController::class, 'act'])->whereUlid('match')->name('act');
        Route::post('m/{match}/emote', [HyperMatchController::class, 'emote'])->whereUlid('match')->name('emote');
        Route::post('m/{match}/leave', [HyperMatchController::class, 'leave'])->whereUlid('match')->name('leave');
    });
});
