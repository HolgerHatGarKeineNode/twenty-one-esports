<?php

/*
 * Chess rapid 10+5 (plan "Schach Rapid und Clan", P1): its own mode and its
 * own Elo ladder. In a season live since before rapid joined, its ladder
 * opens only once the league signs its first 32152 (NIP rev. 9.22, the rule
 * of rev. 9.13/9.14): until then rated rapid is refused, a rated result rates
 * and attests nothing, and blitz and daily stay open.
 */

use App\Enums\ChessGameStatus;
use App\Enums\InviteLinkType;
use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Models\ChessGame;
use App\Models\ChessInvite;
use App\Models\ChessQueueEntry;
use App\Models\InviteLink;
use App\Models\NostrEvent;
use App\Models\Rating;
use App\Models\Season;
use App\Models\SeasonAttestation;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\User;
use App\Support\Chess\ChessGameService;
use App\Support\Chess\ChessInvites;
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
use App\Support\Tournaments\CasualCups;
use App\Support\Tournaments\GameProfile;
use App\Support\Tournaments\TournamentGames;
use Carbon\CarbonImmutable;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
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

/*
 * P2: rapid in the queue (user, 2026-10-05). Two entries pair only in a mode
 * both take; "either" takes rapid and blitz and pairs with the first fit;
 * after switch_hint_seconds alone the card names the other mode's searchers.
 */

test('rapid and blitz searchers never pair with each other; the same mode pairs', function () {
    [$anna, $bert, $cora] = User::factory()->count(3)->create();
    $queue = app(ChessQueue::class);

    expect($queue->join($anna, 'rapid'))->toBeNull()
        ->and($queue->join($bert, 'blitz'))->toBeNull()
        ->and($queue->counts())->toBe(['rapid' => 1, 'blitz' => 1]);

    $game = $queue->join($cora, 'rapid');

    expect($game?->mode)->toBe('rapid')
        ->and([$game->white_id, $game->black_id])->toEqualCanonicalizing([$anna->id, $cora->id])
        ->and($queue->entryOf($bert)?->mode)->toBe('blitz')
        ->and(fn () => $queue->join($cora, 'correspondence'))->toThrow(ChessRuleViolation::class);
});

test('"either" pairs with the first fitting searcher of either mode, in that searcher\'s mode', function () {
    [$anna, $bert, $cora, $dora] = User::factory()->count(4)->create();
    $queue = app(ChessQueue::class);

    $queue->join($anna, 'blitz');
    $first = $queue->join($bert, 'rapid', either: true);

    expect($first?->mode)->toBe('blitz')
        ->and([$first->white_id, $first->black_id])->toEqualCanonicalizing([$anna->id, $bert->id]);

    $queue->join($cora, 'rapid', either: true);
    expect($queue->entryOf($cora)?->takes())->toBe(['rapid', 'blitz'])
        ->and($queue->counts())->toBe(['rapid' => 1, 'blitz' => 1]);

    $second = $queue->join($dora, 'rapid');
    expect($second?->mode)->toBe('rapid');
});

test('two "either" searchers play the first choice of the one who waited longer', function () {
    [$anna, $bert] = User::factory()->count(2)->create();
    $queue = app(ChessQueue::class);

    $queue->join($anna, 'blitz', either: true);
    $this->travel(5)->seconds();
    $game = $queue->join($bert, 'rapid', either: true);

    expect($game?->mode)->toBe('blitz');
});

test('a rated "either" search takes only the modes whose ladder is open for the player', function () {
    seasonBeforeRapid();
    $anna = User::factory()->create();
    $queue = app(ChessQueue::class);

    expect($queue->join($anna, 'rapid', rated: true, either: true))->toBeNull()
        ->and($queue->entryOf($anna)?->takes())->toBe(['blitz'])
        ->and($queue->entryOf($anna)?->rated)->toBeTrue();
});

