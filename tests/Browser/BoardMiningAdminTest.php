<?php

use App\Games\NineMensMorris;
use App\Models\BoardGame;
use App\Models\SeasonParameterChange;
use App\Models\User;
use App\Support\Nostr\NostrKeys;
use App\Support\SeasonChain\ChainDraft;
use App\Support\SeasonChain\Opponents;
use App\Support\SeasonChain\TrustFacts;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;
use Tests\Support\CheckersGame;
use Tests\Support\NineMensMorrisOn;
use Tests\Support\TestSigner;
use Tests\Support\TrustedFacts;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| The board games on the admin season page (plan "Mühle und Dame", P6)
|--------------------------------------------------------------------------
|
| Every board game knob (a weight per game, the share and daily limit of
| their group) is in the chain draft for Block 0: the board fills in the
| proposal and saves it through a Livewire round-trip, and the log shows the
| shrunk shares. In a live season the rule change adds them. Measured at 390
| and 1440 px, in English and German; console, uncaught errors and every
| answer >= 400 are collected (BrowserConsole) and stay empty, with a
| positive control on the same page. And two players who list each other
| find a rated game from a board game's lobby while its rated queue is on.
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

    NineMensMorrisOn::play();
    CheckersGame::play();
});

function boardMiningAdminPage(User $user, int $width, int $height, string $locale): Page
{
    $page = visit(BrowserLogin::url($user))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize($width, $height);
    $page->goto(ComputeUrl::from(route('locale.switch', $locale, false)));
    $page->goto(ComputeUrl::from(route('admin.season', [], false)));
    BrowserWait::until($page, '() => document.querySelector("[data-test=admin-season]") !== null', 10_000);

    return $page;
}

/**
 * Language, sideways overflow, the collector, and the boxes of the given
 * `data-test` elements (left, right, width, height), null when missing.
 *
 * @param  list<string>  $boxes
 * @return array{lang: string, scroll: int, client: int, errors: list<string>, bad: list<string>, boxes: array<string, list<int>|null>}
 */
function boardMiningAdminMeasure(Page $page, array $boxes): array
{
    [$scroll, $client] = $page->evaluate(BrowserConsole::WIDTHS);

    return [
        'lang' => (string) $page->evaluate('() => document.documentElement.lang'),
        'scroll' => (int) $scroll,
        'client' => (int) $client,
        'errors' => (array) $page->evaluate('() => window.__errors'),
        'bad' => (array) $page->evaluate(BrowserConsole::BAD_RESPONSES),
        'boxes' => (array) $page->evaluate('() => Object.fromEntries('.json_encode($boxes).'.map((name) => { const e = document.querySelector(`[data-test=${name}]`); if (! e) return [name, null]; const r = e.getBoundingClientRect(); return [name, [Math.round(r.left), Math.round(r.right), Math.round(r.width), Math.round(r.height)]]; }))'),
    ];
}

