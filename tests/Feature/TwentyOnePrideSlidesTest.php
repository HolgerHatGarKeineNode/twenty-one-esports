<?php

use App\Enums\BoardEndReason;
use App\Enums\ChessEndReason;
use App\Enums\PayoutStatus;
use App\Enums\SeriesResolution;
use App\Enums\SeriesStatus;
use App\Games\GameRegistry;
use App\Models\Admin;
use App\Models\BoardGame;
use App\Models\ChessGame;
use App\Models\Clan;
use App\Models\InviteLink;
use App\Models\InviteLinkUse;
use App\Models\Lineup;
use App\Models\Rating;
use App\Models\RatingChange;
use App\Models\SeasonAttestation;
use App\Models\SeasonBlockVoid;
use App\Models\SeasonPayout;
use App\Models\SeriesMatch;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Badges\RankBadges;
use App\Support\FairPlay\AccountLinks;
use App\Support\Rating\RatingService;
use App\Support\StreamBot\PrideNotes;
use App\Support\TwentyOne\Stream\PrideSlides;
use App\Support\TwentyOne\Stream\RotationKit;
use App\Support\TwentyOne\Stream\RotationPlanner;
use App\Support\TwentyOne\Stream\SceneRenderer;
use App\Support\TwentyOne\Stream\SceneSource;
use App\Support\TwentyOne\Stream\StreamStats;
use Illuminate\Support\Facades\Queue;
use Tests\Support\CheckersGame;
use Tests\Support\TestSigner;

/**
 * A casual chess rating with one change per delta, the last one for `$gameId` (if given).
 *
 * @param  list<int>  $deltas
 */
function prideRating(User $user, array $deltas, ?int $gameId = null, string $pool = Rating::CASUAL, string $game = 'chess'): void
{
    $row = Rating::query()->create([
        'pool' => $pool, 'season' => $pool === Rating::RATED ? 'season-1' : '', 'game' => $game, 'mode' => 'blitz',
        'subject' => 'user:'.$user->id, 'user_id' => $user->id, 'rating' => 1000 + array_sum($deltas), 'results' => count($deltas),
        'wins' => 0, 'draws' => 0, 'losses' => 0,
    ]);

    foreach ($deltas as $index => $delta) {
        RatingChange::query()->create([
            'rating_id' => $row->id, 'source' => RatingChange::CHESS, 'source_id' => $index === count($deltas) - 1 && $gameId !== null ? $gameId : 7000 + $row->id * 10 + $index,
            'score' => $delta > 0 ? 1 : 0, 'before' => 1000, 'after' => 1000 + $delta, 'delta' => $delta, 'results_before' => $index,
        ]);
    }
}

test('the pride slides name the latest winner, the week\'s climbers, new sign-ups and the biggest pot\'s prizes', function () {
    config(['esports.league.nsec' => (new TestSigner)->secret]);
    $ben = User::factory()->create(['name' => 'Ben']);
    $zoe = User::factory()->create(['name' => 'Zoe <b>']);
    $kai = User::factory()->create(['name' => 'Kai']);
    ChessGame::factory()->finished('1-0')->create(['white_id' => $kai, 'black_id' => $ben, 'ended_at' => now()->subDays(2)]);
    $latest = ChessGame::factory()->finished('0-1')->create(['white_id' => $zoe, 'black_id' => $ben, 'ended_at' => now()->subHour()]);
    prideRating($ben, [10, 16], $latest->id);
    prideRating($zoe, [-16]);
    prideRating($kai, [30, 25]);
    // Every ladder counts: a rated climb, and a climb in another game.
    prideRating($zoe, [99], pool: Rating::RATED);
    CheckersGame::play();
    prideRating($kai, [5], game: 'checkers');

    $cup = openTournament(['name' => 'Cup <script>']);
    [$player, $signer] = keyedPlayer();
    soloSignup($cup, $player, $signer);
    $pot = openTournament(['name' => 'Sats Cup']);
    $pot->forceFill(['pot_source' => Tournament::POT_WALLET, 'prize_target_sats' => 21000, 'prize_split' => [50, 30, 20]])->save();

    $pride = app(PrideSlides::class)->all();

    expect($pride['win'])->toMatchArray(['winner' => 'Ben', 'loser' => 'Zoe <b>', 'mode' => 'Blitz chess', 'delta' => 16])
        ->and(array_column($pride['climbers'], 'gain'))->toBe([83, 60, 26])
        ->and(array_column($pride['climbers'], 'name'))->toBe(['Zoe <b>', 'Kai', 'Ben'])
        ->and($pride['climbers'][1]['from'])->toBe(['Chess', 'Checkers'])
        ->and($pride['signups'])->toHaveCount(1)
        ->and($pride['signups'][0])->toMatchArray(['tournament' => 'Cup <script>', 'pot' => null])
        ->and($pride['prizes'])->toMatchArray(['name' => 'Sats Cup', 'pot' => 21000])
        ->and(array_column($pride['prizes']['places'], 'sats'))->toBe([10395, 6237, 4158]);

    // The views render it escaped, and the empty states when there is nothing.
    $renderer = SceneRenderer::fromConfig();
    $source = app(SceneSource::class);
    $stats = app(StreamStats::class)->all();
    $svgs = [];
    foreach (RotationPlanner::PRIDE_SCENES as $scene) {
        $svgs[$scene] = $renderer->svg([...$source->rotation($scene, null, [], 0, 0, $stats), 'viewers' => null], RotationPlanner::VIEWS[$scene]);
    }

    expect($svgs['e1'])->toContain('>Ben<', 'beat Zoe &lt;b&gt;', '+16 casual Elo')
        ->and($svgs['e2'])->toContain('+83 Elo', '+60 Elo', '+26 Elo', '3 results in Chess and Checkers')
        ->and($svgs['e3'])->toContain('Cup &lt;script&gt;')
        ->and($svgs['e4'])->toContain('10,395 sats', '6,237 sats', '4,158 sats')
        ->and(implode('', $svgs))->not->toContain('<script>', '<b>');
});

