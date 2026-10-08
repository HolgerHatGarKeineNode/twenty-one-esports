<?php

use App\Enums\HyperEndReason;
use App\Enums\HyperMatchStatus;
use App\Events\HyperEmoteSent;
use App\Events\HyperHandUpdated;
use App\Events\HyperMatchUpdated;
use App\Games\GameRegistry;
use App\Games\Hyperbitcoinization;
use App\Models\HyperAction;
use App\Models\HyperMatch;
use App\Models\User;
use App\Support\GameChat\GameChannels;
use App\Support\Hyper\HyperGame;
use App\Support\Hyper\HyperMatches;
use App\Support\Nostr\SignedEvent;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Support\HyperOn;
use Tests\Support\TestSigner;

/*
| Live Hyperbitcoinization matches on the server (plan "Hyperbitcoinization", P2a): the switch, the quick
| start against bots, actions through the rules core with their refusals, bots playing server-side, the turn
| timer and the takeover after three timeouts, the end with places and loot, what each viewer may see,
| the broadcasts and channels, the emote throttle and the table chat's channel id.
*/

/**
 * @return list<string>
 */
function hyperChannelNames(object $event): array
{
    return array_map(fn (object $channel): string => $channel->name, $event->broadcastOn());
}

/**
 * Plays as the user's seat through the HTTP endpoint.
 *
 * @param  array<string, mixed>  $action
 */
function hyperAct(User $user, HyperMatch $match, array $action, ?int $ply = null): TestResponse
{
    return test()->actingAs($user)->postJson(route('hyper.act', $match), ['action' => $action, 'ply' => $ply ?? $match->refresh()->ply + 1]);
}

test('with the switch off no route and no registry entry exist; on, both do', function () {
    $this->withoutVite();
    $match = HyperMatch::factory()->create();

    expect(Route::has('hyper.match'))->toBeFalse()
        ->and(app(GameRegistry::class)->find(Hyperbitcoinization::SLUG))->toBeNull();
    $this->get('/hyperbitcoinization/m/'.$match->ulid)->assertNotFound();

    HyperOn::play();

    expect(app(GameRegistry::class)->find(Hyperbitcoinization::SLUG))->toBeInstanceOf(Hyperbitcoinization::class);
    $this->get(route('hyper.match', $match))->assertOk()->assertSee('id="hyper-snapshot"', false);

    // The browser tests' fixture: a match one action from its end.
    [$anna, $bert] = User::factory()->count(2)->create();
    $fixture = $this->getJson(route('testing.hyper', ['user' => $anna, 'opponent' => $bert, 'endgame' => 1]))->assertOk();
    $this->getJson($fixture->json('path').'/snapshot')->assertOk()->assertJsonPath('phase', 'attack')->assertJsonPath('state.territories.mexiko.owner', 1);
});

