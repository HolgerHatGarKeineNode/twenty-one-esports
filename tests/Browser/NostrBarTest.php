<?php

use App\Enums\TournamentFormat;
use App\Models\ChessGame;
use App\Models\Clan;
use App\Models\Lineup;
use App\Models\NostrEvent;
use App\Models\SeriesMatch;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Nostr\SignedEvent;
use App\Support\Notifications\NotificationDm;
use App\Support\Wallet\NwcCipher;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use swentel\nostr\Encryption\Nip44;
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
| The Nostr bar (P45)
|--------------------------------------------------------------------------
|
| Against a local `nak serve` relay (never a public one), with a throwaway
| key behind the stubbed window.nostr:
|
| - Every kind of page with a Nostr object (player, clan, tournament, game,
|   series match, match room, season, stream) at 375 and 1440 px: the bar is
|   there, every button is at least 44 x 44 px, no horizontal overflow, the
|   console is clean and no response is 400 or more (with a positive control).
| - Follow: the preview counts the player's existing follows, the signed kind
|   3 on the relay keeps all of them and adds one; with the relay down the
|   panel refuses and nothing reaches any relay.
| - Message: to a player with a DM relay list a NIP-17 gift wrap, to one
|   without a NIP-04 kind 4; the recipient opens both with their key.
|
| NOSTR_BAR_SHOTS=<dir> additionally writes the English screenshots there.
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

    $this->port = (int) Process::run(['php', '-r', '$s = stream_socket_server("tcp://127.0.0.1:0"); echo explode(":", stream_socket_get_name($s, false))[1];'])->output();
    $this->relay = Process::start(['nak', 'serve', '--hostname', '127.0.0.1', '--port', (string) $this->port]);
    WaitForPort::open('127.0.0.1', $this->port);
    $this->relayUrl = 'ws://127.0.0.1:'.$this->port;
    // What the bar reads and publishes to: the local relay only.
    config(['esports.profile_relays' => [$this->relayUrl], 'esports.relays' => [$this->relayUrl], 'esports.chat.relays' => []]);
});

afterEach(function () {
    $this->relay->stop(1);
});

function nostrBarPage(User $user, string $to, int $width): Page
{
    $page = visit(BrowserLogin::url($user))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->context()->addInitScript(TestSigner::browserStub($user));
    $page->setViewportSize($width, 900);
    $page->goto(ComputeUrl::from($to));
    BrowserWait::until($page, '() => document.readyState === "complete" && window.Alpine !== undefined && document.querySelector("[data-test=nostr-bar]") !== null', 15_000);

    return $page;
}

