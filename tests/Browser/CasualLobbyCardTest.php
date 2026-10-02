<?php

use App\Enums\Platform;
use App\Models\SeriesMatch;
use App\Models\User;
use App\Support\LobbyWords;
use App\Support\Nostr\RelayReader;
use App\Support\Series\CasualChallenges;
use App\Support\Series\CasualMatches;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;
use Tests\Support\TestSigner;
use Tests\Support\WaitForPort;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| Lobby card of a casual Rocket League 1v1 (P23 S2, NIP "Lobby and account cards")
|--------------------------------------------------------------------------
|
| Host and guest in two contexts, each with a stubbed window.nostr that signs
| and encrypts with the player's own key (TestSigner::browserStub), over a
| real websocket to an in-memory relay (tests/Support/MiniRelay.php). The
| host sends a lobby card; the guest's chat draws it from the tags, as text.
| The league learns only the two flags, in their order, and the browser
| keeps no card in its local cache.
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

function cardRoomPage(User $user, SeriesMatch $match, int $width = 1440, int $height = 900, ?string $extraScript = null): Page
{
    $page = visit(BrowserLogin::url($user))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->context()->addInitScript(TestSigner::browserStub($user));

    if ($extraScript !== null) {
        $page->context()->addInitScript($extraScript);
    }

    $page->setViewportSize($width, $height);
    $page->goto(ComputeUrl::from(route('matches.room', $match, false)));
    BrowserWait::until($page, '() => window.Alpine && Alpine.$data(document.querySelector("[data-test=room-chat]"))?.status === "live"', 10_000);

    return $page;
}

/**
 * Waits until the chat's casual state says the league took the flag. In the
 * page, not in PHP: the app is served from this very process, and a PHP
 * sleep loop would starve it.
 */
function cardRoomFlag(Page $page, string $flag): void
{
    BrowserWait::until($page, '() => Alpine.$data(document.querySelector("[data-test=room-chat]")).casual?.'.$flag.' === true', 10_000);
}

