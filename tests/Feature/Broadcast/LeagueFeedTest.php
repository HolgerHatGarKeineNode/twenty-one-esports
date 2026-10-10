<?php

use App\Enums\ChessEndReason;
use App\Enums\HyperMatchStatus;
use App\Enums\PayoutStatus;
use App\Enums\PongMatchStatus;
use App\Enums\SeriesResolution;
use App\Enums\SeriesStatus;
use App\Enums\TournamentFormat;
use App\Enums\TournamentStatus;
use App\Events\LeagueFeedEvent;
use App\Models\BoardGame;
use App\Models\ChessGame;
use App\Models\HyperMatch;
use App\Models\HyperSeat;
use App\Models\PongMatch;
use App\Models\RankBadge;
use App\Models\RankBadgeVersion;
use App\Models\ScoreRun;
use App\Models\Season;
use App\Models\SeasonPayout;
use App\Models\SeriesMatch;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\TournamentPayout;
use App\Models\TournamentSignup;
use App\Models\User;
use App\Support\Broadcast\LeagueFeed;
use App\Support\Chess\ChessGameService;
use App\Support\Tournaments\TournamentChampion;
use App\Support\TwentyOne\Stream\PublicName;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\CheckersGame;

/*
|--------------------------------------------------------------------------
| The league's live feed for the OBS overlays (plan "OBS-Broadcast-Overlays", P2)
|--------------------------------------------------------------------------
|
| App\Support\Broadcast\LeagueFeed sends App\Events\LeagueFeedEvent on the public `league.feed` channel from the
| places where results are stored: every source once with its payload, never a forfeit, a voided result or a
| rolled-back one, never a key, an email or a Lightning address; throttled; a failing source never breaks the save.
| Only LeagueFeedEvent is faked, so the model events that drive the feed keep running.
|
*/

beforeEach(fn () => Event::fake([LeagueFeedEvent::class]));

/**
 * The items of every LeagueFeedEvent sent so far.
 *
 * @return list<array<string, mixed>>
 */
function feedItems(): array
{
    return Event::dispatched(LeagueFeedEvent::class)->flatMap(fn (array $call): array => $call[0]->items)->values()->all();
}

/** @return array<string, mixed> the one item of `$kind` */
function feedItem(string $kind): array
{
    $items = array_values(array_filter(feedItems(), fn (array $item): bool => $item['kind'] === $kind));
    expect($items)->toHaveCount(1);

    return $items[0];
}

function feedName(User $user): string
{
    return PublicName::clean($user->displayName());
}

test('the channel is public: league.feed, sent as league.feed with the items only', function () {
    $event = new LeagueFeedEvent([['kind' => 'win', 'game' => 'chess']]);
    $channels = $event->broadcastOn();

    expect($channels)->toHaveCount(1)
        ->and($channels[0])->toBeInstanceOf(Channel::class)
        ->and($channels[0])->not->toBeInstanceOf(PrivateChannel::class)
        ->and($channels[0])->not->toBeInstanceOf(PresenceChannel::class)
        ->and($channels[0]->name)->toBe('league.feed')
        ->and($event->broadcastAs())->toBe('league.feed')
        ->and($event->broadcastWith())->toBe(['items' => [['kind' => 'win', 'game' => 'chess']]]);
});

test('a chess game won through the game service is a win with both public names, and nothing else is sent', function () {
    $game = ChessGame::factory()->create(['ply' => 4]);

    app(ChessGameService::class)->resign($game, $game->white);

    $item = feedItem('win');
    expect($item)->toMatchArray(['kind' => 'win', 'game' => 'chess', 'mode' => 'blitz', 'winners' => [feedName($game->black)], 'losers' => [feedName($game->white)], 'rated' => false])
        ->and($item['at'])->toBeInt()
        ->and(feedItems())->toHaveCount(1);
});

