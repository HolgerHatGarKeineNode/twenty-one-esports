<?php

use App\Enums\BoardGameStatus;
use App\Enums\SeriesResolution;
use App\Enums\SeriesStatus;
use App\Games\GameRegistry;
use App\Models\BoardGame;
use App\Models\Season;
use App\Models\SeasonAttestation;
use App\Models\SeriesMatch;
use App\Models\User;

/*
| Helpers of the /matches tests (plan "Mempool-Streifen"): board games,
| finished series and season attestations written as the app writes them.
*/

/**
 * A board game row as BoardGameService::start() writes it, then moved on to
 * the given state: no queue, no broadcast, no clock job.
 *
 * @param  array<string, mixed>  $attributes
 */
function mempoolBoard(string $slug, array $attributes = []): BoardGame
{
    $rules = app(GameRegistry::class)->get($slug)->rules();
    $start = $rules->start();
    $now = (int) now()->getTimestampMs();

    return BoardGame::query()->create([
        'game' => $slug, 'mode' => 'blitz', 'white_id' => User::factory()->create()->id, 'black_id' => User::factory()->create()->id,
        'status' => BoardGameStatus::Active, 'position' => $rules->serialize($start), 'turn' => $rules->turn($start), 'ply' => 0,
        'initial_ms' => 300_000, 'increment_ms' => 3_000, 'white_ms' => 300_000, 'black_ms' => 300_000, 'turn_started_ms' => $now, 'rated' => false,
        ...$attributes,
    ]);
}

/**
 * A finished series with a result, as SeriesService records it.
 *
 * @param  array<string, mixed>  $attributes
 */
function mempoolSeries(array $attributes = []): SeriesMatch
{
    return SeriesMatch::factory()->accepted()->create([
        'status' => SeriesStatus::Confirmed, 'winner' => 'challenger', 'finished_at' => now(), 'resolution' => SeriesResolution::Confirmed,
        'result_games' => [['winner' => 'challenger', 'challenger' => 3, 'challenged' => 1], ['winner' => 'challenger', 'challenger' => 2, 'challenged' => 0]],
        ...$attributes,
    ]);
}

/**
 * An attestation of the season chain for a match: mined at `height`, or
 * rejected for `reason`.
 */
function mempoolAttest(Season $season, string $source, int $id, ?int $height, ?string $reason = null, bool $candidate = true): SeasonAttestation
{
    return SeasonAttestation::query()->create([
        'season_id' => $season->id, 'source' => $source, 'source_id' => $id, 'label' => '#'.$id, 'game' => 'chess', 'mode' => 'blitz',
        'ladder_address' => 'x', 'attested_at' => now(), 'candidate' => $candidate ? ['winners' => []] : null, 'height' => $height,
        'rule' => $reason === null ? null : 1, 'reason' => $reason, 'era' => $height === null ? null : 1,
        'reward_per_player' => 0, 'reward' => 0, 'event_id' => bin2hex(random_bytes(32)),
    ]);
}

/**
 * The strip's cubes as the page renders them: game and state of each, in order.
 *
 * @return list<string>
 */
function stripCubes(string $html): array
{
    preg_match_all('/data-test="strip-cube" data-game="([^"]+)" data-state="([^"]+)"/', $html, $matches, PREG_SET_ORDER);

    return array_map(fn (array $match): string => $match[1].':'.$match[2], $matches);
}
