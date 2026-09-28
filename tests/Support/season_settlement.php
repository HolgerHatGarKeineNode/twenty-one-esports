<?php

/*
 * Helpers of the season settlement tests (P37), loaded from tests/Pest.php:
 * the feature tests and the browser test build the same ended season.
 */

use App\Models\Season;
use App\Models\SeasonAttestation;
use App\Models\SeasonPayout;
use App\Models\User;
use App\Support\SeasonChain\BlockChain;
use App\Support\SeasonChain\Candidate;
use App\Support\SeasonChain\ConsensusRules;
use App\Support\SeasonChain\Resolution;
use Carbon\CarbonImmutable;
use Tests\Support\FakeNwcWallet;

/**
 * An ended season whose blocks are the wins `$wins` (winner, day after
 * Block 0), stored as SeasonChains stores them: every candidate replayed
 * through the chain first, so the stored heights are the replay's.
 *
 * @param  list<array{0: User, 1: int}>  $wins
 */
function settledSeason(array $wins): Season
{
    $genesisAt = now()->subDays(40)->startOfSecond();
    $season = openSeason(['genesis_at' => $genesisAt, 'ends_at' => now()->subHour()->startOfSecond()]);
    $chain = new BlockChain($season->chainParameters(), new ConsensusRules($season->minimum_trust));

    foreach ($wins as $index => [$winner, $day]) {
        $loser = User::factory()->create();
        $at = CarbonImmutable::instance($genesisAt)->addDays($day)->addMinutes($index);
        $candidate = new Candidate('#'.(500 + $index), 'series:'.(500 + $index), 'rocket-league', 'rocket-league/1v1', $at, Resolution::Confirmed, null,
            [$winner->pubkey], [$loser->pubkey], 'lineup:1', ['lineup:'.(1000 + $index), 'lineup:'.(2000 + $index)], [$winner->pubkey, $loser->pubkey], true,
            [$winner->pubkey => 100, $loser->pubkey => 100], [], []);
        $verdict = $chain->attest($candidate);

        SeasonAttestation::query()->create([
            'season_id' => $season->id, 'source' => 'series', 'source_id' => 500 + $index, 'label' => $candidate->label, 'game' => 'rocket-league', 'mode' => '1v1',
            'ladder_address' => 'rocket-league/1v1', 'attested_at' => $at, 'candidate' => $candidate->toArray(),
            'height' => $verdict->mines() ? count($chain->blocks()) : null, 'rule' => $verdict->rule?->value, 'reason' => $verdict->reason,
            'era' => $verdict->era, 'reward_per_player' => $verdict->rewardPerPlayer, 'reward' => $verdict->reward, 'event_id' => hash('sha256', 'settle-'.$index),
        ]);
    }

    return $season->refresh();
}

/** A player with a Lightning address the fake LNURL server answers, set long ago (no freeze). */
function settlementPlayer(string $name, ?string $address = null): User
{
    return User::factory()->create(['name' => $name, 'lud16' => $address ?? strtolower($name).'@wallet.example']);
}

function payoutOf(Season $season, User $user): SeasonPayout
{
    return SeasonPayout::query()->where('season_id', $season->id)->where('pubkey', $user->pubkey)->sole();
}

function settlementWallet(): FakeNwcWallet
{
    $wallet = fakeWallet();
    fakeLightningAddresses($wallet);

    return $wallet;
}
