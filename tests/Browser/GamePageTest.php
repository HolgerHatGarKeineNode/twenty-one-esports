<?php

use App\Enums\SeriesStatus;
use App\Models\Clan;
use App\Models\Lineup;
use App\Models\MatchNumber;
use App\Models\Rating;
use App\Models\SeriesMatch;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| A series game's page (P26): matches, ladder, clans, worth
|--------------------------------------------------------------------------
|
| The Rocket League page with a live series, an open challenge, finished
| series with scores, a tournament 1v1 of two players, a ladder with five
| lineups and four clans; then EA FC 27 with nothing at all, whose parts
| must each say so in one line. Measured at 1440 and 375 (and German at
| 375): nothing wider than the window, no target under 44 px, nothing cut,
| and the console and the answers clean, with a positive control.
|
*/

beforeEach(function () {
    Http::fake(fn () => Http::response([]));
});

function gamePageSeed(): void
{
    $clans = collect(['Laser Eyes', 'Stack Sats', 'Node Runners', 'Orange Pill', 'Proof Of Work'])
        ->map(fn (string $name) => Clan::factory()->create(['name' => $name]));
    $lineups = $clans->map(fn (Clan $clan) => Lineup::factory()->ready()->create(['clan_id' => $clan->id]));
    $series = fn (int $a, int $b, array $attributes) => SeriesMatch::factory()->create(['challenger_lineup_id' => $lineups[$a]->id, 'challenged_lineup_id' => $lineups[$b]->id, ...$attributes]);
    $game = fn (int $c, int $d) => ['challenger' => $c, 'challenged' => $d, 'winner' => $c > $d ? 'challenger' : 'challenged'];

    $series(0, 1, ['status' => SeriesStatus::Accepted, 'start_at' => now()->subMinutes(12), 'live_games' => [$game(3, 1)]]);
    $series(2, 3, ['status' => SeriesStatus::Open]);
    $series(1, 2, ['status' => SeriesStatus::Confirmed, 'winner' => 'challenger', 'start_at' => now()->subHours(3), 'finished_at' => now()->subHours(2), 'result_games' => [$game(3, 1), $game(2, 4), $game(5, 2)]]);
    $series(3, 0, ['status' => SeriesStatus::Confirmed, 'winner' => 'challenged', 'start_at' => now()->subDays(2), 'finished_at' => now()->subDays(2), 'result_games' => [$game(0, 2), $game(1, 3)]]);
    $series(4, 0, ['status' => SeriesStatus::Confirmed, 'winner' => 'challenger', 'start_at' => now()->subDays(9), 'finished_at' => now()->subDays(9), 'result_games' => [$game(4, 0), $game(2, 1)]]);

    // A tournament's 1v1: two players, no lineups.
    [$anna, $bert] = User::factory()->count(2)->create();
    SeriesMatch::factory()->create([
        'challenger_lineup_id' => null, 'challenged_lineup_id' => null,
        'number' => MatchNumber::query()->create(['user_id' => $anna->id, 'used_at' => now()])->id,
        'created_by_id' => null, 'game' => 'rocket-league', 'mode' => '1v1', 'best_of' => 3,
        'challenger_name' => $anna->displayName(), 'challenged_name' => $bert->displayName(), 'challenger_tag' => 'ANNA', 'challenged_tag' => 'BERT',
        'challenger_lineup_address' => '', 'challenged_lineup_address' => '',
        'sides' => ['challenger' => [$anna->id], 'challenged' => [$bert->id]],
        'status' => SeriesStatus::Confirmed, 'winner' => 'challenged', 'start_at' => now()->subHours(6), 'finished_at' => now()->subHours(5), 'result_games' => [$game(1, 2), $game(0, 3)],
    ]);

    foreach ($lineups as $index => $lineup) {
        Rating::query()->create(['pool' => Rating::CASUAL, 'season' => '', 'game' => 'rocket-league', 'mode' => '3v3', 'subject' => 'lineup:'.$lineup->id, 'lineup_id' => $lineup->id,
            'rating' => 1080 - $index * 23, 'results' => 5 - $index, 'wins' => 3, 'draws' => 0, 'losses' => 2 - min(2, $index)]);
    }
}

