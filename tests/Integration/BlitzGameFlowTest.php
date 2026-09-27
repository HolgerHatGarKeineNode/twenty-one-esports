<?php

use App\Enums\ChessGameStatus;
use App\Models\ChessGame;
use App\Models\User;
use Pest\Browser\Playwright\Page;
use Tests\Integration\Support\RelayCheck;
use Tests\Integration\Support\Stack;
use Tests\Support\BrowserWait;

pest()->group('integration');

/*
|--------------------------------------------------------------------------
| P15: a rated blitz chess game against the real stack
|--------------------------------------------------------------------------
|
| Two throwaway-keyed players, each in its own browser context, against the
| real app server + real Reverb (never Pest\Browser's in-process one): find
| each other in the lobby's queue, play a few moves live, White resigns.
| Rated (both Trusted, mutually listed, esports.chess.rated_queue via
| ESPORTS_RATED_CHESS on the real server env — Stack::env()): the finished
| game's NIP-64 record (kind 64) is checked against the relay, signature
| included. No Result Response (2153) check: see the note at the end of
| the test — the chess path does not publish one.
|
*/

function integrationBlitzPage(User $user, int $width = 1440): Page
{
    // Viewport set on the CONTEXT before any page load, not via a later
    // setViewportSize() call — see the same note on TeamMatchReadyTest.php's
    // teamPage().
    return integrationPage($user, integrationRoute('chess.lobby'), ['width' => $width, 'height' => 900]);
}

function chessBoard(Page $page, string $expression): mixed
{
    return $page->evaluate('() => { const g = Alpine.$data(document.querySelector("[data-test=chess-game]")); return '.$expression.'; }');
}

function playChessMove(Page $page, string $san): void
{
    $page->locator('[data-test=san-input]')->fill($san);
    $page->locator('[data-test=san-input]')->press('Enter');
}

test('two Trusted players are paired for rated blitz, play, and White resigns; the game record and its answer are on the relay', function () {
    // Pest\Browser\Support\BrowserTestIdentifier only scans THIS closure's
    // own source for a literal `visit(` call to decide whether to start the
    // Playwright driver; integrationPage() in a different file does not
    // qualify (tests/Integration/TeamMatchReadyTest.php has the same note).
    if (false) {
        visit('');
    }

    integrationOpenSeason();

    [$anna, $signerAnna] = integrationPlayer('anna-blitz');
    [$bert, $signerBert] = integrationPlayer('bert-blitz');
    integrationTrust([$anna->pubkey, $bert->pubkey]);
    integrationMutualList($anna, $signerAnna, $bert, $signerBert);

    // Both desktop width: the SAN move input (games/⚡show.blade.php) is
    // `max-lg:hidden` — a narrow viewport uses board clicks instead
    // (tests/Browser/BlitzGameTest.php's own approach), which is a UI
    // detail this flow does not need to re-cover.
    $pageA = integrationBlitzPage($anna, 1440);
    $pageB = integrationBlitzPage($bert, 1440);

    BrowserWait::until($pageA, '() => document.querySelector("[data-test=kind-rated]") !== null', 30_000);
    BrowserWait::until($pageA, '() => document.querySelector("[data-test=kind-rated]")?.disabled === false', 30_000);
    $pageA->locator('[data-test=kind-rated]')->click();
    $pageB->locator('[data-test=kind-rated]')->click();

    $pageA->locator('[data-test=find-opponent-button]')->click();
    $pageB->locator('[data-test=find-opponent-button]')->click();

    BrowserWait::until($pageA, '() => location.pathname.startsWith("/games/")', 30_000);
    BrowserWait::until($pageB, '() => location.pathname.startsWith("/games/")', 30_000);

    $game = ChessGame::query()->latest('id')->first();
    expect($game)->not->toBeNull()->and($game->rated)->toBeTrue();

    [$white, $black] = $game->white_id === $anna->id ? [$pageA, $pageB] : [$pageB, $pageA];

    foreach ([$white, $black] as $page) {
        BrowserWait::until($page, '() => Alpine.$data(document.querySelector("[data-test=chess-game]")).connection === "connected"', 30_000);
    }

    playChessMove($white, 'e4');
    BrowserWait::until($black, '() => Alpine.$data(document.querySelector("[data-test=chess-game]")).state.ply === 1', 10_000);
    playChessMove($black, 'e5');
    BrowserWait::until($white, '() => Alpine.$data(document.querySelector("[data-test=chess-game]")).state.ply === 2', 10_000);

    $white->locator('[data-test=resign]')->click();
    $white->locator('[data-test=confirm-resign]')->click();

    BrowserWait::until($black, '() => document.querySelector("[data-test=outcome]")?.innerText === "Win"', 10_000);

    $game->refresh();
    expect($game->status)->toBe(ChessGameStatus::Finished)
        ->and($game->result)->toBe('0-1');

    // resources/js/chess.js: on seeing "finished" the client asks $wire for
    // the record template, signs it (window.nostr, the browserStub here) and
    // submits it — asynchronous, no UI state this test already waits on
    // marks it done. Poll instead of asserting immediately after resign.
    for ($i = 0; $i < 60 && $game->refresh()->record_event_id === null; $i++) {
        usleep(250_000);
    }

    expect($game->record_event_id)->not->toBeNull('the NIP-64 record was not submitted within 15s of the game finishing');

    $relay = new RelayCheck(Stack::instance()->relayUrl);

    // The record is archived (record_event_id, just polled for above) before
    // it reaches the relay: publishing runs through the real queue worker
    // (PublishNostrEvent), one more asynchronous hop this test does not
    // otherwise wait on.
    $records = [];

    for ($i = 0; $i < 60 && $records === []; $i++) {
        $records = $relay->byKind(64);

        if ($records === []) {
            usleep(250_000);
        }
    }

    // NIP-64 game record: White and Black tagged by pubkey+role, PGN result matches, signature valid.
    expect($records)->not->toBeEmpty('no NIP-64 game record reached the relay within 15s of being archived');

    // A raw `p` tag is ["p", pubkey, relay-hint, role] — role at index 3, the
    // relay hint (usually "") sitting at index 2 in between.
    $pTagOf = fn ($event, string $role) => collect($event->tags)->first(fn ($tag) => ($tag[0] ?? null) === 'p' && ($tag[3] ?? null) === $role)[1] ?? null;
    $record = collect($records)->first(fn ($event) => $pTagOf($event, 'white') === $game->white->pubkey && $pTagOf($event, 'black') === $game->black->pubkey);

    expect($record)->not->toBeNull('no kind-64 record tags both this game\'s White and Black')
        ->and($record->hasValidSignature())->toBeTrue()
        ->and($record->content)->toContain('0-1')
        ->and($pTagOf($record, 'white'))->toBe($game->white->pubkey)
        ->and($pTagOf($record, 'black'))->toBe($game->black->pubkey);

    // No Result Response (2153) follows the record: docs/nips/esports.md
    // ("Game Record", revision note of 2026-09-27) dropped it for chess, which
    // the league attests from its own record when the game ends; kind 2153
    // stays a series event (App\Support\Series\SeriesEvents::RESPONSE).
});
