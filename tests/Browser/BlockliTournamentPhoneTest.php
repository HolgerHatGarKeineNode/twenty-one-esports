<?php

use App\Enums\BoardGameStatus;
use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Models\BoardGame;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\Board\BoardGameService;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use App\Support\Tournaments\TournamentBrackets;
use App\Support\Tournaments\TournamentRunner;
use App\Support\TwentyOne\LiveStatus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BlockliOn;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;

pest()->group('browser');

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
    BlockliOn::play();
});

test('a tournament Blockli board is as large on a phone as a casual one, its banner one line above it in the title row, then a chip in the header once scrolled', function (int $width, int $height, bool $onAir = false, int $slideMs = 200) {
    // On air the header's LIVE mark takes room beside the chip: the narrowest header.
    $hls = sys_get_temp_dir().'/esports-chip-'.getmypid().'-'.bin2hex(random_bytes(3));
    File::ensureDirectoryExists($hls);
    config(['twentyone.stream.hls_dir' => $hls, 'twentyone.stream.public_url' => '/__test/live/stream.m3u8']);
    if ($onAir) {
        file_put_contents($hls.'/stream.m3u8', "#EXTM3U\n");
        Cache::put(LiveStatus::ANNOUNCED_KEY, ['viewers' => 1234, 'title' => 'Live now'], 600);
    }
    $tournament = Tournament::factory()->create([
        'game' => 'blockli', 'mode' => 'correspondence', 'format' => TournamentFormat::SingleElimination, 'name' => 'Blockli Phone Cup with a rather long name',
        'options' => FormatOptions::fromArray([], GameProfile::for('blockli', 'correspondence'))->toArray(),
        'capacity' => 2, 'results_mode' => TournamentResultsMode::Players, 'status' => TournamentStatus::Running, 'slug' => 'blockli-phone-cup',
    ]);
    $users = [];
    foreach (['Anna Phone', 'Bert Phone'] as $index => $name) {
        $users[] = $user = User::factory()->create();
        TournamentParticipant::query()->create(['tournament_id' => $tournament->id, 'user_id' => $user->id, 'name' => $name, 'rating' => 1500 - $index, 'members' => [$user->id]]);
    }
    app(TournamentBrackets::class)->generate($tournament, str_repeat('ef', 32));
    app(TournamentRunner::class)->sync($tournament);
    $game = BoardGame::query()->whereIn('tournament_match_id', $tournament->matches()->select('id'))->where('status', BoardGameStatus::Active)->sole();
    $player = $game->white;
    // The same player in a casual game: the board the tournament one must match.
    $casual = app(BoardGameService::class)->start('blockli', $player, User::factory()->create());

    $page = visit(BrowserLogin::url($player))->on()->mobile()->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize($width, $height);
    $measure = <<<'JS'
        () => {
            const box = (sel) => { const el = document.querySelector(sel); if (!el || !el.checkVisibility()) return null; const r = el.getBoundingClientRect(); return { top: Math.round(r.top), bottom: Math.round(r.bottom), left: Math.round(r.left), right: Math.round(r.right), w: Math.round(r.width), h: Math.round(r.height) }; };
            const row = document.querySelector('[data-test=board-game] > div:first-child');
            const name = document.querySelector('[data-test=tournament-banner-row-name]');
            return {
                board: box('[data-test=board]'), title: box('[data-test=board-game] > div:first-child'), banner: box('[data-test=tournament-banner]'), line: box('[data-test=tournament-banner-row]'),
                chip: box('[data-test=tournament-banner-row] > span'), desk: box('[data-test=board-game] [data-test=desk-button]'),
                nameText: name?.textContent ?? null, nameCut: !!name && name.scrollWidth > name.clientWidth, round: document.querySelector('[data-test=tournament-banner-row-round]')?.textContent ?? null,
                rowFits: !!row && row.scrollWidth <= row.clientWidth, overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
                pill: box('[data-test=board-game] > div:first-child [role=status]'), heading: box('[data-test=board-game] h1'),
                shell: box('[data-test=shell-tournament]'), shellChip: box('[data-test=shell-tournament] > span'), shellRow: box('.shell-header > div:not(.shell-edge)'),
                shellFits: (() => { const r = document.querySelector('.shell-header > div:not(.shell-edge)'); return !!r && r.scrollWidth <= r.clientWidth; })(),
                shellName: document.querySelector('[data-test=shell-tournament-name]')?.textContent ?? null,
                search: box('.shell-header [aria-controls=mobile-search]'),
            };
        }
        JS;
    // The board is sized twice on a phone too short for its 200 px floor (an iPhone SE in Safari, 553 px high): fitBoard sets
    // the floor, slides the tab bar away (focus mode) and, on the `focus-mode` event, fits again in the room the bar gave up
    // (212 px here). That event used to come 220 ms after the slide began, ended or not: when the slide's first frame came
    // late (a host with no idle CPU, 16 shards, 2026-10-24) the fit read the bars half-way down and the board stayed at
    // 200 px for good, one page of the pair 12 px short of the other. focusMode.js now waits for the slide itself. A slide of
    // `$slideMs` = 600 outlasts the 220 ms every time: that late frame, made deterministic. Both pages are read once their
    // second fit has run, and only if a fit on the settled page would change nothing.
    $page->context()->addInitScript(<<<JS
        window.__lastFocusModeHidden = null;
        addEventListener('focus-mode', (event) => { window.__lastFocusModeHidden = event.detail.hidden; });
        if ({$slideMs} !== 200) {
            document.addEventListener('DOMContentLoaded', () => {
                const style = document.createElement('style');
                style.textContent = '[data-test=tab-bar], [data-page-bar] { transition-duration: {$slideMs}ms !important; }';
                document.head.append(style);
            });
        }
        JS);
    $settle = function () use ($page): void {
        BrowserWait::until($page, '() => window.Alpine && !!document.querySelector("[data-test=board]")', 10_000);
        // Fitted at all, and, with the tab bar sliding away, fitted again after the slide.
        BrowserWait::until($page, '() => document.querySelector("[data-board-box]")?.style.maxWidth !== "" && (!document.documentElement.classList.contains("is-focus") || window.__lastFocusModeHidden === true)', 10_000);
        BrowserWait::until($page, '() => document.getAnimations().every((a) => a.playState !== "running" || a.effect?.getTiming().iterations === Infinity)', 5_000);
        $fitted = $page->evaluate('() => document.querySelector("[data-board-box]").style.maxWidth');
        $page->evaluate('() => window.dispatchEvent(new CustomEvent("focus-mode", { detail: { hidden: document.documentElement.classList.contains("is-focus") } }))');
        expect($page->evaluate('() => document.querySelector("[data-board-box]").style.maxWidth'))->toBe($fitted, 'the board was fitted after the bars had finished sliding');
    };
    $page->goto(ComputeUrl::from(route('board.show', $casual, false)));
    $settle();
    $plain = $page->evaluate($measure);
    $page->goto(ComputeUrl::from(route('board.show', $game, false)));
    $settle();
    $m = $page->evaluate($measure);
    // Scrolled on, the title row's line is gone under the header: the header's chip takes over (2026-10-15).
    $page->evaluate('() => window.scrollTo(0, document.documentElement.scrollHeight)');
    BrowserWait::until($page, '() => document.querySelector("[data-test=shell-tournament]")?.checkVisibility()', 5_000);
    $scrolled = $page->evaluate($measure);
    fwrite(STDERR, PHP_EOL."tournament {$width}x{$height} ".json_encode(['casual' => $plain, 'tournament' => $m, 'scrolled' => $scrolled]).PHP_EOL);

    // DerCaddy, 2026-10-15: the banner above the game, one line only, and the board no smaller than in a casual game.
    expect($m['board']['w'])->toBe($plain['board']['w'], 'as large as a casual board')
        ->and($m['board']['top'])->toBe($plain['board']['top'], 'starting where a casual board starts')
        ->and($m['title']['h'])->toBe($plain['title']['h'], 'the title row keeps its height')
        ->and($m['banner'])->toBeNull('the full banner is for lg and up')
        ->and($m['line'])->not->toBeNull()
        ->and($m['line']['bottom'])->toBeLessThanOrEqual($m['board']['top'], 'the banner line stands above the board')
        ->and($m['line']['h'])->toBeGreaterThanOrEqual(44, 'its tap area')
        ->and($m['chip']['h'])->toBeLessThanOrEqual(34, 'one line')
        ->and($m['nameText'])->toBe('Blockli Phone Cup with a rather long name')
        ->and($m['round'])->toContain('1')
        ->and($m['rowFits'])->toBeTrue('nothing spills out of the title row')
        ->and($m['overflow'])->toBeLessThanOrEqual(0)
        // The connection pill stays in the title row (at 320 px it fell onto a line of its own): one row, the pill beside the name.
        ->and($plain['pill']['top'])->toBeLessThan($plain['heading']['bottom'], 'the pill beside the name, casual')
        ->and($m['pill']['top'])->toBeLessThan($m['line']['bottom'], 'the pill beside the banner line')
        // The header's chip: none for a casual game, none while the title row's line is in view, then always at the top.
        ->and($plain['shell'])->toBeNull('no chip for a casual game')
        ->and($m['shell'])->toBeNull('no chip while the line is in view')
        ->and($scrolled['line']['bottom'])->toBeLessThanOrEqual($scrolled['shellRow']['bottom'], 'the line has scrolled away')
        ->and($scrolled['shell']['h'])->toBeGreaterThanOrEqual(44, 'its tap area')
        ->and($scrolled['shellChip']['h'])->toBeLessThanOrEqual(30)
        ->and($scrolled['shellChip']['top'])->toBeGreaterThanOrEqual($scrolled['shellRow']['top'])
        ->and($scrolled['shellChip']['bottom'])->toBeLessThanOrEqual($scrolled['shellRow']['bottom'], 'inside the sticky header')
        ->and($scrolled['shell']['right'])->toBeLessThanOrEqual($scrolled['search']['left'], 'clear of the header\'s buttons')
        ->and($scrolled['shellChip']['w'])->toBeGreaterThanOrEqual($onAir ? 30 : 44, $onAir ? 'the trophy at least' : 'room for the trophy and a word')
        ->and($scrolled['shellFits'])->toBeTrue('the header row keeps one line')
        ->and($scrolled['shellName'])->toBe('Blockli Phone Cup with a rather long name')
        ->and($scrolled['overflow'])->toBeLessThanOrEqual(0)
        ->and($page->evaluate('() => window.__errors'))->toBe([]);
    expect(! $onAir || $page->evaluate('() => [...document.querySelectorAll(".shell-header [data-test=live-badge]")].some((el) => el.checkVisibility())'))->toBeTrue('on air: the LIVE mark beside the chip');
    File::deleteDirectory($hls);
})->with([
    'phone 320, on air' => [320, 640, true],
    'phone 320' => [320, 640],
    'phone 390' => [390, 844],
    'phone 360' => [360, 740],
    'iPhone SE in Safari' => [375, 553],
    'iPhone SE in Safari, the bars slide slowly' => [375, 553, false, 600],
]);