function gamePage(string $path, int $width, int $height, ?User $user = null): Page
{
    $page = visit($user ? BrowserLogin::url($user) : '/robots.txt')->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize($width, $height);
    $page->goto(ComputeUrl::from($path));
    BrowserWait::until($page, '() => window.Alpine && document.querySelector("[data-test=game-matches]") !== null && document.fonts.status === "loaded"', 10_000);

    return $page;
}

function gamePageShot(Page $page, string $name, ?string $element = null): void
{
    $dir = getenv('CASUAL_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    File::ensureDirectoryExists($dir);
    $element === null ? $page->screenshot(true, $name) : $page->screenshotElement($element, $name);
    File::move(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
}

/**
 * @return array<string, mixed>
 */
function gamePageGeometry(Page $page): array
{
    return $page->evaluate('() => {
        const parts = ["game-matches", "game-ladder", "game-clans", "game-worth"].map((t) => document.querySelector("[data-test=" + t + "]"));
        const inside = parts.every((el) => { const r = el.getBoundingClientRect(); return r.left >= 0 && r.right <= window.innerWidth + 0.5; });
        const targets = parts.flatMap((el) => [...el.querySelectorAll("a[href], button")]).filter((el) => el.checkVisibility() && getComputedStyle(el).display !== "inline");
        const small = targets.filter((el) => el.getBoundingClientRect().height < 44).map((el) => (el.dataset.test || el.innerText.trim().slice(0, 30)) + " " + Math.round(el.getBoundingClientRect().height));
        const clipped = parts.flatMap((el) => [...el.querySelectorAll("*")]).filter((el) => el.checkVisibility() && el.children.length === 0 && el.scrollWidth > el.clientWidth + 1 && getComputedStyle(el).textOverflow !== "ellipsis" && getComputedStyle(el).overflowX === "visible").map((el) => el.dataset.test || el.tagName + ":" + el.innerText.slice(0, 20));
        const cards = [...document.querySelectorAll("[data-test=game-match]")].filter((el) => el.checkVisibility()).map((el) => Math.round(el.getBoundingClientRect().height));
        return { overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth, inside, small, clipped, cards };
    }');
}

function gamePageControl(Page $page): void
{
    $page->evaluate('() => { setTimeout(() => { throw new Error("probe-throw"); }); return fetch("/__test/server-error"); }');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("probe-throw")) && window.__errors.some((e) => e.startsWith("500 ")) && performance.getEntries().some((e) => e.name.includes("/__test/server-error") && e.responseStatus === 500)', 5_000);
    $page->evaluate('() => { window.__errors = []; performance.clearResourceTimings(); }');
}

test('the Rocket League page shows live, next and final series with faces and scores, the ladder top five and the clans, at 1440 and 375', function () {
    gamePageSeed();

    $wide = gamePage('/games/rocket-league', 1440, 900);
    gamePageControl($wide);
    $desk = gamePageGeometry($wide);
    $state = $wide->evaluate('() => ({
        states: [...document.querySelectorAll("[data-test=game-match]")].map((el) => el.dataset.state),
        scores: [...document.querySelectorAll("[data-test=game-match-score]")].map((el) => el.innerText.replace(/\\s+/g, " ").trim()),
        ladder: [...document.querySelectorAll("[data-test=game-ladder-rating]")].map((el) => el.innerText),
        ladderRow: [...new Set([...document.querySelectorAll("[data-test=game-ladder-row]")].map((el) => Math.round(el.getBoundingClientRect().top)))].length,
        clans: document.querySelectorAll("[data-test=game-clan]").length,
        weeks: document.querySelector("[data-test=game-weeks]") !== null,
    })');
    gamePageShot($wide, 'game-rl-1440');
    gamePageShot($wide, 'game-rl-matches-1440', '[data-test=game-matches]');

    $narrow = gamePage('/games/rocket-league', 375, 812);
    $phone = gamePageGeometry($narrow);
    gamePageShot($narrow, 'game-rl-375');

    expect($state['states'])->toBe(['live', 'next', 'final', 'final', 'final', 'final'])
        ->and($state['scores'])->toBe(['1 : 0', 'vs', '2 : 1', '0 : 2', '0 : 2', '2 : 0'])
        ->and($state['ladder'])->toBe(['1080', '1057', '1034', '1011', '988'])
        // The top five in one row from lg.
        ->and($state['ladderRow'])->toBe(1)
        ->and($state['clans'])->toBe(5)
        ->and($state['weeks'])->toBeTrue()
        ->and($desk)->toMatchArray(['overflow' => 0, 'inside' => true, 'small' => [], 'clipped' => []])
        ->and($phone)->toMatchArray(['overflow' => 0, 'inside' => true, 'small' => [], 'clipped' => []])
        // Four cards on a phone, six from sm.
        ->and($phone['cards'])->toHaveCount(4)
        ->and(max($desk['cards']))->toBeLessThanOrEqual(140);

    foreach ([$wide, $narrow] as $page) {
        expect($page->evaluate('() => window.__errors'))->toBe([])
            ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
    }
});

