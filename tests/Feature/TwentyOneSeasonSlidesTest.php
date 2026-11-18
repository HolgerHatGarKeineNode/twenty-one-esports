<?php

/*
|--------------------------------------------------------------------------
| Season scenes on the stream (Pre-Season launch, Block 0 on 2026-11-20)
|--------------------------------------------------------------------------
|
| s1 counts down to the planned Block 0 every frame ("Block 0 soon." without a date, "Any moment now." once the date
| has passed); s2 shows the launch for `season_launch_hours` after Block 0 and retires by itself; s3 shows one game
| that mines, from the weights and the switches, with ChainOverview's own era and halving figures and its latest block
| that stands. The rotation weighting is the planner's (tests/Unit/TwentyOneRotationPlannerSeasonTest.php).
|
*/

use App\Games\GameRegistry;
use App\Models\Season;
use App\Models\SeasonAttestation;
use App\Models\SeasonBlockVoid;
use App\Models\User;
use App\Support\SeasonChain\Candidate;
use App\Support\SeasonChain\ChainDraft;
use App\Support\SeasonChain\ChainOverview;
use App\Support\SeasonChain\Resolution;
use App\Support\SeasonChain\SeasonRelease;
use App\Support\TwentyOne\Stream\RotationKit;
use App\Support\TwentyOne\Stream\RotationPlanner;
use App\Support\TwentyOne\Stream\SceneRenderer;
use App\Support\TwentyOne\Stream\SceneSource;
use App\Support\TwentyOne\Stream\SeasonSlides;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BlockliOn;
use Tests\Support\HyperOn;

beforeEach(function () {
    Storage::fake('local');
    CarbonImmutable::setTestNow('2026-11-18 12:00:00');
    config(['esports.chess.rated_queue' => true, 'esports.board_games.rated_queue' => true]);
    BlockliOn::play();
    HyperOn::play();
});

/** The season state as the stream reads it, fresh (the cache dropped). */
function seasonState(): array
{
    Cache::forget(SeasonSlides::CACHE_KEY);

    return app(SeasonSlides::class)->state();
}

/** The game's place in GameRegistry::listed(), the planner's `gameId` for s3. */
function seasonGameIndex(string $slug): int
{
    return (int) array_search($slug, array_keys(app(GameRegistry::class)->listed()), true);
}

/** The Pre-Season released `$hoursAgo` hours ago with the draft's parameters. */
function releasedSeason(float $hoursAgo = 1): Season
{
    $released = SeasonRelease::draft();
    $genesis = now()->subSeconds((int) ($hoursAgo * 3600))->startOfSecond();

    return Season::factory()->create(['genesis_message' => 'Pre-Season: every fair win is a block. Tick tock, next block.',
        'supply' => $released['supply'], 'subsidy' => $released['subsidy'], 'eras' => $released['eras'], 'parameters' => $released['parameters'],
        'genesis_at' => $genesis, 'ends_at' => $genesis->addWeeks(12)]);
}

/** A stored block of `$game` at `$height`, mined by `$winner` `$minutesAgo` minutes ago (heights in time order). */
function seasonBlock(Season $season, int $height, string $game, string $mode, User $winner, int $minutesAgo, int $perPlayer = 2_100): SeasonAttestation
{
    $loser = User::factory()->create();
    $at = CarbonImmutable::now()->subMinutes($minutesAgo);
    $candidate = new Candidate('#'.$height, $game.':'.$height, $game, $game.'/'.$mode, $at, Resolution::Confirmed, 41,
        [$winner->pubkey], [$loser->pubkey], null, ['user:'.$winner->id, 'user:'.$loser->id], [$winner->pubkey, $loser->pubkey], true,
        [$winner->pubkey => 100, $loser->pubkey => 100], [], []);

    return SeasonAttestation::query()->create(['season_id' => $season->id, 'source' => $game === 'chess' ? 'chess' : 'board', 'source_id' => $height,
        'label' => '#'.$height, 'game' => $game, 'mode' => $mode, 'ladder_address' => $game.'/'.$mode, 'attested_at' => $at, 'candidate' => $candidate->toArray(),
        'height' => $height, 'era' => 1, 'reward_per_player' => $perPlayer, 'reward' => $perPlayer, 'event_id' => hash('sha256', 'season-slide-'.$height.'-'.$season->id)]);
}

/** A season scene's SVG as the stream renders it. */
function seasonSvg(string $scene, ?int $game = null): string
{
    Cache::forget(SeasonSlides::CACHE_KEY);
    $data = [...app(SceneSource::class)->rotation($scene, $game, [], 0, (int) now()->getTimestampMs(), ['players' => 412]), 'viewers' => 17];

    return SceneRenderer::fromConfig()->svg($data, RotationPlanner::VIEWS[$scene]);
}

/**
 * The slide's own texts (left of the now-playing column) with their right edge, as RotationKit measures the fonts.
 *
 * @return list<array{text: string, left: float, right: float}>
 */