test('without games, climbs, sign-ups or pots every pride slide says so instead of showing nobody', function () {
    $pride = app(PrideSlides::class)->all();
    $renderer = SceneRenderer::fromConfig();
    $svg = fn (string $scene): string => $renderer->svg(['pride' => $pride, 'stats' => [], 'backdrop' => null, 'viewers' => null], RotationPlanner::VIEWS[$scene]);

    expect($pride)->toBe(['win' => null, 'climbers' => [], 'signups' => [], 'prizes' => null, 'block' => null, 'strongest' => null, 'rankUps' => [], 'streaks' => [], 'payouts' => null, 'inviters' => []])
        ->and($svg('e1'))->toContain('No winner yet.')
        ->and($svg('e2'))->toContain('Nobody has climbed this week yet.')
        ->and($svg('e3'))->toContain('No sign-ups yet.')
        ->and($svg('e4'))->toContain('No pot open right now.')
        ->and($svg('e5'))->toContain('No block mined yet.')
        ->and($svg('e6'))->toContain('Opens with the season.')
        ->and($svg('e7'))->toContain('No rank-up this week.')
        ->and($svg('e8'))->toContain('No streak running.')
        ->and($svg('e9'))->toContain('No season paid out yet.');
});

test('the latest win can be a series, with its score and the block it mined, and a voided series never is', function () {
    $season = openSeason();
    ChessGame::factory()->finished()->create(['ended_at' => now()->subHours(2)]);
    $won = SeriesMatch::factory()->create(['status' => SeriesStatus::Confirmed, 'winner' => 'challenged', 'finished_at' => now()->subHour(),
        'result_games' => [['winner' => 'challenged'], ['winner' => 'challenger'], ['winner' => 'challenged'], ['winner' => 'challenged']]]);
    $won->challengedLineup->clan->forceFill(['name' => 'Orange <Pill>'])->save();
    $won->forceFill(['challenged_name' => 'Orange <Pill>'])->save();
    $block = shareBlock($season, 7, User::factory()->create(), User::factory()->create());
    $block->forceFill(['source' => SeasonAttestation::SERIES, 'source_id' => $won->id])->save();
    // Two accounts of one person: voided by the league, later than the real win.
    SeriesMatch::factory()->create(['status' => SeriesStatus::Resolved, 'resolution' => SeriesResolution::Void, 'winner' => 'challenger', 'finished_at' => now()->subMinutes(5)]);

    $pride = app(PrideSlides::class)->all();
    $e1 = SceneRenderer::fromConfig()->svg(['pride' => $pride, 'stats' => [], 'backdrop' => null, 'viewers' => null], RotationPlanner::VIEWS['e1']);

    expect($pride['win'])->toMatchArray(['kind' => 'series', 'gameId' => $won->id, 'winner' => 'Orange <Pill>', 'score' => '3-1', 'block' => 7, 'mode' => 'Rocket League 3v3'])
        // A team's win tags no single player in the pride note.
        ->and($pride['win'])->not->toHaveKey('winnerRef')
        ->and($e1)->toContain('Orange &lt;Pill&gt;', ' 3-1<', 'Mined block 7')
        ->and($e1)->not->toContain('<Pill>');
});

