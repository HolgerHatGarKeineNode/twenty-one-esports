<?php

use App\Models\ChessGame;
use App\Models\Clan;
use App\Models\Lineup;
use App\Models\NostrEvent;
use App\Models\TrustRank;
use App\Models\TrustRun;
use App\Models\User;
use App\Support\Nostr\SignedEvent;
use App\Support\SeasonChain\AnchoredTrustFacts;
use App\Support\SeasonChain\Opponents;
use App\Support\SeasonChain\Seasons;
use App\Support\SeasonChain\TrustFacts;
use App\Support\Series\ChallengeDraft;
use App\Support\Series\SeriesService;
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
| Opponent requests and the "you do not list each other" notice (P57)
|--------------------------------------------------------------------------
|
| /settings/opponents: the explanation on top, one request card per player
| who lists you (with what the league knows about them), decline and "show
| again", accept (signed with the stubbed window.nostr). The match room: a
| captain who cannot accept a rated challenge because the two do not list
| each other sees why and accepts the other's request in one click.
|
| Each state at 375 and 1440 px, in English and German: no horizontal
| overflow, every button and link in the new blocks at least 44 x 44 px,
| explanation lines at most 68 characters, the console and every response
| clean (a thrown error as positive control at the end).
|
| P57_SHOTS=<dir> additionally writes the screenshots there.
|
*/

const P57_COLLECTOR = <<<'JS'
    window.__errors = JSON.parse(sessionStorage.getItem('__errors') ?? '[]');
    const push = (entry) => { window.__errors.push(entry); sessionStorage.setItem('__errors', JSON.stringify(window.__errors)); };
    const originalError = console.error;
    console.error = function (...args) { push('console.error: ' + args.map(String).join(' ')); originalError.apply(console, args); };
    window.addEventListener('error', (e) => push('error: ' + (e.message || (e.target && (e.target.src || e.target.href)) || 'unknown')), true);
    window.addEventListener('unhandledrejection', (e) => push('unhandledrejection: ' + String(e.reason)));
    const originalFetch = window.fetch;
    window.fetch = (...args) => originalFetch(...args).then((r) => { if (r.status >= 400) push(r.status + ' ' + r.url); return r; });
    const originalOpen = XMLHttpRequest.prototype.open;
    XMLHttpRequest.prototype.open = function (method, url, ...rest) {
        this.addEventListener('loadend', () => { if (this.status >= 400 || this.status === 0) push('xhr ' + this.status + ' ' + url); });
        return originalOpen.call(this, method, url, ...rest);
    };
    JS;

const P57_BAD_RESPONSES = <<<'JS'
    () => performance.getEntries()
        .filter((e) => typeof e.responseStatus === 'number' && e.responseStatus >= 400)
        .map((e) => e.responseStatus + ' ' + e.name)
    JS;

/** Interactive elements under the selector that are visible and smaller than 44 x 44 px. */
const P57_SMALL_TARGETS = <<<'JS'
    (selector) => [...document.querySelectorAll(selector)].flatMap((root) => [...root.querySelectorAll('a, button, summary')])
        .filter((e) => e.checkVisibility())
        .map((e) => { const r = e.getBoundingClientRect(); return [e.dataset.test || e.textContent.trim().slice(0, 24), Math.round(r.width), Math.round(r.height)]; })
        .filter(([, w, h]) => w < 44 || h < 44)
    JS;

/** The widest line of each explanation text, in characters of its own font (the house font is monospace); hidden ones have no line. */
const P57_LINE_CHARS = <<<'JS'
    () => [...document.querySelectorAll('[data-test=opponent-explainer] p, [data-test=opponent-explainer] dd, [data-test=needs-mutual] p')].map((e) => {
        const cs = getComputedStyle(e);
        const ctx = document.createElement('canvas').getContext('2d');
        ctx.font = cs.fontWeight + ' ' + cs.fontSize + ' ' + cs.fontFamily;
        const range = document.createRange();
        range.selectNodeContents(e);
        const widest = Math.max(...[...range.getClientRects()].map((r) => r.width));
        return Math.round(widest / ctx.measureText('0').width);
    }).filter(Number.isFinite)
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

    $this->league = new TestSigner;
    config(['esports.league.nsec' => $this->league->secret]);
});