test('after 30 s alone the searching card names the other mode\'s searchers, and switching keeps the place', function () {
    $this->freezeTime();
    [$anna, $bert] = User::factory()->count(2)->create();
    $queue = app(ChessQueue::class);

    $queue->join($anna, 'rapid');
    $entry = $queue->entryOf($anna);
    // Bert searches blitz, far away in rating: nobody fits anybody yet.
    ChessQueueEntry::query()->create(['user_id' => $bert->id, 'mode' => 'blitz', 'rated' => false, 'rating' => 2400, 'joined_at' => now()]);

    $this->travel(29)->seconds();
    expect($queue->switchHint($entry))->toBeNull()
        ->and($queue->switchHintAt($entry)->getTimestamp())->toBe($entry->joined_at->getTimestamp() + 30);

    $this->travel(1)->seconds();
    expect($queue->switchHint($entry))->toBe(['mode' => 'blitz', 'count' => 1]);

    // Nothing to name once Anna takes blitz too.
    expect($queue->switchTo($anna, 'rapid', either: true))->toBeNull()
        ->and($queue->entryOf($anna)?->takes())->toBe(['rapid', 'blitz'])
        ->and($queue->entryOf($anna)?->joined_at->getTimestamp())->toBe($entry->joined_at->getTimestamp())
        ->and($queue->switchHint($queue->entryOf($anna)))->toBeNull();

    // Switched to blitz with the range of 30 s waited: Bert fits once the range opens far enough.
    ChessQueueEntry::query()->where('user_id', $bert->id)->update(['rating' => 1250]);
    $game = $queue->switchTo($anna, 'blitz');

    expect($game?->mode)->toBe('blitz');
});

test('the lobby searches rapid by default, blitz from its tile, both with "either", and shows the switch hint', function () {
    $this->freezeTime();
    [$anna, $bert] = User::factory()->count(2)->create();

    $lobby = Livewire::actingAs($anna)->test('pages::chess.lobby')->call('findOpponent');
    expect(ChessQueueEntry::query()->where('user_id', $anna->id)->value('mode'))->toBe('rapid');
    $lobby->assertSeeHtml('data-test="play-rapid-state"')->assertDontSeeHtml('data-test="switch-hint"')->call('$refresh')->assertOk();

    ChessQueueEntry::query()->create(['user_id' => $bert->id, 'mode' => 'blitz', 'rated' => false, 'rating' => 2400, 'joined_at' => now()]);
    $this->travel(31)->seconds();

    $lobby->call('$refresh')->assertOk()->assertSeeHtml('data-test="switch-hint" data-mode="blitz"')
        ->call('switchSearch', 'blitz')->assertOk();
    expect(ChessQueueEntry::query()->where('user_id', $anna->id)->value('mode'))->toBe('blitz');

    $lobby->call('cancelSearch')->call('findOpponent', false, 'blitz', true)->assertOk()->assertSeeHtml('data-test="play-blitz-state"')->assertSeeHtml('data-test="play-rapid-state"');
    expect(ChessQueueEntry::query()->where('user_id', $anna->id)->sole()->takes())->toBe(['blitz', 'rapid']);
});

test('a friend invite goes out in rapid: "Looking to play" takes it, a rapid searcher starts at once, the lobby says the mode', function () {
    [$anna, $bert, $cora] = User::factory()->lookingToPlay()->count(3)->create();
    $invites = app(ChessInvites::class);

    // Bert's switch is the lobby's live-chess switch (stored as chess/blitz): a rapid invite reaches him.
    $invite = $invites->invite($anna, $bert, 'rapid');
    expect($invite->mode)->toBe('rapid')
        ->and(ChessInvites::looksFor('chess/blitz', 'rapid'))->toBeTrue()
        ->and(ChessInvites::looksFor('chess/blitz', 'correspondence'))->toBeFalse()
        ->and(ChessInvites::looksFor('nine-mens-morris/blitz', 'rapid'))->toBeFalse();

    Livewire::actingAs($bert)->test('pages::chess.lobby')->assertOk()
        ->assertSeeHtml('data-test="incoming-invite-mode">'.e(__(':mode · Casual · colours drawn at random', ['mode' => 'Rapid 10+5'])).'<')
        ->call('acceptInvite', $invite->id);
    expect(ChessGame::query()->sole()->mode)->toBe('rapid');

    // A player searching "either" is paired the moment a rapid invite arrives.
    app(ChessQueue::class)->join($cora, 'blitz', either: true);
    $other = User::factory()->lookingToPlay()->create();
    $started = $invites->invite($other, $cora, 'rapid');
    expect($started->refresh()->chess_game_id)->not->toBeNull()
        ->and(ChessGame::query()->findOrFail($started->chess_game_id)->mode)->toBe('rapid');

    // The lobby's online list invites in the open mode.
    $dora = User::factory()->lookingToPlay()->create();
    Livewire::actingAs(User::factory()->create())->test('pages::chess.lobby')->call('invite', $dora->id, 'rapid')->assertOk();
    expect(ChessInvite::query()->where('invitee_id', $dora->id)->sole()->mode)->toBe('rapid');
});

