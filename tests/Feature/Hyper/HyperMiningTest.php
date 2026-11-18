<?php

use App\Enums\TournamentFormat;
use App\Models\AccountLink;
use App\Models\Clan;
use App\Models\HyperMatch;
use App\Models\NostrEvent;
use App\Models\Season;
use App\Models\SeasonAttestation;
use App\Models\User;
use App\Support\Hyper\HyperMatches;
use App\Support\Hyper\HyperMining;
use App\Support\Hyper\HyperPublisher;
use App\Support\SeasonChain\ChainDraft;
use App\Support\SeasonChain\SeasonChains;
use App\Support\SeasonChain\SeasonSettlement;
use App\Support\SeasonChain\TrustFacts;
use Illuminate\Support\Facades\Queue;
use Tests\Support\HyperOn;

/*
| Hyperbitcoinization in the season chain (NIP rev. 9.26, draft): dark until the season's parameters carry a weight
| for it; with one, a rated free-for-all or 1v1 between trusted players mines one block for its winner alone, who
| must pass every consensus rule against every other seat. The trust gate and the clans are pinned at the start.
*/

beforeEach(function () {
    Queue::fake();
    HyperOn::play();
    hyperTrust();
});

/**
 * The trust facts of the tests: every player at rank 100 and every pair listing each other, but for the ranks,
 * the pairs kept apart and the anchors given.
 *
 * @param  array<string, int>  $ranks  pubkey => rank
 * @param  list<array{0: User, 1: User}>  $apart  pairs that do not list each other
 * @param  array<string, array{0: string, 1: int}>  $anchors  pubkey => [anchor, share in percent]
 */
function hyperTrust(array $ranks = [], array $apart = [], array $anchors = []): void
{
    $apart = array_map(function (array $pair): string {
        $keys = [$pair[0]->pubkey, $pair[1]->pubkey];
        sort($keys);

        return implode('|', $keys);
    }, $apart);

    app()->instance(TrustFacts::class, new class($ranks, $apart, $anchors) implements TrustFacts
    {
        /**
         * @param  array<string, int>  $ranks
         * @param  list<string>  $apart
         * @param  array<string, array{0: string, 1: int}>  $anchors
         */
        public function __construct(private array $ranks, private array $apart, private array $anchors) {}

        public function available(): bool
        {
            return true;
        }

        public function at(array $players, array $gatekeepers): array
        {
            $pair = $gatekeepers;
            sort($pair);
            $connected = ! in_array(implode('|', $pair), $this->apart, true);

            return [
                'key' => str_repeat('7', 64),
                'trust' => array_combine($players, array_map(fn (string $player): int => $this->ranks[$player] ?? 100, $players)),
                'assertions' => array_fill_keys($players, str_repeat('a', 64)),
                'anchors' => array_intersect_key($this->anchors, array_flip($players)),
                'lists' => $connected ? array_fill_keys($gatekeepers, str_repeat('b', 64)) : [],
                'connected' => $connected,
            ];
        }
    });
}

/**
 * A live season in which Hyperbitcoinization mines: the draft's defaults and the game's proposal (weight 2 per mode,
 * 10 % and 5 a day), with the values given.
 *
 * @param  array<string, mixed>  $parameters
 */
function hyperMiningSeason(array $parameters = []): Season
{
    $defaults = ChainDraft::defaults();
    $proposal = config('season.chain.hyperbitcoinization_proposal');

    return openSeason(['parameters' => [
        'weights' => [...$defaults['weights'], ...$proposal['weights']],
        'groups' => $defaults['groups'],
        'shares' => ['chess' => 60, 'board-games' => 30, 'hyperbitcoinization' => $proposal['share']],
        'daily' => [...$defaults['daily'], 'hyperbitcoinization' => $proposal['daily']],
        'pairlimit' => $defaults['pairlimit'],
        'subtree' => $defaults['subtree'],
        'moves' => $defaults['moves'],
        ...$parameters,
    ]], ladders: false);
}

/**
 * A rated match of these players that ends with the first of them in place 1, the others behind in their order.
 *
 * @param  list<User>  $players  the winner first
 */
function hyperWin(array $players, string $mode = HyperMatch::LIVE): HyperMatch
{
    $match = app(HyperMatches::class)->create(array_map(fn (User $player): array => ['user' => $player], $players), seed: 7, mode: $mode);

    return HyperOn::finishTable($match, array_keys($players));
}