test('the season chain, the strongest, rank-ups, streaks, payouts and inviters name their players, and a voided block never shows', function () {
    $season = openSeason();
    $miner = User::factory()->create(['name' => 'Mia <i>']);
    $hal = User::factory()->create(['name' => 'Hal']);
    $ben = User::factory()->create(['name' => 'Ben']);
    // Mia: a rank-up (Silver III to Gold II), blocks 1 and 2, the strongest; block 3 is Hal's but voided.
    shareMoments($miner, $season);
    $voided = shareBlock($season, 3, $hal, $ben);
    SeasonBlockVoid::query()->create(['season_id' => $season->id, 'height' => 3, 'season_attestation_id' => $voided->id, 'reason' => 'Linked accounts', 'voided_by_pubkey' => $hal->pubkey]);
    // Hal: three chess wins in a row, the latest game; Ben: a win, then a draw ends it.
    foreach (range(1, 3) as $i) {
        ChessGame::factory()->finished('1-0')->create(['white_id' => $hal->id, 'black_id' => $miner->id, 'ended_at' => now()->subMinutes(30 - $i)]);
    }
    ChessGame::factory()->finished('1-0')->create(['white_id' => $ben->id, 'black_id' => $miner->id, 'ended_at' => now()->subMinutes(20)]);
    ChessGame::factory()->finished('1/2-1/2')->create(['white_id' => $ben->id, 'black_id' => $miner->id, 'ended_at' => now()->subMinutes(10)]);
    foreach ([[$hal, 21000, PayoutStatus::Paid], [$ben, 9000, PayoutStatus::Open]] as $i => [$user, $sats, $status]) {
        SeasonPayout::query()->forceCreate(['season_id' => $season->id, 'user_id' => $user->id, 'pubkey' => $user->pubkey, 'name' => $user->displayName(), 'blocks' => 3,
            'heights' => [1, 2, 3], 'amount_sats' => $sats, 'idempotency_key' => 'pride-'.$i, 'status' => $status, 'paid_at' => $status === PayoutStatus::Paid ? now() : null]);
    }
    // Two new players through Mia's link, three who already played through Ben's.
    foreach ([[$miner, 2, true], [$ben, 3, false]] as [$inviter, $n, $new]) {
        $link = InviteLink::factory()->multiUse()->create(['inviter_id' => $inviter->id]);
        foreach (range(1, $n) as $j) {
            InviteLinkUse::query()->create(['invite_link_id' => $link->id, 'inviter_id' => $inviter->id, 'user_id' => User::factory()->create()->id, 'was_new' => $new]);
        }
    }

    $pride = app(PrideSlides::class)->all();
    $renderer = SceneRenderer::fromConfig();
    $source = app(SceneSource::class);
    $svg = fn (string $scene): string => $renderer->svg([...$source->rotation($scene, null, [], 0, 0, []), 'viewers' => null], RotationPlanner::VIEWS[$scene]);

    expect($pride['block'])->toMatchArray(['height' => 2, 'reward' => 5000, 'seasonBlocks' => 2, 'minerBlocks' => 2])
        ->and(array_column($pride['block']['miners'], 'name'))->toBe(['Mia <i>'])
        ->and($pride['strongest']['rows'][0])->toMatchArray(['place' => 1, 'name' => 'Mia <i>'])
        ->and($pride['rankUps'][0])->toMatchArray(['name' => 'Mia <i>', 'tier' => 'Gold II', 'previous' => 'Silver III', 'ladder' => 'Chess blitz'])
        ->and(array_column($pride['streaks'], 'name'))->toBe(['Hal'])
        ->and($pride['streaks'][0])->toMatchArray(['wins' => 3, 'games' => ['Chess']])
        ->and($pride['payouts'])->toMatchArray(['total' => 21000, 'players' => 1])
        ->and(array_column($pride['inviters'], 'brought'))->toBe([2])
        ->and($svg('e5'))->toContain('>2<', 'Mia &lt;i&gt;', '+5,000 sats', '2nd block this season')
        ->and($svg('e6'))->toContain('Mia &lt;i&gt;', 'Global Rating')
        ->and($svg('e7'))->toContain('Gold II', 'up from Silver III')
        ->and($svg('e8'))->toContain('>3<', '>Hal<', 'in Chess')
        ->and($svg('e9'))->toContain('21,000', '>Hal<')
        ->and($svg('d3'))->toContain('Mia &lt;i&gt;', 'brought 2 new players in 30 days')
        ->and($svg('e5').$svg('e6').$svg('e7').$svg('d3'))->not->toContain('<i>');
});

