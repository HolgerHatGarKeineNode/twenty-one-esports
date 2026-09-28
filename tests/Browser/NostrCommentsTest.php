<?php

use App\Models\ChessGame;
use App\Models\NostrEvent;
use App\Models\User;
use App\Support\Nostr\SignedEvent;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
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
| Comments, likes and RSVPs on Nostr (P48)
|--------------------------------------------------------------------------
|
| Against a local `nak serve` relay (league, chat and profile relays all in
| one), with a throwaway key behind the stubbed window.nostr:
|
| - the tournament page reads the comments from the relay: a stranger's
|   comment with an HTML payload shows as text and runs nothing, a comment
|   by a pubkey the league muted (kind 44) is left out, "Load more" reads the
|   next page; the RSVP line counts the relay's RSVPs apart from sign-ups;
| - a comment, a like and an RSVP ("going", then "not going" after pulling
|   out) are shown as the event first; nothing is signed before the click,
|   and each click signs exactly once and reaches the relay;
| - tournament, sign-up and game page at 375 and 1440 px, en and de: no
|   horizontal overflow, the buttons 44 px high, a clean console and no
|   response of 400 or more (with a positive control).
|
| COMMENT_SHOTS=<dir> writes the screenshots there.
|
*/

/** Counts every call of the stubbed signer across reloads of the tab (as ShareTest). */
const COMMENTS_SIGN_COUNTER = <<<'JS'
    (() => {
        const sign = window.nostr?.signEvent;
        if (!sign) return;
        window.nostr.signEvent = (draft) => {
            sessionStorage.setItem('__signs', String(Number(sessionStorage.getItem('__signs') ?? '0') + 1));
            return sign(draft);
        };
    })();
    JS;

/** Comments shown and their authors looked up (each row's picture has its URL). */
const COMMENTS_READY = '() => { const rows = document.querySelectorAll("[data-test=comment]"); return rows.length > 0 && [...rows].every((r) => [...r.querySelectorAll("img")].some((i) => (i.getAttribute("src") ?? "") !== "")); }';

/** Heights of the section's buttons, the overflow of the document and of the section, the language. */
const COMMENTS_LAYOUT = <<<'JS'
    (sel) => {
        const root = document.querySelector(sel);
        const visible = (n) => n && n.checkVisibility();
        const heights = root ? [...root.querySelectorAll('button, a[href]')].filter(visible).map((n) => [n.dataset.test ?? n.textContent.trim().slice(0, 20), Math.round(n.getBoundingClientRect().height)]) : null;
        return {
            lang: document.documentElement.lang,
            doc: [document.documentElement.scrollWidth, document.documentElement.clientWidth],
            box: visible(root) ? Math.round(root.getBoundingClientRect().right) : null,
            small: heights === null ? null : heights.filter(([, h]) => h < 44),
            spill: root ? [...root.querySelectorAll('*')].filter((n) => visible(n) && n.getBoundingClientRect().right > root.getBoundingClientRect().right + 1).map((n) => (n.dataset.test ?? n.tagName) + ':' + Math.round(n.getBoundingClientRect().right)) : [],
        };
    }
    JS;

beforeEach(function () {
    Http::fake(fn () => Http::response([]));
    Storage::fake('local');
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
    config(['esports.profile_relays' => [$this->relayUrl], 'esports.relays' => [$this->relayUrl], 'esports.chat.relays' => [$this->relayUrl], 'esports.comments.page' => 3]);
});

afterEach(function () {
    $this->relay->stop(1);
});

function commentsSend(string $url, array $event): void
{
    $client = new Client($url);
    $client->setTimeout(5);
    $client->text(json_encode(['EVENT', $event]));
    $answer = $client->receive();
    $client->close();

    expect($answer instanceof Text ? json_decode($answer->getContent(), true) : null)->toMatchArray([0 => 'OK', 1 => $event['id'], 2 => true]);
}

/** @return list<array<string, mixed>> */
function commentsQuery(string $url, string $args): array
{
    $out = Process::run('nak req '.$args.' '.escapeshellarg($url).' </dev/null')->output();

    return array_values(array_filter(array_map(fn (string $line) => json_decode($line, true), explode("\n", trim($out)))));
}

function commentsPage(User $user, string $to, int $width, string $locale): Page
{
    $page = visit(BrowserLogin::url($user))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->context()->addInitScript(TestSigner::browserStub($user));
    $page->context()->addInitScript(COMMENTS_SIGN_COUNTER);
    $page->setViewportSize($width, 900);
    $page->goto(ComputeUrl::from(route('locale.switch', $locale, false)));
    $page->goto(ComputeUrl::from($to));
    BrowserWait::until($page, '() => document.readyState === "complete" && window.Alpine !== undefined', 10_000);

    return $page;
}