function nostrBarShot(Page $page, string $name): void
{
    $dir = getenv('NOSTR_BAR_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    File::ensureDirectoryExists($dir);
    $page->screenshot(true, $name);
    File::move(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
}

/** Send one signed event to the relay and wait for its OK. */
function nostrBarSend(string $url, array $event): void
{
    $client = new Client($url);
    $client->setTimeout(5);
    $client->text(json_encode(['EVENT', $event]));
    $answer = $client->receive();
    $client->close();

    expect($answer instanceof Text ? json_decode($answer->getContent(), true) : null)->toMatchArray([0 => 'OK', 1 => $event['id'], 2 => true]);
}

/**
 * What the relay holds for a filter, read with nak (stdin closed: without it `nak req` answers nothing).
 *
 * @return list<array<string, mixed>>
 */
function nostrBarQuery(string $url, string $args): array
{
    $out = Process::run('nak req '.$args.' '.escapeshellarg($url).' </dev/null')->output();

    return array_values(array_filter(array_map(fn (string $line) => json_decode($line, true), explode("\n", trim($out)))));
}

/** The bar's box, the size of every visible target in it, and the document's widths. */
const NOSTR_BAR_MEASURE = <<<'JS'
    () => {
        const bar = document.querySelector('[data-test=nostr-bar]');
        const box = bar.getBoundingClientRect();
        const targets = [...bar.querySelectorAll('a, button')].filter((el) => el.checkVisibility())
            .map((el) => { const r = el.getBoundingClientRect(); return [el.dataset.test ?? el.textContent.trim().slice(0, 24), Math.round(r.width), Math.round(r.height)]; });
        return {
            visible: bar.checkVisibility(),
            left: Math.round(box.left), right: Math.round(box.right), height: Math.round(box.height),
            viewport: innerWidth,
            scroll: [document.documentElement.scrollWidth, document.documentElement.clientWidth],
            targets,
            small: targets.filter(([, w, h]) => w < 44 || h < 44),
        };
    }
    JS;

test('the bar is on every kind of page, with 44 px targets, no overflow and a clean console, at 375 and 1440', function () {
    $viewer = User::factory()->create(['name' => 'satsjaeger', 'locale' => 'en']);
    TestSigner::forBrowser($viewer);
    $viewer->refresh();

    $player = User::factory()->create(['name' => 'Laser Eyes', 'lud16' => 'lasereyes@walletofsatoshi.com', 'profile_event_at' => now()]);
    $clanOwner = User::factory()->create(['name' => 'Hodl Clan Owner']);
    $clan = Clan::factory()->create(['owner_id' => $clanOwner->id, 'owner_pubkey' => $clanOwner->pubkey, 'name' => 'Stacking Sats Club']);

    $league = new TestSigner;
    $tournament = Tournament::factory()->rocketLeague(TournamentFormat::DoubleElimination)->signup()->create(['created_by_id' => $player->id, 'slug' => 'nostr-bar-cup']);
    $tournament->forceFill(['published_at' => now(), 'event_id' => NostrEvent::fromSigned(SignedEvent::fromInput($league->sign(31923, [['d', 'nostr-bar-cup']])))->id])->save();

    $game = ChessGame::factory()->rated()->finished('1-0')->create(['white_id' => $viewer->id, 'black_id' => $player->id]);
    $game->forceFill(['record_event_id' => NostrEvent::fromSigned(SignedEvent::fromInput($league->sign(64)))->id])->save();

    $ownClan = Clan::factory()->create(['owner_id' => $viewer->id, 'owner_pubkey' => $viewer->pubkey]);
    $match = SeriesMatch::factory()->accepted()->create([
        'challenger_lineup_id' => Lineup::factory()->mode('2v2')->ready()->create(['clan_id' => $ownClan->id])->id,
        'challenged_lineup_id' => Lineup::factory()->mode('2v2')->ready()->create(['clan_id' => $clan->id])->id,
        'challenge_event_id' => NostrEvent::fromSigned(SignedEvent::fromInput($league->sign(2150)))->id,
    ]);
    openSeason();

    $pages = [
        'player' => route('players.show', $player->npub, false),
        'clan' => route('clans.show', $clan, false),
        'tournament' => route('tournaments.show', $tournament, false),
        'game' => route('games.show', $game, false),
        'match' => route('matches.show', $match->number, false),
        'room' => route('matches.room', $match, false),
        'season' => route('mining', absolute: false),
        'stream' => route('live', absolute: false),
    ];

    foreach ([375, 1440] as $width) {
        foreach ($pages as $name => $to) {
            $page = nostrBarPage($viewer, $to, $width);
            $bar = $page->evaluate(NOSTR_BAR_MEASURE);
            fwrite(STDERR, "\n[nostr-bar] {$name} {$width}px ".json_encode($bar));
            nostrBarShot($page, "nostr-bar-{$name}-{$width}");

            // Open the share panel too: its long nostr: link must not widen the page.
            $page->locator('[data-test=nostr-share]')->click();
            BrowserWait::until($page, '() => document.querySelector("[data-test=nostr-share-panel]").checkVisibility()', 5_000);
            $withPanel = $page->evaluate(BrowserConsole::WIDTHS);

            expect([$name, $width, $bar['visible'], $bar['small']])->toBe([$name, $width, true, []])
                ->and([$name, $width, $bar['scroll'][0] <= $bar['scroll'][1], $withPanel[0] <= $withPanel[1]])->toBe([$name, $width, true, true])
                ->and([$name, $width, $bar['right'] <= $bar['viewport']])->toBe([$name, $width, true])
                ->and([$name, $width, $page->evaluate('() => window.__errors')])->toBe([$name, $width, []])
                ->and([$name, $width, $page->evaluate(BrowserConsole::BAD_RESPONSES)])->toBe([$name, $width, []]);
        }
    }

    // The player page offers everything, the zap as a QR code only.
    $page = nostrBarPage($viewer, $pages['player'], 375);
    expect($page->evaluate('() => [...document.querySelectorAll("[data-test=nostr-bar] [data-test^=nostr-]")].map((el) => el.dataset.test).filter((t) => ["nostr-open", "nostr-share", "nostr-message", "nostr-zap", "nostr-follow"].includes(t))'))
        ->toBe(['nostr-open', 'nostr-share', 'nostr-message', 'nostr-zap', 'nostr-follow'])
        ->and($page->evaluate('() => document.body.innerText.includes("lasereyes@")'))->toBeFalse();
    $page->locator('[data-test=nostr-zap]')->click();
    BrowserWait::until($page, '() => document.querySelector("[data-test=nostr-zap-qr] svg")?.checkVisibility() === true', 5_000);
    nostrBarShot($page, 'nostr-bar-zap-375');

    // Positive control: the same collectors see a thrown error, a 404 fetch and a broken image.
    $page->evaluate('() => { setTimeout(() => { throw new Error("control-throw"); }); fetch("/control-missing-page"); document.body.append(Object.assign(new Image(), { src: "/control-missing.png" })); }');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("control-throw")) && window.__errors.some((e) => e.startsWith("404"))', 5_000);
    BrowserWait::until($page, '() => ('.BrowserConsole::BAD_RESPONSES.')().some((e) => e.includes("control-missing.png"))', 5_000);
});

test('follow: the preview counts the existing follows, the signed list keeps them all and adds one; a relay that is down refuses', function () {
    $viewer = User::factory()->create(['locale' => 'en']);
    $signer = TestSigner::forBrowser($viewer);
    $viewer->refresh();
    $player = User::factory()->create(['name' => 'Laser Eyes']);

    // The viewer's follow list, written by another client, on the relay only.
    $old = array_map(fn () => ['p', (new TestSigner)->pubkey], range(1, 3));
    nostrBarSend($this->relayUrl, $signer->sign(3, [...$old, ['t', 'kept']], '', now()->getTimestamp() - 3600));
    // Where it lives: the viewer's relay list (audit F2: without one, follow refuses).
    nostrBarSend($this->relayUrl, $signer->sign(10002, [['r', $this->relayUrl]], '', now()->getTimestamp() - 3600));

    $page = nostrBarPage($viewer, route('players.show', $player->npub, false), 1440);
    $page->locator('[data-test=nostr-follow]')->click();
    BrowserWait::until($page, '() => document.querySelector("[data-test=nostr-follow-preview]") !== null', 15_000);

    expect($page->evaluate('() => document.querySelector("[data-test=nostr-follow-preview]").textContent.trim()'))
        ->toBe('You follow 3 accounts. After this: 4, with Laser Eyes added. Nothing else changes.')
        // Nothing signed yet: only the old list is on the relay.
        ->and(nostrBarQuery($this->relayUrl, '-k 3 -a '.$viewer->pubkey))->toHaveCount(1);
    nostrBarShot($page, 'nostr-bar-follow-preview-1440');

    $page->locator('[data-test=nostr-follow-sign]')->click();
    BrowserWait::until($page, '() => document.querySelector("[data-test=nostr-follow-done]")?.checkVisibility() === true', 15_000);

    $lists = nostrBarQuery($this->relayUrl, '-k 3 -a '.$viewer->pubkey);
    expect($lists)->toHaveCount(1)
        ->and($lists[0]['tags'])->toBe([...$old, ['t', 'kept'], ['p', $player->pubkey]])
        ->and($page->evaluate('() => window.__errors'))->toBe([]);

    // Reopened: already followed.
    $page = nostrBarPage($viewer, route('players.show', $player->npub, false), 375);
    $page->locator('[data-test=nostr-follow]')->click();
    BrowserWait::until($page, '() => document.querySelector("[data-test=nostr-follow-already]")?.checkVisibility() === true', 15_000);

    // The relay down: refused, and the list on the relay is untouched.
    $other = User::factory()->create(['name' => 'Offline Olga']);
    config(['esports.profile_relays' => ['ws://127.0.0.1:9'], 'esports.relays' => ['ws://127.0.0.1:9']]);
    $page = nostrBarPage($viewer, route('players.show', $other->npub, false), 375);
    $page->locator('[data-test=nostr-follow]')->click();
    BrowserWait::until($page, '() => document.querySelector("[data-test=nostr-follow-panel]")?.dataset.step === "refused"', 15_000);

    expect($page->evaluate('() => document.querySelector("[data-test=nostr-follow-error]").textContent.trim()'))->toStartWith('Your follow list could not be read from all your relays')
        ->and($page->evaluate('() => document.querySelector("[data-test=nostr-follow-sign]")'))->toBeNull();
    nostrBarShot($page, 'nostr-bar-follow-refused-375');

    $after = nostrBarQuery($this->relayUrl, '-k 3 -a '.$viewer->pubkey);
    expect($after)->toHaveCount(1)->and($after[0]['id'])->toBe($lists[0]['id']);

    // Audit F2: a player whose relay list nobody has, with a follow list on the relay: refused, the list untouched.
    config(['esports.profile_relays' => [$this->relayUrl], 'esports.relays' => [$this->relayUrl]]);
    $noList = User::factory()->create(['locale' => 'en']);
    $noListSigner = TestSigner::forBrowser($noList);
    $noList->refresh();
    nostrBarSend($this->relayUrl, $noListSigner->sign(3, $old, '', now()->getTimestamp() - 60));
    $page = nostrBarPage($noList, route('players.show', $other->npub, false), 1440);
    $page->locator('[data-test=nostr-follow]')->click();
    BrowserWait::until($page, '() => document.querySelector("[data-test=nostr-follow-panel]")?.dataset.step === "refused"', 15_000);

    // Re-audit: a new identity (no relay list, no follow list anywhere read) starts a list only after "Start a new list".
    $fresh = User::factory()->create(['locale' => 'en']);
    TestSigner::forBrowser($fresh);
    $fresh->refresh();
    $freshPage = nostrBarPage($fresh, route('players.show', $other->npub, false), 375);
    $freshPage->locator('[data-test=nostr-follow]')->click();
    BrowserWait::until($freshPage, '() => document.querySelector("[data-test=nostr-follow-new-list]")?.checkVisibility() === true', 15_000);

    expect($freshPage->evaluate('() => document.querySelector("[data-test=nostr-follow-new-list-warning]").textContent.trim()'))
        ->toBe('We found no follow list of yours on the relays we read. Following here starts a NEW list with only this person. If you already follow people, follow from your usual client instead.')
        ->and($freshPage->evaluate('() => document.querySelector("[data-test=nostr-follow-sign]").checkVisibility()'))->toBeFalse()
        ->and(nostrBarQuery($this->relayUrl, '-k 3 -a '.$fresh->pubkey))->toBe([]);
    nostrBarShot($freshPage, 'nostr-bar-follow-new-list-375');

    $freshPage->locator('[data-test=nostr-follow-new-list]')->click();
    BrowserWait::until($freshPage, '() => document.querySelector("[data-test=nostr-follow-done]")?.checkVisibility() === true', 15_000);
    $started = nostrBarQuery($this->relayUrl, '-k 3 -a '.$fresh->pubkey);
    expect($started)->toHaveCount(1)
        ->and($started[0]['tags'])->toBe([['p', $other->pubkey]])
        ->and($freshPage->evaluate('() => window.__errors'))->toBe([]);

    expect($page->evaluate('() => document.querySelector("[data-test=nostr-follow-error]").textContent.trim()'))->toStartWith('No relay list of yours (NIP-65, kind 10002) was found')
        ->and(nostrBarQuery($this->relayUrl, '-k 3 -a '.$noList->pubkey))->toHaveCount(1)
        ->and(count(nostrBarQuery($this->relayUrl, '-k 3 -a '.$noList->pubkey)[0]['tags']))->toBe(3);
});

test('"Send a test DM" in the notification settings delivers a NIP-17 DM to the player\'s DM relays and says so, at 375 and 1440', function () {
    config([
        'esports.notifications.nsec' => bin2hex(random_bytes(32)),
        // The local relay is a ws:// on 127.0.0.1: RelayGuard reaches it only when listed here.
        'esports.wallet.nwc_insecure_relays' => ['127.0.0.1:'.$this->port],
    ]);
    $viewer = User::factory()->create(['locale' => 'en']);
    $signer = TestSigner::forBrowser($viewer);
    $viewer->refresh();
    nostrBarSend($this->relayUrl, $signer->sign(10050, [['relay', $this->relayUrl]]));

    foreach ([375, 1440] as $width) {
        $page = visit(BrowserLogin::url($viewer))->page();
        $page->context()->addInitScript(BrowserConsole::COLLECTOR);
        $page->setViewportSize($width, 900);
        $page->goto(ComputeUrl::from(route('settings.notifications', absolute: false)));
        BrowserWait::until($page, '() => document.querySelector("[data-test=send-test-dm]") !== null && window.Livewire !== undefined', 15_000);

        $row = $page->evaluate('() => { const b = document.querySelector("[data-test=send-test-dm]").getBoundingClientRect(); return [Math.round(b.width), Math.round(b.height), Math.round(b.right)]; }');
        expect($row[1])->toBeGreaterThanOrEqual(44)->and($row[0])->toBeGreaterThanOrEqual(44)->and($row[2])->toBeLessThanOrEqual($width)
            ->and($page->evaluate(BrowserConsole::WIDTHS)[0])->toBeLessThanOrEqual($width);

        // P45 digest: one "at once / daily" select per kind that goes out by DM, each 44 px high and inside the page.
        $selects = $page->evaluate('() => [...document.querySelectorAll("[data-test=dm-timing] select")].map((s) => { const b = s.getBoundingClientRect(); return [Math.round(b.height), Math.round(b.right)]; })');
        fwrite(STDERR, "\n[nostr-bar] dm-timing {$width}px ".count($selects).' selects, first '.json_encode($selects[0] ?? null));
        expect($selects)->not->toBe([])
            ->and(array_filter($selects, fn (array $s): bool => $s[0] < 44 || $s[1] > $width))->toBe([]);

        if ($width === 375) {
            $page->locator('[data-test=send-test-dm]')->click();
            BrowserWait::until($page, '() => document.querySelector("[data-test=test-dm-status]")?.dataset.state === "done"', 20_000);

            expect($page->evaluate('() => document.querySelector("[data-test=test-dm-status]").dataset.format'))->toBe('nip17')
                ->and($page->evaluate('() => document.querySelector("[data-test=test-dm-status]").textContent.trim()'))->toBe('Sent as a NIP-17 DM to your DM relays: 1 of 1 relays took it. Look in your Nostr app.');
            nostrBarShot($page, 'nostr-bar-test-dm-375');

            // A second click within the minute is refused, nothing more is sent.
            $page->locator('[data-test=send-test-dm]')->click();
            BrowserWait::until($page, '() => document.body.innerText.includes("One test DM a minute")', 10_000);
        } else {
            nostrBarShot($page, 'nostr-bar-test-dm-1440');
        }

        expect($page->evaluate('() => window.__errors'))->toBe([])
            ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
    }

    // On the relay: one gift wrap to the player, from the notification key inside.
    $wraps = nostrBarQuery($this->relayUrl, '-k 1059 -t p='.$viewer->pubkey);
    expect($wraps)->toHaveCount(1);
    $seal = json_decode(Nip44::decrypt($wraps[0]['content'], Nip44::getConversationKey($signer->secret, $wraps[0]['pubkey'])), true);
    expect($seal['pubkey'])->toBe(NotificationDm::fromConfig()->pubkey());
});

test('message: NIP-17 to a player with a DM relay list, NIP-04 to one without; each opens with the recipient key', function () {
    $viewer = User::factory()->create(['locale' => 'en']);
    TestSigner::forBrowser($viewer);
    $viewer->refresh();

    $withListKey = new TestSigner;
    $withList = User::factory()->withPubkey($withListKey->pubkey)->create(['name' => 'Relay Rita']);
    nostrBarSend($this->relayUrl, $withListKey->sign(10050, [['relay', $this->relayUrl]]));

    $page = nostrBarPage($viewer, route('players.show', $withList->npub, false), 375);
    $page->locator('[data-test=nostr-message]')->click();
    $page->locator('[data-test=nostr-dm-text]')->fill('gg, rematch tomorrow?');
    nostrBarShot($page, 'nostr-bar-message-375');
    $page->locator('[data-test=nostr-dm-send]')->click();
    BrowserWait::until($page, '() => document.querySelector("[data-test=nostr-dm-sent]")?.checkVisibility() === true', 15_000);

    $wraps = nostrBarQuery($this->relayUrl, '-k 1059 -t p='.$withList->pubkey);
    expect($wraps)->toHaveCount(1);
    $seal = json_decode(Nip44::decrypt($wraps[0]['content'], Nip44::getConversationKey($withListKey->secret, $wraps[0]['pubkey'])), true);
    $rumor = json_decode(Nip44::decrypt($seal['content'], Nip44::getConversationKey($withListKey->secret, $seal['pubkey'])), true);
    expect($seal['pubkey'])->toBe($viewer->pubkey)
        ->and($rumor['content'])->toBe('gg, rematch tomorrow?')
        ->and($rumor['tags'])->toBe([['p', $withList->pubkey]]);

    // No DM relay list: NIP-04.
    $withoutKey = new TestSigner;
    $without = User::factory()->withPubkey($withoutKey->pubkey)->create(['name' => 'Legacy Lars']);
    $page = nostrBarPage($viewer, route('players.show', $without->npub, false), 1440);
    $page->locator('[data-test=nostr-message]')->click();
    $page->locator('[data-test=nostr-dm-text]')->fill('hi from the league page');
    $page->locator('[data-test=nostr-dm-send]')->click();

    // Audit F3: never a silent downgrade. The sender is asked, with the confirmed reason, and nothing is out yet.
    BrowserWait::until($page, '() => document.querySelector("[data-test=nostr-dm-confirm]")?.checkVisibility() === true', 15_000);
    expect($page->evaluate('() => document.querySelector("[data-test=nostr-dm-confirm]").innerText.trim()'))->toStartWith('Every relay asked answered, and Legacy Lars has no DM relay list')
        ->and(nostrBarQuery($this->relayUrl, '-k 4 -a '.$viewer->pubkey))->toBe([]);
    nostrBarShot($page, 'nostr-bar-message-confirm-nip04-1440');
    $page->locator('[data-test=nostr-dm-send-nip04]')->click();
    BrowserWait::until($page, '() => document.querySelector("[data-test=nostr-dm-sent-nip04]")?.checkVisibility() === true', 15_000);

    $dms = nostrBarQuery($this->relayUrl, '-k 4 -a '.$viewer->pubkey);
    expect($dms)->toHaveCount(1)
        ->and($dms[0]['tags'])->toBe([['p', $without->pubkey]])
        ->and(NwcCipher::decrypt(NwcCipher::NIP04, $dms[0]['content'], $withoutKey->secret, $viewer->pubkey))->toBe('hi from the league page')
        ->and($page->evaluate('() => window.__errors'))->toBe([])
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
});