function cardRoomShot(Page $page, string $name, ?string $element = null): void
{
    $dir = getenv('CARD_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    File::ensureDirectoryExists($dir);
    $element === null ? $page->screenshot(true, $name) : $page->screenshotElement($element, $name);
    File::move(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
}

const CARD_ROOM_OPEN = '() => document.querySelector("[data-test=room-chat] li[data-from=them] [data-test=chat-card][data-kind=lobby][data-state=open]") !== null';

test('the host shares a Rocket League lobby card, the guest sees it drawn from the tags, the flags land in order and no card is cached', function () {
    $port = (int) Process::run(['php', '-r', '$s = stream_socket_server("tcp://127.0.0.1:0"); echo explode(":", stream_socket_get_name($s, false))[1];'])->output();
    $relay = Process::path(base_path())->start(['php', 'tests/Support/mini-relay.php', (string) $port]);

    try {
        WaitForPort::open('127.0.0.1', $port);
        config(['esports.chat.relays' => ['ws://127.0.0.1:'.$port]]);

        [$match, $host, $guest] = casualStarted('rocket-league');
        TestSigner::forBrowser($host);
        TestSigner::forBrowser($guest);

        $guestPage = cardRoomPage($guest, $match);
        $hostPage = cardRoomPage($host, $match);

        // Positive control: the collector catches a thrown error and a 500 on a fetch; then it starts empty.
        $hostPage->evaluate('() => { setTimeout(() => { throw new Error("probe-throw"); }); return fetch("/__test/server-error"); }');
        BrowserWait::until($hostPage, '() => window.__errors.some((e) => e.includes("probe-throw")) && window.__errors.some((e) => e.startsWith("500 ")) && performance.getEntries().some((e) => e.name.includes("/__test/server-error") && e.responseStatus === 500)', 5_000);
        $hostPage->evaluate('() => { window.__errors = []; performance.clearResourceTimings(); }');

        // The steps name the host, the chat carries the one hint, and there is no server-side lobby form.
        expect($hostPage->evaluate('() => document.querySelector("[data-test=casual-role]").innerText'))->toBe('you host')
            ->and($guestPage->evaluate('() => document.querySelector("[data-test=casual-deadline]").dataset.kind'))->toBe('lobby')
            ->and($hostPage->evaluate('() => document.querySelector("[data-test=chat-hint]").innerText'))->toBe('End-to-end encrypted over Nostr: the league server never receives or stores these messages.')
            ->and($hostPage->evaluate('() => document.querySelector("[data-test=lobby-name-input]")'))->toBeNull()
            // Only the Rocket League host shares a lobby.
            ->and($guestPage->evaluate('() => document.querySelector("[data-test=casual-share]")'))->toBeNull();

        cardRoomShot($hostPage, 'card-host-before-1440');

        // Share lobby sits with the pin in the steps; it opens the composer in the chat.
        $hostPage->locator('[data-test=casual-share]')->click();
        BrowserWait::until($hostPage, '() => document.querySelector("[data-test=lobby-form]")?.checkVisibility() === true', 5_000);
        $prefill = $hostPage->evaluate('() => [document.querySelector("[data-test=card-lobby-name]").value, document.querySelector("[data-test=card-lobby-password]").value]');
        expect($prefill[0])->toBe(LobbyWords::matchName($match->number))->toMatch('/^21-[a-z]{3,8}-'.$match->number.'$/')
            ->and($prefill[1])->toMatch('/^[a-z]{3,8}-[a-z]{3,8}-\d{2}$/');

        // Markup in a value stays text: drawn with x-text, never as HTML.
        $name = '<i>sats</i>4you';
        $hostPage->locator('[data-test=card-lobby-name]')->fill($name);
        $password = $prefill[1];
        cardRoomShot($hostPage, 'card-host-composer-1440');

        // No relay takes the wrap to the guest, only the copy to self: that is no "shared" (NIP "Telling the league").
        $hostPage->evaluate('(guest) => {
            const chat = Alpine.$data(document.querySelector("[data-test=room-chat]"));
            const publish = chat.pool.publish.bind(chat.pool);
            window.__restorePublish = () => { chat.pool.publish = publish; };
            chat.pool.publish = (relays, wrap, options) => wrap.tags.some((t) => t[0] === "p" && t[1] === guest)
                ? relays.map(() => Promise.reject(new Error("blocked: test")))
                : publish(relays, wrap, options);
        }', $guest->pubkey);
        $hostPage->locator('[data-test=send-lobby-card]')->click();
        BrowserWait::until($hostPage, '() => Alpine.$data(document.querySelector("[data-test=room-chat]")).error === "The card did not reach your opponent\'s relays. Please try again."', 10_000);
        expect($match->refresh()->lobby_shared_at)->toBeNull()
            ->and($hostPage->evaluate('() => Alpine.$data(document.querySelector("[data-test=room-chat]")).casual.shared'))->toBeFalse();

        $hostPage->evaluate('() => window.__restorePublish()');
        $hostPage->locator('[data-test=send-lobby-card]')->click();

        cardRoomFlag($hostPage, 'shared');
        BrowserWait::until($guestPage, CARD_ROOM_OPEN, 10_000);
        cardRoomFlag($guestPage, 'seen');
        $match->refresh();

        $card = $guestPage->evaluate('() => {
            const card = document.querySelector("[data-test=room-chat] li[data-from=them] [data-test=chat-card][data-state=open]");
            const values = [...card.querySelectorAll("[data-test=card-value]")];
            return { values: values.map((v) => v.textContent), children: values.map((v) => v.children.length), title: card.querySelector("b").innerText };
        }');

        expect($card)->toBe(['values' => [$name, $password], 'children' => [0, 0], 'title' => 'Rocket League private match'])
            ->and($match->lobby_shared_at?->lte($match->lobby_seen_at))->toBeTrue()
            ->and($match->joined_at)->toBeNull();

        // The relay holds the wraps with the room's NIP-40 expiration (00:00 UTC, at least 7 days out).
        $wraps = app(RelayReader::class)->fetch([['kinds' => [1059], '#p' => [$guest->pubkey]]], ['ws://127.0.0.1:'.$port], perAuthor: 5);
        $expiration = (int) ($wraps[0]->tag('expiration') ?? 0);
        expect($wraps)->toHaveCount(1)
            ->and($expiration % 86400)->toBe(0)
            ->and($expiration)->toBeGreaterThanOrEqual((int) $match->casualChatExpiresFrom()?->addDays(7)->getTimestamp());

        // Neither browser keeps the card: a stub marks the wrap in the room's own cache, and nothing of the lobby is stored under any key.
        $storage = '() => Object.keys(localStorage).map((key) => key + "=" + localStorage.getItem(key)).join("\\n")';
        BrowserWait::until($hostPage, '() => (localStorage.getItem('.json_encode('esports.chat.cache.room.'.$host->pubkey).') ?? "").includes("stub")', 10_000);

        foreach ([[$guestPage, $guest], [$hostPage, $host]] as [$page, $user]) {
            expect($page->evaluate('() => localStorage.getItem('.json_encode('esports.chat.cache.room.'.$user->pubkey).')'))->toContain('"stub":true')
                ->and($page->evaluate($storage))->not->toContain('sats')->not->toContain($password)->not->toContain('lobby-');
        }

        // After a reload the card is opened again from the relay.
        $guestPage->reload();
        BrowserWait::until($guestPage, CARD_ROOM_OPEN, 10_000);

        cardRoomShot($guestPage, 'card-guest-1440');
        $guestPage->setViewportSize(375, 812);
        $narrow = $guestPage->evaluate('() => {
            const box = (s) => document.querySelector(s).getBoundingClientRect();
            const card = box("[data-test=room-chat] li[data-from=them]");
            const chat = box("[data-test=room-chat]");
            const steps = box("[data-test=casual-steps]");
            const joined = box("[data-test=casual-joined]");
            // The five stops of the timeline (P23 S3): what shows of each fits it; the rail between them overhangs on purpose.
            const tiles = [...document.querySelectorAll("[data-test=casual-steps] ol li")].map((li) => [...li.children].filter((el) => el.checkVisibility() && getComputedStyle(el).position !== "absolute").every((el) => el.scrollWidth <= el.clientWidth + 1));
            return {
                overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
                cardInside: card.left >= chat.left && card.right <= chat.right,
                joinedInside: joined.height >= 44 && joined.left >= steps.left && joined.right <= steps.right && joined.bottom <= steps.bottom,
                tilesFit: tiles,
                stepsFirst: steps.top < box("[data-test=casual-line]").top,
            };
        }');
        cardRoomShot($guestPage, 'card-guest-375');
        cardRoomShot($guestPage, 'card-guest-steps-375', '[data-test=casual-steps]');
        cardRoomShot($guestPage, 'card-guest-chat-375', '[data-test=room-chat]');

        expect($narrow)->toBe(['overflow' => 0, 'cardInside' => true, 'joinedInside' => true, 'tilesFit' => [true, true, true, true, true], 'stepsFirst' => true])
            ->and($hostPage->evaluate('() => window.__errors'))->toBe([])
            ->and($guestPage->evaluate('() => window.__errors'))->toBe([])
            ->and($hostPage->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([])
            ->and($guestPage->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
    } finally {
        $relay->stop(1);
    }
});

test('the EA ID card says when it was filled in from the gamer tags, and not when it was not (P51)', function () {
    $port = (int) Process::run(['php', '-r', '$s = stream_socket_server("tcp://127.0.0.1:0"); echo explode(":", stream_socket_get_name($s, false))[1];'])->output();
    $relay = Process::path(base_path())->start(['php', 'tests/Support/mini-relay.php', (string) $port]);

    try {
        WaitForPort::open('127.0.0.1', $port);
        config(['esports.chat.relays' => ['ws://127.0.0.1:'.$port]]);

        [$match, $host, $guest] = casualStarted('ea-sports-fc-27');
        $host->forceFill(['gamer_tags' => ['ea' => 'Host_EA']])->save();
        TestSigner::forBrowser($host);
        TestSigner::forBrowser($guest);

        $hostPage = cardRoomPage($host, $match, 375, 812);
        $guestPage = cardRoomPage($guest, $match, 375, 812);
        $prefilled = '() => { const el = document.querySelector("[data-test=card-account-prefilled]"); return el.checkVisibility() ? el.innerText.trim() : null; }';

        $hostPage->locator('[data-test=share-account]')->click();
        BrowserWait::until($hostPage, '() => document.querySelector("[data-test=account-form]").checkVisibility()', 5_000);
        expect($hostPage->evaluate('() => document.querySelector("[data-test=card-account-id]").value'))->toBe('Host_EA')
            ->and($hostPage->evaluate($prefilled))->toBe('Filled in from your gamer tags. It is sent only when you press Send card.');

        $line = $hostPage->evaluate('() => { const r = document.querySelector("[data-test=card-account-prefilled]").getBoundingClientRect(); const f = document.querySelector("[data-test=account-form]").getBoundingClientRect(); return { overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth, inside: r.left >= f.left && r.right <= f.right }; }');
        expect($line)->toBe(['overflow' => 0, 'inside' => true]);
        cardRoomShot($hostPage, 'card-account-prefilled-375', '[data-test=card-composer]');

        // A changed value is no prefill any more, and nothing went out on its own.
        $hostPage->locator('[data-test=card-account-id]')->fill('Other_EA');
        // Alpine hides the line on its next tick, not within fill().
        BrowserWait::until($hostPage, '() => !document.querySelector("[data-test=card-account-prefilled]").checkVisibility()', 5_000);
        expect($hostPage->evaluate($prefilled))->toBeNull()
            ->and($match->refresh()->lobby_shared_at)->toBeNull();

        // No saved EA ID: an empty field and no such line.
        $guestPage->locator('[data-test=share-account]')->click();
        BrowserWait::until($guestPage, '() => document.querySelector("[data-test=account-form]").checkVisibility()', 5_000);
        expect($guestPage->evaluate('() => document.querySelector("[data-test=card-account-id]").value'))->toBe('')
            ->and($guestPage->evaluate($prefilled))->toBeNull();

        foreach ([$hostPage, $guestPage] as $page) {
            expect($page->evaluate('() => window.__errors'))->toBe([])
                ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
        }
    } finally {
        $relay->stop(1);
    }
});

/**
 * Records what the page sends and gets back, for the privacy check of the
 * pinned card: fetch bodies and answers (every Livewire call and its
 * snapshot), XHR and beacon bodies, websocket frames to the relay. And a
 * clipboard that keeps what the copy buttons wrote.
 */
const CARD_PIN_RECORDER = <<<'JS'
    window.__wire = [];
    window.__copied = [];
    const keep = (entry) => window.__wire.push(entry);
    const fetchBefore = window.fetch;
    window.fetch = async (input, init = {}) => {
        const url = typeof input === 'string' ? input : input.url;
        keep('fetch> ' + url + ' ' + (typeof init.body === 'string' ? init.body : ''));
        const response = await fetchBefore(input, init);
        response.clone().text().then((text) => keep('fetch< ' + url + ' ' + text)).catch(() => {});
        return response;
    };
    const xhrBefore = XMLHttpRequest.prototype.send;
    XMLHttpRequest.prototype.send = function (body) { keep('xhr> ' + (typeof body === 'string' ? body : '')); return xhrBefore.call(this, body); };
    if (navigator.sendBeacon) {
        const beaconBefore = navigator.sendBeacon.bind(navigator);
        navigator.sendBeacon = (url, data) => { keep('beacon> ' + url + ' ' + (typeof data === 'string' ? data : '')); return beaconBefore(url, data); };
    }
    const wsBefore = WebSocket.prototype.send;
    WebSocket.prototype.send = function (data) { keep('ws> ' + String(data)); return wsBefore.call(this, data); };
    Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText: async (text) => { window.__copied.push(text); } } });
    JS;

/** Where the pin sits: on screen without scrolling, inside the steps, nothing wider than the page. */
const CARD_PIN_BOX = '() => {
    const pin = document.querySelector("[data-test=lobby-pin-card]");
    if (!pin) return null;
    const r = pin.getBoundingClientRect();
    const steps = document.querySelector("[data-test=casual-steps]").getBoundingClientRect();
    const action = document.querySelector("[data-test=lobby-pin-confirm]") ?? document.querySelector("[data-test=lobby-pin-replace]") ?? document.querySelector("[data-test=lobby-pin-copy-password]");
    const joined = document.querySelector("[data-test=casual-joined]");
    // Seen without scrolling: inside the window, and its bottom edge not covered by a bar fixed over it (tab bar, score bar).
    const seen = (el) => {
        const box = el.getBoundingClientRect();
        if (box.top < 0 || box.bottom > innerHeight) return false;
        const hit = document.elementFromPoint(box.left + box.width / 2, box.bottom - 2);
        return hit !== null && el.contains(hit);
    };
    return {
        scrollY: window.scrollY,
        visible: pin.checkVisibility(),
        inFold: seen(pin),
        actionInFold: seen(action) && action.getBoundingClientRect().height >= 44,
        joinedInFold: joined ? seen(joined) && joined.getBoundingClientRect().height >= 44 : null,
        inSteps: r.left >= steps.left && r.right <= steps.right && r.top >= steps.top && r.bottom <= steps.bottom,
        overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
    };
}';

