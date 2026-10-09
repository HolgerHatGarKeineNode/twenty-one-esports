<?php

use App\Enums\HyperMatchStatus;
use App\Events\HyperLobbyUpdated;
use App\Events\HyperRematchUpdated;
use App\Events\HyperTableStarted;
use App\Models\Admin;
use App\Models\HyperMatch;
use App\Models\HyperSeat;
use App\Models\HyperTable;
use App\Models\User;
use App\Support\Hyper\HyperGame;
use App\Support\Hyper\HyperLobby;
use App\Support\Hyper\HyperMatches;
use App\Support\Hyper\HyperRuleViolation;
use App\Support\Nostr\NostrKeys;
use App\Support\Settings\LeagueSettings;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Tests\Support\HyperOn;

/*
| The Hyperbitcoinization lobby (plan "Hyperbitcoinization", P3): a table waits for its seats, every faction
| once per table, the creator fills free seats with bots (a live table does so by itself after its wait), a
| full table starts its match and tells its players; a table's own link invites a friend; and a rematch takes
| the old lineup with a new seed once every player said yes.
*/

beforeEach(function () {
    HyperOn::play();
    Event::fake([HyperLobbyUpdated::class, HyperTableStarted::class, HyperRematchUpdated::class]);
});

/**
 * @return list<int>
 */
function hyperStartedFor(): array
{
    return Event::dispatched(HyperTableStarted::class)->flatMap(fn (array $call): array => $call[0]->userIds)->all();
}

test('two players sit at a table with factions nobody else has; the bots fill it and the match starts for both', function () {
    [$anna, $bert, $carl] = User::factory()->count(3)->create();
    $lobby = app(HyperLobby::class);

    $table = $lobby->open($anna, 4, HyperMatch::LIVE, 20, 'fed');
    $table = $lobby->join($table, $bert);
    $lobby->pick($table, $bert, 'goldbug');

    expect(fn () => $lobby->pick($table, $bert, 'fed'))->toThrow(HyperRuleViolation::class, 'Somebody at this table plays this faction.')
        ->and(fn () => $lobby->join($table, $bert))->toThrow(HyperRuleViolation::class, 'You sit at this table already.')
        ->and(fn () => $lobby->fillBots($table, $bert))->toThrow(HyperRuleViolation::class, 'Only who set the table up fills it with bots.')
        // One lobby table at a time.
        ->and(fn () => $lobby->open($bert, 2, HyperMatch::LIVE, 0))->toThrow(HyperRuleViolation::class, 'You wait at another table already.');
    Event::assertDispatched(HyperLobbyUpdated::class, fn (HyperLobbyUpdated $event): bool => $event->broadcastOn()[0]->name === 'hyper.lobby');

    $table = $lobby->fillBots($table, $anna);
    $match = $table->match()->with('seats')->firstOrFail();

    expect($table->status)->toBe(HyperTable::STARTED)
        ->and($match->status)->toBe(HyperMatchStatus::Active)
        ->and($match->mode)->toBe(HyperMatch::LIVE)
        ->and($match->round_limit)->toBe(20)
        ->and($match->seats->pluck('user_id')->all())->toBe([$anna->id, $bert->id, null, null])
        ->and($match->seats->pluck('bot')->all())->toBe([false, false, true, true])
        ->and($match->seats->take(2)->pluck('faction')->all())->toBe(['fed', 'goldbug'])
        ->and($match->seats->pluck('faction')->unique())->toHaveCount(4)
        ->and(hyperStartedFor())->toBe([$anna->id, $bert->id])
        ->and(fn () => $lobby->join($table, $carl))->toThrow(HyperRuleViolation::class, 'This table is not open any more.');
    Event::assertDispatched(HyperTableStarted::class, fn (HyperTableStarted $event): bool => $event->url === route('hyper.match', $match));
});

test('a table that fills up with players starts at once; a correspondence table starts a correspondence match', function () {
    [$anna, $bert] = User::factory()->count(2)->create();
    $lobby = app(HyperLobby::class);

    $table = $lobby->join($lobby->open($anna, 2, HyperMatch::CORRESPONDENCE, 0), $bert);

    expect($table->status)->toBe(HyperTable::STARTED)
        ->and($table->fill_at)->toBeNull()
        ->and($table->match->mode)->toBe(HyperMatch::CORRESPONDENCE)
        ->and($table->match->seats->pluck('bot')->unique()->all())->toBe([false]);
});

