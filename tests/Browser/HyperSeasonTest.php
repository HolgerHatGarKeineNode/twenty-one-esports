<?php

use App\Enums\TournamentFormat;
use App\Models\Admin;
use App\Models\HyperMatch;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;
use Tests\Support\HyperOn;
use Tests\Support\TestSigner;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| Hyperbitcoinization in the season and in tournaments, in the browser (plan "Hyperbitcoinization", P5)
|--------------------------------------------------------------------------
|
| A rated free-for-all tournament in a live season: its player opens the table from the tournament page into a
| new tab (the click on the `target=_blank` link is recorded, not followed: a test cannot hold a second tab); a
| spectator opens "Who wins?" on the table, the player has no poll. The tournament page, the season ladder and the
| table with the poll open are measured as an admin in German at 390 and 1440 px (overflow, clipped texts, the
| primary action above the fold); every page carries BrowserConsole's collector (console errors, uncaught errors,
| answers >= 400), proved by a positive control. The numbers go to STDERR for the report.
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

    HyperOn::play();
});

function seasonPage(User $user, string $path, int $width = 1440, int $height = 900): Page
{
    $page = visit(BrowserLogin::url($user))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    // A link into a new tab is recorded instead of followed; the table's cinematics and sounds stay off.
    $page->context()->addInitScript('window.__opened = []; document.addEventListener("click", (e) => { const a = e.target.closest && e.target.closest("a[target=_blank]"); if (a) { e.preventDefault(); window.__opened.push(a.href); } }, true);');
    $page->context()->addInitScript('try { localStorage.setItem("hb-settings", '.json_encode((string) json_encode(['scenes' => false, 'music' => false, 'fx' => false, 'board' => false, 'speed' => 20])).'); } catch (e) {}');
    $page->goto(ComputeUrl::from(route('locale.switch', 'de', false)));
    $page->setViewportSize($width, $height);
    $page->goto(ComputeUrl::from($path));
    BrowserWait::until($page, '() => document.querySelector("[data-test=hyper-match]") ? document.body.dataset.ready === "1" : document.readyState === "complete"', 15_000);

    return $page;
}

/**
 * @return list<string>
 */
function seasonErrors(Page $page): array
{
    return [...$page->evaluate('() => window.__errors ?? ["collector missing"]'), ...$page->evaluate(BrowserConsole::BAD_RESPONSES)];
}

/**
 * One page's numbers at one size: document overflow, texts cut off (not ellipsised by design), the primary action's
 * box and whether it lies above the fold, and for the table the poll panel's box.
 *
 * @return array<string, mixed>
 */
function seasonMeasure(Page $page, string $name, int $width, int $height, string $primary, string $texts): array
{
    $page->setViewportSize($width, $height);
    $page->evaluate('() => new Promise((done) => setTimeout(done, 300))');

    return ['page' => $name, ...$page->evaluate(<<<JS
        () => {
            const de = document.documentElement;
            const box = (el) => { if (!el) return null; const r = el.getBoundingClientRect(); return r.width && r.height ? [Math.round(r.left), Math.round(r.top), Math.round(r.right), Math.round(r.bottom)] : null; };
            const cut = [...document.querySelectorAll('{$texts}')].filter((el) => el.offsetParent !== null && el.scrollWidth > el.clientWidth + 1 && getComputedStyle(el).textOverflow !== 'ellipsis')
                .map((el) => (el.dataset.test || el.tagName.toLowerCase()) + ': ' + el.innerText.trim().slice(0, 40));
            const main = box(document.querySelector('{$primary}'));
            return { size: innerWidth + 'x' + innerHeight, scroll: [de.scrollWidth, de.clientWidth], clipped: cut, primary: main, above: main !== null && main[3] <= innerHeight && main[0] >= 0 && main[2] <= innerWidth };
        }
        JS)];
}

