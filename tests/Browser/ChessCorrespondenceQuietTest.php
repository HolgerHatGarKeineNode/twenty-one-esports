<?php

use App\Enums\ChessGameStatus;
use App\Models\ChessGame;
use App\Models\NostrEvent;
use App\Models\User;
use App\Support\Chess\ChessGameService;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;
use Tests\Support\TestSigner;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| Quiet daily chess (P52, NIP rev. 9.4), two players in two contexts
|--------------------------------------------------------------------------
|
| Reported (2026-09-28): every daily move was a kind-64 note on the mover's
| Nostr profile. Now a daily move runs over the league server like a blitz
| move: Anna on a phone (375 x 812, board clicks and the move bar) and Bert
| on a desktop (1440 x 900, the move field) play a daily game to mate, and
| no signer is asked once. At the end the league signs the one record; the
| post to a player's own profile waits for the button, shows its preview,
| and only then asks the signer.
|
| Both pages carry the console and network collector (console.error,
| uncaught errors, rejections, fetch and XHR >= 400, resource entries
| >= 400) and a counter on window.nostr.signEvent kept in sessionStorage, so
| the reload to the finished page does not reset it. A positive control
| shows each of them sees what it should.
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

/** Counts every call of the stubbed signer, across reloads of the tab. */
const QUIET_SIGN_COUNTER = <<<'JS'
    (() => {
        const sign = window.nostr?.signEvent;
        if (!sign) return;
        window.nostr.signEvent = (draft) => {
            sessionStorage.setItem('__signs', String(Number(sessionStorage.getItem('__signs') ?? '0') + 1));
            return sign(draft);
        };
    })();
    JS;

function quietPage(User $user, int $width, int $height, string $to): Page
{
    $page = visit(BrowserLogin::url($user))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->context()->addInitScript(TestSigner::browserStub($user));
    $page->context()->addInitScript(QUIET_SIGN_COUNTER);
    $page->setViewportSize($width, $height);
    $page->goto(ComputeUrl::from($to));

    return $page;
}

function quietDaily(): string
{
    return 'Alpine.$data(document.querySelector("[data-test=daily-game]"))';
}

function quietSigns(Page $page): int
{
    return (int) $page->evaluate('() => Number(sessionStorage.getItem("__signs") ?? "0")');
}

function quietClean(Page $page, string $where): void
{
    expect($page->evaluate('() => window.__errors'))->toBe([], "console at {$where}")
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([], "responses at {$where}");
}

/**
 * Layout numbers of the page: overflow of the document, and the box of one
 * element (null when it is not there).
 *
 * @return array{doc: list<int>, box: array{left: float, right: float, top: float, width: float, height: float}|null}
 */
function quietLayout(Page $page, string $selector): array
{
    return $page->evaluate('() => {
        const el = document.querySelector('.json_encode($selector).');
        const b = el?.getBoundingClientRect();
        return {
            doc: [document.documentElement.scrollWidth, document.documentElement.clientWidth],
            box: b ? { left: Math.round(b.left), right: Math.round(b.right), top: Math.round(b.top), width: Math.round(b.width), height: Math.round(b.height) } : null,
        };
    }');
}

/**
 * One daily move by board clicks: pick, confirm (the move bar on a phone,
 * "Make my move" from lg) and the double-check.
 */
function quietMove(Page $page, string $from, string $to, bool $phone): void
{
    $page->locator('[data-square="'.$from.'"]')->click();
    $page->locator('[data-square="'.$to.'"]')->click();
    BrowserWait::until($page, '() => '.quietDaily().'.pending !== null', 5_000);
    $page->locator($phone ? '[data-test=make-move-mobile]' : '[data-test=make-move]')->click();
    $page->locator('[data-test=confirm-daily-move]')->click();
}