test('the board fills in the board games\' proposal on the chain draft and saves it; the page stays clean and inside the viewport', function (int $width, int $height, string $locale) {
    $board = User::factory()->create(['name' => 'vorstand', 'timezone' => 'UTC']);
    config(['esports.board' => [NostrKeys::hexToNpub($board->pubkey)]]);

    $page = boardMiningAdminPage($board, $width, $height, $locale);
    $before = boardMiningAdminMeasure($page, ['draft-row-board-games', 'draft-share-board-games', 'draft-daily-board-games', 'draft-weight-nine-mens-morris-blitz', 'draft-weight-checkers-blitz', 'board-games-proposal', 'fill-board-games-proposal', 'save-draft']);
    $label = (string) $page->evaluate('() => document.querySelector("[data-test=draft-row-board-games] th").innerText.trim()');
    shellShot($page, "board-mining-draft-{$locale}-{$width}");

    $page->locator('[data-test=fill-board-games-proposal]')->click();
    BrowserWait::until($page, '() => document.querySelector("[data-test=draft-share-board-games]").value === "10"', 10_000);
    $filled = $page->evaluate('() => ["chess", "rocket-league", "ea-sports-fc", "board-games"].map((key) => document.querySelector(`[data-test=draft-share-${key}]`).value)');
    $page->locator('[data-test=save-draft]')->click();
    BrowserWait::until($page, '() => document.querySelector("[data-test=settings-log]")?.innerText.includes("35 → 32")', 10_000);
    $after = boardMiningAdminMeasure($page, ['draft-row-board-games', 'rated-board-not-open', 'save-draft']);
    $proposalGone = (bool) $page->evaluate('() => document.querySelector("[data-test=board-games-proposal]") === null');
    shellShot($page, "board-mining-draft-saved-{$locale}-{$width}");

    fwrite(STDERR, "board mining admin {$locale} {$width}x{$height}: ".json_encode(compact('before', 'after', 'label', 'filled')).PHP_EOL);

    expect($label)->toBe($locale === 'de' ? 'Brettspiele' : 'Board games')
        ->and($filled)->toBe(['32', '36', '22', '10'])
        ->and($proposalGone)->toBeTrue()
        ->and(ChainDraft::stored()['shares'])->toBe(['chess' => 32, 'rocket-league' => 36, 'ea-sports-fc' => 22, 'board-games' => 10])
        ->and(ChainDraft::stored()['weights'])->toMatchArray(['chess/blitz' => 1000, 'chess/correspondence' => 2000, 'nine-mens-morris/blitz' => 1000, 'checkers/blitz' => 1000]);

    foreach (['before' => $before, 'after' => $after] as $where => $m) {
        expect($m['lang'])->toBe($locale, $where)
            ->and($m['scroll'])->toBeLessThanOrEqual($m['client'], $where)
            ->and($m['errors'])->toBe([], $where)
            ->and($m['bad'])->toBe([], $where);

        foreach ($m['boxes'] as $name => $box) {
            expect($box)->not->toBeNull("{$where}: {$name}")
                ->and($box[0])->toBeGreaterThanOrEqual(0, "{$where}: {$name}")
                ->and($box[3])->toBeGreaterThan(0, "{$where}: {$name}");

            // The draft table scrolls inside its own box on a phone; the rest stays inside the viewport.
            if (! str_starts_with($name, 'draft-share') && ! str_starts_with($name, 'draft-daily') && ! str_starts_with($name, 'draft-weight') && $name !== 'draft-row-board-games') {
                expect($box[1])->toBeLessThanOrEqual($width, "{$where}: {$name}");
            }
        }
    }

    // Positive control: the collector sees a throw and a failed answer on this very page.
    $page->evaluate('() => { setTimeout(() => { throw new Error("board mining admin positive control"); }); fetch("/board-mining-admin-positive-control-missing"); }');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("board mining admin positive control")) && window.__errors.some((e) => e.startsWith("404 "))', 5_000);
})->with([
    'phone 390, en' => [390, 844, 'en'],
    'desktop 1440, en' => [1440, 900, 'en'],
    'phone 390, de' => [390, 844, 'de'],
    'desktop 1440, de' => [1440, 900, 'de'],
]);

test('in a live season the board adds the board games by a rule change, clean at 390 and 1440 px', function (int $width, int $height, string $locale) {
    $board = User::factory()->withPubkey((new TestSigner)->pubkey)->create(['name' => 'vorstand', 'timezone' => 'UTC']);
    config(['esports.board' => [NostrKeys::hexToNpub($board->pubkey)], 'esports.trust.nsec' => (new TestSigner)->secret]);
    openSeason();

    $page = boardMiningAdminPage($board, $width, $height, $locale);
    $page->locator('[data-test=change-weight-nine-mens-morris-blitz]')->fill('1');
    $page->locator('[data-test=change-weight-checkers-blitz]')->fill('1');
    $page->locator('[data-test=change-share-chess]')->fill('30');
    $page->locator('[data-test=change-share-board-games]')->fill('5');
    $page->locator('[data-test=change-daily-board-games]')->fill('3');
    $page->locator('[data-test=change-reason]')->fill('Board games mine from today.');
    $page->locator('[data-test=save-change]')->click();
    BrowserWait::until($page, '() => document.body.innerText.includes("Board games mine from today.")', 10_000);
    $m = boardMiningAdminMeasure($page, ['season-change', 'change-weight-nine-mens-morris-blitz', 'change-share-board-games', 'change-daily-board-games', 'save-change']);

    fwrite(STDERR, "board mining change {$locale} {$width}x{$height}: ".json_encode($m).PHP_EOL);

    expect(SeasonParameterChange::query()->sole()->parameters)->toBe([
        'weights' => ['nine-mens-morris/blitz' => 1000, 'checkers/blitz' => 1000],
        'shares' => ['chess' => 30, 'board-games' => 5],
        'daily' => ['board-games' => 3],
    ])
        ->and($m['lang'])->toBe($locale)
        ->and($m['scroll'])->toBeLessThanOrEqual($m['client'])
        ->and($m['errors'])->toBe([])
        ->and($m['bad'])->toBe([]);

    foreach ($m['boxes'] as $name => $box) {
        expect($box)->not->toBeNull($name)
            ->and($box[0])->toBeGreaterThanOrEqual(0, $name)
            ->and($box[1])->toBeLessThanOrEqual($width, $name);
    }
})->with([
    'phone 390, en' => [390, 844, 'en'],
    'desktop 1440, de' => [1440, 900, 'de'],
]);

