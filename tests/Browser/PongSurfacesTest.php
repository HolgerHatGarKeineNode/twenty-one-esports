<?php

use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Games\ProofOfPong;
use App\Models\Admin;
use App\Models\PongMatch;
use App\Models\PongRating;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\Pong\PongInvites;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use App\Support\Tournaments\TournamentBrackets;
use App\Support\Tournaments\TournamentRunner;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;
use Tests\Support\PongOn;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| Proof of Pong on the league's surfaces in the browser (plan "Proof of Pong", P4)
|--------------------------------------------------------------------------
|
| Every surface that took the game up (home's tile and ladder card, /matches, /play, the rules section, the player
| page, the lobby with its ladder link and the latest win, the Elo ladder, the match dock's invite, a tournament's
| "Go to your match"), measured as an admin in German at 390, 768, 1440 and 1920 px: document overflow, the element
| inside the screen, texts cut off without an ellipsis, buttons under 44 px. Every page carries BrowserConsole's
| collector (console errors, uncaught errors, answers >= 400), proved by a positive control. The numbers go to STDERR
| for the report, pictures to PONG_SHOTS when it names a directory.
|
*/

beforeEach(function () {
    Http::fake(fn () => Http::response([]));
    config(['session.driver' => 'database']);

    app()->rebinding('request', function ($app): void {
        $app['session']->forgetDrivers();
        $app->forgetInstance('session.store');
        $app->forgetInstance('auth.driver');
        $app['auth']->forgetGuards();
        $app['livewire']->flushState();
    });

    PongOn::play();
});

const PONG_SURFACE_SIZES = [[390, 844], [768, 1024], [1440, 900], [1920, 1080]];

function pongSurfacePage(User $user): Page
{
    $page = visit(BrowserLogin::url($user))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->context()->addInitScript('window.open = () => null;');
    $page->goto(ComputeUrl::from(route('locale.switch', 'de', false)));

    return $page;
}

function pongSurfaceGo(Page $page, string $path): void
{
    $page->goto(ComputeUrl::from($path));
    BrowserWait::until($page, '() => document.readyState === "complete" && !!window.Livewire', 15_000);
}

/**
 * One element at one size: document overflow, its box, whether it is inside the screen, its texts cut off without an
 * ellipsis, and its button-like links and buttons under 44 px high.
 *
 * @return array<string, mixed>
 */
function pongSurfaceMeasure(Page $page, string $selector, int $width, int $height): array
{
    $page->setViewportSize($width, $height);
    $page->evaluate('() => new Promise((done) => setTimeout(done, 300))');

    return $page->evaluate(<<<JS
        () => {
            const de = document.documentElement;
            // A fixed element has no offsetParent: visible is a box of its own.
            const visible = (x) => { const b = x.getBoundingClientRect(); return b.width > 1 && b.height > 1 && getComputedStyle(x).visibility !== 'hidden'; };
            const el = [...document.querySelectorAll('{$selector}')].find(visible) ?? null;
            el?.scrollIntoView({ block: 'center' });
            const r = el ? el.getBoundingClientRect() : null;
            const cut = el ? [...el.querySelectorAll('b, span, a, button, h1, h2, h3, p, dd, dt, li, small')]
                .filter((x) => visible(x) && !x.classList.contains('sr-only') && x.scrollWidth > x.clientWidth + 1 && getComputedStyle(x).textOverflow !== 'ellipsis' && getComputedStyle(x).overflow !== 'visible')
                .map((x) => (x.dataset.test || x.tagName.toLowerCase()) + ': ' + x.innerText.trim().slice(0, 40)) : ['missing'];
            const small = el ? [el, ...el.querySelectorAll('a, button')]
                .filter((x) => (x.tagName === 'A' || x.tagName === 'BUTTON') && visible(x) && ['flex', 'inline-flex', 'grid', 'block', 'inline-block'].includes(getComputedStyle(x).display))
                .filter((x) => x.getBoundingClientRect().height < 44)
                .map((x) => (x.dataset.test || x.tagName.toLowerCase()) + ' ' + Math.round(x.getBoundingClientRect().height) + 'px: ' + (x.innerText.trim() || x.getAttribute('aria-label') || x.getAttribute('href') || '').slice(0, 40)) : [];
            return {
                size: innerWidth + 'x' + innerHeight,
                scroll: [de.scrollWidth, de.clientWidth],
                box: r ? [Math.round(r.left), Math.round(r.top + scrollY), Math.round(r.width), Math.round(r.height)] : null,
                inside: r ? r.left >= -1 && r.right <= innerWidth + 1 : false,
                clipped: cut,
                small,
            };
        }
        JS);
}