function seasonTexts(string $svg): array
{
    preg_match_all('#<text[^>]*\sx="([\d.]+)"[^>]*font-family="(Unbounded|JetBrains Mono)"[^>]*font-size="([\d.]+)"[^>]*>([^<]+)</text>#', $svg, $texts, PREG_SET_ORDER);
    $out = [];

    foreach ($texts as [$tag, $x, $font, $size, $text]) {
        $text = html_entity_decode($text);
        $width = RotationKit::width($text, $font === 'Unbounded' ? RotationKit::DISPLAY : RotationKit::MONO, (float) $size) * ($font === 'Unbounded' ? 1.04 : 1.0);
        $left = str_contains($tag, 'text-anchor="end"') ? (float) $x - $width : (float) $x;

        if ($left < 1040) {
            $out[] = ['text' => $text, 'left' => $left, 'right' => $left + $width];
        }
    }

    return $out;
}

test('without a date the countdown is a teaser, "Block 0 soon.", and it only replaces a closing teaser', function () {
    $state = seasonState();

    expect(SeasonSlides::entries($state, now()->getTimestamp()))->toBe(['lead' => SeasonSlides::COUNTDOWN, 'games' => [], 'boost' => false])
        ->and(SeasonSlides::frameSeason($state, (int) now()->getTimestampMs()))->toMatchArray(['clock' => 'soon', 'countdown' => null])
        ->and(seasonSvg(SeasonSlides::COUNTDOWN))->toContain('Block 0 soon.')->not->toMatch('/\d+d \d\d:\d\d:\d\d/');
});

test('with a planned Block 0 the countdown ticks every frame and the season leads from seven days before', function () {
    planBlock0(now()->addDays(2)->addHours(5)->addMinutes(7));
    $state = seasonState();
    $now = (int) now()->getTimestampMs();

    expect(SeasonSlides::frameSeason($state, $now)['countdown'])->toBe('2d 05:07:00')
        ->and(SeasonSlides::frameSeason($state, $now + 1_000)['countdown'])->toBe('2d 05:06:59')
        ->and(SeasonSlides::entries($state, now()->getTimestamp()))->toMatchArray(['lead' => SeasonSlides::COUNTDOWN, 'boost' => true])
        ->and(SeasonSlides::entries($state, now()->getTimestamp())['games'])->toContain(seasonGameIndex('chess'), seasonGameIndex('blockli'), seasonGameIndex('hyperbitcoinization'));

    planBlock0(now()->addDays(8));

    expect(SeasonSlides::entries(seasonState(), now()->getTimestamp()))->toBe(['lead' => SeasonSlides::COUNTDOWN, 'games' => [], 'boost' => false]);
});

test('once the date has passed without a release the countdown says "Any moment now.", never a frozen or negative time', function () {
    planBlock0(now()->subMinutes(10));
    $state = seasonState();

    expect(SeasonSlides::frameSeason($state, (int) now()->getTimestampMs()))->toMatchArray(['clock' => 'due', 'countdown' => null])
        ->and(SeasonSlides::entries($state, now()->getTimestamp())['boost'])->toBeTrue()
        ->and(seasonSvg(SeasonSlides::COUNTDOWN))->toContain('Any moment now.')->not->toContain('-0', '00:00:00');
});

test('after a season with no next one planned no season slide shows', function () {
    $season = releasedSeason(24 * 7 * 13);

    expect($season->ends_at->isPast())->toBeTrue()
        ->and(SeasonSlides::entries(seasonState(), now()->getTimestamp()))->toBe(['lead' => null, 'games' => [], 'boost' => false]);
});

test('the launch leads for the configured hours after Block 0 and retires by itself, the games keep mining after', function () {
    config(['twentyone.stream.rotation.season_launch_hours' => 12]);
    releasedSeason(1);
    $state = seasonState();
    $genesis = (int) $state['genesis'];

    expect(SeasonSlides::entries($state, $genesis + 3600)['lead'])->toBe(SeasonSlides::LAUNCH)
        ->and(SeasonSlides::entries($state, $genesis + 12 * 3600 - 1)['lead'])->toBe(SeasonSlides::LAUNCH)
        ->and(SeasonSlides::entries($state, $genesis + 12 * 3600))->toMatchArray(['lead' => null, 'boost' => true])
        ->and(SeasonSlides::entries($state, $genesis + 12 * 3600)['games'])->not->toBe([])
        ->and(seasonSvg(SeasonSlides::LAUNCH))->toContain('Pre-Season is live.', 'Pre-Season: every fair win is a block.', '2,100,000 sats to mine', 'The next block is up for grabs');
});

