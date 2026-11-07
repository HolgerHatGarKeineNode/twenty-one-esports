<?php

use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Models\Admin;
use App\Models\BoardGame;
use App\Models\ChessGame;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentParticipant;
use App\Models\TournamentRound;
use App\Models\User;
use App\Support\Chess\ChessGameService;
use App\Support\TwentyOne\LiveStatus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BlockliOn;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;
use Tests\Support\TestSigner;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| "What to do now" in the browser (user, 2026-10-03)
|--------------------------------------------------------------------------
|
| A player of a knockout whose semi-final is won waits ("Wait for the next
| round", the round's live count). The other semi-final ends: the final and
| its game are created on the server, and the open page turns into "Play
| now" without a reload (the tournament's push, or the page's poll) and
| flips. It stays on the tournament page ("Das bitte ausmachen": no automatic
| opening); the big button opens the game. Measured at en 375 and 1440 and
| de 375: the hero is the first thing on the page and ends above the phone's
| tab bar.
|
| Collected: console.error/warn, uncaught errors, rejected promises, fetch
| and XHR >= 400; a thrown error at the end proves the collector sees one.
| NOW_SHOTS=<dir> writes the screenshots there.
|
*/

const NOW_COLLECTOR = <<<'JS'
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

/** The hero's box, the phone's tab bar, what is above the hero, and the page's errors. */
const NOW_MEASURE = <<<'JS'
    () => {
        const hero = document.querySelector('[data-test=now-hero]');
        const box = hero.getBoundingClientRect();
        const action = hero.querySelector('[data-test=now-action]')?.getBoundingClientRect() ?? null;
        const title = hero.querySelector('[data-test=now-title]');
        // The fixed bar at the bottom of a phone's screen, if any.
        const bars = [...document.querySelectorAll('body *')].filter((el) => {
            const style = getComputedStyle(el);
            const r = el.getBoundingClientRect();
            return style.position === 'fixed' && r.height > 0 && r.height < 160 && r.bottom >= innerHeight - 1 && r.width >= innerWidth * 0.9;
        });
        const tab = bars.length ? Math.round(Math.min(...bars.map((el) => el.getBoundingClientRect().top))) : null;
        const main = hero.closest('[data-test=tournament-show]');
        return {
            state: hero.dataset.state,
            top: Math.round(box.top + scrollY),
            bottom: Math.round(box.bottom + scrollY),
            left: Math.round(box.left),
            right: Math.round(box.right),
            action: action && { top: Math.round(action.top), bottom: Math.round(action.bottom), height: Math.round(action.height), width: Math.round(action.width) },
            titleSize: parseFloat(getComputedStyle(title).fontSize),
            titleOverflow: title.scrollWidth - title.clientWidth,
            // Right under the tournament's hero (TournamentDetail.dc.html): before the tabs and the bracket.
            first: hero.getBoundingClientRect().top < main.querySelector('[data-test=tournament-tabs]').getBoundingClientRect().top,
            tab,
            overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
            text: hero.innerText,
            errors: window.__errors ?? ['collector missing'],
            path: location.pathname,
            avatars: hero.querySelectorAll('[data-test=now-cover] img[data-avatar]').length,
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

    config(['esports.league.nsec' => (new TestSigner)->secret]);
});

function nowShot(Page $page, string $name): void
{
    $dir = getenv('NOW_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    File::ensureDirectoryExists($dir);
    $page->screenshot(false, $name);
    File::move(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
}

test('a waiting player sees the hero right under the tournament\'s, above the tab bar, and it flips to "Play now" without a reload and stays on the page', function () {
    $measured = [];
    $page = null;

    foreach ([[375, 812, 'en', true], [1440, 900, 'en', false], [375, 812, 'de', false], [360, 740, 'de', false], [360, 740, 'en', false]] as [$width, $height, $locale, $flip]) {
        $tournament = runningChess(TournamentFormat::SingleElimination, 4, TournamentResultsMode::Players);
        $tournament->forceFill(['name' => 'Halving Blitz Cup', 'published_at' => now()])->save();
        // The players are read below: loaded with the games, as the lazy-loading guard (local and testing) wants.
        [$semi, $other] = ChessGame::query()->with(['white', 'black'])->whereIn('tournament_match_id', $tournament->matches()->select('id'))->orderBy('id')->get()->all();
        [$white, $black] = [$semi->white, $semi->black];
        $white->forceFill(['locale' => $locale])->save();
        app(ChessGameService::class)->resign($semi, $black);

        $page = visit(BrowserLogin::url($white))->page();
        $page->context()->addInitScript(NOW_COLLECTOR);
        $page->setViewportSize($width, $height);
        $page->goto(ComputeUrl::from(route('tournaments.show', $tournament)));
        BrowserWait::until($page, '() => document.querySelector("[data-test=now-hero]") !== null && document.readyState === "complete"', 8_000);
        $wait = $page->evaluate(NOW_MEASURE);
        nowShot($page, "now-wait-{$locale}-{$width}");

        expect($wait['state'])->toBe('wait')
            ->and($wait['first'])->toBeTrue()
            ->and($wait['left'])->toBeGreaterThanOrEqual(0)
            ->and($wait['right'])->toBeLessThanOrEqual($width)
            ->and($wait['overflow'])->toBeLessThanOrEqual(0)
            ->and($wait['titleOverflow'])->toBeLessThanOrEqual(0)
            ->and($wait['bottom'])->toBeLessThanOrEqual($wait['tab'] ?? $height)
            ->and($wait['avatars'])->toBe(1)
            ->and($wait['errors'])->toBe([]);

        if ($locale === 'en') {
            expect($wait['text'])->toContain('Wait for the next round')->toContain('Round 1: 1 match still playing')->toContain('This page tells you as soon as your game starts.');
        } else {
            expect($wait['text'])->toContain('Warte auf die nächste Runde')->toContain('Runde 1: 1 Match läuft noch');
        }

        if (! $flip) {
            $measured["{$locale}-{$width}"] = $wait;

            continue;
        }

        // The other semi-final ends: the server creates the final and its game. The page is NOT reloaded.
        app(ChessGameService::class)->resign($other, $other->black);
        $final = TournamentMatch::query()->where('tournament_id', $tournament->id)->whereHas('chessGame', fn ($query) => $query->where('status', 'active'))->firstOrFail();
        $game = $final->chessGame;

        BrowserWait::until($page, '() => document.querySelector("[data-test=now-hero]")?.dataset.state === "play"', 25_000);
        // The flip runs 300 ms: measure the settled hero.
        usleep(500_000);
        $play = $page->evaluate(NOW_MEASURE);
        nowShot($page, "now-play-{$locale}-{$width}");

        expect($play['text'])->toContain('Play now')->toContain('Go to your game')->not->toContain('Your game opens in')
            ->and($play['path'])->toBe(parse_url(route('tournaments.show', $tournament), PHP_URL_PATH))
            ->and($play['action']['height'])->toBeGreaterThanOrEqual(56)
            ->and($play['action']['bottom'])->toBeLessThanOrEqual($play['tab'] ?? $height)
            ->and($play['avatars'])->toBe(2)
            ->and($play['errors'])->toBe([]);

        // No automatic opening (user, 2026-10-03): six seconds later the page is still the tournament.
        usleep(6_000_000);
        $landed = $page->evaluate('() => ({ errors: window.__errors ?? ["collector missing"], path: location.pathname })');

        expect($landed['errors'])->toBe([])
            ->and($landed['path'])->toBe(parse_url(route('tournaments.show', $tournament), PHP_URL_PATH));

        $measured["{$locale}-{$width}"] = $wait + ['play' => array_diff_key($play, ['text' => 1]), 'landed' => $landed['path']];
    }

    // Positive control: an error thrown on the page reaches the collector.
    $page->evaluate('() => { setTimeout(() => { throw new Error("probe"); }, 0); }');
    BrowserWait::until($page, '() => window.__errors.length > 0', 5_000);
    expect(implode(' | ', $page->evaluate('() => window.__errors')))->toContain('probe');

    fwrite(STDERR, "\n[now] ".json_encode(array_map(fn (array $row): array => array_diff_key($row, ['text' => 1]), $measured))."\n");
});

test('beside the chat rail at 1280 x 800 "Play now" of a round-robin table stands right under its one-line title, above the fold, and the facts keep one column; on a phone the action comes before the details; clean console', function () {
    // V2 and V5 (2026-10-20, measured as an admin in German): the hero's text column kept 328 px beside its action, the
    // 44 px title broke in two and the action sank to y 922; a half fact card kept 141 px, "nein: vor Block 0 …" 78 px high.
    Queue::fake();
    BlockliOn::play();
    $tournament = blockliTournament(TournamentFormat::RoundRobin, [], 4);
    $tournament->forceFill(['name' => 'Blockli Runde', 'published_at' => now(), 'ladder_address' => null])->save();
    $game = BoardGame::query()->whereIn('tournament_match_id', $tournament->matches()->select('id'))->orderBy('id')->firstOrFail();
    $player = User::query()->findOrFail($game->turn === 'w' ? $game->white_id : $game->black_id);
    $player->forceFill(['name' => 'Satoshi Nakamotos Erbe', 'locale' => 'de'])->save();
    Admin::query()->create(['pubkey' => $player->pubkey]);
    $facts = '() => [...document.querySelectorAll("[data-fact]")].filter((el) => el.checkVisibility()).map((el) => { const card = el.getBoundingClientRect(), dd = el.querySelector("dd").getBoundingClientRect(); return [el.dataset.fact, Math.round(card.width), Math.round(dd.height)]; })';

    $page = visit(BrowserLogin::url($player))->page();
    $page->context()->addInitScript(NOW_COLLECTOR);
    $measured = [];

    foreach ([[1280, 800], [390, 844]] as [$width, $height]) {
        $page->setViewportSize($width, $height);
        $page->goto(ComputeUrl::from(route('tournaments.show', $tournament)));
        BrowserWait::until($page, '() => document.querySelector("[data-test=now-hero]") !== null && document.readyState === "complete"', 8_000);
        $m = $page->evaluate(NOW_MEASURE);
        $title = $page->evaluate('() => { const t = document.querySelector("[data-test=now-title]"); return Math.round(t.getBoundingClientRect().height / parseFloat(getComputedStyle(t).lineHeight)); }');
        // The action follows the head (title and line) and stands before the details (deadline, decisions).
        $order = $page->evaluate('() => { const hero = document.querySelector("[data-test=now-hero]"), a = hero.querySelector("[data-test=now-action]").getBoundingClientRect(); const bottoms = [...hero.querySelectorAll("[data-test=now-title], [data-test=now-line]")].map((el) => el.getBoundingClientRect().bottom); const tops = [...hero.querySelectorAll("[data-test=now-until], [data-test=now-others], [data-test=my-wait], [data-test=now-boards]")].filter((el) => el.checkVisibility()).map((el) => el.getBoundingClientRect().top); return { afterHead: a.top >= Math.max(...bottoms), beforeDetails: tops.every((top) => a.bottom <= top) }; }');
        $measured[$width] = ['action' => $m['action'], 'title' => $title, 'order' => $order, 'facts' => $page->evaluate($facts), 'overflow' => $m['overflow']];
        nowShot($page, "now-rr-de-{$width}");

        expect($m['state'])->toBe('play')
            ->and($m['action']['bottom'])->toBeLessThanOrEqual($height, "{$width}: the action ends below the fold")
            ->and($title)->toBe(1, "{$width}: the title breaks")
            ->and($order)->toBe(['afterHead' => true, 'beforeDetails' => true])
            ->and($m['overflow'])->toBe(0)
            ->and($m['errors'])->toBe([]);

        // The facts: one column beside the rail, so no value runs in a 141 px card (R2: a value line, not a word column).
        if ($width === 1280) {
            foreach ($measured[$width]['facts'] as [$key, $cardWidth, $ddHeight]) {
                expect($cardWidth)->toBeGreaterThanOrEqual(340, "fact {$key}'s card is {$cardWidth} px wide")
                    ->and($ddHeight)->toBeLessThanOrEqual(40, "fact {$key} runs {$ddHeight} px high");
            }
        }
    }

    fwrite(STDERR, "\n[now-rr] ".json_encode($measured)."\n");
});

test('with the stream on air the floating "Watch live" tab never covers the hero\'s action: it steps aside at 1280 x 800, 1280 x 720 and 390 x 844 and is back once the action scrolled away; clean console and responses', function () {
    // E4 (2026-10-20, measured as an admin in German): the folded tab (from y 728) covered the lower 15 px of "Play now"
    // (to 743) at 1280 x 800 and its left 173 px at 390 x 844. The action carries data-live-clear (resources/js/livePlayer.js).
    Queue::fake();
    $chess = runningChess(TournamentFormat::SingleElimination, 4, TournamentResultsMode::Players);
    $chess->forceFill(['name' => 'Halving Blitz Cup', 'published_at' => now()])->save();
    $player = ChessGame::query()->with('white')->whereIn('tournament_match_id', $chess->matches()->select('id'))->orderBy('id')->firstOrFail()->white;
    $player->forceFill(['locale' => 'de'])->save();
    Admin::query()->create(['pubkey' => $player->pubkey]);
    $hls = sys_get_temp_dir().'/esports-now-live-'.getmypid().'-'.bin2hex(random_bytes(3));
    File::ensureDirectoryExists($hls);
    config(['twentyone.stream.hls_dir' => $hls, 'twentyone.stream.public_url' => '/__test/live/stream.m3u8']);
    Cache::put(LiveStatus::ANNOUNCED_KEY, ['viewers' => 128], 3600);
    $cover = <<<'JS'
        () => {
            const tab = document.querySelector('[data-test=live-tab]'), action = document.querySelector('[data-test=now-action]');
            const shown = !! tab?.checkVisibility(), a = action.getBoundingClientRect();
            const t = shown ? tab.closest('section').getBoundingClientRect() : null;
            const x = t ? Math.min(t.right, a.right) - Math.max(t.left, a.left) : 0, y = t ? Math.min(t.bottom, a.bottom) - Math.max(t.top, a.top) : 0;
            return { shown, tab: t && [Math.round(t.left), Math.round(t.top), Math.round(t.right), Math.round(t.bottom)], action: [Math.round(a.left), Math.round(a.top), Math.round(a.right), Math.round(a.bottom)], covered: x > 0 && y > 0 ? [Math.round(x), Math.round(y)] : null };
        }
        JS;

    $page = visit(BrowserLogin::url($player))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $measured = [];

    foreach ([[1280, 800], [1280, 720], [390, 844]] as [$width, $height]) {
        file_put_contents($hls.'/stream.m3u8', "#EXTM3U\n");
        Cache::forget(LiveStatus::CACHE_KEY);
        $page->setViewportSize($width, $height);
        $page->goto(ComputeUrl::from(route('tournaments.show', $chess)));
        BrowserWait::until($page, '() => document.readyState === "complete" && !! document.querySelector("[data-test=now-action]") && !! document.querySelector("[data-test=live-player]")', 8_000);
        // The player measures once a second: two rounds of it.
        $page->evaluate('() => new Promise((ok) => setTimeout(ok, 2200))');
        $top = $page->evaluate($cover);
        // Scrolled to the end, the action is gone and the tab is back where it was.
        $page->evaluate('() => { scrollTo(0, document.scrollingElement.scrollHeight); return new Promise((ok) => setTimeout(ok, 1500)); }');
        $end = $page->evaluate($cover);
        $measured["{$width}x{$height}"] = ['top' => $top, 'end' => $end];

        expect($top['covered'])->toBeNull("{$width}x{$height}: the tab covers the action ".json_encode($top))
            // Below 1024 px a page with its own bottom bar or the dock owns the bottom edge: the tab stays aside on the whole page
            // (plan "Canvas and live alignment", P1; the tab bar's Live tab is the way to the stream there).
            ->and($end['shown'])->toBe($width >= 1024, "{$width}x{$height}: the tab is back on a desktop, aside on a phone")
            ->and($end['covered'])->toBeNull()
            ->and($page->evaluate('() => window.__errors'))->toBe([])
            ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
    }

    // Positive control: a throw and a missing image reach the same collector.
    $page->evaluate('() => { setTimeout(() => { throw new Error("live tab positive control") }, 0); const img = new Image(); img.src = "/__missing-live-tab-control.png"; document.body.append(img); return null; }');
    BrowserWait::until($page, '() => window.__errors.length >= 2', 5_000);
    expect(implode("\n", $page->evaluate('() => window.__errors')))->toContain('live tab positive control')->toContain('__missing-live-tab-control.png');

    fwrite(STDERR, "\n[now-live-tab] ".json_encode($measured)."\n");
    File::deleteDirectory($hls);
});

test('before the start a drawn player finds the start time big in the page hero and in "What to do now", above the fold at 390, 1440 and 1920 (user, 2026-10-25); clean console', function () {
    // Drawn at the sign-up close, so the league holds every match until the start (TournamentMatchMaker).
    Tournament::creating(fn (Tournament $tournament) => $tournament->forceFill(['starts_at' => now()->addMinutes(90), 'draw_committed_at' => now()->subDay()]));
    $tournament = runningChess(TournamentFormat::Swiss, 4, TournamentResultsMode::Players);
    $tournament->forceFill(['name' => 'Blitz Schachturnier', 'published_at' => now()])->save();
    $player = TournamentParticipant::query()->where('tournament_id', $tournament->id)->firstOrFail()->user;
    $player->forceFill(['locale' => 'de', 'timezone' => 'Europe/Berlin'])->save();
    Admin::query()->create(['pubkey' => $player->pubkey]);
    $clock = $tournament->starts_at->copy()->setTimezone('Europe/Berlin')->format('H:i');
    $box = '(s) => { const el = document.querySelector(s); if (! el) return null; const r = el.getBoundingClientRect(); if (r.height === 0) return null; return { top: Math.round(r.top + scrollY), bottom: Math.round(r.bottom + scrollY), left: Math.round(r.left), right: Math.round(r.right), size: parseFloat(getComputedStyle(el).fontSize), text: el.innerText }; }';

    $page = visit(BrowserLogin::url($player))->page();
    $page->context()->addInitScript(NOW_COLLECTOR);
    $measured = [];

    foreach ([[390, 844], [1440, 900], [1920, 1080]] as [$width, $height]) {
        $page->setViewportSize($width, $height);
        $page->goto(ComputeUrl::from(route('tournaments.show', $tournament)));
        BrowserWait::until($page, '() => document.querySelector("[data-test=now-starts]") !== null && document.readyState === "complete"', 8_000);
        usleep(1_200_000);
        $m = $page->evaluate(NOW_MEASURE) + [
            'clock' => $page->evaluate("() => ({$box})('[data-test=now-starts-clock]')"),
            'starts' => $page->evaluate("() => ({$box})('[data-test=now-starts]')"),
            'countdown' => $page->evaluate('() => document.querySelector("[data-test=now-starts-countdown]").innerText'),
            'heroClock' => $page->evaluate("() => ({$box})('[data-test=tournament-start-clock]')"),
        ];
        nowShot($page, "now-starts-de-{$width}");

        expect($m['state'])->toBe('wait')
            ->and($m['clock']['text'])->toBe($clock)
            ->and($m['clock']['size'])->toBeGreaterThanOrEqual(40)
            // The page hero names it from lg; a phone reads it in "What to do now" alone.
            ->and($m['heroClock']['text'] ?? '')->toBe($width < 1024 ? '' : $clock)
            ->and($m['starts']['left'])->toBeGreaterThanOrEqual(0)
            ->and($m['starts']['right'])->toBeLessThanOrEqual($width)
            // Ticking: the countdown left its first frame (01:30:00) behind.
            ->and($m['countdown'])->toMatch('/^01:29:\d\d$/')
            ->and($m['starts']['bottom'])->toBeLessThanOrEqual($m['tab'] ?? $height)
            ->and($m['overflow'])->toBeLessThanOrEqual(0)
            ->and($m['text'])->toContain('Euer Match startet um')->toContain('Ortszeit Berlin')
            ->and($m['errors'])->toBe([]);

        $measured[$width] = array_diff_key($m, ['text' => 1]);
    }

    // Positive control: an error thrown on the page reaches the collector.
    $page->evaluate('() => { setTimeout(() => { throw new Error("probe"); }, 0); }');
    BrowserWait::until($page, '() => window.__errors.length > 0', 5_000);
    expect(implode(' | ', $page->evaluate('() => window.__errors')))->toContain('probe');

    fwrite(STDERR, "\n[starts] ".json_encode($measured)."\n");
});

test('a player with a bye sees the running games and the countdown to their latest end, inside the hero at 390 and 1440 (user, 2026-10-25); clean console', function () {
    $tournament = runningChess(TournamentFormat::Swiss, 3, TournamentResultsMode::Players);
    $tournament->forceFill(['published_at' => now()])->save();
    $bye = TournamentMatch::query()->where('tournament_id', $tournament->id)->where('bracket', 'bye')->with('slots.participant.user')->firstOrFail();
    $viewer = $bye->slots[0]->participant->user;
    $viewer->forceFill(['locale' => 'de'])->save();
    ChessGame::query()->whereIn('tournament_match_id', $tournament->matches()->select('id'))->update(['white_ms' => 300_000, 'black_ms' => 300_000, 'turn_started_ms' => (int) (microtime(true) * 1000)]);

    $page = visit(BrowserLogin::url($viewer))->page();
    $page->context()->addInitScript(NOW_COLLECTOR);
    $measured = [];

    foreach ([[360, 740], [390, 844], [1440, 900]] as [$width, $height]) {
        $page->setViewportSize($width, $height);
        $page->goto(ComputeUrl::from(route('tournaments.show', $tournament)));
        BrowserWait::until($page, '() => document.querySelector("[data-test=now-eta]") !== null && document.readyState === "complete"', 8_000);
        usleep(1_200_000);
        $m = $page->evaluate(NOW_MEASURE) + $page->evaluate('() => { const box = (s) => { const r = document.querySelector(s).getBoundingClientRect(); return { left: Math.round(r.left), right: Math.round(r.right), bottom: Math.round(r.bottom + scrollY) }; };
            return { eta: box("[data-test=now-eta]"), board: box("[data-test=now-board]"), countdown: document.querySelector("[data-test=now-eta-countdown]").innerText }; }');
        nowShot($page, "now-bye-eta-{$width}");
        $measured[$width] = array_diff_key($m, ['text' => 1]);

        expect($m['state'])->toBe('bye')
            ->and($m['overflow'])->toBeLessThanOrEqual(0)
            ->and($m['eta']['right'])->toBeLessThanOrEqual($m['right'])
            ->and($m['board']['right'])->toBeLessThanOrEqual($m['right'])
            ->and($m['countdown'])->toMatch('/^00:09:\d\d$/')
            // The whole hero above the tab bar on a small phone too (user 2026-11-03: the tournament's header goes compact).
            ->and($m['bottom'])->toBeLessThanOrEqual($m['tab'] ?? $height, "bye {$width}")
            ->and($m['text'])->toContain('Zug 1')->toContain('spätestens vorbei')
            ->and($m['errors'])->toBe([]);
    }

    $page->evaluate('() => { setTimeout(() => { throw new Error("probe"); }, 0); }');
    BrowserWait::until($page, '() => window.__errors.length > 0', 5_000);
    expect(implode(' | ', $page->evaluate('() => window.__errors')))->toContain('probe');

    fwrite(STDERR, "\n[bye-eta] ".json_encode($measured)."\n");
});

/**
 * The hero of a waiting state measured at 375 x 812 and 360 x 740 in German and English as an admin: its bottom against the phone's
 * tab bar, the texts it must keep, no overflow, a clean console after the load and after a Livewire roundtrip, and a
 * positive control. Gate review of 69a40451 (2026-11-03): "done" ended at 824 / 834 and a bye with a round deadline at
 * 762 in German, the tab bar starts at 748.
 *
 * @param  array<string, list<string>>  $texts  per locale, the texts the hero must keep
 * @return array<string, array<string, mixed>>
 */
function nowWaitingFold(User $viewer, Tournament $tournament, string $state, array $texts, string $shot): array
{
    Admin::query()->firstOrCreate(['pubkey' => $viewer->pubkey]);
    $measured = [];

    foreach ([['de', 375, 812], ['en', 375, 812], ['de', 360, 740], ['en', 360, 740]] as [$locale, $width, $height]) {
        $viewer->forceFill(['locale' => $locale])->save();
        $page = visit(BrowserLogin::url($viewer))->page();
        $page->context()->addInitScript(NOW_COLLECTOR);
        $page->setViewportSize($width, $height);
        $page->goto(ComputeUrl::from(route('tournaments.show', $tournament)));
        BrowserWait::until($page, '() => document.querySelector("[data-test=now-hero]") !== null && document.readyState === "complete"', 8_000);
        usleep(500_000);
        $m = $page->evaluate(NOW_MEASURE);
        nowShot($page, "{$shot}-{$locale}-{$width}");
        $measured["{$locale}-{$width}"] = array_diff_key($m, ['text' => 1]);

        expect($m['state'])->toBe($state)
            ->and($m['first'])->toBeTrue()
            ->and($m['overflow'])->toBeLessThanOrEqual(0)
            ->and($m['right'])->toBeLessThanOrEqual($width)
            ->and($m['tab'])->not->toBeNull()
            ->and($m['bottom'])->toBeLessThanOrEqual($m['tab'], "{$state} {$locale} {$width}")
            ->and($m['errors'])->toBe([])
            ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([])
            ->and($page->evaluate(BrowserConsole::SQUEEZED, ['[data-test=tournament-hero], [data-test=now-hero]']))->toBe([]);
        foreach ($texts[$locale] as $text) {
            expect($m['text'])->toContain($text);
        }

        $page->evaluate('() => { window.__rt = false; Livewire.all().find((c) => c.el.matches("[data-test=tournament-show]")).$wire.$refresh().then(() => { window.__rt = true; }); }');
        BrowserWait::until($page, '() => window.__rt === true && document.querySelector("[data-test=now-hero]") !== null', 8_000);
        expect($page->evaluate('() => window.__errors'))->toBe([])
            ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
    }

    $page->evaluate('() => { setTimeout(() => { throw new Error("probe"); }, 0); }');
    BrowserWait::until($page, '() => window.__errors.length > 0', 5_000);
    expect(implode(' | ', $page->evaluate('() => window.__errors')))->toContain('probe');

    return $measured;
}

test('a player whose games are done sees the running games and their latest end inside the hero, above the tab bar at 375 in German and English (gate 2026-11-03); clean console', function () {
    $tournament = runningChess(TournamentFormat::RoundRobin, 4, TournamentResultsMode::Players);
    // A fixed name: the tournament's own hero above stays the same height on every run.
    $tournament->forceFill(['name' => 'Halving Blitz Cup', 'published_at' => now()])->save();
    $active = fn () => ChessGame::query()->with(['white', 'black'])->whereIn('tournament_match_id', $tournament->matches()->select('id'))->where('status', 'active')->orderBy('id')->get();

    foreach ([1, 2] as $round) {
        foreach ($active() as $game) {
            app(ChessGameService::class)->resign($game, $game->black);
        }
    }

    // Round 3: the viewer wins their game, the other one keeps running on five minutes per clock.
    [$mine] = $active()->all();
    $viewer = $mine->white;
    app(ChessGameService::class)->resign($mine, $mine->black);
    ChessGame::query()->whereIn('tournament_match_id', $tournament->matches()->select('id'))->where('status', 'active')
        ->update(['white_ms' => 300_000, 'black_ms' => 300_000, 'turn_started_ms' => (int) (microtime(true) * 1000)]);

    $measured = nowWaitingFold($viewer, $tournament, 'done', [
        'de' => ['Deine Spiele sind fertig', 'spätestens vorbei'],
        'en' => ['Your games are done', 'over at the latest'],
    ], 'now-done');

    fwrite(STDERR, "\n[done] ".json_encode($measured)."\n");
});

test('a player with a bye sees the round deadline and the running games\' latest end inside the hero, above the tab bar at 375 in German and English (gate 2026-11-03); clean console', function () {
    $tournament = runningChess(TournamentFormat::Swiss, 3, TournamentResultsMode::Players);
    $tournament->forceFill(['name' => 'Halving Blitz Cup', 'published_at' => now()])->save();
    $bye = TournamentMatch::query()->where('tournament_id', $tournament->id)->where('bracket', 'bye')->with('slots.participant.user')->firstOrFail();
    $viewer = $bye->slots[0]->participant->user;
    ChessGame::query()->whereIn('tournament_match_id', $tournament->matches()->select('id'))->update(['white_ms' => 300_000, 'black_ms' => 300_000, 'turn_started_ms' => (int) (microtime(true) * 1000)]);
    TournamentRound::query()->findOrFail($bye->tournament_round_id)->forceFill(['window_ends_at' => now()->addHours(2)])->save();

    $measured = nowWaitingFold($viewer, $tournament, 'bye', [
        'de' => ['Rundenschluss in', 'spätestens vorbei', 'Zug 1'],
        'en' => ['Round deadline in', 'over at the latest', 'move 1'],
    ], 'now-bye-deadline');

    fwrite(STDERR, "\n[bye-deadline] ".json_encode($measured)."\n");
});