test('a climb out of games a fair play link voided is no climb', function () {
    Queue::fake();
    $main = User::factory()->create(['name' => 'Main']);
    $alt = User::factory()->create(['name' => 'Alt']);
    foreach (range(1, 3) as $i) {
        app(RatingService::class)->applyChessGame(ChessGame::factory()->finished('1-0')->create(['white_id' => $main->id, 'black_id' => $alt->id, 'ended_at' => now()->subMinutes(30 - $i)]));
    }
    $before = array_column(app(PrideSlides::class)->read()['climbers'], 'name');
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    app(AccountLinks::class)->link($admin, $main->pubkey, $alt->pubkey, 'Same person');

    expect($before)->toBe(['Main'])
        ->and(app(PrideSlides::class)->read()['climbers'])->toBe([]);
});

test('with the board games switched off no climb, rank-up or block of theirs reaches a slide or a note', function () {
    $season = openSeason();
    CheckersGame::play();
    // A board game's rated ladder opens only with its published version (its rank badge needs it).
    publishLadders($season);
    config(['esports.badges.nsec' => (new TestSigner)->secret]);
    $user = User::factory()->create(['name' => 'Checkerist']);
    prideRating($user, [40], game: 'checkers');
    $rating = Rating::query()->create(['pool' => Rating::RATED, 'season' => $season->slug, 'game' => 'checkers', 'mode' => 'blitz', 'subject' => 'user:'.$user->id, 'user_id' => $user->id, 'rating' => 1010, 'results' => 5, 'wins' => 4]);
    app(RankBadges::class)->sync($user, 'checkers', 'blitz');
    $rating->forceFill(['rating' => 1061, 'results' => 9, 'wins' => 7])->save();
    app(RankBadges::class)->sync($user, 'checkers', 'blitz');
    shareBlock($season, 1, $user, User::factory()->create())->forceFill(['source' => SeasonAttestation::BOARD, 'game' => 'checkers', 'mode' => 'blitz'])->save();
    $on = app(PrideSlides::class)->read();
    config(['esports.board_games.enabled' => false]);
    app()->forgetInstance(GameRegistry::class);
    app()->forgetInstance(PrideSlides::class);
    $off = app(PrideSlides::class)->read();

    expect([count($on['climbers']), count($on['rankUps']), $on['block']['height'] ?? null])->toBe([1, 1, 1])
        ->and([$off['climbers'], $off['rankUps'], $off['block']])->toBe([[], [], null])
        ->and(app(PrideNotes::class)->compose(2, 0))->toBeNull();
});

test('a series won by a no-show is no pride moment, on the slide or in the note', function () {
    $lineup = fn (User $user) => Lineup::factory()->game('rocket-league', '1v1')->create(['clan_id' => Clan::factory()->create(['owner_id' => $user->id])->id]);
    [$winner, $loser] = [User::factory()->create(['name' => 'Winner']), User::factory()->create(['name' => 'NoShow'])];
    SeriesMatch::factory()->create(['challenger_lineup_id' => $lineup($winner)->id, 'challenged_lineup_id' => $lineup($loser)->id,
        'status' => SeriesStatus::Resolved, 'resolution' => SeriesResolution::Forfeit, 'winner' => 'challenger', 'finished_at' => now()->subMinutes(5), 'result_games' => [],
        'resolved_roster' => [['user_id' => $winner->id, 'pubkey' => $winner->pubkey, 'name' => 'Winner', 'side' => 'challenger', 'role' => 'player']]]);

    expect(app(PrideSlides::class)->read()['win'])->toBeNull()
        ->and(app(PrideNotes::class)->compose(1, 0))->toBeNull()
        // Still a game played wherever the league counts.
        ->and(app(StreamStats::class)->count()['gamesPlayed'])->toBe(1);
});