test('/invite offers rapid for chess and makes a rapid link', function () {
    $anna = User::factory()->create();

    $html = $this->actingAs($anna)->get(route('invites.create', ['game' => 'chess']))->assertOk()->getContent();
    expect($html)->toContain('data-test="invite-mode-rapid"')
        ->and(strpos($html, 'data-test="invite-mode-rapid"'))->toBeLessThan(strpos($html, 'data-test="invite-mode-blitz"'));

    Livewire::actingAs($anna)->test('pages::invites.create', ['game' => 'chess'])->set('mode', 'rapid')->call('createLink')->assertOk();
    expect(InviteLink::query()->sole()->type)->toBe(InviteLinkType::Rapid);
});

test('the next weekly chess cup is rapid; a blitz cup already open stays blitz', function () {
    config(['esports.league.nsec' => (new TestSigner)->secret]);
    $blitz = runningCup(4);

    expect(CasualCups::setup('chess')['mode'])->toBe('rapid')
        // The EU series has its blitz cup open: nothing new, nothing rewritten.
        ->and(app(CasualCups::class)->ensure('chess', 'eu'))->toBeNull()
        ->and($blitz->refresh()->mode)->toBe('blitz');

    $rapid = app(CasualCups::class)->ensure('chess', 'us');

    expect($rapid)->toBeInstanceOf(Tournament::class)
        ->and($rapid->mode)->toBe('rapid')
        ->and($rapid->profile()->key)->toBe('rapid')
        ->and(Tournament::query()->where('mode', 'blitz')->pluck('id')->all())->toBe([$blitz->id]);
});

test('a director can pick rapid for a tournament, and its games get the rapid clock and first-move window', function () {
    expect(collect(TournamentGames::grouped())->firstWhere('slug', 'chess')['options'])->toContain(['rapid', 'Rapid 10+5'])
        ->and(TournamentGames::find('rapid'))->toBe(['chess', 'rapid']);

    $tournament = runningChess(TournamentFormat::SingleElimination, 2, TournamentResultsMode::Players, chessMode: 'rapid');
    $match = TournamentMatch::query()->where('tournament_id', $tournament->id)->sole();
    $game = ChessGame::query()->where('tournament_match_id', $match->id)->where('status', ChessGameStatus::Active)->sole();

    expect($game->mode)->toBe('rapid')
        ->and([$game->initial_ms, $game->increment_ms])->toBe([600_000, 5_000])
        ->and($game->deadline_ms - $game->turn_started_ms)->toBe(600_000);
});

test('in a request a closed ladder is asked once, and a ladder published later in the same request is seen; outside a request it is asked again', function () {
    $season = seasonBeforeRapid();
    $count = function (Closure $ask): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $ask();
        DB::disableQueryLog();

        return count(array_filter(DB::getQueryLog(), fn (array $query): bool => str_contains($query['query'], 'nostr_events')));
    };

    // A worker or the stream daemon (no route): every ask of the closed ladder reads again, so another process's publish is seen.
    expect(Ladders::isOpen('chess', 'rapid'))->toBeFalse()
        ->and($count(fn () => Ladders::isOpen('chess', 'rapid')))->toBe(1);
    $template = NostrEvent::query()->where(['kind' => Ladders::KIND, 'd' => 'chess/blitz/'.$season->slug])->firstOrFail();
    DB::table('nostr_events')->insert([...collect($template->getAttributes())->except('id')->all(), 'event_id' => str_repeat('e', 64), 'd' => 'chess/rapid/'.$season->slug]);
    expect(Ladders::isOpen('chess', 'rapid'))->toBeTrue();

    // An HTTP request: the full read is complete, a miss costs nothing more until a ladder version is stored.
    DB::table('nostr_events')->where('event_id', str_repeat('e', 64))->delete();
    Ladders::forget();
    request()->setRouteResolver(fn () => new Route('GET', 'chess', []));
    expect(Ladders::isOpen('chess', 'rapid'))->toBeFalse()
        ->and($count(fn () => [Ladders::isOpen('chess', 'rapid'), Ladders::isOpen('chess', 'rapid'), Ladders::isOpen('chess', 'blitz')]))->toBe(0);

    $this->travel(2)->minutes();
    app(SeasonChains::class)->changeParameters(rapidBoardMember(), ['weights' => ['chess/rapid' => 1500]], 'Rapid joins the season.', CarbonImmutable::now());
    expect(Ladders::isOpen('chess', 'rapid'))->toBeTrue();
});
