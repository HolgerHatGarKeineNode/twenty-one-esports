<?php

use App\Models\ChessQueueEntry;
use App\Models\NostrEvent;
use App\Models\TrustRank;
use App\Models\TrustRun;
use App\Models\User;
use App\Support\Nostr\SignedEvent;
use App\Support\SeasonChain\AnchoredTrustFacts;
use App\Support\SeasonChain\Opponents;
use App\Support\SeasonChain\Seasons;
use App\Support\SeasonChain\TrustFacts;
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
| Add as opponent, and the Rated choice in the lobby (P7e)
|--------------------------------------------------------------------------
|
| One player, one browser context: the lobby first offers no Rated (nobody
| lists them back), then they add a player who already lists them on that
| player's page (signed with the stubbed window.nostr), and the lobby opens
| Rated and searches rated. Each page at 375 and 1440 px: no horizontal
| overflow, the new controls inside the viewport.
|
| Collected on every page: console.error, uncaught errors, rejected promises,
| every fetch/XHR answer >= 400 (or 0), and the resource timing entries >= 400.
|
| P7E_SHOTS=<dir> additionally writes the English screenshots there.
|
*/

const P7E_COLLECTOR = <<<'JS'
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

const P7E_BAD_RESPONSES = <<<'JS'
    () => performance.getEntries()
        .filter((e) => typeof e.responseStatus === 'number' && e.responseStatus >= 400)
        .map((e) => e.responseStatus + ' ' + e.name)
    JS;

beforeEach(function () {
    Http::fake(fn () => Http::response([]));

    config(['session.driver' => 'database']);
    config(['esports.chess.lobby_poll_seconds' => 3600, 'esports.chess.queue.range.every_seconds' => 3600]);

    app()->rebinding('request', function ($app): void {
        $app['session']->forgetDrivers();
        $app->forgetInstance('session.store');
        $app->forgetInstance('auth.driver');
        $app['auth']->forgetGuards();
        $app['livewire']->flushState();
    });
});

function p7ePage(User $user, string $to, int $width): Page
{
    $page = visit(BrowserLogin::url($user))->page();
    $page->context()->addInitScript(P7E_COLLECTOR);
    $page->context()->addInitScript(TestSigner::browserStub($user));
    $page->setViewportSize($width, 900);
    $page->goto(ComputeUrl::from($to));

    return $page;
}

