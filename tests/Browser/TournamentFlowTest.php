<?php

use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Models\ChessGame;
use App\Models\Clan;
use App\Models\IncomingPayment;
use App\Models\Lineup;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentSignup;
use App\Models\TournamentSponsor;
use App\Models\User;
use App\Support\Chess\ChessGameService;
use App\Support\Payouts\PayoutApproval;
use App\Support\Payouts\PayoutRunner;
use App\Support\Prizes\PrizePool;
use App\Support\Prizes\SponsorLogos;
use App\Support\Tournaments\TournamentRunner;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
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

/** Box of one warning: its edges, whether its text overflows it, and the text. */
const WARNING_BOX = <<<'JS'
    (selector) => {
        const el = document.querySelector(selector);
        const r = el.getBoundingClientRect();
        return { left: Math.round(r.left), right: Math.round(r.right), width: Math.round(r.width), height: Math.round(r.height), scrollWidth: el.scrollWidth, clientWidth: el.clientWidth, text: el.innerText };
    }
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
    $dir = getenv(str_starts_with($name, 'p9-') ? 'P9_SHOTS' : 'P8B_SHOTS');

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

test('a tournament game offers no abort, and a missed first move ends it on the game-over card with the forfeit reason, at 375 and 1440 px', function () {
    $measured = [];
    $page = null;

    foreach ([[375, 812], [1440, 900]] as [$width, $height]) {
        $tournament = runningChess(TournamentFormat::SingleElimination, 2, TournamentResultsMode::Players);
        $game = ChessGame::query()->whereIn('tournament_match_id', $tournament->matches()->select('id'))->sole();
        $white = $game->white;
        $white->forceFill(['locale' => 'en'])->save();

        $page = tournamentPage($white);
        $page->setViewportSize($width, $height);
        $page->goto(ComputeUrl::from(route('games.show', $game)));
        BrowserWait::until($page, '() => document.querySelector("[data-test=resign]") !== null', 8_000);
        $actions = $page->evaluate('() => ({ abort: document.querySelector("[data-test=abort]") !== null, resign: document.querySelector("[data-test=resign]") !== null })');

        // White opens, Black never moves: the first-move window passes and Black forfeits.
        $game = app(ChessGameService::class)->move($game, $white, 'e2e4');
        $this->travelTo(CarbonImmutable::createFromTimestampMs((int) $game->deadline_ms)->addSecond());
        app(ChessGameService::class)->checkClock($game);
        $page->evaluate('() => Alpine.$data(document.querySelector("[data-test=chess-game]")).resync()');
        BrowserWait::until($page, '() => document.querySelector("[data-test=outcome]")?.innerText === "Win"', 8_000);
        tournamentShot($page, "p18-forfeit-{$width}");

        $card = $page->evaluate('() => {
            const dialog = document.querySelector("[data-test=game-over] [role=dialog]").getBoundingClientRect();
            const reason = document.querySelector("[data-test=outcome] + span");

            return { left: dialog.left, right: dialog.right, width: dialog.width, height: dialog.height, reason: reason.innerText, clipped: reason.scrollWidth - reason.clientWidth };
        }');
        $state = $page->evaluate(TOURNAMENT_STATE);
        $measured[$width] = $actions + $card + ['overflow' => $state['overflow']];

        expect($actions)->toBe(['abort' => false, 'resign' => true])
            ->and($card['reason'])->toBe('decided by forfeit')
            ->and($card['clipped'])->toBeLessThanOrEqual(0)
            ->and($card['left'])->toBeGreaterThanOrEqual(0)
            ->and($card['right'])->toBeLessThanOrEqual($width)
            ->and($state['overflow'])->toBeLessThanOrEqual(0)
            ->and($state['errors'])->toBe([]);

        $this->travelBack();
    }

    // Positive control: an error thrown on the page reaches the collector.
    $page->evaluate('() => { setTimeout(() => { throw new Error("probe"); }, 0); }');
    BrowserWait::until($page, '() => window.__errors.length > 0', 5_000);
    expect(implode(' | ', $page->evaluate(TOURNAMENT_STATE)['errors']))->toContain('probe');

    fwrite(STDERR, "\n[p18-forfeit] ".json_encode($measured)."\n");
});

