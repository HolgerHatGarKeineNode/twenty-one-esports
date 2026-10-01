<?php

/*
| Score games (plan "AoE2 und Trackmania", P4), the pages: an organizer
| creates a leaderboard with the format chooser, a player submits a value
| with a proof link, an admin approves or rejects it, a director corrects a
| value with a reason and ends the leaderboard. The player's game account id
| never shows on a public page nor in a Nostr event. ScoreDemoOn switches the
| demo on.
*/

use App\Enums\TournamentFormat;
use App\Enums\TournamentStatus;
use App\Models\Admin;
use App\Models\NostrEvent;
use App\Models\ScoreRun;
use App\Models\Tournament;
use App\Models\TournamentOrganizer;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\LeagueTime;
use App\Support\Scores\ManualSubmissions;
use App\Support\Scores\ScoreLeaderboards;
use App\Support\Scores\ScoreRuns;
use App\Support\Scores\ScoreServers;
use App\Support\Tournaments\TournamentBrackets;
use App\Support\Tournaments\TournamentPublisher;
use App\Support\Tournaments\TournamentRunner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\ScoreDemoOn;
use Tests\Support\TestSigner;

const SCORE_ACCOUNT = 'acct-7f3e9c21-private';

/**
 * A published, running leaderboard of the score demo with three players; the first stored a private account id.
 *
 * @return array{0: Tournament, 1: list<User>}
 */
function publishedScoreBoard(): array
{
    $organizer = User::factory()->create();
    TournamentOrganizer::query()->create(['pubkey' => $organizer->pubkey]);
    $tournament = Tournament::factory()->scoreDemo()->create(['created_by_id' => $organizer->id, 'starts_at' => now()->addDay()]);
    app(TournamentPublisher::class)->publish($tournament, $organizer, CarbonImmutable::now()->addHours(12));
    $users = [];

    foreach (range(1, 3) as $index) {
        $users[] = $user = User::factory()->create(['name' => "racer{$index}", 'gamer_tags' => $index === 1 ? ['score-demo' => SCORE_ACCOUNT] : null]);
        TournamentParticipant::query()->create(['tournament_id' => $tournament->id, 'user_id' => $user->id, 'name' => $user->name, 'rating' => 1500, 'members' => [$user->id]]);
    }

    $tournament->forceFill(['status' => TournamentStatus::Running])->save();
    app(TournamentBrackets::class)->generate($tournament, str_repeat('ef', 32));
    app(TournamentRunner::class)->sync($tournament);

    return [$tournament->refresh(), $users];
}

beforeEach(function () {
    Queue::fake();
    config(['esports.league.nsec' => (new TestSigner)->secret]);
    $this->fake = ScoreDemoOn::play();
});

test('an organizer creates a leaderboard of a score game: only the leaderboard format can run', function () {
    $organizer = User::factory()->create();
    TournamentOrganizer::query()->create(['pubkey' => $organizer->pubkey]);

    Livewire::actingAs($organizer)->test('pages::admin.tournament-create')
        ->call('pickGame', 'score-demo/time-trial')->assertOk()
        ->assertSet('format', TournamentFormat::Leaderboard)
        ->set('name', 'Score Week 1')
        ->call('create')
        ->assertHasNoErrors();

    $tournament = Tournament::query()->sole();

    expect([$tournament->game, $tournament->mode, $tournament->format])->toBe(['score-demo', 'time-trial', TournamentFormat::Leaderboard])
        ->and($tournament->plannedDuration())->toBe(7.0);
});

test('a player submits a value with a proof, an admin approves it, and it leads the public table', function () {
    [$tournament, [$a, $b]] = publishedScoreBoard();
    $this->travelTo($tournament->starts_at->addHours(2));
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    $this->get(route('tournaments.scores', $tournament))->assertOk()
        ->assertSee('data-test="score-leaderboard"', false)
        ->assertDontSee('data-test="score-submit"', false)
        ->assertDontSee('data-test="score-direct"', false);

    Livewire::actingAs($a)->test('pages::scores.tournament', ['tournament' => $tournament])
        ->call('$refresh')->assertOk()
        ->set('value', '0:59.250')
        ->set('achievedAt', LeagueTime::input(now()->subHour()))
        ->set('proofUrl', 'https://replays.example.org/run/42')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSee(__('waits for an admin'));

    $run = ScoreRun::query()->sole();

    Livewire::actingAs($admin)->test('pages::admin.scores')
        ->call('$refresh')->assertOk()
        ->assertSee('https://replays.example.org/run/42')
        ->call('approve', $run->id)
        ->assertSet('error', '')
        ->assertSee(__('Approved: it counts now.'));

    // A guest again (Livewire::actingAs() logs the admin in for the whole test).
    auth()->logout();

    $this->get(route('tournaments.scores', $tournament))->assertOk()
        ->assertSeeInOrder(['racer1', '0:59.250'])
        // The proof link is for the league team only.
        ->assertDontSee('replays.example.org');

    $this->get(route('tournaments.show', $tournament))->assertOk()->assertSee('data-test="tournament-leaderboard"', false)->assertSee('0:59.250');
});