test('a tournament table opens from the tournament page in a new tab, and a spectator of it votes in "Who wins?", a player never; pages measured', function () {
    $season = openSeason(ladders: false);
    config(['esports.game_chat.creator' => (new TestSigner)->pubkey]);
    $tournament = HyperOn::tournament(4, TournamentFormat::FreeForAll, ['heatSize' => 4, 'heatAdvance' => 1]);
    $table = HyperMatch::query()->with('seats.user')->sole();
    $player = $table->seats[0]->user;
    $spectator = User::factory()->create();
    Admin::query()->create(['pubkey' => $player->pubkey]);
    Admin::query()->create(['pubkey' => $spectator->pubkey]);
    $rows = [];

    expect($table->rated)->toBeTrue()->and($table->season)->toBe($season->slug);

    // The player: the tournament page's primary action opens the table in a new tab.
    $page = seasonPage($player, route('tournaments.show', $tournament, false));
    foreach ([[390, 844], [1440, 900]] as [$width, $height]) {
        $rows[] = seasonMeasure($page, 'tournament', $width, $height, '[data-test=now-action]', 'main h1, main h2, main a, main button, main b');
    }
    $page->setViewportSize(1440, 900);
    expect($page->evaluate('() => document.querySelector("[data-test=now-action]").target'))->toBe('_blank');
    $page->locator('[data-test=now-action]')->click();
    BrowserWait::until($page, '() => window.__opened.length === 1', 5_000);
    expect($page->evaluate('() => window.__opened'))->toBe([route('hyper.match', $table)])
        ->and(seasonErrors($page))->toBe([]);

    // On the table the player has no poll.
    $page->goto(ComputeUrl::from(route('hyper.match', $table, false)));
    BrowserWait::until($page, '() => document.body.dataset.ready === "1"', 15_000);
    expect($page->evaluate('() => document.querySelector("[data-test=hyper-poll-open]") === null && document.querySelector("[data-test=hyper-poll]") === null'))->toBeTrue()
        ->and(seasonErrors($page))->toBe([]);

    // The spectator opens "Who wins?": one answer per seat, nobody voted yet, and it stays open until closed.
    $watch = seasonPage($spectator, route('hyper.match', $table, false));
    $watch->locator('[data-test=hyper-poll-open]')->click();
    BrowserWait::until($watch, '() => !document.querySelector("[data-test=hyper-poll]").hidden && document.querySelectorAll("[data-test=hyper-poll-option]").length === 4', 5_000);
    $poll = $watch->evaluate('() => ({ heading: document.querySelector("#poll-h").innerText, options: [...document.querySelectorAll("[data-test=hyper-poll-option] .po-label")].map((el) => el.innerText), total: document.querySelector("[data-test=hyper-poll-total]").innerText, note: document.querySelector("[data-test=hyper-poll-note]").innerText, disabled: [...document.querySelectorAll("[data-test=hyper-poll-option]")].map((b) => b.disabled) })');
    $watch->evaluate('() => new Promise((done) => setTimeout(done, 3000))');
    $stillOpen = $watch->evaluate('() => !document.querySelector("[data-test=hyper-poll]").hidden');

    foreach ([[390, 844], [1440, 900]] as [$width, $height]) {
        $rows[] = seasonMeasure($watch, 'table + poll', $width, $height, '[data-test=hyper-poll]', '#poll h2, #poll .po-label, #poll .po-count, #poll p');
    }

    expect($poll['heading'])->toBe('WER GEWINNT?')
        ->and($poll['options'])->toBe($table->seats->map(fn ($seat): string => $seat->user->displayName().' · '.['bitcoiner' => 'Bitcoiner', 'fed' => 'Fed', 'ezb' => 'EZB', 'goldbug' => 'Goldbug', 'shitcoiner' => 'Shitcoiner', 'nocoiner' => 'Nocoiner'][$seat->faction])->all())
        ->and($poll['total'])->toBe('0 Stimmen')
        ->and($poll['note'])->toBe('Eine Stimme pro Konto, öffentlich auf Nostr. Nur für Zuschauer.')
        ->and($poll['disabled'])->toBe([false, false, false, false])
        ->and($stillOpen)->toBeTrue()
        ->and(seasonErrors($watch))->toBe([]);

    // The season ladder.
    $ladder = seasonPage($spectator, route('hyper.ladder', absolute: false));
    foreach ([[390, 844], [1440, 900]] as [$width, $height]) {
        $rows[] = seasonMeasure($ladder, 'ladder', $width, $height, '[data-test=hyper-ladder-play]', '[data-test=hyper-ladder] h1, [data-test=hyper-ladder] h2, [data-test=hyper-ladder] a, [data-test=hyper-ladder] li, [data-test=hyper-ladder] b');
    }
    expect(seasonErrors($ladder))->toBe([]);

    fwrite(STDERR, "\nhyper season measured: ".json_encode($rows, JSON_UNESCAPED_UNICODE)."\n");

    foreach ($rows as $row) {
        $where = $row['page'].' '.$row['size'];
        expect($row['scroll'][0])->toBe($row['scroll'][1], $where)
            ->and($row['clipped'])->toBe([], $where)
            ->and($row['above'])->toBeTrue($where);
    }

    // The positive control: the collector on the table sees a thrown error and a failed answer.
    $watch->evaluate('() => { setTimeout(() => { throw new Error("hyper p5 positive control"); }); fetch("/hyperbitcoinization/m/0"); }');
    BrowserWait::until($watch, '() => window.__errors.some((e) => e.includes("hyper p5 positive control")) && window.__errors.some((e) => e.startsWith("404 "))', 5_000);
});
