<?php

use App\Enums\HyperMatchStatus;
use App\Models\HyperAction;
use App\Models\HyperMatch;
use App\Models\User;
use App\Support\Hyper\HyperGame;
use App\Support\Hyper\HyperMatches;
use App\Support\Hyper\HyperReplay;
use Tests\Support\HyperOn;

/*
| The replay of a finished Hyperbitcoinization match (plan "Hyperbitcoinization", P3): rebuilt from the seed and
| the action log through the rules core, never from a stored state, it ends on the stored state exactly; every
| ply's events come out the same; the replay page and its endpoints show every hand; a running match has none.
| And the sats a player collected in finished matches stand on the player page.
*/

beforeEach(function () {
    HyperOn::play();
});

/**
 * A match played to its end on the server: Anna plays a turn, a correspondence deadline lets a bot play one for
 * her, then she leaves and the bots finish it at the bot-only round cap (a `round_limit` action).
 */
function hyperPlayedToTheEnd(User $anna, int $seed = 21): HyperMatch
{
    config(['esports.hyper.bot_round_cap' => 4]);
    $matches = app(HyperMatches::class);
    $match = $matches->create([['user' => $anna, 'faction' => 'goldbug'], ['bot' => true], ['bot' => true]], seed: $seed, creator: $anna, mode: HyperMatch::CORRESPONDENCE);
    $mine = $matches->snapshot($match, $anna)['legal']['deploy'][0];
    $matches->act($match, $anna, ['type' => 'deploy', 'territory' => $mine, 'unit' => 'pleb', 'qty' => 1], 1);
    $matches->act($match->refresh(), $anna, ['type' => 'end_turn']);
    test()->travel(24 * 3600 + 1)->seconds();
    $matches->checkClock($match->refresh());
    $matches->leave($match->refresh(), $anna);

    return $match->refresh();
}

test('replaying a finished match from its seed and actions ends on the stored state exactly, every ply with the same events', function () {
    $anna = User::factory()->create();
    $match = hyperPlayedToTheEnd($anna);
    $sources = $match->actions()->pluck('source')->unique()->values()->all();

    expect($match->status)->toBe(HyperMatchStatus::Finished)
        ->and($sources)->toContain(HyperAction::PLAYER, HyperAction::TIMER, HyperAction::LEAVE, HyperAction::BOT, HyperAction::SERVER)
        ->and($match->actions()->where('action->type', 'round_limit')->count())->toBe(1);

    $replay = new HyperReplay($match);

    expect($replay->ply())->toBe($match->ply)
        ->and($replay->game()->toArray())->toBe(HyperGame::fromArray($match->state)->toArray())
        ->and($replay->plies())->toHaveCount($match->ply)
        // Through JSON, as the endpoint sends them (a stored 1.0 reads back as 1).
        ->and(json_decode((string) json_encode(array_column($replay->plies(), 'events')), true))->toBe($match->actions()->where('ply', '>', 0)->get()->pluck('events')->all());

    // Halfway, the table is the one after that ply: the seed is the same, the dice still to come.
    $half = intdiv($match->ply, 2);
    expect((new HyperReplay($match, $half))->ply())->toBe($half)
        ->and((new HyperReplay($match, $half))->game()->isOver())->toBeFalse();
});

test('a different seed does not replay the stored match', function () {
    $anna = User::factory()->create();
    $match = hyperPlayedToTheEnd($anna);
    $match->seed = ($match->seed + 1) % 0x100000000;

    $replayed = rescue(fn () => (new HyperReplay($match))->game()->toArray(), null, false);

    expect($replayed)->not->toBe(HyperGame::fromArray($match->refresh()->state)->toArray());
});

test('the replay page and its endpoints: plies and the state at a ply, every hand open; a running match has no replay', function () {
    $this->withoutVite();
    $anna = User::factory()->create();
    $match = hyperPlayedToTheEnd($anna);
    $running = HyperOn::versus($anna, User::factory()->create());

    $this->get(route('hyper.replay', $running))->assertNotFound();
    $this->getJson(route('hyper.replay.data', $running))->assertNotFound();

    $page = $this->get(route('hyper.replay', $match))->assertOk()
        ->assertSee('data-replay="1"', false)
        ->assertSee('data-test="hyper-replay-bar"', false)
        ->assertDontSee('data-test="hyper-rematch"', false);
    preg_match('#id="hyper-config">(.*?)</script>#s', (string) $page->getContent(), $config);
    $config = json_decode($config[1], true, flags: JSON_THROW_ON_ERROR);
    expect($config['replay']['last'])->toBe($match->ply);

    $data = $this->getJson(route('hyper.replay.data', $match))->assertOk();
    expect($data->json('ply'))->toBe($match->ply)
        ->and($data->json('plies'))->toHaveCount($match->ply)
        // A drawn card shows in the replay; the match's own catch-up hides it from a guest.
        ->and(collect($data->json('plies'))->flatMap(fn (array $ply): array => $ply['events'])->firstWhere('type', 'card_drawn')['card'] ?? null)->toBeString();

    $end = $this->getJson(route('hyper.replay.state', ['match' => $match, 'ply' => $match->ply]))->assertOk();
    $start = $this->getJson(route('hyper.replay.state', ['match' => $match, 'ply' => 0]))->assertOk();
    expect($end->json('status'))->toBe('finished')
        ->and($end->json('me'))->toBeNull()
        ->and($end->json('legal'))->toBeNull()
        ->and($end->json('state.seed'))->toBe($match->seed)
        ->and($end->json('state.rng'))->toBeNull()
        ->and(array_column($end->json('state.seats'), 'hand'))->each->toBeArray()
        ->and($end->json('state.territories'))->toBe(HyperGame::fromArray($match->state)->toArray()['territories'])
        ->and($start->json('ply'))->toBe(0)
        ->and($start->json('status'))->toBe('active')
        ->and($start->json('deadline_ms'))->toBeNull();

    // The end screen of the match itself leads to the replay.
    $this->actingAs($anna)->get(route('hyper.match', $match))->assertOk()
        ->assertSee('href="'.route('hyper.replay', $match, false).'"', false)
        ->assertSee('data-test="hyper-rematch"', false);
});

test('the player page shows the sats collected in finished Hyperbitcoinization matches, game points only', function () {
    $this->withoutVite();
    [$anna, $bert] = User::factory()->count(2)->create();
    $first = hyperPlayedToTheEnd($anna, 21);
    $second = hyperPlayedToTheEnd($anna, 22);
    HyperOn::versus($anna, $bert);
    $sats = round($first->seats->firstWhere('user_id', $anna->id)->loot + $second->seats->firstWhere('user_id', $anna->id)->loot, 1);

    $this->get(route('players.show', $anna->npub))->assertOk()
        ->assertSee('data-test="player-hyper"', false)
        ->assertSee('data-test="player-hyper-sats">'.rtrim(rtrim(number_format($sats, 1, '.', ''), '0'), '.').'<', false)
        ->assertSee(trans_choice(':count finished match|:count finished matches', 2))
        ->assertDontSee('payout', false);

    // Bert played no finished match: no card.
    $this->get(route('players.show', $bert->npub))->assertOk()->assertDontSee('data-test="player-hyper"', false);
});
