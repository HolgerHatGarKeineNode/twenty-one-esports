<?php

use App\Enums\NotificationKind;
use App\Jobs\NotifyTournamentWatchers;
use App\Models\Tournament;
use App\Models\TournamentWatch;
use App\Models\User;
use App\Support\Notifications\Notifier;
use App\Support\Tournaments\TournamentPublisher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\TestSigner;

/*
|--------------------------------------------------------------------------
| "Notify me of new Rocket League tournaments" (plan "RL-Startseite", P2)
|--------------------------------------------------------------------------
|
| The prize band without an open tournament offers to hear of the next one.
| Publishing a Rocket League tournament tells every player who asked once
| (bell, push, DM by the player's settings), never for another game and
| never for a casual cup; the button and the settings switch turn it off.
|
*/

beforeEach(function () {
    // Every other queued job (the calendar, relay deliveries) stays faked; the watchers' job runs.
    Queue::fake()->except([NotifyTournamentWatchers::class]);
    config(['esports.league.nsec' => (new TestSigner)->secret]);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00:00', 'UTC'));
});

/** The new-tournament notices a player got. */
function newTournamentNotices(User $user): int
{
    return $user->notifications()->where('type', NotificationKind::NewTournament->value)->count();
}

/** A draft of a game, published by its organizer. */
function publishDraft(string $game): Tournament
{
    $factory = $game === 'rocket-league' ? Tournament::factory()->rocketLeague() : Tournament::factory();
    $draft = $factory->create(['created_by_id' => organizer()->id, 'starts_at' => now()->addWeek()]);

    return app(TournamentPublisher::class)->publish($draft, $draft->creator, CarbonImmutable::now()->addDays(5));
}

test('publishing a Rocket League tournament tells its watchers exactly once, and nobody who watches another game', function () {
    $watcher = User::factory()->create();
    $chessWatcher = User::factory()->create();
    $nobody = User::factory()->create();
    TournamentWatch::query()->create(['user_id' => $watcher->id, 'game' => 'rocket-league']);
    TournamentWatch::query()->create(['user_id' => $chessWatcher->id, 'game' => 'chess']);

    $tournament = publishDraft('rocket-league');

    expect(newTournamentNotices($watcher))->toBe(1)
        ->and(newTournamentNotices($chessWatcher))->toBe(0)
        ->and(newTournamentNotices($nobody))->toBe(0)
        ->and($watcher->notifications()->first()->data['url'])->toBe(route('tournaments.show', $tournament));

    // A second run (a retry, a concurrent worker) tells no one twice.
    (new NotifyTournamentWatchers($tournament->id))->handle(app(Notifier::class));
    expect(newTournamentNotices($watcher))->toBe(1);

    // Another game's tournament: the Rocket League watcher hears nothing, the chess watcher once.
    publishDraft('chess');
    expect(newTournamentNotices($watcher))->toBe(1)
        ->and(newTournamentNotices($chessWatcher))->toBe(1);
});

test('a casual cup opening tells no watcher', function () {
    $watcher = User::factory()->create();
    TournamentWatch::query()->create(['user_id' => $watcher->id, 'game' => 'rocket-league']);
    $cup = Tournament::factory()->rocketLeague()->create(['cup_series' => 'rocket-league-eu', 'cup_number' => 1, 'starts_at' => now()->addDays(2)]);

    app(TournamentPublisher::class)->openSignup($cup, CarbonImmutable::now()->addDay());
    (new NotifyTournamentWatchers($cup->id))->handle(app(Notifier::class));

    expect(newTournamentNotices($watcher))->toBe(0);
});

test('a watcher who switched the kind off in the settings hears nothing; the band button subscribes and unsubscribes', function () {
    $off = User::factory()->create();
    $off->forceFill(['chess_settings' => [...$off->chessSettings()->toArray(), 'triggers' => [NotificationKind::NewTournament->value => false]]])->save();
    TournamentWatch::query()->create(['user_id' => $off->id, 'game' => 'rocket-league']);

    $player = User::factory()->create();
    $page = Livewire::actingAs($player)->test('pages::games.series', ['slug' => 'rocket-league'])
        ->assertSeeHtml('data-test="prize-watch" data-watching="0"')
        ->call('toggleTournamentWatch')
        ->assertSeeHtml('data-test="prize-watch" data-watching="1"');
    expect(TournamentWatch::query()->where('user_id', $player->id)->where('game', 'rocket-league')->count())->toBe(1);

    publishDraft('rocket-league');
    expect(newTournamentNotices($off))->toBe(0)
        ->and(newTournamentNotices($player))->toBe(1)
        ->and(NotificationKind::NewTournament->group())->not->toBeNull();

    $page->call('toggleTournamentWatch');
    expect(TournamentWatch::query()->where('user_id', $player->id)->exists())->toBeFalse();

    publishDraft('rocket-league');
    expect(newTournamentNotices($player))->toBe(1);
});

test('a guest is asked to log in instead, and another game page offers no watch', function () {
    $this->get(route('games.rocket-league'))->assertOk()->assertSeeHtml('data-test="prize-watch-login"');

    Livewire::actingAs(User::factory()->create())->test('pages::games.series', ['slug' => 'ea-sports-fc-26'])
        ->assertDontSeeHtml('data-test="prize-watch"')
        ->call('toggleTournamentWatch');

    expect(TournamentWatch::query()->count())->toBe(0);
});
