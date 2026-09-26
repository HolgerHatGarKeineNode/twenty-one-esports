<?php

use App\Http\Controllers\GeneratedAvatarController;
use App\Http\Controllers\InviteCardController;
use App\Http\Controllers\NostrJsonController;
use App\Http\Controllers\NotifyAtBlockZeroController;
use App\Http\Controllers\PlayerController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SwitchLocaleController;
use App\Livewire\Actions\Logout;
use App\Models\InviteLink;
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
    Route::post('notify/block0', NotifyAtBlockZeroController::class)->name('notify.block0');
});

Route::middleware(['auth', 'admin'])->group(function () {
    Route::view('admin/status', 'pages.coming-soon', ['page' => 'Admin', 'section' => 'admin'])->name('admin.status');
});

/*
 * Clans, lineups and invites (P4). `clans/create` is registered before
 * `clans/{clan}` so "create" is never taken for a clan slug.
 */
Route::middleware('auth')->group(function () {
    Route::livewire('clans/create', 'pages::clans.create')->name('clans.create');
    Route::livewire('clans/{clan}/manage', 'pages::clans.manage')->name('clans.manage');
    Route::livewire('invites/{invite}', 'pages::invites.show')->name('invites.show');
});

/*
 * Invite deep links (P6b): `/i/{code}` for anyone, guests included, so a link
 * shared on Signal lands on a page that works before login. The preview
 * image carries no session (a crawler fetches it) and never sets a cookie.
 */
Route::livewire('i/{link}', 'pages::invites.link')
    ->where('link', InviteLink::CODE_PATTERN)
    ->middleware('throttle:invites')
    ->name('invites.link');
Route::get('i/{code}/card-{format}.png', InviteCardController::class)
    ->where(['code' => InviteLink::CODE_PATTERN, 'format' => 'wide|square'])
    ->withoutMiddleware('web')
    ->middleware('throttle:invites')
    ->name('invites.card');

Route::livewire('clans', 'pages::clans.index')->name('clans.index');
Route::livewire('clans/{clan}', 'pages::clans.show')->name('clans.show');
Route::livewire('games/rocket-league', 'pages::games.rocket-league')->name('games.rocket-league');

// Live chess (P5a): the blitz lobby and one game, live or finished.
Route::livewire('chess', 'pages::chess.lobby')->name('chess.lobby');
Route::livewire('games/{game}', 'pages::games.show')->whereNumber('game')->name('games.show');

// Daily chess (P5b): challenge a player, your daily games.
Route::middleware('auth')->group(function () {
    Route::livewire('chess/challenge', 'pages::chess.challenge')->name('chess.challenge');
    Route::livewire('me/correspondence', 'pages::me.correspondence')->name('me.correspondence');
});

/*
 * Rocket League series (P6a): challenge a lineup, the match list, the public
 * match page and the room of the two lineups. `{match}` is the league match
 * number. The detail page resolves it itself, so an unknown number shows the
 * "not found" state of States.dc.html.
 */
Route::middleware('auth')->group(function () {
    Route::livewire('challenges/create', 'pages::challenges.create')->name('challenges.create');
    Route::livewire('matches/{match}/room', 'pages::matches.room')->name('matches.room');
});

Route::livewire('matches', 'pages::matches.index')->name('matches.index');
Route::livewire('matches/{match}', 'pages::matches.show')->whereNumber('match')->name('matches.show');

/*
 * Placeholder pages for the planned routes (screens-v1.md). Each one renders the
 * shell with a "Coming soon" empty state until its phase builds the real page.
 * `page` is a translation key, `section` marks the active main-navigation item.
 */
$placeholders = [
    ['games', 'games.index', 'Live games', 'chess'],
    ['tournaments', 'tournaments.index', 'Tournaments', 'tournaments'],
    ['ladder/{game}/{mode}', 'ladder.show', 'Ladder', 'ladder'],
    ['rules', 'rules', 'Rules', null],
    ['protocol', 'protocol', 'Open protocol', null],
];

foreach ($placeholders as [$uri, $name, $page, $section]) {
    Route::view($uri, 'pages.coming-soon', ['page' => $page, 'section' => $section])->name($name);
}

/*
 * Nostr profiles of other players (P10a). The browser reads kind 0 from the
 * profile relays and hands the signed events in (resources/js/profiles.js);
 * the player card is fetched when a name is hovered or tapped. The generated
 * avatar is a pure function of the key: no session, cached for a year.
 */
Route::post('profiles', [ProfileController::class, 'store'])->middleware('throttle:profiles')->name('profiles.store');
Route::get('players/{npub}', [PlayerController::class, 'show'])->name('players.show');
Route::get('players/{npub}/card', [PlayerController::class, 'card'])->name('players.card');
Route::get('avatars/{pubkey}.svg', GeneratedAvatarController::class)
    ->where('pubkey', '[0-9a-f]{64}')
    ->withoutMiddleware('web')
    ->name('avatars.generated');

// NIP-05 for esports@esports.einundzwanzig.space; public JSON, no session.
Route::get('.well-known/nostr.json', NostrJsonController::class)
    ->withoutMiddleware('web')
    ->name('nostr.nip05');

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