function p7eShot(Page $page, string $name): void
{
    $dir = getenv('P7E_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    File::ensureDirectoryExists($dir);
    $page->screenshot(true, $name);
    File::move(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
}

/**
 * At this width: no horizontal overflow, the element inside the viewport,
 * no collected error and no bad response. Logs the measured box.
 */
function p7eMeasure(Page $page, string $selector, int $width, string $label): void
{
    $page->setViewportSize($width, 900);
    BrowserWait::until($page, '() => document.documentElement.clientWidth === '.$width, 5_000);
    $box = $page->evaluate('() => { const r = document.querySelector('.json_encode($selector).').getBoundingClientRect(); return [Math.round(r.left), Math.round(r.right), Math.round(r.width), Math.round(r.height)]; }');
    $scroll = $page->evaluate('() => [document.documentElement.scrollWidth, document.documentElement.clientWidth]');
    fwrite(STDERR, "\n[p7e] {$label} {$width}px {$selector} left/right/width/height: ".json_encode($box).', scrollWidth/clientWidth: '.json_encode($scroll)."\n");

    expect($scroll[0])->toBeLessThanOrEqual($scroll[1])
        ->and($box[0])->toBeGreaterThanOrEqual(0)
        ->and($box[1])->toBeLessThanOrEqual($width)
        ->and($page->evaluate('() => window.__errors'))->toBe([])
        ->and($page->evaluate(P7E_BAD_RESPONSES))->toBe([]);
}

test('a player adds an opponent who lists them back, and the lobby then opens Rated and searches rated', function () {
    openSeason();
    config(['esports.chess.rated_queue' => true]);
    app()->bind(TrustFacts::class, AnchoredTrustFacts::class);

    [$anna, $bert] = [User::factory()->create(['name' => 'anna']), User::factory()->create(['name' => 'bert'])];
    $annaSigner = TestSigner::forBrowser($anna);
    $bertSigner = TestSigner::forBrowser($bert);
    $opponents = app(Opponents::class);
    $opponents->add($bert, $anna, $bertSigner->signTemplates($opponents->prepareAdd($bert, $anna)));

    // A trust run of the live season: both Trusted.
    $trust = new TestSigner;
    $anchors = NostrEvent::fromSigned(SignedEvent::fromInput($trust->sign(30000, [['d', 'esports/x/anchors'], ['alt', 'anchors']])));
    $run = TrustRun::query()->create(['season_id' => Seasons::live()?->id, 'trust_pubkey' => $trust->pubkey, 'anchor_list_nostr_event_id' => $anchors->id,
        'anchors' => 0, 'lists' => 0, 'ranked' => 2, 'published' => 2, 'computed_at' => now()]);
    foreach ([$anna, $bert] as $player) {
        $assertion = NostrEvent::fromSigned(SignedEvent::fromInput($trust->sign(30382, [['d', $player->pubkey], ['p', $player->pubkey], ['rank', '100'], ['alt', 'rank']])));
        TrustRank::query()->create(['pubkey' => $player->pubkey, 'rank' => 100, 'raw' => 1.0, 'anchor_list_event_id' => $anchors->event_id,
            'trust_run_id' => $run->id, 'nostr_event_id' => $assertion->id, 'event_id' => $assertion->event_id]);
    }

    // The lobby before: Rated closed, with the reason.
    $lobby = p7ePage($anna, route('chess.lobby', [], false), 375);
    BrowserWait::until($lobby, '() => document.querySelector("[data-test=kind-rated]")?.disabled === true', 10_000);
    expect($lobby->evaluate('() => document.querySelector("[data-test=kind-why]").innerText'))->toContain('Rated play needs a player you list each other with');
    p7eMeasure($lobby, '[data-test=game-kind]', 375, 'lobby rated closed');
    p7eShot($lobby, 'lobby-rated-closed-375');

    // The player page: bert lists anna, anna adds him back.
    $player = p7ePage($anna, route('players.show', $bert->npub, false), 375);
    BrowserWait::until($player, '() => document.querySelector("[data-test=opponent]")?.dataset.state === "lists-you"', 10_000);
    p7eMeasure($player, '[data-test=opponent]', 375, 'player lists you');
    p7eShot($player, 'player-add-back-375');
    $player->locator('[data-test=opponent-add]')->click();
    BrowserWait::until($player, '() => document.querySelector("[data-test=opponent]")?.dataset.state === "mutual"', 15_000);

    expect($opponents->listEachOther($anna, $bert))->toBeTrue()
        ->and(NostrEvent::query()->where('kind', 30000)->where('pubkey', $anna->pubkey)->count())->toBe(1);

    foreach ([375, 1440] as $width) {
        p7eMeasure($player, '[data-test=opponent]', $width, 'player mutual');
        p7eShot($player, "player-mutual-{$width}");
    }

    // The lobby after: Rated open; choose it and search.
    $lobby->goto(ComputeUrl::from(route('chess.lobby', [], false)));
    BrowserWait::until($lobby, '() => document.querySelector("[data-test=kind-rated]")?.disabled === false && window.Alpine !== undefined', 10_000);
    $lobby->locator('[data-test=kind-rated]')->click();
    BrowserWait::until($lobby, '() => document.querySelector("[data-test=kind-rated]").getAttribute("aria-checked") === "true"', 5_000);
    // x-show applies a tick after the radio's own bindings: wait for the text, not the attribute.
    BrowserWait::until($lobby, '() => document.querySelector("[data-test=kind-why]").innerText.includes("(you have 1)")', 5_000);

    foreach ([1440, 375] as $width) {
        p7eMeasure($lobby, '[data-test=game-kind]', $width, 'lobby rated open');
        p7eShot($lobby, "lobby-rated-open-{$width}");
    }

    $lobby->locator('[data-test=find-opponent-button]')->click();
    BrowserWait::until($lobby, '() => document.querySelector("[data-test=searching-kind]") !== null', 10_000);

    expect($lobby->evaluate('() => document.querySelector("[data-test=searching-kind]").innerText'))->toBe('Blitz · rated')
        ->and(ChessQueueEntry::query()->where('user_id', $anna->id)->value('rated'))->toBeTrue();
    p7eMeasure($lobby, '[data-test=searching]', 375, 'lobby searching rated');
    p7eShot($lobby, 'lobby-searching-rated-375');

    // The settings list, both widths.
    $settings = p7ePage($anna, route('settings.opponents', [], false), 1440);
    BrowserWait::until($settings, '() => document.querySelector("[data-test=opponent-entry-mutual]") !== null', 10_000);
    foreach ([1440, 375] as $width) {
        p7eMeasure($settings, '[data-test=opponent-list]', $width, 'settings opponents');
        p7eShot($settings, "settings-opponents-{$width}");
    }

    // Positive control: the collector sees a thrown error on this page.
    $settings->evaluate('() => setTimeout(() => { throw new Error("positive control"); })');
    BrowserWait::until($settings, '() => window.__errors.some((e) => e.includes("positive control"))', 5_000);
});
