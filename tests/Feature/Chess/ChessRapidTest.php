<?php

/*
 * Chess rapid 10+5 (plan "Schach Rapid und Clan", P1): its own mode and its
 * own Elo ladder. In a season live since before rapid joined, its ladder
 * opens only once the league signs its first 32152 (NIP rev. 9.22, the rule
 * of rev. 9.13/9.14): until then rated rapid is refused, a rated result rates
 * and attests nothing, and blitz and daily stay open.
 */

use App\Models\ChessGame;
use App\Models\NostrEvent;
use App\Models\Rating;
use App\Models\Season;
use App\Models\SeasonAttestation;
use App\Models\User;
use App\Support\Chess\ChessGameService;
use App\Support\Chess\ChessPgn;
use App\Support\Chess\ChessQueue;
use App\Support\Chess\ChessRuleViolation;
use App\Support\Chess\RatedChess;
use App\Support\Nostr\NostrKeys;
use App\Support\Nostr\SignedEvent;
use App\Support\Rating\RatingService;
use App\Support\SeasonChain\SeasonChains;
use App\Support\SeasonChain\TrustFacts;
use App\Support\Series\Ladders;
use App\Support\Tournaments\GameProfile;
use Carbon\CarbonImmutable;
use Tests\Support\TestSigner;
use Tests\Support\TrustedFacts;

/**
 * A live season whose Block 0 was signed before chess rapid joined: every
 * ladder of the registry is published but rapid's.
 */
function seasonBeforeRapid(): Season
{
    $season = openSeason(['slug' => 'season-1']);
    NostrEvent::query()->where(['kind' => Ladders::KIND, 'd' => 'chess/rapid/'.$season->slug])->delete();

    return $season;
}

/**
 * A board member on the public admin list, who may change the chain rules,
 * and the trust key a new ladder version names (without it a change signs no
 * ladder, SeasonChains::changeParameters()).
 */
function rapidBoardMember(): User
{
    $admin = User::factory()->create();
    config(['esports.board' => [NostrKeys::hexToNpub($admin->pubkey)], 'esports.trust.nsec' => (new TestSigner)->secret]);

    return $admin;
}

beforeEach(function () {
    app()->bind(TrustFacts::class, TrustedFacts::class);
    config(['esports.chess.rated_queue' => true]);
});

test('a rapid game starts with ten minutes and five seconds a move on the clock', function () {
    [$white, $black] = [User::factory()->create(), User::factory()->create()];

    $game = app(ChessGameService::class)->start($white, $black, 'rapid');

    expect($game->mode)->toBe('rapid')
        ->and([$game->initial_ms, $game->increment_ms, $game->white_ms, $game->black_ms])->toBe([600_000, 5_000, 600_000, 600_000])
        ->and(ChessPgn::headersFor($game)['TimeControl'])->toBe('600+5')
        ->and(app(ChessGameService::class)->start(User::factory()->create(), User::factory()->create(), 'blitz')->only(['initial_ms', 'increment_ms']))
        ->toBe(['initial_ms' => 300_000, 'increment_ms' => 3_000]);
});

test('a tournament can plan rapid: its game profile and its first-move window', function () {
    $profile = GameProfile::for('chess', 'rapid');

    expect([$profile->key, $profile->unit, $profile->gameLength, $profile->break, $profile->overhead, $profile->bestOfOptions, $profile->what])
        ->toBe(['rapid', 'min', 27.0, 3.0, 3.0, [1], 'game'])
        ->and(config('esports.tournaments.first_move_seconds.rapid'))->toBe(600)
        ->and(GameProfile::for('chess', 'blitz')->gameLength)->toBe(14.0);
});

test('in a season live since before rapid joined, the rapid ladder stays closed until the league signs its 32152, and blitz and daily stay open', function () {
    $season = seasonBeforeRapid();
    $blitz = Ladders::KIND.':'.$season->league_pubkey.':chess/blitz/season-1';

    expect(Ladders::address('chess', 'rapid'))->toBeNull()
        ->and(Ladders::isOpen('chess', 'rapid'))->toBeFalse()
        ->and(Ladders::address('chess', 'blitz'))->toBe($blitz)
        ->and(Ladders::address('chess', 'correspondence'))->not->toBeNull();

    // The board's first rule change gives rapid its weight and signs a new version of every ladder, rapid's first.
    $this->travel(1)->minutes();
    $before = CarbonImmutable::now()->subSecond();
    $this->travel(1)->minutes();
    $change = app(SeasonChains::class)->changeParameters(rapidBoardMember(), ['weights' => ['chess/rapid' => 1500]], 'Rapid joins the season.', CarbonImmutable::now());
    $first = NostrEvent::query()->where(['kind' => Ladders::KIND, 'pubkey' => $season->league_pubkey, 'd' => 'chess/rapid/season-1'])->sole();

    expect(SignedEvent::fromInput($change->nostrEvent->payload())->tagsNamed('weight'))->toBe([['chess/rapid', '1.5']])
        ->and(SignedEvent::fromInput($first->payload())->tag('time_control'))->toBe('600+5')
        ->and(Ladders::address('chess', 'rapid'))->toBe(Ladders::KIND.':'.$season->league_pubkey.':chess/rapid/season-1')
        // A tournament that re-derives its ladder from a moment before the change gets none.
        ->and(Ladders::address('chess', 'rapid', $before))->toBeNull()
        ->and(Ladders::address('chess', 'blitz'))->toBe($blitz)
        ->and(Ladders::address('chess', 'blitz', $before))->toBe($blitz);
});