function p57Page(User $user, string $to, int $width, string $locale): Page
{
    $page = visit(BrowserLogin::url($user))->page();
    $page->context()->addInitScript(P57_COLLECTOR);
    $page->context()->addInitScript(TestSigner::browserStub($user));
    $page->setViewportSize($width, 900);
    $page->goto(ComputeUrl::from(route('locale.switch', $locale, false)));
    $page->goto(ComputeUrl::from($to));
    BrowserWait::until($page, '() => document.documentElement.lang === '.json_encode($locale).' && window.Alpine !== undefined', 10_000);

    return $page;
}

function p57Shot(Page $page, string $name): void
{
    $dir = getenv('P57_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    File::ensureDirectoryExists($dir);
    $page->screenshot(true, $name);
    File::move(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
}

/**
 * At this width: no horizontal overflow, the block inside the viewport,
 * targets at least 44 px, explanation lines at most 68 characters, no
 * collected error and no bad response. Logs the measured numbers.
 */
function p57Measure(Page $page, string $selector, int $width, string $label): void
{
    $page->setViewportSize($width, 900);
    BrowserWait::until($page, '() => document.documentElement.clientWidth === '.$width, 5_000);
    // Let the reflow of the resize settle before measuring.
    $page->evaluate('() => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)))');
    $box = $page->evaluate('() => { const r = document.querySelector('.json_encode($selector).').getBoundingClientRect(); return [Math.round(r.left), Math.round(r.right), Math.round(r.width), Math.round(r.height)]; }');
    $scroll = $page->evaluate('() => [document.documentElement.scrollWidth, document.documentElement.clientWidth]');
    $small = $page->evaluate(P57_SMALL_TARGETS, $selector);
    $chars = $page->evaluate(P57_LINE_CHARS);
    fwrite(STDERR, "\n[p57] {$label} {$width}px {$selector} left/right/width/height: ".json_encode($box).', scrollWidth/clientWidth: '.json_encode($scroll)
        .', line chars: '.json_encode($chars).', small targets: '.json_encode($small)."\n");

    expect($scroll[0])->toBeLessThanOrEqual($scroll[1])
        ->and($box[0])->toBeGreaterThanOrEqual(0)
        ->and($box[1])->toBeLessThanOrEqual($width)
        ->and($small)->toBe([])
        ->and($chars === [] ? 0 : max($chars))->toBeLessThanOrEqual(68)
        ->and($page->evaluate('() => window.__errors'))->toBe([])
        ->and($page->evaluate(P57_BAD_RESPONSES))->toBe([]);
}

/** A trust run of the live season: each given player at rank 100. */
function p57TrustRun(User ...$players): void
{
    $trust = new TestSigner;
    $anchors = NostrEvent::fromSigned(SignedEvent::fromInput($trust->sign(30000, [['d', 'esports/x/anchors'], ['alt', 'anchors']])));
    $run = TrustRun::query()->create(['season_id' => Seasons::live()?->id, 'trust_pubkey' => $trust->pubkey, 'anchor_list_nostr_event_id' => $anchors->id,
        'anchors' => 0, 'lists' => 0, 'ranked' => count($players), 'published' => count($players), 'computed_at' => now()]);

    foreach ($players as $player) {
        $assertion = NostrEvent::fromSigned(SignedEvent::fromInput($trust->sign(30382, [['d', $player->pubkey], ['p', $player->pubkey], ['rank', '100'], ['alt', 'rank']])));
        TrustRank::query()->create(['pubkey' => $player->pubkey, 'rank' => 100, 'raw' => 1.0, 'anchor_list_event_id' => $anchors->event_id,
            'trust_run_id' => $run->id, 'nostr_event_id' => $assertion->id, 'event_id' => $assertion->event_id]);
    }
}

function p57Add(User $player, TestSigner $signer, User $opponent): void
{
    $opponents = app(Opponents::class);
    $opponents->add($player, $opponent, $signer->signTemplates($opponents->prepareAdd($player, $opponent)));
}

test('requests: the explanation, a card per requester with league facts, decline and show again, and accept, at 375 and 1440 in English and German', function () {
    openSeason();
    app()->bind(TrustFacts::class, AnchoredTrustFacts::class);

    $signers = [];
    $users = [];
    foreach (['anna', 'Bertrand von Längenfeld-Überlänge', 'newcomer'] as $name) {
        $signers[$name] = TestSigner::forBrowser($users[$name] = User::factory()->create(['name' => $name]));
    }
    ['anna' => $anna, 'Bertrand von Längenfeld-Überlänge' => $bert, 'newcomer' => $newbie] = $users;
    $bert->forceFill(['created_at' => now()->subMonths(3)])->save();
    Clan::factory()->create(['name' => 'Laser Eyes of the Northern Shore', 'owner_id' => $bert->id]);
    ChessGame::factory()->finished()->count(3)->create(['white_id' => $bert->id]);
    p57TrustRun($anna, $bert);

    p57Add($bert, $signers['Bertrand von Längenfeld-Überlänge'], $anna);
    p57Add($newbie, $signers['newcomer'], $anna);

    expect($anna->notifications()->count())->toBe(2);

    foreach (['de', 'en'] as $locale) {
        $page = p57Page($anna, route('settings.opponents', [], false), 375, $locale);
        BrowserWait::until($page, '() => document.querySelectorAll("[data-test=opponent-request]").length === 2', 10_000);

        $facts = $page->evaluate('() => [...document.querySelectorAll("[data-test=opponent-request]")].map((c) => c.innerText.replace(/\s+/g, " "))');
        fwrite(STDERR, "\n[p57] {$locale} cards: ".json_encode($facts, JSON_UNESCAPED_UNICODE)."\n");
        expect($page->evaluate('() => document.querySelector("[data-test=opponent-explainer]").checkVisibility()'))->toBeTrue()
            ->and($page->evaluate('() => document.querySelector("[data-pubkey=\''.$bert->pubkey.'\'] [data-test=fact-trust]").innerText'))->toContain('Trusted')
            ->and($page->evaluate('() => document.querySelector("[data-pubkey=\''.$bert->pubkey.'\'] [data-test=fact-games]").innerText'))->toContain('3')
            ->and($page->evaluate('() => document.querySelector("[data-pubkey=\''.$newbie->pubkey.'\'] [data-test=opponent-request-new]")?.checkVisibility()'))->toBeTrue()
            ->and($page->evaluate('() => document.querySelector("[data-pubkey=\''.$bert->pubkey.'\'] [data-test=opponent-request-new]")'))->toBeNull();

        foreach ([375, 1440] as $width) {
            p57Measure($page, '[data-test=opponent-requests]', $width, "{$locale} requests");
            p57Measure($page, '[data-test=opponent-explainer]', $width, "{$locale} explainer");
            p57Shot($page, "p57-requests-{$locale}-{$width}");
        }

        // Decline the newcomer: the card goes, "Declined (1)" holds it with the honest note; show it again.
        $page->setViewportSize(375, 900);
        $page->locator('[data-pubkey="'.$newbie->pubkey.'"] [data-test=opponent-request-decline]')->click();
        BrowserWait::until($page, '() => document.querySelectorAll("[data-test=opponent-request]").length === 1 && document.querySelector("[data-test=opponent-declined]") !== null', 10_000);
        $page->locator('[data-test=opponent-declined-toggle]')->click();
        BrowserWait::until($page, '() => document.querySelector("[data-test=opponent-declined]").open === true', 5_000);
        foreach ([375, 1440] as $width) {
            p57Measure($page, '[data-test=opponent-requests]', $width, "{$locale} declined open");
            p57Shot($page, "p57-declined-{$locale}-{$width}");
        }
        $page->setViewportSize(375, 900);
        $page->locator('[data-test=opponent-declined-restore]')->click();
        BrowserWait::until($page, '() => document.querySelectorAll("[data-test=opponent-request]").length === 2 && document.querySelector("[data-test=opponent-declined]") === null', 10_000);
    }

    // Accept Bertrand: the signed add from the card; the list shows him as mutual.
    $page->locator('[data-pubkey="'.$bert->pubkey.'"] [data-test=opponent-request-accept]')->click();
    try {
        BrowserWait::until($page, '() => document.querySelectorAll("[data-test=opponent-request]").length === 1 && document.querySelector("[data-test=opponent-entry-mutual]") !== null', 15_000);
    } catch (Throwable $e) {
        fwrite(STDERR, "\n[p57-debug] ".json_encode($page->evaluate('() => [document.documentElement.lang, [...document.querySelectorAll("[role=alert]")].map((e) => e.innerText), document.querySelectorAll("[data-test=opponent-request]").length, document.querySelector("[data-test=opponent-list]").innerText]'), JSON_UNESCAPED_UNICODE)."\n");
        throw $e;
    }

    expect(app(Opponents::class)->listEachOther($anna, $bert))->toBeTrue()
        ->and($anna->notifications()->count())->toBe(2);

    foreach ([1440, 375] as $width) {
        p57Measure($page, '[data-test=opponent-settings]', $width, 'en accepted');
        p57Shot($page, "p57-accepted-en-{$width}");
    }

    // Positive control: the collector sees a thrown error on this page.
    $page->evaluate('() => setTimeout(() => { throw new Error("positive control"); })');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("positive control"))', 5_000);
});

test('room: a captain who cannot accept a rated challenge because the two do not list each other sees why and accepts the sender\'s request in one click', function () {
    openSeason();
    app()->instance(TrustFacts::class, new class implements TrustFacts
    {
        public function available(): bool
        {
            return true;
        }

        public function at(array $players, array $gatekeepers): array
        {
            return ['trust' => array_fill_keys($players, 100), 'anchors' => [], 'connected' => true];
        }
    });

    $sides = [];
    foreach (['anna', 'bert'] as $name) {
        $captain = User::factory()->create(['name' => $name]);
        $signer = TestSigner::forBrowser($captain);
        $sides[$name] = [Lineup::factory()->mode('1v1')->ready()->create(['clan_id' => Clan::factory()->create(['owner_id' => $captain->id, 'name' => $name.' clan'])->id]), $captain, $signer];
    }
    [$a, $b] = [$sides['anna'], $sides['bert']];
    $service = app(SeriesService::class);
    $start = now()->addHour()->startOfMinute()->getTimestamp();
    $draft = new ChallengeDraft($a[0]->id, $b[0]->id, 3, true, [$start], $start - 600, '');
    $match = $service->challenge($a[1], $draft, $a[2]->signTemplates($service->prepareChallenge($a[1], $draft)['templates']));
    p57Add($a[1], $a[2], $b[1]);

    foreach (['en', 'de'] as $locale) {
        $page = p57Page($b[1], route('matches.room', $match, false), 375, $locale);
        BrowserWait::until($page, '() => document.querySelector("[data-test=needs-mutual] [data-test=opponent]")?.dataset.state === "lists-you"', 10_000);
        fwrite(STDERR, "\n[p57] {$locale} room notice: ".json_encode($page->evaluate('() => document.querySelector("[data-test=needs-mutual]").innerText.replace(/\s+/g, " ")'), JSON_UNESCAPED_UNICODE)."\n");

        foreach ([375, 1440] as $width) {
            p57Measure($page, '[data-test=needs-mutual]', $width, "{$locale} room notice");
            p57Shot($page, "p57-room-notice-{$locale}-{$width}");
        }
    }

    // One click: bert accepts anna's request inside the notice; the notice goes, the lists are mutual.
    $page->setViewportSize(375, 900);
    $page->locator('[data-test=needs-mutual] [data-test=opponent-add]')->click();
    BrowserWait::until($page, '() => document.querySelector("[data-test=needs-mutual]") === null && document.querySelector("[data-test=accept-challenge]") !== null', 15_000);

    expect(app(Opponents::class)->listEachOther($a[1], $b[1]))->toBeTrue();

    foreach ([375, 1440] as $width) {
        p57Measure($page, '[data-test=answer-card]', $width, 'de room after accept');
        p57Shot($page, "p57-room-after-de-{$width}");
    }

    $page->evaluate('() => setTimeout(() => { throw new Error("positive control"); })');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("positive control"))', 5_000);
});
