<?php

use App\Models\Admin;
use App\Models\ImpersonationHold;
use App\Models\User;
use App\Support\SeasonChain\TrustFacts;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;
use Tests\Support\ChessLobby;
use Tests\Support\TestSigner;
use Tests\Support\TrustedFacts;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| The impersonation hold (plan "Profile standard and impersonation", P2)
|--------------------------------------------------------------------------
|
| The admin decision page with both profiles side by side, the chess lobby
| of the held player, whose Rated badge reads "account being checked", and
| the player's opponents page, which says only that the account is checked.
| Each at 390 and 1440 px, in German and English: no horizontal overflow,
| the block inside the viewport, every button and link at least 44 x 44 px,
| no squeezed text, the console and every response clean (a thrown error as
| positive control at the end).
|
| IH_SHOTS=<dir> additionally writes the screenshots there.
|
*/

const IH_COLLECTOR = <<<'JS'
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

const IH_BAD_RESPONSES = <<<'JS'
    () => performance.getEntries()
        .filter((e) => typeof e.responseStatus === 'number' && e.responseStatus >= 400)
        .map((e) => e.responseStatus + ' ' + e.name)
    JS;

/** Interactive elements under the selector that are visible and smaller than 44 x 44 px (a checkbox counts through its label, not its box). */
const IH_SMALL_TARGETS = <<<'JS'
    (selector) => [...document.querySelectorAll(selector)].flatMap((root) => [...root.querySelectorAll('a, button, summary, input:not([type=checkbox]):not([type=radio])')])
        .filter((e) => e.checkVisibility())
        .map((e) => { const r = e.getBoundingClientRect(); return [e.dataset.test || e.textContent.trim().slice(0, 24), Math.round(r.width), Math.round(r.height)]; })
        .filter(([, w, h]) => w < 44 || h < 44)
    JS;

beforeEach(function () {
    Http::fake(fn () => Http::response([]));
    config(['session.driver' => 'database', 'esports.fair_play.impersonation.enabled' => true]);

    app()->rebinding('request', function ($app): void {
        $app['session']->forgetDrivers();
        $app->forgetInstance('session.store');
        $app->forgetInstance('auth.driver');
        $app['auth']->forgetGuards();
        $app['livewire']->flushState();
    });
});

function ihPage(User $user, string $to, int $width, string $locale): Page
{
    $page = visit(BrowserLogin::url($user))->page();
    $page->context()->addInitScript(IH_COLLECTOR);
    $page->setViewportSize($width, 900);
    $page->goto(ComputeUrl::from(route('locale.switch', $locale, false)));
    $page->goto(ComputeUrl::from($to));
    BrowserWait::until($page, '() => document.documentElement.lang === '.json_encode($locale).' && window.Alpine !== undefined', 10_000);

    return $page;
}