function commentsSigns(Page $page): int
{
    return (int) $page->evaluate('() => Number(sessionStorage.getItem("__signs") ?? "0")');
}

function commentsShot(Page $page, string $name): void
{
    $dir = getenv('COMMENT_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    $page->screenshot(true, $name);
    rename(base_path('tests/Browser/Screenshots/'.$name.'.png'), rtrim($dir, '/').'/'.$name.'.png');
}

function commentsClean(Page $page, string $where): void
{
    expect($page->evaluate('() => window.__errors'))->toBe([], "console at {$where}")
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([], "responses at {$where}");
}

test('comments, likes and RSVPs: read safely from the relay, shown before signing, signed once per click; clean at 375 and 1440 in en and de', function () {
    $league = new TestSigner;
    config(['esports.league.nsec' => $league->secret]);

    $user = User::factory()->create(['name' => 'satsjaeger', 'locale' => 'en']);
    $signer = TestSigner::forBrowser($user);
    $user->refresh();
    $tournament = openTournament(['name' => 'Testnet Open'])->refresh();
    $address = $tournament->address();

    $annaKey = new TestSigner;
    User::factory()->withPubkey($annaKey->pubkey)->create(['name' => 'anna']);
    $stranger = new TestSigner;
    $muted = new TestSigner;
    $scope = fn () => [['A', $address, ''], ['K', '31923'], ['P', $league->pubkey], ['a', $address, ''], ['k', '31923'], ['p', $league->pubkey]];
    $payload = '<img src=x onerror="window.__xss=1"><script>window.__xss=2</script> **bold**';
    $now = now()->getTimestamp();

    commentsSend($this->relayUrl, $stranger->sign(1111, $scope(), $payload, $now - 300));
    commentsSend($this->relayUrl, $annaKey->sign(1111, $scope(), 'Good luck, everyone!', $now - 200));
    commentsSend($this->relayUrl, $muted->sign(1111, $scope(), 'buy my coin', $now - 100));
    commentsSend($this->relayUrl, $annaKey->sign(1111, $scope(), 'First!', $now - 400));
    // The league mutes a pubkey (NIP-28 kind 44 of the channel creator): its comments are left out on this app.
    commentsSend($this->relayUrl, $league->sign(44, [['p', $muted->pubkey]], 'spam', $now - 50));
    // RSVPs by anyone: two going, one not; the sign-up count stays the league's.
    commentsSend($this->relayUrl, $stranger->sign(31925, [['d', 'x1'], ['a', $address], ['status', 'accepted']], '', $now - 60));
    commentsSend($this->relayUrl, $annaKey->sign(31925, [['d', $address], ['a', $address], ['status', 'accepted']], '', $now - 60));
    commentsSend($this->relayUrl, $muted->sign(31925, [['d', $address], ['a', $address], ['status', 'declined']], '', $now - 60));
    commentsSend($this->relayUrl, $stranger->sign(7, [['e', $tournament->event->event_id], ['a', $address], ['p', $league->pubkey], ['k', '31923']], '+', $now - 30));

    // A rated game with the league's record, for the game page's section.
    $record = NostrEvent::fromSigned(SignedEvent::fromInput($league->sign(64, [['alt', 'Chess game']], "1. f3 e5 2. g4 Qh4# 0-1\n")));
    $game = ChessGame::factory()->rated()->finished('0-1')->create(['record_event_id' => $record->id]);
    commentsSend($this->relayUrl, $stranger->sign(1111, [['E', $record->event_id, '', $league->pubkey], ['K', '64'], ['P', $league->pubkey], ['e', $record->event_id, '', $league->pubkey], ['k', '64'], ['p', $league->pubkey]], 'Qh4# in two, ouch', $now - 10));

    soloSignup($tournament, $user, $signer);

    $pages = [
        'tournament' => [route('tournaments.show', $tournament, false), '[data-test=nostr-comments]'],
        'signup' => [route('tournaments.signup', $tournament, false), '[data-test=rsvp-offer]'],
        'game' => [route('games.show', $game, false), '[data-test=nostr-comments]'],
    ];
    $failures = [];

    foreach (['en', 'de'] as $locale) {
        foreach ([375, 1440] as $width) {
            foreach ($pages as $name => [$to, $selector]) {
                $page = commentsPage($user, $to, $width, $locale);

                if ($name !== 'signup') {
                    // Read from the relay and the authors looked up: every row has its picture.
                    BrowserWait::until($page, COMMENTS_READY, 15_000);
                }

                $m = $page->evaluate(COMMENTS_LAYOUT, $selector);
                fwrite(STDERR, "\n[p48] {$name} {$locale} {$width}px ".json_encode($m)."\n");
                commentsShot($page, "p48-{$name}-{$locale}-{$width}");

                $ok = $m['lang'] === $locale && $m['doc'][0] <= $m['doc'][1] && $m['box'] !== null && $m['box'] <= $width && $m['small'] === [] && $m['spill'] === []
                    && $page->evaluate('() => window.__errors') === [] && $page->evaluate(BrowserConsole::BAD_RESPONSES) === [] && commentsSigns($page) === 0;

                if (! $ok) {
                    $failures[] = "{$name} {$locale}@{$width}: ".json_encode([...$m, 'errors' => $page->evaluate('() => window.__errors'), 'bad' => $page->evaluate(BrowserConsole::BAD_RESPONSES), 'signs' => commentsSigns($page)]);
                }
            }
        }
    }

    expect($failures)->toBe([]);

    // The tournament page in English at 375: what the relay holds, read safely. The first page (3) holds the
    // muted account's comment too, which is left out, so it shows two; the oldest comes with "Load more".
    $page = commentsPage($user, $pages['tournament'][0], 375, 'en');
    BrowserWait::until($page, '() => ('.COMMENTS_READY.')() && document.querySelector("[data-test=rsvp-count]") !== null && document.querySelector("[data-test=comments-more]").checkVisibility()', 15_000);
    $shown = fn () => $page->evaluate('() => [...document.querySelectorAll("[data-test=comment]")].map((c) => [c.querySelector("[data-test=comment-author]").textContent.trim(), c.querySelector("[data-test=comment-text-shown]").textContent])');
    $first = $shown();

    expect(array_column($first, 1))->toBe(['Good luck, everyone!', $payload])
        ->and($first[0][0])->toBe('anna')
        ->and($first[1][0])->toStartWith('npub1')
        // The payload is text: no element was made from it and nothing of it ran.
        ->and($page->evaluate('() => document.querySelectorAll("[data-test=comment-text-shown] *").length'))->toBe(0)
        ->and($page->evaluate('() => window.__xss ?? null'))->toBeNull()
        // The muted account is left out; the RSVPs are counted apart from the sign-ups.
        ->and($page->evaluate('() => document.body.innerText.includes("buy my coin")'))->toBeFalse()
        ->and($page->evaluate('() => document.querySelector("[data-test=rsvp-count]").textContent.trim()'))->toBe('2 said on Nostr they’re going')
        ->and($page->evaluate('() => document.querySelector("[data-test=like-count]").textContent.trim()'))->toBe('1');

    $page->locator('[data-test=comments-more]')->click();
    BrowserWait::until($page, '() => document.querySelectorAll("[data-test=comment]").length === 3 && ('.COMMENTS_READY.')()', 15_000);

    expect(array_column($shown(), 1))->toBe(['Good luck, everyone!', $payload, 'First!']);

    // Write a comment: the preview shows the exact text and signs nothing; "Sign and post" signs once.
    $page->locator('[data-test=comment-text]')->fill("  GG all\nsee you there  ");
    $page->locator('[data-test=comment-preview]')->click();
    BrowserWait::until($page, '() => document.querySelector("[data-test=comment-preview-text]")?.checkVisibility()', 15_000);

    expect($page->evaluate('() => document.querySelector("[data-test=comment-preview-text]").textContent'))->toBe("GG all\nsee you there")
        ->and(commentsSigns($page))->toBe(0);

    $page->locator('[data-test=comment-edit]')->click();
    expect(commentsSigns($page))->toBe(0);
    $page->locator('[data-test=comment-preview]')->click();
    BrowserWait::until($page, '() => document.querySelector("[data-test=comment-sign]")?.checkVisibility()', 15_000);
    commentsShot($page, 'p48-comment-preview-en-375');
    $page->locator('[data-test=comment-sign]')->click();
    BrowserWait::until($page, '() => document.querySelector("[data-test=comment-posted]")?.checkVisibility()', 15_000);

    expect(commentsSigns($page))->toBe(1)
        ->and($page->evaluate('() => document.querySelector("[data-test=comment] [data-test=comment-text-shown]").textContent'))->toBe("GG all\nsee you there");

    // Like: the preview first, then one signature.
    $page->locator('[data-test=like]')->click();
    BrowserWait::until($page, '() => document.querySelector("[data-test=like-sign]")?.checkVisibility()', 15_000);
    expect(commentsSigns($page))->toBe(1);
    $page->locator('[data-test=like-sign]')->click();
    BrowserWait::until($page, '() => document.querySelector("[data-test=like-count]").textContent.trim() === "2"', 15_000);

    expect(commentsSigns($page))->toBe(2);
    commentsClean($page, 'tournament after posting');

    $mine = collect(commentsQuery($this->relayUrl, '-k 1111 -k 7 -a '.$user->pubkey))->keyBy('kind');

    expect($mine->keys()->sort()->values()->all())->toBe([7, 1111])
        ->and($mine[1111]['content'])->toBe("GG all\nsee you there")
        ->and(array_column($mine[1111]['tags'], 0))->toBe(['A', 'K', 'P', 'a', 'e', 'k', 'p', 'alt'])
        ->and($mine[7]['content'])->toBe('+')
        ->and(SignedEvent::fromInput($mine[1111])?->hasValidSignature())->toBeTrue();

    // RSVP on the sign-up page (German, 1440): "going", preview first, one signature.
    $signup = commentsPage($user, $pages['signup'][0], 1440, 'de');
    $signup->locator('[data-test=rsvp-offer][data-status=accepted] [data-test=rsvp-open]')->click();
    BrowserWait::until($signup, '() => document.querySelector("[data-test=rsvp-sign]")?.checkVisibility()', 15_000);
    commentsShot($signup, 'p48-rsvp-preview-de-1440');
    expect(commentsSigns($signup))->toBe(0);
    $signup->locator('[data-test=rsvp-sign]')->click();
    BrowserWait::until($signup, '() => document.querySelector("[data-test=rsvp-done]")?.checkVisibility()', 15_000);
    expect(commentsSigns($signup))->toBe(1);

    // Pulling out (one signed consent): now "not going" is offered, and replaces "going" on the relay.
    $signup->locator('[data-test=withdraw]')->click();
    BrowserWait::until($signup, '() => document.querySelector("[data-test=rsvp-offer][data-status=declined] [data-test=rsvp-open]")?.checkVisibility()', 15_000);
    $this->travel(2)->seconds();
    $signup->locator('[data-test=rsvp-offer][data-status=declined] [data-test=rsvp-open]')->click();
    BrowserWait::until($signup, '() => document.querySelector("[data-test=rsvp-offer][data-status=declined] [data-test=rsvp-sign]")?.checkVisibility()', 15_000);
    $signup->locator('[data-test=rsvp-offer][data-status=declined] [data-test=rsvp-sign]')->click();
    BrowserWait::until($signup, '() => document.querySelector("[data-test=rsvp-offer][data-status=declined] [data-test=rsvp-done]")?.checkVisibility()', 15_000);

    $rsvps = commentsQuery($this->relayUrl, '-k 31925 -a '.$user->pubkey);

    expect(commentsSigns($signup))->toBe(3)
        ->and($rsvps)->toHaveCount(1)
        ->and(collect($rsvps[0]['tags'])->firstWhere(0, 'status')[1])->toBe('declined')
        ->and(collect($rsvps[0]['tags'])->firstWhere(0, 'd')[1])->toBe($address);
    commentsClean($signup, 'sign-up after the RSVPs');

    // Positive control: the collector does see a thrown error and a 404 on this page.
    $signup->evaluate('() => { setTimeout(() => { throw new Error("p48 probe"); }); fetch("/p48-missing-probe"); }');
    BrowserWait::until($signup, '() => window.__errors.length >= 2', 5_000);
    expect(implode("\n", $signup->evaluate('() => window.__errors')))->toContain('p48 probe')->toContain('404');
});

test('no relay answering is said as such, and an event without comments says so; guests read and get a login link', function () {
    $league = new TestSigner;
    config(['esports.league.nsec' => $league->secret]);
    $user = User::factory()->create(['name' => 'satsjaeger', 'locale' => 'en']);
    TestSigner::forBrowser($user);
    $user->refresh();
    $tournament = openTournament(['name' => 'Quiet Cup'])->refresh();

    $page = commentsPage($user, route('tournaments.show', $tournament, false), 375, 'en');
    BrowserWait::until($page, '() => document.querySelector("[data-test=comments-empty]")?.checkVisibility()', 15_000);
    commentsClean($page, 'empty');

    // The relays are down: "unknown", never "no comments".
    config(['esports.relays' => ['ws://127.0.0.1:9']]);
    $down = commentsPage($user, route('tournaments.show', $tournament, false), 375, 'en');
    BrowserWait::until($down, '() => document.querySelector("[data-test=comments-unreached]")?.checkVisibility()', 15_000);

    expect($down->evaluate('() => document.querySelector("[data-test=comments-empty]").checkVisibility()'))->toBeFalse()
        ->and($down->evaluate('() => document.querySelector("[data-test=rsvp-unreached]")?.checkVisibility() ?? false'))->toBeTrue();

    // A guest: the comments, no composer, a login link.
    config(['esports.relays' => [$this->relayUrl]]);
    $guest = visit(route('tournaments.show', $tournament, false))->page();
    BrowserWait::until($guest, '() => document.querySelector("[data-test=comments-empty]")?.checkVisibility()', 15_000);

    expect($guest->evaluate('() => document.querySelector("[data-test=comment-composer]")'))->toBeNull()
        ->and($guest->evaluate('() => document.querySelector("[data-test=comment-login] a").getBoundingClientRect().height'))->toBeGreaterThanOrEqual(44);
});