/** The chain's attestation of a match, or null when the chain signed none. */
function hyperAttestation(HyperMatch $match): ?SeasonAttestation
{
    return SeasonAttestation::query()->where(['source' => SeasonAttestation::HYPER, 'source_id' => $match->id])->first();
}

/**
 * The tags of the league's signed result of a match.
 *
 * @return list<list<string>>
 */
function hyperResultTags(HyperMatch $match): array
{
    return NostrEvent::query()->where('event_id', $match->refresh()->result_event_id)->sole()->payload()['tags'];
}

test('without a weight for the game a rated match pins nothing, is signed as before and mines nothing', function () {
    $season = openSeason(ladders: false);
    [$anna, $bert, $carl] = User::factory()->count(3)->create();

    $match = hyperWin([$anna, $bert, $carl]);

    expect($season->parameters['weights'])->not->toHaveKey('hyperbitcoinization/live')
        ->and($match->rated)->toBeTrue()
        ->and($match->gate_at_start)->toBeNull()
        ->and(SeasonAttestation::query()->count())->toBe(0)
        ->and(app(SeasonChains::class)->readout($season)['mined'])->toBe(0);

    $tags = hyperResultTags($match);

    expect(array_column($tags, 0))->not->toContain('block')
        ->and(array_column($tags, 0))->not->toContain('gate')
        ->and(NostrEvent::query()->where('event_id', $match->result_event_id)->sole()->signed_at)->toBe($match->ended_at->getTimestamp());
});

test('with a weight a rated free-for-all of trusted players mines one block for its winner alone, which the chain, the readout and the settlement count', function () {
    $season = hyperMiningSeason();
    [$anna, $bert, $carl, $dora] = User::factory()->count(4)->create();

    $match = hyperWin([$anna, $bert, $carl, $dora]);
    $attestation = hyperAttestation($match);
    $reward = $season->chainParameters()->rewardPerPlayer(2000, 1);

    expect($attestation)->not->toBeNull()
        ->and([$attestation->height, $attestation->reason, $attestation->game, $attestation->mode])->toBe([1, null, 'hyperbitcoinization', 'live'])
        ->and([$attestation->reward_per_player, $attestation->reward])->toBe([$reward, $reward])
        ->and($attestation->candidate['winners'])->toBe([$anna->pubkey])
        ->and($attestation->candidate['losers'])->toBe([$bert->pubkey, $carl->pubkey, $dora->pubkey])
        ->and($attestation->candidate['table']['connected'])->toBe([$bert->pubkey, $carl->pubkey, $dora->pubkey])
        ->and($attestation->event_id)->toBe($match->refresh()->result_event_id);

    $readout = app(SeasonChains::class)->readout($season);

    expect($readout['mined'])->toBe($reward)
        ->and(array_map(fn (array $block): array => [$block['weight_key'], $block['reward'], $block['winners']], $readout['blocks']))->toBe([['hyperbitcoinization/live', $reward, 1]])
        ->and($readout['stored']['miners'])->toBe([$anna->pubkey => ['blocks' => 1, 'sats' => $reward]]);

    $rows = collect(app(SeasonSettlement::class)->review($season)['rows'])->keyBy('pubkey');

    expect($rows->keys()->all())->toBe([$anna->pubkey])
        ->and([$rows[$anna->pubkey]['blocks'], $rows[$anna->pubkey]['mined'], $rows[$anna->pubkey]['heights']])->toBe([1, $reward, [1]]);

    // The one signed result carries the result's tags, the gate pinned at the start and the block.
    $tags = collect(hyperResultTags($match));

    expect($tags->where(0, 'block')->values()->all())->toBe([['block', '1', $season->genesisId()]])
        ->and($tags->where(0, 'season')->values()->all())->toBe([['season', $season->slug]])
        ->and($tags->where(0, 'place')->count())->toBe(4)
        ->and($tags->where(0, 'trust')->values()->all())->toBe([['trust', str_repeat('7', 64), (string) $season->minimum_trust]])
        ->and($tags->where(0, 'gate')->pluck(1)->all())->toBe([$anna->pubkey, $bert->pubkey, $carl->pubkey, $dora->pubkey])
        ->and($tags->where(0, 'listed')->count())->toBe(3)
        ->and(NostrEvent::query()->where('kind', SeasonChains::ATTESTATION)->count())->toBe(1);
});

