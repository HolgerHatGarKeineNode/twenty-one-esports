<?php

use App\Enums\ChessGameStatus;
use App\Models\ChessGame;
use App\Support\Chess\ChessGameService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Safety net for chess clocks: every live game whose deadline passed is
 * checked, in case the delayed App\Jobs\CheckChessClock did not run (worker
 * down, queue backed up). Only the server clock decides, so running it often
 * is harmless.
 */
Artisan::command('chess:check-clocks', function (ChessGameService $games) {
    $due = ChessGame::query()
        ->where('status', ChessGameStatus::Active)
        ->where('deadline_ms', '<=', (int) now()->getTimestampMs())
        ->get();

    foreach ($due as $game) {
        $games->checkClock($game);
    }

    $this->info("Checked {$due->count()} game(s).");
})->purpose('End live chess games whose clock ran out');

Schedule::command('chess:check-clocks')->everyTenSeconds()->withoutOverlapping();
