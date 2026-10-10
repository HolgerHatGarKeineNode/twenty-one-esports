<?php

use App\Http\Controllers\BroadcastStyleguideController;
use App\Http\Controllers\DisputeEvidenceController;
use App\Http\Controllers\SiteModerationController;
use Illuminate\Support\Facades\Route;

/*
| Admin area: board npubs from config/esports.php plus admins from the
| `admins` table (Gate `admin`, middleware alias `admin`).
*/

Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    // The admin home (P17): what the league, the tournaments and the servers are doing, read-only.
    Route::livewire('status', 'pages::admin.status')->name('status');

    Route::livewire('admins', 'pages::admin.admins')->name('admins');
    // Organizers (P8a): who may create tournaments; own page since P17.
    Route::livewire('organizers', 'pages::admin.organizers')->name('organizers');

    // The season chain (P7c): status, estimator, Block 0 release, rule changes.
    Route::livewire('season', 'pages::admin.season')->name('season');

    // Weekly events (P10): recurring slots; the scheduler dates them (events:schedule-weekly).
    Route::livewire('events', 'pages::admin.events')->name('events');

    // League settings (P44): the operational values an admin changes, with their log.
    Route::livewire('settings', 'pages::admin.settings')->name('settings');

    // League weeks: the next Blockfill and TMNF week, its settings and the approval it needs to start.
    Route::livewire('league-weeks', 'pages::admin.league-weeks')->name('league-weeks');

    // Trust (P7d): the reports the trust job read, dismissals and exclusions.
    Route::livewire('trust', 'pages::admin.trust')->name('trust');

    // Fair play (P41): linked accounts of one person, and players locked after confirmed false reports.
    Route::livewire('fair-play', 'pages::admin.fair-play')->name('fair-play');

    // Site-wide mutes and bans of a Nostr key: the list with Undo, and the moderation menu on every chat message.
    Route::livewire('moderation', 'pages::admin.moderation')->name('moderation');
    Route::post('moderation', SiteModerationController::class)->middleware('throttle:60,1')->name('moderation.store');

    // NIP-05 names (P47): the names players claimed on the league's domain, and revoking one.
    Route::livewire('nip05', 'pages::admin.nip05')->name('nip05');

    // Tournament payouts (P9): the admin check at the end and the payments; `?tournament=<id>`.
    Route::livewire('payouts', 'pages::admin.payouts')->name('payouts');

    // Series disputes and no-shows (P6a); `{match}` is the league match number.
    Route::livewire('disputes', 'pages::admin.disputes')->name('disputes');
    Route::livewire('disputes/{match}', 'pages::admin.dispute')->name('disputes.show');
    Route::get('disputes/{match}/evidence/{evidence}', DisputeEvidenceController::class)->name('disputes.evidence');
});

/*
| The broadcast design system (plan "OBS-Broadcast-Overlays", P1): the OBS overlays' type, colour, motion and tempo
| with the engine running live. Admins only; not under admin/ because it is its own full-screen document.
*/
Route::middleware(['auth', 'admin'])->get('broadcast/styleguide', BroadcastStyleguideController::class)->name('broadcast.styleguide');

/*
| Tournaments (P8a): admins and the organizers an admin unlocked. The list
| shows an organizer their own tournaments; the organizer list itself is for
| admins only (checked in the page). Editing checks `manage-tournament`.
*/
Route::middleware(['auth', 'can:create-tournaments'])->prefix('admin')->name('admin.')->group(function () {
    Route::livewire('tournaments', 'pages::admin.tournaments')->name('tournaments');
    Route::livewire('tournaments/create', 'pages::admin.tournament-create')->name('tournaments.create');
    // Editing and moderating: an admin on every tournament, an organizer on their own.
    Route::livewire('tournaments/{tournament}/edit', 'pages::admin.tournament-edit')->whereNumber('tournament')
        ->middleware('can:manage-tournament,tournament')->name('tournaments.edit');
});