test('a 1v1 mines for its winner, in correspondence too, and the pages that list blocks still render', function () {
    $season = hyperMiningSeason();
    [$anna, $bert] = User::factory()->count(2)->create();

    $match = hyperWin([$anna, $bert], HyperMatch::CORRESPONDENCE);

    expect(hyperAttestation($match)?->height)->toBe(1)
        ->and(hyperAttestation($match)?->mode)->toBe('correspondence');

    $this->withoutVite()->get(route('mining'))->assertOk();
    $this->actingAs($anna)->get(route('mining'))->assertOk();
});

test('a table that fails a consensus rule against one seat is attested without a block, and the chain names the reason', function (Closure $arrange, string $reason) {
    $season = hyperMiningSeason(['daily' => [...ChainDraft::defaults()['daily'], 'hyperbitcoinization' => 1], 'pairlimit' => [1, 1]]);
    [$anna, $bert, $carl] = User::factory()->count(3)->create();

    $match = $arrange($anna, $bert, $carl);
    $attestation = hyperAttestation($match);

    expect($attestation)->not->toBeNull()
        ->and([$attestation->height, $attestation->reason, $attestation->reward])->toBe([null, $reason, 0])
        ->and(collect(hyperResultTags($match))->where(0, 'block')->values()->all())->toBe([['block', '', $attestation->link_event_id]]);

    // Only an earlier win of the arrangement is a block; this table adds none for the readout and the settlement.
    $earlier = SeasonAttestation::query()->whereNotNull('height')->where('source_id', '!=', $match->id)->count();

    expect(app(SeasonChains::class)->readout($season)['blocks'])->toHaveCount($earlier)
        ->and(array_sum(array_column(app(SeasonSettlement::class)->review($season)['rows'], 'blocks')))->toBe($earlier);
})->with([
    'the winner below the trust minimum at the start' => [function (User $anna, User $bert, User $carl): HyperMatch {
        hyperTrust(ranks: [$anna->pubkey => 49]);

        return hyperWin([$anna, $bert, $carl]);
    }, 'not-trusted'],
    'a loser below the trust minimum at the start' => [function (User $anna, User $bert, User $carl): HyperMatch {
        hyperTrust(ranks: [$carl->pubkey => 49]);

        return hyperWin([$anna, $bert, $carl]);
    }, 'not-trusted'],
    'a winner who rose above the minimum only after the start' => [function (User $anna, User $bert, User $carl): HyperMatch {
        hyperTrust(ranks: [$anna->pubkey => 49]);
        $match = app(HyperMatches::class)->create([['user' => $anna], ['user' => $bert], ['user' => $carl]], seed: 7);
        hyperTrust();

        return HyperOn::finishTable($match, [0, 1, 2]);
    }, 'not-trusted'],
    'a winner barred under the fair-play rules' => [function (User $anna, User $bert, User $carl): HyperMatch {
        $admin = User::factory()->create();
        AccountLink::query()->create(['main_user_id' => $bert->id, 'main_pubkey' => $bert->pubkey, 'linked_user_id' => $anna->id, 'linked_pubkey' => $anna->pubkey,
            'linked_by_id' => $admin->id, 'linked_by_pubkey' => $admin->pubkey, 'reason' => 'Same person']);

        return hyperWin([$anna, $bert, $carl]);
    }, 'not-trusted'],
    'a match begun before the game had a weight, so without a pin' => [function (User $anna, User $bert, User $carl): HyperMatch {
        $match = app(HyperMatches::class)->create([['user' => $anna], ['user' => $bert], ['user' => $carl]], seed: 7);
        $match->forceFill(['gate_at_start' => null])->save();

        return HyperOn::finishTable($match, [0, 1, 2]);
    }, 'not-pinned'],
    'a loser the winner has not added as an opponent' => [function (User $anna, User $bert, User $carl): HyperMatch {
        hyperTrust(apart: [[$anna, $carl]]);

        return hyperWin([$anna, $bert, $carl]);
    }, 'not-connected'],
    'every seat of one clan' => [function (User $anna, User $bert, User $carl): HyperMatch {
        $clan = Clan::factory()->create();
        array_map(fn (User $player): User => HyperOn::inClan($player, $clan), [$anna, $bert, $carl]);

        return hyperWin([$anna, $bert, $carl]);
    }, 'same-clan'],
    'one loser of the winner\'s clan' => [function (User $anna, User $bert, User $carl): HyperMatch {
        $clan = Clan::factory()->create();
        array_map(fn (User $player): User => HyperOn::inClan($player, $clan), [$anna, $carl]);

        return hyperWin([$anna, $bert, $carl]);
    }, 'same-clan'],
    'a winner who left the clan of a loser only after the start' => [function (User $anna, User $bert, User $carl): HyperMatch {
        $clan = Clan::factory()->create();
        array_map(fn (User $player): User => HyperOn::inClan($player, $clan), [$anna, $carl]);
        $match = app(HyperMatches::class)->create([['user' => $anna], ['user' => $bert], ['user' => $carl]], seed: 7);
        $anna->clanMember()->delete();

        return HyperOn::finishTable($match, [0, 1, 2]);
    }, 'same-clan'],
    'a loser in the winner\'s trust circle' => [function (User $anna, User $bert, User $carl): HyperMatch {
        hyperTrust(anchors: [$anna->pubkey => [str_repeat('c', 64), 95], $carl->pubkey => [str_repeat('c', 64), 92]]);

        return hyperWin([$anna, $bert, $carl]);
    }, 'same-subtree'],
    'a pair over its limit for the day' => [function (User $anna, User $bert, User $carl): HyperMatch {
        hyperWin([$bert, $anna]);

        return hyperWin([$anna, $bert, $carl]);
    }, 'pairing-daily-limit'],
    'a pair over its limit for the season' => [function (User $anna, User $bert, User $carl): HyperMatch {
        hyperWin([$bert, $anna]);
        test()->travel(1)->days();

        return hyperWin([$anna, $bert, $carl]);
    }, 'pairing-season-limit'],
    'a winner over the daily limit' => [function (User $anna, User $bert, User $carl): HyperMatch {
        hyperWin([$anna, User::factory()->create()]);

        return hyperWin([$anna, $bert, $carl]);
    }, 'player-daily-limit'],
    'a win handed over by forfeit: every other seat left' => [function (User $anna, User $bert, User $carl): HyperMatch {
        $match = app(HyperMatches::class)->create([['user' => $anna], ['user' => $bert], ['user' => $carl]], seed: 7);
        app(HyperMatches::class)->leave($match, $bert);
        app(HyperMatches::class)->leave($match->refresh(), $carl);

        return HyperOn::finishTable($match->refresh(), [0, 1, 2]);
    }, 'forfeit'],
]);

