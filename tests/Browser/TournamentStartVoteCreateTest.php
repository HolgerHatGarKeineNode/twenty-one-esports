<?php

use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| The start vote on the create page in the browser (PR #26)
|--------------------------------------------------------------------------
|
| A chooser click renders only the islands TournamentFormatChooser::changed()
| names, so the `start-vote` island has to be among them: Blitz shows the
| vote, Daily (correspondence) hides it, Blitz shows it again still ticked.
| The ticked fields fit the page at 390 and 1440 px, and the console and
| every response stay quiet throughout.
|
*/

const START_VOTE_PICK = <<<'JS'
    (game) => [...document.querySelectorAll('button[role=radio]')].find((b) => b.getAttribute('wire:click') === `pickGame('${game}')`)?.click()
    JS;

const START_VOTE_STATE = <<<'JS'
    () => {
        const block = document.querySelector('[data-test=start-vote-create]');
        const fields = document.querySelector('[data-test=start-vote-fields]');
        return {
            shown: block !== null,
            open: fields !== null && getComputedStyle(fields).display !== 'none',
            width: fields?.getBoundingClientRect().width ?? 0,
            overflow: document.documentElement.scrollWidth - innerWidth,
        };
    }
    JS;

test('the start vote follows the game picked on the create page and fits the page', function (int $width) {
    $page = visit(BrowserLogin::url(organizer()))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize($width, $width < 640 ? 844 : 900);
    $page->goto(ComputeUrl::from(route('locale.switch', 'de')));
    $page->goto(ComputeUrl::from(route('admin.tournaments.create')));
    $page->waitForLoadState('networkidle');
    BrowserWait::until($page, '() => window.Alpine !== undefined', 10_000);

    $pick = function (string $game, string $until) use ($page): array {
        $page->evaluate('('.START_VOTE_PICK.')("'.$game.'")');
        BrowserWait::until($page, $until, 10_000);

        return $page->evaluate(START_VOTE_STATE);
    };

    expect($pick('blitz', '() => document.querySelector("[data-test=start-vote-create]") !== null'))
        ->toMatchArray(['shown' => true, 'open' => false]);

    $page->evaluate('() => document.querySelector("[data-test=start-vote-toggle]").click()');
    BrowserWait::until($page, '() => getComputedStyle(document.querySelector("[data-test=start-vote-fields]")).display !== "none"', 5_000);
    $open = $page->evaluate(START_VOTE_STATE);

    expect($open['overflow'])->toBe(0)
        ->and($open['width'])->toBeGreaterThan($width < 640 ? 280 : 1000);

    expect($pick('daily', '() => document.querySelector("[data-test=start-vote-create]") === null'))->toMatchArray(['shown' => false]);
    expect($pick('blitz', '() => document.querySelector("[data-test=start-vote-create]") !== null'))->toMatchArray(['shown' => true, 'open' => true]);

    expect(array_merge(
        $page->evaluate('() => window.__errors ?? ["collector missing"]'),
        array_map(fn ($e): string => 'runner '.(is_array($e) ? ($e['message'] ?? json_encode($e)) : (string) $e), $page->javaScriptErrors()),
        $page->evaluate(BrowserConsole::BAD_RESPONSES),
    ))->toBe([]);
})->with([390, 1440]);