test('a game gets a slide only while it mines: switched off or without a weight it has none', function () {
    planBlock0(now()->addDay());

    expect(SeasonSlides::entries(seasonState(), now()->getTimestamp())['games'])->toContain(seasonGameIndex('blockli'));

    config(['esports.board_games.rated_queue' => false]);

    expect(SeasonSlides::entries(seasonState(), now()->getTimestamp())['games'])->not->toContain(seasonGameIndex('blockli'))->toContain(seasonGameIndex('chess'));

    $chain = ChainDraft::stored();
    saveChainDraft([...$chain, 'weights' => array_filter($chain['weights'], fn (string $key): bool => ! str_starts_with($key, 'chess/'), ARRAY_FILTER_USE_KEY)]);

    expect(SeasonSlides::entries(seasonState(), now()->getTimestamp())['games'])->not->toContain(seasonGameIndex('chess'))->toContain(seasonGameIndex('hyperbitcoinization'));
});

test('a game\'s era, halving distance and pay per mode are ChainOverview\'s own figures', function () {
    $season = releasedSeason(2);
    $ada = User::factory()->create(['name' => 'Ada']);
    seasonBlock($season, 1, 'chess', 'blitz', $ada, 60);
    seasonBlock($season, 2, 'chess', 'rapid', $ada, 30);
    $keys = app(ChainOverview::class)->live($season)['keys'];

    foreach (seasonState()['games'] as $game) {
        $key = $season->chainParameters()->genesis->shareKey($game['slug']);

        expect([$game['era'], $game['eras'], $game['to_halving'], $game['closed']], $game['slug'])
            ->toBe([$keys[$key]['era'], $keys[$key]['eras'], $keys[$key]['to_halving'], $keys[$key]['closed']])
            ->and(array_column($game['modes'], 'pays'))->each->toBeIn(array_column($keys[$key]['modes'], 'pays'))
            // The "about N wins" line: the wins left at this reward of the mode it names, as ChainOverview counts them.
            ->and($game['wins'])->toBe(collect($keys[$key]['modes'])->first(fn (array $mode, string $modeKey): bool => ChainOverview::keyLabel($modeKey) === $game['winsLabel'])['wins'] ?? null);
    }

    // The share tiles: the cheapest and the dearest win of each group, over its games' modes.
    foreach (SeasonSlides::sharePays(seasonState()) as $tile) {
        $pays = collect(seasonState()['games'])->where('group', $tile['label'])->flatMap(fn (array $game): array => array_column($game['modes'], 'pays'));
        expect([$tile['low'], $tile['high']], $tile['label'])->toBe($pays->isEmpty() ? [0, 0] : [$pays->min(), $pays->max()]);
    }
});

test('a voided block never shows: the latest block and the count are the ones that stand', function () {
    $season = releasedSeason(2);
    $ada = User::factory()->create(['name' => 'Ada']);
    $bob = User::factory()->create(['name' => 'Bob']);
    seasonBlock($season, 1, 'chess', 'blitz', $ada, 60);
    seasonBlock($season, 2, 'chess', 'blitz', $ada, 40);
    $void = seasonBlock($season, 3, 'chess', 'blitz', $bob, 5);
    SeasonBlockVoid::query()->create(['season_id' => $season->id, 'height' => 3, 'season_attestation_id' => $void->id, 'reason' => 'Farmed.', 'voided_by_pubkey' => str_repeat('a', 64)]);

    $chess = collect(seasonState()['games'])->firstWhere('slug', 'chess');

    expect($chess['blocks'])->toBe(2)
        ->and($chess['latest']['height'])->toBe(2)
        ->and(array_column($chess['latest']['miners'], 'name'))->toBe(['Ada'])
        ->and(implode("\n", array_column(seasonTexts(seasonSvg(SeasonSlides::GAME, seasonGameIndex('chess'))), 'text')))->toContain('Latest block · height 2', 'Ada')->not->toContain('Bob');
});

test('every season slide keeps its texts inside x 32..1016, long miner names included, with no fee wording', function () {
    planBlock0(now()->addDays(2));
    $svgs = [seasonSvg(SeasonSlides::COUNTDOWN), ...array_map(fn (string $slug): string => seasonSvg(SeasonSlides::GAME, seasonGameIndex($slug)), ['chess', 'blockli', 'hyperbitcoinization'])];

    $season = releasedSeason(1);
    seasonBlock($season, 1, 'blockli', 'correspondence', User::factory()->create(['name' => 'Satoshi Nakamoto the Second of the Cypherpunk Mailing List']), 20);
    $svgs = [...$svgs, seasonSvg(SeasonSlides::LAUNCH), ...array_map(fn (string $slug): string => seasonSvg(SeasonSlides::GAME, seasonGameIndex($slug)), ['chess', 'blockli', 'hyperbitcoinization'])];

    foreach ($svgs as $svg) {
        expect($svg)->toContain('width="1280" height="720"')
            ->and(implode("\n", array_column(seasonTexts($svg), 'text')))->not->toMatch('/\bfee\b|Gebühr|#\w/iu');

        foreach (seasonTexts($svg) as $t) {
            expect($t['left'])->toBeGreaterThanOrEqual(32, $t['text'])
                ->and($t['right'])->toBeLessThanOrEqual(1016, $t['text']);
        }
    }

    expect($svgs[6])->toContain('Satoshi Nakamoto');
});
