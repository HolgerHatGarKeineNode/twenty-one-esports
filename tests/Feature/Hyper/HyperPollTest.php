<?php

use App\Enums\TournamentFormat;
use App\Models\HyperMatch;
use App\Models\NostrEvent;
use App\Models\User;
use App\Support\GameChat\GameChannels;
use App\Support\Hyper\HyperMatches;
use App\Support\Hyper\HyperPoll;
use App\Support\Hyper\HyperPublisher;
use App\Support\Nostr\SignedEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Support\HyperOn;
use Tests\Support\TestSigner;

/*
| The spectators' "Who wins?" of a rated or tournament Hyperbitcoinization match (plan "Hyperbitcoinization", P5): a
| NIP-88 poll fixed by the match alone (the NIP's test vector), shown to spectators only, never to a player; and what
| the league signs behind `esports.hyper.publish`: the poll at the start, the result (`2154`) at the end, once each.
*/

beforeEach(function () {
    $this->withoutVite();
    HyperOn::play();
});

/**
 * @return array<string, mixed>|null
 */
function hyperPageConfig(HyperMatch $match, ?User $viewer): ?array
{
    $page = ($viewer === null ? test() : test()->actingAs($viewer))->get(route('hyper.match', $match))->assertOk();
    preg_match('#id="hyper-config">(.*?)</script>#s', (string) $page->getContent(), $found);

    return json_decode($found[1], true, flags: JSON_THROW_ON_ERROR);
}

test('the poll is fixed by the match alone: its id is the NIP\'s test vector', function () {
    config(['esports.game_chat.creator' => str_repeat('a', 64)]);
    $match = HyperMatch::factory()->create(['ulid' => '01K74R2X7YQ0Z8D6P4M3N2B1C0', 'mode' => 'live', 'rated' => true, 'season' => 'pre-season', 'created_at' => Carbon::createFromTimestamp(1791496800)]);
    $match->seats()->delete();
    foreach (['bitcoiner', 'fed', 'ezb', 'goldbug'] as $seat => $faction) {
        $match->seats()->create(['seat' => $seat, 'user_id' => User::factory()->create()->id, 'faction' => $faction, 'bot' => false]);
    }

    $event = HyperPoll::event($match->refresh());

    expect(GameChannels::matchChannelId($match->ulid))->toBe('b724a470ed82a8fd8ced9ad7e6c09588c4f3b146a8ce35a679d4e8af4c8ea131')
        ->and($event['id'])->toBe('70f61499bede51bad60a0a5560c3cb9474fd346ea6dbfbe8d8a341ce16036506')
        ->and($event['content'])->toBe('Who wins?')
        ->and(collect($event['tags'])->where(0, 'option')->values()->all())->toBe([
            ['option', 's0', 'Seat 1 · Bitcoiner'], ['option', 's1', 'Seat 2 · Fed'], ['option', 's2', 'Seat 3 · ECB'], ['option', 's3', 'Seat 4 · Goldbug'],
        ]);
});