test('a forfeit at the table leaves the match without a block, in a 1v1 as in a free-for-all of four with one loser gone, and the match stays rated', function () {
    hyperMiningSeason();
    [$anna, $bert, $carl, $dora, $emil, $fritz] = User::factory()->count(6)->create();

    $duel = HyperOn::versus($anna, $bert);
    app(HyperMatches::class)->leave($duel, $bert);
    $duel = HyperOn::finishTable($duel->refresh(), [0, 1]);

    expect([hyperAttestation($duel)?->height, hyperAttestation($duel)?->reason])->toBe([null, 'forfeit']);

    // Dora and Emil play to the end; Fritz alone leaves.
    $table = app(HyperMatches::class)->create([['user' => $carl], ['user' => $dora], ['user' => $emil], ['user' => $fritz]], seed: 7);
    app(HyperMatches::class)->leave($table, $fritz);
    $table = HyperOn::finishTable($table->refresh(), [0, 1, 2, 3]);

    expect([hyperAttestation($table)?->height, hyperAttestation($table)?->reason, hyperAttestation($table)?->reward])->toBe([null, 'forfeit', 0])
        ->and(hyperAttestation($table)?->candidate['winners'])->toBe([$carl->pubkey])
        ->and(hyperAttestation($table)?->candidate['losers'])->toBe([$dora->pubkey, $emil->pubkey, $fritz->pubkey])
        ->and($table->rated)->toBeTrue()
        ->and($table->seats->sortBy('seat')->pluck('place')->all())->toBe([1, 2, 3, 4])
        ->and(SeasonAttestation::query()->whereNotNull('height')->count())->toBe(0);

    // The bot that plays a forfeited seat on wins: the best seat that stayed holds place 1, but won nothing.
    $table->load('seats.user');

    expect(HyperMining::winner($table)?->user?->id)->toBe($carl->id)
        ->and(HyperMining::winner($table->forceFill(['winner_seat' => 3])))->toBeNull();
});