test('on a phone too short for the board the tab bar stays away when Livewire reports the page late, and of two changes that overlap only the last one reports', function () {
    // The full run of 2026-11-14 (16 shards, load average 45): the board's page had the tab bar away and its last
    // `focus-mode` event said "shown", so the two tests that wait for the hide's event waited in vain. focusMode.js started
    // twice on a plain page load (DOMContentLoaded, then Livewire's `livewire:navigated` a task later): the second start
    // called the bar back, the board sent it away again, and the three delayed events came in no fixed order.
    [$anna, $bert] = User::factory()->count(2)->create();
    $game = app(BoardGameService::class)->start('blockli', $anna, $bert);
    $page = visit(BrowserLogin::url($anna))->on()->mobile()->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize(375, 553);
    // Every `focus-mode` event with what the bar does at that moment. And the seam: Livewire's report of the page load is
    // held until the test lets it go, after the bars have slid: a phone whose main thread is busy, made deterministic.
    $page->context()->addInitScript(<<<'JS'
        (() => {
            window.__focus = { events: [], held: 0, release: null };
            const away = () => document.documentElement.classList.contains('is-focus');
            addEventListener('focus-mode', (event) => window.__focus.events.push([event.detail.hidden, away()]));
            document.addEventListener('livewire:navigated', (event) => {
                if (window.__focus.held > 0) return;
                window.__focus.held++;
                event.stopImmediatePropagation();
                window.__focus.release = () => { document.dispatchEvent(new CustomEvent('livewire:navigated', { bubbles: true })); return away(); };
            }, true);
        })();
        JS);
    $state = '() => ({ away: document.documentElement.classList.contains("is-focus"), board: document.querySelector("[data-board-box]").style.maxWidth, tabBar: Math.round(document.querySelector("[data-test=tab-bar]").getBoundingClientRect().top), events: window.__focus.events.length })';
    $settle = function (int $after) use ($page): void {
        // The hide has reported, as the last event, and nothing slides any more.
        BrowserWait::until($page, "() => window.__focus?.events.length > {$after} && window.__focus.events.at(-1)[0] === true && document.documentElement.classList.contains('is-focus')", 10_000);
        BrowserWait::until($page, '() => document.getAnimations().every((a) => a.playState !== "running" || a.effect?.getTiming().iterations === Infinity)', 5_000);
    };
    $page->goto(ComputeUrl::from(route('board.show', $game, false)));
    BrowserWait::until($page, '() => window.Alpine && !!document.querySelector("[data-test=board]")', 10_000);
    $settle(0);
    $loaded = $page->evaluate($state);

    // Livewire reports the page now: the bar stays away at once, and two frames on nothing has moved or reported.
    expect($page->evaluate('() => window.__focus.held'))->toBe(1, 'the seam held Livewire\'s report of the page load');
    $stillAway = $page->evaluate('() => window.__focus.release()');
    $reported = $page->evaluate('() => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(() => resolve(('.$state.')()))))');
    fwrite(STDERR, PHP_EOL.'[focus 375x553] '.json_encode(compact('loaded', 'stillAway', 'reported')).PHP_EOL);

    expect($loaded['tabBar'])->toBe(553, 'the tab bar is below the screen')
        ->and($stillAway)->toBeTrue('the tab bar stays away when Livewire reports the page')
        ->and($reported)->toBe($loaded, 'nothing moved and nothing was reported');

    // The bar called back and sent away again within one task (a tap on the bottom edge, then one on the board): the
    // show is overtaken and stays silent, the hide reports once, and no event says other than what the bar does.
    $page->evaluate('() => { for (const selector of ["[data-test=focus-edge]", "[data-focus-board]"]) document.querySelector(selector).dispatchEvent(new PointerEvent("pointerdown", { bubbles: true })); }');
    $settle($loaded['events']);
    $events = $page->evaluate('() => window.__focus.events');
    fwrite(STDERR, '[focus events] '.json_encode($events).PHP_EOL);

    expect(array_slice($events, $loaded['events']))->toBe([[true, true]], 'one report for two overlapping changes, saying what the bar does')
        ->and(collect($events)->reject(fn (array $event): bool => $event[0] === $event[1])->all())->toBe([], 'every focus-mode event says what the bar does')
        ->and($page->evaluate($state)['board'])->toBe($loaded['board'], 'the board keeps its size')
        ->and($page->evaluate('() => window.__errors'))->toBe([])
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);

    // Positive control: the collector sees a throw, a console error and a failed answer on this very page.
    $page->evaluate('() => { console.error("focus console control"); setTimeout(() => { throw new Error("focus positive control"); }); fetch("/board/0"); }');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("focus positive control")) && window.__errors.some((e) => e.includes("focus console control")) && window.__errors.some((e) => e.startsWith("404 "))', 5_000);
});

