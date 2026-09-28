<?php

use App\Enums\ClanRole;
use App\Models\Clan;
use App\Models\ClanMember;
use App\Models\Lineup;
use App\Models\NostrEvent;
use App\Models\Rating;
use App\Models\RatingChange;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserWait;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| The ladder opens on the view that has rows
|--------------------------------------------------------------------------
|
| Before Block 0 the plain ladder URL shows the casual ladder with a slim
| note that links to the rated ladder. Measured at 375 x 667 (the Rated /
| Casual switch must sit above the fold) and 1440 x 900: no horizontal
| overflow, a clean console and no response >= 400, on load and after the
| Livewire roundtrip of the switch. The collector is ClanEditTest's
| (BrowserConsole); its positive control is in ClanLogoTest.
|
| LADDER_SHOTS=<dir> additionally writes the English screenshots there.
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
});

function ladderPage(User $user, string $to, int $width, int $height): Page
{
    $page = visit(route('testing.login', ['user' => $user, 'to' => $to]))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize($width, $height);
    $page->goto(ComputeUrl::from($to));

    return $page;
}

function ladderShot(Page $page, string $name): void
{
    $dir = getenv('LADDER_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    File::ensureDirectoryExists($dir);
    $page->screenshot(true, $name);
    File::move(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
}

test('before Block 0 the ladder opens on casual, notes the rated ladder, and keeps the switch above the fold', function () {
    $players = User::factory()->count(3)->create();
    foreach ($players as $index => $player) {
        Rating::query()->create(['pool' => Rating::CASUAL, 'season' => '', 'game' => 'chess', 'mode' => 'blitz', 'subject' => 'user:'.$player->id,
            'user_id' => $player->id, 'rating' => 1060 - $index * 25, 'results' => 4, 'wins' => 2, 'draws' => 0, 'losses' => 2]);
    }

    foreach ([[375, 667], [1440, 900]] as [$width, $height]) {
        $page = ladderPage($players[0], '/ladder/chess/blitz', $width, $height);
        BrowserWait::until($page, '() => document.readyState === "complete" && document.querySelector("[data-test=ladder-rated-note]") !== null', 10_000);

        $switch = $page->evaluate('() => { const r = document.querySelector("[data-test=pool-rated]").parentElement.getBoundingClientRect(); return [Math.round(r.top), Math.round(r.bottom), Math.round(r.left), Math.round(r.right)]; }');
        $note = $page->evaluate('() => { const r = document.querySelector("[data-test=ladder-rated-note]").getBoundingClientRect(); return [Math.round(r.top), Math.round(r.height)]; }');
        $link = $page->evaluate('() => { const r = document.querySelector("[data-test=ladder-rated-link]").getBoundingClientRect(); return [Math.round(r.width), Math.round(r.height)]; }');
        $widths = $page->evaluate(BrowserConsole::WIDTHS);
        fwrite(STDERR, "\n[ladder] {$width}x{$height} switch top/bottom/left/right ".json_encode($switch).' note top/height '.json_encode($note).' link w/h '.json_encode($link).' scroll/client '.json_encode($widths)."\n");

        expect($page->evaluate('() => document.querySelector("[data-test=pool-casual]").getAttribute("aria-pressed")'))->toBe('true')
            ->and($page->evaluate('() => document.querySelectorAll("[data-test=ladder-row]").length'))->toBe(3)
            ->and($switch[1])->toBeLessThanOrEqual($height)
            ->and($switch[3])->toBeLessThanOrEqual($width)
            ->and($link[1])->toBeGreaterThanOrEqual(44)
            ->and($widths[0])->toBeLessThanOrEqual($widths[1]);
        ladderShot($page, "ladder-casual-default-{$width}");

        // The switch's roundtrip: rated shows the Pre-Season card.
        $page->locator('[data-test=pool-rated]')->click();
        BrowserWait::until($page, '() => document.querySelector("[data-test=ladder-preseason]") !== null', 10_000);
        expect($page->evaluate(BrowserConsole::WIDTHS)[0])->toBeLessThanOrEqual($width);
        ladderShot($page, "ladder-rated-preseason-{$width}");

        expect($page->evaluate('() => window.__errors'))->toBe([])
            ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
    }
});

/*
|--------------------------------------------------------------------------
| The full rated ladder (P32)
|--------------------------------------------------------------------------
|
| A live season with eight blitz players in two clans: the season chips,
| the overview with the share of wins, the tier lines, the form strips,
| Block Height and Global Rating, the clan view (a Livewire roundtrip) and
| the opened Proof. Measured at 375 x 812 and 1440 x 900 in English and at
| 375 in German (the longer labels): no horizontal overflow, no clipped
| stat, a readable name, the form strip inside its row, the Global column
| aligned with its header at 1440, the proof link >= 44 px, a clean console
| and no response >= 400. The positive control at the end throws and
| requests a missing file in the same page and sees both.
|
*/

function p32BrowserLadder(): User
{
    $season = openSeason(['slug' => 'season-1']);
    $names = ['satsjäger', 'lena.k', 'mempoolmax', 'kai_blitz', 'hodlqueen', 'rocketman21', 'nonce_nick', 'Bartholomäus von Blockzeit'];
    $ratings = [1432, 1188, 1176, 1109, 1089, 1034, 1004, 912];
    $users = collect($names)->map(fn (string $name) => User::factory()->create(['name' => $name]));
    $laser = Clan::factory()->create(['name' => 'Laser Eyes Allgäu', 'clantag' => 'LSR', 'owner_id' => $users[0]->id]);
    $hodl = Clan::factory()->create(['name' => 'HODL Rockets Kempten', 'clantag' => 'HDL', 'owner_id' => $users[1]->id]);

    foreach ($users as $index => $user) {
        if ($index > 1 && $index < 7) {
            ClanMember::query()->firstOrCreate(['user_id' => $user->id], ['clan_id' => ($index % 2 ? $hodl : $laser)->id, 'role' => ClanRole::Member, 'joined_at' => now()]);
        }

        $results = $index === 6 ? 3 : 6 + $index;
        $row = Rating::query()->create(['pool' => Rating::RATED, 'season' => 'season-1', 'game' => 'chess', 'mode' => 'blitz', 'subject' => 'user:'.$user->id,
            'user_id' => $user->id, 'rating' => $ratings[$index], 'results' => $results, 'wins' => max(0, $results - 2 - $index % 3), 'draws' => $index % 2, 'losses' => 2 + $index % 3 - $index % 2]);

        foreach (array_slice([1.0, 0.0, 0.5, 1.0, 1.0, 0.0], 0, min(5, $results)) as $step => $score) {
            RatingChange::query()->create(['rating_id' => $row->id, 'source' => RatingChange::CHESS, 'source_id' => 5000 + $index * 10 + $step,
                'match_number' => 400 + $index * 5 + $step, 'score' => $score, 'before' => $row->rating, 'after' => $row->rating, 'delta' => 0, 'results_before' => $step]);
        }
    }

    NostrEvent::query()->create(['event_id' => str_repeat('b', 64), 'pubkey' => $season->league_pubkey, 'kind' => 32152, 'd' => 'chess/blitz/season-1',
        'signed_at' => now()->subMinutes(3)->getTimestamp(), 'raw' => '{}']);

    return $users[0];
}

/** Everything the P32 measurement reads, in one evaluate(). */
const P32_MEASURE = <<<'JS'
    () => {
        const visible = (el) => el.checkVisibility();
        const box = (el) => el.getBoundingClientRect();
        const rows = [...document.querySelectorAll('[data-test=ladder-row]')];
        const forms = [...document.querySelectorAll('[data-test=ladder-row] [role=img]')].filter(visible);
        // A truncated name still shows at least eight characters (about 60 px of the house mono at 13 px).
        const cut = [...document.querySelectorAll('[data-test=ladder-name]')].filter(visible).filter((el) => el.scrollWidth > el.clientWidth).map((el) => el.clientWidth);
        const stats = [...document.querySelectorAll('[data-test^=ladder-stat-]')].map((el) => [el.dataset.test, el.scrollWidth, el.clientWidth, el.textContent.trim()]);
        const header = [...document.querySelectorAll('[data-test=ladder-global-head]')].filter(visible).map((el) => Math.round(box(el).right));
        const globals = [...document.querySelectorAll('[data-test=ladder-global]')].filter(visible).map((el) => Math.round(box(el).right));
        return {
            lang: document.documentElement.lang,
            scroll: document.documentElement.scrollWidth, client: document.documentElement.clientWidth,
            rows: rows.length, rowHeights: [...new Set(rows.map((r) => Math.round(box(r).height)))],
            lines: [...document.querySelectorAll('[data-test=tier-line]')].map((el) => el.textContent.trim().replace(/\s+/g, ' ')),
            forms: forms.length, formOutside: forms.filter((f) => box(f).right > box(f.closest('[data-test=ladder-row]')).right + 0.5).length,
            cutNames: cut.length, cutMin: cut.length ? Math.min(...cut) : null,
            stats, statClipped: stats.filter((s) => s[1] > s[2]).map((s) => s[0]),
            shares: [...document.querySelectorAll('[data-test=ladder-share-bar]')].map((el) => Math.round(box(el).width)),
            headerRight: header, globalRights: [...new Set(globals)],
        };
    }
    JS;

test('the rated ladder shows season, overview, tier lines, form, global score, clan view and proof at 375 and 1440, and in German', function () {
    $user = p32BrowserLadder();
    $failures = [];

    foreach ([['en', 375, 812], ['en', 1440, 900], ['de', 375, 812]] as [$locale, $width, $height]) {
        $page = ladderPage($user, '/ladder/chess/blitz', $width, $height);
        if ($locale === 'de') {
            $page->goto(ComputeUrl::from(route('locale.switch', 'de', false)));
            $page->goto(ComputeUrl::from('/ladder/chess/blitz'));
        }
        BrowserWait::until($page, '() => document.readyState === "complete" && document.querySelector("[data-test=ladder-overview]") !== null', 10_000);

        $m = $page->evaluate(P32_MEASURE);
        fwrite(STDERR, "\n[p32] {$locale} {$width}x{$height} ".json_encode($m, JSON_UNESCAPED_UNICODE)."\n");
        ladderShot($page, "p32-rated-{$locale}-{$width}");

        // Seven tiers hold the eight ratings (1188 and 1176 share Diamond I); rows differ by at most the clan chip (24 px) against a bare avatar (22 px).
        $ok = $m['lang'] === $locale && $m['scroll'] <= $m['client'] && $m['rows'] === 8 && count($m['lines']) === 7 && max($m['rowHeights']) - min($m['rowHeights']) <= 4
            && $m['forms'] === 8 && $m['formOutside'] === 0 && ($m['cutMin'] ?? 60) >= 60 && $m['statClipped'] === [] && count($m['shares']) === 5 && min($m['shares']) > 0;
        if ($width === 1440) {
            // The Global column's cells end where its header ends.
            $ok = $ok && count($m['globalRights']) === 1 && abs($m['globalRights'][0] - ($m['headerRight'][0] ?? 0)) <= 1;
        }

        // The proof opens and its link is a 44 px target.
        $page->locator('[data-test=ladder-proof] summary')->click();
        BrowserWait::until($page, '() => document.querySelector("[data-test=ladder-proof]").open === true', 5_000);
        $link = $page->evaluate('() => { const r = document.querySelector("[data-test=ladder-proof-link]").getBoundingClientRect(); return [Math.round(r.width), Math.round(r.height)]; }');
        $ok = $ok && $link[1] >= 44;
        ladderShot($page, "p32-proof-{$locale}-{$width}");

        // The clan view: a Livewire roundtrip, two clans, still no overflow.
        $page->locator('[data-test=view-clans]')->click();
        BrowserWait::until($page, '() => document.querySelectorAll("[data-test=ladder-clan]").length === 2', 10_000);
        // One line per clan: every cell of a clan row sits in the same grid row (the row stays <= 60 px).
        $clans = $page->evaluate('() => [document.documentElement.scrollWidth, document.documentElement.clientWidth, document.querySelector("[data-test=view-clans]").getAttribute("aria-pressed"), Math.max(...[...document.querySelectorAll("[data-test=ladder-clan]")].map((r) => Math.round(r.getBoundingClientRect().height)))]');
        fwrite(STDERR, "[p32] {$locale} {$width} proof link ".json_encode($link).' clans '.json_encode($clans)."\n");
        $ok = $ok && $clans[0] <= $clans[1] && $clans[2] === 'true' && $clans[3] <= 60;
        ladderShot($page, "p32-clans-{$locale}-{$width}");

        $errors = $page->evaluate('() => window.__errors');
        $bad = $page->evaluate(BrowserConsole::BAD_RESPONSES);
        if (! $ok || $errors !== [] || $bad !== []) {
            $failures[] = "{$locale} {$width}: ".json_encode(['m' => $m, 'link' => $link, 'clans' => $clans, 'errors' => $errors, 'bad' => $bad], JSON_UNESCAPED_UNICODE);
        }

        if ($locale === 'de') {
            $page->goto(ComputeUrl::from(route('locale.switch', 'en', false)));
        }
    }

    expect($failures)->toBe([]);

    // A lineup ladder (Rocket League 3v3): clan names instead of players, no Block Height or Global column.
    $lineups = collect([['Laser Eyes Allgäu Rocket Squad', 1160], ['Mempool Maniacs', 1040], ['Nonce Hunters', 955]])->map(function (array $spec, int $index) {
        $lineup = Lineup::factory()->mode('3v3')->create();
        $lineup->clan->forceFill(['name' => $spec[0]])->save();
        $row = Rating::query()->create(['pool' => Rating::RATED, 'season' => 'season-1', 'game' => 'rocket-league', 'mode' => '3v3', 'subject' => 'lineup:'.$lineup->id,
            'lineup_id' => $lineup->id, 'rating' => $spec[1], 'results' => 6, 'wins' => 4 - $index, 'losses' => 2 + $index]);
        foreach ([1.0, 0.0, 1.0] as $step => $score) {
            RatingChange::query()->create(['rating_id' => $row->id, 'source' => RatingChange::SERIES, 'source_id' => 7000 + $index * 10 + $step, 'match_number' => 600 + $step,
                'score' => $score, 'before' => $row->rating, 'after' => $row->rating, 'delta' => 0, 'results_before' => $step]);
        }

        return $lineup;
    });
    expect($lineups)->toHaveCount(3);

    foreach ([375, 1440] as $width) {
        $page = ladderPage($user, '/ladder/rocket-league/3v3', $width, 900);
        BrowserWait::until($page, '() => document.readyState === "complete" && document.querySelector("[data-test=ladder-overview]") !== null', 10_000);
        $m = $page->evaluate(P32_MEASURE);
        fwrite(STDERR, "\n[p32] rl3v3 {$width} ".json_encode($m, JSON_UNESCAPED_UNICODE)."\n");
        ladderShot($page, "p32-rl-rated-en-{$width}");
        $page->locator('[data-test=view-clans]')->click();
        BrowserWait::until($page, '() => document.querySelectorAll("[data-test=ladder-clan]").length >= 3', 10_000);
        $clanHeight = $page->evaluate('() => Math.max(...[...document.querySelectorAll("[data-test=ladder-clan]")].map((r) => Math.round(r.getBoundingClientRect().height)))');
        ladderShot($page, "p32-rl-clans-en-{$width}");
        expect($m['scroll'])->toBeLessThanOrEqual($m['client'])
            ->and($m['rows'])->toBe(3)->and($m['forms'])->toBe(3)->and($m['formOutside'])->toBe(0)->and($m['globalRights'])->toBe([])
            ->and($clanHeight)->toBeLessThanOrEqual(60)
            ->and($page->evaluate(BrowserConsole::WIDTHS)[0])->toBeLessThanOrEqual($width)
            ->and($page->evaluate('() => window.__errors'))->toBe([])
            ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
    }

    // Positive control: the same collectors see a thrown error and a missing file on this page.
    $page = ladderPage($user, '/ladder/chess/blitz', 375, 812);
    BrowserWait::until($page, '() => document.readyState === "complete"', 10_000);
    $page->evaluate('() => { setTimeout(() => { throw new Error("p32 positive control"); }); fetch("/p32-missing-fetch"); const img = document.createElement("img"); img.src = "/p32-missing-image.png"; document.body.append(img); }');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("p32 positive control")) && window.__errors.some((e) => e.startsWith("404"))', 5_000);
    BrowserWait::until($page, '() => performance.getEntries().some((e) => e.name.endsWith("/p32-missing-image.png") && e.responseStatus === 404)', 5_000);
    expect(implode("\n", $page->evaluate('() => window.__errors')))->toContain('p32 positive control')->toContain('404 ')
        ->and(implode("\n", $page->evaluate(BrowserConsole::BAD_RESPONSES)))->toContain('404 ')->toContain('/p32-missing-image.png');
});