test('a player starts a match against bots, acts through the rules core, and the bots answer on the server', function () {
    HyperOn::play();
    Event::fake([HyperMatchUpdated::class, HyperHandUpdated::class]);
    $anna = User::factory()->create();

    $created = $this->actingAs($anna)->postJson(route('hyper.quick'), ['bots' => 2, 'faction' => 'goldbug'])->assertCreated();
    $match = HyperMatch::query()->where('ulid', $created->json('id'))->firstOrFail();

    expect($created->json('url'))->toBe(route('hyper.match', $match))
        ->and($match->seats->pluck('bot')->all())->toBe([false, true, true])
        ->and($match->seats[0]->faction)->toBe('goldbug')
        ->and($match->seats->pluck('faction')->unique())->toHaveCount(3);

    $snapshot = $this->getJson(route('hyper.snapshot', $match))->assertOk()->json();
    expect($snapshot['me'])->toBe(0)
        ->and($snapshot['seat'])->toBe(0)
        ->and($snapshot['legal']['phase'])->toBe('buy')
        ->and($snapshot['map']['territories'])->toHaveCount(53);

    $mine = $snapshot['legal']['deploy'][0];
    $before = $snapshot['state']['territories'][$mine]['pleb'];
    hyperAct($anna, $match, ['type' => 'deploy', 'territory' => $mine, 'unit' => 'pleb', 'qty' => 1])
        ->assertOk()->assertJsonPath('ply', 1)->assertJsonPath('events.0.type', 'placed');
    $answer = hyperAct($anna, $match, ['type' => 'end_turn'])->assertOk();

    // Both bots played their turns right after (the queue runs synchronously here), and it is Anna's turn again.
    $match->refresh();
    expect($answer->json('snapshot.seat'))->toBe(0)
        ->and($match->current_seat)->toBe(0)
        ->and($match->actions()->where('source', HyperAction::BOT)->distinct()->pluck('seat')->sort()->values()->all())->toBe([1, 2])
        ->and($match->actions()->where('source', HyperAction::PLAYER)->count())->toBe(2)
        ->and(HyperGame::fromArray($match->state)->round())->toBe(2);
    // A player's turn and each bot turn reach the table, on the players' and the spectators' channel.
    Event::assertDispatched(HyperMatchUpdated::class, fn (HyperMatchUpdated $event): bool => hyperChannelNames($event) === ['private-hyper.'.$match->ulid, 'hyper.'.$match->ulid.'.watch']);
    Event::assertDispatchedTimes(HyperMatchUpdated::class, 4);
    // The action log replays the match: the seed and every action, in ply order, give the stored state.
    $replay = HyperGame::start(array_map(fn (array $seat): array => ['faction' => $seat['faction'], 'bot' => $seat['bot']], HyperGame::fromArray($match->state)->toArray()['seats']), 0, $match->seed)->game;

    foreach ($match->actions()->where('ply', '>', 0)->get() as $action) {
        $replay = $replay->apply($action->seat, $action->action)->game;
    }

    expect($replay->toArray())->toBe(HyperGame::fromArray($match->state)->toArray());
});

test('a stranger, a stale ply, an action out of turn and an illegal action are refused', function () {
    HyperOn::play();
    [$anna, $bert, $carl] = User::factory()->count(3)->create();
    $match = HyperOn::versus($anna, $bert);

    $this->postJson(route('hyper.act', $match), ['action' => ['type' => 'end_phase'], 'ply' => 1])->assertUnauthorized();
    hyperAct($carl, $match, ['type' => 'end_phase'])->assertForbidden()->assertJsonPath('reason', 'not_seated');
    hyperAct($anna, $match, ['type' => 'end_phase'], ply: 5)->assertConflict()->assertJsonPath('reason', 'out_of_sync');
    hyperAct($bert, $match, ['type' => 'end_phase'])->assertUnprocessable()->assertJsonPath('reason', 'not_your_turn');
    hyperAct($anna, $match, ['type' => 'attack', 'from' => 'ny', 'to' => 'texas'])->assertUnprocessable()->assertJsonPath('reason', 'wrong_phase');

    expect($match->refresh()->ply)->toBe(0)
        ->and($match->actions()->count())->toBe(1);
});

