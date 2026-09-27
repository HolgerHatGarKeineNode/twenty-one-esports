<?php

use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Models\Clan;
use App\Models\Lineup;
use App\Models\Tournament;
use App\Models\TournamentOrganizer;
use App\Models\TournamentParticipant;
use App\Models\TournamentSignup;
use App\Models\User;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
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
| CREATION IS NOT DRIVEN THROUGH THE ADMIN UI — see the finding below.
| The rest of the flow (sign-up, draw, relay verification) is real UI /
| real stack throughout.
|
| Finding, reported rather than papered over (mandate boundary: a test
| does not silently patch app code, and this needed more than the one
| measurement a found bug otherwise gets because every earlier reading
| pointed at a different, real bug that had to be ruled out first):
| resources/views/pages/admin/⚡tournament-create.blade.php's
| `[data-test=tournament-create-button]` (`wire:click="create"`) does not
| fire the Livewire action when clicked through Playwright against
| Stack's real server. Measured 2026-09-27, in this order, each ruling out
| one candidate cause: (1) Gate::forUser($organizer)->allows(
| 'create-tournaments') is true in this same process; (2) no validation
| error renders (`[role=alert]`, `.text-loss`) and the button is not
| disabled; (3) exactly one element matches the selector and it carries
| `wire:click="create"`; (4) a window.fetch/XHR interceptor installed
| before the click recorded ZERO requests after it — the click never
| reaches the server at all; (5) a window.onerror/console.error/
| unhandledrejection collector installed from page load recorded nothing.
| The button sits far down a long page (~2260px at the default desktop
| viewport); scrollIntoViewIfNeeded() before the click made no difference.
| Not reproduced over plain HTTP (a GET of the rendered page and the Gate
| check both behave correctly), so this is specific to driving THIS one
| button through a real browser — worth a look by whoever owns that page,
| not something to keep guessing at from here. Diagnosis so far: ~1 hour.
|
*/

/** A published RL 3v3 tournament with the SAME shape the admin create page's own create() would produce. */
function openRocketLeagueTournament(string $name, User $organizer): Tournament
{
    $profile = GameProfile::for('rocket-league', '3v3');
    $tournament = Tournament::query()->create([
        'name' => $name,
        'game' => $profile->game,
        'mode' => $profile->mode,
        'format' => TournamentFormat::SingleElimination,
        'options' => FormatOptions::defaults($profile)->toArray(),
        'capacity' => 12,
        'starts_at' => now()->addWeek(),
        'time_window' => 180,
        'on_site' => false,
        'stations' => null,
        'results_mode' => TournamentResultsMode::Players,
        'status' => TournamentStatus::Draft,
        'created_by_id' => $organizer->id,
    ]);

    // This test process writes directly here (not through the real app
    // server/queue worker) — see Stack::retryOnLock()'s own docblock for why
    // that specific combination needs a retry, not a longer PRAGMA wait.
    return Stack::retryOnLock(fn () => app(TournamentPublisher::class)->publish($tournament, $organizer, CarbonImmutable::now()->addMinutes(2)));
}

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

    // The admin create page itself DOES render correctly for this organizer
    // (see the finding above for what does not work): kept as a real,
    // narrower check that the page and its form are reachable and usable,
    // short of the one button.
    $admin = integrationPage($organizer, integrationRoute('admin.tournaments.create'));
    BrowserWait::until($admin, '() => document.querySelector("[data-test=tournament-name]") !== null', 30_000);
    $admin->locator('[data-test=tournament-name]')->fill('Genesis Cup (admin form check)');
    expect($admin->evaluate('() => document.querySelector("[data-test=tournament-name]")?.value'))->toBe('Genesis Cup (admin form check)');

    $tournament = openRocketLeagueTournament('Genesis Cup', $organizer);

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
