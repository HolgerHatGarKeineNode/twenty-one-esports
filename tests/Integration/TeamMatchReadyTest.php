<?php

use App\Enums\SeriesStatus;
use App\Models\Clan;
use App\Models\Lineup;
use App\Models\SeriesMatch;
use App\Models\User;
use App\Support\Series\Ladders;
use Pest\Browser\Playwright\Page;
use Tests\Integration\Support\RelayCheck;
use Tests\Integration\Support\Stack;
use Tests\Support\BrowserWait;

pest()->group('integration');

/*
|--------------------------------------------------------------------------
| P15: a rated Rocket League 3v3 team match, two Ready lineups
|--------------------------------------------------------------------------
|
| Against the real stack (Tests\Integration\Support\Stack): real app
| server, real Reverb, real queue worker, a real local `nak serve` relay.
| Two clans' Ready lineups (LineupFactory::ready(), the "Ready" state P15
| asks for), their captains and every seated player Trusted, the two
| captains listing each other (App\Support\SeasonChain\RatedTrustGate), so
| the match is rated end to end: Challenge (2150) -> Answer (2151) ->
| Result Report (2152) -> Result Response (2153) -> League Attestation
| (2154), each checked against the relay, not the app's own archive. Two
| series in a row so the second Attestation's `prev` can be checked
| against the first (NIP "Season chain"/"League Attestation").
|
| The match's `start_at` (10 real minutes out, esports.series.now_minutes)
| is moved to the past directly in the DB this process shares with the
| real server, rather than waiting 10 real minutes twice: Carbon::travel()
| only affects this PHP process, never the separate `php artisan serve`.
|
*/

function teamPage(User $user, string $url, int $width = 1440): Page
{
    // The viewport is set on the CONTEXT before the page's own JS runs
    // (integrationPage()'s first navigation), not via a later, separate
    // setViewportSize() call: a resize mid-hydration on a page whose assets
    // are still loading through Stack's single-threaded real server is one
    // more plausible source of the client-side timing flake documented at
    // integrationPage()/INTEGRATION_NAV_TIMEOUT_MS.
    return integrationPage($user, $url, ['width' => $width, 'height' => 900]);
}

function enterSeriesGoals(Page $page, int $game, int $challenger, int $challenged): void
{
    $page->locator("[data-test=goals-{$game}-c]")->fill((string) $challenger);
    $page->locator("[data-test=goals-{$game}-c]")->press('Tab');
    $page->locator("[data-test=goals-{$game}-d]")->fill((string) $challenged);
    $page->locator("[data-test=goals-{$game}-d]")->press('Tab');
}

/**
 * Challenge (A) -> Accept (B) -> Report (A) -> Confirm (B), rated, through
 * the real UI on the real server. Returns the confirmed SeriesMatch.
 */
function playRatedSeries(User $captainA, User $captainB): SeriesMatch
{
    $pageA = teamPage($captainA, integrationRoute('challenges.create'), 1440);
    BrowserWait::until($pageA, '() => document.querySelector("[data-test=pick-opponent]") !== null', 30_000);
    $pageA->locator('[data-test=pick-opponent]')->click();
    // resources/views/pages/challenges/⚡create.blade.php's enabled "Rated"
    // radio (unlike its "Casual" sibling, data-test="type-casual") carries no
    // data-test attribute at all: measured 2026-09-27, a locator on a
    // selector matching nothing does not "fail fast", it waits out the
    // FULL client timeout (confirmed at both 30s and 60s) before throwing —
    // indistinguishable from a real hang until read this way. Selecting by
    // its own text, scoped to the radio group, needs no app change.
    $pageA->locator('[role=radiogroup] button:has-text("Rated")')->click();
    BrowserWait::until($pageA, '() => [...document.querySelectorAll("[role=radiogroup] button")].find((b) => b.textContent.includes("Rated"))?.getAttribute("aria-checked") === "true"', 30_000);
    $pageA->locator('[data-test=challenge-now]')->click();
    BrowserWait::until($pageA, '() => document.querySelector("[data-test=send-challenge]") && ! document.querySelector("[data-test=send-challenge]").disabled', 30_000);
    $pageA->locator('[data-test=send-challenge]')->click();
    BrowserWait::until($pageA, '() => document.querySelector("[data-test=withdraw-challenge]") !== null', 30_000);

    $match = SeriesMatch::query()->where('status', SeriesStatus::Open)->latest('id')->first();
    expect($match)->not->toBeNull()->and($match->rated)->toBeTrue();

    $pageB = teamPage($captainB, integrationRoute('matches.room', $match), 375);
    BrowserWait::until($pageB, '() => document.querySelector("[data-test=accept-challenge]") !== null', 30_000);
    $pageB->locator('[data-test=accept-challenge]')->click();
    BrowserWait::until($pageB, '() => document.querySelector("[data-test=accept-challenge]") === null', 30_000);
    expect($match->refresh()->status)->toBe(SeriesStatus::Accepted);

    // Past its picked start (esports.series.now_minutes ahead): the DB write
    // is real, the real server reads it on the very next request.
    $match->forceFill(['start_at' => now()->subMinute()])->save();

    $pageA->reload();
    BrowserWait::until($pageA, '() => !! document.querySelector("[data-test=open-submit]")', 30_000);
    // BO3: the series is decided once a side reaches 2 GAME wins, not by
    // inflating one game's own score — a challenger sweep needs both games
    // entered (SeriesResultTest.php's own casual test does the same: two
    // enterGoals() calls, one per game).
    enterSeriesGoals($pageA, 0, 3, 1);
    BrowserWait::until($pageA, '() => document.querySelector("[data-test=series-score]")?.innerText.trim() === "1 : 0"', 15_000);
    enterSeriesGoals($pageA, 1, 2, 0);
    BrowserWait::until($pageA, '() => document.querySelector("[data-test=series-score]")?.innerText.trim() === "2 : 0"', 15_000);
    $pageA->locator('[data-test=open-submit]')->click();
    BrowserWait::until($pageA, '() => document.querySelector("[data-test=submit-dialog]")?.offsetParent !== null', 30_000);

    $pageA->locator('[data-test=confirm-submit]')->click();
    BrowserWait::until($pageA, '() => document.querySelector("[data-test=waiting-for-ok]") !== null', 30_000);
    expect($match->refresh()->status)->toBe(SeriesStatus::Reported);

    $pageB->reload();
    BrowserWait::until($pageB, '() => document.querySelector("[data-test=accept-result]") !== null', 30_000);
    $pageB->locator('[data-test=accept-result]')->click();
    BrowserWait::until($pageB, '() => document.querySelector("[data-test=win-moment]") !== null', 30_000);

    return $match->refresh();
}