/** A picture of the screen with the element scrolled into view (pongSurfaceMeasure()), only when PONG_SHOTS names a directory. */
function pongSurfaceShot(Page $page, string $name): void
{
    $dir = getenv('PONG_SHOTS');

    if (is_string($dir) && $dir !== '') {
        File::ensureDirectoryExists($dir);
        $page->screenshot(false, $name);
        File::move(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
    }
}

test('every Proof of Pong surface as an admin in German at 390, 768, 1440 and 1920: no overflow, nothing cut off, buttons 44 px, console clean', function () {
    $anna = User::factory()->create(['name' => 'Anna Admin', 'looking_to_play' => PongInvites::LOOKING]);
    Admin::query()->create(['pubkey' => $anna->refresh()->pubkey]);
    $bert = User::factory()->create(['name' => 'Bert Bitcoinerbaron von Hodlhausen']);
    $carl = User::factory()->create(['name' => 'Carl Pleb']);

    // Anna won a live match as Saylor, stands on the Elo ladder, and Carl invited her to the next one.
    PongMatch::factory()->finished(0, [21, 19])->create(['left_id' => $anna->id, 'right_id' => $bert->id, 'state' => [
        'ref' => null, 'speed' => 1, 'seen' => [null, null], 'rematch' => [false, false], 'next' => null, 'version' => 9, 'figures' => ['saylor', 'lagarde'],
    ]]);
    PongRating::query()->create(['user_id' => $anna->id, 'rating' => 1016, 'results' => 1, 'wins' => 1, 'losses' => 0]);
    PongRating::query()->create(['user_id' => $bert->id, 'rating' => 984, 'results' => 1, 'wins' => 0, 'losses' => 1]);
    app(PongInvites::class)->invite($carl, $anna);

    $surfaces = [
        'home tile' => [route('home', absolute: false), '[data-test=play-tile][data-game=proof-of-pong]'],
        'home ladder' => [route('home', absolute: false), '[data-test=ladder-top][data-game=proof-of-pong]'],
        // On a desktop the invite's own tab; on a phone and a tablet the dock is one bar with the most urgent item.
        'dock invite' => [route('home', absolute: false), '[data-test=dock-mobile-bar], [data-dock-tab^=pong-invite-]'],
        'matches row' => [route('matches.index', ['game' => ProofOfPong::SLUG], false), '[data-test=pong-row]'],
        'play card' => [route('play', absolute: false), '[data-test=play-game-proof-of-pong]'],
        'rules section' => [route('rules', absolute: false), '[data-test=doc-section-proof-of-pong]'],
        'profile card' => [route('players.show', $anna->npub, false), '[data-test=player-pong]'],
        'lobby' => [route('pong.index', absolute: false), '[data-test=pong-index]'],
        'lobby win' => [route('pong.index', absolute: false), '[data-test=pong-last-win]'],
        'ladder' => [route('pong.ladder', absolute: false), '[data-test=pong-ladder]'],
    ];

    $page = pongSurfacePage($anna);
    $rows = [];
    $errors = [];
    $at = null;

    foreach ($surfaces as $name => [$path, $selector]) {
        if ($path !== $at) {
            pongSurfaceGo($page, $path);
            $at = $path;
        }

        foreach (PONG_SURFACE_SIZES as [$width, $height]) {
            // The dock is a tab row of its own on a phone; measured at every size like the rest.
            $rows[] = ['surface' => $name, ...pongSurfaceMeasure($page, $selector, $width, $height)];

            if (in_array($width, [390, 1440], true)) {
                pongSurfaceShot($page, 'pong-'.str_replace(' ', '-', $name).'-'.$width);
            }
        }

        $errors[$name] = [...$page->evaluate('() => window.__errors ?? ["collector missing"]'), ...$page->evaluate(BrowserConsole::BAD_RESPONSES)];
    }

    $german = $page->evaluate('() => document.querySelector("[data-test=pong-ladder]").innerText');

    fwrite(STDERR, "\npong surfaces measured: ".json_encode($rows, JSON_UNESCAPED_UNICODE)."\n");

    foreach ($rows as $row) {
        $where = $row['surface'].' '.$row['size'];
        expect($row['box'])->not->toBeNull($where)
            ->and($row['scroll'][0])->toBe($row['scroll'][1], $where)
            ->and($row['inside'])->toBeTrue($where)
            ->and($row['clipped'])->toBe([], $where)
            ->and($row['small'])->toBe([], $where);
    }

    expect($german)->toContain('So wird gezählt', 'Live-Match spielen')
        ->and(array_filter($errors))->toBe([]);

    // Positive control: the collector sees a thrown error and a failed answer on this page.
    $page->evaluate('() => { setTimeout(() => { throw new Error("pong p4 positive control"); }); fetch("/proof-of-pong/m/0"); }');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("pong p4 positive control")) && window.__errors.some((e) => e.startsWith("404 "))', 5_000);
});

