<?php

use App\Enums\HyperMatchStatus;
use App\Models\HyperMatch;
use App\Models\User;
use App\Support\Hyper\HyperGame;
use App\Support\Hyper\HyperMap;
use App\Support\Hyper\HyperMatches;
use App\Support\Hyper\HyperRng;
use App\Support\Hyper\HyperStats;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Tests\Support\HyperOn;

/*
| The end-of-match statistics of Hyperbitcoinization (plan "Hyperbitcoinization", P3, "Nachspiel-Statistik"):
| computed from the replay, they agree with the action log counted here independently from the stored events;
| every moment scene is triggered by a crafted table played through the rules core; the endpoint answers only
| for a finished match and caches the result.
*/

beforeEach(function () {
    HyperOn::play();
});

/**
 * Anna plays one turn by hand (a deploy, a blitz on her best target, the end of her turn), then leaves; the
 * bots finish the match at the bot-only round cap.
 */
function hyperStatsMatch(User $anna, int $seed): HyperMatch
{
    config(['esports.hyper.bot_round_cap' => 5]);
    $matches = app(HyperMatches::class);
    $match = $matches->create([['user' => $anna, 'faction' => 'goldbug'], ['bot' => true], ['bot' => true], ['bot' => true]], seed: $seed, creator: $anna, mode: HyperMatch::CORRESPONDENCE);
    $legal = $matches->snapshot($match, $anna)['legal'];
    $matches->act($match, $anna, ['type' => 'deploy', 'territory' => $legal['deploy'][0], 'unit' => 'pleb', 'qty' => 'max']);
    $matches->act($match->refresh(), $anna, ['type' => 'end_phase']);
    $attack = $matches->snapshot($match->refresh(), $anna)['legal']['attack'];

    if ($attack !== []) {
        $from = (string) array_key_first($attack);
        $matches->act($match->refresh(), $anna, ['type' => 'attack', 'from' => $from, 'to' => $attack[$from][0], 'mode' => 'blitz']);
    }

    $matches->act($match->refresh(), $anna, ['type' => 'end_turn']);
    $matches->leave($match->refresh(), $anna);

    return $match->refresh();
}

/**
 * A crafted table on the rules core: seat 0 to move in round 2's attack phase, every territory neutral with one
 * pleb but `land` (`id => [owner, plebs]`), the seats' purse and hand as given, the dice from `rng`.
 *
 * @param  list<string>  $factions
 * @param  array<string, array{int, int}>  $land
 * @param  array<int, array<string, mixed>>  $seats
 */
function hyperCraftedTable(array $factions, array $land, array $seats, int $rng): HyperGame
{
    $state = HyperGame::start(array_map(fn (string $faction): array => ['faction' => $faction], $factions), 0, 1)->game->toArray();

    foreach (HyperMap::IDS as $id) {
        $state['territories'][$id] = ['owner' => $land[$id][0] ?? null, 'pleb' => $land[$id][1] ?? 1, 'maxi' => 0, 'asic' => 0, 'shield' => null];
    }

    foreach ($seats as $index => $purse) {
        $state['seats'][$index] = [...$state['seats'][$index], ...$purse];
    }

    foreach ($state['seats'] as $index => $seat) {
        $state['seats'][$index] = [...$seat, 'hand' => $seats[$index]['hand'] ?? [], 'free_plebs' => 0];
    }

    return HyperGame::fromArray([...$state, 'round' => 2, 'seat' => 0, 'phase' => 'attack', 'placed' => [], 'pending_move' => null, 'rng' => HyperRng::seeded($rng)->state()]);
}

/**
 * Plays the actions of the seat to move on the crafted table and feeds every ply to HyperStats, as the replay does.
 *
 * @param  list<array<string, mixed>>  $actions
 * @return array<string, mixed>
 */
function hyperStatsOfPlay(HyperGame $game, array $actions): array
{
    $stats = new HyperStats;
    $stats->observe(null, $game, ['ply' => 0, 'seat' => 0, 'source' => 'server', 'events' => []]);

    foreach ($actions as $index => $action) {
        $step = $game->apply($game->currentSeat(), $action);
        $stats->observe($game, $step->game, ['ply' => $index + 1, 'seat' => $game->currentSeat(), 'source' => 'player', 'events' => $step->events]);
        $game = $step->game;
    }

    expect($game->isOver())->toBeTrue();

    return $stats->result($game);
}

