<?php

use App\Games\GameRegistry;
use App\Http\Controllers\BadgeImageController;
use App\Http\Controllers\GeneratedAvatarController;
use App\Http\Controllers\InviteCardController;
use App\Http\Controllers\LiveStatusController;
use App\Http\Controllers\LnurlPayController;
use App\Http\Controllers\NostrJsonController;
use App\Http\Controllers\NotificationDmOptOutController;
use App\Http\Controllers\NotifyAtBlockZeroController;
use App\Http\Controllers\PageCardController;
use App\Http\Controllers\PlayerController;
use App\Http\Controllers\PlayerSearchController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RobotsController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\ShareCardController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\StreamCoverController;
use App\Http\Controllers\StreamPrideImageController;
use App\Http\Controllers\SwitchLocaleController;
use App\Http\Controllers\TournamentCalendarController;
use App\Livewire\Actions\Logout;
use App\Models\InviteLink;
use App\Support\Cards\PageCard;
use App\Support\Seo\Sitemap;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::view('/', 'pages.home')->name('home');

Route::get('locale/{locale}', SwitchLocaleController::class)
    ->whereIn('locale', config('app.supported_locales'))
    ->name('locale.switch');

Route::middleware('guest')->group(function () {
    // "Start playing" (the header, for guests) logs in and then opens the chess lobby: `then=play` sets where the login lands.
    // "Log in to chat" on /live comes back to the stream: `then=live`.
    Route::get('login', function (Request $request) {
        $query = $request->query('then');
        $then = is_string($query) ? (['play' => 'chess.lobby', 'live' => 'live'][$query] ?? null) : null;

        if ($then !== null) {
            $request->session()->put('url.intended', route($then));
        }

        return view('pages.auth.login');
    })->name('login');
});

// Every registered game with its cover, modes and actions ("All games and modes", the game hub's last link). Public.
Route::view('play', 'pages.play')->name('play');

Route::middleware('auth')->group(function () {
    // The player's own hub (P30): what needs them, what runs, where they stand. The header's "Your page".
    Route::livewire('me', 'pages::me.hub')->name('dashboard');
    Route::post('logout', Logout::class)->name('logout');
    Route::post('notify/block0', NotifyAtBlockZeroController::class)->name('notify.block0');
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
// The overview of a series game (Rocket League, EA Sports FC), one page per registry entry.
Route::livewire('games/rocket-league', 'pages::games.series')->defaults('slug', 'rocket-league')->name('games.rocket-league');
Route::livewire('games/{slug}', 'pages::games.series')->whereIn('slug', array_keys(app(GameRegistry::class)->series()))->name('games.series');

// Live chess (P5a): the blitz lobby and one game, live or finished.
Route::livewire('chess', 'pages::chess.lobby')->name('chess.lobby');
// Every live chess game, for guests too (P10, spectating).
Route::livewire('games', 'pages::games.index')->name('games.index');
Route::livewire('games/{game}', 'pages::games.show')->whereNumber('game')->name('games.show');
// The 24/7 stream (P20): the big player, what is on it, the zap QR code. nginx serves the HLS files under /live/, not /live.
Route::livewire('live', 'pages::live')->name('live');
// Its status for the page's poller (P20b): JSON, public, no session. Not under /live/, which nginx serves from hls_dir.
Route::get('stream/status', LiveStatusController::class)->withoutMiddleware('web')->middleware('throttle:live-status')->name('stream.status');
// Its 30311 picture (StreamCover), the slide the daemon last rendered; public, no session.
Route::get('stream/cover.png', StreamCoverController::class)->withoutMiddleware('web')->name('stream.cover');
// The slides the stream bot's pride notes carry (PrideNotes), by their content hash; public, no session.
Route::get('stream/pride/{hash}.png', StreamPrideImageController::class)->where('hash', '[0-9a-f]{64}')->withoutMiddleware('web')->name('stream.pride-image');

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
    // A scheduled casual 1v1 without a clan (P23 S4).
    Route::livewire('challenges/casual', 'pages::challenges.casual')->name('challenges.casual');
    Route::livewire('matches/{match}/room', 'pages::matches.room')->name('matches.room');
});

// Ladders (P7b): the rated season ladder and the casual ladder of a game and mode.
// The strongest players across every game (P40): the Global Rating of the live season.
Route::view('ladder/strongest', 'pages.ladder.strongest')->name('ladder.strongest');
Route::livewire('ladder/{game}/{mode}', 'pages::ladder.show')->name('ladder.show');

// The season chain (P7c): tip, supply, eras, blocks and rules; the rest state before Block 0.
Route::livewire('mining', 'pages::mining')->name('mining');

Route::livewire('matches', 'pages::matches.index')->name('matches.index');
Route::livewire('matches/{match}', 'pages::matches.show')->whereNumber('match')->name('matches.show');

/*
 * Tournaments (P8b): the list, one tournament with its bracket, the draw,
 * sign-up (logged in), the director desk (the tournament's directors:
 * gate `direct-tournament`, checked again in every action), and the prize
 * pool settings (P9: its organizer and admins, gate `manage-tournament`).
 */
