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
use App\Support\Nostr\SignedEvent;
use App\Support\Payouts\PayoutApproval;
use App\Support\Payouts\PayoutRunner;
use App\Support\Prizes\IncomingPayments;
use App\Support\Prizes\PoolInvoices;
use App\Support\Prizes\PrizePool;
use App\Support\Prizes\SponsorLogos;
use App\Support\SeasonChain\LeagueKey;
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
    TestSigner::forBrowser($director);
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
        TestSigner::forBrowser($white);

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
| P9: the prize pot in the browser. Every pot is booked in the league
| wallet (user, 2026-10-02). Anyone adds sats: the league wallet makes the
| invoice, shown as a QR code only; the fake wallet settles it and the panel
| notices it by itself. A finished tournament shows its sponsors and
| payouts; the admins' payouts page (a pot short of its fixed prizes is not
| approved), the pool settings, the create page (percent, then fixed
| amounts) and the edit page are measured too. All at 375 and 1440 px, with the collector armed and a
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
    // Every pot is booked in the league wallet (2026-10-02).
    $league = fakeWallet();
    Storage::fake('public');

    // An open pot that takes top-ups, 50/30/20.
    $openPot = $league;
    $open = openTournament(['name' => 'Halving Cup', 'capacity' => 8], rocketLeague: true);
    app(PrizePool::class)->configurePot($open, $open->creator, true, 100_000, Tournament::PRIZES_PERCENT, [50, 30, 20]);
    fundPool($league, $open->refresh(), 42_000);

    // Zap sponsors (user, 2026-10-02): a stranger and a player zap the tournament's event; the league receipts each.
    $zapPot = function (TestSigner $signer, int $sats, string $comment) use ($league, $open): void {
        $request = SignedEvent::fromInput($signer->sign(9734, [['relays', 'wss://relay.example.org'], ['amount', (string) ($sats * 1000)],
            ['p', (string) LeagueKey::poolPubkey()], ['a', (string) $open->address()], ['k', '31923']], $comment, now()->getTimestamp()));
        $payment = app(PoolInvoices::class)->forZapRequest($request, $request->toJson(), $sats);
        $league->settleIncoming($payment->payment_hash);
        app(IncomingPayments::class)->check($payment, 0);
    };
    $zapPot(new TestSigner, 21_000, 'Stack sats');
    $stacker = new TestSigner;
    User::factory()->withPubkey($stacker->pubkey)->create(['name' => 'satoshi_stacker', 'locale' => 'en']);
    $zapPot($stacker, 5_000, '');

    // The sponsors section: one pledge paid outside the wallet (with a note), one still open.
    $bakery = TournamentSponsor::query()->create(['tournament_id' => $open->id, 'name' => 'Hodl Bakery', 'pledged_sats' => 10_500,
        'logo_path' => app(SponsorLogos::class)->store(UploadedFile::fake()->image('bakery.png', 480, 160))]);
    app(PrizePool::class)->markPaidOutside($bakery, $open->creator, 8_000, 'Bank transfer on 1 October');
    TournamentSponsor::query()->create(['tournament_id' => $open->id, 'name' => 'Lightning Pizza', 'pledged_sats' => 50_000]);

    // Fixed amounts the pot covers, with sats left over.
    $walletPot = openTournament(['name' => 'Stacker Open', 'capacity' => 8], rocketLeague: true);
    app(PrizePool::class)->configurePot($walletPot, $walletPot->creator, true, null, Tournament::PRIZES_FIXED, [], [60_000, 30_000, 10_000]);
    fundPool($league, $walletPot->refresh(), 150_000);

    expect([$open->refresh()->pool_opened_at, $walletPot->refresh()->pool_opened_at])->each->not->toBeNull();

    $finishedPot = $league;
    fakeLightningAddresses($finishedPot);
    $finished = finishedPoolTournament($finishedPot, 210_000, 4, withoutAddress: [4]);
    $finished->forceFill(['name' => 'Blitz Night Munich'])->save();
    $admin = anAdmin();
    $admin->forceFill(['locale' => 'en', 'name' => 'ada_admin'])->save();
    // A sponsor who paid while the tournament ran (its logo shows once paid), by an invoice of the league wallet.
    $sponsor = TournamentSponsor::query()->create(['tournament_id' => $finished->id, 'name' => 'Satoshi’s Pizza', 'pledged_sats' => 50_000,
        'logo_path' => app(SponsorLogos::class)->store(UploadedFile::fake()->image('pizza.png', 600, 200))]);
    $finished->forceFill(['pool_closed_at' => null])->save();
    fundPool($finishedPot, $finished, 50_000, topUp: true);
    IncomingPayment::query()->latest('id')->firstOrFail()->forceFill(['sponsor_id' => $sponsor->id, 'source' => 'sponsor'])->save();

    app(PayoutApproval::class)->approve($finished->refresh(), $admin);

    // Fixed prizes of 80 000 sats in a pot that received 60 000: not approved until it covers them (coordinator, 2026-10-02).
    $short = finishedPoolTournament($league, 60_000, 2, fixed: [50_000, 30_000]);
    $short->forceFill(['name' => 'Short Stack Cup'])->save();
    $requests = count($finishedPot->payRequests());

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

        // Anyone adds sats: the league wallet makes the invoice for this pot, shown as a QR code, never as text.
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

        // The zap sponsors' wall with "+N sats from zaps on top", then the zap panel with the pot's LNURL QR code.
        $page->goto(ComputeUrl::from(route('tournaments.show', $open)));
        BrowserWait::until($page, '() => document.querySelector("[data-test=pool-zappers]") !== null', 8_000);
        $page->evaluate('() => document.querySelector("[data-test=prize-pool]").scrollIntoView()');
        $wall = $page->evaluate(TOURNAMENT_STATE);
        $wall['entries'] = $page->evaluate('() => [...document.querySelectorAll("[data-test=pool-zapper]")].map((el) => { const r = el.getBoundingClientRect(); const img = el.querySelector("img").getBoundingClientRect(); return { text: el.innerText.replace(/\\s+/g, " "), left: Math.round(r.left), right: Math.round(r.right), height: Math.round(r.height), avatar: Math.round(img.width) }; })');
        $wall['list'] = $page->evaluate('() => { const ol = document.querySelector("[data-test=pool-zappers] ol"); const r = ol.getBoundingClientRect(); return { left: Math.round(r.left), right: Math.round(r.right), scrollWidth: ol.scrollWidth, clientWidth: ol.clientWidth }; }');
        $wall['onTop'] = $page->evaluate('() => document.querySelector("[data-test=pool-zaps-on-top]")?.innerText ?? null');
        $wall['pot'] = $page->evaluate('() => document.querySelector("[data-test=pool-sats]").innerText');
        tournamentShot($page, "p9-zap-wall-{$width}");
        $page->evaluate('() => document.querySelector("#pot-zap").scrollIntoView()');
        $zapPanel = $page->evaluate(TOURNAMENT_STATE);
        $zapPanel['box'] = $page->evaluate(WARNING_BOX, '[data-test=pot-zap]');
        $zapPanel['qr'] = $page->evaluate('() => { const r = document.querySelector("[data-test=pot-zap-qr]").getBoundingClientRect(); return [Math.round(r.width), Math.round(r.height)]; }');
        $zapPanel['amounts'] = $page->evaluate('() => [...document.querySelectorAll("[data-test=pot-zap-amount]")].map((el) => Math.round(el.getBoundingClientRect().height))');
        $zapPanel['address'] = $page->evaluate('(address) => document.body.innerText.includes(address)', PoolInvoices::address());
        tournamentShot($page, "p9-pot-zap-{$width}");

        expect($wall['errors'])->toBe([])->and($wall['overflow'])->toBeLessThanOrEqual(0)
            ->and($wall['entries'])->toHaveCount(2)
            ->and($wall['entries'][0]['text'])->toContain('21')->and($wall['entries'][1]['text'])->toContain('satoshi_stacker')
            ->and(collect($wall['entries'])->every(fn (array $e): bool => $e['avatar'] >= 32 && $e['height'] >= 44))->toBeTrue()
            ->and($wall['list']['left'])->toBeGreaterThanOrEqual(0)->and($wall['list']['right'])->toBeLessThanOrEqual($width)
            ->and($wall['onTop'])->toContain('26')->and($wall['pot'])->toContain('126')
            ->and($zapPanel['errors'])->toBe([])->and($zapPanel['overflow'])->toBeLessThanOrEqual(0)
            ->and($zapPanel['box']['left'])->toBeGreaterThanOrEqual(0)->and($zapPanel['box']['right'])->toBeLessThanOrEqual($width)
            ->and($zapPanel['box']['scrollWidth'])->toBeLessThanOrEqual($zapPanel['box']['clientWidth'])
            ->and($zapPanel['qr'][0])->toBeGreaterThanOrEqual(150)
            ->and($zapPanel['amounts'])->toHaveCount(4)->and(min($zapPanel['amounts']))->toBeGreaterThanOrEqual(44)
            ->and($zapPanel['address'])->toBeFalse();

        $page->goto(ComputeUrl::from(route('tournaments.show', $finished)));
        BrowserWait::until($page, '() => document.querySelector("[data-test=pool-payouts]") !== null', 8_000);
        $page->evaluate('() => document.querySelector("[data-test=prize-pool]").scrollIntoView()');
        $payouts = $page->evaluate(TOURNAMENT_STATE);
        tournamentShot($page, "p9-payouts-public-{$width}");

        expect($topUp->source)->toBe('topup')->and($topUp->pot)->toBe('tournament:'.$open->id)->and($topUp->refresh()->status->value)->toBe('settled')
            ->and($invoiceText)->toBeFalse()
            ->and($received['errors'])->toBe([])->and($payouts['errors'])->toBe([])
            ->and($received['overflow'])->toBeLessThanOrEqual(0)->and($payouts['overflow'])->toBeLessThanOrEqual(0)
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
        $shortState['box'] = $desk->evaluate(WARNING_BOX, '[data-test=payouts-blocker]');
        $shortState['approve'] = $desk->evaluate('() => document.querySelector("[data-test=approve-payouts]") !== null');
        tournamentShot($desk, "p9-admin-payouts-short-{$width}");
        $desk->goto(ComputeUrl::from(route('tournaments.pool', $open)));
        BrowserWait::until($desk, '() => document.querySelector("[data-test=prize-pot]") !== null', 8_000);
        $settings = $desk->evaluate(TOURNAMENT_STATE);
        $settings['wide'] = $desk->evaluate(POOL_WIDE);
        tournamentShot($desk, "p9-pool-settings-{$width}");

        // The sponsors section: a pledge paid outside the wallet with who, when and the note; "Mark as paid outside" opens its form.
        $desk->evaluate('() => document.querySelector("#sponsors-h").scrollIntoView()');
        $sponsors = $desk->evaluate(TOURNAMENT_STATE);
        $sponsors['rows'] = $desk->evaluate('() => [...document.querySelectorAll("[data-test=sponsor-row]")].map((el) => { const r = el.getBoundingClientRect(); return { left: Math.round(r.left), right: Math.round(r.right), text: el.innerText.replace(/\\s+/g, " ") }; })');
        $sponsors['buttons'] = $desk->evaluate('() => [...document.querySelectorAll("[data-test=sponsor-outside-button], [data-test=sponsor-outside-undo]")].map((el) => { const r = el.getBoundingClientRect(); return [Math.round(r.left), Math.round(r.right), Math.round(r.height)]; })');
        $sponsors['warning'] = $desk->evaluate('() => document.querySelector("[data-test=pool-outside-warning]")?.innerText ?? null');
        tournamentShot($desk, "p9-sponsors-{$width}");
        $desk->locator('[data-test=sponsor-outside-button]')->click();
        BrowserWait::until($desk, '() => document.querySelector("[data-test=sponsor-outside-form]") !== null', 8_000);
        $desk->evaluate('() => document.querySelector("[data-test=sponsor-outside-form]").scrollIntoView({block: "center"})');
        $outsideForm = $desk->evaluate(TOURNAMENT_STATE);
        $outsideForm['box'] = $desk->evaluate(WARNING_BOX, '[data-test=sponsor-outside-form]');
        $outsideForm['sats'] = $desk->evaluate('() => document.querySelector("[data-test=sponsor-outside-sats]").value');
        $outsideForm['inputs'] = $desk->evaluate('() => [...document.querySelectorAll("[data-test=sponsor-outside-form] input")].map((el) => { const r = el.getBoundingClientRect(); return [Math.round(r.left), Math.round(r.right), Math.round(r.height)]; })');
        tournamentShot($desk, "p9-sponsor-outside-form-{$width}");

        expect($sponsors['errors'])->toBe([])->and($sponsors['overflow'])->toBeLessThanOrEqual(0)
            ->and($sponsors['rows'])->toHaveCount(2)
            ->and($sponsors['rows'][0]['text'])->toContain('Bank transfer on 1 October')->toContain('paid outside the wallet')
            ->and(collect($sponsors['rows'])->every(fn (array $row): bool => $row['left'] >= 0 && $row['right'] <= $width))->toBeTrue()
            ->and($sponsors['buttons'])->toHaveCount(2)
            ->and(collect($sponsors['buttons'])->every(fn (array $b): bool => $b[0] >= 0 && $b[1] <= $width && $b[2] >= 44))->toBeTrue()
            ->and($sponsors['warning'])->toContain('8')
            ->and($outsideForm['errors'])->toBe([])->and($outsideForm['overflow'])->toBeLessThanOrEqual(0)
            ->and($outsideForm['sats'])->toBe('50000')
            ->and($outsideForm['box']['left'])->toBeGreaterThanOrEqual(0)->and($outsideForm['box']['right'])->toBeLessThanOrEqual($width)
            ->and(collect($outsideForm['inputs'])->every(fn (array $b): bool => $b[0] >= 0 && $b[1] <= $width && $b[2] >= 44))->toBeTrue();

        // The optional pot of the create page: on, no wallet to connect, a target and a preset with its preview in sats.
        $desk->goto(ComputeUrl::from(route('admin.tournaments.create')));
        BrowserWait::until($desk, '() => document.querySelector("[data-test=pot-enabled]") !== null', 8_000);
        $desk->locator('[data-test=pot-enabled]')->click();
        BrowserWait::until($desk, '() => document.querySelector("[data-test=pot-league-wallet]") !== null', 8_000);
        $desk->locator('[data-test=pot-target]')->fill('150000');
        $desk->locator('[data-test=pot-preset-top-4]')->click();
        BrowserWait::until($desk, '() => document.querySelectorAll("[data-test=pot-preview] li").length === 4', 8_000);
        $desk->evaluate('() => document.querySelector("[data-test=prize-pot]").scrollIntoView()');
        $create = $desk->evaluate(TOURNAMENT_STATE);
        $create['wide'] = $desk->evaluate(POOL_WIDE);
        $create['uri'] = $desk->evaluate('() => document.querySelector("[data-test=pot-uri]") !== null || document.querySelector("[data-test=prize-pot]").innerText.includes("walletconnect")');
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

        // The edit page of a pot with fixed amounts: no wallet to connect, and what is left over.
        $desk->goto(ComputeUrl::from(route('admin.tournaments.edit', $walletPot)));
        BrowserWait::until($desk, '() => document.querySelector("[data-test=pot-league-wallet]") !== null', 8_000);
        $desk->evaluate('() => document.querySelector("[data-test=prize-pot]").scrollIntoView()');
        $edit = $desk->evaluate(TOURNAMENT_STATE);
        $edit['wide'] = $desk->evaluate(POOL_WIDE);
        $edit['funding'] = $desk->evaluate('() => document.querySelector("[data-test=pot-fixed-funding]")?.innerText ?? null');
        tournamentShot($desk, "p9-pot-edit-{$width}");

        // More in prizes than the pot received: a warning next to them, saving stays possible.
        $desk->locator('[data-test=pot-fixed-1]')->fill('150000');
        BrowserWait::until($desk, '() => document.querySelector("[data-test=pot-fixed-funding][role=status]") !== null', 8_000);
        $desk->evaluate('() => document.querySelector("[data-test=pot-fixed-funding]").scrollIntoView({block: "center"})');
        $shortForm = $desk->evaluate(TOURNAMENT_STATE);
        $shortForm['box'] = $desk->evaluate(WARNING_BOX, '[data-test=pot-fixed-funding]');
        tournamentShot($desk, "p9-pot-edit-short-{$width}");

        // Where players look: the index with the pot chips, the tournament page with what is still to be won.
        $page->goto(ComputeUrl::from(route('tournaments.index')));
        BrowserWait::until($page, '() => document.querySelectorAll("[data-test=prize-chip]").length >= 3', 8_000);
        $index = $page->evaluate(TOURNAMENT_STATE);
        tournamentShot($page, "p9-pot-index-{$width}");
        $page->goto(ComputeUrl::from(route('tournaments.show', $walletPot)));
        BrowserWait::until($page, '() => document.querySelector("[data-test=pool-left]") !== null', 8_000);
        $page->evaluate('() => document.querySelector("[data-test=prize-pool]").scrollIntoView()');
        $walletShow = $page->evaluate(TOURNAMENT_STATE);
        tournamentShot($page, "p9-pot-show-{$width}");

        fwrite(STDERR, "\n[p9-pool] {$width}: ".json_encode(['admin' => $adminState, 'settings' => $settings, 'create' => $create, 'fixed' => $fixed, 'edit' => $edit, 'shortForm' => $shortForm, 'shortPayouts' => $shortState])."\n");

        foreach ([$shortForm, $shortState] as $warned) {
            expect($warned['errors'])->toBe([])->and($warned['overflow'])->toBeLessThanOrEqual(0)
                ->and($warned['box']['left'])->toBeGreaterThanOrEqual(0)->and($warned['box']['right'])->toBeLessThanOrEqual($width)
                ->and($warned['box']['scrollWidth'])->toBeLessThanOrEqual($warned['box']['clientWidth']);
        }

        expect($shortForm['box']['text'])->toContain('You can still save')->and($shortState['box']['text'])->toContain('less than the fixed prizes need')
            ->and($shortState['approve'])->toBeFalse();

        expect($adminState['errors'])->toBe([])->and($settings['errors'])->toBe([])
            ->and($adminState['overflow'])->toBeLessThanOrEqual(0)->and($settings['overflow'])->toBeLessThanOrEqual(0)
            ->and($create['errors'])->toBe([])->and($create['overflow'])->toBeLessThanOrEqual(0)->and($create['uri'])->toBeFalse()
            ->and($fixed['errors'])->toBe([])->and($fixed['overflow'])->toBeLessThanOrEqual(0)->and($fixed['inputs'])->toHaveCount(4)
            ->and(collect($fixed['inputs'])->every(fn (array $box): bool => $box[0] >= 0 && $box[1] <= $width && $box[2] >= 44))->toBeTrue()
            ->and($edit['funding'])->toContain('left over')
            ->and($edit['errors'])->toBe([])->and($edit['overflow'])->toBeLessThanOrEqual(0)
            ->and($index['errors'])->toBe([])->and($index['overflow'])->toBeLessThanOrEqual(0)
            ->and($walletShow['errors'])->toBe([])->and($walletShow['overflow'])->toBeLessThanOrEqual(0);

        $measured[$width] = ['wall' => $wall, 'zapPanel' => $zapPanel, 'sponsors' => $sponsors, 'outsideForm' => $outsideForm, 'shortForm' => $shortForm, 'shortPayouts' => $shortState, 'invoice' => $invoice, 'received' => $received, 'payouts' => $payouts, 'fixed' => $fixed, 'admin' => $adminState, 'settings' => $settings, 'create' => $create, 'edit' => $edit, 'index' => $index, 'walletShow' => $walletShow];
    }

    expect($finished->payouts()->pluck('status')->map->value->sort()->values()->all())->toBe(['open', 'paid', 'paid', 'paid'])
        ->and(count($finishedPot->payRequests()) - $requests)->toBe(3);

    // Positive control: an error thrown on the page reaches the collector.
    $desk->evaluate('() => { setTimeout(() => { throw new Error("probe"); }, 0); }');
    BrowserWait::until($desk, '() => window.__errors.length > 0', 5_000);
    expect(implode(' | ', $desk->evaluate(TOURNAMENT_STATE)['errors']))->toContain('probe');

    fwrite(STDERR, "\n[p9-pool] ".json_encode($measured)."\n");
});
