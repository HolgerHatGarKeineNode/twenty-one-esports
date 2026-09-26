<?php

use App\Models\Admin;
use App\Models\ClanInvite;
use App\Models\User;
use App\Support\Clans\ClanDraft;
use App\Support\Clans\ClanService;
use App\Support\Nostr\NostrKeys;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;
use Tests\Support\TestSigner;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| The player picker: tournament directors and the clan invite
|--------------------------------------------------------------------------
|
| /admin/tournaments/create in director mode at 375 and 1440 px: typed text
| that matches nobody says so and cannot be added; typing a name, ArrowDown
| and Enter add the player as a director chip, and the added player is not
| suggested again. The open list is measured: inside the viewport, not
| clipped or covered by the card or the next section (the topmost element at
| its corners is the list), and the page does not overflow.
|
| The clan invite (allow-npub) at 375 and 1440 px: a name that matches nobody
| is refused; a pasted npub without an account is offered as "not registered
| yet", becomes a chip and is invited (the stub account is created); a
| player found by name is invited the same way.
|
| Collected on every page: console.error/warn, uncaught errors, rejected
| promises, fetch and XHR >= 400 and the resource timing entries >= 400.
| PICKER_SHOTS=<dir> writes the English screenshots there.
|
*/

const PICKER_COLLECTOR = <<<'JS'
    window.__errors = [];
    const push = (entry) => window.__errors.push(entry);
    for (const level of ['error', 'warn']) {
        const original = console[level];
        console[level] = function (...args) { push('console.' + level + ': ' + args.map(String).join(' ')); original.apply(console, args); };
    }
    window.addEventListener('error', (e) => push('error: ' + (e.message || (e.target && (e.target.src || e.target.href)) || 'unknown')), true);
    window.addEventListener('unhandledrejection', (e) => push('unhandledrejection: ' + String(e.reason)));
    const originalFetch = window.fetch;
    window.fetch = (...args) => originalFetch(...args).then((r) => { if (r.status >= 400) push(r.status + ' ' + r.url); return r; });
    const originalOpen = XMLHttpRequest.prototype.open;
    XMLHttpRequest.prototype.open = function (method, url, ...rest) {
        this.addEventListener('loadend', () => { if (this.status >= 400 || this.status === 0) push('xhr ' + this.status + ' ' + url); });
        return originalOpen.call(this, method, url, ...rest);
    };
    JS;

const PICKER_BAD_RESPONSES = <<<'JS'
    () => performance.getEntries()
        .filter((e) => typeof e.responseStatus === 'number' && e.responseStatus >= 400)
        .map((e) => e.responseStatus + ' ' + e.name)
    JS;

/** The open list: its box, whether each corner is on top, the combobox state, the page overflow. */
const PICKER_LIST_STATE = <<<'JS'
    (id) => {
        const list = document.querySelector('[data-test=picker-list]');
        const input = document.getElementById(id);
        const r = list.getBoundingClientRect();
        const inset = 4;
        const corners = [[r.left + inset, r.top + inset], [r.right - inset, r.top + inset], [r.left + inset, r.bottom - inset], [r.right - inset, r.bottom - inset]];
        return {
            box: [Math.round(r.left), Math.round(r.top), Math.round(r.right), Math.round(r.bottom)],
            viewport: [window.innerWidth, window.innerHeight],
            onTop: corners.map(([x, y]) => list.contains(document.elementFromPoint(x, y))),
            options: [...list.querySelectorAll('[role=option]')].map((o) => o.innerText.replace(/\s+/g, ' ').trim()),
            expanded: input.getAttribute('aria-expanded'),
            active: input.getAttribute('aria-activedescendant'),
            selected: [...list.querySelectorAll('[role=option][aria-selected=true]')].map((o) => o.id),
            overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
        };
    }
    JS;

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

function pickerPage(User $user, string $to, int $width, int $height, bool $signer = false): Page
{
    $page = visit(BrowserLogin::url($user))->page();
    $page->context()->addInitScript(PICKER_COLLECTOR);

    if ($signer) {
        $page->context()->addInitScript(TestSigner::browserStub($user));
    }

    $page->setViewportSize($width, $height);
    $page->goto(ComputeUrl::from($to));

    return $page;
}

/**
 * @return array<string, mixed>
 */
function pickerListState(Page $page, string $id): array
{
    return $page->evaluate('() => ('.PICKER_LIST_STATE.')('.json_encode($id).')');
}

