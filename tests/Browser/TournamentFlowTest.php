<?php

use App\Enums\TournamentFormat;
use App\Models\Clan;
use App\Models\Lineup;
use App\Models\TournamentMatch;
use App\Models\TournamentSignup;
use App\Models\User;
use App\Support\Tournaments\TournamentRunner;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;
use Tests\Support\TestSigner;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| Tournaments in the browser (P8b)
|--------------------------------------------------------------------------
|
| Sign-up signed through window.nostr at 375 px (a solo player) and 1440 px
| (a captain entering a lineup), and the tournament page of a running
| director tournament (bracket, director marker) at both widths.
|
| Collected on every page: console.error/warn, uncaught errors, rejected
| promises, fetch and XHR >= 400, and horizontal overflow; a thrown error
| injected at the end proves the collector sees one. P8B_SHOTS=<dir> writes
| the English screenshots there.
|
*/

const TOURNAMENT_COLLECTOR = <<<'JS'
    window.__errors = [];
    const push = (entry) => window.__errors.push(entry);
    for (const level of ['error', 'warn']) {
        const original = console[level];
        console[level] = function (...args) { push('console.' + level + ': ' + args.map(String).join(' ')); original.apply(console, args); };
    }
    window.addEventListener('error', (e) => push('error: ' + (e.message || 'unknown')));
    window.addEventListener('unhandledrejection', (e) => push('unhandledrejection: ' + String(e.reason)));
    const originalFetch = window.fetch;
    window.fetch = (...args) => originalFetch(...args).then((r) => { if (r.status >= 400) push(r.status + ' ' + r.url); return r; });
    const originalOpen = XMLHttpRequest.prototype.open;
    XMLHttpRequest.prototype.open = function (method, url, ...rest) {
        this.addEventListener('loadend', () => { if (this.status >= 400) push('xhr ' + this.status + ' ' + url); });
        return originalOpen.call(this, method, url, ...rest);
    };
    JS;

const TOURNAMENT_STATE = <<<'JS'
    () => ({
        overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
        errors: window.__errors ?? ['collector missing'],
    })
    JS;

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

    $league = new TestSigner;
    config(['esports.league.nsec' => $league->secret]);
});

