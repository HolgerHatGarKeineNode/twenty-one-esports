<?php

use App\Http\Controllers\SwitchLocaleController;
use App\Livewire\Actions\Logout;
use Illuminate\Support\Facades\Route;

Route::view('/', 'pages.home')->name('home');

Route::get('locale/{locale}', SwitchLocaleController::class)
    ->whereIn('locale', config('app.supported_locales'))
    ->name('locale.switch');

Route::middleware('guest')->group(function () {
    Route::view('login', 'pages.auth.login')->name('login');
});

Route::middleware('auth')->group(function () {
    Route::view('me', 'pages.coming-soon', ['page' => 'Your page', 'section' => null])->name('dashboard');
    Route::post('logout', Logout::class)->name('logout');
});

Route::middleware(['auth', 'admin'])->group(function () {
    Route::view('admin/status', 'pages.coming-soon', ['page' => 'Admin', 'section' => 'admin'])->name('admin.status');
});

/*
 * Placeholder pages for the planned routes (screens-v1.md). Each one renders the
 * shell with a "Coming soon" empty state until its phase builds the real page.
 * `page` is a translation key, `section` marks the active main-navigation item.
 */
$placeholders = [
    ['chess', 'chess.lobby', 'Chess', 'chess'],
    ['chess/challenge', 'chess.challenge', 'Challenge a friend', 'chess'],
    ['games', 'games.index', 'Live games', 'chess'],
    ['games/rocket-league', 'games.rocket-league', 'Rocket League', null],
    ['games/{game}', 'games.show', 'Game', 'chess'],
    ['matches', 'matches.index', 'Matches', 'matches'],
    ['matches/{match}', 'matches.show', 'Match', 'matches'],
    ['tournaments', 'tournaments.index', 'Tournaments', 'tournaments'],
    ['ladder/{game}/{mode}', 'ladder.show', 'Ladder', 'ladder'],
    ['clans', 'clans.index', 'Clans', 'clans'],
    ['clans/{clan}', 'clans.show', 'Clan', 'clans'],
    ['players/{npub}', 'players.show', 'Player', null],
    ['challenges/create', 'challenges.create', 'New challenge', null],
    ['rules', 'rules', 'Rules', null],
    ['protocol', 'protocol', 'Open protocol', null],
];

foreach ($placeholders as [$uri, $name, $page, $section]) {
    Route::view($uri, 'pages.coming-soon', ['page' => $page, 'section' => $section])->name($name);
}

Route::get('styleguide', function () {
    abort_unless(app()->environment(['local', 'testing']), 404);

    return view('pages.styleguide');
})->name('styleguide');

require __DIR__.'/settings.php';

// Nostr login endpoints and admin area (P3).
require __DIR__.'/auth.php';
require __DIR__.'/admin.php';

// Fixtures for tests/Browser only; never registered outside the testing
// environment (each route in routes/testing.php checks it again).
if (app()->environment('testing')) {
    require __DIR__.'/testing.php';
}

/*
 * Unknown URLs still pass the web middleware (session, locale), so the 404 page
 * speaks the visitor's language and knows who is logged in.
 */
Route::fallback(fn () => abort(404));