test('the statistics agree with the action log, counted independently from the stored events', function () {
    $anna = User::factory()->create();

    foreach ([21, 33] as $seed) {
        $match = hyperStatsMatch($anna, $seed);
        expect($match->status)->toBe(HyperMatchStatus::Finished);
        $stats = HyperStats::compute($match);
        $seats = $match->seats->count();
        $final = HyperGame::fromArray($match->state);

        // Counted from hyper_actions as stored while the match was played, not from the replay.
        $battles = array_fill(0, $seats, 0);
        $runs = [];
        $turn = [];
        $banks = 0;
        $zones = 0;
        $knockouts = 0;
        $rounds = 1;
        $previous = [];

        // Ply 0 is the setup: the first turn starts there.
        foreach ($match->actions()->get() as $action) {
            $events = $action->events;
            $types = array_column($events, 'type');

            if (in_array('dice_rolled', $types, true)) {
                $battles[$action->seat]++;
            }

            foreach ($events as $index => $event) {
                match ($event['type']) {
                    'turn_started' => $turn[$event['seat']] = 0,
                    'territory_conquered' => $runs[] = ++$turn[$event['seat']],
                    'card_played' => $event['card'] === 'attack51' && ($events[$index + 1]['type'] ?? null) !== 'territory_conquered' ? $runs[] = ++$turn[$event['seat']] : null,
                    'round_started' => $rounds = $event['round'],
                    default => null,
                };

                if ($event['type'] === 'territory_conquered') {
                    $previous[$event['territory']] = $event['previous_owner'];
                }

                $banks += (int) ($event['type'] === 'bank_fallen' && ($previous[$event['territory']] ?? null) !== null);
                $zones += (int) ($event['type'] === 'zone_completed');
                $knockouts += (int) ($event['type'] === 'player_eliminated');
            }
        }

        $loot = $match->seats->pluck('loot')->map(fn (mixed $loot): float => (float) $loot)->all();
        $turningPoints = array_count_values(array_column($stats['turning_points'], 'type'));

        expect(array_sum($battles))->toBeGreaterThan(0)
            ->and($stats['leaders']['battles']['all'])->toBe($battles)
            ->and($stats['leaders']['battles']['seat'])->toBe(array_search(max($battles), $battles, true))
            ->and($stats['leaders']['conquest_run']['value'])->toBe($runs === [] ? 0 : max($runs))
            ->and(array_map(fn (float|int $value): float => (float) $value, $stats['leaders']['loot']['all']))->toBe($loot)
            ->and($stats['rounds'])->toBe($rounds)
            ->and($stats['winner'])->toBe($match->winner_seat)
            ->and($turningPoints['bank_fallen'] ?? 0)->toBe($banks)
            ->and($turningPoints['zone_completed'] ?? 0)->toBe($zones)
            ->and($turningPoints['player_eliminated'] ?? 0)->toBe($knockouts);

        // One point per round and the setup; the last is the table at the end, the first the one after the deal.
        $start = HyperGame::start(array_map(fn (string $faction): array => ['faction' => $faction], $stats['factions']), $match->round_limit, $match->seed)->game;

        foreach (range(0, $seats - 1) as $seat) {
            expect($stats['series']['territories'][$seat])->toHaveCount($rounds + 1)
                ->and(end($stats['series']['territories'][$seat]))->toBe(count($final->territoriesOf($seat)))
                ->and(end($stats['series']['units'][$seat]))->toBe($final->unitsOf($seat))
                ->and(end($stats['series']['banks'][$seat]))->toBe($final->banksOf($seat))
                ->and((float) end($stats['series']['loot'][$seat]))->toBe((float) $final->seatAt($seat)['loot'])
                ->and($stats['series']['territories'][$seat][0])->toBe(count($start->territoriesOf($seat)));
        }

        expect($stats['moments'][0])->toMatchArray(['key' => 'finale', 'seat' => $match->winner_seat]);
    }
});