test('a rejected submission says why, and nobody reviews their own', function () {
    [$tournament, [$a]] = publishedScoreBoard();
    $this->travelTo($tournament->starts_at->addHours(2));
    Admin::query()->create(['pubkey' => $a->pubkey]);
    $other = User::factory()->create();
    Admin::query()->create(['pubkey' => $other->pubkey]);
    $run = app(ManualSubmissions::class)->submit($tournament, $a, '1:00.000', now()->subHour(), 'https://example.org/p');

    Livewire::actingAs($a)->test('pages::admin.scores')->call('approve', $run->id)->assertNotSet('error', '');

    Livewire::actingAs($other)->test('pages::admin.scores')
        ->call('startReject', $run->id)->set('reason', 'The replay is another track.')->call('reject')
        ->assertSet('error', '');

    Livewire::actingAs($a)->test('pages::scores.tournament', ['tournament' => $tournament])
        ->assertSee('rejected: The replay is another track.');
});

test('a director corrects a value with a public reason and ends the leaderboard once the window closed', function () {
    [$tournament, [$a, $b, $c]] = publishedScoreBoard();
    $start = $tournament->starts_at->toImmutable();
    $this->fake->record($a->id, 'demo-1', 61_000, $start->addHour())->record($b->id, 'demo-1', 60_000, $start->addHour());
    $this->travelTo($start->addHours(3));
    app(ScoreLeaderboards::class)->snapshot($tournament);

    Livewire::actingAs($tournament->creator)->test('pages::scores.tournament', ['tournament' => $tournament])
        ->call('$refresh')->assertOk()
        ->call('pickCorrection', $b->id)
        ->set('correctValue', '')
        ->set('correctReason', 'Slow-motion cheat, seen in the replay.')
        ->call('correct')
        ->assertHasNoErrors()
        ->assertSee('Slow-motion cheat, seen in the replay.');

    // After the submission grace (gate F3).
    $this->travelTo($start->addDays(7)->addMinutes(61));

    Livewire::actingAs($tournament->creator)->test('pages::scores.tournament', ['tournament' => $tournament])
        ->call('finalize')->assertHasNoErrors();

    expect($tournament->refresh()->status)->toBe(TournamentStatus::Finished)
        ->and(app(ScoreRuns::class)->standings($tournament)[0]->participant->user_id)->toBe($a->id);

    $this->get(route('scores.show', 'score-demo'))->assertOk()
        ->assertSee('data-test="score-board-card"', false)
        ->assertSeeInOrder(['data-test="score-points-time-trial"', 'racer1', '25'], false);
});

test('the director desk and the control send a leaderboard to its scores page', function () {
    [$tournament] = publishedScoreBoard();

    $this->actingAs($tournament->creator)->get(route('tournaments.director', $tournament))->assertRedirect(route('tournaments.scores', $tournament));
});

test('a game account id is never on a public page nor in a Nostr event', function () {
    [$tournament, [$a]] = publishedScoreBoard();
    $this->fake->record($a->id, 'demo-1', 61_000, $tournament->starts_at->addHour());
    $this->travelTo($tournament->starts_at->addHours(2));
    app(ScoreLeaderboards::class)->snapshot($tournament);
    $this->travelTo($tournament->starts_at->addDays(8));
    app(ScoreLeaderboards::class)->finalize($tournament, $tournament->creator);
    app(TournamentPublisher::class)->republish($tournament->refresh());

    foreach ([route('tournaments.show', $tournament), route('tournaments.scores', $tournament), route('tournaments.tv', $tournament), route('scores.show', 'score-demo'),
        route('players.show', $a->npub), route('play'), route('home'), route('tournaments.index'), route('sitemap.section', ['section' => 'pages', 'file' => 1])] as $url) {
        expect((string) $this->get($url)->assertOk()->getContent())->not->toContain(SCORE_ACCOUNT);
    }

    // The owner's own settings show it: the one place it may appear, to its owner.
    $this->actingAs($a)->get(route('gaming.edit'))->assertOk()->assertSee(SCORE_ACCOUNT);

    $payloads = NostrEvent::query()->get()->map(fn (NostrEvent $event): string => json_encode($event->payload()))->implode("\n");

    expect(NostrEvent::query()->count())->toBeGreaterThan(0)
        ->and($payloads)->not->toContain(SCORE_ACCOUNT)
        ->and(ScoreRun::query()->sole()->toJson())->not->toContain(SCORE_ACCOUNT);
});

test('a pending finish of an unknown account is never on a leaderboard', function () {
    ['token' => $token] = ScoreServers::issue('demo box', 'score-demo');
    [$tournament] = runningScoreBoard(2);
    $this->travelTo($tournament->starts_at->addHour());

    $this->postJson(route('scores.ingest'), ['events' => [['id' => 'p1', 'mode' => 'time-trial', 'course' => 'demo-1', 'account' => 'acct-nobody', 'value' => 1, 'achieved_at' => now()->getTimestamp()]]],
        ['Authorization' => 'Bearer '.$token])->assertOk();

    expect(collect(app(ScoreRuns::class)->standings($tournament))->pluck('value')->filter()->all())->toBe([]);
    $this->get(route('tournaments.scores', $tournament))->assertOk()->assertDontSee('acct-nobody');
});
