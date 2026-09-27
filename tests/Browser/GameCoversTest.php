<?php

use App\Models\ChessGame;
use App\Models\Lineup;
use App\Models\SeriesMatch;
use App\Models\User;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use Illuminate\Support\Facades\File;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;
use Tests\Support\TestSigner;

/*
|--------------------------------------------------------------------------
| Game covers (EA Sports FC 26/27, Rocket League, chess)
|--------------------------------------------------------------------------
|
| On /tournaments, the games menu, the challenge form and the match list,
| at 375 and 1440 px: every cover the page shows has loaded a local image,
| keeps 16:9, and the page does not scroll sideways. The console collector
| (BrowserConsole) must stay empty on every page, and proves it can see
| something with a positive control: a thrown error and a broken cover.
|
| GAME_COVER_SHOTS=<dir> additionally writes the English screenshots there.
|
*/

/** Every visible cover, scrolled into view, with its box, file and load state. */
const GAME_COVERS_SCRIPT = <<<'JS'
    async (scope) => {
        const root = scope ? document.querySelector(scope) : document;
        const pictures = [...root.querySelectorAll('picture[data-game-cover]')].filter((p) => p.checkVisibility({ checkVisibilityCSS: true }));
        const out = [];
        for (const picture of pictures) {
            const img = picture.querySelector('img');
            img.scrollIntoView({ block: 'center' });
            if (!img.complete || img.naturalWidth === 0) {
                await new Promise((resolve) => { img.addEventListener('load', resolve, { once: true }); img.addEventListener('error', resolve, { once: true }); setTimeout(resolve, 3000); });
            }
            // The layout box, not getBoundingClientRect(): the landing poster is tilted in 3D and animated in.
            out.push({ game: picture.dataset.gameCover, width: picture.offsetWidth, height: picture.offsetHeight, loaded: img.complete && img.naturalWidth > 0, src: img.currentSrc });
        }
        return out;
    }
    JS;

function gameCoverPage(User $user, string $to, int $width): Page
{
    $page = visit(BrowserLogin::url($user))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize($width, $width === 375 ? 667 : 900);
    $page->goto(ComputeUrl::from($to));
    BrowserWait::until($page, '() => document.readyState === "complete"', 10_000);

    return $page;
}