test('a hand-counted crafted match: two battles, a run of two, a fallen bank, a completed space, a knockout and every pride moment', function () {
    // Bitcoiner (seat 0) holds the Dollar Zone but New York and Mexico; the Fed (seat 1) holds both with one pleb.
    $table = hyperCraftedTable(['bitcoiner', 'fed'], ['alaska' => [0, 1], 'kanada' => [0, 1], 'westk' => [0, 1], 'texas' => [0, 40], 'ny' => [1, 1], 'mexiko' => [1, 1]], [], 62);

    $stats = hyperStatsOfPlay($table, [
        ['type' => 'attack', 'from' => 'texas', 'to' => 'ny', 'mode' => 'blitz'],
        ['type' => 'move_in', 'count' => 5],
        ['type' => 'attack', 'from' => 'texas', 'to' => 'mexiko', 'mode' => 'blitz'],
    ]);
    $moments = collect($stats['moments'])->keyBy('key');

    expect($stats['leaders']['battles'])->toBe(['seat' => 0, 'value' => 2, 'all' => [2, 0]])
        ->and($stats['leaders']['conquest_run'])->toBe(['seat' => 0, 'value' => 2, 'round' => 2])
        ->and($stats['winner'])->toBe(0)
        ->and($stats['turning_points'])->toBe([
            ['round' => 2, 'type' => 'bank_fallen', 'seat' => 0, 'loser' => 1, 'territory' => 'ny'],
            ['round' => 2, 'type' => 'zone_completed', 'seat' => 0, 'zone' => 'dollar'],
            ['round' => 2, 'type' => 'player_eliminated', 'seat' => 0, 'loser' => 1],
        ])
        ->and($moments->keys()->all())->toBe(['finale', 'first_bank', 'zone', 'comeback', 'perfect_dice', 'knockout'])
        ->and($moments['finale'])->toMatchArray(['seat' => 0, 'faction' => 'bitcoiner', 'by_limit' => false])
        ->and($moments['first_bank'])->toMatchArray(['seat' => 0, 'territory' => 'ny', 'loser' => 1])
        ->and($moments['zone'])->toMatchArray(['seat' => 0, 'zone' => 'dollar'])
        ->and($moments['comeback'])->toMatchArray(['seat' => 0, 'zone' => 'dollar', 'round' => 2])
        ->and($moments['perfect_dice'])->toMatchArray(['seat' => 0])
        ->and($moments['knockout'])->toMatchArray(['seat' => 0, 'victim' => 1])
        // Territories over x = 0 (the table as crafted) and x = 2 (the end of the match in round 2).
        ->and($stats['series']['territories'])->toBe([[4, 6], [2, 0]]);
});

test('every meme moment: Lost Keys on the richest, the coin flip lost, Pizza Day, the 51% attack and a Nocoiner win', function () {
    // The Nocoiner (seat 0) next to the Fed in Mexico and the Shitcoiner (richest) in New York.
    $table = hyperCraftedTable(['nocoiner', 'fed', 'shitcoiner'], ['alaska' => [0, 1], 'kanada' => [0, 1], 'westk' => [0, 1], 'texas' => [0, 40], 'ny' => [2, 1], 'mexiko' => [1, 1]], [
        0 => ['hand' => ['keys', 'salvador', 'pizza', 'attack51'], 'sats' => 10.0],
        2 => ['sats' => 50.0],
    ], 1);

    $stats = hyperStatsOfPlay($table, [
        ['type' => 'play_card', 'card' => 'keys'],
        ['type' => 'play_card', 'card' => 'salvador'],
        ['type' => 'play_card', 'card' => 'pizza'],
        ['type' => 'play_card', 'card' => 'attack51', 'target' => 'mexiko'],
        ['type' => 'attack', 'from' => 'texas', 'to' => 'ny', 'mode' => 'blitz'],
    ]);
    $moments = collect($stats['moments'])->keyBy('key');

    expect($moments->only(['lost_keys', 'salvador_lost', 'pizza', 'attack51', 'bitcoin_dead'])->keys()->all())->toBe(['lost_keys', 'salvador_lost', 'pizza', 'attack51', 'bitcoin_dead'])
        ->and($moments['lost_keys'])->toMatchArray(['seat' => 0, 'victim' => 2, 'sats' => 15.0])
        ->and($moments['salvador_lost'])->toMatchArray(['seat' => 0])
        ->and($moments['pizza'])->toMatchArray(['seat' => 0])
        ->and($moments['attack51'])->toMatchArray(['seat' => 0, 'territory' => 'mexiko'])
        ->and($moments['bitcoin_dead'])->toMatchArray(['seat' => 0])
        // The 51% attack and the blitz on New York: two conquests in one turn, one battle (the card rolls no dice).
        ->and($stats['leaders']['conquest_run']['value'])->toBe(2)
        ->and($stats['leaders']['battles']['all'])->toBe([1, 0, 0]);

    // A coin flip won, and a winner that is not the Nocoiner, show neither.
    $won = hyperStatsOfPlay(hyperCraftedTable(['bitcoiner', 'fed'], ['texas' => [0, 40], 'mexiko' => [1, 1]], [0 => ['hand' => ['salvador'], 'sats' => 10.0]], 6), [
        ['type' => 'play_card', 'card' => 'salvador'],
        ['type' => 'attack', 'from' => 'texas', 'to' => 'mexiko', 'mode' => 'blitz'],
    ]);
    expect(array_column($won['moments'], 'key'))->not->toContain('salvador_lost')
        ->and(array_column($won['moments'], 'key'))->not->toContain('bitcoin_dead');
});