function pickerShot(Page $page, string $name): void
{
    $dir = getenv('PICKER_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    File::ensureDirectoryExists($dir);
    $page->screenshot(false, $name);
    File::move(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
}

test('typing, ArrowDown and Enter add a director at 375 and 1440 px; free text is refused', function () {
    $admin = User::factory()->create(['name' => 'satsjaeger']);
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    $nick = User::factory()->create(['name' => 'nonce_nick']);
    User::factory()->create(['name' => 'nonce_nora']);
    $measured = [];

    foreach ([[375, 812], [1440, 900]] as [$width, $height]) {
        $page = pickerPage($admin, route('admin.tournaments.create', absolute: false), $width, $height);
        BrowserWait::until($page, '() => document.querySelector("[data-test=results-director]") !== null', 10_000);
        $page->locator('[data-test=results-director]')->click();
        BrowserWait::until($page, '() => document.getElementById("dir-new") !== null && window.Alpine !== undefined', 10_000);
        $input = $page->locator('#dir-new');

        // Text that matches nobody: the picker says so, and Enter submits nothing but an error.
        $input->type('zz_nobody');
        BrowserWait::until($page, '() => document.querySelector("[data-test=picker-message]")?.offsetParent !== null && document.querySelector("[data-test=picker-message]").textContent === "No player found."', 8_000);
        $input->press('Enter');
        BrowserWait::until($page, '() => document.querySelector("[data-test=directors] [role=alert]")?.textContent.trim() === "Pick a player from the suggestions."', 8_000);

        // A name: suggestions, ArrowDown highlights the first, Enter adds it.
        $input->fill('');
        $input->type('nonce');
        BrowserWait::until($page, '() => document.querySelectorAll("[data-test=picker-list] [role=option]").length === 2 && document.querySelector("[data-test=picker-list]").offsetParent !== null', 8_000);
        $page->evaluate('() => document.getElementById("dir-new").scrollIntoView({ block: "center" })');
        $input->press('ArrowDown');
        BrowserWait::until($page, '() => document.getElementById("dir-new").getAttribute("aria-activedescendant") === "dir-new-opt-0"', 5_000);
        $open = pickerListState($page, 'dir-new');
        pickerShot($page, "player-picker-open-{$width}");

        $input->press('Enter');
        BrowserWait::until($page, '() => document.querySelector("[data-test=directors] ul")?.textContent.includes("nonce_nick") === true', 8_000);
        BrowserWait::until($page, '() => document.getElementById("dir-new")?.value === ""', 5_000);
        pickerShot($page, "player-picker-added-{$width}");

        // The added player is not suggested again.
        $page->locator('#dir-new')->type('nonce');
        BrowserWait::until($page, '() => document.querySelectorAll("[data-test=picker-list] [role=option]").length === 1', 8_000);
        $again = pickerListState($page, 'dir-new');

        $measured[$width] = ['list' => $open['box'], 'viewport' => $open['viewport'], 'overflow' => $open['overflow']];

        expect($open['options'])->toBe(['nonce_nick '.$nick->shortNpub(), 'nonce_nora '.User::query()->where('name', 'nonce_nora')->sole()->shortNpub()])
            ->and($open['expanded'])->toBe('true')
            ->and($open['active'])->toBe('dir-new-opt-0')
            ->and($open['selected'])->toBe(['dir-new-opt-0'])
            ->and($open['onTop'])->toBe([true, true, true, true])
            ->and($open['box'][0])->toBeGreaterThanOrEqual(0)
            ->and($open['box'][2])->toBeLessThanOrEqual($width)
            ->and($open['overflow'])->toBeLessThanOrEqual(0)
            ->and($again['options'])->toHaveCount(1)
            ->and($again['options'][0])->toStartWith('nonce_nora')
            ->and($again['overflow'])->toBeLessThanOrEqual(0)
            ->and($page->evaluate('() => window.__errors'))->toBe([])
            ->and($page->evaluate(PICKER_BAD_RESPONSES))->toBe([]);
    }

    fwrite(STDERR, "\n[player-picker] ".json_encode($measured)."\n");
});

test('the clan invite takes a player by name or an npub without an account, at 375 and 1440 px; a loose name is refused', function () {
    $owner = User::factory()->create(['name' => 'satsjaeger']);
    $ownerSigner = TestSigner::forBrowser($owner);
    $queen = User::factory()->create(['name' => 'queen_q']);
    $service = app(ClanService::class);
    $draft = new ClanDraft('Laser Eyes', 'LSR');
    $clan = $service->create($owner, $draft, $ownerSigner->signTemplates($service->prepareCreate($owner, $draft)));
    $newcomerKey = (new TestSigner)->pubkey;
    $newcomerNpub = NostrKeys::hexToNpub($newcomerKey);
    $measured = [];

    foreach ([[375, 812, 'npub'], [1440, 900, 'name']] as [$width, $height, $by]) {
        $page = pickerPage($owner, route('clans.manage', $clan, false), $width, $height, signer: true);
        BrowserWait::until($page, '() => document.getElementById("invite-player") !== null && window.Alpine !== undefined', 10_000);
        $input = $page->locator('#invite-player');
        $page->evaluate('() => document.getElementById("invite-player").scrollIntoView({ block: "center" })');

        // A name that matches nobody: no suggestion, and sending is refused.
        $input->type('Nobody Here');
        BrowserWait::until($page, '() => document.querySelector("#invite [data-test=picker-message]")?.textContent === "No player found."', 8_000);
        // The message overlays what is below it (the send button at 375 px); Escape closes it.
        $input->press('Escape');
        $page->locator('[data-test=send-invite]')->click();
        BrowserWait::until($page, '() => document.querySelector("#invite [role=alert]")?.textContent.trim() === "Pick a player from the suggestions or paste a full npub."', 8_000);
        $input->fill('');

        if ($by === 'npub') {
            // Pasted npub of a key without an account: offered as itself, highlighted, Enter picks it.
            $input->type($newcomerNpub);
            BrowserWait::until($page, '() => document.getElementById("invite-player").getAttribute("aria-activedescendant") === "invite-player-opt-0"', 8_000);
            $list = pickerListState($page, 'invite-player');
            pickerShot($page, "player-picker-clan-npub-open-{$width}");
            $input->press('Enter');
        } else {
            $input->type('queen');
            BrowserWait::until($page, '() => document.querySelectorAll("[data-test=picker-list] [role=option]").length === 1', 8_000);
            $input->press('ArrowDown');
            BrowserWait::until($page, '() => document.getElementById("invite-player").getAttribute("aria-activedescendant") === "invite-player-opt-0"', 5_000);
            $list = pickerListState($page, 'invite-player');
            pickerShot($page, "player-picker-clan-name-open-{$width}");
            $input->press('Enter');
        }

        BrowserWait::until($page, '() => document.querySelector("[data-test=picker-chip]")?.offsetParent !== null', 5_000);
        $chip = $page->evaluate('() => { const c = document.querySelector("[data-test=picker-chip]"); const r = c.getBoundingClientRect(); const n = c.querySelector("span"); return { nameFits: n.scrollWidth <= n.clientWidth, text: c.innerText.replace(/\\s+/g, " ").trim(), right: Math.round(r.right), overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth }; }');
        pickerShot($page, "player-picker-clan-{$by}-chip-{$width}");

        $page->locator('[data-test=send-invite]')->click();
        BrowserWait::until($page, '() => document.querySelector("[data-test=invite-link]") !== null', 10_000);
        pickerShot($page, "player-picker-clan-{$by}-sent-{$width}");

        $invitee = $by === 'npub' ? User::query()->where('pubkey', $newcomerKey)->sole() : $queen;
        $measured[$width] = ['list' => $list['box'], 'chip' => $chip, 'overflow' => $list['overflow']];

        expect($list['options'])->toHaveCount(1)
            ->and($list['options'][0])->toBe($by === 'npub' ? 'npub1…'.substr($newcomerNpub, -4).' not registered yet' : 'queen_q '.$queen->shortNpub())
            ->and($list['onTop'])->toBe([true, true, true, true])
            ->and($list['box'][2])->toBeLessThanOrEqual($width)
            ->and($list['overflow'])->toBeLessThanOrEqual(0)
            ->and($chip['text'])->toContain($by === 'npub' ? 'not registered yet' : 'queen_q')
            ->and($chip['nameFits'])->toBeTrue()
            ->and($chip['right'])->toBeLessThanOrEqual($width)
            ->and($chip['overflow'])->toBeLessThanOrEqual(0)
            ->and(ClanInvite::query()->where('invitee_id', $invitee->id)->exists())->toBeTrue()
            ->and($page->evaluate('() => window.__errors'))->toBe([])
            ->and($page->evaluate(PICKER_BAD_RESPONSES))->toBe([]);
    }

    fwrite(STDERR, "\n[player-picker-clan] ".json_encode($measured)."\n");
});

test('the collectors see a thrown error and a failed search (positive control)', function () {
    $admin = User::factory()->create(['name' => 'satsjaeger']);
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    $page = pickerPage($admin, route('admin.tournaments.create', absolute: false), 1440, 900);
    BrowserWait::until($page, '() => document.readyState === "complete" && document.querySelector("[data-test=results-director]") !== null', 10_000);
    $page->evaluate('() => setTimeout(() => { throw new Error("positive control"); })');
    $page->evaluate('() => fetch("/search/players?exclude=not-a-list", { headers: { Accept: "application/json" } })');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("positive control")) && window.__errors.some((e) => e.startsWith("422 "))', 5_000);

    expect(implode("\n", $page->evaluate('() => window.__errors')))->toContain('positive control')->toContain('/search/players');
});
