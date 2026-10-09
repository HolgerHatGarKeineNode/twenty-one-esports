<?php

use App\Models\Admin;
use App\Models\PongInvite;
use App\Models\User;
use App\Support\Pong\PongInvites;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;
use Tests\Support\PongOn;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| Proof of Pong's lobby in the first screen (plan "Proof of Pong", P9)
|--------------------------------------------------------------------------
|
| The user, 2026-10-10: "Die Lobby für Live-Spiele ist von der UI/UX her echt nicht gut designed: zu weit unten, auf
| dem Desktop muss zu viel gescrollt werden." Both ways to play are primary: the live 1v1 card (Looking to play, who
| is online, an incoming invite) and the bot card's start button end above the fold at four desktop sizes and on two
| phones, where the fold is the window minus the tab bar (the body's padding-bottom). Measured as an admin in German
| with an incoming invite waiting, the busier state of the live card. The figure choice stays a strip, the chat
| column stays on the right from xl, nothing overflows, every control is 44 px, and the console stays clean
| (positive control at the end).
|
*/

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

    PongOn::play();
});

/** The lobby's first screen at the window's size: where its primary parts end, against the fold. */
const PONG_FIRST_SCREEN = <<<'JS'
    () => {
        // The fold: the window above the tab bar (the body's padding-bottom), and above the dock where it lies over the element.
        const base = innerHeight - parseFloat(getComputedStyle(document.body).paddingBottom || '0');
        const floors = [...document.querySelectorAll('[data-live-floor]')].filter((el) => el.checkVisibility()).map((el) => el.getBoundingClientRect()).filter((r) => r.height > 0);
        const edge = (sel) => {
            const el = document.querySelector(sel);
            if (! el || ! el.checkVisibility()) return null;
            const r = el.getBoundingClientRect();
            const fold = Math.min(base, ...floors.filter((f) => f.left < r.right && f.right > r.left).map((f) => f.top));
            return { bottom: Math.round(r.bottom + scrollY), fold: Math.round(fold) };
        };
        const host = document.querySelector('.chat-rail-host');
        const visible = (el) => el.checkVisibility() && el.getBoundingClientRect().width > 0 && ! el.closest('.sr-only');
        const small = [...host.querySelectorAll('button, a[href], label:has(input), summary')]
            .filter((el) => visible(el) && ! el.closest('.chat-rail') && ! el.closest('p'))
            .map((el) => ({ el, r: el.getBoundingClientRect() }))
            .filter(({ r }) => Math.min(r.height, r.width) < 44)
            .map(({ el, r }) => (el.dataset.test || el.tagName.toLowerCase()) + ' ' + Math.round(r.width) + 'x' + Math.round(r.height));
        const chat = document.querySelector('[data-test=game-chat]');
        const live = document.querySelector('[data-test=pong-lobby]').getBoundingClientRect();
        return {
            base: Math.round(base),
            live: edge('[data-test=pong-lobby]'), invite: edge('[data-test=pong-accept-invite]'), looking: edge('[data-test=looking-toggle]'),
            bot: edge('[data-test=pong-play-bot]'), ways: edge('[data-test=pong-ways]'),
            picker: Math.round(document.querySelector('[data-test=pong-picker]').getBoundingClientRect().height),
            chatLeft: Math.round(chat.getBoundingClientRect().left), liveRight: Math.round(live.right), chatPosition: getComputedStyle(chat).position,
            overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
            small, lang: document.documentElement.lang,
        };
    }
    JS;