test('a duel in which both only end their turns until the round limit mines nothing for its winner; a duel won by a conquest mines', function () {
    hyperMiningSeason();
    [$anna, $bert, $carl, $dora] = User::factory()->count(4)->create();
    $players = [$anna, $bert];

    $passed = app(HyperMatches::class)->create([['user' => $anna], ['user' => $bert]], limit: 12, seed: 7);

    for ($turn = 0; $turn < 24 && $passed->status->value !== 'finished'; $turn++) {
        app(HyperMatches::class)->act($passed, $players[$passed->current_seat], ['type' => 'end_turn']);
        $passed->refresh();
    }

    $winner = HyperMining::winner($passed->load('seats.user'))?->user;

    expect($passed->status->value)->toBe('finished')
        ->and($winner)->not->toBeNull()
        ->and($passed->seats->contains(fn ($seat): bool => $seat->takeover !== null))->toBeFalse()
        ->and([hyperAttestation($passed)?->height, hyperAttestation($passed)?->reason, hyperAttestation($passed)?->reward])->toBe([null, 'no-conquest', 0])
        ->and(hyperAttestation($passed)?->candidate['table']['conquests'])->toBe(0)
        ->and(collect(hyperResultTags($passed))->where(0, 'conquests')->values()->all())->toBe([['conquests', $winner->pubkey, '0']]);

    $conquered = hyperWin([$carl, $dora]);

    expect([hyperAttestation($conquered)?->height, hyperAttestation($conquered)?->reason])->toBe([1, null])
        ->and(hyperAttestation($conquered)?->candidate['table']['conquests'])->toBe(1)
        ->and(collect(hyperResultTags($conquered))->where(0, 'conquests')->values()->all())->toBe([['conquests', $carl->pubkey, '1']]);
});

test('a match that cannot mine is left to the result as before: a bot seat, a team match, a tournament table, a match begun before Block 0 or ended after the season', function () {
    [$anna, $bert, $carl, $dora] = User::factory()->count(4)->create();

    $before = HyperOn::versus($anna, $bert);
    $season = hyperMiningSeason();
    $before = HyperOn::finishTable($before, [0, 1]);
    $bots = HyperOn::finishTable(HyperOn::versus($anna, $bert, bots: 1), [0, 1, 2]);
    $teams = HyperOn::finishTable(HyperOn::teams(Clan::factory()->create(), Clan::factory()->create(), $anna, $bert, $carl, $dora), [0, 1, 2, 3]);
    // A free-for-all table of a tournament, every seat a player, in the season that mines.
    $cup = HyperOn::tournament(4, TournamentFormat::FreeForAll, ['heatSize' => 4, 'heatAdvance' => 1]);
    $table = HyperMatch::query()->whereIn('tournament_match_id', $cup->matches()->pluck('id'))->sole();
    $pinned = $table->gate_at_start;
    $table = HyperOn::finishTable($table, [0, 1, 2, 3]);
    $late = HyperOn::ending(HyperOn::versus($carl, $dora), winner: 0, runnerUp: 1);

    // The season ended while the last match was still played (its turn clock set on, so the turn itself is in time).
    $this->travelTo($season->ends_at->copy()->addMinute());
    $late->forceFill(['deadline_ms' => (int) now()->addHour()->getTimestampMs()])->save();
    $late = HyperOn::finish($late, $carl);

    expect([$before->rated, $bots->rated, $teams->gate_at_start, $late->rated])->toBe([false, false, null, true])
        ->and($late->gate_at_start)->not->toBeNull()
        ->and([$table->rated, $table->season, $table->seats->whereNotNull('user_id')->count(), $pinned])->toBe([true, $season->slug, 4, null])
        ->and(array_map(HyperMining::eligible(...), [$before, $bots, $teams, $table, $late]))->toBe([false, false, false, false, false])
        // Asked directly, without the guard around the call at the end of a match: no row and no error.
        ->and(array_map(fn (HyperMatch $match): ?SeasonAttestation => app(SeasonChains::class)->attestHyperMatch($match->id), [$before, $bots, $teams, $table, $late]))->toBe([null, null, null, null, null])
        ->and(SeasonAttestation::query()->count())->toBe(0)
        ->and(NostrEvent::query()->where('kind', SeasonChains::ATTESTATION)->get()->flatMap(fn (NostrEvent $event): array => array_column($event->payload()['tags'], 0))->all())->not->toContain('block');
});