test('a daily game at 375 and 1440: every move over the server with no signature, one league record at the end, and the profile post only by button', function () {
    $league = new TestSigner;
    config(['esports.league.nsec' => $league->secret]);
    [$anna, $bert] = User::factory()->count(2)->create();
    TestSigner::forBrowser($anna);
    TestSigner::forBrowser($bert);
    $game = app(ChessGameService::class)->start($anna, $bert, ChessGame::CORRESPONDENCE);
    $path = route('games.show', $game, false);

    $phone = quietPage($anna, 375, 812, $path);
    $desk = quietPage($bert, 1440, 900, $path);
    BrowserWait::until($phone, '() => window.Alpine && '.quietDaily().'?.myTurn === true', 10_000);
    BrowserWait::until($desk, '() => window.Alpine && '.quietDaily().' !== undefined', 10_000);

    $sizes = ['375 daily' => quietLayout($phone, '[data-test=daily-game]'), '1440 daily' => quietLayout($desk, '[data-test=daily-game]')];
    shellShot($phone, 'p52-375-daily');
    shellShot($desk, 'p52-1440-daily');

    // Fool's mate: 1. f3 e5 2. g4 Qh4#. After each move the other page asks for the state, as after a missed push.
    $plies = [[$phone, 'f2', 'f3', true], [$desk, 'e7', 'e5', false], [$phone, 'g2', 'g4', true], [$desk, 'd8', 'h4', false]];

    foreach ($plies as $i => [$page, $from, $to, $isPhone]) {
        $other = $page === $phone ? $desk : $phone;
        quietMove($page, $from, $to, $isPhone);

        if ($i < 3) {
            BrowserWait::until($page, '() => '.quietDaily().'.state.ply === '.($i + 1).' || '.quietDaily().'.error !== ""', 10_000);
            expect($page->evaluate('() => '.quietDaily().'.error'))->toBe('');
            $other->evaluate('() => '.quietDaily().'.resync()');
            BrowserWait::until($other, '() => '.quietDaily().'.state.ply === '.($i + 1), 10_000);
        } else {
            // The mating move ends the game: the page reloads into the finished view.
            BrowserWait::until($page, '() => document.querySelector("[data-test=chess-game-done]") !== null', 15_000);
            // Over Reverb the other page may have reloaded already.
            $other->evaluate('() => { const el = document.querySelector("[data-test=daily-game]"); if (el) Alpine.$data(el).resync(); }');
            BrowserWait::until($other, '() => document.querySelector("[data-test=chess-game-done]") !== null', 15_000);
        }
    }

    $game->refresh();

    // Server: the game is over, no move is an event, and the one kind-64 note is the league's.
    expect($game->status)->toBe(ChessGameStatus::Finished)
        ->and($game->result)->toBe('0-1')
        ->and($game->moves()->pluck('san')->all())->toBe(['f3', 'e5', 'g4', 'Qh4#'])
        ->and($game->moves()->whereNotNull('nostr_event_id')->count())->toBe(0)
        ->and(NostrEvent::query()->where('kind', 64)->pluck('pubkey')->all())->toBe([$league->pubkey])
        ->and($game->recordEvent?->payload()['content'])->toEndWith("1. f3 e5 2. g4 Qh4# 0-1\n")
        // Browser: not one signature for four moves and two finished pages.
        ->and(quietSigns($phone))->toBe(0)
        ->and(quietSigns($desk))->toBe(0);

    quietClean($phone, '375 after the game');
    quietClean($desk, '1440 after the game');

    // The finished page offers the post; nothing is posted until the click.
    foreach (['375' => $phone, '1440' => $desk] as $width => $page) {
        BrowserWait::until($page, '() => window.Alpine && Alpine.$data(document.querySelector("[data-test=game-post]"))?.step === "idle"', 10_000);
        $sizes["{$width} post"] = quietLayout($page, '[data-test=game-post]');
        $sizes["{$width} post button"] = quietLayout($page, '[data-test=game-post-open]');
        $page->evaluate('() => document.querySelector("[data-test=game-post]").scrollIntoView({ block: "center" })');
        shellShot($page, "p52-{$width}-post");
    }

    // Anna posts from her phone: the preview first, the exact note, then the signer once.
    $phone->locator('[data-test=game-post-open]')->click();
    BrowserWait::until($phone, '() => document.querySelector("[data-test=game-post-alt]")?.innerText.includes("0-1")', 5_000);
    $sizes['375 preview'] = quietLayout($phone, '[data-test=game-post-preview]');
    $sizes['375 preview pgn'] = quietLayout($phone, '[data-test=game-post-pgn]');
    $sizes['375 sign button'] = quietLayout($phone, '[data-test=game-post-sign]');
    $phone->evaluate('() => document.querySelector("[data-test=game-post-preview]").scrollIntoView({ block: "center" })');
    shellShot($phone, 'p52-375-preview');

    expect($phone->evaluate('() => document.querySelector("[data-test=game-post-pgn]").innerText'))->toContain('1. f3 e5 2. g4 Qh4# 0-1')
        ->and(quietSigns($phone))->toBe(0);

    $phone->locator('[data-test=game-post-sign]')->click();
    BrowserWait::until($phone, '() => Alpine.$data(document.querySelector("[data-test=game-post]")).step === "done" || Alpine.$data(document.querySelector("[data-test=game-post]")).error !== ""', 10_000);

    $post = NostrEvent::query()->find($game->refresh()->white_post_event_id);

    expect($phone->evaluate('() => Alpine.$data(document.querySelector("[data-test=game-post]")).error'))->toBe('')
        ->and(quietSigns($phone))->toBe(1)
        ->and($post?->pubkey)->toBe($anna->pubkey)
        ->and($post?->payload()['content'])->toBe($game->recordEvent->payload()['content'])
        ->and($post?->payload()['tags'])->toContain(['q', $game->recordEvent->event_id, '', $league->pubkey])
        ->and(collect($post?->payload()['tags'])->where(0, 't')->all())->toBe([])
        ->and($game->black_post_event_id)->toBeNull()
        ->and(quietSigns($desk))->toBe(0);

    // A reload keeps it posted.
    $phone->reload();
    BrowserWait::until($phone, '() => window.Alpine && Alpine.$data(document.querySelector("[data-test=game-post]"))?.step === "done"', 10_000);

    $phone->evaluate('() => document.querySelector("[data-test=game-post]").scrollIntoView({ block: "center" })');
    shellShot($phone, 'p52-375-posted');
    fwrite(STDERR, "\n[p52-quiet] ".json_encode($sizes));

    foreach ($sizes as $what => $size) {
        [$scroll, $client] = $size['doc'];
        $viewport = str_starts_with($what, '375') ? 375 : 1440;

        expect($scroll)->toBeLessThanOrEqual($client, "{$what}: the document scrolls sideways")
            ->and($size['box'])->not->toBeNull("{$what}: not on the page");

        if (! str_ends_with($what, 'daily')) {
            expect($size['box']['left'])->toBeGreaterThanOrEqual(0, "{$what} left")
                ->and($size['box']['right'])->toBeLessThanOrEqual($viewport, "{$what} right")
                ->and($size['box']['height'])->toBeGreaterThan(0, "{$what} height");
        }
    }

    // Touch targets on the phone: the post button and "Sign and post" are at least 44 px high.
    expect($sizes['375 post button']['box']['height'])->toBeGreaterThanOrEqual(44)
        ->and($sizes['375 sign button']['box']['height'])->toBeGreaterThanOrEqual(44)
        ->and($sizes['375 preview pgn']['box']['width'])->toBeLessThanOrEqual($sizes['375 preview']['box']['width']);

    quietClean($phone, '375 after the post');
    quietClean($desk, '1440 after the post');

    // Positive control: the collectors see a thrown error, a 404 fetch and a broken image, and the counter a signature.
    foreach ([$phone, $desk] as $page) {
        $page->evaluate('() => { setTimeout(() => { throw new Error("control-throw"); }); fetch("/control-missing-page"); document.body.append(Object.assign(new Image(), { src: "/control-missing.png" })); window.nostr.signEvent({ kind: 1, tags: [], content: "control", created_at: Math.floor(Date.now() / 1000) }); }');
        BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("control-throw")) && window.__errors.some((e) => e.startsWith("404"))', 5_000);
        BrowserWait::until($page, '() => ('.BrowserConsole::BAD_RESPONSES.')().some((e) => e.includes("control-missing.png"))', 5_000);
    }

    expect(quietSigns($phone))->toBe(2)
        ->and(quietSigns($desk))->toBe(1);
});
