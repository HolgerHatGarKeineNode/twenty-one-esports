<?php

/*
 * Helpers of the `season-chain-v2` page tests, loaded by the files that use
 * them: the feature test (KeyErasPagesTest) and the browser test
 * (KeyHalvingsTest) build the same small live season, in which a few wins
 * take a game past its first halving.
 */

use App\Models\Season;
use App\Models\SeasonAttestation;
use App\Models\User;
use App\Support\SeasonChain\BlockChain;
use App\Support\SeasonChain\Candidate;
use App\Support\SeasonChain\ConsensusRules;
use App\Support\SeasonChain\Resolution;
use Carbon\CarbonImmutable;

/**
 * A live season small enough to halve in a few wins: 21 000 sats, chess 50 %
 * (pot 10 500, era 1 ends at 5 250, era 2 at 7 875), the board games 30 %
 * (pot 6 300, era 1 ends at 3 150); a blitz win pays 2 100 in era 1.
 */
function keySeason(array $attributes = []): Season
{
    $parameters = Season::factory()->make()->parameters;

    return openSeason([
        'supply' => 21_000,
        'parameters' => [...$parameters, 'weights' => ['chess/blitz' => 1000, 'chess/rapid' => 1500, 'blockli/blitz' => 1000, 'blockli/rapid' => 1500], 'shares' => ['chess' => 50, 'board-games' => 30], 'daily' => ['chess' => 5, 'board-games' => 5]],
        ...$attributes,
    ]);
}

/** Wins of these `<game>/<mode>`, attested in this order between players nobody else is, stored as the chain rules them. */
function keyWins(Season $season, array $keys): BlockChain
{
    $chain = new BlockChain($season->chainParameters(), new ConsensusRules($season->minimum_trust));

    foreach ($keys as $index => $key) {
        [$game, $mode] = explode('/', $key, 2);
        $winner = User::factory()->create();
        $loser = User::factory()->create();
        $at = CarbonImmutable::instance($season->genesis_at)->addMinutes(1 + $index);
        $candidate = new Candidate('#'.(700 + $index), 'series:'.(700 + $index), $game, $key, $at, Resolution::Confirmed, 34,
            [$winner->pubkey], [$loser->pubkey], null, [$winner->pubkey, $loser->pubkey], [$winner->pubkey, $loser->pubkey], true,
            [$winner->pubkey => 100, $loser->pubkey => 100], [], []);
        $verdict = $chain->attest($candidate);

        SeasonAttestation::query()->create([
            'season_id' => $season->id, 'source' => 'series', 'source_id' => 700 + $index, 'label' => $candidate->label, 'game' => $game, 'mode' => $mode,
            'ladder_address' => $key, 'attested_at' => $at, 'candidate' => $candidate->toArray(),
            'height' => $verdict->mines() ? count($chain->blocks()) : null, 'rule' => $verdict->rule?->value, 'reason' => $verdict->reason,
            'era' => $verdict->era, 'reward_per_player' => $verdict->rewardPerPlayer, 'reward' => $verdict->reward, 'event_id' => hash('sha256', 'key-'.$index),
        ]);
    }

    return $chain;
}
