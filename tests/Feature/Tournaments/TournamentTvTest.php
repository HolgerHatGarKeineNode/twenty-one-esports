<?php

use App\Enums\TournamentFormat;
use App\Enums\TournamentStatus;
use App\Events\TournamentChanged;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Support\Tournaments\TournamentPrizePool;
use App\Support\Tournaments\TournamentRunner;
use Illuminate\Broadcasting\Channel;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Tests\Support\TestSigner;

/*
 * The tournament TV (P19): public for a published tournament, a 404 for a
 * draft; a result, a closed round and a finished tournament are broadcast
 * on the tournament's public channel after the commit; the pot scene shows
 * the league's pool and nothing without one; check() renders only when the
 * tournament moved.
 */

beforeEach(function () {
    config(['esports.league.nsec' => (new TestSigner)->secret]);
});

function tvRunning(int $n = 4): Tournament
{
    $tournament = runningChess(TournamentFormat::SingleElimination, $n);
    $tournament->forceFill(['published_at' => now()->subDay(), 'name' => 'Blitz Night Munich'])->save();

    return $tournament->refresh();
}

function fakePool(?array $pool): void
{
    app()->instance(TournamentPrizePool::class, new class($pool) implements TournamentPrizePool
    {
        public function __construct(private ?array $pool) {}

        public function for(Tournament $tournament): ?array
        {
            return $this->pool;
        }
    });
}

test('the TV is public for a published tournament and a 404 for a draft, even for its manager', function () {
    $running = tvRunning();

    $this->get(route('tournaments.tv', $running))->assertOk()
        ->assertSee('data-test="tv"', false)
        ->assertSee('Blitz Night Munich')
        ->assertSee('data-test="tv-bracket"', false)
        ->assertSee('data-test="tv-qr"', false)
        // No site chrome: no header navigation, no footer.
        ->assertDontSee('Skip to content');

    $open = openTournament(['name' => 'Halving Cup']);
    $this->get(route('tournaments.tv', $open))->assertOk()->assertSee('data-scene-id="lobby"', false);

    $draft = Tournament::factory()->create(['name' => 'Secret Draft']);
    expect($draft->status)->toBe(TournamentStatus::Draft);

    $this->get(route('tournaments.tv', $draft))->assertNotFound();
    $this->actingAs($draft->creator)->get(route('tournaments.tv', $draft))->assertNotFound();
});

test('a director result and a closed round are broadcast on the public tournament channel', function () {
    $tournament = tvRunning();
    $runner = app(TournamentRunner::class);
    $round = TournamentRunner::currentRound($tournament);
    Event::fake([TournamentChanged::class]);

    $matches = TournamentMatch::query()->where('tournament_round_id', $round->id)->where('status', 'ready')->orderBy('id')->get();
    $runner->enterResult($matches[0], $tournament->creator, ['result' => '1-0']);

    Event::assertDispatchedTimes(TournamentChanged::class, 1);
    Event::assertDispatched(TournamentChanged::class, fn (TournamentChanged $event): bool => $event->tournamentId === $tournament->id && $event->reason === 'result'
        && $event->broadcastOn()[0] instanceof Channel && $event->broadcastOn()[0]->name === 'tournament.'.$tournament->id
        && $event->broadcastAs() === 'tournament.changed');

    // The same result again changes nothing and says nothing.
    $runner->enterResult($matches[0]->refresh(), $tournament->creator, ['result' => '1-0']);
    Event::assertDispatchedTimes(TournamentChanged::class, 1);

    $runner->enterResult($matches[1], $tournament->creator, ['result' => '0-1']);
    $runner->closeRound($round, $tournament->creator);

    Event::assertDispatched(TournamentChanged::class, fn (TournamentChanged $event): bool => $event->reason === 'round');
});

test('the pot scene shows the league pool and who can still win it, and is left out without a pool', function () {
    $tournament = tvRunning();

    $this->get(route('tournaments.tv', $tournament))->assertOk()
        ->assertDontSee('data-scene-id="pot"', false)
        ->assertDontSee('In the pot');

    fakePool(['sats' => 210000, 'split' => [['place' => 1, 'percent' => 60, 'sats' => 126000], ['place' => 2, 'percent' => 40, 'sats' => 84000]], 'sponsors' => []]);

    $this->get(route('tournaments.tv', $tournament))->assertOk()
        ->assertSee('data-scene-id="pot"', false)
        ->assertSee("210\u{00A0}000")
        ->assertSee("126\u{00A0}000 sats")
        ->assertSee('4 entries can still win it');
});

test('check renders only when the tournament moved', function () {
    $tournament = tvRunning();
    $component = Livewire::test('pages::tournaments.tv', ['tournament' => $tournament]);
    $before = $component->get('version');

    $component->call('check')->assertOk();
    expect($component->get('version'))->toBe($before)
        ->and($component->effects['html'] ?? null)->toBeNull();

    $match = TournamentMatch::query()->where('tournament_id', $tournament->id)->where('status', 'ready')->orderBy('id')->first();
    app(TournamentRunner::class)->enterResult($match, $tournament->creator, ['result' => '1-0']);

    $component->call('check')->assertOk();
    expect($component->get('version'))->not->toBe($before)
        ->and($component->effects['html'] ?? null)->toContain('data-status="done"');
});

test('a finished tournament shows its champion first', function () {
    $tournament = tvRunning();
    playOutAsDirector($tournament);

    $this->get(route('tournaments.tv', $tournament->refresh()))->assertOk()
        ->assertSee('data-scene="champion"', false)
        ->assertSee('data-test="tv-champion"', false)
        ->assertSee('Champion of Blitz Night Munich')
        ->assertSee('Player 1');
});
