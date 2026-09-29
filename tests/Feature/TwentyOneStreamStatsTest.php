<?php

use App\Enums\ChessEndReason;
use App\Enums\ChessGameStatus;
use App\Enums\SeriesResolution;
use App\Enums\SeriesStatus;
use App\Models\BoardGame;
use App\Models\ChessGame;
use App\Models\Clan;
use App\Models\ClanMember;
use App\Models\Lineup;
use App\Models\Rating;
use App\Models\SeriesMatch;
use App\Models\User;
use App\Support\TwentyOne\Stream\RotationPlanner;
use App\Support\TwentyOne\Stream\SceneRenderer;
use App\Support\TwentyOne\Stream\SceneSource;
use App\Support\TwentyOne\Stream\StreamStats;
use Illuminate\Support\Carbon;
use Tests\Support\CheckersGame;

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
        ->and($ladders['blitz'][0])->toBe(['rank' => 1, 'name' => 'Alice', 'elo' => 1040, 'games' => 3, 'wins' => 3, 'draws' => 0, 'losses' => 0,
            'avatarRef' => ['id' => $first->user_id, 'pubkey' => $first->user->pubkey, 'source' => null]])
        ->and($ladders['blitz'][1]['name'])->toBe($second->user->displayName())
        ->and([$ladders['blitz'][2]['name'], $ladders['blitz'][3]['name']])->toBe([$third->user->displayName(), $fourth->user->displayName()])
        ->and($ladders['daily'])->toBe([['rank' => 1, 'name' => 'Daisy', 'elo' => 990, 'games' => 1, 'wins' => 0, 'draws' => 0, 'losses' => 1,
            'avatarRef' => ['id' => $daily->user_id, 'pubkey' => $daily->user->pubkey, 'source' => null]]])
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

    expect(array_diff_key($shown, ['pride' => true, 'faceRefs' => true]))->toBe(['name' => 'Satoshis Hodlers', 'tag' => $older->clantag, 'members' => 1, 'games' => 2, 'founded' => 'Sep 20, 2026', 'logoUrl' => 'https://example.com/logo.png', 'logoRef' => null])
        // The owner's face, the one member.
        ->and($shown['faceRefs'])->toHaveCount(1)
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

test('changed numbers reach the teaser data once the cache period passed, not before', function () {
    // One spotlight turn for the whole test: the oldest clan stays on show.
    config(['twentyone.stream.stats.clan_spotlight_seconds' => 10_000_000_000]);
    $clan = Clan::factory()->create(['name' => 'Oldest']);
    $alice = casualRating(User::factory()->create(['name' => 'Alice']), 'blitz', 1100, 3);
    $bob = casualRating(User::factory()->create(['name' => 'Bob']), 'blitz', 1000, 2);
    ChessGame::factory()->finished()->create(['ended_at' => now()]);
    ChessGame::factory()->create();
    // What the supervisor renders: this poll's counts, through the scene data of a teaser.
    $shown = fn (): array => app(SceneSource::class)->rotation('a3', null, [], 0, (int) now()->getTimestampMs(), app(StreamStats::class)->all())['stats'];

    $before = $shown();
    User::factory()->create();
    Clan::factory()->create();
    ClanMember::query()->create(['clan_id' => $clan->id, 'user_id' => User::factory()->create()->id, 'role' => 'member', 'joined_at' => now()]);
    ChessGame::factory()->finished()->create(['ended_at' => now()]);
    ChessGame::factory()->create();
    $bob->forceFill(['rating' => 1200, 'results' => 3])->save();
    $this->travel(10)->seconds();
    $cached = $shown();
    $this->travel(6)->seconds();
    $after = $shown();
    $numbers = fn (array $stats): array => [$stats['players'], $stats['clans'], $stats['gamesPlayed'], $stats['gamesToday'], $stats['liveNow'], $stats['clan']['members']];

    expect($numbers($before))->toBe([$before['players'], 1, 1, 1, 1, 1])
        ->and(array_column($before['ladders']['blitz'], 'name'))->toBe(['Alice', 'Bob'])
        ->and($cached)->toBe($before)
        ->and($numbers($after))->toBe([User::query()->count(), 2, 2, 2, 2, 2])
        ->and($after['players'])->toBeGreaterThan($before['players'])
        ->and($after['clan']['name'])->toBe('Oldest')
        // A new result moves Bob up, with his Elo.
        ->and(array_column($after['ladders']['blitz'], 'name'))->toBe(['Bob', 'Alice'])
        ->and(array_column($after['ladders']['blitz'], 'elo'))->toBe([1200, 1100])
        ->and($alice->id)->toBeInt();
});

