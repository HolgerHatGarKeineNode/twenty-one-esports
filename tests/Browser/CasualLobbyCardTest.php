<?php

use App\Models\SeriesMatch;
use App\Models\User;
use App\Support\Nostr\RelayReader;
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

function cardRoomPage(User $user, SeriesMatch $match, int $width = 1440, int $height = 900): Page
{
    $page = visit(BrowserLogin::url($user))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->context()->addInitScript(TestSigner::browserStub($user));
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
            ->and($guestPage->evaluate('() => document.querySelector("[data-test=share-lobby]")'))->toBeNull();

        cardRoomShot($hostPage, 'card-host-before-1440');

        $hostPage->locator('[data-test=share-lobby]')->click();
        $prefill = $hostPage->evaluate('() => [document.querySelector("[data-test=card-lobby-name]").value, document.querySelector("[data-test=card-lobby-password]").value]');
        expect($prefill[0])->toBe('e21-'.$match->number)
            ->and($prefill[1])->toMatch('/^[a-hjkmnp-z2-9]{6}$/');

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