/*
| P9: the prize pot in the browser. A pot is always its tournament's own
| wallet (user, 2026-09-27). Anyone adds sats: the wallet makes the invoice,
| shown as a QR code only; the fake wallet settles it and the panel notices
| it by itself. A pot whose connection may not make invoices says so. A
| finished tournament shows its sponsors and payouts; the admins' payouts
| page, the pool settings and the create page (percent, then fixed amounts)
| are measured too. All at 375 and 1440 px, with the collector armed and a
| positive control. P9_SHOTS=<dir> writes the English screenshots there.
*/
/** Elements that reach past the viewport's right edge (for the report when a page overflows). */
const POOL_WIDE = <<<'JS'
    () => [...document.querySelectorAll('body *')]
        .filter((el) => el.getBoundingClientRect().right > document.documentElement.clientWidth + 1 && !el.parentElement.closest('.overflow-x-auto'))
        .slice(0, 5)
        .map((el) => el.tagName + '.' + String(el.className).slice(0, 60) + ' ' + Math.round(el.getBoundingClientRect().right))
    JS;

test('the prize pot: top-ups by QR code, sponsors, payouts, fixed prizes and the admin pages, at 375 and 1440 px', function () {
    // The LNURL fake of the winners' addresses answers instead of the blanket fake of beforeEach.
    Http::swap(new HttpFactory);
    fakeWallet();
    Storage::fake('public');

    // An open pot that takes top-ups, 50/30/20.
    $openPot = ownPotWallet(42_000);
    $open = openTournament(['name' => 'Halving Cup', 'capacity' => 8], rocketLeague: true);
    app(PrizePool::class)->configurePot($open, $open->creator, true, $openPot->uri('pay'), null, Tournament::PRIZES_PERCENT, [50, 30, 20]);

    // A pot whose connection may only pay: no top-ups.
    $payOnly = ownPotWallet(21_000);
    $payOnly->payMethods = ['pay_invoice', 'get_balance', 'get_info'];
    $closedPot = openTournament(['name' => 'Lightning Ladder', 'capacity' => 8], rocketLeague: true);
    app(PrizePool::class)->configurePot($closedPot, $closedPot->creator, true, $payOnly->uri('pay'), null, Tournament::PRIZES_PERCENT, [60, 40]);

    // Fixed amounts in a wallet that covers them, with sats left over.
    $own = ownPotWallet(150_000);
    $walletSecret = $own->clients['pay']['secret'];
    $walletPot = openTournament(['name' => 'Stacker Open', 'capacity' => 8], rocketLeague: true);
    app(PrizePool::class)->configurePot($walletPot, $walletPot->creator, true, $own->uri('pay', 'pot@wallet.example'), null, Tournament::PRIZES_FIXED, [], [60_000, 30_000, 10_000]);
    // The create page connects a wallet of its own here (sharing one is allowed too).
    $fresh = ownPotWallet(150_000);

    expect([$open->refresh()->pool_opened_at, $closedPot->refresh()->pool_opened_at, $walletPot->refresh()->pool_opened_at])->each->not->toBeNull()
        ->and($closedPot->pot_can_receive)->toBeFalse()->and($open->pot_can_receive)->toBeTrue();

    $finishedPot = ownPotWallet(0);
    fakeLightningAddresses($finishedPot);
    $finished = finishedPoolTournament($finishedPot, 210_000, 4, withoutAddress: [4]);
    $finished->forceFill(['name' => 'Blitz Night Munich'])->save();
    $admin = anAdmin();
    $admin->forceFill(['locale' => 'en', 'name' => 'ada_admin'])->save();
    // A sponsor who paid while the tournament ran (its logo shows once paid), into the pot's own wallet.
    $sponsor = TournamentSponsor::query()->create(['tournament_id' => $finished->id, 'name' => 'Satoshi’s Pizza', 'pledged_sats' => 50_000,
        'logo_path' => app(SponsorLogos::class)->store(UploadedFile::fake()->image('pizza.png', 600, 200))]);
    $finished->forceFill(['pool_closed_at' => null])->save();
    fundPool($finishedPot, $finished, 50_000, topUp: true);
    IncomingPayment::query()->latest('id')->firstOrFail()->forceFill(['sponsor_id' => $sponsor->id, 'source' => 'sponsor'])->save();

    app(PayoutApproval::class)->approve($finished->refresh(), $admin);

    // Fixed prizes of 80 000 sats in a wallet holding 60 000, not approved yet: a warning, the approval stays (user, 2026-09-27).
    $shortPot = ownPotWallet(0);
    fakeLightningAddresses($shortPot);
    $short = finishedPoolTournament($shortPot, 60_000, 2, fixed: [50_000, 30_000]);
    $short->forceFill(['name' => 'Short Stack Cup'])->save();

    foreach ($finished->payouts()->get() as $payout) {
        app(PayoutRunner::class)->run($payout, true);
    }

    $player = User::factory()->create(['name' => 'hodlqueen', 'locale' => 'en']);
    TestSigner::forBrowser($player);
    $measured = [];
    $desk = null;

    foreach ([[375, 812], [1440, 900]] as [$width, $height]) {
        $page = tournamentPage($player);
        $page->setViewportSize($width, $height);

        // Anyone adds sats: the pot's own wallet makes the invoice, shown as a QR code, never as text.
        $page->goto(ComputeUrl::from(route('tournaments.show', $open)));
        BrowserWait::until($page, '() => document.querySelector("[data-test=topup]") !== null', 8_000);
        $page->evaluate('() => document.querySelector("[data-test=prize-pool]").scrollIntoView()');
        tournamentShot($page, "p9-pool-open-{$width}");
        $page->locator('[data-test=topup]')->click();
        BrowserWait::until($page, '() => document.querySelector("[data-test=topup-qr] svg") !== null', 10_000);
        $topUp = IncomingPayment::query()->latest('id')->firstOrFail();
        $invoice = $page->evaluate('() => { const box = document.querySelector("[data-test=topup-invoice]").getBoundingClientRect(); const qr = document.querySelector("[data-test=topup-qr]").getBoundingClientRect(); return [Math.round(box.left), Math.round(box.right), Math.round(box.width), Math.round(qr.width), Math.round(qr.height)]; }');
        $invoiceText = $page->evaluate('(bolt11) => document.body.innerText.toLowerCase().includes(bolt11.toLowerCase().slice(0, 24))', $topUp->bolt11);
        tournamentShot($page, "p9-topup-invoice-{$width}");

        $openPot->settleIncoming($topUp->payment_hash);
        BrowserWait::until($page, '() => document.querySelector("[data-test=topup-received]") !== null', 12_000);
        $received = $page->evaluate(TOURNAMENT_STATE);
        tournamentShot($page, "p9-topup-received-{$width}");

        // A pot whose connection may not make invoices: no top-ups, and it says so.
        $page->goto(ComputeUrl::from(route('tournaments.show', $closedPot)));
        BrowserWait::until($page, '() => document.querySelector("[data-test=topup-off]") !== null', 8_000);
        $page->evaluate('() => document.querySelector("[data-test=prize-pool]").scrollIntoView()');
        $off = $page->evaluate(TOURNAMENT_STATE);
        $off['button'] = $page->evaluate('() => document.querySelector("[data-test=topup]") !== null');
        tournamentShot($page, "p9-topup-off-{$width}");

        $page->goto(ComputeUrl::from(route('tournaments.show', $finished)));
        BrowserWait::until($page, '() => document.querySelector("[data-test=pool-payouts]") !== null', 8_000);
        $page->evaluate('() => document.querySelector("[data-test=prize-pool]").scrollIntoView()');
        $payouts = $page->evaluate(TOURNAMENT_STATE);
        tournamentShot($page, "p9-payouts-public-{$width}");

        expect($topUp->source)->toBe('topup')->and($topUp->pot)->toBe('tournament:'.$open->id)->and($topUp->refresh()->status->value)->toBe('settled')
            ->and($invoiceText)->toBeFalse()
            ->and($off['button'])->toBeFalse()
            ->and($received['errors'])->toBe([])->and($off['errors'])->toBe([])->and($payouts['errors'])->toBe([])
            ->and($received['overflow'])->toBeLessThanOrEqual(0)->and($off['overflow'])->toBeLessThanOrEqual(0)->and($payouts['overflow'])->toBeLessThanOrEqual(0)
            ->and($invoice[0])->toBeGreaterThanOrEqual(16)
            ->and($invoice[1])->toBeLessThanOrEqual($width - 16)
            ->and($invoice[3])->toBeGreaterThanOrEqual(200);

        $desk = tournamentPage($admin);
        $desk->setViewportSize($width, $height);
        $desk->goto(ComputeUrl::from(route('admin.payouts', ['tournament' => $finished->id])));
        BrowserWait::until($desk, '() => document.querySelector("[data-test=admin-payout-row]") !== null', 8_000);
        $adminState = $desk->evaluate(TOURNAMENT_STATE);
        $adminState['wide'] = $desk->evaluate(POOL_WIDE);
        tournamentShot($desk, "p9-admin-payouts-{$width}");
        $desk->goto(ComputeUrl::from(route('admin.payouts', ['tournament' => $short->id])));
        BrowserWait::until($desk, '() => document.querySelector("[data-test=payouts-underfunded]") !== null', 8_000);
        $desk->evaluate('() => document.querySelector("[data-test=payouts-underfunded]").scrollIntoView({block: "center"})');
        $shortState = $desk->evaluate(TOURNAMENT_STATE);
        $shortState['box'] = $desk->evaluate(WARNING_BOX, '[data-test=payouts-underfunded]');
        $shortState['approve'] = $desk->evaluate('() => document.querySelector("[data-test=approve-payouts]") !== null');
        tournamentShot($desk, "p9-admin-payouts-short-{$width}");
        $desk->goto(ComputeUrl::from(route('tournaments.pool', $open)));
        BrowserWait::until($desk, '() => document.querySelector("[data-test=prize-pot]") !== null', 8_000);
        $settings = $desk->evaluate(TOURNAMENT_STATE);
        $settings['wide'] = $desk->evaluate(POOL_WIDE);
        tournamentShot($desk, "p9-pool-settings-{$width}");

        // The optional pot of the create page: on, its own wallet (no other source), checked live, a preset with its preview in sats.
        $desk->goto(ComputeUrl::from(route('admin.tournaments.create')));
        BrowserWait::until($desk, '() => document.querySelector("[data-test=pot-enabled]") !== null', 8_000);
        $desk->locator('[data-test=pot-enabled]')->click();
        BrowserWait::until($desk, '() => document.querySelector("[data-test=pot-uri]") !== null', 8_000);
        $desk->locator('[data-test=pot-uri]')->fill($fresh->uri('pay'));
        $desk->locator('[data-test=pot-check]')->click();
        BrowserWait::until($desk, '() => document.querySelector("[data-test=pot-notice]") !== null', 10_000);
        $desk->locator('[data-test=pot-preset-top-4]')->click();
        BrowserWait::until($desk, '() => document.querySelectorAll("[data-test=pot-preview] li").length === 4', 8_000);
        $desk->evaluate('() => document.querySelector("[data-test=prize-pot]").scrollIntoView()');
        $create = $desk->evaluate(TOURNAMENT_STATE);
        $create['wide'] = $desk->evaluate(POOL_WIDE);
        // Positive control of the secret probe below: before saving, the typed string is in the page's Livewire snapshot.
        $create['secret'] = $desk->evaluate('(secret) => document.documentElement.outerHTML.includes(secret)', substr($fresh->clients['pay']['secret'], 0, 12));
        $create['hint'] = $desk->evaluate('() => document.querySelector("[data-test=pot-own-wallet-hint]")?.innerText ?? null');
        $create['league'] = $desk->evaluate('() => document.querySelector("[data-test=prize-pot]").innerText.includes("League pot")');
        tournamentShot($desk, "p9-pot-create-{$width}");

        // Fixed amounts: sats per place, a place added, the sum with the fee reserve.
        $desk->locator('[data-test=pot-mode-fixed]')->click();
        BrowserWait::until($desk, '() => document.querySelector("[data-test=pot-fixed-1]") !== null', 8_000);
        $desk->locator('[data-test=pot-fixed-add]')->click();
        BrowserWait::until($desk, '() => document.querySelector("[data-test=pot-fixed-4]") !== null', 8_000);
        $desk->locator('[data-test=pot-fixed-4]')->fill('5000');
        BrowserWait::until($desk, '() => document.querySelector("[data-test=pot-fixed-sum]").innerText.includes("105")', 8_000);
        $desk->evaluate('() => document.querySelector("[data-test=prize-pot]").scrollIntoView()');
        $fixed = $desk->evaluate(TOURNAMENT_STATE);
        $fixed['wide'] = $desk->evaluate(POOL_WIDE);
        $fixed['inputs'] = $desk->evaluate('() => [...document.querySelectorAll("[data-test^=pot-fixed-]")].filter((el) => el.tagName === "INPUT").map((el) => { const r = el.closest("label").getBoundingClientRect(); return [Math.round(r.left), Math.round(r.right), Math.round(r.height)]; })');
        $fixed['sum'] = $desk->evaluate('() => document.querySelector("[data-test=pot-fixed-sum]").innerText');
        tournamentShot($desk, "p9-pot-create-fixed-{$width}");

        // More in prizes than the checked wallet holds: a warning next to them, saving stays possible.
        $desk->locator('[data-test=pot-fixed-1]')->fill('95000');
        BrowserWait::until($desk, '() => document.querySelector("[data-test=pot-fixed-funding][role=status]") !== null', 8_000);
        $desk->evaluate('() => document.querySelector("[data-test=pot-fixed-funding]").scrollIntoView({block: "center"})');
        $shortForm = $desk->evaluate(TOURNAMENT_STATE);
        $shortForm['box'] = $desk->evaluate(WARNING_BOX, '[data-test=pot-fixed-funding]');
        tournamentShot($desk, "p9-pot-create-short-{$width}");

        // The edit page of a pot with fixed amounts in its own wallet: "connected", never the string, and what is left over.
        $desk->goto(ComputeUrl::from(route('admin.tournaments.edit', $walletPot)));
        BrowserWait::until($desk, '() => document.querySelector("[data-test=pot-connected]") !== null', 8_000);
        $desk->evaluate('() => document.querySelector("[data-test=prize-pot]").scrollIntoView()');
        $edit = $desk->evaluate(TOURNAMENT_STATE);
        $edit['wide'] = $desk->evaluate(POOL_WIDE);
        $edit['secret'] = $desk->evaluate('(secret) => document.documentElement.outerHTML.includes(secret)', substr($walletSecret, 0, 12));
        $edit['funding'] = $desk->evaluate('() => document.querySelector("[data-test=pot-fixed-funding]")?.innerText ?? null');
        tournamentShot($desk, "p9-pot-edit-{$width}");

        // Where players look: the index with the pot chips, the tournament page with the balance time.
        $page->goto(ComputeUrl::from(route('tournaments.index')));
        BrowserWait::until($page, '() => document.querySelectorAll("[data-test=prize-chip]").length >= 3', 8_000);
        $index = $page->evaluate(TOURNAMENT_STATE);
        tournamentShot($page, "p9-pot-index-{$width}");
        $page->goto(ComputeUrl::from(route('tournaments.show', $walletPot)));
        BrowserWait::until($page, '() => document.querySelector("[data-test=pool-as-of]") !== null', 8_000);
        $page->evaluate('() => document.querySelector("[data-test=prize-pool]").scrollIntoView()');
        $walletShow = $page->evaluate(TOURNAMENT_STATE);
        tournamentShot($page, "p9-pot-show-{$width}");

        fwrite(STDERR, "\n[p9-pool] {$width}: ".json_encode(['admin' => $adminState, 'settings' => $settings, 'create' => $create, 'fixed' => $fixed, 'edit' => $edit, 'shortForm' => $shortForm, 'shortPayouts' => $shortState])."\n");

        foreach ([$shortForm, $shortState] as $warned) {
            expect($warned['errors'])->toBe([])->and($warned['overflow'])->toBeLessThanOrEqual(0)
                ->and($warned['box']['left'])->toBeGreaterThanOrEqual(0)->and($warned['box']['right'])->toBeLessThanOrEqual($width)
                ->and($warned['box']['scrollWidth'])->toBeLessThanOrEqual($warned['box']['clientWidth'])
                ->and($warned['box']['text'])->toContain('Warning:');
        }

        expect($shortForm['box']['text'])->toContain('You can still save')->and($shortState['box']['text'])->toContain('You can still approve')
            ->and($shortState['approve'])->toBeTrue();

        expect($adminState['errors'])->toBe([])->and($settings['errors'])->toBe([])
            ->and($adminState['overflow'])->toBeLessThanOrEqual(0)->and($settings['overflow'])->toBeLessThanOrEqual(0)
            ->and($create['errors'])->toBe([])->and($create['overflow'])->toBeLessThanOrEqual(0)->and($create['secret'])->toBeTrue()->and($create['league'])->toBeFalse()->and($create['hint'])->toContain('sub-wallet with its own balance')
            ->and($fixed['errors'])->toBe([])->and($fixed['overflow'])->toBeLessThanOrEqual(0)->and($fixed['inputs'])->toHaveCount(4)
            ->and(collect($fixed['inputs'])->every(fn (array $box): bool => $box[0] >= 0 && $box[1] <= $width && $box[2] >= 44))->toBeTrue()
            ->and($edit['funding'])->toContain('left over')
            ->and($edit['errors'])->toBe([])->and($edit['overflow'])->toBeLessThanOrEqual(0)->and($edit['secret'])->toBeFalse()
            ->and($index['errors'])->toBe([])->and($index['overflow'])->toBeLessThanOrEqual(0)
            ->and($walletShow['errors'])->toBe([])->and($walletShow['overflow'])->toBeLessThanOrEqual(0);

        $measured[$width] = ['shortForm' => $shortForm, 'shortPayouts' => $shortState, 'invoice' => $invoice, 'received' => $received, 'off' => $off, 'payouts' => $payouts, 'fixed' => $fixed, 'admin' => $adminState, 'settings' => $settings, 'create' => $create, 'edit' => $edit, 'index' => $index, 'walletShow' => $walletShow];
    }

    expect($finished->payouts()->pluck('status')->map->value->sort()->values()->all())->toBe(['open', 'paid', 'paid', 'paid'])
        ->and($finishedPot->payRequests())->toHaveCount(3);

    // Positive control: an error thrown on the page reaches the collector.
    $desk->evaluate('() => { setTimeout(() => { throw new Error("probe"); }, 0); }');
    BrowserWait::until($desk, '() => window.__errors.length > 0', 5_000);
    expect(implode(' | ', $desk->evaluate(TOURNAMENT_STATE)['errors']))->toContain('probe');

    fwrite(STDERR, "\n[p9-pool] ".json_encode($measured)."\n");
});