test('two Ready lineups play a rated series, and the second League Attestation chains to the first', function () {
    // Pest\Browser\Support\BrowserTestIdentifier marks a test as browser-using
    // (and starts the Playwright driver) only by scanning THIS closure's own
    // source for a literal `visit(` call; integrationPage() in a different
    // file calling it does not qualify, and without this the driver never
    // starts ("Call to a member function sendText() on null").
    if (false) {
        visit('');
    }

    integrationOpenSeason();

    [$captainA, $signerA] = integrationPlayer('captain-alpha');
    $clanA = Clan::factory()->create(['owner_id' => $captainA->id]);
    $lineupA = Lineup::factory()->mode('3v3')->ready()->create(['clan_id' => $clanA->id])->load('seats.user');

    [$captainB, $signerB] = integrationPlayer('captain-bravo');
    $clanB = Clan::factory()->create(['owner_id' => $captainB->id]);
    $lineupB = Lineup::factory()->mode('3v3')->ready()->create(['clan_id' => $clanB->id])->load('seats.user');

    $pubkeys = $lineupA->seats->pluck('user.pubkey')->merge($lineupB->seats->pluck('user.pubkey'))->all();
    integrationTrust($pubkeys);
    integrationMutualList($captainA, $signerA, $captainB, $signerB);

    expect(Ladders::isOpen('rocket-league', '3v3'))->toBeTrue();

    $first = playRatedSeries($captainA, $captainB);
    $second = playRatedSeries($captainA, $captainB);

    expect($first->resolution->value)->toBe('confirmed')
        ->and($second->resolution->value)->toBe('confirmed');

    $relay = new RelayCheck(Stack::instance()->relayUrl);

    // Every kind here is published by PublishNostrEvent through the real
    // queue worker, one more asynchronous hop after the UI already shows the
    // match resolved (the same reason BlitzGameFlowTest.php and
    // TournamentFlowTest.php poll before their own relay checks) — this one
    // did not, and measured 2026-09-27 that gap: a second `composer
    // test:integration` run in a row (queue worker still catching up on the
    // first run's backlog of jobs) saw only 1 of 2 kind-2152 events at the
    // moment of the check. Poll for the expected count instead of asserting
    // immediately.
    $pollForCount = function (int $kind, int $min) use ($relay): array {
        $events = [];

        for ($i = 0; $i < 120 && count($events) < $min; $i++) {
            $events = $relay->byKind($kind);

            if (count($events) < $min) {
                usleep(250_000);
            }
        }

        return $events;
    };

    // Challenge (2150): both sides tagged, ladder referenced, signature valid.
    $challenges = $pollForCount(2150, 2);
    expect(count($challenges))->toBeGreaterThanOrEqual(2, 'expected at least 2 events of kind 2150 on the relay within 30s');
    expect($challenges[0]->kind)->toBe(2150)
        ->and($challenges[0]->tagsNamed('a'))->not->toBeEmpty()
        ->and($challenges[0]->hasValidSignature())->toBeTrue();

    // Answer (2151), Report (2152), Response (2153): all present, all valid.
    foreach ([2151, 2152, 2153] as $kind) {
        $events = $pollForCount($kind, 2);
        expect(count($events))->toBeGreaterThanOrEqual(2, "expected at least 2 events of kind {$kind} on the relay within 30s")
            ->and($events[0]->hasValidSignature())->toBeTrue();
    }

    // League Attestation (2154): resolution/winner tags present, and the
    // newest one's `prev` points at a real, signature-valid earlier link.
    $attestations = $pollForCount(2154, 2);
    expect(count($attestations))->toBeGreaterThanOrEqual(2, 'expected at least 2 events of kind 2154 on the relay within 30s');

    foreach ($attestations as $attestation) {
        expect($attestation->tag('resolution'))->not->toBeNull()
            ->and($attestation->tag('winner'))->not->toBeNull()
            ->and($attestation->hasValidSignature())->toBeTrue();
    }

    $newest = collect($attestations)->sortByDesc(fn ($event) => $event->createdAt)->first();
    $prev = $newest->tag('prev');
    expect($prev)->not->toBeNull('the second attestation of a live season must reference the first via `prev`');

    $chain = $relay->chain($newest);
    expect(count($chain))->toBeGreaterThanOrEqual(2)
        ->and($chain[0]->hasValidSignature())->toBeTrue()
        ->and($chain[count($chain) - 1]->id)->toBe($newest->id);
});
