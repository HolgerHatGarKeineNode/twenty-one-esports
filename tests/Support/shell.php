<?php

use App\Models\Admin;
use App\Models\ChessGame;
use App\Models\Lineup;
use App\Models\SeriesMatch;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;

/*
| Helpers of the shell navigation browser tests (header concept B):
| tests/Browser/ShellNavigationTest.php and ShellNavigationWidthsTest.php.
| Two files, two shards: together they take longer than one shard should.
*/

/** Rects of the chrome: header rows, tab bar, sideways scroll and every control under 44 px (below lg) or 24 px (from lg). */
const SHELL_MEASURE = <<<'JS'
    () => {
        const rect = (el) => { if (!el || !el.checkVisibility({ checkVisibilityCSS: true })) return null; const r = el.getBoundingClientRect(); return { top: Math.round(r.top), bottom: Math.round(r.bottom), left: Math.round(r.left), right: Math.round(r.right), height: Math.round(r.height), width: Math.round(r.width) }; };
        const header = document.querySelector('body > header');
        const rows = [...header.children].filter((el) => el.checkVisibility({ checkVisibilityCSS: true }) && getComputedStyle(el).position === 'static');
        const desktop = window.innerWidth >= 1024;
        const min = desktop ? 24 : 44;
        const small = [];
        for (const root of [header, document.getElementById('mobile-nav')]) {
            for (const el of root.querySelectorAll('a[href], button, input:not([type=hidden])')) {
                if (!el.checkVisibility({ checkVisibilityCSS: true }) || el.closest('#bell-panel')) continue;
                const r = el.getBoundingClientRect();
                if (r.width === 0 || r.height === 0) continue;
                if (Math.round(r.height) < min || Math.round(r.width) < min) small.push(`${(el.dataset.test || el.textContent.trim().replace(/\s+/g, ' ').slice(0, 30) || el.tagName)} ${Math.round(r.width)}x${Math.round(r.height)}`);
            }
        }
        const tabs = [...document.querySelectorAll('.gtab:not(.gtab-hub)')].filter((el) => el.checkVisibility({ checkVisibilityCSS: true })).map((el) => el.dataset.test.replace('game-tab-', '') + ':' + el.querySelector(getComputedStyle(el.querySelector('.gtab-full')).display === 'none' ? '.gtab-short' : '.gtab-full').textContent.trim());
        // Squeezed, not scrolled: a row whose content is wider than its box, a label cut short.
        const squeezed = [...document.querySelectorAll('[data-test=game-tabs], [data-test=context-bar], [data-test=tab-bar] ul, [data-test=tab-bar] .tab span, .nav-link, .ctx-link')]
            .filter((el) => el.checkVisibility({ checkVisibilityCSS: true }) && el.scrollWidth > el.clientWidth + 1)
            .map((el) => `${el.dataset.test || el.className.split(' ')[0] || el.tagName} ${el.scrollWidth}>${el.clientWidth}`);
        const nav = document.querySelector('[data-test=game-tabs]');
        const search = document.querySelector('[data-test=site-search-form]');
        if (nav && search && nav.checkVisibility() && search.checkVisibility()) {
            const last = [...nav.children].filter((el) => el.checkVisibility()).pop();
            if (last && last.getBoundingClientRect().right > search.getBoundingClientRect().left) squeezed.push(`row 1 runs under the search: ${Math.round(last.getBoundingClientRect().right)} > ${Math.round(search.getBoundingClientRect().left)}`);
        }
        return {
            squeezed,
            width: window.innerWidth,
            scroll: document.documentElement.scrollWidth,
            client: document.documentElement.clientWidth,
            header: rect(header),
            rows: rows.map((el) => (el.dataset.test || el.tagName.toLowerCase()) + ' ' + Math.round(el.getBoundingClientRect().height)),
            tabbar: rect(document.querySelector('[data-test=tab-bar]')),
            tabs,
            small,
            lang: document.documentElement.lang,
        };
    }
    JS;

/** Every finite animation finished (a sheet measured mid-rise is 24 px off), then two frames. */
const SHELL_SETTLE = '() => Promise.all(document.getAnimations().filter((a) => a.effect?.getComputedTiming().iterations !== Infinity).map((a) => a.finished.catch(() => null))).then(() => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(() => resolve(true)))))';

function shellPage(?User $user, int $width, int $height = 900): Page
{
    $page = visit($user === null ? BrowserLogin::LANDING : BrowserLogin::url($user))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize($width, $height);

    return $page;
}

/**
 * @param  list<string>  $problems
 */
function shellOpen(Page $page, string $url, array &$problems): void
{
    $page->goto(ComputeUrl::from($url));
    BrowserWait::until($page, '() => document.readyState === "complete" && !!window.Alpine', 10_000);
    $page->evaluate(SHELL_SETTLE);

    foreach ([...$page->evaluate('() => window.__errors'), ...$page->evaluate(BrowserConsole::BAD_RESPONSES)] as $problem) {
        $problems[] = "{$url}: {$problem}";
    }
}

function shellShot(Page $page, string $name): void
{
    $dir = getenv('SHELL_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    File::ensureDirectoryExists($dir);
    $page->evaluate(SHELL_SETTLE);
    // The viewport, not the full page: a full-page shot paints the fixed tab bar in the middle of a long page.
    $page->screenshot(false, $name);
    // Pest clears tests/Browser/Screenshots on every run: move the file out at once.
    File::move(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
}

/**
 * A player whose last match was Rocket League, the one before chess, and an
 * open daily game (so the match dock shows).
 */
function shellPlayer(): User
{
    $lineup = Lineup::factory()->mode('3v3')->ready()->create();
    $player = $lineup->clan->owner;
    $player->forceFill(['name' => 'Pia Player'])->save();
    ChessGame::factory()->daily()->create(['white_id' => $player->id, 'created_at' => now()->subDays(2)]);
    SeriesMatch::factory()->accepted()->create(['challenger_lineup_id' => $lineup->id, 'challenged_lineup_id' => Lineup::factory()->mode('3v3')->ready()->create()->id, 'created_at' => now()->subDay()]);

    return $player;
}

function shellAdmin(): User
{
    $admin = User::factory()->create(['name' => 'Ada Admin']);
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    return $admin;
}
