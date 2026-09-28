<?php

use App\Models\Tournament;
use App\Models\User;
use App\Support\Tournaments\CasualCups;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserWait;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| The visual pass (P53)
|--------------------------------------------------------------------------
|
| Pictures where the pages read as text only (user, 2026-09-28: "zu
| textlich. BILDER!!!! eigentlich überall mal durchscanne"): the cup board
| with covers, faces in the chess rows of the match list and on the ladder,
| covers on /live and in the rules' games table, halving and cap bars on
| /mining; part C: home's line per game, the state bar, the next tournament
| off air, the share cap split, the clans' line, the empty states and the
| casual game covers. Each page at 1440 and 375 in English and 375 in German: its
| picture is there, painted and inside the window, nothing is wider than the
| window, and the console and the answers stay clean, with a positive control.
|
*/

beforeEach(function () {
    Http::fake(fn () => Http::response([]));
    Queue::fake();
    config(['filesystems.disks.public.url' => '/storage']);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00:00', 'UTC'));
});

/**
 * The page's pictures under `$selector`: how many are visible, how many are
 * painted (an image that loaded, or a box with a width), and how many stick
 * out of the window; plus the document's overflow.
 *
 * @return array{visible: int, painted: int, outside: int, overflow: int}
 */
function visualPassState(Page $page, string $selector): array
{
    return $page->evaluate('async (selector) => {
        const els = [...document.querySelectorAll(selector)].filter((el) => el.checkVisibility());
        for (const img of els.flatMap((el) => el.tagName === "IMG" ? [el] : [...el.querySelectorAll("img")])) {
            img.loading = "eager";
            if (!img.complete) { await new Promise((r) => { img.addEventListener("load", r, { once: true }); img.addEventListener("error", r, { once: true }); setTimeout(r, 3000); }); }
        }
        const painted = (el) => {
            const img = el.tagName === "IMG" ? el : el.querySelector("img");
            return img ? img.naturalWidth > 0 : el.getBoundingClientRect().width > 0;
        };
        return {
            visible: els.length,
            painted: els.filter(painted).length,
            outside: els.filter((el) => { const r = el.getBoundingClientRect(); return r.left < 0 || r.right > window.innerWidth; }).length,
            overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
        };
    }', $selector);
}

function visualPassShot(Page $page, string $name): void
{
    $dir = getenv('VISUAL_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    File::ensureDirectoryExists($dir);
    $page->screenshot(true, $name);
    File::move(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
}

test('every touched page shows its pictures at 1440 and 375, German at 375, with a clean console', function () {
    navWorld();
    config(['esports.casual_cups.enabled' => ['chess', 'rocket-league', 'ea-sports-fc-26']]);
    app(CasualCups::class)->tick();
    $cup = Tournament::query()->where('cup_open_series', 'chess-eu')->sole();
    // A player without a clan or a game: the empty states of /me and the own page show their pictures (P53 part C).
    $player = User::factory()->member()->create(['name' => 'Lena Loner']);

    // Page => the selector of its new pictures and how many at least.
    $pages = [
        '/tournaments' => ['[data-test=cup-group-cover], [data-test^=state-segment-]', 5],
        '/' => ['[data-test=cup-line-cover]', 3],
        '/chess' => ['[data-test=cup-mention]', 2],
        '/games/rocket-league' => ['[data-test=cup-mention]', 2],
        route('tournaments.show', $cup, false) => ['[data-test=cup-mention]', 1],
        '/matches' => ['[data-test=chess-row-face]', 4],
        '/ladder/chess/blitz' => ['main img[data-avatar]', 6],
        '/live' => ['[data-test=live-tournament-cover], [data-test=live-offline-next-cover]', 2],
        '/rules' => ['[data-test=rules-table-cover]', 7],
        '/mining' => ['[data-test=era-pay-bar], [data-test=share-cap-bar]', 7],
        '/clans' => ['[data-test=clan-card-mark], [data-test=clan-spotlight-mark]', 1],
        '/challenges/casual' => ['[data-test^=casual-game-] picture', 3],
        '/players/'.$player->npub => ['[data-test=open-picture-cover], [data-test=join-picture]', 2],
        '/me' => ['[data-test=open-picture-cover], [data-test=join-picture]', 2],
    ];

    $page = visit(route('testing.login', ['user' => $player, 'to' => '/robots.txt']))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $failures = [];
    $controlled = false;

    foreach ([[1440, 900, 'en'], [375, 812, 'en'], [375, 812, 'de']] as [$width, $height, $lang]) {
        $page->setViewportSize($width, $height);

        if ($lang === 'de') {
            $page->goto(ComputeUrl::from('/locale/de'));
        }

        foreach ($pages as $path => [$selector, $least]) {
            $page->goto(ComputeUrl::from($path));
            BrowserWait::until($page, '() => document.readyState === "complete" && window.Alpine && document.fonts.status === "loaded"', 10_000);

            if (! $controlled) {
                // Positive control: a thrown error and a 500 answer reach the collector on this page.
                $page->evaluate('() => { setTimeout(() => { throw new Error("probe-throw"); }); return fetch("/__test/server-error"); }');
                BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("probe-throw")) && window.__errors.some((e) => e.startsWith("500 ")) && performance.getEntries().some((e) => e.name.includes("/__test/server-error") && e.responseStatus === 500)', 5_000);
                $page->evaluate('() => { window.__errors = []; performance.clearResourceTimings(); }');
                $controlled = true;
            }

            if ($path === '/live' && $width < 1024) {
                // Below lg the running tournaments sit behind the "On the stream" tab.
                $page->locator('[data-test=live-switch-programme]')->click();
            }

            if ($path === '/rules' && $width < 1024) {
                // Below lg the rules are an accordion: open the games section.
                $page->locator('[data-test=doc-section-games] [data-test=doc-toggle]')->click();
            }

            $state = visualPassState($page, $selector);
            $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($path)), '-') ?: 'home';
            visualPassShot($page, "visual-{$slug}-{$width}-{$lang}");

            if ($state['visible'] < $least || $state['painted'] !== $state['visible'] || $state['outside'] !== 0 || $state['overflow'] !== 0) {
                $failures[] = "{$path} {$width} {$lang}: ".json_encode($state);
            }

            foreach ([...$page->evaluate('() => window.__errors'), ...$page->evaluate(BrowserConsole::BAD_RESPONSES)] as $problem) {
                $failures[] = "{$path} {$width} {$lang}: {$problem}";
            }

            $page->evaluate('() => { window.__errors = []; performance.clearResourceTimings(); }');
        }
    }

    expect($controlled)->toBeTrue()
        ->and($failures)->toBe([]);
});
