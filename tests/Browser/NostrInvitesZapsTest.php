<?php

use App\Models\ChessGame;
use App\Models\User;
use App\Support\Nostr\HostResolver;
use App\Support\Wallet\NwcCipher;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use swentel\nostr\Encryption\Nip44;
use Tests\Support\Bolt11Fixture;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;
use Tests\Support\TestSigner;
use Tests\Support\WaitForPort;
use WebSocket\Client;
use WebSocket\Message\Text;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| Nostrfy: invite, zaps, NIP-05 (P47)
|--------------------------------------------------------------------------
|
| Against a local `nak serve` relay (never a public one), with a throwaway
| key behind the stubbed window.nostr, at 375 and 1440 px, in English and
| German:
|
| - "Your follows here" on /me: the player's kind 3 read from the relay,
|   who plays here listed, the invite picker with names from kind 0 and the
|   preview with the personal link;
| - the invite DM: NIP-17 on the click to a follow with a DM relay list,
|   nothing to one without until the yes for exactly that person, then NIP-04;
| - zap the winner: a finished game, preview, signed zap request, the
|   invoice as a QR code from a faked LNURL server; the address never as text;
| - the NIP-05 settings: claim a name, the address shown.
|
| Every page: the section's targets at least 44 x 44 px, no horizontal
| overflow, the console clean and no response of 400 or more, with a positive
| control. P47_SHOTS=<dir> writes screenshots there.
|
*/

beforeEach(function () {
    config(['session.driver' => 'database']);
    app()->rebinding('request', function ($app): void {
        $app['session']->forgetDrivers();
        $app->forgetInstance('session.store');
        $app->forgetInstance('auth.driver');
        $app['auth']->forgetGuards();
        $app['livewire']->flushState();
    });

    $this->port = (int) Process::run(['php', '-r', '$s = stream_socket_server("tcp://127.0.0.1:0"); echo explode(":", stream_socket_get_name($s, false))[1];'])->output();
    $this->relay = Process::start(['nak', 'serve', '--hostname', '127.0.0.1', '--port', (string) $this->port]);
    WaitForPort::open('127.0.0.1', $this->port);
    $this->relayUrl = 'ws://127.0.0.1:'.$this->port;
    config(['esports.profile_relays' => [$this->relayUrl], 'esports.relays' => [$this->relayUrl], 'esports.chat.relays' => []]);
});

afterEach(function () {
    $this->relay->stop(1);
});

function p47Page(User $user, string $to, int $width, string $locale = 'en', string $ready = 'true'): Page
{
    $page = visit(BrowserLogin::url($user))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->context()->addInitScript(TestSigner::browserStub($user));
    $page->setViewportSize($width, 900);
    $page->goto(ComputeUrl::from('/locale/'.$locale));
    $page->goto(ComputeUrl::from($to));
    BrowserWait::until($page, '() => document.readyState === "complete" && window.Alpine !== undefined && window.Livewire !== undefined && ('.$ready.')', 15_000);

    return $page;
}