test('an EA FC page with nothing played says so in one line per part, no empty chart, in German at 375 too', function () {
    $german = User::factory()->create(['locale' => 'de']);

    $wide = gamePage('/games/ea-sports-fc-27', 1440, 900);
    gamePageControl($wide);
    $empty = $wide->evaluate('() => ({
        lines: ["game-matches-empty", "game-ladder-empty", "rl-hashrate-empty", "game-clans-empty"].map((t) => { const el = document.querySelector("[data-test=" + t + "]"); return el ? Math.round(el.getBoundingClientRect().height) : null; }),
        parts: ["game-matches", "game-ladder", "game-clans", "game-worth"].map((t) => Math.round(document.querySelector("[data-test=" + t + "]").getBoundingClientRect().height)),
        charts: document.querySelectorAll("[role=img]").length,
    })');
    $desk = gamePageGeometry($wide);
    gamePageShot($wide, 'game-fc27-empty-1440');

    $de = gamePage('/games/ea-sports-fc-27', 375, 812, $german);
    $phone = gamePageGeometry($de);
    gamePageShot($de, 'game-fc27-empty-de-375');

    expect($empty['lines'])->each->toBeLessThanOrEqual(90)
        // No part grows into a big empty box. P56: the clans sit in the 4-of-12 side column from lg, where their two
        // one-line empty states wrap once each (251 px measured at 1440).
        ->and(max($empty['parts']))->toBeLessThanOrEqual(260)
        ->and($empty['charts'])->toBe(0)
        ->and($desk)->toMatchArray(['overflow' => 0, 'inside' => true, 'small' => [], 'clipped' => []])
        ->and($phone)->toMatchArray(['overflow' => 0, 'inside' => true, 'small' => [], 'clipped' => []])
        ->and($de->evaluate('() => document.querySelector("#gm-h").innerText'))->toBe('Matches');

    foreach ([$wide, $de] as $page) {
        expect($page->evaluate('() => window.__errors'))->toBe([])
            ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
    }
});

/*
| P56: the landing's head. One primary action in the first viewport above the
| fixed chrome and not covered, the pulse counts as 44 px links, the hero
| before the invite before the matches, no line of text longer than 68
| characters of its own font, nothing wider than the window. A guest and a
| captain with an open challenge, English and German, 375 x 667 and 1440 x 900.
*/

/** The head's geometry; `wide` lists every text line over 68 ch (in its own font's "0"). */
const GAME_LANDING_PROBE = <<<'JS'
    () => {
        const root = document.querySelector('[data-test=game-page]');
        const floor = Math.round(Math.min(innerHeight, ...['[data-test=tab-bar]', '[data-test=dock-mobile-bar]']
            .map((s) => document.querySelector(s)).filter((el) => el && el.checkVisibility()).map((el) => el.getBoundingClientRect().top)));
        const cta = document.querySelector('[data-test=game-cta]');
        const r = cta.getBoundingClientRect();
        const hit = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
        const ctx = document.createElement('canvas').getContext('2d');
        const lines = [];
        const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
        for (let node; (node = walker.nextNode());) {
            const text = node.textContent.replace(/\s+/g, ' ').trim();
            const el = node.parentElement;
            if (text.length < 30 || !el.checkVisibility()) continue;
            ctx.font = getComputedStyle(el).font;
            const ch = ctx.measureText('0').width;
            const range = document.createRange();
            range.selectNodeContents(node);
            const widest = Math.max(0, ...[...range.getClientRects()].map((x) => x.width));
            lines.push([Math.round(widest / ch), text.slice(0, 40)]);
        }
        const heads = [...root.querySelectorAll('[data-test=game-hero] :is(a[href], button), [data-test=game-pulse] a')].filter((el) => el.checkVisibility());
        const top = (sel) => { const el = document.querySelector(sel); return el ? el.getBoundingClientRect().top + scrollY : null; };
        return {
            overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
            floor, ctaTop: Math.round(r.top), ctaBottom: Math.round(r.bottom), ctaHit: !!hit && (hit === cta || cta.contains(hit)),
            action: cta.dataset.action,
            order: [top('[data-test=game-cta]'), top('[data-test=invite-module]'), top('[data-test=game-matches]')],
            small: heads.filter((el) => el.getBoundingClientRect().height < 44).map((el) => (el.dataset.test || el.innerText.trim().slice(0, 20)) + ' ' + Math.round(el.getBoundingClientRect().height)),
            pulse: [...document.querySelectorAll('[data-test=game-pulse] a')].map((el) => el.dataset.test.replace('game-pulse-', '') + ':' + el.dataset.count),
            longest: Math.max(0, ...lines.map((l) => l[0])),
            wide: lines.filter((l) => l[0] > 68),
            pitch: document.querySelector('[data-test=game-pitch]').innerText,
        };
    }
    JS;

