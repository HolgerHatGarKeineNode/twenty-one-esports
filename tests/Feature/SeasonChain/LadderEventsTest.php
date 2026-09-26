<?php

/*
 * The ladders (32152) of a chain season (P7d; NIP "Ladder", "League-wide
 * seasons"): opened at Block 0 with the season parameters and the trust
 * gate, republished after each parameter change with the standings, the
 * frozen parameters copied from the first version.
 */

use App\Models\Lineup;
use App\Models\NostrEvent;
use App\Models\Rating;
use App\Models\SeasonAttestation;
use App\Models\User;
use App\Support\Nostr\NostrKeys;
use App\Support\SeasonChain\LadderEvents;
use App\Support\SeasonChain\LeagueKey;
use App\Support\SeasonChain\SeasonChains;
use App\Support\Series\Ladders;
use Carbon\CarbonImmutable;
use Tests\Support\TestSigner;

/** @return list<list<string>> the newest version's tags */
function ladderTags(string $d): array
{
    return NostrEvent::query()->where(['kind' => Ladders::KIND, 'd' => $d])->orderByDesc('signed_at')->orderByDesc('id')->firstOrFail()->payload()['tags'];
}

beforeEach(function () {
    $this->season = openSeason();
    $this->trust = new TestSigner;
    config(['esports.trust.nsec' => $this->trust->secret]);
});

test('the first version of a chess ladder describes itself: registry values, rating, 21 tiers, provisional, trust, hashrate, the genesis', function () {
    app(LadderEvents::class)->publish($this->season, LeagueKey::required(), $this->trust->pubkey);

    $tags = ladderTags('chess/blitz/pre-season');
    $ladder = NostrEvent::query()->where(['kind' => Ladders::KIND, 'd' => 'chess/blitz/pre-season'])->sole();

    expect($ladder->pubkey)->toBe(LeagueKey::required()->pubkey())
        ->and(array_slice($tags, 0, 9))->toBe([
            ['d', 'chess/blitz/pre-season'],
            ['game', 'chess'],
            ['mode', 'blitz'],
            ['season', 'pre-season'],
            ['starts', (string) $this->season->genesis_at->getTimestamp()],
            ['rates', 'player'],
            ['time_control', '300+3'],
            ['variant', 'standard'],
            ['rating', 'elo', '1000', '32', '400'],
        ])
        ->and(collect($tags)->where(0, 'tier')->count())->toBe(21)
        ->and($tags)->toContain(['tier', 'bronze-1', '0'], ['tier', 'grand-champion-3', '1425'], ['provisional', '5', '40'], ['trust', $this->trust->pubkey, '50'], ['hashrate', '3', '2', '1', '5'], ['e', $this->season->genesisId(), ''])
        ->and(collect($tags)->where(0, 'standing')->all())->toBe([])
        ->and(ladderTags('rocket-league/2v2/pre-season'))->toContain(['rates', 'lineup'])
        ->and(collect(ladderTags('rocket-league/2v2/pre-season'))->where(0, 'time_control')->all())->toBe([]);
});

test('a parameter change republishes every ladder with the standings and the frozen parameters of the first version', function () {
    app(LadderEvents::class)->publish($this->season, LeagueKey::required(), $this->trust->pubkey);
    $first = NostrEvent::query()->where(['kind' => Ladders::KIND, 'd' => 'chess/blitz/pre-season'])->sole();

    [$alice, $bob] = [User::factory()->create(), User::factory()->create()];
    foreach ([[$alice, 1020, 1, 0], [$bob, 980, 0, 1]] as [$user, $rating, $wins, $losses]) {
        Rating::query()->create(['pool' => Rating::RATED, 'season' => 'pre-season', 'game' => 'chess', 'mode' => 'blitz', 'subject' => 'user:'.$user->id,
            'user_id' => $user->id, 'rating' => $rating, 'results' => 1, 'wins' => $wins, 'losses' => $losses]);
    }
    $address = Ladders::address('chess', 'blitz');
    SeasonAttestation::query()->create([
        'season_id' => $this->season->id, 'source' => 'chess', 'source_id' => 1, 'label' => '#1', 'game' => 'chess', 'mode' => 'blitz',
        'ladder_address' => $address, 'attested_at' => now(), 'event_id' => str_repeat('d', 64),
    ]);

    // A config edit during the season must not reach the ladder: its parameters are frozen.
    config(['season.rating.k' => 20]);
    $admin = User::factory()->create();
    config(['esports.board' => [NostrKeys::hexToNpub($admin->pubkey)]]);
    $this->travel(1)->minutes();
    app(SeasonChains::class)->changeParameters($admin, ['daily' => ['chess' => 4]], 'Long blitz evenings.', CarbonImmutable::now());

    $tags = ladderTags('chess/blitz/pre-season');

    expect(NostrEvent::query()->where(['kind' => Ladders::KIND, 'd' => 'chess/blitz/pre-season'])->count())->toBe(2)
        ->and(NostrEvent::query()->where('kind', Ladders::KIND)->count())->toBe(10)
        ->and(NostrEvent::query()->where(['kind' => Ladders::KIND, 'd' => 'chess/blitz/pre-season'])->max('signed_at'))->toBeGreaterThan($first->signed_at)
        ->and($tags)->toContain(['rating', 'elo', '1000', '32', '400'], ['e', str_repeat('d', 64), ''], ['p', $alice->pubkey], ['p', $bob->pubkey])
        ->and(collect($tags)->where(0, 'standing')->values()->all())->toBe([
            ['standing', '1', $alice->pubkey, '1020', '1', '0', 'provisional'],
            ['standing', '2', $bob->pubkey, '980', '0', '1', 'provisional'],
        ]);
});

test('NIP 7.1: the Rocket League 1v1 ladder rates players and lists pubkeys, never a lineup or a malformed a', function () {
    $player = User::factory()->create();
    $lineup = Lineup::factory()->mode('1v1')->create();
    $base = ['pool' => Rating::RATED, 'season' => $this->season->slug, 'game' => 'rocket-league', 'mode' => '1v1', 'results' => 3, 'wins' => 2, 'losses' => 1];
    Rating::query()->create([...$base, 'subject' => 'user:'.$player->id, 'user_id' => $player->id, 'rating' => 1040]);
    // A row of the old lineup kind (rev. 6) must not leak into a player ladder.
    Rating::query()->create([...$base, 'subject' => 'lineup:'.$lineup->id, 'lineup_id' => $lineup->id, 'rating' => 1020]);

    app(LadderEvents::class)->publish($this->season, LeagueKey::required(), $this->trust->pubkey);
    $tags = ladderTags('rocket-league/1v1/'.$this->season->slug);

    expect($tags)->toContain(['rates', 'player'], ['p', $player->pubkey])
        ->and(collect($tags)->where(0, 'standing')->pluck(2)->all())->toBe([$player->pubkey])
        ->and(collect($tags)->where(0, 'a')->filter(fn ($tag) => ! str_starts_with($tag[1], '32152:') && ! str_starts_with($tag[1], '2156:'))->all())->toBe([])
        ->and(collect($tags)->where(0, 'p')->pluck(1)->all())->not->toContain($lineup->address())
        ->and(ladderTags('rocket-league/2v2/'.$this->season->slug))->toContain(['rates', 'lineup']);
});