function p47Shot(Page $page, string $name): void
{
    $dir = getenv('P47_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    File::ensureDirectoryExists($dir);
    $page->screenshot(true, $name);
    File::move(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
}

function p47Send(string $url, array $event): void
{
    $client = new Client($url);
    $client->setTimeout(5);
    $client->text(json_encode(['EVENT', $event]));
    $answer = $client->receive();
    $client->close();

    expect($answer instanceof Text ? json_decode($answer->getContent(), true) : null)->toMatchArray([0 => 'OK', 1 => $event['id'], 2 => true]);
}

/**
 * @return list<array<string, mixed>>
 */
function p47Query(string $url, string $args): array
{
    $out = Process::run('nak req '.$args.' '.escapeshellarg($url).' </dev/null')->output();

    return array_values(array_filter(array_map(fn (string $line) => json_decode($line, true), explode("\n", trim($out)))));
}

/** A section's visible targets (a, button, summary) below 44 px, and the page's widths. */
function p47Measure(Page $page, string $selector): array
{
    return $page->evaluate(str_replace('__SEL__', $selector, <<<'JS'
        () => {
            const section = document.querySelector('__SEL__');
            const box = section.getBoundingClientRect();
            const targets = [...section.querySelectorAll('a, button, summary, input:not([type=checkbox]), textarea')].filter((el) => el.checkVisibility())
                .map((el) => { const r = el.getBoundingClientRect(); return [el.dataset.test ?? el.textContent.trim().slice(0, 24), Math.round(r.width), Math.round(r.height), Math.round(r.right)]; });
            return {
                visible: section.checkVisibility(),
                right: Math.round(box.right),
                viewport: innerWidth,
                overflow: document.documentElement.scrollWidth > document.documentElement.clientWidth,
                small: targets.filter(([, w, h]) => w < 44 || h < 44),
                outside: targets.filter(([, , , right]) => right > innerWidth),
                count: targets.length,
            };
        }
        JS));
}

function p47Clean(Page $page, string $label): void
{
    expect([$label, $page->evaluate('() => window.__errors')])->toBe([$label, []])
        ->and([$label, $page->evaluate(BrowserConsole::BAD_RESPONSES)])->toBe([$label, []]);
}

/**
 * The viewer's lists on the relay: a relay list naming it, a kind 3 that
 * follows two league players and three strangers (names in their kind 0);
 * `dmReady` has a DM relay list, `legacy` only a read relay.
 *
 * @return array{viewer: User, signer: TestSigner, here: list<User>, dmReady: TestSigner, legacy: TestSigner, quiet: TestSigner}
 */
function p47Follows(string $relay, string $locale = 'en'): array
{
    $viewer = User::factory()->create(['name' => 'satsjaeger', 'locale' => $locale]);
    $signer = TestSigner::forBrowser($viewer);
    $viewer->refresh();
    $here = [User::factory()->create(['name' => 'Laser Eyes']), User::factory()->create(['name' => 'Hodl Hanna'])];
    [$dmReady, $legacy, $quiet] = [new TestSigner, new TestSigner, new TestSigner];
    $at = now()->getTimestamp() - 600;

    p47Send($relay, $signer->sign(10002, [['r', $relay]], '', $at));
    p47Send($relay, $signer->sign(3, [['p', $dmReady->pubkey], ['p', $here[0]->pubkey], ['p', $legacy->pubkey], ['p', $here[1]->pubkey], ['p', $quiet->pubkey]], '', $at));
    p47Send($relay, $dmReady->sign(0, [], json_encode(['name' => 'Relay Rita']), $at));
    p47Send($relay, $legacy->sign(0, [], json_encode(['display_name' => 'Legacy Lars']), $at));
    p47Send($relay, $dmReady->sign(10050, [['relay', $relay]], '', $at));
    p47Send($relay, $legacy->sign(10002, [['r', $relay, 'read']], '', $at));

    return ['viewer' => $viewer, 'signer' => $signer, 'here' => $here, 'dmReady' => $dmReady, 'legacy' => $legacy, 'quiet' => $quiet];
}

const P47_FOLLOWS_DONE = '() => document.querySelector("[data-test=follows-here]")?.dataset.state === "done" && document.querySelectorAll("[data-test=follows-here-player]").length === 2';

test('"Your follows here" and the invite picker on /me: 44 px, no overflow, a clean console, at 375 and 1440, en and de', function () {
    $lists = p47Follows($this->relayUrl);
    $viewer = $lists['viewer'];

    foreach (['en', 'de'] as $locale) {
        foreach ([375, 1440] as $width) {
            $label = "{$locale} {$width}";
            $page = p47Page($viewer, route('dashboard', absolute: false), $width, $locale);
            $page->evaluate('() => document.querySelector("[data-test=follows-here]").scrollIntoView()');
            BrowserWait::until($page, P47_FOLLOWS_DONE, 20_000);

            $names = $page->evaluate('() => [...document.querySelectorAll("[data-test=follows-here-player]")].map((li) => li.querySelector("a[data-player-card]").textContent.trim())');
            $section = p47Measure($page, '[data-test=follows-here]');
            fwrite(STDERR, "\n[p47] follows-here {$label} ".json_encode($section));

            expect([$label, $names])->toBe([$label, ['Laser Eyes', 'Hodl Hanna']])
                ->and([$label, $section['visible'], $section['overflow'], $section['small'], $section['outside']])->toBe([$label, true, false, [], []]);
            p47Shot($page, "p47-follows-here-{$locale}-{$width}");

            // The picker: the three strangers, named from their kind 0 where they have one.
            $page->locator('[data-test=follows-invite-open]')->click();
            BrowserWait::until($page, '() => document.querySelectorAll("[data-test=follows-invite-candidate]").length === 3 && document.body.innerText.includes("Legacy Lars")', 15_000);
            $candidates = $page->evaluate('() => [...document.querySelectorAll("[data-test=follows-invite-candidate]")].map((el) => el.innerText.trim().split("\n")[0])');
            expect([$label, array_slice($candidates, 0, 2)])->toBe([$label, ['Relay Rita', 'Legacy Lars']]);

            $page->locator('[data-test=follows-invite-candidate] input')->nth(0)->check();
            $page->locator('[data-test=follows-invite-preview]')->click();
            BrowserWait::until($page, '() => document.querySelector("[data-test=follows-invite-text]")?.value.includes("/i/") === true', 15_000);

            $picker = p47Measure($page, '[data-test=follows-here]');
            fwrite(STDERR, "\n[p47] invite preview {$label} ".json_encode($picker));
            expect([$label, $picker['overflow'], $picker['small'], $picker['outside']])->toBe([$label, false, [], []]);
            p47Shot($page, "p47-invite-preview-{$locale}-{$width}");

            // Nothing sent by opening, picking or previewing.
            expect(p47Query($this->relayUrl, '-k 1059 -k 4'))->toBe([]);
            p47Clean($page, $label);
        }
    }

    // Positive control: the collectors see a thrown error and a 404 on this page.
    $page->evaluate('() => { setTimeout(() => { throw new Error("control-throw"); }); fetch("/control-missing-page"); }');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("control-throw")) && window.__errors.some((e) => e.startsWith("404"))', 5_000);
});

test('the invite DM: NIP-17 on the click to a follow with DM relays, nothing to one without until the yes for them, then NIP-04', function () {
    $lists = p47Follows($this->relayUrl);
    $viewer = $lists['viewer'];

    $page = p47Page($viewer, route('dashboard', absolute: false), 1440);
    $page->evaluate('() => document.querySelector("[data-test=follows-here]").scrollIntoView()');
    BrowserWait::until($page, P47_FOLLOWS_DONE, 20_000);
    $page->locator('[data-test=follows-invite-open]')->click();
    BrowserWait::until($page, '() => document.body.innerText.includes("Legacy Lars") && document.body.innerText.includes("Relay Rita")', 15_000);

    $page->locator('[data-test=follows-invite-candidate] input')->nth(0)->check();
    $page->locator('[data-test=follows-invite-candidate] input')->nth(1)->check();
    $page->locator('[data-test=follows-invite-preview]')->click();
    BrowserWait::until($page, '() => document.querySelector("[data-test=follows-invite-text]")?.value.includes("/i/") === true', 15_000);
    $text = $page->evaluate('() => document.querySelector("[data-test=follows-invite-text]").value');

    expect(p47Query($this->relayUrl, '-k 1059 -k 4'))->toBe([]);

    $page->evaluate('() => { window.__warns = []; const warn = console.warn; console.warn = (...args) => { window.__warns.push(args.map(String).join(" ")); warn.apply(console, args); }; }');
    $page->locator('[data-test=follows-invite-send]')->click();
    BrowserWait::until($page, '() => [...document.querySelectorAll("[data-test=follows-invite-recipient]")].every((li) => li.dataset.status !== "waiting")', 30_000);
    $statuses = $page->evaluate('() => [...document.querySelectorAll("[data-test=follows-invite-recipient]")].map((li) => li.dataset.status)');
    fwrite(STDERR, "\n[p47] invite statuses ".json_encode($statuses).' warns '.json_encode($page->evaluate('() => window.__warns')));
    expect($statuses)->toBe(['sent', 'confirm'])
        ->and($page->evaluate('() => [...document.querySelectorAll("[data-test=follows-invite-confirm]")].map((el) => el.checkVisibility())'))->toBe([false, true]);
    p47Shot($page, 'p47-invite-confirm-nip04-1440');

    // Rita: one gift wrap, which she opens with her key; Lars: nothing yet.
    $wraps = p47Query($this->relayUrl, '-k 1059 -t p='.$lists['dmReady']->pubkey);
    expect($wraps)->toHaveCount(1)
        ->and(p47Query($this->relayUrl, '-k 4 -a '.$viewer->pubkey))->toBe([]);
    $seal = json_decode(Nip44::decrypt($wraps[0]['content'], Nip44::getConversationKey($lists['dmReady']->secret, $wraps[0]['pubkey'])), true);
    $rumor = json_decode(Nip44::decrypt($seal['content'], Nip44::getConversationKey($lists['dmReady']->secret, $seal['pubkey'])), true);
    expect($seal['pubkey'])->toBe($viewer->pubkey)->and($rumor['content'])->toBe($text);

    // The yes for Lars: one kind 4 to him, with the link.
    $page->locator('[data-test=follows-invite-recipient][data-status=confirm] [data-test=follows-invite-nip04]')->click();
    BrowserWait::until($page, '() => document.querySelector("[data-test=follows-invite-close]")?.checkVisibility() === true', 20_000);
    $dms = p47Query($this->relayUrl, '-k 4 -a '.$viewer->pubkey);

    expect($dms)->toHaveCount(1)
        ->and($dms[0]['tags'])->toBe([['p', $lists['legacy']->pubkey]])
        ->and(NwcCipher::decrypt(NwcCipher::NIP04, $dms[0]['content'], $lists['legacy']->secret, $viewer->pubkey))->toBe($text)
        ->and($page->evaluate('() => [...document.querySelectorAll("[data-test=follows-invite-recipient]")].map((li) => li.dataset.status)'))->toBe(['sent', 'sent']);
    p47Shot($page, 'p47-invite-sent-1440');
    p47Clean($page, 'invite dm');
});

test('zap the winner: preview, signed zap request, the invoice as a QR code, no address as text, at 375 and 1440, en and de', function () {
    config(['esports.wallet.invoice_networks' => ['bcrt']]);
    app()->instance(HostResolver::class, new class extends HostResolver
    {
        public function addresses(string $host): array
        {
            return ['93.184.215.14'];
        }
    });
    Http::fake(function (Request $request) {
        $url = parse_url($request->url());

        if (($url['host'] ?? '') !== 'wallet.example') {
            return Http::response([], 404);
        }

        if (str_starts_with($url['path'] ?? '', '/.well-known/lnurlp/')) {
            return Http::response(['tag' => 'payRequest', 'callback' => 'https://wallet.example/cb', 'minSendable' => 1000, 'maxSendable' => 100_000_000_000,
                'metadata' => '[["text/plain","tip"]]', 'allowsNostr' => true, 'nostrPubkey' => str_repeat('ab', 32)]);
        }

        parse_str($url['query'] ?? '', $query);

        return Http::response(['pr' => Bolt11Fixture::make((int) $query['amount'], hash('sha256', (string) $query['nostr']), network: 'bcrt')['invoice']]);
    });

    $winner = User::factory()->create(['name' => 'Laser Eyes', 'lud16' => 'lasereyes@wallet.example']);
    $viewer = User::factory()->create(['name' => 'satsjaeger']);
    TestSigner::forBrowser($viewer);
    $viewer->refresh();
    $game = ChessGame::factory()->finished('1-0')->create(['white_id' => $winner->id, 'black_id' => User::factory()->create()->id, 'ply' => 20]);

    foreach (['en', 'de'] as $locale) {
        foreach ([375, 1440] as $width) {
            $label = "{$locale} {$width}";
            $page = p47Page($viewer, route('games.show', $game, false), $width, $locale, 'document.querySelector("[data-test=zap-winner]") !== null');
            $page->evaluate('() => document.querySelector("[data-test=zap-winner]").scrollIntoView()');
            $page->locator('[data-test=zap-open]')->click();
            BrowserWait::until($page, '() => document.querySelector("[data-test=zap-panel]")?.checkVisibility() === true', 5_000);
            $page->locator('[data-test=zap-amount]')->nth(1)->click();
            $page->locator('[data-test=zap-preview-button]')->click();
            BrowserWait::until($page, '() => document.querySelector("[data-test=zap-preview]")?.checkVisibility() === true', 10_000);
            p47Shot($page, "p47-zap-preview-{$locale}-{$width}");

            $page->locator('[data-test=zap-sign]')->click();
            BrowserWait::until($page, '() => document.querySelector("[data-test=zap-invoice-qr] svg")?.checkVisibility() === true', 15_000);

            $section = p47Measure($page, '[data-test=zap-winner]');
            fwrite(STDERR, "\n[p47] zap {$label} ".json_encode($section));
            expect([$label, $section['visible'], $section['overflow'], $section['small'], $section['outside']])->toBe([$label, true, false, [], []])
                ->and($page->evaluate('() => document.querySelector("[data-test=zap-open-wallet]").getAttribute("href").startsWith("lightning:lnbcrt210")'))->toBeTrue()
                ->and($page->evaluate('() => document.documentElement.outerHTML.includes("lasereyes@") || document.documentElement.outerHTML.includes("wallet.example")'))->toBeFalse();
            p47Shot($page, "p47-zap-invoice-{$locale}-{$width}");
            p47Clean($page, "zap {$label}");
        }
    }

    // The LNURL server got exactly the signed zap request: kind 9734 by the viewer, 210 sats, to the winner.
    Http::assertSent(function (Request $request) use ($viewer, $winner): bool {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $zap = json_decode((string) ($query['nostr'] ?? 'null'), true);

        return is_array($zap) && $zap['kind'] === 9734 && $zap['pubkey'] === $viewer->pubkey && $query['amount'] === '210000'
            && in_array(['p', $winner->pubkey], $zap['tags'], true);
    });
});

test('the NIP-05 settings: claim a name and see the address, at 375 and 1440, en and de', function () {
    $viewer = User::factory()->create(['name' => 'satsjaeger']);
    TestSigner::forBrowser($viewer);
    $viewer->refresh();

    foreach (['en', 'de'] as $locale) {
        foreach ([375, 1440] as $width) {
            $label = "{$locale} {$width}";
            // A query, not the model: the model in memory never held the name, so save() would write nothing.
            User::query()->whereKey($viewer->id)->update(['nip05_name' => null, 'nip05_changed_at' => null]);
            $page = p47Page($viewer, route('settings.nip05', absolute: false), $width, $locale, 'document.querySelector("[data-test=nip05-input]") !== null');

            $page->locator('[data-test=nip05-input]')->fill('Sats.Jaeger');
            $page->locator('[data-test=nip05-save]')->click();
            BrowserWait::until($page, '() => document.querySelector("[data-test=nip05-address]") !== null', 10_000);

            $section = p47Measure($page, '[data-test=nip05-settings]');
            fwrite(STDERR, "\n[p47] nip05 {$label} ".json_encode($section));
            expect($page->evaluate('() => document.querySelector("[data-test=nip05-address]").textContent.trim()'))->toBe('sats.jaeger@'.parse_url(config('app.url'), PHP_URL_HOST))
                ->and([$label, $section['overflow'], $section['small'], $section['outside']])->toBe([$label, false, [], []]);
            p47Shot($page, "p47-nip05-{$locale}-{$width}");
            p47Clean($page, "nip05 {$label}");
        }
    }

    // The document answers it.
    expect($this->get(route('nostr.nip05', ['name' => 'sats.jaeger']))->json('names'))->toBe(['sats.jaeger' => $viewer->pubkey]);
});