function pongLobbyShot(Page $page, string $name): void
{
    $dir = getenv('PONG_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    File::ensureDirectoryExists($dir);
    $page->screenshot(false, $name);
    File::move(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
}

test('the live 1v1 card and the bot start end above the fold at 1280x720, 1366x768, 1440x900, 1920x1080, 390x844 and 360x740, as an admin in German with an invite waiting', function () {
    expect(config('broadcasting.default'))->toBe('reverb', 'Run this through scripts/test-browser.sh, which starts Reverb.');

    $admin = User::factory()->create(['name' => 'Anna Admin', 'looking_to_play' => PongInvites::LOOKING]);
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    PongInvite::factory()->create(['invitee_id' => $admin->id, 'inviter_id' => User::factory()->create(['name' => 'Bert Beispiel'])->id, 'expires_at' => now()->addMinutes(10)]);

    $page = visit(BrowserLogin::url($admin))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->goto(ComputeUrl::from(route('locale.switch', 'de', false)));
    $page->setViewportSize(1440, 900);
    $page->goto(ComputeUrl::from(route('pong.index', absolute: false)));
    BrowserWait::until($page, '() => document.readyState === "complete" && !!window.Livewire && !!document.querySelector("[data-test=pong-incoming-invite]") && document.fonts.status === "loaded"', 10_000);

    $rows = [];
    foreach ([[1280, 720], [1366, 768], [1440, 900], [1920, 1080], [390, 844], [360, 740]] as [$width, $height]) {
        $page->setViewportSize($width, $height);
        $page->evaluate('() => scrollTo(0, 0)');
        usleep(400_000);
        $row = $page->evaluate(PONG_FIRST_SCREEN);
        pongLobbyShot($page, "pong-p9-{$width}x{$height}");

        if ($width === 1440) {
            // The roster opens over the page: the page keeps its height, and a pick closes it.
            $tall = $page->evaluate('() => document.documentElement.scrollHeight');
            $page->locator('[data-test=pong-figure-open]')->click();
            usleep(300_000);
            pongLobbyShot($page, 'pong-p9-1440x900-roster');
            $row['roster'] = $page->evaluate('() => document.documentElement.scrollHeight') - $tall;
            $page->locator('[data-test=pong-figure-saylor]')->click();
            usleep(200_000);
            $row['rosterOpen'] = $page->evaluate('() => document.querySelector("[data-pp-more]").open');
            $row['picked'] = $page->evaluate('() => document.querySelector("[data-test=pong-picked-name]").textContent');
            expect($row['roster'])->toBe(0)->and($row['rosterOpen'])->toBeFalse()->and($row['picked'])->toBe('Michael Saylor');
        }

        if ($width < 768) {
            // On a phone the segmented control starts on the live 1v1 (an invite waits); the bot is one tap away.
            expect($row['bot'])->toBeNull("bot hidden behind its segment {$width}");
            $page->locator('[data-test=pong-way-bot]')->click();
            usleep(200_000);
            $row['bot'] = $page->evaluate(PONG_FIRST_SCREEN)['bot'];
            pongLobbyShot($page, "pong-p9-{$width}x{$height}-bot");
            $page->locator('[data-test=pong-way-live]')->click();
        }
        $rows["{$width}x{$height}"] = $row;
    }
    fwrite(STDERR, "\n[p9 first screen] ".json_encode($rows)."\n");

    foreach ($rows as $size => $row) {
        $desktop = (int) $size >= 1280;
        expect($row['lang'])->toBe('de')
            ->and($row['bot']['bottom'])->toBeLessThanOrEqual($row['bot']['fold'], "bot start {$size}")
            ->and($row['overflow'])->toBe(0, "overflow {$size}")
            ->and($row['small'])->toBe([], "small targets {$size}")
            // One row: the figure choice opens over the page and never lengthens it.
            ->and($row['picker'])->toBeLessThanOrEqual(80, "picker {$size}");

        if ($desktop) {
            expect($row['live']['bottom'])->toBeLessThanOrEqual($row['live']['fold'], "live card {$size}")
                ->and($row['ways'])->toBeNull()
                ->and($row['chatPosition'])->toBe('sticky')
                ->and($row['chatLeft'])->toBeGreaterThanOrEqual($row['liveRight'] + 24, "chat column {$size}");
        } else {
            // On a phone the live path's own controls are in the first screen: the segments, Looking to play and Accept.
            expect($row['ways']['bottom'])->toBeLessThanOrEqual($row['ways']['fold'], "segments {$size}")
                ->and($row['looking']['bottom'])->toBeLessThanOrEqual($row['looking']['fold'], "looking {$size}")
                ->and($row['invite']['bottom'])->toBeLessThanOrEqual($row['invite']['fold'], "accept {$size}");
        }
    }

    expect([...$page->evaluate('() => window.__errors ?? ["collector missing"]'), ...$page->evaluate(BrowserConsole::BAD_RESPONSES)])->toBe([]);

    // Positive control: the collector on this page sees a thrown error.
    $page->evaluate('() => { setTimeout(() => { throw new Error("p9 positive control"); }); }');
    BrowserWait::until($page, '() => (window.__errors || []).some((e) => e.includes("p9 positive control"))', 5_000);
});
