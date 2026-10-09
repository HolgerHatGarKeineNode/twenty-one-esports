<?php

use App\Enums\HyperEndReason;
use App\Enums\HyperMatchStatus;
use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Events\HyperMatchUpdated;
use App\Games\Hyperbitcoinization;
use App\Models\Admin;
use App\Models\Clan;
use App\Models\HyperMatch;
use App\Models\HyperRatingChange;
use App\Models\HyperSeat;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\Hyper\HyperTournamentTeams;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use App\Support\Tournaments\TournamentBrackets;
use App\Support\Tournaments\TournamentControl;
use App\Support\Tournaments\TournamentRunner;
use App\Support\Tournaments\TournamentWaits;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Tests\Support\HyperOn;

/*
| The league's desk on a Hyperbitcoinization tournament (plan "Hyperbitcoinization", P5c): restarting a round, calling
| the tournament off and correcting a result void the table that no longer counts (ended unrated, in no season, its
| open pages told) and seat a new one where the match is played again. A held match shows on the desk. A table whose
| report to the bracket failed is reported again by the clock sweep.
*/

beforeEach(function () {
    $this->withoutVite();
    HyperOn::play();
});

function hyperDeskAdmin(): User
{
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    return $admin;
}

/** The latest table of a tournament match. */
function hyperTableOf(TournamentMatch $match): ?HyperMatch
{
    return HyperMatch::query()->where('tournament_match_id', $match->id)->with('seats')->latest('id')->first();
}

/** A running 2v2 clan bracket of two clans of two (the tables start at once: nobody to name). */
function hyperDeskClans(): Tournament
{
    $profile = GameProfile::for(Hyperbitcoinization::SLUG, 'live');
    $tournament = Tournament::factory()->create([
        'game' => Hyperbitcoinization::SLUG, 'mode' => 'live', 'format' => TournamentFormat::SingleElimination,
        'options' => FormatOptions::fromArray(['teamSize' => 2], $profile)->toArray(),
        'capacity' => 2, 'results_mode' => TournamentResultsMode::Players, 'status' => TournamentStatus::Running,
        'slug' => 'hyper-desk-'.fake()->unique()->numberBetween(1, 1_000_000), 'ladder_address' => null,
    ]);

    foreach (range(0, 1) as $index) {
        $players = User::factory()->count(2)->create()->values()->all();
        $clan = Clan::factory()->create(['owner_id' => $players[0]->id]);
        array_map(fn (User $player): User => HyperOn::inClan($player, $clan), $players);
        TournamentParticipant::query()->create([
            'tournament_id' => $tournament->id, 'lineup_id' => HyperTournamentTeams::lineup($clan, 'live')->id, 'name' => $clan->name,
            'rating' => 1500 - 10 * $index, 'members' => array_map(fn (User $user): int => $user->id, $players),
        ]);
    }

    app(TournamentBrackets::class)->generate($tournament, str_repeat('ab', 32));
    app(TournamentRunner::class)->sync($tournament);

    return $tournament->refresh();
}

/**
 * The voided table: ended `aborted`/`voided`, unrated, in no season, no places and no points, its match superseded.
 */
function expectHyperVoided(HyperMatch $table, TournamentMatch $match): void
{
    $table->refresh()->load('seats');

    expect($table->status)->toBe(HyperMatchStatus::Aborted)
        ->and($table->end_reason)->toBe(HyperEndReason::Voided)
        ->and($table->rated)->toBeFalse()
        ->and($table->season)->toBeNull()
        ->and($table->ended_at)->not->toBeNull()
        ->and($table->seats->every(fn (HyperSeat $seat): bool => $seat->place === null && $seat->points === null))->toBeTrue()
        ->and(HyperRatingChange::query()->where('hyper_match_id', $table->id)->exists())->toBeFalse()
        ->and($match->refresh()->replaced_through)->toBe($table->id)
        ->and($match->isReplaced($table->id))->toBeTrue();
}

test('restarting a free-for-all round voids its running table, tells its open pages, and seats a new rated table', function () {
    Event::fake([HyperMatchUpdated::class]);
    $season = openSeason(ladders: false);
    $admin = hyperDeskAdmin();
    $tournament = HyperOn::tournament(4, TournamentFormat::FreeForAll, ['heatSize' => 4, 'heatAdvance' => 1]);
    $match = TournamentMatch::query()->where('tournament_id', $tournament->id)->where('bracket', '!=', 'bye')->sole();
    $old = hyperTableOf($match);

    expect($old->rated)->toBeTrue()->and($old->status)->toBe(HyperMatchStatus::Active);

    expect(app(TournamentControl::class)->restartRound($tournament, $admin, $match->tournament_round_id, 0, 'Server outage'))->toBeTrue();

    expectHyperVoided($old, $match);
    $new = hyperTableOf($match);

    expect($new->id)->toBeGreaterThan($old->id)
        ->and($new->status)->toBe(HyperMatchStatus::Active)
        ->and($new->rated)->toBeTrue()
        ->and($new->season)->toBe($season->slug)
        ->and($new->seats->pluck('user_id')->all())->toBe($old->seats->pluck('user_id')->all());
    // The open pages of the old table hear that it ended.
    Event::assertDispatched(HyperMatchUpdated::class, fn (HyperMatchUpdated $event): bool => $event->payload['match'] === $old->ulid && $event->payload['status'] === 'aborted');

    // The old table's late end changes nothing; the new one's moves the bracket.
    app(TournamentRunner::class)->hyperMatchFinished($old->id);
    expect($match->refresh()->result)->toBeNull();

    HyperOn::finishTable($new, [1, 0, 2, 3]);
    expect($match->refresh()->result['hyper'])->toBe($new->ulid)
        ->and($tournament->refresh()->status)->toBe(TournamentStatus::Finished);
});

