<?php

use App\Enums\TournamentStatus;
use App\Models\Clan;
use App\Models\Lineup;
use App\Models\Tournament;
use App\Models\TournamentOrganizer;
use App\Models\TournamentParticipant;
use App\Models\TournamentSignup;
use App\Support\Tournaments\TournamentPublisher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Tests\Integration\Support\RelayCheck;
use Tests\Integration\Support\Stack;
use Tests\Support\BrowserWait;

pest()->group('integration');

/*
|--------------------------------------------------------------------------
| P15: a tournament from creation through sign-up to a resolved draw
|--------------------------------------------------------------------------
|
| Against the real stack: a Rocket League 3v3 tournament, signed up by a
| clan lineup AND a full solo pool (both paths P15 asks for), closed and
| drawn from the fake Bitcoin block API (never mempool.space), the mix
| team's composition checked, and the resulting Tournament Draw (2155)
| checked against the relay: kind, tags (entrants, draw height, teams,
| format), signature.
|
| Creation is driven through the admin UI's real "Create tournament"
| button. click() returns once the browser has dispatched the event; the
| Livewire roundtrip it starts (the POST to Livewire's update route, then the redirect
| to admin.tournaments) finishes asynchronously, against a server in a
| separate process. Anything read straight after click() — the URL, the
| DB row, a fetch interceptor that records on response — therefore still
| sees the old state; the test waits for the redirect first. Measured
| 2026-09-27: read immediately, 5/5 clicks showed no resolved request, the
| old URL and no row; after waiting for the redirect, 5/5 had the row.
|
*/