test('when the turn runs out the server ends it, placed troops stay; three timeouts in a row hand the seat to a bot, which plays to the end', function () {
    HyperOn::play();
    config(['esports.hyper.bot_round_cap' => 4]);
    $anna = User::factory()->create();
    $match = app(HyperMatches::class)->create([['user' => $anna, 'faction' => 'fed'], ['bot' => true, 'faction' => 'ezb']], seed: 11);
    $mine = app(HyperMatches::class)->snapshot($match, $anna)['legal']['deploy'][0];
    hyperAct($anna, $match, ['type' => 'deploy', 'territory' => $mine, 'unit' => 'pleb', 'qty' => 1])->assertOk();

    $this->travel(89)->seconds();
    $this->artisan('hyper:check-clocks')->assertSuccessful();
    expect($match->refresh()->current_seat)->toBe(0);

    $this->travel(2)->seconds();
    $this->artisan('hyper:check-clocks')->assertSuccessful();
    $match->refresh();
    $timer = $match->actions()->where('source', HyperAction::TIMER)->firstOrFail();

    // Ended, not undone: the pleb placed before stays on the map.
    expect($timer->action)->toBe(['type' => 'end_turn'])
        ->and(collect($timer->events)->pluck('type')->all())->not->toContain('undone')
        ->and(collect($timer->events)->pluck('type')->all())->toContain('turn_ended')
        ->and($match->seats[0]->timeouts)->toBe(1)
        ->and($match->current_seat)->toBe(0);
    // The bot played its turn between, and Anna's new turn has its own 90 seconds.
    expect($match->deadline_ms)->toBe((int) now()->getTimestampMs() + 90_000);

    foreach ([2, 3] as $timeouts) {
        $this->travel(91)->seconds();
        $this->artisan('hyper:check-clocks')->assertSuccessful();
        expect($match->refresh()->seats[0]->timeouts)->toBe($timeouts);
    }

    // Taken over, only bots are left: they play on and the bot-only cap ends the match by the limit's ranking.
    expect($match->status)->toBe(HyperMatchStatus::Finished)
        ->and($match->seats[0]->bot)->toBeTrue()
        ->and($match->seats[0]->takeover)->toBe('timeouts')
        ->and($match->end_reason)->toBe(HyperEndReason::Limit)
        ->and($match->seats->pluck('place')->sort()->values()->all())->toBe([1, 2])
        ->and($match->seats->firstWhere('place', 1)->seat)->toBe($match->winner_seat)
        ->and($match->seats->pluck('loot')->all())->toBe(HyperGame::fromArray($match->state)->loot())
        ->and($match->actions()->where('action->type', 'round_limit')->count())->toBe(1)
        ->and($match->deadline_ms)->toBeNull();
});

test('the last conquest ends the match: places and loot are written, and further actions are refused', function () {
    HyperOn::play();
    [$anna, $bert] = User::factory()->count(2)->create();
    $match = HyperOn::endgame($anna, $bert);

    hyperAct($anna, $match, ['type' => 'play_card', 'card' => 'attack51', 'target' => 'mexiko'])->assertOk()
        ->assertJsonPath('snapshot.status', 'finished')->assertJsonPath('snapshot.winner', 0);

    $match->refresh();
    expect($match->end_reason)->toBe(HyperEndReason::Conquest)
        ->and($match->seats->pluck('place')->all())->toBe([1, 2])
        ->and($match->seats->pluck('loot')->all())->toBe(HyperGame::fromArray($match->state)->loot())
        ->and($match->ended_at)->not->toBeNull();
    hyperAct($anna, $match, ['type' => 'end_turn'])->assertConflict()->assertJsonPath('reason', 'game_over');
});