test('a chess or board game won by forfeit is no latest win and no streak link', function () {
    CheckersGame::play();
    $player = User::factory()->create(['name' => 'Forfeiter']);
    $real = ChessGame::factory()->finished('1-0')->create(['white_id' => User::factory()->create(['name' => 'Real'])->id, 'black_id' => User::factory()->create()->id, 'ended_at' => now()->subDays(2)]);
    foreach ([1, 2, 3] as $hours) {
        ChessGame::factory()->finished('1-0', ChessEndReason::Forfeit)->create(['white_id' => $player->id, 'black_id' => User::factory()->create()->id, 'ended_at' => now()->subHours($hours)]);
    }
    BoardGame::query()->create(['game' => 'checkers', 'mode' => 'blitz', 'white_id' => $player->id, 'black_id' => User::factory()->create()->id, 'status' => 'finished', 'result' => '1-0', 'end_reason' => BoardEndReason::Forfeit->value,
        'position' => '-', 'turn' => 'w', 'ply' => 0, 'initial_ms' => 300000, 'increment_ms' => 3000, 'white_ms' => 1, 'black_ms' => 1, 'turn_started_ms' => 0, 'ended_at' => now()->subMinutes(5)]);

    $pride = app(PrideSlides::class)->read();

    expect($pride['win'])->toMatchArray(['kind' => 'chess', 'gameId' => $real->id, 'winner' => 'Real'])
        ->and($pride['streaks'])->toBe([]);
});

test('a rank-up a correction took back leaves the slide', function () {
    $season = openSeason();
    $user = User::factory()->create(['name' => 'Dropped']);
    shareMoments($user, $season);
    $before = app(PrideSlides::class)->read()['rankUps'];
    Rating::query()->where(['pool' => Rating::RATED, 'user_id' => $user->id, 'game' => 'chess', 'mode' => 'blitz'])->update(['rating' => 1010]);
    app(RankBadges::class)->sync($user, 'chess', 'blitz');

    expect(array_column($before, 'tier'))->toBe(['Gold II'])
        ->and(app(PrideSlides::class)->read()['rankUps'])->toBe([]);
});

test('an ended season\'s block, the list\'s own places and a climb over many games are told as they are', function () {
    $renderer = SceneRenderer::fromConfig();
    $svg = fn (string $scene, array $pride): string => $renderer->svg(['pride' => $pride, 'stats' => [], 'backdrop' => null, 'viewers' => null], RotationPlanner::VIEWS[$scene]);
    $miner = ['name' => 'Mia', 'avatar' => null];

    $e5 = $svg('e5', ['block' => ['height' => 9, 'season' => 'season-1', 'reward' => 5000, 'ladder' => 'Chess blitz', 'miners' => [$miner], 'beat' => ['Ben'], 'seasonBlocks' => 9, 'minerBlocks' => 2, 'ago' => '1 day ago', 'live' => false]]);
    // Place 2's name has no letter the fonts draw: its row drops, place 3 stays 3.
    $e6 = $svg('e6', ['strongest' => ['season' => 'season-1', 'ranked' => 3, 'rows' => [['place' => 1, 'name' => 'Mia', 'rating' => 1200, 'games' => ['Chess']], ['place' => 2, 'name' => 'مرحبا', 'rating' => 1100, 'games' => []], ['place' => 3, 'name' => 'Zoe', 'rating' => 1000, 'games' => []]]]]);
    $e9 = $svg('e9', ['payouts' => ['season' => 'season-1', 'total' => 30000, 'players' => 7, 'rows' => [['rank' => 1, 'name' => 'مرحبا', 'sats' => 20000, 'blocks' => 4], ['rank' => 2, 'name' => 'Zoe', 'sats' => 10000, 'blocks' => 2]]]]);
    $e2 = $svg('e2', ['climbers' => [['name' => 'Kai', 'gain' => 60, 'games' => 9, 'from' => ['Chess', 'Checkers', 'Rocket League']]]]);

    expect($e5)->toContain('The last block of season-1')->not->toContain('New block', 'mine the next one')
        ->and($e6)->toContain('data-unit="place-name-3"')->not->toContain('data-unit="place-name-2"')
        ->and($e9)->toContain('to 7 players', '>2</text>')->not->toContain('>1</text>')
        ->and($e2)->toContain('9 results in 3 games')->not->toContain('Chess and');
});

test('a styled name keeps its letters on the stream instead of losing them', function () {
    expect(RotationKit::clean('𝕞ptf'))->toBe('mptf')
        ->and(RotationKit::clean('Ｂｅｎ 🚀'))->toBe('Ben')
        ->and(RotationKit::clean('Müller'))->toBe('Müller');
});