test('a live table gets bots for its free seats once its wait is over; a correspondence table waits for its creator', function () {
    config(['esports.hyper.lobby_fill_seconds' => 120]);
    [$anna, $bert] = User::factory()->count(2)->create();
    $lobby = app(HyperLobby::class);
    $live = $lobby->open($anna, 3, HyperMatch::LIVE, 0);
    $daily = $lobby->open($bert, 3, HyperMatch::CORRESPONDENCE, 0);

    $this->travel(119)->seconds();
    $this->artisan('hyper:check-clocks')->assertSuccessful();
    expect($live->refresh()->status)->toBe(HyperTable::OPEN);

    $this->travel(2)->seconds();
    $this->artisan('hyper:check-clocks')->assertSuccessful();

    expect($live->refresh()->status)->toBe(HyperTable::STARTED)
        ->and($live->match->seats->pluck('bot')->all())->toBe([false, true, true])
        ->and($daily->refresh()->status)->toBe(HyperTable::OPEN)
        ->and(hyperStartedFor())->toBe([$anna->id]);
});

test('a player who gets up frees the seat; the creator getting up closes the table', function () {
    [$anna, $bert] = User::factory()->count(2)->create();
    $lobby = app(HyperLobby::class);
    $table = $lobby->join($lobby->open($anna, 3, HyperMatch::LIVE, 0), $bert);

    $table = $lobby->leave($table, $bert);
    expect($table->takenSeats->pluck('user_id')->all())->toBe([$anna->id])
        ->and($lobby->tableOf($bert))->toBeNull();

    $lobby->leave($table, $anna);
    expect($table->refresh()->status)->toBe(HyperTable::CANCELLED)
        ->and($lobby->openTables())->toHaveCount(0);
});

test('the lobby page: a new table, a faction, the open tables to join, the table link first, and the match into a new tab', function () {
    $this->withoutVite();
    [$anna, $bert] = User::factory()->count(2)->create();

    $this->get(route('hyper.index'))->assertOk()->assertSee('data-test="hyper-login"', false)->assertSee('data-test="hyper-lobby-tables"', false);

    Livewire::actingAs($anna)->test('hyper-lobby')
        ->call('$set', 'seats', 2)
        ->call('$set', 'mode', 'correspondence')
        ->call('openTable')
        ->assertSee('data-test="hyper-lobby-mine"', false)
        // The invite button's script is compiled: a Blade directive left in a component attribute reaches Alpine as text.
        ->assertDontSee('@js(', false)
        ->assertSee('writeText(\''.str_replace('/', '\\/', route('hyper.table', HyperTable::query()->firstOrFail())).'\')', false)
        ->call('pick', 'nocoiner')
        ->assertSee('data-faction="nocoiner"', false);
    $table = HyperTable::query()->firstOrFail();
    expect($table->seats)->toBe(2)->and($table->mode)->toBe('correspondence');

    // The table's own link shows it first, with its join button.
    $this->actingAs($bert)->get(route('hyper.table', $table))->assertOk()
        ->assertSee('data-test="hyper-lobby-table" data-table="'.$table->ulid.'"', false)
        ->assertSee('data-test="hyper-lobby-join"', false);

    Livewire::actingAs($bert)->test('hyper-lobby', ['focus' => $table->ulid])
        ->call('join', $table->ulid)
        ->assertDispatched('hyper-open', url: route('hyper.match', $table->refresh()->match))
        ->assertSee('data-test="hyper-lobby-open-match"', false);

    // Taken: Anna's faction is hers, the other chips are free for Bert's next table.
    expect($table->match->seats->pluck('faction')->first())->toBe('nocoiner');
    Livewire::actingAs($anna)->test('hyper-lobby')->call('join', $table->ulid)->assertSet('error', __('This table is closed.'));
});

