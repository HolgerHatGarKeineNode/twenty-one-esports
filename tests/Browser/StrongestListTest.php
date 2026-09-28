<?php

use App\Models\NostrEvent;
use App\Models\Rating;
use App\Models\RatingChange;
use App\Models\SeriesMatch;
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
| The strongest players across all games (P40)
|--------------------------------------------------------------------------
|
| /ladder/strongest, its home tile and the njump.me links of the ladder
| Proof, measured at 375 x 812 and 1440 x 900 in English and at 375 in
| German: no horizontal overflow, one row height, the game strip inside its
| cell, the Global column aligned with its header, readable names, 44 px
| link targets (24 px for the Proof's inline links), the home tile's five on
| one line from lg, the context bar still inside 1024 px with its new link,
| a clean console and no response >= 400. The positive control at the end
| throws and requests missing files on the same page and sees both.
|
| STRONGEST_SHOTS=<dir> additionally writes the screenshots there.
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

function p40Page(User $user, string $to, int $width, int $height, string $locale = 'en'): Page
{
    $page = visit(route('testing.login', ['user' => $user, 'to' => $to]))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize($width, $height);
    if ($locale !== 'en') {
        $page->goto(ComputeUrl::from(route('locale.switch', $locale, false)));
    }
    $page->goto(ComputeUrl::from($to));

    return $page;
}

function p40Shot(Page $page, string $name): void
{
    $dir = getenv('STRONGEST_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    File::ensureDirectoryExists($dir);
    $page->screenshot(true, $name);
    File::move(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
}

/**
 * A live season: nine blitz players (one below the minimum weight) and two
 * 2v2 lineups whose one series lists two of them, so two rows draw on two
 * games. Names include a long one and umlauts.
 */
function p40BrowserSeason(): User
{
    $season = openSeason(['slug' => 'season-1']);
    $names = ['satsjäger', 'lena.k', 'mempoolmax', 'kai_blitz', 'hodlqueen', 'rocketman21', 'nonce_nick', 'Bartholomäus von Blockzeit', 'newcomer'];
    $ratings = [1432, 1188, 1176, 1109, 1089, 1034, 1004, 912, 1050];
    $results = [14, 9, 11, 6, 8, 7, 5, 12, 3];
    $users = collect($names)->map(fn (string $name) => User::factory()->create(['name' => $name]));

    foreach ($users as $index => $user) {
        Rating::query()->create(['pool' => Rating::RATED, 'season' => 'season-1', 'game' => 'chess', 'mode' => 'blitz', 'subject' => 'user:'.$user->id,
            'user_id' => $user->id, 'rating' => $ratings[$index], 'results' => $results[$index], 'wins' => intdiv($results[$index], 2), 'draws' => 0, 'losses' => $results[$index] - intdiv($results[$index], 2)]);
    }

    $match = SeriesMatch::factory()->create(['mode' => '2v2', 'rated' => true, 'resolved_roster' => [
        ['user_id' => $users[1]->id, 'pubkey' => $users[1]->pubkey, 'name' => 'lena.k', 'side' => 'challenger', 'role' => 'player'],
        ['user_id' => $users[7]->id, 'pubkey' => $users[7]->pubkey, 'name' => 'Bartholomäus von Blockzeit', 'side' => 'challenged', 'role' => 'player'],
    ]]);
    foreach ([[$match->challenger_lineup_id, 1016], [$match->challenged_lineup_id, 984]] as [$lineupId, $rating]) {
        $row = Rating::query()->create(['pool' => Rating::RATED, 'season' => 'season-1', 'game' => 'rocket-league', 'mode' => '2v2', 'subject' => 'lineup:'.$lineupId,
            'lineup_id' => $lineupId, 'rating' => $rating, 'results' => 1, 'wins' => $rating > 1000 ? 1 : 0, 'losses' => $rating > 1000 ? 0 : 1]);
        RatingChange::query()->create(['rating_id' => $row->id, 'source' => RatingChange::SERIES, 'source_id' => $match->id, 'score' => $rating > 1000 ? 1 : 0,
            'before' => 1000, 'after' => $rating, 'delta' => $rating - 1000, 'results_before' => 0]);
    }

    NostrEvent::query()->create(['event_id' => str_repeat('c', 64), 'pubkey' => $season->league_pubkey, 'kind' => 32152, 'd' => 'chess/blitz/season-1',
        'signed_at' => now()->subMinutes(3)->getTimestamp(), 'raw' => '{}']);

    return $users[0];
}

/** Everything the list measurement reads, in one evaluate(). */
const P40_MEASURE = <<<'JS'
    () => {
        const visible = (el) => el.checkVisibility();
        const box = (el) => el.getBoundingClientRect();
        const rows = [...document.querySelectorAll('[data-test=strongest-row] > a')];
        const strips = [...document.querySelectorAll('[data-test=strongest-row] [data-test=game-strip]')].filter(visible);
        const cut = [...document.querySelectorAll('[data-test=strongest-name]')].filter(visible).filter((el) => el.scrollWidth > el.clientWidth).map((el) => el.clientWidth);
        const head = [...document.querySelectorAll('[data-test=strongest] [title^="Global"]')].filter(visible).map((el) => Math.round(box(el).right));
        const ratings = [...document.querySelectorAll('[data-test=strongest-rating]')].filter(visible);
        const links = [...document.querySelectorAll('[data-test=strongest-ladders] a')].filter(visible).map((el) => Math.round(box(el).height));
        return {
            lang: document.documentElement.lang,
            scroll: document.documentElement.scrollWidth, client: document.documentElement.clientWidth,
            rows: rows.length, rowHeights: [...new Set(rows.map((r) => Math.round(box(r).height)))],
            strips: strips.length,
            stripOutside: strips.filter((s) => box(s).right > box(s.closest('[data-test=strongest-row] > a')).right + 0.5 || box(s).width < 40).length,
            segments: [...document.querySelectorAll('[data-test=strongest-games] [data-game]')].filter(visible).map((el) => Math.round(box(el).width)),
            cutNames: cut.length, cutMin: cut.length ? Math.min(...cut) : null,
            headRight: head, ratingRights: [...new Set(ratings.map((el) => Math.round(box(el).right)))],
            ratings: ratings.map((el) => el.textContent.trim()),
            linkHeights: links,
        };
    }
    JS;

test('the strongest list, its home tile and the ladder Proof links hold at 375 and 1440, and in German', function () {
    $user = p40BrowserSeason();
    $failures = [];

    foreach ([['en', 375, 812], ['en', 1440, 900], ['de', 375, 812]] as [$locale, $width, $height]) {
        $page = p40Page($user, '/ladder/strongest', $width, $height, $locale);
        BrowserWait::until($page, '() => document.readyState === "complete" && document.querySelector("[data-test=strongest-row]") !== null', 10_000);

        $m = $page->evaluate(P40_MEASURE);
        fwrite(STDERR, "\n[p40] {$locale} {$width}x{$height} ".json_encode($m, JSON_UNESCAPED_UNICODE)."\n");
        p40Shot($page, "p40-strongest-{$locale}-{$width}");

        // Eight ranked (newcomer has 3 results); each row one strip; rows differ by at most 4 px.
        $ok = $m['lang'] === $locale && $m['scroll'] <= $m['client'] && $m['rows'] === 8 && max($m['rowHeights']) - min($m['rowHeights']) <= 4
            && $m['strips'] === 8 && $m['stripOutside'] === 0 && ($m['cutMin'] ?? 60) >= 60 && min($m['linkHeights']) >= 44;
        if ($width === 1440) {
            // The Global cells end where their header ends; lena.k and Bartholomäus show two segments each.
            $ok = $ok && count($m['ratingRights']) === 1 && abs($m['ratingRights'][0] - ($m['headRight'][0] ?? 0)) <= 1 && count($m['segments']) === 10 && min($m['segments']) >= 4;
        }

        // Home: the tile's five, on one line from lg, each a 44 px target.
        $page->goto(ComputeUrl::from('/'));
        BrowserWait::until($page, '() => document.readyState === "complete" && document.querySelector("[data-test=home-strongest]") !== null', 10_000);
        $page->evaluate('() => document.querySelector("[data-test=home-strongest]").scrollIntoView({block: "center"})');
        $home = $page->evaluate('() => { const rows = [...document.querySelectorAll("[data-test=home-strongest-row] a")]; return [document.documentElement.scrollWidth, document.documentElement.clientWidth, rows.length, [...new Set(rows.map((r) => Math.round(r.getBoundingClientRect().top)))].length, Math.min(...rows.map((r) => Math.round(r.getBoundingClientRect().height))), rows.map((r) => r.querySelector("[data-test=home-strongest-rating]").textContent.trim())]; }');
        fwrite(STDERR, "[p40] {$locale} {$width} home ".json_encode($home, JSON_UNESCAPED_UNICODE)."\n");
        p40Shot($page, "p40-home-{$locale}-{$width}");
        $ok = $ok && $home[0] <= $home[1] && $home[2] === 5 && $home[4] >= 44 && $home[5] === array_slice($m['ratings'], 0, 5)
            && ($width === 1440 ? $home[3] === 1 : $home[3] === 5);

        // The ladder Proof: two njump links, 24 px targets, a new tab, the strongest link under the table.
        $page->goto(ComputeUrl::from('/ladder/chess/blitz?pool=rated'));
        BrowserWait::until($page, '() => document.readyState === "complete" && document.querySelector("[data-test=ladder-proof]") !== null', 10_000);
        $page->locator('[data-test=ladder-proof] summary')->click();
        BrowserWait::until($page, '() => document.querySelector("[data-test=ladder-proof]").open === true', 5_000);
        $proof = $page->evaluate('() => { const links = [...document.querySelectorAll("[data-test=proof-link]")]; const own = document.querySelector("[data-test=ladder-strongest-link]").getBoundingClientRect(); return [document.documentElement.scrollWidth, document.documentElement.clientWidth, links.map((a) => [a.getAttribute("href").slice(0, 24), a.target, a.rel, Math.round(a.getBoundingClientRect().height), Math.round(a.getBoundingClientRect().right) <= Math.round(a.closest("details").getBoundingClientRect().right)]), Math.round(own.height)]; }');
        fwrite(STDERR, "[p40] {$locale} {$width} proof ".json_encode($proof)."\n");
        p40Shot($page, "p40-proof-{$locale}-{$width}");
        $ok = $ok && $proof[0] <= $proof[1] && count($proof[2]) === 2 && $proof[3] >= 44
            && str_starts_with($proof[2][0][0], 'https://njump.me/naddr1') && str_starts_with($proof[2][1][0], 'https://njump.me/npub1')
            && collect($proof[2])->every(fn (array $link): bool => $link[1] === '_blank' && $link[2] === 'noopener noreferrer' && $link[3] >= 24 && $link[4]);

        $errors = $page->evaluate('() => window.__errors');
        $bad = $page->evaluate(BrowserConsole::BAD_RESPONSES);
        if (! $ok || $errors !== [] || $bad !== []) {
            $failures[] = "{$locale} {$width}: ".json_encode(['m' => $m, 'home' => $home, 'proof' => $proof, 'errors' => $errors, 'bad' => $bad], JSON_UNESCAPED_UNICODE);
        }
    }

    expect($failures)->toBe([]);

    // The context bar keeps its new link inside the narrowest desktop width, in both languages.
    foreach (['en', 'de'] as $locale) {
        $page = p40Page($user, '/ladder/strongest', 1024, 768, $locale);
        BrowserWait::until($page, '() => document.readyState === "complete"', 10_000);
        $bar = $page->evaluate('() => { const bar = document.querySelector("[data-test=context-bar]"); const link = bar.querySelector("[data-test=ctx-strongest]").getBoundingClientRect(); return [bar.scrollWidth, bar.clientWidth, Math.round(link.right), Math.round(bar.getBoundingClientRect().right), document.documentElement.scrollWidth, document.documentElement.clientWidth]; }');
        fwrite(STDERR, "[p40] {$locale} 1024 context bar ".json_encode($bar)."\n");
        p40Shot($page, "p40-ctx-{$locale}-1024");
        expect($bar[0])->toBeLessThanOrEqual($bar[1])->and($bar[2])->toBeLessThanOrEqual($bar[3])->and($bar[4])->toBeLessThanOrEqual($bar[5]);
    }

    // Positive control: the same collectors see a thrown error, a failed fetch and a missing image on this page.
    $page = p40Page($user, '/ladder/strongest', 375, 812);
    BrowserWait::until($page, '() => document.readyState === "complete"', 10_000);
    $page->evaluate('() => { setTimeout(() => { throw new Error("p40 positive control"); }); fetch("/p40-missing-fetch"); const img = document.createElement("img"); img.src = "/p40-missing-image.png"; document.body.append(img); }');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("p40 positive control")) && window.__errors.some((e) => e.startsWith("404"))', 5_000);
    BrowserWait::until($page, '() => performance.getEntries().some((e) => e.name.endsWith("/p40-missing-image.png") && e.responseStatus === 404)', 5_000);
    expect(implode("\n", $page->evaluate('() => window.__errors')))->toContain('p40 positive control')->toContain('404 ')
        ->and(implode("\n", $page->evaluate(BrowserConsole::BAD_RESPONSES)))->toContain('404 ')->toContain('/p40-missing-image.png');
});

test('before Block 0 the page and the home tile are honest and empty at 375 and 1440', function () {
    $user = User::factory()->create(['name' => 'earlybird']);

    foreach ([375, 1440] as $width) {
        $page = p40Page($user, '/ladder/strongest', $width, 900);
        BrowserWait::until($page, '() => document.readyState === "complete" && document.querySelector("[data-test=strongest-empty]") !== null', 10_000);
        $empty = $page->evaluate('() => [document.documentElement.scrollWidth, document.documentElement.clientWidth, document.querySelector("[data-test=strongest-empty]").dataset.state, document.querySelectorAll("[data-test=strongest-row]").length, Math.min(...[...document.querySelectorAll("[data-test=strongest-empty] a")].map((a) => Math.round(a.getBoundingClientRect().height)))]');
        p40Shot($page, "p40-empty-en-{$width}");

        $page->goto(ComputeUrl::from('/'));
        BrowserWait::until($page, '() => document.readyState === "complete" && document.querySelector("[data-test=home-strongest-empty]") !== null', 10_000);
        $page->evaluate('() => document.querySelector("[data-test=home-strongest]").scrollIntoView({block: "center"})');
        $home = $page->evaluate('() => [document.documentElement.scrollWidth, document.documentElement.clientWidth, document.querySelectorAll("[data-test=home-strongest-row]").length]');
        p40Shot($page, "p40-home-empty-en-{$width}");
        fwrite(STDERR, "\n[p40] empty {$width} ".json_encode([$empty, $home])."\n");

        expect($empty[0])->toBeLessThanOrEqual($empty[1])->and($empty[2])->toBe('pre-launch')->and($empty[3])->toBe(0)->and($empty[4])->toBeGreaterThanOrEqual(44)
            ->and($home[0])->toBeLessThanOrEqual($home[1])->and($home[2])->toBe(0)
            ->and($page->evaluate('() => window.__errors'))->toBe([])
            ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
    }
});
