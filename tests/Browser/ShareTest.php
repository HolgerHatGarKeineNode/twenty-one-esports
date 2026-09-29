<?php

use App\Enums\ChessEndReason;
use App\Enums\SeriesStatus;
use App\Models\ChessGame;
use App\Models\InviteLink;
use App\Models\NostrEvent;
use App\Models\PlacementReveal;
use App\Models\Rating;
use App\Models\SeriesMatch;
use App\Models\User;
use App\Support\Badges\RankBadges;
use App\Support\Nostr\SignedEvent;
use App\Support\Rating\RankTiers;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserWait;
use Tests\Support\TestSigner;
use Tests\Support\WaitForPort;
use WebSocket\Client;
use WebSocket\Message\Text;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| Rank badges on the Nostr profile, and share posts (P11)
|--------------------------------------------------------------------------
|
| Against a local `nak serve` relay (never a public one), with a throwaway
| key behind the stubbed window.nostr:
|
| - "Show on my Nostr profile": the page reads the player's existing 10008
|   from the relay, the confirmation says the other badge stays, the signed
|   list on the relay holds the old entry and the new pair.
| - "Post on Nostr": the signed kind 1 reaches the relay with the card URL.
| - Every page with the new UI (badges tab, own player page, won tournament,
|   finished rated game) at 375 and 1440 px: no horizontal overflow, a clean
|   console, no response of 400 or more.
|
| SHARE_SHOTS=<dir> additionally writes the English screenshots there.
|
*/

const SHARE_COLLECTOR = <<<'JS'
    window.__errors = [];
    const push = (entry) => window.__errors.push(entry);
    const originalError = console.error;
    console.error = function (...args) { push('console.error: ' + args.map(String).join(' ')); originalError.apply(console, args); };
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

/** Every resource and the document itself answered below 400. */
const SHARE_BAD_RESPONSES = <<<'JS'
    () => performance.getEntries()
        .filter((e) => typeof e.responseStatus === 'number' && e.responseStatus >= 400)
        .map((e) => e.responseStatus + ' ' + e.name)
    JS;

const SHARE_NO_OVERFLOW = '() => document.documentElement.scrollWidth <= document.documentElement.clientWidth';

/**
 * Every image on the page loaded (a card or badge that failed to render has naturalWidth 0).
 * A lazy image in a closed menu (the game covers of the games menu) never loads until the menu
 * opens, so a lazy image counts only when it is visible and above the fold.
 */
const SHARE_IMAGES_LOADED = '() => [...document.images].filter((i) => i.loading !== "lazy" || (i.checkVisibility() && i.getBoundingClientRect().top < innerHeight)).every((i) => i.complete && i.naturalWidth > 0)';

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
    // Profile relays (the page reads and publishes) and league relays (the server publishes): the local relay only.
    config(['esports.profile_relays' => [$this->relayUrl], 'esports.relays' => [$this->relayUrl]]);
});

afterEach(function () {
    $this->relay->stop(1);
});

function sharePage(User $user, string $to, int $width): Page
{
    $page = visit(route('testing.login', ['user' => $user, 'to' => $to]))->page();
    $page->context()->addInitScript(SHARE_COLLECTOR);
    $page->context()->addInitScript(TestSigner::browserStub($user));
    $page->setViewportSize($width, 900);
    $page->goto(ComputeUrl::from($to));
    BrowserWait::until($page, '() => document.readyState === "complete" && window.Alpine !== undefined', 10_000);

    return $page;
}