test('a rematch: the same lineup and factions, a new seed, bots and left seats as bots; it starts once every player said yes', function () {
    [$anna, $bert, $carl] = User::factory()->count(3)->create();
    $old = HyperOn::versus($anna, $bert, bots: 1);
    $lobby = app(HyperLobby::class);

    $this->actingAs($anna)->postJson(route('hyper.rematch', $old))->assertForbidden()->assertJsonPath('reason', 'no_rematch');

    $old->forceFill(['status' => HyperMatchStatus::Finished, 'current_seat' => null, 'deadline_ms' => null])->save();
    $this->actingAs($carl)->postJson(route('hyper.rematch', $old))->assertForbidden();

    $first = $this->actingAs($anna)->postJson(route('hyper.rematch', $old))->assertOk();
    expect($first->json('ready'))->toBe([0])
        ->and($first->json('waiting'))->toBe([1])
        ->and($first->json('url'))->toBeNull();
    Event::assertDispatched(HyperRematchUpdated::class, fn (HyperRematchUpdated $event): bool => $event->match === $old->ulid && $event->payload['waiting'] === [1]);

    // A second yes from Anna changes nothing; Bert's starts it.
    $this->actingAs($anna)->postJson(route('hyper.rematch', $old))->assertOk()->assertJsonPath('url', null);
    $second = $this->actingAs($bert)->postJson(route('hyper.rematch', $old))->assertOk();
    $new = HyperMatch::query()->whereKeyNot($old->id)->with('seats')->latest('id')->firstOrFail();

    expect($second->json('url'))->toBe(route('hyper.match', $new))
        ->and($new->seats->pluck('user_id')->all())->toBe($old->seats->pluck('user_id')->all())
        ->and($new->seats->pluck('faction')->all())->toBe($old->seats->pluck('faction')->all())
        ->and($new->seats->pluck('bot')->all())->toBe([false, false, true])
        ->and($new->mode)->toBe($old->mode)
        ->and($new->seed)->not->toBe($old->seed)
        ->and(HyperTable::query()->where('rematch_of', $old->id)->count())->toBe(1)
        ->and($lobby->openTables())->toHaveCount(0);
    Event::assertDispatched(HyperRematchUpdated::class, fn (HyperRematchUpdated $event): bool => $event->payload['url'] === route('hyper.match', $new));

    // Asked again later: the same new match.
    $this->actingAs($bert)->postJson(route('hyper.rematch', $old))->assertOk()->assertJsonPath('url', route('hyper.match', $new));
});

test('a rematch with bots only and a player who left starts right away for the one who asks', function () {
    [$anna, $bert] = User::factory()->count(2)->create();
    $old = HyperOn::versus($anna, $bert, bots: 1);
    app(HyperMatches::class)->leave($old, $bert);
    $old->refresh()->forceFill(['status' => HyperMatchStatus::Finished, 'current_seat' => null, 'deadline_ms' => null])->save();

    $answer = $this->actingAs($anna)->postJson(route('hyper.rematch', $old))->assertOk();
    $new = HyperMatch::query()->whereKeyNot($old->id)->with('seats')->latest('id')->firstOrFail();

    expect($answer->json('url'))->toBe(route('hyper.match', $new))
        ->and($new->seats->pluck('user_id')->all())->toBe([$anna->id, null, null])
        ->and($new->seats->pluck('bot')->all())->toBe([false, true, true])
        ->and(array_column(HyperGame::fromArray($new->state)->toArray()['seats'], 'faction'))->toBe(['bitcoiner', 'fed', $old->seats[2]->faction]);
});

test('a rematch seats a player a bot took over for missed turns as a bot, so nobody waits for them', function () {
    [$anna, $bert] = User::factory()->count(2)->create();
    $old = HyperOn::versus($anna, $bert);
    $old->seats()->where('seat', 1)->update(['bot' => true, 'takeover' => HyperSeat::TAKEOVER_TIMEOUTS]);
    $old->refresh()->forceFill(['status' => HyperMatchStatus::Finished, 'current_seat' => null, 'deadline_ms' => null])->save();

    $answer = $this->actingAs($anna)->postJson(route('hyper.rematch', $old))->assertOk();

    expect($answer->json('url'))->not->toBeNull()
        ->and(HyperMatch::query()->whereKeyNot($old->id)->latest('id')->firstOrFail()->seats->pluck('bot')->all())->toBe([false, true]);
});

