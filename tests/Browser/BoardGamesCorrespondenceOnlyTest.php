<?php

use App\Models\Admin;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BlockliOn;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;
use Tests\Support\CheckersGame;
use Tests\Support\NineMensMorrisOn;

pest()->group('browser');

/*
| Nine men's morris, checkers and Blockli are correspondence only (user,
| 2026-10-07). /play and their three lobbies measured as an admin in German
| at six widths: no sideways overflow, no cut, squeezed or spilled text,
| buttons 44 px, no Blitz tile or text, console and network quiet (with a
| positive control on the same page).
*/

/** Overflow, cut text, squeezed sentences, words spilling out of their cell, low buttons, blitz on the page; inside `main`. */
const NOBLITZ_MEASURE = <<<'JS'
    () => {
        const main = document.querySelector('main') ?? document.body;
        const visible = (el) => el.checkVisibility();
        const label = (el) => (el.dataset.test ?? el.tagName.toLowerCase()) + ' "' + (el.innerText || el.getAttribute('aria-label') || '').trim().replace(/\s+/g, ' ').slice(0, 40) + '"';
        const all = [...main.querySelectorAll('*')].filter((el) => !(el instanceof SVGElement) && visible(el) && el.getBoundingClientRect().width > 1);
        const scrolls = (el) => ['auto', 'scroll', 'hidden', 'clip'].includes(getComputedStyle(el).overflowX);
        const boards = ['nine-mens-morris', 'checkers', 'blockli'];
        const scopes = [...document.querySelectorAll(boards.map((s) => '[data-test=play-game-' + s + ']').join(',') + ', [data-test=board-lobby] [data-test=play]')];
        return {
            overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
            cut: all.filter((el) => el.children.length === 0 && el.clientWidth > 0 && el.scrollWidth > el.clientWidth + 1 && getComputedStyle(el).textOverflow !== 'ellipsis').map(label),
            // A sentence of more than 6 words in a box under 240 px wide and over 60 px high (user, 2026-10-07).
            squeezed: [...document.querySelectorAll('p, h1, h2, h3, li, dd, header span')].filter((el) => visible(el) && el.innerText.trim().split(/\s+/).length > 6 && el.getBoundingClientRect().width < 240 && el.getBoundingClientRect().height > 60).map(label),
            spill: all.filter((el) => el.children.length === 0 && el.innerText?.trim() && el.parentElement && !scrolls(el.parentElement) && getComputedStyle(el).position !== 'absolute'
                && el.getBoundingClientRect().right > el.parentElement.getBoundingClientRect().right + 1).map(label),
            low: [...main.querySelectorAll('button, a.btn-p, a.btn-s, a.btn-w')].filter((el) => visible(el) && el.getBoundingClientRect().height < 44).map((el) => label(el) + ' ' + el.getBoundingClientRect().height.toFixed(1) + ' px'),
            // Blitz on a board game's card or ways to play (chess keeps its own on the same page).
            blitz: scopes.filter((el) => /blitz|5\+3/i.test(el.innerText)).map(label),
            scopes: scopes.length,
        };
    }
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

    NineMensMorrisOn::play();
    CheckersGame::play();
    BlockliOn::play();
});

function noBlitzPage(User $user, string $to, int $width, int $height): Page
{
    $page = visit(BrowserLogin::url($user))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize($width, $height);
    $page->goto(ComputeUrl::from($to));

    return $page;
}

function noBlitzShot(Page $page, string $name): void
{
    $dir = getenv('NOBLITZ_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    File::ensureDirectoryExists($dir);
    $page->screenshot(false, $name);
    File::move(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
}

test('as an admin in German /play and the three board game lobbies offer no blitz and squeeze, cut and spill nothing at six widths; buttons 44 px; quiet console and network', function () {
    $admin = User::factory()->withPubkey(str_repeat('ad', 32))->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    $rows = [];
    $pages = [
        'play' => [route('play', ['lang' => 'de'], false), '[data-test=play-board-games]', 3],
        'nine-mens-morris' => [route('board.lobby', ['board' => 'nine-mens-morris', 'lang' => 'de'], false), '[data-test=board-lobby]', 1],
        'checkers' => [route('board.lobby', ['board' => 'checkers', 'lang' => 'de'], false), '[data-test=board-lobby]', 1],
        'blockli' => [route('board.lobby', ['board' => 'blockli', 'lang' => 'de'], false), '[data-test=board-lobby]', 1],
    ];

    foreach ([[390, 844], [1024, 768], [1280, 800], [1440, 900], [1600, 900], [1920, 1080]] as [$width, $height]) {
        foreach ($pages as $name => [$to, $ready, $scopes]) {
            $page = noBlitzPage($admin, $to, $width, $height);
            BrowserWait::until($page, '() => document.readyState === "complete" && window.Livewire !== undefined && document.querySelector('.json_encode($ready).') !== null', 10_000);

            if ($name === 'play') {
                $page->evaluate('() => document.querySelector("[data-test=play-board-games]").scrollIntoView({ block: "start" })');

                if (in_array($width, [390, 1440], true)) {
                    // The covers are lazy: load them before the shot, so it shows the new DAILY covers.
                    $page->evaluate('() => document.querySelectorAll("[data-test=play-board-games] img").forEach((i) => { i.loading = "eager"; })');
                    BrowserWait::until($page, '() => [...document.querySelectorAll("[data-test=play-board-games] img")].every((i) => i.complete && i.naturalWidth > 0)', 10_000);
                    noBlitzShot($page, "play-board-games-de-{$width}");
                }
            }

            $m = $page->evaluate(NOBLITZ_MEASURE);
            $label = "{$name} de {$width}";
            $rows[] = sprintf('%-17s de %4d  overflow %d  cut %d  squeezed %d  spill %d  low %d  blitz %d', $name, $width, $m['overflow'], count($m['cut']), count($m['squeezed']), count($m['spill']), count($m['low']), count($m['blitz']));

            expect($m['scopes'])->toBe($scopes, "{$label}: board game cards / ways to play found")
                ->and($m['blitz'])->toBe([], "{$label}: blitz offered")
                ->and($m['overflow'])->toBeLessThanOrEqual(0, "{$label}: sideways overflow")
                ->and($m['cut'])->toBe([], "{$label}: cut text")
                ->and($m['squeezed'])->toBe([], "{$label}: squeezed text")
                ->and($m['spill'])->toBe([], "{$label}: spilled words")
                ->and($m['low'])->toBe([], "{$label}: buttons under 44 px")
                ->and($page->evaluate('() => window.__errors'))->toBe([], "{$label}: console")
                ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([], "{$label}: responses");

            // Positive control once per width on a lobby: the collector sees a throw, a console error and a failed answer.
            if ($name === 'blockli') {
                $page->evaluate('() => { console.error("noblitz console control"); setTimeout(() => { throw new Error("noblitz positive control"); }); fetch("/board/0"); }');
                BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("noblitz positive control")) && window.__errors.some((e) => e.includes("noblitz console control")) && window.__errors.some((e) => e.startsWith("404 "))', 5_000);
            }

            $page->close();
        }
    }

    fwrite(STDERR, PHP_EOL.implode(PHP_EOL, $rows).PHP_EOL);
});
