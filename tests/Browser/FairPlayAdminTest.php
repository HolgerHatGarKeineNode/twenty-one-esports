<?php

use App\Enums\ChessEndReason;
use App\Models\AccountLink;
use App\Models\Admin;
use App\Models\ChessGame;
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
| The fair-play admin page (P41): link two accounts, then unlink them
|--------------------------------------------------------------------------
|
| At 375 and 1440 px an admin picks the main and the second account by name
| in the player pickers, gives a reason and links them; the page reports the
| voided result and lists the link. Unlinking with a reason moves it to the
| undone links. Measured: no horizontal overflow, every button of the page at
| least 44 px high, the rows inside the window. The console, uncaught errors
| and every answer (the Livewire round-trips included) stay clean, with a
| positive control first. FAIR_PLAY_SHOTS=<dir> writes the screenshots there.
|
*/

beforeEach(function () {
    Http::fake(fn () => Http::response([]));
});

function fairPlayPage(User $admin, int $width, int $height): Page
{
    $page = visit(BrowserLogin::url($admin))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    // wire:confirm asks window.confirm; the admin says yes.
    $page->context()->addInitScript('window.confirm = () => true;');
    $page->setViewportSize($width, $height);
    $page->goto(ComputeUrl::from(route('admin.fair-play', absolute: false)));
    BrowserWait::until($page, '() => window.Alpine !== undefined && window.Livewire !== undefined && document.getElementById("fair-play-main") !== null', 10_000);

    return $page;
}

/** Type a name into a picker, highlight the first suggestion and take it. */
function fairPlayPick(Page $page, string $id, string $name): void
{
    $page->evaluate('(id) => document.getElementById(id).scrollIntoView({ block: "center" })', $id);
    $input = $page->locator('#'.$id);
    $input->type($name);
    BrowserWait::until($page, '() => document.querySelectorAll("[data-picker-id='.$id.'] [data-test=picker-list] [role=option]").length === 1', 8_000);
    $input->press('ArrowDown');
    BrowserWait::until($page, '() => document.getElementById('.json_encode($id).').getAttribute("aria-activedescendant") === '.json_encode($id.'-opt-0'), 5_000);
    $input->press('Enter');
    BrowserWait::until($page, '() => document.querySelector("[data-picker-id='.$id.'] [data-test=picker-chip]")?.offsetParent !== null', 5_000);
}

/**
 * @return array<string, mixed>
 */
function fairPlayGeometry(Page $page): array
{
    return $page->evaluate('() => {
        const main = document.querySelector("[data-test=admin-fair-play]");
        const buttons = [...main.querySelectorAll("button")].filter((el) => el.checkVisibility());
        return {
            overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
            small: buttons.filter((el) => el.getBoundingClientRect().height < 44).map((el) => (el.dataset.test || el.innerText.trim().slice(0, 30)) + " " + Math.round(el.getBoundingClientRect().height)),
            outside: [...main.querySelectorAll("[data-test=fair-play-link-row], [data-test=fair-play-history-row], [data-test=fair-play-link-form]")].filter((el) => { const r = el.getBoundingClientRect(); return r.left < 0 || r.right > window.innerWidth + 0.5; }).map((el) => el.dataset.test),
            clipped: [...main.querySelectorAll("*")].filter((el) => el.checkVisibility() && el.children.length === 0 && el.scrollWidth > el.clientWidth + 1 && getComputedStyle(el).overflowX === "visible" && getComputedStyle(el).textOverflow !== "ellipsis").map((el) => el.dataset.test || el.tagName + ":" + el.innerText.slice(0, 30)),
        };
    }');
}

function fairPlayShot(Page $page, string $name): void
{
    $dir = getenv('FAIR_PLAY_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    File::ensureDirectoryExists($dir);
    $page->screenshot(true, $name);
    File::move(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
}

test('an admin links two accounts of one player and unlinks them again, at 375 and 1440 px, with a clean console', function () {
    $admin = User::factory()->create(['name' => 'satsjaeger']);
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    foreach ([[375, 812], [1440, 900]] as $round => [$width, $height]) {
        $main = User::factory()->create(['name' => 'mainkonto'.$round]);
        $second = User::factory()->create(['name' => 'zweitkonto'.$round.' with a rather long display name for the row']);
        $game = ChessGame::factory()->finished('1-0')->create(['white_id' => $second->id, 'black_id' => $main->id]);

        $page = fairPlayPage($admin, $width, $height);

        // Positive control: a thrown error and a 500 answer must both be seen, then the slate is cleaned.
        $page->evaluate('() => { setTimeout(() => { throw new Error("fair-play-probe"); }); return fetch("/__test/server-error"); }');
        BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("fair-play-probe")) && window.__errors.some((e) => e.startsWith("500 ")) && performance.getEntries().some((e) => e.name.includes("/__test/server-error") && e.responseStatus === 500)', 5_000);
        $page->evaluate('() => { window.__errors = []; performance.clearResourceTimings(); }');

        fairPlayPick($page, 'fair-play-main', 'mainkonto'.$round);
        fairPlayPick($page, 'fair-play-linked', 'zweitkonto'.$round);
        $page->locator('[data-test=fair-play-reason]')->fill('Same Lightning address and device');
        $page->locator('[data-test=fair-play-link]')->click();
        BrowserWait::until($page, '() => document.querySelector("[data-test=fair-play-notice]")?.innerText.includes("Linked.") === true && document.querySelector("[data-test=fair-play-link-row]") !== null', 10_000);
        $linked = fairPlayGeometry($page);
        $notice = $page->evaluate('() => document.querySelector("[data-test=fair-play-notice]").innerText.trim()');
        fairPlayShot($page, "fair-play-linked-{$width}");

        $page->locator('[data-test=fair-play-reason]')->fill('Siblings, checked on a call');
        $page->evaluate('() => document.querySelector("[data-test=fair-play-unlink]").scrollIntoView({ block: "center" })');
        $page->locator('[data-test=fair-play-unlink]')->click();
        BrowserWait::until($page, '() => document.querySelector("[data-test=fair-play-notice]")?.innerText.includes("Unlinked.") === true && document.querySelector("[data-test=fair-play-history-row]") !== null', 10_000);
        $unlinked = fairPlayGeometry($page);
        $history = $page->evaluate('() => document.querySelector("[data-test=fair-play-history-row]").innerText');
        fairPlayShot($page, "fair-play-unlinked-{$width}");

        expect($notice)->toBe('Linked. Results voided between the accounts: 1. Prizes withheld: 0.')
            ->and($linked)->toBe(['overflow' => 0, 'small' => [], 'outside' => [], 'clipped' => []], "linked at {$width}px")
            ->and($unlinked)->toBe(['overflow' => 0, 'small' => [], 'outside' => [], 'clipped' => []], "unlinked at {$width}px")
            ->and($history)->toContain('Siblings, checked on a call')->toContain('Same Lightning address and device')
            ->and($page->evaluate('() => window.__errors'))->toBe([], "console at {$width}px")
            ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([], "answers at {$width}px")
            ->and($game->refresh()->end_reason)->toBe(ChessEndReason::Voided)
            ->and(AccountLink::query()->where('linked_user_id', $second->id)->sole()->only(['main_user_id', 'linked_by_id', 'unlinked_by_id']))
            ->toBe(['main_user_id' => $main->id, 'linked_by_id' => $admin->id, 'unlinked_by_id' => $admin->id]);
    }
});
