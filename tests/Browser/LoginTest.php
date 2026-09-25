<?php

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserWait;

pest()->group('browser');

beforeEach(function () {
    // The login response defers App\Support\Membership::refresh(), which
    // calls the Verein API — Illuminate\Support\Defer runs deferred
    // callbacks from Kernel::terminate(), which LaravelHttpServer calls for
    // every request this test drives. Without this fake that is a real
    // outbound call to verein.einundzwanzig.space during a browser test.
    Http::fake(fn () => Http::response([]));
});

/**
 * Installs window.nostr with a signEvent that signs through the testing-only
 * routes/testing.php endpoint (Tests\Support\TestSigner — a throwaway
 * keypair, never a real extension or nostr-mill), and replaces WebSocket
 * entirely so nostrLogin.js's best-effort profile fetch (which reads a few
 * relays via nostr-tools' SimplePool) can never reach a real relay: every
 * connection attempt fails immediately instead of being left to time out.
 */
function stubNostrExtension(): string
{
    return <<<'JS'
        class BlockedWebSocket extends EventTarget {
            constructor(url) {
                super();
                this.url = url;
                this.readyState = 3; // CLOSED
                setTimeout(() => {
                    this.dispatchEvent(new Event('error'));
                    if (this.onerror) this.onerror(new Event('error'));
                    this.dispatchEvent(new Event('close'));
                    if (this.onclose) this.onclose(new CloseEvent('close'));
                }, 0);
            }
            close() {}
            send() {}
        }
        window.WebSocket = BlockedWebSocket;

        window.nostr = {
            signEvent: async (draft) => {
                const cookie = document.cookie.split('; ').find((row) => row.startsWith('XSRF-TOKEN='));
                const headers = { 'Content-Type': 'application/json', Accept: 'application/json' };
                if (cookie) {
                    headers['X-XSRF-TOKEN'] = decodeURIComponent(cookie.slice('XSRF-TOKEN='.length));
                }

                const response = await fetch('/__test/nostr/sign', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers,
                    body: JSON.stringify(draft),
                });

                return response.json();
            },
        };
        JS;
}

test('nostr login: the button lands on home, the chip shows the name and short npub, then logs out', function () {
    $page = visit('/login')->page();
    $page->context()->addInitScript(stubNostrExtension());
    // Re-navigate: visit() above already loaded /login before the init
    // script existed, and window.nostr has to be there before nostrLogin.js
    // asks hasNostrExtension() on this same document.
    $page->goto(ComputeUrl::from('/login'));

    // Not getByTestId(): this app's data-test attribute (throughout the
    // blade views) is not Playwright's default data-testid, and Pest's
    // Page::getByTestId() only ever checks the latter.
    $page->locator('[data-test="login-nostr"]')->click();
    BrowserWait::until($page, '() => window.location.pathname === "/"', 10_000);

    $user = User::query()->sole();

    $chip = $page->locator('[data-test="account-chip"]');
    $chipText = $chip->textContent();

    expect($chipText)->toContain($user->displayName())
        ->toContain($user->shortNpub());

    // Log out: open the chip's dropdown, then the "Log out" menu item. Flux
    // menu items are ARIA menuitems, not buttons, even though the
    // underlying element is a <button type="submit">.
    $chip->click();
    $page->getByRole('menuitem', ['name' => __('Log out')])->click();
    BrowserWait::until($page, '() => document.querySelector("[data-test=account-chip]") === null', 10_000);

    expect($page->evaluate('() => document.querySelector("[data-test=account-chip]")'))->toBeNull();
});