test('while the rapid ladder is closed, rated rapid is refused and a rated rapid result rates and attests nothing; casual rapid still rates', function () {
    seasonBeforeRapid();
    [$a, $b] = [User::factory()->create(), User::factory()->create()];

    expect(app(RatedChess::class)->refusal($a, 'rapid'))->toBe('Rated Chess Rapid 10+5 starts when the board adds it to the running season.')
        ->and(app(RatedChess::class)->refusal($a, 'blitz'))->toBeNull()
        ->and(fn () => app(ChessQueue::class)->join($a, 'rapid', rated: true))->toThrow(ChessRuleViolation::class);

    // A rated game that slipped through anyway (no ladder pinned): no rated row, no attestation.
    $rated = ChessGame::factory()->state(['mode' => 'rapid'])->rated()->finished('1-0')->create(['white_id' => $a->id, 'black_id' => $b->id]);

    expect($rated->ladder_address)->toBeNull()
        ->and(app(RatingService::class)->applyChessGame($rated))->toBeFalse()
        ->and(app(SeasonChains::class)->attestChessGame($rated))->toBeNull()
        ->and(Rating::query()->count())->toBe(0)
        ->and(SeasonAttestation::query()->count())->toBe(0);

    $casual = ChessGame::factory()->finished('1-0')->create(['white_id' => $a->id, 'black_id' => $b->id, 'mode' => 'rapid']);

    expect(app(RatingService::class)->applyChessGame($casual))->toBeTrue()
        ->and(Rating::query()->get(['pool', 'game', 'mode'])->map->only(['pool', 'game', 'mode'])->unique()->values()->all())
        ->toBe([['pool' => Rating::CASUAL, 'game' => 'chess', 'mode' => 'rapid']]);
});

test('with its ladder open, rated rapid is paired, rated and attested on the rapid ladder only', function () {
    $season = openSeason(['slug' => 'season-1']);
    [$a, $b] = [User::factory()->create(), User::factory()->create()];
    $queue = app(ChessQueue::class);

    expect($queue->join($a, 'rapid', rated: true))->toBeNull();
    $game = $queue->join($b, 'rapid', rated: true);
    $rapid = Ladders::KIND.':'.$season->league_pubkey.':chess/rapid/season-1';

    expect($game)->toBeInstanceOf(ChessGame::class)
        ->and($game->only(['mode', 'rated', 'ladder_address', 'initial_ms', 'increment_ms']))
        ->toBe(['mode' => 'rapid', 'rated' => true, 'ladder_address' => $rapid, 'initial_ms' => 600_000, 'increment_ms' => 5_000]);

    $service = app(ChessGameService::class);
    $game = $service->move($game, $game->white, 'e2e4');
    $service->resign($game->refresh(), $game->black);

    $attestation = SeasonAttestation::query()->sole();

    expect($attestation->only(['game', 'mode', 'ladder_address']))->toBe(['game' => 'chess', 'mode' => 'rapid', 'ladder_address' => $rapid])
        ->and(Rating::query()->where('pool', Rating::RATED)->pluck('mode')->unique()->values()->all())->toBe(['rapid'])
        ->and(Rating::query()->where('pool', Rating::RATED)->pluck('season')->unique()->values()->all())->toBe(['season-1']);
});

test('the rapid ladder page answers before Block 0, while closed in a live season and once open', function () {
    $this->get(route('ladder.show', ['chess', 'rapid']))->assertOk()->assertSee('Rapid 10+5');

    seasonBeforeRapid();
    $this->get(route('ladder.show', ['chess', 'rapid']).'?pool=rated')->assertOk()->assertDontSee('Ladder record');

    publishLadders(Season::query()->sole());
    $this->get(route('ladder.show', ['chess', 'rapid']).'?pool=rated')->assertOk()->assertSee('Ladder record');
});