test('calling the tournament off voids every running table: unrated, no points, no Elo', function () {
    openSeason(ladders: false);
    $admin = hyperDeskAdmin();
    $tournament = HyperOn::tournament(4, TournamentFormat::SingleElimination);
    $matches = TournamentMatch::query()->where('tournament_id', $tournament->id)->whereHas('hyperMatch')->orderBy('id')->get();

    expect($matches)->toHaveCount(2);
    $tables = $matches->map(fn (TournamentMatch $match): HyperMatch => hyperTableOf($match));

    expect(app(TournamentControl::class)->abort($tournament, $admin, 'Venue lost'))->toBeTrue();

    foreach ($matches as $index => $match) {
        expectHyperVoided($tables[$index], $match);
    }

    expect(HyperMatch::query()->count())->toBe(2)
        ->and(HyperMatch::query()->where('status', HyperMatchStatus::Active)->exists())->toBeFalse();
});

test('a corrected 1v1 result voids the running final and seats the new finalist; a finished final is held, shown on the desk, and a restart plays it again', function () {
    openSeason(ladders: false);
    $admin = hyperDeskAdmin();
    $control = app(TournamentControl::class);
    $tournament = HyperOn::tournament(4, TournamentFormat::SingleElimination);
    [$semi1, $semi2] = TournamentMatch::query()->where('tournament_id', $tournament->id)->whereHas('hyperMatch')->orderBy('id')->get()->all();

    HyperOn::finishTable(hyperTableOf($semi1), [0, 1]);
    HyperOn::finishTable(hyperTableOf($semi2), [0, 1]);
    $final = TournamentMatch::query()->where('tournament_id', $tournament->id)->whereHas('hyperMatch')->whereKeyNot([$semi1->id, $semi2->id])->sole();
    $firstFinal = hyperTableOf($final);
    $loser = $semi1->slots()->where('slot', 1)->with('participant')->sole()->participant;

    expect($firstFinal->status)->toBe(HyperMatchStatus::Active);

    // Semi 1 really went to slot 1: the final's side changes while its table runs.
    $control->setResult($tournament, $admin, $semi1->id, ['noshow' => 0], 'Wrong winner reported');

    expectHyperVoided($firstFinal, $final);
    $secondFinal = hyperTableOf($final);

    expect($secondFinal->id)->toBeGreaterThan($firstFinal->id)
        ->and($secondFinal->status)->toBe(HyperMatchStatus::Active)
        ->and($secondFinal->seats->pluck('user_id')->all())->toContain($loser->user_id);

    // The new final is played to its end; then semi 1 is corrected back: the final is held, its table superseded.
    HyperOn::finishTable($secondFinal, [0, 1]);
    expect($final->refresh()->result)->not->toBeNull();

    $control->setResult($tournament, $admin, $semi1->id, ['noshow' => 1], 'The first report was right');
    $final->refresh();

    expect($final->result)->toBeNull()
        ->and($final->held)->not->toBeNull()
        ->and($final->isReplaced($secondFinal->id))->toBeTrue()
        ->and($secondFinal->refresh()->status)->toBe(HyperMatchStatus::Finished)
        ->and(hyperTableOf($final)->id)->toBe($secondFinal->id);

    // The desk shows it: set its result or restart its round.
    $wait = collect(TournamentWaits::of($tournament->refresh()))->firstWhere('matchId', $final->id);
    expect($wait?->state)->toBe('held')
        ->and($wait->consequence)->toBe('Set its result or restart its round')
        ->and($wait->needsAdmin)->toBeTrue();

    expect($control->restartRound($tournament, $admin, $final->tournament_round_id, 0, 'Replay the final'))->toBeTrue();
    $third = hyperTableOf($final);

    expect($third->id)->toBeGreaterThan($secondFinal->id)
        ->and($third->status)->toBe(HyperMatchStatus::Active)
        ->and($final->refresh()->held)->toBeNull();
});