Route::livewire('tournaments', 'pages::tournaments.index')->name('tournaments.index');
Route::livewire('tournaments/{tournament}', 'pages::tournaments.show')->whereNumber('tournament')->name('tournaments.show');
Route::livewire('tournaments/{tournament}/draw', 'pages::tournaments.draw')->whereNumber('tournament')->name('tournaments.draw');
Route::get('tournaments/{tournament}/calendar.ics', TournamentCalendarController::class)->whereNumber('tournament')->name('tournaments.calendar');
// The TV view (P19): full screen, no site around it, for a big screen or a stream.
Route::livewire('tournaments/{tournament}/tv', 'pages::tournaments.tv')->whereNumber('tournament')->name('tournaments.tv');
Route::middleware('auth')->group(function () {
    Route::livewire('tournaments/{tournament}/signup', 'pages::tournaments.signup')->whereNumber('tournament')->name('tournaments.signup');
    Route::livewire('tournaments/{tournament}/pool', 'pages::tournaments.pool')->whereNumber('tournament')
        ->middleware('can:manage-tournament,tournament')->name('tournaments.pool');
    Route::livewire('tournaments/{tournament}/director', 'pages::tournaments.director')->whereNumber('tournament')
        ->middleware('can:direct-tournament,tournament')->name('tournaments.director');
});

// The rules (P28) and the open protocol (P29): every number and kind read from the config, the code and the NIP at render time.
Route::view('rules', 'pages.rules')->name('rules');
Route::view('protocol', 'pages.protocol')->name('protocol');

/*
 * Nostr profiles of other players (P10a). The browser reads kind 0 from the
 * profile relays and hands the signed events in (resources/js/profiles.js);
 * the player card is fetched when a name is hovered or tapped. The generated
 * avatar is a pure function of the key: no session, cached for a year.
 */
Route::post('profiles', [ProfileController::class, 'store'])->middleware('throttle:profiles')->name('profiles.store');
// The player picker (<x-player-picker>): suggestions for logged-in players only.
// The site search (P16): the header's search field, for guests too; noindex, throttled per IP.
Route::get('search', SearchController::class)->middleware('throttle:search')->name('search');
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
    ->where(['game' => '[a-z0-9-]{1,40}', 'tier' => '[a-z]+(?:-[a-z]+)*-[123]|provisional', 'artwork' => '[0-9]{1,6}'])
    ->withoutMiddleware('web')
    ->middleware('throttle:cards')
    ->name('badges.rank');
Route::get('badges/rank/{game}/{tier}-v{artwork}-{size}.png', BadgeImageController::class)
    ->where(['game' => '[a-z0-9-]{1,40}', 'tier' => '[a-z]+(?:-[a-z]+)*-[123]', 'artwork' => '[0-9]{1,6}', 'size' => '256'])
    ->withoutMiddleware('web')
    ->middleware('throttle:cards')
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
    ->middleware('throttle:cards')
    ->group(function () {
        // Ids capped at 18 digits: a longer one would not fit an int and must be a 404, not a 500.
        Route::get('rank-up/{version}-{format}.png', [ShareCardController::class, 'rankUp'])->where('version', '[0-9]{1,18}')->name('cards.rank-up');
        Route::get('block/{block}/{npub}-{format}.png', [ShareCardController::class, 'block'])->where(['block' => '[0-9]{1,18}', 'npub' => 'npub1[0-9a-z]{58}'])->name('cards.block');
        Route::get('tournament/{finished}-{format}.png', [ShareCardController::class, 'tournament'])->where('finished', '[0-9]{1,18}')->name('cards.tournament');
        Route::get('tournament-invite/{tournament}-{format}.png', [ShareCardController::class, 'tournamentInvite'])->where('tournament', '[0-9]{1,18}')->name('cards.tournament-invite');
        Route::get('wrapped/{season}/{npub}-{format}.png', [ShareCardController::class, 'wrapped'])->where(['season' => '[a-z0-9-]{1,64}', 'npub' => 'npub1[0-9a-z]{58}'])->name('cards.wrapped');
        // The link preview of every public page (P54), one per page and state: 1200 × 630.
        Route::get('page/{type}/{key}.png', PageCardController::class)
            ->where(['type' => implode('|', PageCard::TYPES), 'key' => '[a-z0-9][a-z0-9._-]{0,99}'])
            ->name('cards.page');
    });

// NIP-05 for esports@esports.einundzwanzig.space and the names players claimed (P47); public JSON, no session.
Route::get('.well-known/nostr.json', NostrJsonController::class)
    ->withoutMiddleware('web')
    ->name('nostr.nip05');

// The league's Lightning address pool@<host> (P9, LUD-06/16 with NIP-57 zaps); public JSON, no session.
Route::withoutMiddleware('web')->middleware('throttle:invoices')->group(function () {
    Route::get('.well-known/lnurlp/{username}', [LnurlPayController::class, 'metadata'])->where('username', '[a-z0-9._-]{1,64}')->name('lnurl.pay');
    Route::get('lnurlp/{username}/callback', [LnurlPayController::class, 'callback'])->where('username', '[a-z0-9._-]{1,64}')->name('lnurl.callback');
});

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
