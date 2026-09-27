<?php

use App\Enums\ChessEndReason;
use App\Enums\ChessGameStatus;
use App\Models\ChessGame;
use App\Models\Clan;
use App\Models\Rating;
use App\Models\User;
use App\Support\TwentyOne\Stream\StreamStats;
use Illuminate\Support\Carbon;

/**
 * A casual chess rating row as the rating service writes it.
 */
function casualRating(?User $user, string $mode, int $rating, int $results, array $wdl = [0, 0, 0], string $pool = Rating::CASUAL): Rating
{
    $user ??= User::factory()->create();

    return Rating::query()->create(['pool' => $pool, 'season' => $pool === Rating::CASUAL ? '' : 'season-1', 'game' => 'chess', 'mode' => $mode,
        'subject' => 'user:'.$user->id, 'user_id' => $user->id, 'rating' => $rating, 'results' => $results,
        'wins' => $wdl[0], 'draws' => $wdl[1], 'losses' => $wdl[2]]);
}

test('the counts match the footer, and today starts at midnight in Berlin', function () {
    // 10:00 in Berlin, 08:00 UTC: the Berlin day began at 22:00 UTC yesterday.
    $this->travelTo(Carbon::parse('2026-09-27 08:00:00', 'UTC'));
    ChessGame::factory()->finished()->create(['ended_at' => Carbon::parse('2026-09-26 22:30:00', 'UTC')]);
    ChessGame::factory()->finished()->create(['ended_at' => Carbon::parse('2026-09-26 21:30:00', 'UTC')]);
    ChessGame::factory()->finished('0-1', ChessEndReason::Aborted)->create(['status' => ChessGameStatus::Aborted, 'ended_at' => now()]);
    ChessGame::factory()->create();
    ChessGame::factory()->daily()->create();
    Clan::factory()->create();

    $stats = app(StreamStats::class)->count();

    expect($stats)->toMatchArray([
        'players' => User::query()->count(),
        'clans' => 1,
        'gamesPlayed' => 2,
        'liveNow' => 2,
        'gamesToday' => 1,
    ]);
});

test('the ladders are the casual chess ladders in the ladder page order', function () {
    $first = casualRating(User::factory()->create(['name' => 'Alice']), 'blitz', 1040, 3, [3, 0, 0]);
    // Same rating: more results ranks first (though created last), then the older row.
    $third = casualRating(null, 'blitz', 1020, 2, [1, 0, 1]);
    $fourth = casualRating(null, 'blitz', 1020, 2);
    $second = casualRating(null, 'blitz', 1020, 5, [3, 1, 1]);
    casualRating(null, 'blitz', 1010, 1);
    casualRating(null, 'blitz', 1500, 0);
    casualRating(null, 'blitz', 1600, 9, pool: Rating::RATED);
    $daily = casualRating(User::factory()->create(['name' => 'Daisy']), 'correspondence', 990, 1, [0, 0, 1]);

    $ladders = app(StreamStats::class)->count()['ladders'];

    expect(array_column($ladders['blitz'], 'elo'))->toBe([1040, 1020, 1020, 1020])
        ->and(array_column($ladders['blitz'], 'games'))->toBe([3, 5, 2, 2])
        ->and($ladders['blitz'][0])->toBe(['rank' => 1, 'name' => 'Alice', 'elo' => 1040, 'games' => 3, 'wins' => 3, 'draws' => 0, 'losses' => 0])
        ->and($ladders['blitz'][1]['name'])->toBe($second->user->displayName())
        ->and([$ladders['blitz'][2]['name'], $ladders['blitz'][3]['name']])->toBe([$third->user->displayName(), $fourth->user->displayName()])
        ->and($ladders['daily'])->toBe([['rank' => 1, 'name' => 'Daisy', 'elo' => 990, 'games' => 1, 'wins' => 0, 'draws' => 0, 'losses' => 1]])
        ->and($first->id)->toBeInt()
        ->and($daily->id)->toBeInt();
});

test('the clan spotlight counts members and their games, and takes turns', function () {
    $this->travelTo(Carbon::parse('2026-09-27 08:00:00', 'UTC'));
    $older = Clan::factory()->create(['name' => 'Satoshis Hodlers', 'picture' => 'https://example.com/logo.png', 'created_at' => Carbon::parse('2026-09-20 12:00:00')]);
    $newer = Clan::factory()->create(['picture' => null]);
    ChessGame::factory()->finished()->create(['white_id' => $older->owner_id]);
    ChessGame::factory()->finished()->create(['black_id' => $older->owner_id]);
    ChessGame::factory()->create(['white_id' => $older->owner_id]);
    ChessGame::factory()->finished()->create();

    $turn = intdiv(now()->getTimestamp(), 600) % 2;
    $spotlight = app(StreamStats::class)->count()['clan'];
    $this->travel(600)->seconds();
    $next = app(StreamStats::class)->count()['clan'];

    [$shown, $other] = $turn === 0 ? [$spotlight, $next] : [$next, $spotlight];

    expect($shown)->toBe(['name' => 'Satoshis Hodlers', 'tag' => $older->clantag, 'members' => 1, 'games' => 2, 'founded' => 'Sep 20, 2026', 'logoUrl' => 'https://example.com/logo.png'])
        ->and($other['name'])->toBe($newer->name)
        ->and($other['logoUrl'])->toBeNull();
});

test('without clans there is no spotlight, and the numbers are cached for a while', function () {
    $stats = app(StreamStats::class);

    expect($stats->all()['clan'])->toBeNull()
        ->and($stats->all()['players'])->toBe(0);

    User::factory()->create();

    expect($stats->all()['players'])->toBe(0);

    $this->travel(16)->seconds();

    expect($stats->all()['players'])->toBe(1);
});