function ihShot(Page $page, string $name): void
{
    $dir = getenv('IH_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    File::ensureDirectoryExists($dir);
    $page->screenshot(true, $name);
    File::move(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
}

/** At this width: no overflow, the block inside the viewport, targets of 44 px, nothing squeezed, no error, no bad response. */
function ihMeasure(Page $page, string $selector, int $width, string $label): void
{
    $page->setViewportSize($width, 900);
    BrowserWait::until($page, '() => document.documentElement.clientWidth === '.$width, 5_000);
    $page->evaluate('() => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)))');
    $box = $page->evaluate('() => { const r = document.querySelector('.json_encode($selector).').getBoundingClientRect(); return [Math.round(r.left), Math.round(r.right), Math.round(r.width), Math.round(r.height)]; }');
    $scroll = $page->evaluate('() => [document.documentElement.scrollWidth, document.documentElement.clientWidth]');
    $small = $page->evaluate(IH_SMALL_TARGETS, $selector);
    $squeezed = $page->evaluate(BrowserConsole::SQUEEZED, [$selector]);
    fwrite(STDERR, "\n[ih] {$label} {$width}px {$selector} left/right/width/height: ".json_encode($box).', scrollWidth/clientWidth: '.json_encode($scroll)
        .', small targets: '.json_encode($small).', squeezed: '.json_encode($squeezed)."\n");

    expect($box[2])->toBeGreaterThan(0)
        ->and($scroll[0])->toBeLessThanOrEqual($scroll[1])
        ->and($box[0])->toBeGreaterThanOrEqual(0)
        ->and($box[1])->toBeLessThanOrEqual($width)
        ->and($small)->toBe([])
        ->and($squeezed)->toBe([])
        ->and($page->evaluate('() => window.__errors'))->toBe([])
        ->and($page->evaluate(IH_BAD_RESPONSES))->toBe([]);
}

test('the decision page shows both profiles side by side, and a release ends the hold, at 390 and 1440 in German and English', function () {
    $admin = User::factory()->create(['name' => 'Referee', 'picture' => null, 'created_at' => now()->subYears(2)]);
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    $original = User::factory()->create(['name' => 'Odell', 'picture' => null, 'nip05' => 'odell@einundzwanzig.space', 'nip05_verified_at' => now(), 'is_member' => true, 'created_at' => now()->subYear()]);
    $held = User::factory()->create(['name' => 'Оdеll', 'picture' => null, 'created_at' => now()->subDay()]);
    $hold = ImpersonationHold::factory()->create(['user_id' => $held->id, 'pubkey' => $held->pubkey, 'matched_user_id' => $original->id, 'matched_pubkey' => $original->pubkey, 'matched' => 'name', 'name_skeleton' => 'odei']);

    foreach (['de', 'en'] as $locale) {
        $page = ihPage($admin, route('admin.checks.hold', $hold, false), 390, $locale);
        BrowserWait::until($page, '() => document.querySelectorAll("[data-test=hold-side]").length === 2', 10_000);

        $text = $page->evaluate('() => document.querySelector("[data-test=admin-hold]").innerText.replace(/\s+/g, " ")');
        expect($text)->toContain('odell@einundzwanzig.space')->toContain($locale === 'de' ? 'Prüfung auf Identitätsmissbrauch' : 'Impersonation check')
            ->and(mb_strtolower($text))->not->toContain('gesicht');

        foreach ([390, 1440] as $width) {
            // Side by side on a wide screen, one under the other on a phone.
            // The page's own blocks; the breadcrumb bar above belongs to the shared admin frame.
            ihMeasure($page, '[data-test=hold-sides]', $width, "{$locale} sides");
            ihMeasure($page, '[data-test=hold-decision]', $width, "{$locale} decision");
            $tops = $page->evaluate('() => [...document.querySelectorAll("[data-test=hold-side]")].map((e) => Math.round(e.getBoundingClientRect().top))');
            fwrite(STDERR, "[ih] {$locale} {$width}px side tops: ".json_encode($tops)."\n");
            expect($width === 1440 ? $tops[0] === $tops[1] : $tops[0] < $tops[1])->toBeTrue();
            ihShot($page, "hold-decision-{$width}x900-{$locale}");
        }
    }

    // Pick "release", confirm the dialog, and the page shows the decision.
    $page->setViewportSize(390, 900);
    $page->locator('[data-test=hold-choice-release]')->click();
    BrowserWait::until($page, '() => document.querySelector("[data-test=hold-choice-release]")?.getAttribute("aria-checked") === "true"', 5_000);
    $page->evaluate('() => { window.confirm = () => true; }');
    $page->locator('[data-test=hold-confirm]')->click();
    BrowserWait::until($page, '() => document.querySelector("[data-test=hold-decided]") !== null', 15_000);

    expect($hold->refresh()->decision)->toBe(ImpersonationHold::RELEASED);
    ihMeasure($page, '[data-test=hold-decision]', 390, 'en decided');

    $page->evaluate('() => setTimeout(() => { throw new Error("positive control"); })');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("positive control"))', 5_000);
});

test('the held player\'s chess lobby badge reads "account being checked", not "barred", and the opponents page says the account is being checked, at 390 and 1440 in German and English', function () {
    app()->bind(TrustFacts::class, TrustedFacts::class);
    config(['esports.chess.rated_queue' => true]);
    $league = new TestSigner;
    config(['esports.league.nsec' => $league->secret]);
    openSeason(['slug' => 'season-1', 'genesis_at' => now()->subDays(10), 'ends_at' => now()->addDays(60)]);
    $original = User::factory()->create(['name' => 'Odell', 'picture' => null, 'created_at' => now()->subYear()]);
    $held = User::factory()->create(['name' => 'Odell', 'picture' => null, 'created_at' => now()->subDays(30)]);
    ImpersonationHold::factory()->create(['user_id' => $held->id, 'pubkey' => $held->pubkey, 'matched_user_id' => $original->id, 'matched_pubkey' => $original->pubkey]);

    foreach (['de', 'en'] as $locale) {
        $page = ihPage($held, route('chess.lobby', [], false), 390, $locale);
        ChessLobby::openBlitz($page);
        BrowserWait::until($page, '() => document.querySelector("[data-test=kind-rated-badge]")?.textContent.trim() !== ""', 10_000);

        $badge = $page->evaluate('() => { const b = document.querySelector("[data-test=kind-rated-badge]"); return [b.dataset.kind, b.textContent.trim()]; }');
        fwrite(STDERR, "\n[ih] {$locale} lobby badge: ".json_encode($badge, JSON_UNESCAPED_UNICODE)."\n");
        expect($badge)->toBe(['held', $locale === 'de' ? 'Konto wird geprüft' : 'account being checked']);

        foreach ([390, 1440] as $width) {
            ihMeasure($page, '#lobby-quick', $width, "{$locale} lobby");
            ihShot($page, "lobby-held-{$width}x900-{$locale}");
        }

        // The opponents page, where the player's state for rated play stands: only that the account is being checked.
        $page->goto(ComputeUrl::from(route('settings.opponents', [], false)));
        BrowserWait::until($page, '() => document.querySelector("[data-test=rated-held]") !== null && window.Alpine !== undefined', 10_000);
        $notice = $page->evaluate('() => document.querySelector("[data-test=rated-standard]").innerText.replace(/\s+/g, " ")');
        fwrite(STDERR, "\n[ih] {$locale} opponents notice: ".$notice."\n");
        expect($notice)->toContain($locale === 'de' ? 'Dein Konto wird gerade geprüft.' : 'Your account is being checked.')
            ->and(mb_strtolower($notice))->not->toContain('odell')->not->toContain('imperson')->not->toContain('gesicht')->not->toContain('face');

        foreach ([390, 1440] as $width) {
            ihMeasure($page, '[data-test=rated-standard]', $width, "{$locale} opponents");
            ihShot($page, "opponents-held-{$width}x900-{$locale}");
        }
    }

    $page->evaluate('() => setTimeout(() => { throw new Error("positive control"); })');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("positive control"))', 5_000);
});