test('the landing leads with one action in the first viewport, pulse links of 44 px and lines of at most 68 ch, guest and captain, English and German', function () {
    gamePageSeed();
    $captain = SeriesMatch::query()->where('status', SeriesStatus::Open)->firstOrFail()->challengerLineup->clan->owner;
    $captain->update(['locale' => 'de']);
    $en = 'Play alone or with your clan. Every series moves your Elo.';
    $de = 'Spiel allein oder mit deinem Clan. Jede Serie bewegt dein Elo.';

    // Guests first: the browser keeps the login cookie across the helper's pages.
    $runs = [
        ['guest en 375', null, '/games/rocket-league?lang=en', 375, 667, 'login', $en],
        ['guest de 1440', null, '/games/rocket-league?lang=de', 1440, 900, 'login', $de],
        ['fc27 guest en 375', null, '/games/ea-sports-fc-27?lang=en', 375, 667, 'login', $en],
        ['captain de 375', $captain, '/games/rocket-league?lang=de', 375, 667, 'match', $de],
        ['captain en 1440', $captain, '/games/rocket-league?lang=en', 1440, 900, 'match', $en],
    ];

    foreach ($runs as $index => [$label, $user, $path, $width, $height, $action, $pitch]) {
        $page = gamePage($path, $width, $height, $user);

        if ($index === 0) {
            gamePageControl($page);
            // Positive control of the line probe: a 120 character line must be caught, then goes again.
            $page->evaluate('() => { const p = document.createElement("p"); p.id = "probe-line"; p.textContent = "x".repeat(120); document.querySelector("[data-test=game-matches]").append(p); }');
            expect(collect($page->evaluate(GAME_LANDING_PROBE)['wide'])->pluck(1)->all())->toContain(str_repeat('x', 40));
            $page->evaluate('() => document.getElementById("probe-line").remove()');
        }

        $probe = $page->evaluate(GAME_LANDING_PROBE);
        gamePageShot($page, 'landing-'.str_replace(' ', '-', $label));
        fwrite(STDERR, "\n[landing] {$label}: ".json_encode(array_diff_key($probe, ['wide' => 0]))."\n");

        expect($probe['overflow'])->toBe(0, $label)
            ->and($probe['action'])->toBe($action, $label)
            ->and($probe['pitch'])->toBe($pitch, $label)
            ->and($probe['ctaTop'])->toBeGreaterThanOrEqual(0, $label)
            ->and($probe['ctaBottom'])->toBeLessThanOrEqual($probe['floor'], $label)
            ->and($probe['ctaHit'])->toBeTrue($label)
            ->and($probe['order'][0])->toBeLessThan($probe['order'][2], $label)
            ->and($probe['small'])->toBe([], $label)
            ->and($probe['wide'])->toBe([], $label)
            ->and($page->evaluate('() => window.__errors'))->toBe([], $label)
            ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([], $label);

        // Below lg the invite follows the hero; from lg it heads the side column, level with the main one.
        if ($width < 1024) {
            expect($probe['order'][0])->toBeLessThan($probe['order'][1], $label)
                ->and($probe['order'][1])->toBeLessThan($probe['order'][2], $label);
        }

        expect($probe['pulse'])->toBe(str_starts_with($label, 'fc27')
            ? ['live:0', 'open:0', 'searching:0', 'clans:0', 'ranked:0']
            : ['live:1', 'open:1', 'searching:0', 'clans:5', 'ranked:5'], $label);
    }
});