test('the longest defence and the unluckiest roller come from repelled attacks and the pips against 3.5 a die', function () {
    // Seat 0 attacks Mexico (seat 1, 30 plebs) twice from Texas with three units each time; Mexico holds.
    $table = hyperCraftedTable(['bitcoiner', 'fed'], ['texas' => [0, 4], 'westk' => [0, 4], 'mexiko' => [1, 30], 'ny' => [1, 1]], [], 5);
    $game = $table;
    $stats = new HyperStats;
    $stats->observe(null, $game, ['ply' => 0, 'seat' => 0, 'source' => 'server', 'events' => []]);
    $pips = [0 => 0, 1 => 0];
    $dice = [0 => 0, 1 => 0];

    foreach ([['texas', 'mexiko'], ['westk', 'mexiko']] as $index => [$from, $to]) {
        $step = $game->apply(0, ['type' => 'attack', 'from' => $from, 'to' => $to, 'mode' => 'roll']);
        $stats->observe($game, $step->game, ['ply' => $index + 1, 'seat' => 0, 'source' => 'player', 'events' => $step->events]);
        $game = $step->game;
        $roll = collect($step->events)->firstWhere('type', 'dice_rolled');
        $pips[0] += array_sum($roll['attacker']);
        $dice[0] += count($roll['attacker']);
        $pips[1] += array_sum($roll['defender']);
        $dice[1] += count($roll['defender']);
    }

    $result = $stats->result($game);
    $balance = [0 => $pips[0] - 3.5 * $dice[0], 1 => $pips[1] - 3.5 * $dice[1]];
    $worst = $balance[0] <= $balance[1] ? 0 : 1;

    expect($result['leaders']['defence'])->toBe(['seat' => 1, 'value' => 2, 'territory' => 'mexiko'])
        ->and($result['leaders']['battles']['all'])->toBe([2, 0])
        ->and($result['leaders']['unlucky'])->toBe(['seat' => $worst, 'value' => round($balance[$worst], 1), 'dice' => $dice[$worst]]);
});

test('the endpoint answers for a finished match only, from the cache once computed', function () {
    $anna = User::factory()->create();
    $running = HyperOn::versus($anna, User::factory()->create());
    $this->getJson(route('hyper.stats', $running))->assertNotFound();

    // A finished match whose state was written by hand does not replay: no statistics, and no error page.
    $bert = User::factory()->create();
    $handWritten = HyperOn::endgame($anna, $bert);
    app(HyperMatches::class)->act($handWritten, $anna, ['type' => 'play_card', 'card' => 'attack51', 'target' => 'mexiko']);
    expect($handWritten->refresh()->status)->toBe(HyperMatchStatus::Finished);
    $this->getJson(route('hyper.stats', $handWritten))->assertNoContent();

    $match = hyperStatsMatch($anna, 21);
    $response = $this->getJson(route('hyper.stats', $match))->assertOk()
        ->assertJsonStructure(['version', 'rounds', 'winner', 'factions', 'series' => HyperStats::SERIES, 'leaders' => ['battles', 'conquest_run', 'defence', 'loot', 'unlucky'], 'turning_points', 'moments']);

    // Through JSON, as the endpoint sends it (a cached 1.0 reads back as 1).
    expect(json_decode((string) json_encode(Cache::get('hyper:stats:v'.HyperStats::VERSION.':'.$match->ulid)), true))->toBe($response->json());

    // A cached answer is served as cached: the stored log is not replayed again.
    Cache::put('hyper:stats:v'.HyperStats::VERSION.':'.$match->ulid, ['cached' => true], 60);
    $this->getJson(route('hyper.stats', $match))->assertOk()->assertExactJson(['cached' => true]);

    // The match page and the replay page hand the endpoint to the script.
    $this->withoutVite();
    foreach ([route('hyper.replay', $match), route('hyper.match', $match)] as $url) {
        preg_match('#id="hyper-config">(.*?)</script>#s', (string) $this->get($url)->assertOk()->getContent(), $config);
        expect(json_decode($config[1], true, flags: JSON_THROW_ON_ERROR)['urls']['stats'])->toBe(route('hyper.stats', $match, false));
    }
});

test('the sequence runs 30 to 60 s, can be skipped and browsed by hand (Node)', function () {
    $run = Process::path(base_path())->timeout(60)->run(['node', '--test', 'tests/js/hyperStats.test.mjs']);

    expect($run->successful())->toBeTrue($run->output().$run->errorOutput())
        ->and($run->output())->toContain('ℹ pass 4')->toContain('ℹ skipped 0');
});