function cardPinValues(Page $page): ?array
{
    return $page->evaluate('() => { const card = document.querySelector("[data-test=lobby-pin-card]"); return card ? [card.querySelector("[data-test=lobby-pin-name]").textContent, card.querySelector("[data-test=lobby-pin-password]").textContent] : null; }');
}

function cardPinWait(Page $page, ?string $name): void
{
    BrowserWait::until($page, $name === null
        ? '() => document.querySelector("[data-test=lobby-pin]") !== null && document.querySelector("[data-test=lobby-pin-card]") === null'
        : '() => document.querySelector("[data-test=lobby-pin-name]")?.textContent === '.json_encode($name), 10_000);
}

function cardPinRefresh(Page $page): void
{
    $page->evaluate('() => Livewire.all().find((c) => c.el.querySelector("[data-test=casual-steps]")).$wire.$refresh()');
}

test('the open lobby card is pinned in the steps for both players; a card from before the check-in counts after "Still valid"; Replace and Close move the pin; name and password never reach the server', function () {
    $port = (int) Process::run(['php', '-r', '$s = stream_socket_server("tcp://127.0.0.1:0"); echo explode(":", stream_socket_get_name($s, false))[1];'])->output();
    $relay = Process::path(base_path())->start(['php', 'tests/Support/mini-relay.php', (string) $port]);

    try {
        WaitForPort::open('127.0.0.1', $port);
        config(['esports.chat.relays' => ['ws://127.0.0.1:'.$port]]);

        // A scheduled Rocket League 1v1 (match #53): accepted, the check-in window open, nobody checked in yet.
        [$anna, $bert] = User::factory()->count(2)->create();
        $at = now()->addDay()->setTime(21, 0)->getTimestamp();
        $challenges = app(CasualChallenges::class);
        $match = $challenges->challenge($anna, $bert, 'rocket-league', Platform::Pc, true, [$at], now()->addDay()->setTime(12, 0)->getTimestamp(), '');
        $match = $challenges->accept($match, $bert, $at, Platform::Pc, true)->refresh();
        $this->travelTo(now()->setTimestamp($at)->subMinutes(8));
        [$host, $guest] = $match->host_side === casualSideOf($match, $anna) ? [$anna, $bert] : [$bert, $anna];
        // The guest reads the room in German, the host in English.
        $guest->forceFill(['locale' => 'de'])->save();
        TestSigner::forBrowser($host);
        TestSigner::forBrowser($guest);
        [$name, $password] = ['sats-lobby-alpha', 'mempool-halving-42'];
        [$newName, $newPassword] = ['sats-lobby-bravo', 'nonce-block-07'];

        $hostPage = cardRoomPage($host, $match, 375, 812, CARD_PIN_RECORDER);
        $guestPage = cardRoomPage($guest, $match, 1440, 900, CARD_PIN_RECORDER);

        // Positive control: the collector catches a thrown error and a 500 on a fetch; then it starts empty.
        foreach ([$hostPage, $guestPage] as $page) {
            $page->evaluate('() => { setTimeout(() => { throw new Error("probe-throw"); }); return fetch("/__test/server-error"); }');
            BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("probe-throw")) && window.__errors.some((e) => e.startsWith("500 ")) && performance.getEntries().some((e) => e.name.includes("/__test/server-error") && e.responseStatus === 500)', 5_000);
            $page->evaluate('() => { window.__errors = []; performance.clearResourceTimings(); }');
        }

        // No card yet: no pin card, and before the check-in no Share lobby in the steps nor in the chat.
        expect(cardPinValues($hostPage))->toBeNull()
            ->and($hostPage->evaluate('() => [document.querySelector("[data-test=casual-share]"), document.querySelector("[data-test=share-lobby]"), document.querySelector("[data-test=close-lobby]")]'))->toBe([null, null, null]);

        // The card of match #53: sent from the chat before the check-in (an older client offered it there). It goes out, and the chat does not ask the league.
        $hostPage->evaluate('async ([name, password]) => {
            const chat = Alpine.$data(document.querySelector("[data-test=room-chat]"));
            chat.openComposer("lobby");
            chat.lobbyName = name;
            chat.lobbyPassword = password;
            await chat.sendCard("lobby");
        }', [$name, $password]);

        cardPinWait($hostPage, $name);
        cardPinWait($guestPage, $name);
        expect($hostPage->evaluate('() => Alpine.$data(document.querySelector("[data-test=room-chat]")).error'))->toBe('')
            ->and($match->refresh()->lobby_shared_at)->toBeNull()
            // Before the check-in the pin has Replace and Close for the host, no "Still valid".
            ->and($hostPage->evaluate('() => [!!document.querySelector("[data-test=lobby-pin-replace]"), !!document.querySelector("[data-test=lobby-pin-close]"), !!document.querySelector("[data-test=lobby-pin-confirm]")]'))->toBe([true, true, false])
            ->and($guestPage->evaluate('() => document.querySelector("[data-test=lobby-pin-replace]")'))->toBeNull();

        // Both check in; the pages render again (the league's own sync does the same within seconds).
        app(CasualMatches::class)->checkIn($match, $anna);
        app(CasualMatches::class)->checkIn($match, $bert);
        cardPinRefresh($hostPage);
        cardPinRefresh($guestPage);
        BrowserWait::until($hostPage, '() => document.querySelector("[data-test=lobby-pin-confirm]")?.checkVisibility() === true', 10_000);
        BrowserWait::until($guestPage, '() => document.querySelector("[data-test=casual-deadline]")?.dataset.kind === "lobby" && document.querySelector("[data-test=lobby-pin-name]")?.textContent === '.json_encode($name), 10_000);

        // The flow stands at "Lobby shared" with the card open: the pin shows it, with "Still valid" as the one primary action, on screen at 375.
        $hostBox = $hostPage->evaluate(CARD_PIN_BOX);
        $guestBox = $guestPage->evaluate(CARD_PIN_BOX);
        expect($hostBox)->toBe(['scrollY' => 0, 'visible' => true, 'inFold' => true, 'actionInFold' => true, 'joinedInFold' => null, 'inSteps' => true, 'overflow' => 0])
            ->and($guestBox)->toBe(['scrollY' => 0, 'visible' => true, 'inFold' => true, 'actionInFold' => true, 'joinedInFold' => null, 'inSteps' => true, 'overflow' => 0])
            ->and($hostPage->evaluate('() => document.querySelector("[data-test=lobby-pin-by]").textContent'))->toMatch('/^Shared by you at \d\d:\d\d$/')
            ->and($guestPage->evaluate('() => document.querySelector("[data-test=lobby-pin-by]").textContent'))->toMatch('/^Geteilt von .+ um \d\d:\d\d$/')
            ->and($guestPage->evaluate('() => document.querySelector("[data-test=lobby-pin-card]").innerText'))->toContain('Tritt dieser Lobby in Rocket League bei')->toContain('bestätigt, dass die Lobby noch gilt');
        cardRoomShot($hostPage, 'pin-host-confirm-375-en');

        // "Still valid": the card goes out again, the league counts it, the steps move on to "Joined" on both sides.
        $hostPage->locator('[data-test=lobby-pin-confirm]')->click();
        cardRoomFlag($hostPage, 'shared');
        BrowserWait::until($hostPage, '() => document.querySelector("[data-test=casual-step-joined]")?.getAttribute("aria-current") === "step" && document.querySelector("[data-test=lobby-pin-confirm]") === null && document.querySelector("[data-test=lobby-pin-replace]") !== null', 10_000);
        cardRoomFlag($guestPage, 'seen');
        cardPinRefresh($guestPage);
        BrowserWait::until($guestPage, '() => document.querySelector("[data-test=casual-joined]") !== null && document.querySelector("[data-test=lobby-pin-name]")?.textContent === '.json_encode($name), 10_000);
        expect($match->refresh()->lobby_shared_at)->not->toBeNull()
            ->and($match->lobby_seen_at)->not->toBeNull()
            ->and(cardPinValues($guestPage))->toBe([$name, $password]);

        // Both sizes for both players: the pin is on screen without scrolling the page or the chat.
        $hostPage->setViewportSize(1440, 900);
        $guestPage->setViewportSize(375, 812);
        $guestPage->evaluate('() => window.scrollTo(0, 0)');
        expect($hostPage->evaluate(CARD_PIN_BOX))->toBe(['scrollY' => 0, 'visible' => true, 'inFold' => true, 'actionInFold' => true, 'joinedInFold' => null, 'inSteps' => true, 'overflow' => 0])
            // The guest's primary action, "I am in the lobby", stays above the fold at 375 too.
            ->and($guestPage->evaluate(CARD_PIN_BOX))->toBe(['scrollY' => 0, 'visible' => true, 'inFold' => true, 'actionInFold' => true, 'joinedInFold' => true, 'inSteps' => true, 'overflow' => 0]);
        cardRoomShot($hostPage, 'pin-host-1440-en');
        cardRoomShot($guestPage, 'pin-guest-375-de');
        $hostPage->setViewportSize(375, 812);
        cardRoomShot($hostPage, 'pin-host-375-en');

        // The copy buttons write the values, and say so.
        $guestPage->locator('[data-test=lobby-pin-copy-name]')->click();
        $guestPage->locator('[data-test=lobby-pin-copy-password]')->click();
        BrowserWait::until($guestPage, '() => window.__copied.length === 2', 5_000);
        expect($guestPage->evaluate('() => window.__copied'))->toBe([$name, $password]);

        // "I am in the lobby" under the card: the step "Joined" is done, the card stays pinned.
        $guestPage->locator('[data-test=casual-joined]')->click();
        BrowserWait::until($guestPage, '() => document.querySelector("[data-test=casual-step-joined]")?.dataset.done === "1" && document.querySelector("[data-test=casual-joined]") === null && document.querySelector("[data-test=lobby-pin-name]")?.textContent === '.json_encode($name), 10_000);
        expect($match->refresh()->joined_at)->not->toBeNull();

        // Replace: the composer opens in the chat with the old name; the new card replaces the pin on both sides.
        $hostPage->locator('[data-test=lobby-pin-replace]')->click();
        BrowserWait::until($hostPage, '() => document.querySelector("[data-test=lobby-form]")?.checkVisibility() === true', 5_000);
        expect($hostPage->evaluate('() => document.querySelector("[data-test=card-lobby-name]").value'))->toBe($name);
        $hostPage->locator('[data-test=card-lobby-name]')->fill($newName);
        $hostPage->locator('[data-test=card-lobby-password]')->fill($newPassword);
        $hostPage->locator('[data-test=send-lobby-card]')->click();
        cardPinWait($hostPage, $newName);
        cardPinWait($guestPage, $newName);
        expect(cardPinValues($guestPage))->toBe([$newName, $newPassword])
            ->and(cardPinValues($hostPage))->toBe([$newName, $newPassword]);

        // Close: the pin is gone on both sides; the host gets Share lobby back, the guest keeps the score step.
        $hostPage->locator('[data-test=lobby-pin-close]')->click();
        cardPinWait($hostPage, null);
        cardPinWait($guestPage, null);
        expect($hostPage->evaluate('() => document.querySelector("[data-test=casual-share]")?.checkVisibility()'))->toBeTrue()
            ->and($guestPage->evaluate('() => document.querySelector("[data-test=casual-step-report]")?.getAttribute("aria-current")'))->toBe('step')
            ->and($guestPage->evaluate('() => document.querySelector("[data-test=room-chat] li[data-from=them] [data-test=chat-card][data-state=closed]") !== null'))->toBeTrue();

        // Nothing of either lobby went to the league: no request body, no Livewire answer, no websocket frame (the wraps are encrypted).
        // The one exception is this test's stand-in for the signer extension: TestSigner::browserStub() encrypts on the test server
        // (`/__test/nostr/…`, routes/testing.php), where a real extension encrypts inside the browser. Those lines are set apart, and
        // they are the only ones that carry a value: the positive control that the recorder sees the plaintext where it travels.
        $recorded = [$hostPage->evaluate('() => window.__wire'), $guestPage->evaluate('() => window.__wire')];
        [$signer, $app] = collect(array_merge(...$recorded))->partition(fn (string $line) => preg_match('#^fetch[<>] (https?://[^/ ]+)?/__test/nostr/#', $line) === 1);
        $all = $app->implode("\n");
        expect($signer->implode("\n"))->toContain($name)->toContain($newPassword)
            ->and($all)->not->toContain($name)->not->toContain($password)->not->toContain($newName)->not->toContain($newPassword)
            // Positive control: the recorder saw the Livewire calls of both flags and the wraps going out.
            ->and(collect($recorded[0])->contains(fn (string $line) => str_starts_with($line, 'fetch> ') && str_contains($line, 'casualLobbyShared')))->toBeTrue()
            ->and(collect($recorded[1])->contains(fn (string $line) => str_starts_with($line, 'fetch> ') && str_contains($line, 'casualLobbySeen')))->toBeTrue()
            ->and(collect($recorded[0])->contains(fn (string $line) => str_starts_with($line, 'ws> ["EVENT"')))->toBeTrue()
            ->and($match->refresh()->lobby_name)->toBeNull()
            ->and($match->lobby_password)->toBeNull();

        foreach ([$hostPage, $guestPage] as $page) {
            expect($page->evaluate('() => window.__errors'))->toBe([])
                ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
        }
    } finally {
        $relay->stop(1);
    }
});
