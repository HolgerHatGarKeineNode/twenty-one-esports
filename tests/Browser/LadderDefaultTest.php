<?php

use App\Models\Rating;
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