test('a forfeit, an aborted game and a draw send nothing', function () {
    ChessGame::factory()->create()->forceFill(['status' => 'finished', 'result' => '1-0', 'end_reason' => ChessEndReason::Forfeit, 'ended_at' => now()])->save();
    ChessGame::factory()->create()->forceFill(['status' => 'aborted', 'ended_at' => now()])->save();
    ChessGame::factory()->create()->forceFill(['status' => 'finished', 'result' => '1/2-1/2', 'end_reason' => ChessEndReason::Agreement, 'ended_at' => now()])->save();

    expect(feedItems())->toBe([]);
});

test('a decided board game is a win in its game', function () {
    CheckersGame::play();
    $white = User::factory()->create();
    $black = User::factory()->create();
    $game = BoardGame::query()->create(['game' => 'checkers', 'mode' => 'blitz', 'white_id' => $white->id, 'black_id' => $black->id, 'status' => 'active',
        'position' => '-', 'turn' => 'w', 'ply' => 9, 'initial_ms' => 300000, 'increment_ms' => 3000, 'white_ms' => 1, 'black_ms' => 1, 'turn_started_ms' => 0]);

    $game->forceFill(['status' => 'finished', 'result' => '0-1', 'end_reason' => 'resignation', 'ended_at' => now()])->save();

    expect(feedItem('win'))->toMatchArray(['game' => 'checkers', 'winners' => [feedName($black)], 'losers' => [feedName($white)]]);
});

test('a confirmed series is a win of the side with its score; a void or forfeit one is not', function () {
    $match = SeriesMatch::factory()->accepted()->create();
    $match->forceFill(['status' => SeriesStatus::Confirmed, 'winner' => 'challenged', 'result_games' => [['winner' => 'challenged'], ['winner' => 'challenger'], ['winner' => 'challenged']], 'finished_at' => now()])->save();

    $item = feedItem('win');
    expect($item)->toMatchArray(['game' => $match->game, 'winners' => [PublicName::clean($match->challenged_name)], 'losers' => [PublicName::clean($match->challenger_name)]])
        ->and($item['score'])->toBe('2-1');

    SeriesMatch::factory()->accepted()->create()->forceFill(['status' => SeriesStatus::Resolved, 'resolution' => SeriesResolution::Void, 'winner' => 'challenger', 'finished_at' => now()])->save();
    SeriesMatch::factory()->accepted()->create()->forceFill(['status' => SeriesStatus::Resolved, 'resolution' => SeriesResolution::Forfeit, 'winner' => 'challenger', 'finished_at' => now()])->save();

    expect(feedItems())->toHaveCount(1);
});

test('a finished Proof of Pong match is a win with the score from the winner\'s side', function () {
    $match = PongMatch::factory()->active()->create();
    $match->forceFill(['status' => PongMatchStatus::Finished, 'winner_id' => $match->right_id, 'score_left' => 15, 'score_right' => 21, 'ended_at' => now()])->save();

    expect(feedItem('win'))->toMatchArray(['game' => 'proof-of-pong', 'winners' => [feedName($match->right)], 'losers' => [feedName($match->left)], 'score' => '21-15']);
});

test('a finished Hyperbitcoinization match names place 1, never a bot', function () {
    $winner = User::factory()->create();
    $loser = User::factory()->create();
    $match = HyperMatch::factory()->create();
    HyperSeat::query()->where('hyper_match_id', $match->id)->where('seat', 0)->update(['user_id' => $winner->id, 'bot' => false, 'place' => 1]);
    HyperSeat::query()->where('hyper_match_id', $match->id)->where('seat', 1)->update(['user_id' => $loser->id, 'bot' => false, 'place' => 2]);

    $match->forceFill(['status' => HyperMatchStatus::Finished, 'winner_seat' => 0, 'ended_at' => now()])->save();

    expect(feedItem('win'))->toMatchArray(['game' => 'hyperbitcoinization', 'winners' => [feedName($winner)], 'losers' => [feedName($loser)]]);
});