test('two players who list each other find a rated nine men\'s morris game from the lobby and land on the board; the lobby is clean', function (int $width, int $height, string $locale) {
    expect(config('broadcasting.default'))->toBe('reverb', 'Run this through `composer test:browser`, which starts Reverb.');

    config(['esports.board_games.rated_queue' => true]);
    app()->bind(TrustFacts::class, TrustedFacts::class);
    publishLadders(openSeason());
    [$annaSigner, $bertSigner] = [new TestSigner, new TestSigner];
    $anna = User::factory()->withPubkey($annaSigner->pubkey)->create(['name' => 'Anna']);
    $bert = User::factory()->withPubkey($bertSigner->pubkey)->create(['name' => 'Bert']);
    $opponents = app(Opponents::class);

    foreach ([[$anna, $annaSigner, $bert], [$bert, $bertSigner, $anna]] as [$player, $signer, $opponent]) {
        $opponents->add($player, $opponent, $signer->signTemplates($opponents->prepareAdd($player, $opponent)));
        $this->travel(1)->seconds();
    }

    $lobby = route('board.lobby', NineMensMorris::SLUG, false);
    $first = visit(BrowserLogin::url($anna))->page();
    $first->context()->addInitScript(BrowserConsole::COLLECTOR);
    $first->setViewportSize($width, $height);
    $first->goto(ComputeUrl::from(route('locale.switch', $locale, false)));
    $first->goto(ComputeUrl::from($lobby));
    // As in the chess lobby (P5 of plan mempool-streifen): the Blitz tile opens the panel, Rated is its second option.
    BrowserWait::until($first, '() => document.querySelector("[data-test=game-kind]")?.dataset.ratedOpen === "true"', 10_000);
    $first->locator('[data-test=play-blitz]')->click();
    $first->locator('[data-test=kind-rated]')->click();
    $offered = boardMiningAdminMeasure($first, ['find-opponent', 'kind-rated', 'find-opponent-button']);
    shellShot($first, "board-rated-lobby-{$locale}-{$width}");

    $first->locator('[data-test=find-opponent-button]')->click();
    BrowserWait::until($first, '() => document.querySelector("[data-test=lobby-searching]")?.dataset.rated === "true"', 10_000);
    $searching = boardMiningAdminMeasure($first, ['lobby-searching']);

    $second = visit(BrowserLogin::url($bert))->page();
    $second->context()->addInitScript(BrowserConsole::COLLECTOR);
    $second->setViewportSize($width, $height);
    $second->goto(ComputeUrl::from(route('locale.switch', $locale, false)));
    $second->goto(ComputeUrl::from($lobby));
    BrowserWait::until($second, '() => document.querySelector("[data-test=game-kind]")?.dataset.ratedOpen === "true"', 10_000);
    $second->locator('[data-test=play-blitz]')->click();
    $second->locator('[data-test=kind-rated]')->click();
    $second->locator('[data-test=find-opponent-button]')->click();

    foreach ([$second, $first] as $page) {
        BrowserWait::until($page, '() => location.pathname.startsWith("/board/") && document.querySelector("[data-test=board-game]") !== null', 15_000);
    }

    $board = boardMiningAdminMeasure($second, ['board-game']);
    $game = BoardGame::query()->sole();

    fwrite(STDERR, "board rated lobby {$locale} {$width}x{$height}: ".json_encode(compact('offered', 'searching', 'board')).PHP_EOL);

    expect($game->rated)->toBeTrue()
        ->and($game->ladder_address)->not->toBeNull()
        ->and([$game->white_id, $game->black_id])->toEqualCanonicalizing([$anna->id, $bert->id]);

    foreach (compact('offered', 'searching', 'board') as $where => $m) {
        expect($m['lang'])->toBe($locale, $where)
            ->and($m['scroll'])->toBeLessThanOrEqual($m['client'], $where)
            ->and($m['errors'])->toBe([], $where)
            ->and($m['bad'])->toBe([], $where);

        foreach ($m['boxes'] as $name => $box) {
            expect($box)->not->toBeNull("{$where}: {$name}")
                ->and($box[0])->toBeGreaterThanOrEqual(0, "{$where}: {$name}")
                ->and($box[1])->toBeLessThanOrEqual($width, "{$where}: {$name}");
        }
    }

    // Positive control on the board page.
    $second->evaluate('() => { setTimeout(() => { throw new Error("board rated lobby positive control"); }); fetch("/board/0"); }');
    BrowserWait::until($second, '() => window.__errors.some((e) => e.includes("board rated lobby positive control")) && window.__errors.some((e) => e.startsWith("404 "))', 5_000);
})->with([
    'phone 390, en' => [390, 844, 'en'],
    'desktop 1440, de' => [1440, 900, 'de'],
]);
