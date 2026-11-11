<?php

use App\Models\Admin;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BlockliOn;
use Tests\Support\BlockspaceOn;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| The combined tournament's sequence editor (plan "combined tournament sequence", P6)
|--------------------------------------------------------------------------
|
| /admin/tournaments/create, chess blitz, at 390 and 1440 px: the organizer adds Blockli and Blockspace, moves
| Blockspace up, removes it again; chess stays first. Each step travels as an island request. Measured: the order in
| the list, horizontal overflow of the page and of the list, the 44 px targets, the console (collector and runner) and
| every response of 400 or more. A thrown error at the end proves the collector sees one.
|
*/

beforeEach(function () {
    Http::fake(fn () => Http::response([]));
    BlockliOn::play();
    BlockspaceOn::play();

    config(['session.driver' => 'database']);

    app()->rebinding('request', function ($app): void {
        $app['session']->forgetDrivers();
        $app->forgetInstance('session.store');
        $app->forgetInstance('auth.driver');
        $app['auth']->forgetGuards();
        $app['livewire']->flushState();
    });
});

/** The games of the sequence in the order the list shows them. */
function sequenceOrder(Page $page): string
{
    return (string) $page->evaluate('() => [...document.querySelectorAll("[data-test=sequence-game]")].map((li) => li.dataset.game).join(",")');
}

/** SEQUENCE_SHOTS=<dir> keeps a screenshot of the full list per viewport there. */
function sequenceShot(Page $page, string $name): void
{
    $dir = getenv('SEQUENCE_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    File::ensureDirectoryExists($dir);
    $page->screenshot(true, $name);
    File::move(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
}

/** Waits until the list shows this order. */
function sequenceWait(Page $page, string $order): void
{
    BrowserWait::until($page, '() => [...document.querySelectorAll("[data-test=sequence-game]")].map((li) => li.dataset.game).join(",") === "'.$order.'"', 8_000);
}

test('the sequence editor adds, reorders and removes games at 390 and 1440 px, quietly and without overflow', function () {
    $admin = User::factory()->create(['name' => 'satsjaeger']);
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    $page = visit(BrowserLogin::url($admin))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);

    foreach ([[390, 844], [1440, 900]] as [$width, $height]) {
        $page->setViewportSize($width, $height);
        $page->goto(ComputeUrl::from(route('admin.tournaments.create')));
        BrowserWait::until($page, '() => document.querySelector("[data-test=sequence-option]") !== null && window.Livewire !== undefined', 10_000);

        expect(sequenceOrder($page))->toBe('chess');

        $page->locator('[data-test=sequence-add-blockli]')->click();
        sequenceWait($page, 'chess,blockli');
        $page->locator('[data-test=sequence-add-blockspace]')->click();
        sequenceWait($page, 'chess,blockli,blockspace');

        // Blockspace one place up: it plays second.
        $page->locator('[data-test=sequence-game][data-game=blockspace] [data-test=sequence-up]')->click();
        sequenceWait($page, 'chess,blockspace,blockli');

        $full = $page->evaluate(<<<'JS'
            () => {
                const list = document.querySelector('[data-test=sequence-games]').getBoundingClientRect();
                const buttons = [...document.querySelectorAll('[data-test=sequence-games] button, [data-test=sequence-add] button')].map((b) => b.getBoundingClientRect());
                return {
                    page: document.documentElement.scrollWidth - document.documentElement.clientWidth,
                    list: { left: Math.round(list.left), right: Math.round(list.right), width: Math.round(list.width) },
                    smallest: Math.round(Math.min(...buttons.map((b) => Math.min(b.width, b.height)))),
                    firstUp: document.querySelector('[data-test=sequence-game][data-game=blockspace] [data-test=sequence-up]').disabled,
                    decider: document.querySelector('[data-test=sequence-decider]') !== null,
                    help: document.querySelector('[data-test=sequence-help]').textContent.trim(),
                };
            }
            JS);

        sequenceShot($page, "sequence-editor-{$width}");

        // Blockspace out again: the sequence is chess and Blockli.
        $page->locator('[data-test=sequence-game][data-game=blockspace] [data-test=sequence-remove]')->click();
        sequenceWait($page, 'chess,blockli');

        expect($full['page'])->toBeLessThanOrEqual(0)
            ->and($full['list']['right'])->toBeLessThanOrEqual($width)
            ->and($full['list']['left'])->toBeGreaterThanOrEqual(0)
            ->and($full['smallest'])->toBeGreaterThanOrEqual(44)
            // Second place cannot move above the tournament's game.
            ->and($full['firstUp'])->toBeTrue()
            ->and($full['decider'])->toBeTrue()
            ->and($full['help'])->toStartWith('Chess + Blockspace + Blockli, one game each')
            ->and($page->evaluate('() => document.documentElement.scrollWidth - document.documentElement.clientWidth'))->toBeLessThanOrEqual(0)
            ->and($page->evaluate('() => window.__errors'))->toBe([])
            ->and($page->javaScriptErrors())->toBe([])
            ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
    }

    // Positive control: a thrown error reaches the collector the assertions above read.
    $page->evaluate('() => setTimeout(() => { throw new Error("positive control"); })');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("positive control"))', 5_000);
});
