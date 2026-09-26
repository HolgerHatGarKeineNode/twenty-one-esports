<?php

use App\Http\Controllers\DisputeEvidenceController;
use Illuminate\Support\Facades\Route;

/*
| Admin area: board npubs from config/esports.php plus admins from the
| `admins` table (Gate `admin`, middleware alias `admin`).
*/

Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::livewire('admins', 'pages::admin.admins')->name('admins');

    // The season chain (P7c): status, estimator, Block 0 release, rule changes.
    Route::livewire('season', 'pages::admin.season')->name('season');

    // Weekly events (P10): recurring slots; the scheduler dates them (events:schedule-weekly).
    Route::livewire('events', 'pages::admin.events')->name('events');

    // Trust (P7d): the reports the trust job read, dismissals and exclusions.
    Route::livewire('trust', 'pages::admin.trust')->name('trust');

    // Series disputes and no-shows (P6a); `{match}` is the league match number.
    Route::livewire('disputes', 'pages::admin.disputes')->name('disputes');
    Route::livewire('disputes/{match}', 'pages::admin.dispute')->name('disputes.show');
    Route::get('disputes/{match}/evidence/{evidence}', DisputeEvidenceController::class)->name('disputes.evidence');
});

/*
| Tournaments (P8a): admins and the organizers an admin unlocked. The list
| shows an organizer their own tournaments; the organizer list itself is for
| admins only (checked in the page).
*/
Route::middleware(['auth', 'can:create-tournaments'])->prefix('admin')->name('admin.')->group(function () {
    Route::livewire('tournaments', 'pages::admin.tournaments')->name('tournaments');
    Route::livewire('tournaments/create', 'pages::admin.tournament-create')->name('tournaments.create');
});
