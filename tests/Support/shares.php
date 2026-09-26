<?php

/*
 * Helpers of the badge and share tests (P11), shared by tests/Feature/Badges
 * and tests/Browser/ShareTest.php (loaded from tests/Pest.php).
 */

use App\Enums\TournamentFormat;
use App\Enums\TournamentStatus;
use App\Models\RankBadgeVersion;
use App\Models\Rating;
use App\Models\Season;
use App\Models\SeasonAttestation;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\Badges\RankBadges;
use App\Support\SeasonChain\Candidate;
use App\Support\SeasonChain\Resolution;
use App\Support\Tournaments\TournamentBrackets;
use Carbon\CarbonImmutable;
use Tests\Support\TestSigner;

/**
 * Everything a player can share, for `$user` in the live season `$season`
 * (openSeason()): a rank badge that went Silver III → Gold II (two rank-up
 * versions), a mined chess block and a second one before it, and a finished
 * single-elimination tournament the player won.
 *
 * @return array{versions: list<RankBadgeVersion>, block: SeasonAttestation, tournament: Tournament, opponent: User}
 */
function shareMoments(User $user, Season $season): array
{
    config(['esports.badges.nsec' => config('esports.badges.nsec') ?: (new TestSigner)->secret]);

    $rating = Rating::query()->create([
        'pool' => Rating::RATED, 'season' => $season->slug, 'game' => 'chess', 'mode' => 'blitz',
        'subject' => 'user:'.$user->id, 'user_id' => $user->id, 'rating' => 1010, 'results' => 5, 'wins' => 4,
    ]);
    $badges = app(RankBadges::class);
    $versions = [$badges->sync($user, 'chess', 'blitz')];
    $rating->forceFill(['rating' => 1061, 'results' => 9, 'wins' => 7])->save();
    $versions[] = $badges->sync($user, 'chess', 'blitz');

    $opponent = User::factory()->create(['name' => 'pillpusher']);
    shareBlock($season, 1, $user, $opponent);
    $block = shareBlock($season, 2, $user, $opponent);

    $tournament = shareTournament($user, $opponent);

    return ['versions' => $versions, 'block' => $block, 'tournament' => $tournament, 'opponent' => $opponent];
}

/** A finished two-player single elimination ("Testnet Cup") that `$winner` won. */
function shareTournament(User $winner, User $loser): Tournament
{
    $tournament = Tournament::factory()->create([
        'name' => 'Testnet Cup', 'format' => TournamentFormat::SingleElimination, 'capacity' => 2,
        'status' => TournamentStatus::Finished, 'slug' => 'testnet-cup-'.fake()->unique()->numberBetween(1, 1_000_000), 'starts_at' => now()->subMinutes(30),
    ]);
    $entries = [];

    foreach ([$winner, $loser] as $index => $player) {
        $entries[] = TournamentParticipant::query()->create(['tournament_id' => $tournament->id, 'user_id' => $player->id, 'name' => $player->displayName(), 'rating' => 1100 - $index, 'members' => [$player->id]]);
    }

    app(TournamentBrackets::class)->generate($tournament, str_repeat('ab', 32));
    $final = TournamentMatch::query()->where('tournament_id', $tournament->id)->with('slots')->sole();
    $winnerSlot = $final->slots->search(fn ($slot) => $slot->tournament_participant_id === $entries[0]->id);
    $final->forceFill(['status' => 'done', 'result' => ['winner' => $winnerSlot, 'games_won' => $winnerSlot === 0 ? [1, 0] : [0, 1], 'points' => []]])->save();

    return $tournament->refresh();
}

/** A mined chess block with `$winner` as the miner, as SeasonChains stores it. */
function shareBlock(Season $season, int $height, User $winner, User $loser): SeasonAttestation
{
    $at = CarbonImmutable::now()->subMinutes(10 - $height);
    $candidate = new Candidate('#'.(210 + $height), 'chess:'.$height, 'chess', 'chess/blitz', $at, Resolution::Confirmed, 41,
        [$winner->pubkey], [$loser->pubkey], null, ['user:'.$winner->id, 'user:'.$loser->id], [$winner->pubkey, $loser->pubkey], true,
        [$winner->pubkey => 100, $loser->pubkey => 100], [], []);

    return SeasonAttestation::query()->create([
        'season_id' => $season->id, 'source' => 'chess', 'source_id' => $height, 'label' => $candidate->label, 'game' => 'chess', 'mode' => 'blitz',
        'ladder_address' => 'chess/blitz', 'attested_at' => $at, 'candidate' => $candidate->toArray(),
        'height' => $height, 'era' => 1, 'reward_per_player' => 5_000, 'reward' => 5_000, 'event_id' => hash('sha256', 'share-block-'.$height.'-'.$season->id.'-'.$winner->pubkey),
    ]);
}