test('two accounts that alternate wins mine once a day and three times a season, and a third account at the table does not reset their pairing', function () {
    $season = hyperMiningSeason();
    [$anna, $bert, $carl] = User::factory()->count(3)->create();
    $verdict = fn (HyperMatch $match): array => [hyperAttestation($match)?->height, hyperAttestation($match)?->reason];

    // Day 1: one block for the pair, whoever wins; padding the table with Carl changes nothing for Anna and Bert.
    expect($verdict(hyperWin([$anna, $bert])))->toBe([1, null])
        ->and($verdict(hyperWin([$bert, $anna])))->toBe([null, 'pairing-daily-limit'])
        ->and($verdict(hyperWin([$anna, $bert, $carl])))->toBe([null, 'pairing-daily-limit'])
        ->and($verdict(hyperWin([$bert, $carl, $anna])))->toBe([null, 'pairing-daily-limit']);

    $this->travel(1)->days();
    expect($verdict(hyperWin([$bert, $anna])))->toBe([2, null]);

    // Day 3: a table of three counts for the pair too, as its third block of the season.
    $this->travel(1)->days();
    expect($verdict(hyperWin([$anna, $carl, $bert])))->toBe([3, null]);

    $this->travel(1)->days();
    expect($verdict(hyperWin([$anna, $bert])))->toBe([null, 'pairing-season-limit'])
        ->and($verdict(hyperWin([$bert, $anna, $carl])))->toBe([null, 'pairing-season-limit'])
        ->and(app(SeasonChains::class)->readout($season)['stored']['times'])->toHaveCount(3);
});

test('a match is attested once: ending it again, attesting it again or signing its result again adds no event and no block', function () {
    $season = hyperMiningSeason();
    [$anna, $bert] = User::factory()->count(2)->create();

    $match = hyperWin([$anna, $bert]);
    $first = hyperAttestation($match);

    $again = app(SeasonChains::class)->attestHyperMatch($match->id);
    app(HyperPublisher::class)->result($match->id);
    app(HyperMatches::class)->checkClock($match->refresh());

    expect($again?->id)->toBe($first->id)
        ->and(SeasonAttestation::query()->count())->toBe(1)
        ->and(NostrEvent::query()->where('kind', SeasonChains::ATTESTATION)->count())->toBe(1)
        ->and($match->refresh()->result_event_id)->toBe($first->event_id)
        ->and(app(SeasonChains::class)->readout($season)['mined'])->toBe($first->reward);

    // A result the league signed already (before the game had a weight) is never attested afterwards.
    $signed = HyperOn::versus($anna, $bert);
    $signed->forceFill(['status' => 'finished', 'result_event_id' => str_repeat('e', 64)])->save();

    expect(app(SeasonChains::class)->attestHyperMatch($signed->id))->toBeNull()
        ->and(SeasonAttestation::query()->count())->toBe(1);
});

test('a failing chain attestation never undoes the match: the result is signed as before, without a block', function () {
    hyperMiningSeason();
    [$anna, $bert] = User::factory()->count(2)->create();
    $match = HyperOn::versus($anna, $bert);

    // The attestation fails at its last step, after the event was signed inside its transaction.
    SeasonAttestation::creating(fn () => throw new RuntimeException('The chain is down.'));
    $match = HyperOn::finishTable($match, [0, 1]);
    SeasonAttestation::flushEventListeners();

    expect($match->status->value)->toBe('finished')
        ->and($match->result_event_id)->not->toBeNull()
        ->and(array_column(hyperResultTags($match), 0))->not->toContain('block')
        ->and(SeasonAttestation::query()->count())->toBe(0)
        ->and(NostrEvent::query()->where('kind', SeasonChains::ATTESTATION)->count())->toBe(1);
});