test('a checked highscore is a score, a director\'s correction and an unchecked run are not', function () {
    $user = User::factory()->create();
    $base = ['user_id' => $user->id, 'game' => 'score-demo', 'mode' => 'time-trial', 'course' => 'demo', 'value' => 61234, 'unit' => 'ms', 'achieved_at' => now()];

    $pending = ScoreRun::query()->create($base + ['source' => ScoreRun::MANUAL]);
    ScoreRun::query()->create($base + ['source' => ScoreRun::DIRECTOR, 'verified_at' => now()]);
    expect(feedItems())->toBe([]);

    $pending->forceFill(['verified_at' => now()])->save();

    expect(feedItem('score'))->toMatchArray(['game' => 'score-demo', 'mode' => 'time-trial', 'winners' => [feedName($user)], 'score' => $pending->formatted()]);
});

test('a rank-up is sent with tier and rating; a step down or a provisional tier is not', function () {
    $user = User::factory()->create();
    $badge = RankBadge::query()->create(['user_id' => $user->id, 'pubkey' => $user->pubkey, 'game' => 'chess', 'mode' => 'blitz',
        'd' => 'rank/chess-blitz/'.$user->pubkey, 'badge_pubkey' => str_repeat('b', 64), 'tier' => 'gold-2', 'season' => '']);
    RankBadgeVersion::query()->create(['rank_badge_id' => $badge->id, 'tier' => 'silver-1', 'previous_tier' => 'gold-2', 'season' => '', 'rating' => 1400, 'signed_at' => time()]);
    expect(feedItems())->toBe([]);

    RankBadgeVersion::query()->create(['rank_badge_id' => $badge->id, 'tier' => 'gold-2', 'previous_tier' => 'silver-1', 'season' => '', 'rating' => 1612, 'signed_at' => time()]);

    expect(feedItem('rank-up'))->toMatchArray(['game' => 'chess', 'mode' => 'blitz', 'winners' => [feedName($user)], 'rating' => 1612]);
});

test('a sign-up to a public tournament is sent with the entries; a draft\'s is not', function () {
    $user = User::factory()->create();
    $tournament = Tournament::factory()->signup()->create(['published_at' => now()]);
    $draft = Tournament::factory()->create();

    TournamentSignup::query()->create(['tournament_id' => $draft->id, 'user_id' => $user->id, 'name' => 'x', 'members' => [$user->id]]);
    expect(feedItems())->toBe([]);

    TournamentSignup::query()->create(['tournament_id' => $tournament->id, 'user_id' => $user->id, 'name' => 'x', 'members' => [$user->id]]);

    expect(feedItem('signup'))->toMatchArray(['winners' => [feedName($user)], 'entries' => 1, 'tournament' => PublicName::clean($tournament->name), 'tournamentId' => $tournament->id, 'game' => 'chess']);
});

test('a tournament played out by its director sends every closed round and the champion', function () {
    $tournament = runningChess(TournamentFormat::SingleElimination, 4);
    $tournament->forceFill(['published_at' => now()])->save();

    playOutAsDirector($tournament);

    $rounds = array_values(array_filter(feedItems(), fn (array $item): bool => $item['kind'] === 'round'));
    $winner = TournamentParticipant::query()->where('tournament_id', $tournament->id)->where('name', 'Player 1')->firstOrFail()->user;

    expect($tournament->refresh()->status)->toBe(TournamentStatus::Finished)
        ->and(array_column($rounds, 'round'))->toBe([1, 2])
        ->and(feedItem('champion'))->toMatchArray(['winners' => [feedName($winner)], 'tournamentId' => $tournament->id]);
});

