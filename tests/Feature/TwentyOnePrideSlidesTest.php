<?php

use App\Models\ChessGame;
use App\Models\Rating;
use App\Models\RatingChange;
use App\Models\Tournament;
use App\Models\User;
use App\Support\TwentyOne\Stream\PrideSlides;
use App\Support\TwentyOne\Stream\RotationKit;
use App\Support\TwentyOne\Stream\RotationPlanner;
use App\Support\TwentyOne\Stream\SceneRenderer;
use App\Support\TwentyOne\Stream\SceneSource;
use App\Support\TwentyOne\Stream\StreamStats;
use Tests\Support\TestSigner;

/**
 * A casual chess rating with one change per delta, the last one for `$gameId` (if given).
 *
 * @param  list<int>  $deltas
 */
function prideRating(User $user, array $deltas, ?int $gameId = null, string $pool = Rating::CASUAL): void
{
    $row = Rating::query()->create([
        'pool' => $pool, 'season' => $pool === Rating::RATED ? 'season-1' : '', 'game' => 'chess', 'mode' => 'blitz',
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
    // Rated changes are not casual climbs.
    prideRating($zoe, [99], pool: Rating::RATED);

    $cup = openTournament(['name' => 'Cup <script>']);
    [$player, $signer] = keyedPlayer();
    soloSignup($cup, $player, $signer);
    $pot = openTournament(['name' => 'Sats Cup']);
    $pot->forceFill(['pot_source' => Tournament::POT_WALLET, 'prize_target_sats' => 21000, 'prize_split' => [50, 30, 20]])->save();

    $pride = app(PrideSlides::class)->all();

    expect($pride['win'])->toMatchArray(['winner' => 'Ben', 'loser' => 'Zoe <b>', 'mode' => 'Blitz chess', 'delta' => 16])
        ->and(array_column($pride['climbers'], 'gain'))->toBe([55, 26])
        ->and(array_column($pride['climbers'], 'name'))->toBe(['Kai', 'Ben'])
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
        ->and($svgs['e2'])->toContain('+55 Elo', '+26 Elo')
        ->and($svgs['e3'])->toContain('Cup &lt;script&gt;')
        ->and($svgs['e4'])->toContain('10,395 sats', '6,237 sats', '4,158 sats')
        ->and(implode('', $svgs))->not->toContain('<script>', '<b>');
});

test('without games, climbs, sign-ups or pots every pride slide says so instead of showing nobody', function () {
    $pride = app(PrideSlides::class)->all();
    $renderer = SceneRenderer::fromConfig();
    $svg = fn (string $scene): string => $renderer->svg(['pride' => $pride, 'stats' => [], 'backdrop' => null, 'viewers' => null], RotationPlanner::VIEWS[$scene]);

    expect($pride)->toBe(['win' => null, 'climbers' => [], 'signups' => [], 'prizes' => null])
        ->and($svg('e1'))->toContain('No winner yet.')
        ->and($svg('e2'))->toContain('Nobody has climbed this week yet.')
        ->and($svg('e3'))->toContain('No sign-ups yet.')
        ->and($svg('e4'))->toContain('No pot open right now.');
});

test('a styled name keeps its letters on the stream instead of losing them', function () {
    expect(RotationKit::clean('𝕞ptf'))->toBe('mptf')
        ->and(RotationKit::clean('Ｂｅｎ 🚀'))->toBe('Ben')
        ->and(RotationKit::clean('Müller'))->toBe('Müller');
});