function gameCoverShot(Page $page, string $name): void
{
    $dir = getenv('GAME_COVER_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    File::ensureDirectoryExists($dir);
    $page->screenshot(true, $name);
    File::move(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
}

/**
 * Covers of the page (or of `$scope`) that must be there, loaded and 16:9.
 *
 * @param  list<string>  $games
 */
function assertGameCovers(Page $page, array $games, ?string $scope = null): void
{
    $covers = $page->evaluate(GAME_COVERS_SCRIPT, $scope);

    expect(array_values(array_unique(array_column($covers, 'game'))))->toContain(...$games);

    foreach ($covers as $cover) {
        expect($cover['loaded'])->toBeTrue("cover {$cover['game']} did not load ({$cover['src']})")
            ->and($cover['src'])->toContain('/images/games/'.$cover['game'].'-')
            ->and($cover['width'])->toBeGreaterThan(20)
            ->and(abs($cover['height'] / $cover['width'] - 9 / 16))->toBeLessThan(0.02);
    }
}

function assertCleanPage(Page $page, string $where): void
{
    [$scroll, $client] = $page->evaluate(BrowserConsole::WIDTHS);

    expect($scroll)->toBeLessThanOrEqual($client, "{$where}: the page scrolls sideways ({$scroll} > {$client})")
        ->and($page->evaluate('() => window.__errors'))->toBe([], "{$where}: console")
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([], "{$where}: responses");
}

test('covers show on /tournaments, the games menu, the challenge form and the match list at 375 and 1440 px, with a clean console', function () {
    $captain = ($mine = Lineup::factory()->game('ea-sports-fc-27', '1v1')->ready()->create())->clan->owner;
    $theirs = Lineup::factory()->game('ea-sports-fc-27', '1v1')->ready()->create();
    SeriesMatch::factory()->accepted()->create(['challenger_lineup_id' => $mine->id, 'challenged_lineup_id' => $theirs->id]);
    SeriesMatch::factory()->create(['challenger_lineup_id' => Lineup::factory()->game('ea-sports-fc-26', '2v2')->ready(), 'challenged_lineup_id' => Lineup::factory()->game('ea-sports-fc-26', '2v2')->ready()]);
    SeriesMatch::factory()->create();
    ChessGame::factory()->create();
    config(['esports.league.nsec' => (new TestSigner)->secret]);
    $cup = openTournament(['name' => 'Kick-off Cup', 'game' => 'ea-sports-fc-27', 'mode' => '1v1', 'options' => FormatOptions::defaults(GameProfile::for('ea-sports-fc-27', '1v1'))->toArray()]);
    openTournament(['name' => 'RL Sunday'], rocketLeague: true);

    foreach ([375, 1440] as $width) {
        $page = gameCoverPage($captain, route('tournaments.index', absolute: false), $width);
        // The page's own covers; the header's game chips (below lg, lazy, scrolled sideways) are checked with the games menu below.
        assertGameCovers($page, ['ea-sports-fc-27', 'rocket-league'], 'main');
        gameCoverShot($page, "covers-tournaments-{$width}");
        assertCleanPage($page, "/tournaments at {$width}");

        $landing = gameCoverPage($captain, route('tournaments.show', $cup, false), $width);
        assertGameCovers($landing, ['ea-sports-fc-27'], '[data-test=game-cover]');
        gameCoverShot($landing, "covers-tournament-landing-{$width}");
        assertCleanPage($landing, "tournament landing at {$width}");

        // The game hub ("All N games") from lg; below lg the game chips under the top bar, then the hub as a sheet.
        if ($width === 1440) {
            $page->locator('[data-test=games-menu]')->click();
            BrowserWait::until($page, '() => document.querySelector("[data-test=games-menu-ea-sports-fc-26]")?.checkVisibility()', 5_000);
            assertGameCovers($page, ['chess', 'rocket-league', 'ea-sports-fc-27', 'ea-sports-fc-26'], '#game-hub');
        } else {
            BrowserWait::until($page, '() => document.querySelector("[data-test=mobile-ea-sports-fc-26]")?.checkVisibility()', 5_000);
            assertGameCovers($page, ['chess', 'rocket-league', 'ea-sports-fc-27', 'ea-sports-fc-26'], '#game-chips');
            $page->locator('[data-test=mobile-games-menu]')->click();
            BrowserWait::until($page, '() => document.querySelector("[data-test=games-menu-ea-sports-fc-26]")?.checkVisibility()', 5_000);
            assertGameCovers($page, ['chess', 'rocket-league', 'ea-sports-fc-27', 'ea-sports-fc-26'], '#game-hub');
        }
        gameCoverShot($page, "covers-games-menu-{$width}");
        assertCleanPage($page, "games menu at {$width}");

        $page = gameCoverPage($captain, route('challenges.create', ['game' => 'ea-sports-fc-27'], false), $width);
        // Below lg the form is a stepper: step 1 shows the lineup picker with the covers.
        assertGameCovers($page, ['ea-sports-fc-27'], 'main');
        gameCoverShot($page, "covers-challenge-create-{$width}");
        assertCleanPage($page, "challenge create at {$width}");

        $page = gameCoverPage($captain, route('matches.index', absolute: false), $width);
        assertGameCovers($page, ['ea-sports-fc-27', 'ea-sports-fc-26', 'rocket-league', 'chess'], '[data-test=matches]');
        gameCoverShot($page, "covers-matches-{$width}");

        // The game filter: a select on a phone, every button fully inside its group from sm.
        if ($width === 375) {
            expect($page->evaluate('() => [document.querySelector("[data-test=game-filter-select]").checkVisibility(), document.querySelector("[data-test=game-ea-sports-fc-26]").checkVisibility()]'))->toBe([true, false]);
            $page->locator('[data-test=game-filter-select]')->selectOption('ea-sports-fc-26');
            BrowserWait::until($page, '() => [...document.querySelectorAll("[data-test=matches] [data-test=match-row] [data-game-cover]")].every((p) => p.dataset.gameCover === "ea-sports-fc-26") && !document.querySelector("[data-test=chess-row]")', 10_000);
        } else {
            expect($page->evaluate('() => { const g = document.querySelector("[aria-labelledby=f-game][role=group]"); return g.scrollWidth <= g.clientWidth; }'))->toBeTrue();
        }
        assertCleanPage($page, "/matches at {$width}");

        $page = gameCoverPage($captain, route('games.series', 'ea-sports-fc-27', false), $width);
        assertGameCovers($page, ['ea-sports-fc-27'], 'main');
        gameCoverShot($page, "covers-game-page-{$width}");
        assertCleanPage($page, "FC 27 page at {$width}");
    }

    // At sm (640 px), the narrowest width with the buttons, all five fit their group.
    $page = gameCoverPage($captain, route('matches.index', absolute: false), 640);
    expect($page->evaluate('() => { const g = document.querySelector("[aria-labelledby=f-game][role=group]"); return [g.checkVisibility(), g.scrollWidth <= g.clientWidth]; }'))->toBe([true, true]);
    assertCleanPage($page, '/matches at 640');
    // Positive control of the fit check: a button too wide for the group makes it false.
    expect($page->evaluate('() => { const g = document.querySelector("[aria-labelledby=f-game][role=group]"); g.insertAdjacentHTML("beforeend", \'<button style="flex:none;width:2000px">x</button>\'); return g.scrollWidth <= g.clientWidth; }'))->toBeFalse();

    // Positive control: the same collector and cover check see a thrown error and a broken cover.
    $page = gameCoverPage($captain, route('matches.index', absolute: false), 1440);
    $page->evaluate('() => { setTimeout(() => { throw new Error("cover positive control"); }); const picture = document.querySelector("[data-test=matches] picture[data-game-cover]"); picture.scrollIntoView(); picture.querySelector("source").remove(); const img = picture.querySelector("img"); img.removeAttribute("srcset"); img.loading = "eager"; img.src = "/images/games/missing-480.jpg"; }');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("cover positive control")) && window.__errors.some((e) => e.includes("missing-480.jpg"))', 5_000);
    $broken = array_values(array_filter($page->evaluate(GAME_COVERS_SCRIPT, null), fn (array $cover): bool => ! $cover['loaded']));

    expect($broken)->toHaveCount(1)
        ->and($broken[0]['src'])->toContain('missing-480.jpg')
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->not->toBe([]);
});