test('a spectator of a rated or tournament match gets the poll, a player never (not after leaving either), a casual match nobody', function () {
    config(['esports.game_chat.creator' => (new TestSigner)->pubkey]);
    [$anna, $bert, $carl] = User::factory()->count(3)->create();
    $casual = HyperOn::versus($anna, $bert);
    openSeason(ladders: false);
    $rated = HyperOn::versus($anna, $bert);
    app(HyperMatches::class)->leave($rated, $bert);

    // A guest first: every request after actingAs() is that user's.
    $guest = hyperPageConfig($rated, null)['poll'];
    expect($guest['id'])->toBe(HyperPoll::event($rated)['id'])->and($guest['me'])->toBeNull();

    expect(hyperPageConfig($casual, $carl)['poll'])->toBeNull()
        ->and(hyperPageConfig($rated, $anna)['poll'])->toBeNull()
        ->and(hyperPageConfig($rated, $bert)['poll'])->toBeNull();

    $poll = hyperPageConfig($rated, $carl)['poll'];

    expect($poll['id'])->toBe(HyperPoll::event($rated)['id'])
        ->and(array_column($poll['options'], 'id'))->toBe(['s0', 's1'])
        ->and($poll['options'][0]['label'])->toBe($anna->displayName().' · Bitcoiner')
        ->and($poll['players'])->toBe([$anna->pubkey, $bert->pubkey])
        ->and($poll['closedAt'])->toBeNull()
        ->and($poll['me'])->toBe($carl->pubkey);

    $this->actingAs($carl)->get(route('hyper.match', $rated))->assertSee('data-test="hyper-poll-open"', false)->assertSee('data-test="hyper-poll"', false);
    $this->actingAs($anna)->get(route('hyper.match', $rated))->assertDontSee('data-test="hyper-poll-open"', false)->assertDontSee('data-test="hyper-poll"', false);

    // A tournament match has it even outside a season; it closes with the match.
    $tournament = HyperOn::tournament(2, TournamentFormat::SingleElimination);
    $table = HyperMatch::query()->whereNotNull('tournament_match_id')->sole();
    HyperOn::finishTable($table, [0, 1]);

    expect(hyperPageConfig($table->refresh(), $carl)['poll']['closedAt'])->toBe($table->ended_at->getTimestamp())
        ->and($tournament->refresh()->status->value)->toBe('finished');
});

test('with publishing on, the league signs the poll at the start and the result at the end, once each; off, nothing', function () {
    Queue::fake();
    $season = openSeason(ladders: false);
    config(['esports.game_chat.creator' => null]);
    [$anna, $bert] = User::factory()->count(2)->create();

    // Off (on by default since 2026-10-09): a rated match signs nothing.
    config(['esports.hyper.publish' => false]);
    HyperOn::finish(HyperOn::ending(HyperOn::versus($anna, $bert), winner: 0, runnerUp: 1), $anna);
    expect(NostrEvent::query()->whereIn('kind', [1068, 2154])->count())->toBe(0);

    config(['esports.hyper.publish' => true]);
    $match = HyperOn::versus($anna, $bert);
    app(HyperMatches::class)->leave($match, $bert);
    $match = HyperOn::finish(HyperOn::ending($match->refresh(), winner: 0, runnerUp: 1), $anna);
    app(HyperPublisher::class)->poll($match->id);
    app(HyperPublisher::class)->result($match->id);

    $poll = NostrEvent::query()->where('kind', 1068)->sole();
    $result = SignedEvent::fromInput(NostrEvent::query()->where('kind', 2154)->sole()->payload());
    $tags = $result->tags;

    expect($poll->event_id)->toBe(HyperPoll::event($match)['id'])
        ->and($match->poll_event_id)->toBe($poll->event_id)
        ->and($match->result_event_id)->toBe($result->id)
        ->and($result->createdAt)->toBe($match->ended_at->getTimestamp())
        ->and(array_slice($tags, 0, 5))->toBe([['game', 'hyperbitcoinization'], ['mode', 'live'], ['format', 'duel'], ['hyper', $match->ulid], ['season', $season->slug]])
        ->and(collect($tags)->where(0, 'place')->values()->all())->toBe([['place', $anna->pubkey, '1', 'bitcoiner', ''], ['place', $bert->pubkey, '2', 'fed', '']])
        ->and(collect($tags)->where(0, 'forfeit')->values()->all())->toBe([['forfeit', $bert->pubkey]])
        ->and(collect($tags)->where(0, 'elo')->values()->all())->toBe([['elo', $anna->pubkey, '1020', '1038'], ['elo', $bert->pubkey, '980', '962']])
        ->and(collect($tags)->firstWhere(0, 'e'))->toBe(['e', GameChannels::matchChannelId($match->ulid), '', 'root'])
        ->and($result->content)->toBe('Forfeit: a player left the match or let their turns run out.');
});

test('publishing is on by default', function () {
    expect(config('esports.hyper.publish'))->toBeTrue();
});