test('a held free-for-all table shows on the desk with a restart only', function () {
    $tournament = HyperOn::tournament(4, TournamentFormat::FreeForAll, ['heatSize' => 4, 'heatAdvance' => 1]);
    $match = TournamentMatch::query()->where('tournament_id', $tournament->id)->where('bracket', '!=', 'bye')->sole();

    expect(TournamentWaits::forMatch($tournament, $match->load('slots.participant')))->toBeNull();

    $match->forceFill(['held' => ['was' => null, 'reason' => 'x', 'at' => now()->toIso8601String(), 'user_id' => null, 'name' => 'Admin']])->save();
    $wait = TournamentWaits::forMatch($tournament, $match->refresh()->load('slots.participant'));

    expect($wait?->state)->toBe('held')
        ->and($wait->consequence)->toBe('Restart its round');
});

test('restarting a clan bracket round voids the running team table and seats the same clans again', function () {
    openSeason(ladders: false);
    $admin = hyperDeskAdmin();
    $tournament = hyperDeskClans();
    $match = TournamentMatch::query()->where('tournament_id', $tournament->id)->where('bracket', '!=', 'bye')->sole();
    $old = hyperTableOf($match);

    expect($old)->not->toBeNull()->and($old->isTeamMatch())->toBeTrue()->and($old->rated)->toBeTrue();

    expect(app(TournamentControl::class)->restartRound($tournament, $admin, $match->tournament_round_id, 0, 'Server outage'))->toBeTrue();

    expectHyperVoided($old, $match);
    $new = hyperTableOf($match);

    expect($new->id)->toBeGreaterThan($old->id)
        ->and($new->isTeamMatch())->toBeTrue()
        ->and($new->team_clans)->toBe($old->team_clans)
        ->and($new->status)->toBe(HyperMatchStatus::Active);
});

test('a finished table whose report to the bracket failed ends for the players all the same, and the clock sweep reports it again', function () {
    Exceptions::fake();
    $tournament = HyperOn::tournament(4, TournamentFormat::FreeForAll, ['heatSize' => 4, 'heatAdvance' => 1]);
    $match = TournamentMatch::query()->where('tournament_id', $tournament->id)->where('bracket', '!=', 'bye')->sole();
    $table = hyperTableOf($match);
    $fail = true;
    // The bracket's database hiccups once, while the report after the commit reads the tournament.
    Tournament::retrieved(function () use (&$fail): void {
        if ($fail) {
            $fail = false;

            throw new RuntimeException('Bracket store unavailable');
        }
    });

    $ended = HyperOn::finishTable($table, [1, 0, 2, 3]);

    expect($ended->status)->toBe(HyperMatchStatus::Finished)
        ->and($fail)->toBeFalse()
        ->and($match->refresh()->result)->toBeNull();
    Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'Bracket store unavailable');

    // Not before its grace: the request that ended it may still be reporting.
    $this->artisan('hyper:check-clocks')->assertSuccessful();
    expect($match->refresh()->result)->toBeNull();

    $this->travel(31)->seconds();
    $this->artisan('hyper:check-clocks')->expectsOutputToContain('reported 1 tournament table(s)')->assertSuccessful();

    expect($match->refresh()->result['hyper'] ?? null)->toBe($table->ulid)
        ->and($tournament->refresh()->status)->toBe(TournamentStatus::Finished);

    // Once reported, never again.
    $this->artisan('hyper:check-clocks')->expectsOutputToContain('reported 0 tournament table(s)')->assertSuccessful();
});

test('restarting the round of a table that finished but never reached the bracket seats a new table, and the old one\'s late report is ignored', function () {
    Exceptions::fake();
    $admin = hyperDeskAdmin();
    $tournament = HyperOn::tournament(4, TournamentFormat::FreeForAll, ['heatSize' => 4, 'heatAdvance' => 1]);
    $match = TournamentMatch::query()->where('tournament_id', $tournament->id)->where('bracket', '!=', 'bye')->sole();
    $old = hyperTableOf($match);
    $fail = true;
    Tournament::retrieved(function () use (&$fail): void {
        if ($fail) {
            $fail = false;

            throw new RuntimeException('Bracket store unavailable');
        }
    });

    HyperOn::finishTable($old, [1, 0, 2, 3]);
    expect($match->refresh()->result)->toBeNull();

    expect(app(TournamentControl::class)->restartRound($tournament, $admin, $match->tournament_round_id, 0, 'The table never reported'))->toBeTrue();
    $new = hyperTableOf($match);

    expect($old->refresh()->status)->toBe(HyperMatchStatus::Finished)
        ->and($match->refresh()->isReplaced($old->id))->toBeTrue()
        ->and($new->id)->toBeGreaterThan($old->id)
        ->and($new->status)->toBe(HyperMatchStatus::Active);

    // The sweep leaves the superseded table alone.
    $this->travel(31)->seconds();
    $this->artisan('hyper:check-clocks')->expectsOutputToContain('reported 0 tournament table(s)')->assertSuccessful();
    expect($match->refresh()->result)->toBeNull();
});