test('a paid tournament prize and a paid season payout are sent with their sats, never the Lightning address', function () {
    $user = User::factory()->create();
    $tournament = Tournament::factory()->create(['status' => TournamentStatus::Finished, 'published_at' => now()]);
    $payout = TournamentPayout::query()->create(['tournament_id' => $tournament->id, 'user_id' => $user->id, 'pubkey' => $user->pubkey, 'name' => 'Winner',
        'place' => 1, 'amount_sats' => 21000, 'idempotency_key' => 'k1', 'lud16' => 'secret@wallet.example', 'status' => PayoutStatus::Paying]);

    $payout->forceFill(['status' => PayoutStatus::Paid, 'paid_at' => now()])->save();

    expect(feedItem('payout'))->toMatchArray(['winners' => [feedName($user)], 'place' => 1, 'sats' => 21000, 'tournamentId' => $tournament->id]);

    $season = Season::factory()->create();
    $seasonPayout = SeasonPayout::query()->create(['season_id' => $season->id, 'user_id' => $user->id, 'pubkey' => $user->pubkey, 'name' => 'Miner', 'blocks' => 3,
        'heights' => [1, 2, 3], 'amount_sats' => 3000, 'idempotency_key' => 'k2', 'lud16' => 'secret@wallet.example', 'status' => PayoutStatus::Paying]);
    $seasonPayout->forceFill(['status' => PayoutStatus::Paid, 'paid_at' => now()])->save();

    $json = json_encode(feedItems());
    expect(collect(feedItems())->where('kind', 'payout')->last())->toMatchArray(['sats' => 3000, 'blocks' => 3, 'season' => $season->slug])
        ->and($json)->not->toContain('wallet.example')
        ->and($json)->not->toContain($user->pubkey)
        ->and($json)->not->toContain((string) $user->npub);
});

test('a result rolled back is never announced; one committed is announced after the commit', function () {
    $game = ChessGame::factory()->create();

    try {
        DB::transaction(function () use ($game): void {
            $game->forceFill(['status' => 'finished', 'result' => '1-0', 'end_reason' => ChessEndReason::Resignation, 'ended_at' => now()])->save();
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }

    expect(feedItems())->toBe([]);

    DB::transaction(function () use ($game): void {
        $game->refresh()->forceFill(['status' => 'finished', 'result' => '1-0', 'end_reason' => ChessEndReason::Resignation, 'ended_at' => now()])->save();
        expect(feedItems())->toBe([]);
    });

    expect(feedItems())->toHaveCount(1);
});

test('the feed is throttled to FEED_PER_MINUTE pushes; the rest is left to the snapshot poll', function () {
    foreach (range(1, LeagueFeed::FEED_PER_MINUTE + 5) as $index) {
        LeagueFeed::push(['kind' => 'win', 'game' => 'chess', 'winners' => ["P{$index}"]]);
    }

    expect(feedItems())->toHaveCount(LeagueFeed::FEED_PER_MINUTE);
});

test('a source that throws is reported and the result still saves', function () {
    Exceptions::fake();
    $tournament = Tournament::factory()->create(['status' => TournamentStatus::Running, 'published_at' => now()]);
    app()->bind(TournamentChampion::class, fn () => throw new RuntimeException('champion read failed'));

    $tournament->forceFill(['status' => TournamentStatus::Finished])->save();

    expect($tournament->refresh()->status)->toBe(TournamentStatus::Finished)
        ->and(feedItems())->toBe([]);
    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'champion read failed');
});

test('a push that fails (the throttle\'s cache, the socket) is reported and the result still saves', function () {
    Exceptions::fake();
    RateLimiter::shouldReceive('attempt')->andThrow(new RuntimeException('cache down'));
    $game = ChessGame::factory()->create();

    $game->forceFill(['status' => 'finished', 'result' => '1-0', 'end_reason' => ChessEndReason::Resignation, 'ended_at' => now()])->save();

    expect($game->refresh()->status->value)->toBe('finished')
        ->and(feedItems())->toBe([]);
    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'cache down');
});
