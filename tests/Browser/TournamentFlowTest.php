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
/** The "Fill the pot" card in view, the viewport only (P9_SHOTS). */
function potFillShot(Page $page, string $name): void
{
    $dir = getenv('P9_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    File::ensureDirectoryExists($dir);
    $page->evaluate('() => document.querySelector("#pot-fill").scrollIntoView({ block: "start" })');
    $page->screenshot(false, $name);
    File::move(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
}

/** The "Fill the pot" card: its box, chips, actions, hint lines, and whether the pool address shows as text. */
const POT_FILL = <<<'JS'
    (address) => {
        const card = document.querySelector('[data-test=pot-fill]');
        const r = card.getBoundingClientRect();
        const box = (el) => { const b = el.getBoundingClientRect(); return { left: Math.round(b.left), right: Math.round(b.right), top: Math.round(b.top), height: Math.round(b.height) }; };
        const hint = card.querySelector('[data-test=pot-fill-hint]');
        return {
            box: { left: Math.round(r.left), right: Math.round(r.right), scrollWidth: card.scrollWidth, clientWidth: card.clientWidth },
            cards: document.querySelectorAll('[data-test=pot-fill]').length,
            amounts: [...card.querySelectorAll('[data-test=pot-fill-amount]')].map((el) => box(el)),
            field: box(card.querySelector('[data-test=topup-amount]').closest('label')),
            zap: box(card.querySelector('[data-test=pot-zap-preview]')),
            pay: box(card.querySelector('[data-test=topup]')),
            hintLines: [...hint.children].reduce((n, line) => n + Math.round(line.getBoundingClientRect().height / parseFloat(getComputedStyle(line).lineHeight)), 0),
            text: card.innerText,
            address: document.body.innerText.includes(address),
        };
    }
    JS;

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
        // Rocket League is played in the player's own copy: the sign-up needs the ownership tick.
        $page->locator('[data-test=owns-game]')->check();
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
        // One card fills the pot (user, 2026-10-03): one set of amounts, "Zap with Nostr" first, "Pay without Nostr" beside it.
        $page->evaluate('() => document.querySelector("#pot-fill").scrollIntoView()');
        $zapPanel = [...$page->evaluate(TOURNAMENT_STATE), ...$page->evaluate(POT_FILL, PoolInvoices::address())];
        potFillShot($page, "pot-fill-en-{$width}");
        // The amount picked is the invoice's: 2 100 sats without Nostr, into this pot.
        $page->evaluate('() => document.querySelectorAll("[data-test=pot-fill-amount]")[1].click()');
        $page->locator('[data-test=topup]')->click();
        BrowserWait::until($page, '() => document.querySelector("[data-test=topup-qr] svg") !== null', 10_000);
        $picked = IncomingPayment::query()->latest('id')->firstOrFail();
        $zapPanel['afterPay'] = $page->evaluate(TOURNAMENT_STATE);

        expect($wall['errors'])->toBe([])->and($wall['overflow'])->toBeLessThanOrEqual(0)
            ->and($wall['entries'])->toHaveCount(2)
            ->and($wall['entries'][0]['text'])->toContain('21')->and($wall['entries'][1]['text'])->toContain('satoshi_stacker')
            ->and(collect($wall['entries'])->every(fn (array $e): bool => $e['avatar'] >= 32 && $e['height'] >= 44))->toBeTrue()
            ->and($wall['list']['left'])->toBeGreaterThanOrEqual(0)->and($wall['list']['right'])->toBeLessThanOrEqual($width)
            ->and($wall['onTop'])->toContain('26')->and($wall['pot'])->toContain('126')
            ->and($zapPanel['errors'])->toBe([])->and($zapPanel['overflow'])->toBeLessThanOrEqual(0)
            ->and($zapPanel['afterPay']['errors'])->toBe([])
            ->and($zapPanel['cards'])->toBe(1)
            ->and($zapPanel['box']['left'])->toBeGreaterThanOrEqual(0)->and($zapPanel['box']['right'])->toBeLessThanOrEqual($width)
            ->and($zapPanel['box']['scrollWidth'])->toBeLessThanOrEqual($zapPanel['box']['clientWidth'])
            ->and($zapPanel['amounts'])->toHaveCount(4)->and(min(array_column($zapPanel['amounts'], 'height')))->toBeGreaterThanOrEqual(44)
            ->and(max(array_column($zapPanel['amounts'], 'right')))->toBeLessThanOrEqual($zapPanel['box']['right'])
            ->and($zapPanel['field']['height'])->toBeGreaterThanOrEqual(44)->and($zapPanel['field']['right'])->toBeLessThanOrEqual($zapPanel['box']['right'])
            ->and([$zapPanel['zap']['height'], $zapPanel['pay']['height']])->each->toBeGreaterThanOrEqual(44)
            ->and($zapPanel['hintLines'])->toBeLessThanOrEqual(2)
            ->and($zapPanel['address'])->toBeFalse()
            ->and($picked->amount_sats)->toBe(2_100)->and($picked->source)->toBe('topup')->and($picked->pot)->toBe('tournament:'.$open->id);
        // The zap first: above "Pay without Nostr" on a phone, left of it on a desk.
        expect($width < 640 ? $zapPanel['zap']['top'] < $zapPanel['pay']['top'] : ($zapPanel['zap']['top'] === $zapPanel['pay']['top'] && $zapPanel['zap']['right'] < $zapPanel['pay']['left']))->toBeTrue();

        // The German card on a phone: the longer words still fit, two hint lines at most.
        if ($width === 375) {
            $player->forceFill(['locale' => 'de'])->save();
            $german = tournamentPage($player);
            $german->setViewportSize($width, $height);
            $german->goto(ComputeUrl::from(route('tournaments.show', $open)));
            BrowserWait::until($german, '() => document.querySelector("[data-test=pot-fill]") !== null', 8_000);
            $german->evaluate('() => document.querySelector("#pot-fill").scrollIntoView()');
            $de = [...$german->evaluate(TOURNAMENT_STATE), ...$german->evaluate(POT_FILL, PoolInvoices::address())];
            potFillShot($german, 'pot-fill-de-375');
            $player->forceFill(['locale' => 'en'])->save();

            expect($de['errors'])->toBe([])->and($de['overflow'])->toBeLessThanOrEqual(0)
                ->and($de['text'])->toContain('Den Topf füllen', 'Mit Nostr zappen', 'Ohne Nostr bezahlen')
                ->and($de['box']['scrollWidth'])->toBeLessThanOrEqual($de['box']['clientWidth'])
                ->and(max(array_column($de['amounts'], 'right')))->toBeLessThanOrEqual($de['box']['right'])
                ->and($de['field']['right'])->toBeLessThanOrEqual($de['box']['right'])
                ->and($de['zap']['right'])->toBeLessThanOrEqual($de['box']['right'])->and($de['pay']['right'])->toBeLessThanOrEqual($de['box']['right'])
                ->and($de['hintLines'])->toBeLessThanOrEqual(2)
                ->and($de['address'])->toBeFalse();
        }

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

/*
| "Fill the pot" notices a zap by itself (user, 2026-10-03: „21 sats
| gezapped, aber es kommt keine automatische Meldung, dass es ankam."): the
| player zaps 210 sats with Nostr, the fake wallet settles the invoice, and
| the card switches to the thank-you without a reload, the pot and the
| sponsors' wall with it. At en 375 and 1440 and de 375, collector armed,
| with a positive control. P9_SHOTS=<dir> writes the screenshots there.
*/
const POT_ARRIVED = <<<'JS'
    (pubkey) => {
        const card = document.querySelector('[data-test=pot-fill]');
        const r = card.getBoundingClientRect();
        const note = card.querySelector('[data-test=topup-received]');
        const n = note?.getBoundingClientRect();
        return {
            box: { left: Math.round(r.left), right: Math.round(r.right), scrollWidth: card.scrollWidth, clientWidth: card.clientWidth },
            note: note ? { text: note.innerText, left: Math.round(n.left), right: Math.round(n.right), height: Math.round(n.height), scrollWidth: note.scrollWidth, clientWidth: note.clientWidth } : null,
            qr: card.querySelector('[data-test=topup-qr]') !== null,
            polling: card.querySelector('[wire\\:poll\\.3s]') !== null,
            pot: document.querySelector('[data-test=pool-sats]').innerText,
            wall: document.querySelector('[data-test=pool-zapper][data-pubkey="' + pubkey + '"] [data-test=pool-zapper-sats]')?.innerText ?? null,
            reloaded: window.__potMarker !== 1,
        };
    }
    JS;

test('a paid zap switches the "Fill the pot" card to the thank-you by itself, with the pot and the wall, at en 375 and 1440 and de 375', function () {
    $league = fakeWallet();
    $open = openTournament(['name' => 'Halving Cup', 'capacity' => 8], rocketLeague: true);
    app(PrizePool::class)->configurePot($open, $open->creator, true, 100_000, Tournament::PRIZES_PERCENT, [50, 30, 20]);
    fundPool($league, $open->refresh(), 42_000);
    $player = User::factory()->create(['name' => 'hodlqueen', 'locale' => 'en']);
    TestSigner::forBrowser($player);
    $pubkey = (string) $player->refresh()->pubkey;
    $measured = [];
    $page = null;

    foreach ([['en', 375, 812], ['en', 1440, 900], ['de', 375, 812]] as [$locale, $width, $height]) {
        $player->forceFill(['locale' => $locale])->save();
        $page = tournamentPage($player);
        $page->setViewportSize($width, $height);
        $page->goto(ComputeUrl::from(route('tournaments.show', $open)));
        BrowserWait::until($page, '() => document.querySelector("[data-test=pot-zap-preview]") !== null', 8_000);
        $page->evaluate('() => document.querySelector("#pot-fill").scrollIntoView()');
        $before = $page->evaluate(POT_ARRIVED, $pubkey);

        // 210 sats with Nostr: preview, sign, and the card shows the zap's invoice and polls it.
        $page->evaluate('() => document.querySelectorAll("[data-test=pot-fill-amount]")[0].click()');
        $page->locator('[data-test=pot-zap-preview]')->click();
        BrowserWait::until($page, '() => document.querySelector("[data-test=pot-zap-sign-button]")?.checkVisibility() === true', 8_000);
        $page->locator('[data-test=pot-zap-sign-button]')->click();
        BrowserWait::until($page, '() => document.querySelector("[data-test=topup-qr] svg") !== null', 10_000);
        $zap = IncomingPayment::query()->latest('id')->firstOrFail();
        $page->evaluate('() => { window.__potMarker = 1; }');
        $waiting = $page->evaluate(POT_ARRIVED, $pubkey);
        potFillShot($page, "pot-zap-waiting-{$locale}-{$width}");

        // Paid: the league wallet settles it, the card notices by itself, no reload.
        $league->settleIncoming($zap->payment_hash);
        $paidAt = microtime(true);
        BrowserWait::until($page, '() => document.querySelector("[data-test=topup-received]") !== null', 15_000);
        $switchMs = (int) round((microtime(true) - $paidAt) * 1000);
        // The page around the card renders its pot and wall again: the player's zapped sum changes.
        BrowserWait::until($page, '() => (document.querySelector("[data-test=pool-zapper][data-pubkey=\''.$pubkey.'\'] [data-test=pool-zapper-sats]")?.innerText ?? null) !== '.json_encode($before['wall']), 8_000);
        $arrived = [...$page->evaluate(TOURNAMENT_STATE), ...$page->evaluate(POT_ARRIVED, $pubkey)];
        potFillShot($page, "pot-zap-arrived-{$locale}-{$width}");

        expect($zap->source)->toBe('zap')->and($zap->amount_sats)->toBe(210)->and($zap->payer_pubkey)->toBe($pubkey)
            ->and($zap->refresh()->zap_verified)->toBeTrue()
            ->and($waiting['qr'])->toBeTrue()->and($waiting['polling'])->toBeTrue()
            ->and($switchMs)->toBeLessThan(10_000)
            ->and($arrived['errors'])->toBe([])->and($arrived['overflow'])->toBeLessThanOrEqual(0)
            ->and($arrived['reloaded'])->toBeFalse()
            ->and($arrived['qr'])->toBeFalse()->and($arrived['polling'])->toBeFalse()
            ->and($arrived['note']['text'])->toBe($locale === 'de' ? '✓ 210 Sats angekommen, danke!' : '✓ 210 sats arrived, thank you!')
            ->and($arrived['note']['left'])->toBeGreaterThanOrEqual($arrived['box']['left'])->and($arrived['note']['right'])->toBeLessThanOrEqual($arrived['box']['right'])
            ->and($arrived['note']['scrollWidth'])->toBeLessThanOrEqual($arrived['note']['clientWidth'])
            ->and($arrived['box']['left'])->toBeGreaterThanOrEqual(0)->and($arrived['box']['right'])->toBeLessThanOrEqual($width)
            ->and($arrived['pot'])->not->toBe($before['pot'])
            ->and($arrived['wall'])->not->toBeNull()->and($arrived['wall'])->not->toBe($before['wall']);

        $measured["{$locale}-{$width}"] = ['before' => $before, 'waiting' => $waiting, 'switchMs' => $switchMs, 'arrived' => $arrived];
    }

    // Positive control: an error thrown on the page reaches the collector.
    $page->evaluate('() => { setTimeout(() => { throw new Error("probe"); }, 0); }');
    BrowserWait::until($page, '() => window.__errors.length > 0', 5_000);
    expect(implode(' | ', $page->evaluate(TOURNAMENT_STATE)['errors']))->toContain('probe');

    fwrite(STDERR, "\n[pot-zap-arrived] ".json_encode($measured)."\n");
});