test('a friendly match is never rated; any other bot-free table in a live season is, and a rematch keeps the old match\'s rating', function () {
    openSeason(ladders: false);
    [$anna, $bert, $carl, $dora] = User::factory()->count(4)->create();
    $lobby = app(HyperLobby::class);

    $friendly = $lobby->open($anna, 2, HyperMatch::LIVE, 0, friendly: true);
    $friendlyMatch = $lobby->join($friendly, $bert)->match()->firstOrFail();
    $rated = $lobby->open($carl, 2, HyperMatch::LIVE, 0);
    $ratedMatch = $lobby->join($rated, $dora)->match()->firstOrFail();

    expect($friendly->refresh()->friendly)->toBeTrue()
        ->and($friendlyMatch->rated)->toBeFalse()
        ->and($friendlyMatch->season)->toBeNull()
        ->and($rated->refresh()->friendly)->toBeFalse()
        ->and($ratedMatch->rated)->toBeTrue();

    // A rematch inherits: the friendly one stays friendly, the rated one stays rated.
    foreach ([$friendlyMatch, $ratedMatch] as $old) {
        $old->forceFill(['status' => HyperMatchStatus::Finished, 'current_seat' => null, 'deadline_ms' => null])->save();
    }

    $lobby->rematch($friendlyMatch, $anna);
    $friendlyAgain = $lobby->rematch($friendlyMatch, $bert)['match'];
    $lobby->rematch($ratedMatch, $carl);
    $ratedAgain = $lobby->rematch($ratedMatch, $dora)['match'];

    expect($friendlyAgain->rated)->toBeFalse()
        ->and(HyperTable::query()->where('rematch_of', $friendlyMatch->id)->value('friendly'))->toBeTrue()
        ->and($ratedAgain->rated)->toBeTrue()
        ->and(HyperTable::query()->where('rematch_of', $ratedMatch->id)->value('friendly'))->toBeFalse();
});

test('the lobby offers "Friendly match (unrated)" and every table shows Rated or Unrated; the match page header says it too', function () {
    $this->withoutVite();
    openSeason(ladders: false);
    [$anna, $bert, $carl] = User::factory()->count(3)->create();

    Livewire::actingAs($anna)->test('hyper-lobby')
        ->assertSee('data-test="hyper-lobby-friendly"', false)
        ->assertSee(__('Friendly match (unrated)'))
        ->assertSee(__('Rated in the season when no bot plays.'))
        ->toggle('friendly')
        ->assertSeeHtml('aria-pressed="true" class=')
        ->assertSee(__('A friendly match is never rated.'))
        ->call('$set', 'seats', 2)
        ->call('openTable')
        ->assertSee('data-test="hyper-lobby-rated" data-rated="0"', false);

    $table = HyperTable::query()->sole();
    expect($table->friendly)->toBeTrue();

    // Bert sees Anna's friendly table as unrated, Anna sees Carl's new table as rated.
    Livewire::actingAs($bert)->test('hyper-lobby')->assertSee('data-test="hyper-lobby-rated" data-rated="0"', false);
    app(HyperLobby::class)->open($carl, 3, HyperMatch::LIVE, 0);
    Livewire::actingAs($anna)->test('hyper-lobby')->assertSee('data-test="hyper-lobby-rated" data-rated="1"', false);

    $match = app(HyperLobby::class)->join($table, $bert)->match()->firstOrFail();
    $rated = HyperOn::versus(User::factory()->create(), User::factory()->create());

    $this->get(route('hyper.match', $match))->assertOk()->assertSee('data-test="hyper-rated" data-rated="0"', false)->assertSee(__('Unrated'));
    $this->get(route('hyper.match', $rated))->assertOk()->assertSee('data-test="hyper-rated" data-rated="1"', false)->assertSee(__('Rated'));
});

test('a live table waits 5 minutes for players by default; an admin sets the wait on /admin/settings for the tables that open next', function () {
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    config(['esports.board' => [NostrKeys::hexToNpub($admin->pubkey)]]);
    [$anna, $bert] = User::factory()->count(2)->create();
    $this->freezeTime();
    $lobby = app(HyperLobby::class);

    $five = $lobby->open($anna, 4, HyperMatch::LIVE, 0);

    expect(LeagueSettings::definitions())->toHaveKey('esports.hyper.lobby_fill_seconds')
        ->and($five->fill_at->getTimestamp())->toBe(now()->addSeconds(300)->getTimestamp());

    LeagueSettings::save($admin, ['esports.hyper.lobby_fill_seconds' => 600]);
    LeagueSettings::forget();
    $ten = $lobby->open($bert, 4, HyperMatch::LIVE, 0);

    expect($ten->fill_at->getTimestamp())->toBe(now()->addSeconds(600)->getTimestamp())
        ->and($five->refresh()->fill_at->getTimestamp())->toBe(now()->addSeconds(300)->getTimestamp());
});