/** A running single-elimination Blockli cup of two, and its one active game. */
function phoneCupGame(string $name): BoardGame
{
    $tournament = Tournament::factory()->create([
        'game' => 'blockli', 'mode' => 'correspondence', 'format' => TournamentFormat::SingleElimination, 'name' => $name,
        'options' => FormatOptions::fromArray([], GameProfile::for('blockli', 'correspondence'))->toArray(),
        'capacity' => 2, 'results_mode' => TournamentResultsMode::Players, 'status' => TournamentStatus::Running, 'slug' => 'blockli-panel-cup',
    ]);
    foreach (['Anna Panel', 'Bert Panel'] as $index => $player) {
        $user = User::factory()->create();
        TournamentParticipant::query()->create(['tournament_id' => $tournament->id, 'user_id' => $user->id, 'name' => $player, 'rating' => 1500 - $index, 'members' => [$user->id]]);
    }
    app(TournamentBrackets::class)->generate($tournament, str_repeat('ef', 32));
    app(TournamentRunner::class)->sync($tournament);

    return BoardGame::query()->whereIn('tournament_match_id', $tournament->matches()->select('id'))->where('status', BoardGameStatus::Active)->sole();
}

test('a tap on the banner line or the header chip opens the whole banner over the page, and a tap anywhere else folds it without touching the board', function (int $width, int $height) {
    // DerCaddy, 2026-10-15: "Wäre es möglich, dass sich das Banner mit Klick darauf vergrößert? Und sobald man wieder woanders klickt, wird es wieder klein."
    $game = phoneCupGame('Blockli Phone Cup with a rather long name that never fits one line');
    $page = visit(BrowserLogin::url($game->white))->on()->mobile()->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize($width, $height);
    $page->goto(ComputeUrl::from(route('board.show', $game, false)));
    BrowserWait::until($page, '() => window.Alpine && Alpine.$data(document.querySelector("[data-test=board-game]"))?.canMove', 10_000);
    BrowserWait::until($page, '() => document.getAnimations().every((a) => a.playState !== "running" || a.effect?.getTiming().iterations === Infinity)', 5_000);
    $measure = <<<'JS'
        () => {
            const box = (sel) => { const el = document.querySelector(sel); if (!el || !el.checkVisibility()) return null; const r = el.getBoundingClientRect(); return { top: Math.round(r.top), bottom: Math.round(r.bottom), left: Math.round(r.left), right: Math.round(r.right), h: Math.round(r.height) }; };
            const name = document.querySelector('[data-test=banner-sheet-name]');
            return {
                board: box('[data-test=board]'), panel: box('[data-test=banner-sheet]'), shade: box('[data-test=banner-sheet-shade]'), header: box('.shell-header'),
                name: name?.checkVisibility() ? name.textContent : null, nameWhole: !!name && name.scrollWidth <= name.clientWidth,
                link: box('[data-test=banner-sheet-link]'), expanded: document.querySelector('[data-test=tournament-banner-row]').getAttribute('aria-expanded'),
                focus: document.activeElement?.dataset.test ?? null, ply: Alpine.$data(document.querySelector('[data-test=board-game]')).state.ply,
                overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
            };
        }
        JS;
    $closed = $page->evaluate($measure);
    $page->locator('[data-test=tournament-banner-row]')->click();
    BrowserWait::until($page, '() => !!document.querySelector("[data-test=banner-sheet]")?.checkVisibility()', 3_000);
    $open = $page->evaluate($measure);
    // A tap on the board below the panel: the shade takes it, the panel folds, no move is made and no block shown. The
    // point lies under the panel and on screen: on the board where the panel leaves it free, else just below it (at 320x640
    // the open panel covers the whole board).
    $tapY = max($open['panel']['bottom'] + 16, min($open['board']['bottom'] - 40, $height - 20));
    fwrite(STDERR, PHP_EOL."banner tap {$width}x{$height} y={$tapY} panel={$open['panel']['bottom']} board={$open['board']['top']}-{$open['board']['bottom']}".PHP_EOL);
    $page->locator('[data-test=banner-sheet-shade]')->click(['position' => ['x' => (int) (($open['board']['left'] + $open['board']['right']) / 2), 'y' => $tapY]]);
    BrowserWait::until($page, '() => !document.querySelector("[data-test=banner-sheet]")?.checkVisibility()', 3_000);
    $folded = $page->evaluate($measure);
    $preview = $page->evaluate('() => Alpine.$data(document.querySelector("[data-test=board-game]")).preview');
    // Scrolled on, the header's chip opens the same panel; Escape folds it and hands the focus back to an opener in view
    // (the chip, or the line once a short page brings it back and the chip steps aside).
    $page->evaluate('() => window.scrollTo(0, document.documentElement.scrollHeight)');
    BrowserWait::until($page, '() => document.querySelector("[data-test=shell-tournament]")?.checkVisibility()', 5_000);
    $page->locator('[data-test=shell-tournament]')->click();
    BrowserWait::until($page, '() => !!document.querySelector("[data-test=banner-sheet]")?.checkVisibility()', 3_000);
    $fromChip = $page->evaluate($measure);
    $page->locator('[data-test=banner-sheet-close]')->press('Escape');
    BrowserWait::until($page, '() => !document.querySelector("[data-test=banner-sheet]")?.checkVisibility()', 3_000);
    $back = $page->evaluate('() => document.activeElement?.dataset.test ?? null');
    fwrite(STDERR, PHP_EOL."banner panel {$width}x{$height} ".json_encode(compact('closed', 'open', 'folded', 'fromChip', 'back')).PHP_EOL);

    expect($closed['panel'])->toBeNull()
        ->and($open['panel'])->not->toBeNull()
        ->and($open['name'])->toBe('Blockli Phone Cup with a rather long name that never fits one line')
        ->and($open['nameWhole'])->toBeTrue('the whole name, wrapped, nothing cut')
        ->and($open['panel']['top'])->toBeGreaterThanOrEqual($open['header']['bottom'], 'under the header')
        ->and($open['panel']['bottom'])->toBeLessThanOrEqual($height)
        ->and($open['link']['h'])->toBeGreaterThanOrEqual(44, 'the tournament page one tap away')
        ->and($open['expanded'])->toBe('true')
        ->and($open['focus'])->toBe('banner-sheet-close')
        ->and($open['board'])->toBe($closed['board'], 'the board neither moves nor shrinks')
        ->and($folded['panel'])->toBeNull()
        ->and($folded['expanded'])->toBe('false')
        ->and($folded['ply'])->toBe($closed['ply'], 'the folding tap made no move')
        ->and($preview)->toBeNull('and showed no block')
        ->and($fromChip['panel'])->not->toBeNull()
        ->and($back)->toBeIn(['shell-tournament', 'tournament-banner-row'])
        ->and($open['overflow'])->toBeLessThanOrEqual(0)
        ->and($page->evaluate('() => window.__errors'))->toBe([]);
})->with([
    'phone 390' => [390, 844],
    'phone 320' => [320, 640],
]);