test('a Rocket League 3v3 tournament: creation, clan + solo-pool sign-up, and a blockhash draw verified on the relay', function () {
    // Pest\Browser\Support\BrowserTestIdentifier scans THIS closure's own
    // source for a literal `visit(` call to decide whether to start the
    // Playwright driver; integrationPage() in a different file does not
    // qualify (see the same note in TeamMatchReadyTest.php).
    if (false) {
        visit('');
    }

    $stack = Stack::instance();

    [$organizer] = integrationPlayer('tournament-organizer');
    TournamentOrganizer::query()->create(['pubkey' => $organizer->pubkey]);
    expect(Gate::forUser($organizer)->allows('create-tournaments'))->toBeTrue();

    // The organizer creates the tournament through the admin UI: an RL 3v3
    // draft for the default 12 teams, in the recommended format.
    $admin = integrationPage($organizer, integrationRoute('admin.tournaments.create'));
    BrowserWait::until($admin, '() => document.querySelector("[data-test=tournament-name]") !== null', 30_000);
    $admin->locator('[data-test=tournament-name]')->fill('Genesis Cup');
    $admin->locator('[data-test=game-rocket-league] button:has-text("3v3")')->click();
    BrowserWait::until($admin, '() => document.querySelector("[data-test=game-rocket-league] button[aria-checked=true]")?.textContent.trim() === "3v3"', 30_000);
    $admin->locator('[data-test=tournament-create-button]')->click();
    // See the docblock: the create() roundtrip is asynchronous to click().
    BrowserWait::until($admin, '() => location.pathname === "/admin/tournaments"', 30_000);

    $tournament = Tournament::query()->where('name', 'Genesis Cup')->sole();
    expect($tournament->game)->toBe('rocket-league')
        ->and($tournament->mode)->toBe('3v3')
        ->and($tournament->status)->toBe(TournamentStatus::Draft)
        ->and($tournament->created_by_id)->toBe($organizer->id);

    // Publishing is not part of this flow's UI coverage. This test process
    // writes directly here (not through the real app server/queue worker) —
    // see Stack::retryOnLock()'s own docblock for why that specific
    // combination needs a retry, not a longer PRAGMA wait.
    $tournament = Stack::retryOnLock(fn () => app(TournamentPublisher::class)->publish($tournament, $organizer, CarbonImmutable::now()->addMinutes(2)));

    // A clan's Ready lineup signs up.
    [$captain] = integrationPlayer('rl-captain');
    $clan = Clan::factory()->create(['owner_id' => $captain->id]);
    Lineup::factory()->mode('3v3')->ready()->create(['clan_id' => $clan->id]);

    $captainPage = integrationPage($captain, integrationRoute('tournaments.signup', $tournament));
    BrowserWait::until($captainPage, '() => document.querySelector("[data-test=enter-lineup]") !== null', 30_000);
    $captainPage->locator('[data-test=enter-lineup]')->click();
    BrowserWait::until($captainPage, '() => document.querySelector("[data-test=my-entry]") !== null', 30_000);

    // A full solo pool of three signs up individually.
    $soloUsers = [];

    foreach (['solo-one', 'solo-two', 'solo-three'] as $name) {
        [$solo] = integrationPlayer($name);
        $soloUsers[] = $solo;
        $soloPage = integrationPage($solo, integrationRoute('tournaments.signup', $tournament));
        BrowserWait::until($soloPage, '() => document.querySelector("[data-test=enter-solo]") !== null', 30_000);
        $soloPage->locator('[data-test=enter-solo]')->click();
        BrowserWait::until($soloPage, '() => document.querySelector("[data-test=my-entry]") !== null', 30_000);
    }

    expect(TournamentSignup::query()->where('tournament_id', $tournament->id)->active()->count())->toBe(4);

    // Close sign-up (real wall-clock aside: the close time is written directly,
    // the same way the DB is otherwise shared with the real server) and mine
    // the block the draw commits to on the fake API — never mempool.space.
    $tournament->forceFill(['signup_closes_at' => now()->subSecond()])->save();
    $stack->artisan('tournaments:advance');

    $tournament->refresh();
    expect($tournament->draw_height)->not->toBeNull();

    // TournamentDraws::resolve() only accepts a block mined strictly AFTER
    // signup_closes_at/draw_committed_at was written; both are second-
    // granularity `now()` calls a few lines apart in this same fast test, so
    // relying on real elapsed time risks the same second and a spurious
    // "mined before the commitment" re-commit instead of a resolve.
    $hash = $stack->bitcoin->mine($tournament->draw_height, now()->addSecond()->getTimestamp());
    $stack->artisan('tournaments:advance');

    $tournament->refresh();
    expect($tournament->draw_hash)->toBe($hash)
        ->and($tournament->status->value)->toBe('running');

    $participants = TournamentParticipant::query()->where('tournament_id', $tournament->id)->get();
    expect($participants)->toHaveCount(2);

    $mixTeam = $participants->firstWhere('lineup_id', null);
    expect($mixTeam)->not->toBeNull()
        ->and(collect($mixTeam->members)->sort()->values()->all())->toBe(collect($soloUsers)->pluck('id')->sort()->values()->all());

    // Publishing runs through the real queue worker (PublishNostrEvent), one
    // more asynchronous hop after the DB state already checked above — the
    // same reason tests/Integration/BlitzGameFlowTest.php polls before its
    // own relay check.
    $relay = new RelayCheck($stack->relayUrl);
    $draws = [];

    for ($i = 0; $i < 60 && $draws === []; $i++) {
        $draws = $relay->events('-k 2155 -t a='.escapeshellarg((string) $tournament->address()));

        if ($draws === []) {
            usleep(250_000);
        }
    }

    expect($draws)->not->toBeEmpty('no Tournament Draw (2155) for this tournament reached the relay within 15s');

    $draw = $draws[0];
    expect($draw->kind)->toBe(2155)
        ->and($draw->hasValidSignature())->toBeTrue()
        ->and($draw->tag('draw'))->toBe((string) $tournament->draw_height)
        ->and($draw->tag('teams'))->toBe('3')
        ->and($draw->tag('format'))->not->toBeNull();

    $entrantTags = collect($draw->tagsNamed('p'))->pluck(0)->all();
    foreach ($soloUsers as $solo) {
        expect($entrantTags)->toContain($solo->pubkey);
    }
});