test('nobody but the owner sees a hand or a drawn card: not in the snapshot, the event catch-up or the broadcast', function () {
    HyperOn::play();
    Event::fake([HyperMatchUpdated::class, HyperHandUpdated::class]);
    [$anna, $bert, $carl] = User::factory()->count(3)->create();
    $match = HyperOn::endgame($anna, $bert);
    // Bert keeps a second territory, so the card only conquers and Anna draws a card at the end of her turn.
    $state = $match->state;
    $state['territories']['kolumbien']['owner'] = 1;
    $match->forceFill(['state' => $state])->save();

    hyperAct($anna, $match, ['type' => 'play_card', 'card' => 'attack51', 'target' => 'mexiko'])->assertOk();
    hyperAct($anna, $match, ['type' => 'end_turn'])->assertOk();
    $drawn = HyperGame::fromArray($match->refresh()->state)->seatAt(0)['hand'];
    $seen = fn (?User $viewer): array => app(HyperMatches::class)->snapshot($match, $viewer)['state']['seats'][0];
    $catchUp = fn (?User $viewer): array => collect(app(HyperMatches::class)->eventsSince($match, $viewer, 0)['actions'])->pluck('events')->flatten(1)->where('type', 'card_drawn')->values()->all();

    expect($drawn)->toHaveCount(1)
        ->and($seen($anna))->toMatchArray(['hand' => $drawn, 'hand_count' => 1])
        ->and($seen($bert))->toMatchArray(['hand' => null, 'hand_count' => 1])
        ->and($seen(null))->toMatchArray(['hand' => null, 'hand_count' => 1])
        ->and($seen($carl)['hand'])->toBeNull()
        ->and($catchUp($anna)[0]['card'])->toBe($drawn[0])
        ->and($catchUp($bert)[0]['card'])->toBeNull()
        ->and($catchUp(null)[0]['card'])->toBeNull()
        // Nobody sees the seed, the dice generator or the deck while the match runs.
        ->and(app(HyperMatches::class)->snapshot($match, $anna)['state'])->not->toHaveKeys(['rng', 'deck'])
        ->and(app(HyperMatches::class)->snapshot($match, $anna)['state']['seed'])->toBeNull()
        ->and(collect(app(HyperMatches::class)->eventsSince($match, $anna, -1)['actions'][0]['events'])->firstWhere('type', 'game_started')['seed'])->toBeNull();

    $json = fn (object $event): string => (string) json_encode($event->broadcastWith());
    Event::assertDispatched(HyperMatchUpdated::class, fn (HyperMatchUpdated $event): bool => collect($event->payload['events'])->contains(fn (array $e): bool => $e['type'] === 'card_drawn' && $e['card'] === null));
    Event::assertNotDispatched(HyperMatchUpdated::class, fn (HyperMatchUpdated $event): bool => str_contains($json($event), '"card":"'.$drawn[0].'"') || str_contains($json($event), '"hand":["'));
    // Only Anna's own channel carries the card, and only Anna may join it.
    Event::assertDispatched(HyperHandUpdated::class, fn (HyperHandUpdated $event): bool => $event->seat === 0 && $event->hand === $drawn
        && hyperChannelNames($event) === ['private-hyper.'.$match->ulid.'.seat.0'] && $event->events[0]['card'] === $drawn[0]);
    Event::assertNotDispatched(HyperHandUpdated::class, fn (HyperHandUpdated $event): bool => $event->seat !== 0);
});

test('only a seated player joins the players\' channel and only its own seat channel; spectators watch the public one', function () {
    config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb' => [
        'driver' => 'reverb', 'key' => 'key', 'secret' => 'secret', 'app_id' => 'app', 'options' => ['host' => '127.0.0.1', 'port' => 1, 'scheme' => 'http', 'useTLS' => false],
    ]]);
    Broadcast::purge('reverb');
    require base_path('routes/channels.php');
    [$anna, $bert, $carl] = User::factory()->count(3)->create();
    $match = HyperOn::versus($anna, $bert);
    $auth = fn (User $user, string $channel) => $this->actingAs($user)->post('/broadcasting/auth', ['socket_id' => '1.1', 'channel_name' => $channel]);

    $auth($anna, 'private-hyper.'.$match->ulid)->assertOk();
    $auth($bert, 'private-hyper.'.$match->ulid)->assertOk();
    $auth($carl, 'private-hyper.'.$match->ulid)->assertForbidden();
    $auth($anna, 'private-hyper.'.$match->ulid.'.seat.0')->assertOk();
    $auth($bert, 'private-hyper.'.$match->ulid.'.seat.0')->assertForbidden();
    $auth($anna, 'presence-hyper.'.$match->ulid.'.here')->assertOk()->assertJsonPath('channel_data', json_encode(['user_id' => (string) $anna->id, 'user_info' => ['id' => $anna->id, 'seat' => 0, 'name' => $anna->displayName()]]));
    $auth($carl, 'presence-hyper.'.$match->ulid.'.here')->assertForbidden();
});

