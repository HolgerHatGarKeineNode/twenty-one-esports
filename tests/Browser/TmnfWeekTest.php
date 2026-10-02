<?php

use App\Models\User;
use App\Support\Tmnf\TmnfWeeks;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| TMNF's week in the browser (plan "Trackmania und Restposten", P2)
|--------------------------------------------------------------------------
|
| The game page (scores/tmnf: hero, this week's board, How to join) and the
| week page (How to join right under the hero) in English at 375 and 1440 px
| and in German at 375 px, one name long enough to need truncating, a linked
| viewer on the board. Measured: the document and the How to join steps stay
| inside the viewport, the texts are in the page's language. Console,
| uncaught errors and every answer >= 400 are collected (BrowserConsole) and
| stay empty; a positive control shows the collector sees a throw and a
| failed answer. SHELL_SHOTS=<dir> writes the screenshots.
|
*/

beforeEach(function () {
    Http::fake(fn () => Http::response([]));
    config(['session.driver' => 'database', 'esports.tmnf.server.address' => 'tmnf.einundzwanzig.space:2350']);

    app()->rebinding('request', function ($app): void {
        $app['session']->forgetDrivers();
        $app->forgetInstance('session.store');
        $app->forgetInstance('auth.driver');
        $app['auth']->forgetGuards();
        $app['livewire']->flushState();
    });

    tmnfOn();
    $this->freezeTime();
    $this->travelTo(CarbonImmutable::parse('2026-10-07 12:00:00'));

    $names = ['Hal Finney Fan' => 24_870, 'Ada Fullthrottle' => 25_120, 'A very long Nostr display name that has to be cut off somewhere' => 25_480, 'Nakamoto' => 26_010, 'Mempool Max' => 27_300];
    foreach (array_keys($names) as $index => $name) {
        $user = tmnfPlayer('driver_'.$index, linked: true, attributes: ['name' => $name]);
        tmnfFinish('driver_'.$index, $names[$name], now()->subHours(20 - $index));

        if ($name === 'Ada Fullthrottle') {
            $this->me = $user;
        }
    }

    $this->week = app(TmnfWeeks::class)->current();
});

function tmnfWeekPage(User $user, string $locale, int $width, int $height, string $path): Page
{
    $page = visit(BrowserLogin::url($user))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize($width, $height);
    $page->goto(ComputeUrl::from(route('locale.switch', $locale, false)));
    $page->goto(ComputeUrl::from($path));

    return $page;
}

/** The document's widths, the How to join section's edges and each of its step cards' edges. */
const TMNF_WEEK_MEASURE = <<<'JS'
    () => {
        const join = document.querySelector('[data-test=tmnf-join]');
        const box = join.getBoundingClientRect();
        const steps = [...join.querySelectorAll('ol > li')].map((li) => { const r = li.getBoundingClientRect(); return [Math.round(r.left), Math.round(r.right)]; });
        return {
            scroll: document.documentElement.scrollWidth,
            client: document.documentElement.clientWidth,
            join: [Math.round(box.left), Math.round(box.right)],
            steps,
            heading: document.querySelector('#tmnf-join-h').innerText,
            lang: document.documentElement.lang,
        };
    }
    JS;

test('the game page and the week page lead with the week and How to join, no overflow, clean console', function (string $locale, int $width, int $height) {
    foreach ([['scores', route('scores.show', 'tmnf', false)], ['week', route('tournaments.show', $this->week, false)]] as [$name, $path]) {
        $page = tmnfWeekPage($this->me, $locale, $width, $height, $path);
        BrowserWait::until($page, '() => document.querySelector("[data-test=tmnf-join]") !== null', 10_000);

        $measure = $page->evaluate(TMNF_WEEK_MEASURE);
        fwrite(STDERR, "tmnf {$name} {$locale} {$width}: ".json_encode($measure).PHP_EOL);

        expect($measure['lang'])->toBe($locale)
            ->and($measure['heading'])->toBe($locale === 'de' ? 'So machst du mit' : 'How to join')
            ->and($measure['scroll'])->toBeLessThanOrEqual($measure['client'])
            ->and($measure['join'][0])->toBeGreaterThanOrEqual(0)
            ->and($measure['join'][1])->toBeLessThanOrEqual($width)
            ->and(count($measure['steps']))->toBe(4);
        foreach ($measure['steps'] as [$left, $right]) {
            expect($left)->toBeGreaterThanOrEqual(0)->and($right)->toBeLessThanOrEqual($width);
        }

        shellShot($page, "tmnf-{$name}-{$locale}-{$width}");
        $page->evaluate('() => document.querySelector("[data-test=tmnf-join]").scrollIntoView({ block: "start" })');
        shellShot($page, "tmnf-{$name}-join-{$locale}-{$width}");

        expect($page->evaluate('() => window.__errors'))->toBe([])
            ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
    }

    // Positive control: the collector sees a throw and a failed answer on this very page.
    $page->evaluate('() => { setTimeout(() => { throw new Error("tmnf week positive control"); }); fetch("/scores/tmnf/nothing-here"); }');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("tmnf week positive control")) && window.__errors.some((e) => e.startsWith("404 "))', 5_000);
})->with([
    'en 375' => ['en', 375, 812],
    'en 1440' => ['en', 1440, 900],
    'de 375' => ['de', 375, 812],
]);

test('the settings page links a saved login with a code shown on request, no overflow, clean console', function (string $locale, int $width, int $height) {
    $player = tmnfPlayer('new_driver', attributes: ['name' => 'New Driver']);
    $page = tmnfWeekPage($player, $locale, $width, $height, route('gaming.edit', [], false));
    BrowserWait::until($page, '() => document.querySelector("[data-test=tmnf-link-show]") !== null', 10_000);

    $page->evaluate('() => document.querySelector("[data-test=tmnf-link-show]").click()');
    BrowserWait::until($page, '() => document.querySelector("[data-test=tmnf-link-code]") !== null', 10_000);
    $code = $page->evaluate('() => { const c = document.querySelector("[data-test=tmnf-link-code]"); c.scrollIntoView({ block: "center" }); const r = c.getBoundingClientRect(); return { text: c.innerText.trim(), box: [Math.round(r.left), Math.round(r.right)], scroll: document.documentElement.scrollWidth, client: document.documentElement.clientWidth }; }');
    fwrite(STDERR, "tmnf link {$locale} {$width}: ".json_encode($code).PHP_EOL);

    expect($code['text'])->toMatch('/^link [A-Z2-9]{6}$/')
        ->and($code['box'][0])->toBeGreaterThanOrEqual(0)->and($code['box'][1])->toBeLessThanOrEqual($width)
        ->and($code['scroll'])->toBeLessThanOrEqual($code['client']);

    shellShot($page, "tmnf-link-{$locale}-{$width}");

    expect($page->evaluate('() => window.__errors'))->toBe([])
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
})->with([
    'en 375' => ['en', 375, 812],
    'en 1440' => ['en', 1440, 900],
]);