test('a Proof of Pong tournament sends its player to the live match: "Go to your match" at 390, 768, 1440 and 1920, console clean', function () {
    config(['esports.pong.tournaments' => true]);
    $anna = User::factory()->create(['name' => 'Anna Admin']);
    Admin::query()->create(['pubkey' => $anna->refresh()->pubkey]);
    $tournament = Tournament::factory()->create([
        'name' => 'Proof of Pong Abendturnier', 'game' => ProofOfPong::SLUG, 'mode' => 'live', 'format' => TournamentFormat::SingleElimination,
        'options' => FormatOptions::fromArray([], GameProfile::for(ProofOfPong::SLUG, 'live'))->toArray(), 'capacity' => 2, 'checkin_minutes' => 5,
        'results_mode' => TournamentResultsMode::Players, 'status' => TournamentStatus::Running, 'published_at' => now(), 'ladder_address' => null,
    ]);

    foreach ([$anna, User::factory()->create(['name' => 'Bert Pleb'])] as $index => $user) {
        TournamentParticipant::query()->create(['tournament_id' => $tournament->id, 'user_id' => $user->id, 'name' => $user->name, 'rating' => 1500 - $index, 'members' => [$user->id]]);
    }

    app(TournamentBrackets::class)->generate($tournament, str_repeat('ab', 32));
    app(TournamentRunner::class)->sync($tournament);
    $match = PongMatch::query()->sole();

    $page = pongSurfacePage($anna);
    pongSurfaceGo($page, route('tournaments.show', $tournament, false));
    $rows = [];

    foreach (PONG_SURFACE_SIZES as [$width, $height]) {
        $rows[] = pongSurfaceMeasure($page, '[data-test=now-hero]', $width, $height);

        if (in_array($width, [390, 1440], true)) {
            pongSurfaceShot($page, 'pong-tournament-now-'.$width);
        }
    }

    $href = $page->evaluate('() => [...document.querySelectorAll("[data-test=now-hero] a")].map((a) => [a.getAttribute("href"), a.target, a.innerText.trim()])');
    $errors = [...$page->evaluate('() => window.__errors ?? ["collector missing"]'), ...$page->evaluate(BrowserConsole::BAD_RESPONSES)];

    fwrite(STDERR, "\npong tournament measured: ".json_encode($rows, JSON_UNESCAPED_UNICODE)."\nlinks: ".json_encode($href, JSON_UNESCAPED_UNICODE)."\n");

    foreach ($rows as $row) {
        expect($row['scroll'][0])->toBe($row['scroll'][1], $row['size'])
            ->and($row['clipped'])->toBe([], $row['size'])
            ->and($row['small'])->toBe([], $row['size']);
    }

    expect($href)->toContain([route('pong.match', $match), '_blank', 'Zu deinem Match'])
        ->and($errors)->toBe([]);
});