test('a player who leaves is replaced by a bot, which finishes the turn', function () {
    HyperOn::play();
    [$anna, $bert] = User::factory()->count(2)->create();
    $match = HyperOn::versus($anna, $bert, bots: 1);

    $this->actingAs($anna)->postJson(route('hyper.leave', $match))->assertOk()->assertJsonPath('snapshot.seats.0.bot', true);

    $match->refresh();
    expect($match->seats[0]->takeover)->toBe('left')
        ->and($match->seats[0]->left_at)->not->toBeNull()
        ->and($match->actions()->where('source', HyperAction::LEAVE)->value('action'))->toBe(['type' => 'end_turn'])
        ->and($match->current_seat)->toBe(1);
    $this->actingAs($anna)->postJson(route('hyper.leave', $match))->assertForbidden()->assertJsonPath('reason', 'already_left');
    hyperAct($anna, $match, ['type' => 'end_turn'])->assertForbidden()->assertJsonPath('reason', 'seat_taken_over');
    expect(app(HyperMatches::class)->snapshot($match, $anna)['state']['seats'][0]['hand'])->toBeNull()
        ->and(app(HyperMatches::class)->eventsSince($match, $anna, 0)['actions'])->not->toBeEmpty()
        ->and($match->handSeatOf($anna))->toBeNull()
        ->and($match->handSeatOf($bert))->toBe(1);
});

test('emotes reach the table, three stickers a minute and one clip a turn per player', function () {
    HyperOn::play();
    Event::fake([HyperEmoteSent::class]);
    [$anna, $bert, $carl] = User::factory()->count(3)->create();
    $match = HyperOn::versus($anna, $bert);
    $emote = fn (User $user, string $emote) => $this->actingAs($user)->postJson(route('hyper.emote', $match), ['emote' => $emote]);

    foreach (['gg', 'hodl', 'brrr'] as $sticker) {
        $emote($anna, $sticker)->assertOk()->assertJson(['seat' => 0, 'kind' => 'sticker', 'emote' => $sticker]);
    }

    $emote($anna, 'rekt')->assertTooManyRequests()->assertJsonPath('reason', 'emote_throttled');
    $emote($bert, 'rekt')->assertOk();
    $emote($anna, 'cerca-wette')->assertOk()->assertJsonPath('kind', 'clip');
    $emote($anna, 'dennis-traum')->assertTooManyRequests();
    $emote($anna, 'not-a-clip')->assertUnprocessable()->assertJsonPath('reason', 'unknown_emote');
    $emote($carl, 'gg')->assertForbidden();

    $this->travel(61)->seconds();
    $emote($anna, 'rekt')->assertOk();

    Event::assertDispatchedTimes(HyperEmoteSent::class, 6);
    Event::assertDispatched(HyperEmoteSent::class, fn (HyperEmoteSent $event): bool => hyperChannelNames($event) === ['private-hyper.'.$match->ulid, 'hyper.'.$match->ulid.'.watch']);
});

test('each match has its own table chat, fixed by the creator and the match id, and the snapshot names it', function () {
    HyperOn::play();
    $league = new TestSigner;
    config(['esports.league.nsec' => $league->secret, 'esports.game_chat.creator' => null]);
    [$one, $two] = HyperMatch::factory()->count(2)->create();
    $signed = SignedEvent::fromInput($league->sign(40, [], GameChannels::matchContent($one->ulid), GameChannels::CREATED_AT));

    expect(GameChannels::matchChannelId($one->ulid))->toMatch('/^[0-9a-f]{64}$/')
        ->toBe($signed?->id)
        ->toBe(GameChannels::matchChannelId($one->ulid))
        ->not->toBe(GameChannels::matchChannelId($two->ulid))
        ->not->toBe(GameChannels::channelId('chess'))
        ->and(app(HyperMatches::class)->snapshot($one, null)['chat']['channel'])->toBe(GameChannels::matchChannelId($one->ulid));

    // A fixed creator and match id: the id never changes (a changed field would orphan the chat written into it).
    config(['esports.game_chat.creator' => str_repeat('a', 64)]);
    expect(GameChannels::matchChannelId('01k7000000000000000000000a'))->toBe(hash('sha256', json_encode([0, str_repeat('a', 64), GameChannels::CREATED_AT, 40, [], GameChannels::matchContent('01k7000000000000000000000a')], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));

    config(['esports.game_chat.creator' => null, 'esports.league.nsec' => null]);
    expect(GameChannels::matchChannelId($one->ulid))->toBeNull();
});