test('with the board games switched off their old ladders and games stay off the stream', function () {
    casualRating(null, 'blitz', 1111, 3)->forceFill(['game' => 'checkers'])->save();
    BoardGame::query()->create(['game' => 'checkers', 'mode' => 'blitz', 'white_id' => User::factory()->create()->id, 'black_id' => User::factory()->create()->id, 'status' => 'finished', 'result' => '1-0',
        'position' => '-', 'turn' => 'w', 'ply' => 9, 'initial_ms' => 300000, 'increment_ms' => 3000, 'white_ms' => 1, 'black_ms' => 1, 'turn_started_ms' => 0, 'ended_at' => now()]);
    $source = app(SceneSource::class);
    $renderer = SceneRenderer::fromConfig();
    $stats = app(StreamStats::class)->all();

    expect($stats['boards'])->toBe([])
        ->and($stats['gamesPlayed'])->toBe(0)
        ->and($renderer->svg($source->rotation('b3', null, [], 0, 0, $stats), RotationPlanner::VIEWS['b3']))->toContain('Chess, up to 24 hours for each move.')->not->toContain('Checkers')
        ->and($renderer->svg($source->rotation('c3', null, [], 0, 0, $stats), RotationPlanner::VIEWS['c3']))->toContain('Every game has a ladder.')->not->toContain('Checkers');
});

test('every game reaches the stream: its ladders (the season ladder first, a lineup under its clan) and its games, a voided series never', function () {
    CheckersGame::play();
    $season = openSeason();
    $alice = User::factory()->create(['name' => 'Alice']);
    casualRating($alice, 'blitz', 1300, 4);
    casualRating($alice, 'blitz', 1050, 2, pool: Rating::RATED)->forceFill(['season' => $season->slug])->save();
    casualRating(null, 'blitz', 1111, 3)->forceFill(['game' => 'checkers'])->save();
    $lineup = Lineup::factory()->create(['clan_id' => Clan::factory()->create(['name' => 'Rocket <Pack>'])->id]);
    Rating::query()->create(['pool' => Rating::CASUAL, 'season' => '', 'game' => 'rocket-league', 'mode' => '3v3', 'subject' => 'lineup:'.$lineup->id, 'lineup_id' => $lineup->id, 'rating' => 1020, 'results' => 1, 'wins' => 1]);
    ChessGame::factory()->finished()->create();
    BoardGame::query()->create(['game' => 'checkers', 'mode' => 'blitz', 'white_id' => $alice->id, 'black_id' => User::factory()->create()->id, 'status' => 'finished', 'result' => '1-0',
        'position' => '-', 'turn' => 'w', 'ply' => 9, 'initial_ms' => 300000, 'increment_ms' => 3000, 'white_ms' => 1, 'black_ms' => 1, 'turn_started_ms' => 0, 'ended_at' => now()]);
    SeriesMatch::factory()->create(['status' => SeriesStatus::Confirmed, 'winner' => 'challenger', 'finished_at' => now()]);
    // Two accounts of one person played each other: the league voided it.
    SeriesMatch::factory()->create(['status' => SeriesStatus::Resolved, 'resolution' => SeriesResolution::Void, 'winner' => 'challenger', 'finished_at' => now()]);

    $stats = app(StreamStats::class)->count();
    $boards = collect($stats['boards'])->keyBy(fn (array $board): string => $board['game'].'/'.$board['mode']);
    $c3 = SceneRenderer::fromConfig()->svg(app(SceneSource::class)->rotation('c3', null, [], 0, 0, app(StreamStats::class)->all()), RotationPlanner::VIEWS['c3']);

    expect($stats['gamesPlayed'])->toBe(3)
        ->and($stats['gamesToday'])->toBe(3)
        ->and($boards->keys()->all())->toBe(['chess/blitz', 'rocket-league/3v3', 'checkers/blitz'])
        // The season ladder has rows, so it is the one shown.
        ->and($boards['chess/blitz']['pool'])->toBe(Rating::RATED)
        ->and(array_column($boards['chess/blitz']['rows'], 'elo'))->toBe([1050])
        ->and($boards['rocket-league/3v3']['rows'][0])->toMatchArray(['name' => 'Rocket <Pack>', 'tag' => $lineup->clan->clantag, 'avatarRef' => null])
        ->and($boards['checkers/blitz'])->toMatchArray(['gameName' => 'Checkers', 'modeName' => 'Blitz 5+3', 'pool' => Rating::CASUAL])
        ->and($c3)->toContain('Chess Blitz 5+3', 'Rocket League 3v3', 'Checkers Blitz 5+3', 'Rocket &lt;Pack&gt;', '>season<')
        ->and($c3)->not->toContain('<Pack>');
});