function shareShot(Page $page, string $name): void
{
    $dir = getenv('SHARE_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    File::ensureDirectoryExists($dir);
    $page->screenshot(true, $name);
    File::move(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
}

/** Send one signed event to the relay and wait for its OK. */
function relaySend(string $url, array $event): void
{
    $client = new Client($url);
    $client->setTimeout(5);
    $client->text(json_encode(['EVENT', $event]));
    $answer = $client->receive();
    $client->close();

    expect($answer instanceof Text ? json_decode($answer->getContent(), true) : null)->toMatchArray([0 => 'OK', 1 => $event['id'], 2 => true]);
}

/**
 * What the relay holds for a filter, read with nak (stdin closed: without it
 * `nak req` answers nothing).
 *
 * @return list<array<string, mixed>>
 */
function relayQuery(string $url, string $args): array
{
    $out = Process::run('nak req '.$args.' '.escapeshellarg($url).' </dev/null')->output();

    return array_values(array_filter(array_map(fn (string $line) => json_decode($line, true), explode("\n", trim($out)))));
}

test('badges go on the Nostr profile without losing the old list, a share post reaches the relay, every page is clean at 375 and 1440', function () {
    $user = User::factory()->create(['name' => 'satsjaeger', 'locale' => 'en']);
    $signer = TestSigner::forBrowser($user);
    $user->refresh();
    $season = openSeason(['slug' => 'pre-season']);
    $moments = shareMoments($user, $season);
    $game = ChessGame::factory()->rated()->finished('1-0')->create(['white_id' => $user->id, 'black_id' => $moments['opponent']->id]);

    // The player's existing profile badges, written by another client, on the relay only.
    $existing = $signer->sign(10008, [['a', '30009:'.str_repeat('a', 64).':bravery'], ['e', str_repeat('1', 64)]], '', now()->getTimestamp() - 3600);
    relaySend($this->relayUrl, $existing);
    // P45 audit F2: the badge list is read only from the player's own write relays, named by their relay list.
    relaySend($this->relayUrl, $signer->sign(10002, [['r', $this->relayUrl]], '', now()->getTimestamp() - 3600));

    $pages = [
        'badges' => route('settings.badges', absolute: false),
        'player' => route('players.show', $user->npub, false),
        'tournament' => route('tournaments.show', $moments['tournament'], false),
        'game' => route('games.show', $game, false),
    ];

    foreach ([375, 1440] as $width) {
        foreach ($pages as $name => $to) {
            $page = sharePage($user, $to, $width);
            BrowserWait::until($page, SHARE_IMAGES_LOADED, 20_000);
            $metrics = $page->evaluate('() => [document.documentElement.scrollWidth, document.documentElement.clientWidth]');
            fwrite(STDERR, "\n[share] {$name} {$width}px scrollWidth/clientWidth: ".json_encode($metrics)."\n");
            shareShot($page, "share-{$name}-{$width}");

            expect([$name, $width, $page->evaluate(SHARE_NO_OVERFLOW)])->toBe([$name, $width, true])
                ->and([$name, $width, $page->evaluate('() => window.__errors')])->toBe([$name, $width, []])
                ->and([$name, $width, $page->evaluate(SHARE_BAD_RESPONSES)])->toBe([$name, $width, []]);
        }
    }

    // Show on my Nostr profile: read, confirm (the old badge stays), sign.
    $page = sharePage($user, $pages['badges'], 1440);
    $page->locator('[data-test=badge-show]')->click();
    BrowserWait::until($page, '() => getComputedStyle(document.querySelector("[data-test=badge-confirm]")).display !== "none"', 15_000);
    expect($page->evaluate('() => document.querySelector("[data-test=badge-kept]").textContent.trim()'))->toBe('Your 1 other badge stays on your profile.')
        ->and($page->evaluate('() => document.querySelector("[data-test=badge-relays]").textContent.trim()'))->toBe('1 of 1 of your relays answered.');
    shareShot($page, 'share-badge-confirm-1440');

    $page->locator('[data-test=badge-confirm-sign]')->click();
    BrowserWait::until($page, '() => document.querySelector("[data-test=badge-listed]") !== null', 15_000);

    $badge = $moments['versions'][1]->badge;
    $lists = relayQuery($this->relayUrl, '-k 10008 -a '.$user->pubkey);

    expect($lists)->toHaveCount(1)
        ->and($lists[0]['tags'])->toBe([...$existing['tags'], ['a', $badge->address()], ['e', $badge->awardEvent->event_id], ['alt', 'Profile badges']])
        ->and(SignedEvent::fromInput($lists[0])?->hasValidSignature())->toBeTrue()
        ->and(NostrEvent::query()->where('kind', 10008)->where('event_id', $lists[0]['id'])->exists())->toBeTrue();

    // Post on Nostr: the block share, the preview first (P46), then the signer.
    $page->locator('[data-test=share-moment][data-type=block] [data-test=share-post]')->first()->click();
    BrowserWait::until($page, '() => document.querySelector("[data-test=share-moment][data-type=block] [data-test=share-preview-text]")?.innerText.includes("Mined block 2")', 15_000);
    $page->locator('[data-test=share-moment][data-type=block] [data-test=share-sign]')->first()->click();
    BrowserWait::until($page, '() => getComputedStyle(document.querySelector("[data-test=share-moment][data-type=block] [data-test=share-done]")).display !== "none"', 15_000);
    shareShot($page, 'share-posted-1440');

    $notes = relayQuery($this->relayUrl, '-k 1 -a '.$user->pubkey);

    expect($notes)->toHaveCount(1)
        ->and($notes[0]['content'])->toStartWith('Mined block 2 on the TWENTY ONE Esports season chain')
        ->and($notes[0]['content'])->toContain('/cards/en/block/'.$moments['block']->id.'/'.$user->npub.'-wide.png?v=')
        ->and($notes[0]['tags'][0][0])->toBe('imeta')
        ->and(SignedEvent::fromInput($notes[0])?->hasValidSignature())->toBeTrue()
        ->and($page->evaluate('() => window.__errors'))->toBe([])
        ->and($page->evaluate(SHARE_BAD_RESPONSES))->toBe([]);
});

/** Counts every call of the stubbed signer, across reloads of the tab (as ChessCorrespondenceQuietTest). */
const SHARE_SIGN_COUNTER = <<<'JS'
    (() => {
        const sign = window.nostr?.signEvent;
        if (!sign) return;
        window.nostr.signEvent = (draft) => {
            sessionStorage.setItem('__signs', String(Number(sessionStorage.getItem('__signs') ?? '0') + 1));
            return sign(draft);
        };
    })();
    JS;

/** The box of an entry point and of its post button, the document overflow and the page language. */
const SHARE_ENTRY = <<<'JS'
    (sel) => {
        const el = document.querySelector(sel);
        const button = el?.querySelector('[data-test=share-post]');
        const box = (node) => { const b = node.getBoundingClientRect(); return { left: Math.round(b.left), right: Math.round(b.right), width: Math.round(b.width), height: Math.round(b.height) }; };
        return {
            lang: document.documentElement.lang,
            doc: [document.documentElement.scrollWidth, document.documentElement.clientWidth],
            entry: el && el.checkVisibility() ? box(el) : null,
            button: button && button.checkVisibility() ? { ...box(button), text: button.innerText.trim() } : null,
            // Anything inside the entry that reaches past it (a long npub, a cut label).
            spill: el ? [...el.querySelectorAll('*')].filter((n) => n.checkVisibility() && n.getBoundingClientRect().right > el.getBoundingClientRect().right + 1).map((n) => (n.dataset.test ?? n.tagName) + ':' + Math.round(n.getBoundingClientRect().right) + ':' + n.textContent.trim().slice(0, 30)) : [],
        };
    }
    JS;

/** @param  (Closure(): void)|null  $beforeVisit  runs after the login and the language switch, before the page loads */
function sharePageIn(User $user, string $to, int $width, string $locale, ?Closure $beforeVisit = null): Page
{
    $page = visit(route('testing.login', ['user' => $user, 'to' => $to]))->page();
    $page->context()->addInitScript(SHARE_COLLECTOR);
    $page->context()->addInitScript(TestSigner::browserStub($user));
    $page->context()->addInitScript(SHARE_SIGN_COUNTER);
    $page->setViewportSize($width, 900);
    $page->goto(ComputeUrl::from(route('locale.switch', $locale, false)));

    if ($beforeVisit !== null) {
        $beforeVisit();
    }

    $page->goto(ComputeUrl::from($to));
    BrowserWait::until($page, '() => document.readyState === "complete" && window.Alpine !== undefined', 10_000);

    return $page;
}

function shareSigns(Page $page): int
{
    return (int) $page->evaluate('() => Number(sessionStorage.getItem("__signs") ?? "0")');
}

test('P46: every moment offers its own post where it happens, shows the note first and signs only on the click; en and de at 375 and 1440', function () {
    $user = User::factory()->create(['name' => 'satsjaeger', 'locale' => 'en']);
    $signer = TestSigner::forBrowser($user);
    $user->refresh();
    $season = openSeason(['slug' => 'pre-season']);
    $moments = shareMoments($user, $season);
    $opponent = $moments['opponent'];

    // A won rated blitz game (fool's mate, the player is Black) with the league's record.
    $league = new TestSigner;
    $record = NostrEvent::fromSigned(SignedEvent::fromInput($league->sign(64, [['alt', 'Chess game']], "1. f3 e5 2. g4 Qh4# 0-1\n")));
    $game = ChessGame::factory()->rated()->finished('0-1', ChessEndReason::Checkmate)->create([
        'white_id' => $opponent->id, 'black_id' => $user->id, 'ply' => 4, 'record_event_id' => $record->id,
        'fen' => 'rnb1kbnr/pppp1ppp/8/4p3/6Pq/5P2/PPPPP2P/RNBQKBNR w KQkq - 1 3',
    ]);
    foreach ([['f2f3', 'f3', 'rnbqkbnr/pppppppp/8/8/8/5P2/PPPPP1PP/RNBQKBNR b KQkq - 0 1'], ['e7e5', 'e5', 'rnbqkbnr/pppp1ppp/8/4p3/8/5P2/PPPPP1PP/RNBQKBNR w KQkq - 0 2'],
        ['g2g4', 'g4', 'rnbqkbnr/pppp1ppp/8/4p3/6P1/5P2/PPPPP2P/RNBQKBNR b KQkq - 0 2'], ['d8h4', 'Qh4#', 'rnb1kbnr/pppp1ppp/8/4p3/6Pq/5P2/PPPPP2P/RNBQKBNR w KQkq - 1 3']] as $index => [$uci, $san, $fen]) {
        $game->moves()->create(['ply' => $index + 1, 'uci' => $uci, 'san' => $san, 'fen' => $fen, 'spent_ms' => 1000, 'clock_ms' => 290_000]);
    }

    // A confirmed casual series the player's side won.
    $series = SeriesMatch::factory()->create([
        'status' => SeriesStatus::Confirmed, 'winner' => 'challenger', 'finished_at' => now(),
        'result_games' => [['winner' => 'challenger', 'challenger' => 3, 'challenged' => 1], ['winner' => 'challenger', 'challenger' => 2, 'challenged' => 0]],
        'rosters' => ['challenger' => [$user->id], 'challenged' => [$opponent->id]],
    ]);

    // A published tournament the player entered.
    $tournament = openTournament(['name' => 'Testnet Open']);
    soloSignup($tournament, $user, $signer);

    // A placement reveal per locale round, written right before the first page at 375 (the login's own landing would claim it):
    // the rank-up notice links to the profile's badges.
    $revealLadders = ['en' => ['chess', 'correspondence'], 'de' => ['rocket-league', '1v1']];
    $reveal = fn (string $locale) => function () use ($user, $season, $revealLadders, $locale): void {
        [$game, $mode] = $revealLadders[$locale];
        $rating = Rating::query()->create(['pool' => Rating::RATED, 'season' => $season->slug, 'game' => $game, 'mode' => $mode,
            'subject' => 'user:'.$user->id, 'user_id' => $user->id, 'rating' => 1010, 'results' => 5]);
        PlacementReveal::query()->create(['user_id' => $user->id, 'rating_id' => $rating->id, 'game' => $game, 'mode' => $mode, 'rating' => 1010, 'tier' => RankTiers::fromConfig()->tierFor(1010, 5)]);
    };

    // The season is over: /mining shows the player's Wrapped card.
    $season->forceFill(['ends_at' => now()->subMinute()])->save();

    $entries = [
        'game' => [route('games.show', $game, false), '[data-test=game-win-share]', ['en' => 'Post the win', 'de' => 'Sieg posten']],
        'series' => [route('matches.show', $series, false), '[data-test=series-win-share]', ['en' => 'Post the win', 'de' => 'Sieg posten']],
        'signup' => [route('tournaments.signup', $tournament, false), '[data-test=signup-post]', ['en' => 'Post that you’re in', 'de' => 'Posten, dass du dabei bist']],
        'rank-up' => [route('players.show', $user->npub, false), '[data-test=rank-up-share]', ['en' => 'Post my rank up', 'de' => 'Meinen Aufstieg posten']],
        'wrapped' => [route('mining', absolute: false), '[data-test=season-wrapped]', ['en' => 'Post my season', 'de' => 'Meine Season posten']],
    ];
    $failures = [];
    $reveals = [];

    foreach (['en', 'de'] as $locale) {
        foreach ([375, 1440] as $width) {
            foreach ($entries as $name => [$to, $selector, $labels]) {
                $page = sharePageIn($user, $to, $width, $locale, $width === 375 && $name === 'game' ? $reveal($locale) : null);

                // The rank-up notice (P10 placement) shows once, on the first page: it links to the badges, it signs nothing.
                if ($page->evaluate('() => document.querySelector("[data-test=placement-reveal]")?.checkVisibility() ?? false')) {
                    $reveals["{$locale}@{$width}"] = $page->evaluate('() => { const a = document.querySelector("[data-test=placement-share]"); const b = a.getBoundingClientRect(); return { href: a.getAttribute("href"), text: a.innerText.trim(), right: Math.round(b.right), height: Math.round(b.height), inner: innerWidth }; }');
                    shareShot($page, "p46-reveal-{$locale}-{$width}");
                    $page->locator('[data-test=placement-close]')->click();
                }

                BrowserWait::until($page, SHARE_IMAGES_LOADED, 20_000);
                $m = $page->evaluate(SHARE_ENTRY, $selector);
                fwrite(STDERR, "\n[p46] {$name} {$locale} {$width}px ".json_encode($m)."\n");
                shareShot($page, "p46-{$name}-{$locale}-{$width}");

                $ok = $m['lang'] === $locale && $m['doc'][0] <= $m['doc'][1] && $m['entry'] !== null && $m['entry']['left'] >= 0 && $m['entry']['right'] <= $width
                    && $m['button'] !== null && $m['button']['height'] === 44 && $m['button']['text'] === $labels[$locale] && $m['spill'] === []
                    && $page->evaluate('() => window.__errors') === [] && $page->evaluate(SHARE_BAD_RESPONSES) === [] && shareSigns($page) === 0;

                if (! $ok) {
                    $failures[] = "{$name} {$locale}@{$width}: ".json_encode([...$m, 'errors' => $page->evaluate('() => window.__errors'), 'bad' => $page->evaluate(SHARE_BAD_RESPONSES), 'signs' => shareSigns($page)]);
                }
            }
        }
    }

    expect($failures)->toBe([])
        ->and(array_keys($reveals))->toBe(['en@375', 'de@375'])
        ->and(array_column($reveals, 'href'))->each->toEndWith('/players/'.$user->npub.'#rb-h')
        ->and(array_column($reveals, 'text'))->toBe(['Share your rank', 'Rang teilen'])
        ->and(array_column($reveals, 'height'))->toBe([44, 44])
        ->and(array_filter($reveals, fn (array $link): bool => $link['right'] > $link['inner']))->toBe([]);

    // The win at 375 in German: the preview shows the exact note and the card; nothing is signed until "Sign and post".
    $page = sharePageIn($user, $entries['game'][0], 375, 'de');
    $page->locator('[data-test=game-win-share] [data-test=share-post]')->click();
    BrowserWait::until($page, '() => document.querySelector("[data-test=game-win-share] [data-test=share-preview-text]")?.innerText.includes("GG nostr:npub1")', 15_000);
    BrowserWait::until($page, '() => { const i = document.querySelector("[data-test=game-win-share] [data-test=share-preview-card]"); return i.complete && i.naturalWidth === 1200; }', 20_000);
    $preview = $page->evaluate(SHARE_ENTRY, '[data-test=game-win-share]');
    shareShot($page, 'p46-game-preview-de-375');

    expect(shareSigns($page))->toBe(0)
        ->and($preview['doc'][0])->toBeLessThanOrEqual($preview['doc'][1])
        ->and($preview['spill'])->toBe([])
        ->and($page->evaluate('() => document.querySelector("[data-test=game-win-share] [data-test=share-preview-text]").innerText'))->toStartWith('Blitzpartie gegen pillpusher bei TWENTY ONE Esports gewonnen.')
        ->and($page->evaluate('() => document.querySelector("[data-test=game-win-share] [data-test=share-preview-mentions]").innerText'))->toContain('pillpusher');

    // Cancel signs nothing; opening again and "Sign and post" signs once.
    $page->locator('[data-test=game-win-share] [data-test=share-cancel]')->click();
    expect(shareSigns($page))->toBe(0);
    $page->locator('[data-test=game-win-share] [data-test=share-post]')->click();
    BrowserWait::until($page, '() => document.querySelector("[data-test=game-win-share] [data-test=share-sign]")?.checkVisibility()', 15_000);
    $page->locator('[data-test=game-win-share] [data-test=share-sign]')->click();
    BrowserWait::until($page, '() => document.querySelector("[data-test=game-win-share] [data-test=share-posted]")?.checkVisibility()', 15_000);

    // "I'm in" at 1440 in English, the same way.
    $signup = sharePageIn($user, $entries['signup'][0], 1440, 'en');
    $signup->locator('[data-test=signup-post] [data-test=share-post]')->click();
    BrowserWait::until($signup, '() => document.querySelector("[data-test=signup-post] [data-test=share-preview-text]")?.innerText.includes("nostr:naddr1")', 15_000);
    expect(shareSigns($signup))->toBe(0);
    $signup->locator('[data-test=signup-post] [data-test=share-sign]')->click();
    BrowserWait::until($signup, '() => document.querySelector("[data-test=signup-post] [data-test=share-posted]")?.checkVisibility()', 15_000);

    $notes = collect(relayQuery($this->relayUrl, '-k 1 -a '.$user->pubkey))->keyBy(fn (array $note) => collect($note['tags'])->firstWhere(0, 'alt')[1]);
    $win = $notes->get('Share post: game in TWENTY ONE Esports');
    $in = $notes->get('Share post: signup in TWENTY ONE Esports');

    expect(shareSigns($page))->toBe(1)
        ->and(shareSigns($signup))->toBe(1)
        ->and($win)->not->toBeNull()
        ->and(SignedEvent::fromInput($win)?->hasValidSignature())->toBeTrue()
        ->and(collect($win['tags'])->where(0, 'p')->pluck(1)->all())->toBe([$opponent->pubkey])
        ->and(collect($win['tags'])->firstWhere(0, 'q')[1])->toBe($record->event_id)
        ->and($win['content'])->toContain('GG nostr:'.$opponent->npub)
        ->and($win['content'])->toContain('/cards/de/page/game/'.$game->id.'.png?v=')
        ->and($in)->not->toBeNull()
        ->and(collect($in['tags'])->firstWhere(0, 'q')[1])->toBe($tournament->refresh()->address())
        ->and($in['content'])->toContain('/i/'.InviteLink::query()->where('tournament_id', $tournament->id)->value('code'))
        ->and($notes->flatMap(fn (array $note) => collect($note['tags'])->where(0, 't'))->all())->toBe([])
        ->and($page->evaluate('() => window.__errors'))->toBe([])
        ->and($signup->evaluate('() => window.__errors'))->toBe([])
        ->and($signup->evaluate(SHARE_BAD_RESPONSES))->toBe([]);
});

test('the share collectors see a thrown error and a failed card (positive control)', function () {
    $user = User::factory()->create();
    TestSigner::forBrowser($user);

    $page = sharePage($user->refresh(), route('settings.badges', absolute: false), 1440);
    $page->evaluate('() => { const img = new Image(); img.src = "/cards/en/rank-up/999999-wide.png"; document.body.append(img); setTimeout(() => { throw new Error("positive control"); }); }');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("positive control"))', 5_000);
    BrowserWait::until($page, '() => performance.getEntries().some((e) => e.name.includes("/cards/en/rank-up/999999"))', 5_000);

    expect(implode("\n", $page->evaluate(SHARE_BAD_RESPONSES)))->toMatch('#^404 http://\S+/cards/en/rank-up/999999-wide\.png$#m');
});

/**
 * A throwaway MiniRelay (tests/Support/MiniRelay.php: no signature check, events served in seed order).
 *
 * @param  list<array<string, mixed>>  $seed
 * @return array{0: InvokedProcess, 1: string}
 */
function shareMiniRelay(array $seed): array
{
    $port = (int) Process::run(['php', '-r', '$s = stream_socket_server("tcp://127.0.0.1:0"); echo explode(":", stream_socket_get_name($s, false))[1];'])->output();
    $file = storage_path('framework/testing/share-seed-'.$port.'.json');
    File::ensureDirectoryExists(dirname($file));
    File::put($file, (string) json_encode($seed));
    $relay = Process::path(base_path())->start(['php', 'tests/Support/mini-relay.php', (string) $port, $file]);

    WaitForPort::open('127.0.0.1', $port);

    return [$relay, 'ws://127.0.0.1:'.$port];
}

test('a forged copy served first does not hide the real list, a write relay that is down refuses, and no relay taking the list shows no success', function () {
    $season = openSeason(['slug' => 'pre-season']);
    [$anna, $bert] = [User::factory()->create(['name' => 'anna']), User::factory()->create(['name' => 'bert'])];
    $annaKey = TestSigner::forBrowser($anna);
    $bertKey = TestSigner::forBrowser($bert);
    shareMoments($anna->refresh(), $season);
    // Bert needs a rank badge only (blocks are unique per season and height, Anna has them).
    Rating::query()->create(['pool' => Rating::RATED, 'season' => 'pre-season', 'game' => 'chess', 'mode' => 'blitz',
        'subject' => 'user:'.$bert->id, 'user_id' => $bert->id, 'rating' => 1040, 'results' => 6]);
    app(RankBadges::class)->sync($bert->refresh(), 'chess', 'blitz');

    // Anna's real list, and a forged copy with the same id and a junk signature, served FIRST.
    $real = $annaKey->sign(10008, [['a', '30009:'.str_repeat('a', 64).':bravery'], ['e', str_repeat('1', 64)]], '', now()->getTimestamp() - 3600);
    $forged = [...$real, 'sig' => str_repeat('0', 128)];
    // Bert's relay list names one write relay, and it is down.
    $closed = (int) Process::run(['php', '-r', '$s = stream_socket_server("tcp://127.0.0.1:0"); echo explode(":", stream_socket_get_name($s, false))[1];'])->output();
    $relayList = $bertKey->sign(10002, [['r', 'ws://127.0.0.1:'.$closed]]);

    [$relay, $url] = shareMiniRelay([$forged, $real, $relayList]);
    config(['esports.profile_relays' => [$url], 'esports.relays' => []]);
    // P45 audit F2: Anna's lists are read from her own write relays only, so her relay list names this one.
    relaySend($url, $annaKey->sign(10002, [['r', $url]], '', now()->getTimestamp() - 3600));

    try {
        // Bert: the write relay never answers, so nothing is read and the league refuses.
        $page = sharePage($bert, route('settings.badges', absolute: false), 1440);
        $page->locator('[data-test=badge-show]')->click();
        BrowserWait::until($page, '() => [...document.querySelectorAll("[data-test=rank-badges] [role=alert]")].some((e) => e.offsetParent !== null && e.textContent.includes("could not be read"))', 15_000);

        expect($page->evaluate('() => getComputedStyle(document.querySelector("[data-test=badge-confirm]")).display'))->toBe('none')
            ->and(NostrEvent::query()->where('kind', 10008)->where('pubkey', $bert->pubkey)->exists())->toBeFalse();

        // Anna: the forged copy arrives first and is dropped; the real list is found.
        $page = sharePage($anna, route('settings.badges', absolute: false), 1440);
        $page->locator('[data-test=badge-show]')->click();
        BrowserWait::until($page, '() => getComputedStyle(document.querySelector("[data-test=badge-confirm]")).display !== "none"', 15_000);

        expect($page->evaluate('() => document.querySelector("[data-test=badge-kept]").textContent.trim()'))->toBe('Your 1 other badge stays on your profile.');
    } finally {
        // The relay goes away before Anna signs: no relay takes her list.
        $relay->stop(1);
    }

    $page->locator('[data-test=badge-confirm-sign]')->click();
    BrowserWait::until($page, '() => document.querySelector("[data-test=badge-warning]")?.offsetParent != null', 15_000);

    expect($page->evaluate('() => document.querySelector("[data-test=badge-warning]").textContent.trim()'))->toBe('None of your relays took the new list. Nothing was changed.')
        ->and($page->evaluate('() => document.querySelector("[data-test=badge-added]")?.offsetParent ?? null'))->toBeNull()
        ->and($page->evaluate('() => document.querySelector("[data-test=badge-listed]")'))->toBeNull()
        // Only the real list the read found is archived; the new one never reached the league.
        ->and(NostrEvent::query()->where('kind', 10008)->where('pubkey', $anna->pubkey)->pluck('event_id')->all())->toBe([$real['id']])
        ->and($page->evaluate('() => window.__errors'))->toBe([]);
});