function tournamentShot(Page $page, string $name): void
{
    $dir = getenv('P8B_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    File::ensureDirectoryExists($dir);
    $page->screenshot(true, $name);
    File::move(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
}

/** A page logged in as `$user` with the collector (and the signer stub) armed. */
function tournamentPage(User $user): Page
{
    $page = visit(BrowserLogin::url($user))->page();
    $page->context()->addInitScript(TOURNAMENT_COLLECTOR);
    $page->context()->addInitScript(TestSigner::browserStub($user));

    return $page;
}

test('sign-up signs in the browser, and the tournament page shows the bracket with the director marker, at 375 and 1440 px', function () {
    // An open Rocket League 3v3 tournament: a solo player (375) and a captain (1440) sign up.
    $open = openTournament(['name' => 'Halving Cup', 'capacity' => 8], rocketLeague: true);
    $solo = User::factory()->create(['name' => 'hodlqueen', 'locale' => 'en']);
    TestSigner::forBrowser($solo);
    $captain = User::factory()->create(['name' => 'satsjaeger', 'locale' => 'en']);
    TestSigner::forBrowser($captain);
    $lineup = Lineup::factory()->mode('3v3')->ready(1)->create(['clan_id' => Clan::factory()->create(['owner_id' => $captain->id, 'name' => 'Laser Eyes', 'clantag' => 'LSR'])->id]);

    // A running director tournament with one result entered: the public bracket and its marker.
    $running = runningChess(TournamentFormat::SingleElimination, 8);
    $running->forceFill(['name' => 'Blitz Night Munich'])->save();
    $director = $running->creator;
    $director->forceFill(['locale' => 'en', 'name' => 'rbf_rita'])->save();
    $match = TournamentMatch::query()->where('tournament_id', $running->id)->where('status', 'ready')->orderBy('id')->first();
    app(TournamentRunner::class)->enterResult($match, $director, ['result' => '1-0']);

    $measured = [];

    foreach ([[375, 812, $solo, 'enter-solo'], [1440, 900, $captain, 'enter-lineup']] as [$width, $height, $user, $button]) {
        $page = tournamentPage($user);
        $page->setViewportSize($width, $height);

        $page->goto(ComputeUrl::from(route('tournaments.signup', $open)));
        BrowserWait::until($page, '() => document.querySelector("[data-test='.$button.']") !== null', 8_000);
        tournamentShot($page, "p8b-signup-{$width}");
        $page->locator("[data-test={$button}]")->click();
        BrowserWait::until($page, '() => document.querySelector("[data-test=my-entry]") !== null', 10_000);
        $signup = $page->evaluate(TOURNAMENT_STATE);
        tournamentShot($page, "p8b-signed-up-{$width}");

        $page->goto(ComputeUrl::from(route('tournaments.show', $running)));
        BrowserWait::until($page, '() => document.querySelector("[data-test=director-marker]") !== null', 8_000);
        $page->locator('[data-test=director-marker] summary')->first()->click();
        BrowserWait::until($page, '() => document.querySelector("[data-test=director-marker]")?.open === true', 8_000);
        $show = $page->evaluate(TOURNAMENT_STATE);
        $marker = $page->evaluate('() => document.querySelector("[data-test=director-marker]").textContent.replace(/\s+/g, " ").trim()');
        tournamentShot($page, "p8b-show-bracket-{$width}");

        $page->goto(ComputeUrl::from(route('tournaments.show', $open)));
        BrowserWait::until($page, '() => document.querySelector("[data-test=entries]") !== null', 8_000);
        $entries = $page->evaluate(TOURNAMENT_STATE);
        tournamentShot($page, "p8b-show-signup-{$width}");

        $measured[$width] = ['signup' => $signup, 'show' => $show, 'entries' => $entries];

        expect($signup['errors'])->toBe([])
            ->and($show['errors'])->toBe([])
            ->and($entries['errors'])->toBe([])
            ->and($signup['overflow'])->toBeLessThanOrEqual(0)
            ->and($show['overflow'])->toBeLessThanOrEqual(0)
            ->and($entries['overflow'])->toBeLessThanOrEqual(0)
            ->and($marker)->toContain('Entered by the tournament director')
            ->and($marker)->toContain('Entered by rbf_rita at')
            ->and($marker)->toContain('Not confirmed by the players.');
    }

    expect(TournamentSignup::query()->where('tournament_id', $open->id)->active()->pluck('lineup_id')->all())->toBe([null, $lineup->id]);

    // The director desk and the list, for the screenshots and the console.
    $page = tournamentPage($director);

    foreach ([[375, 812], [1440, 900]] as [$width, $height]) {
        $page->setViewportSize($width, $height);
        $page->goto(ComputeUrl::from(route('tournaments.director', $running)));
        BrowserWait::until($page, '() => document.querySelector("[data-test=round-results]") !== null', 8_000);
        $desk = $page->evaluate(TOURNAMENT_STATE);
        tournamentShot($page, "p8b-director-{$width}");
        $page->goto(ComputeUrl::from(route('tournaments.index')));
        BrowserWait::until($page, '() => document.querySelector("[data-test=tournaments-index]") !== null', 8_000);
        $index = $page->evaluate(TOURNAMENT_STATE);
        tournamentShot($page, "p8b-index-{$width}");

        expect($desk['errors'])->toBe([])->and($index['errors'])->toBe([])
            ->and($desk['overflow'])->toBeLessThanOrEqual(0)->and($index['overflow'])->toBeLessThanOrEqual(0);
    }

    // Positive control: an error thrown on the page reaches the collector.
    $page->evaluate('() => { setTimeout(() => { throw new Error("probe"); }, 0); }');
    BrowserWait::until($page, '() => window.__errors.length > 0', 5_000);
    expect(implode(' | ', $page->evaluate(TOURNAMENT_STATE)['errors']))->toContain('probe');

    fwrite(STDERR, "\n[p8b-tournaments] ".json_encode($measured)."\n");
});
