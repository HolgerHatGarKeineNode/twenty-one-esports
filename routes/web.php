<?php

use App\Http\Controllers\BadgeImageController;
use App\Http\Controllers\GeneratedAvatarController;
use App\Http\Controllers\InviteCardController;
use App\Http\Controllers\NostrJsonController;
use App\Http\Controllers\NotificationDmOptOutController;
use App\Http\Controllers\NotifyAtBlockZeroController;
use App\Http\Controllers\PlayerController;
use App\Http\Controllers\PlayerSearchController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RobotsController;
use App\Http\Controllers\ShareCardController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\SwitchLocaleController;
use App\Livewire\Actions\Logout;
use App\Models\InviteLink;
use App\Support\Seo\Sitemap;
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

/*
 * "Turn off these DMs" (the last line of every notification DM): signed,
 * no login, since the recipient may never have logged in. The link opens
 * the page; only its POST turns anything off (a link preview must not).
 */
Route::middleware('signed:relative')->group(function () {
    Route::get('notifications/dm/{user}', [NotificationDmOptOutController::class, 'show'])->whereNumber('user')->name('notifications.dm-off');
    Route::post('notifications/dm/{user}', [NotificationDmOptOutController::class, 'store'])->whereNumber('user')->name('notifications.dm-off.store');
});

Route::livewire('clans', 'pages::clans.index')->name('clans.index');
Route::livewire('clans/{clan}', 'pages::clans.show')->name('clans.show');
Route::livewire('games/rocket-league', 'pages::games.rocket-league')->name('games.rocket-league');

// Live chess (P5a): the blitz lobby and one game, live or finished.
Route::livewire('chess', 'pages::chess.lobby')->name('chess.lobby');
// Every live chess game, for guests too (P10, spectating).
Route::livewire('games', 'pages::games.index')->name('games.index');
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

// Ladders (P7b): the rated season ladder and the casual ladder of a game and mode.
Route::livewire('ladder/{game}/{mode}', 'pages::ladder.show')->name('ladder.show');

// The season chain (P7c): tip, supply, eras, blocks and rules; the rest state before Block 0.
Route::livewire('mining', 'pages::mining')->name('mining');

Route::livewire('matches', 'pages::matches.index')->name('matches.index');
Route::livewire('matches/{match}', 'pages::matches.show')->whereNumber('match')->name('matches.show');

/*
 * Tournaments (P8b): the list, one tournament with its bracket, the draw,
 * sign-up (logged in), and the director desk (the tournament's directors:
 * gate `direct-tournament`, checked again in every action).
 */
Route::livewire('tournaments', 'pages::tournaments.index')->name('tournaments.index');
Route::livewire('tournaments/{tournament}', 'pages::tournaments.show')->whereNumber('tournament')->name('tournaments.show');
Route::livewire('tournaments/{tournament}/draw', 'pages::tournaments.draw')->whereNumber('tournament')->name('tournaments.draw');
Route::middleware('auth')->group(function () {
    Route::livewire('tournaments/{tournament}/signup', 'pages::tournaments.signup')->whereNumber('tournament')->name('tournaments.signup');
    Route::livewire('tournaments/{tournament}/director', 'pages::tournaments.director')->whereNumber('tournament')
        ->middleware('can:direct-tournament,tournament')->name('tournaments.director');
});

/*
 * Placeholder pages for the planned routes (screens-v1.md). Each one renders the
 * shell with a "Coming soon" empty state until its phase builds the real page.
 * `page` is a translation key, `section` marks the active main-navigation item.
 */
$placeholders = [
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
// The player picker (<x-player-picker>): suggestions for logged-in players only.
Route::get('search/players', PlayerSearchController::class)->middleware(['auth', 'throttle:player-search'])->name('players.search');
Route::get('players/{npub}', [PlayerController::class, 'show'])->name('players.show');
Route::get('players/{npub}/card', [PlayerController::class, 'card'])->name('players.card');
Route::get('avatars/{pubkey}.svg', GeneratedAvatarController::class)
    ->where('pubkey', '[0-9a-f]{64}')
    ->withoutMiddleware('web')
    ->name('avatars.generated');

/*
 * Rank badge artwork (P11, NIP "Image URL per rank"): one URL per game, tier
 * and artwork version, the `image` (1024) and `thumb` (256) of the NIP-58
 * definitions. Public, no session: Nostr clients fetch them.
 */
Route::get('badges/rank/{game}/{tier}-v{artwork}.png', BadgeImageController::class)
    ->where(['game' => '[a-z0-9-]+', 'tier' => '[a-z]+(?:-[a-z]+)*-[123]|provisional', 'artwork' => '[0-9]+'])
    ->withoutMiddleware('web')
    ->name('badges.rank');
Route::get('badges/rank/{game}/{tier}-v{artwork}-{size}.png', BadgeImageController::class)
    ->where(['game' => '[a-z0-9-]+', 'tier' => '[a-z]+(?:-[a-z]+)*-[123]', 'artwork' => '[0-9]+', 'size' => '256'])
    ->withoutMiddleware('web')
    ->name('badges.rank.thumb');

/*
 * Share cards (P11): PNG link previews (`wide`, 1200 × 630) and stories
 * (`story`, 1080 × 1920) of a rank up, a mined block, a tournament win and a
 * player's season. Public and without a session, like the invite card: the
 * share posts on Nostr link them.
 */
Route::prefix('cards/{locale}')
    // One where(): a group's whereIn() does not reach its routes (measured: /cards/fr/… matched).
    ->where(['locale' => implode('|', config('app.supported_locales')), 'format' => 'wide|story'])
    ->withoutMiddleware('web')
    ->middleware('throttle:invites')
    ->group(function () {
        Route::get('rank-up/{version}-{format}.png', [ShareCardController::class, 'rankUp'])->whereNumber('version')->name('cards.rank-up');
        Route::get('block/{block}/{npub}-{format}.png', [ShareCardController::class, 'block'])->whereNumber('block')->where('npub', 'npub1[0-9a-z]+')->name('cards.block');
        Route::get('tournament/{finished}-{format}.png', [ShareCardController::class, 'tournament'])->whereNumber('finished')->name('cards.tournament');
        Route::get('wrapped/{season}/{npub}-{format}.png', [ShareCardController::class, 'wrapped'])->where(['season' => '[a-z0-9-]+', 'npub' => 'npub1[0-9a-z]+'])->name('cards.wrapped');
    });

// NIP-05 for esports@esports.einundzwanzig.space; public JSON, no session.
Route::get('.well-known/nostr.json', NostrJsonController::class)
    ->withoutMiddleware('web')
    ->name('nostr.nip05');

// Search engines (P14): robots.txt and the sitemap; public, no session.
Route::get('robots.txt', RobotsController::class)
    ->withoutMiddleware('web')
    ->name('robots');
Route::get('sitemap.xml', [SitemapController::class, 'index'])
    ->withoutMiddleware('web')
    ->name('sitemap');
Route::get('sitemaps/{section}-{file}.xml', [SitemapController::class, 'show'])
    ->whereIn('section', Sitemap::SECTIONS)
    ->where('file', '[1-9][0-9]{0,5}')
    ->withoutMiddleware('web')
    ->name('sitemap.section');

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
