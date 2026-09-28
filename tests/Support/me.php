<?php

/*
 * The player of /me (P30) with something in every part of the page, shared
 * by tests/Feature/MeHubTest.php and tests/Browser/MeHubTest.php (loaded
 * from tests/Pest.php).
 */

use App\Enums\Platform;
use App\Enums\SeriesStatus;
use App\Models\ChessGame;
use App\Models\Clan;
use App\Models\Lineup;
use App\Models\Rating;
use App\Models\RatingChange;
use App\Models\SeriesMatch;
use App\Models\Tournament;
use App\Models\TournamentSignup;
use App\Models\User;
use App\Support\Series\CasualChallenges;
use Tests\Support\TestSigner;

/**
 * Satoshi: a clan with a Rocket League 3v3 lineup, a daily game on their
 * move and one on the other side's, `$results` finished blitz games plus a
 * won series, casual ratings in chess blitz and RL 3v3, a tournament signed
 * up for, a casual 1v1 sent and one accepted for tomorrow, gamer tags, and
 * Looking to play on for Rocket League.
 *
 * @return array{me: User, clan: Clan, lineup: Lineup, myMove: ChessGame, theirMove: ChessGame, tournament: Tournament, sent: SeriesMatch, scheduled: SeriesMatch, won: SeriesMatch}
 */
function meHubPlayer(int $results = 7): array
{
    config(['esports.league.nsec' => (new TestSigner)->secret]);

    $me = User::factory()->create(['name' => 'Satoshi', 'gamer_tags' => ['epic' => 'satoshi21'], 'looking_to_play' => 'rocket-league/1v1']);
    // A second player in the same test gets a clan of their own (name and tag are unique).
    static $seeded = 0;
    $suffix = $seeded++ === 0 ? '' : (string) $seeded;
    $clan = Clan::factory()->create(['owner_id' => $me->id, 'name' => trim('Laser Eyes '.$suffix), 'clantag' => 'LSR'.$suffix]);
    $lineup = Lineup::factory()->mode('3v3')->ready()->create(['clan_id' => $clan->id]);
    $names = ['Hal', 'Nakamoto', 'Adam', 'Wei', 'Nick', 'Len', 'Gavin', 'Pieter', 'Lyn', 'Jameson', 'Elizabeth', 'Ragnar'];
    $opponent = fn (int $i): User => User::factory()->create(['name' => $names[$i % count($names)]]);

    // A daily game on Satoshi's move (White, no move yet), and one on the other side's.
    $myMove = ChessGame::factory()->daily()->create(['white_id' => $me->id, 'black_id' => $opponent(0)->id]);
    $theirMove = ChessGame::factory()->daily()->create(['white_id' => $opponent(1)->id, 'black_id' => $me->id]);

    $rating = Rating::query()->create(['pool' => Rating::CASUAL, 'season' => '', 'game' => 'chess', 'mode' => 'blitz', 'subject' => 'user:'.$me->id, 'user_id' => $me->id,
        'rating' => 1042, 'results' => $results, 'wins' => intdiv($results + 1, 2), 'draws' => $results > 2 ? 1 : 0, 'losses' => $results - intdiv($results + 1, 2) - ($results > 2 ? 1 : 0)]);

    foreach (range(1, $results) as $i) {
        $white = $i % 2 === 0;
        $result = match ($i % 3) {
            0 => '1/2-1/2',
            1 => $white ? '1-0' : '0-1',
            default => $white ? '0-1' : '1-0',
        };
        $other = $opponent($i + 1);
        $game = ChessGame::factory()->finished($result)->create([
            'white_id' => $white ? $me->id : $other->id, 'black_id' => $white ? $other->id : $me->id, 'ended_at' => now()->subHours($i), 'updated_at' => now()->subHours($i),
        ]);
        RatingChange::query()->create(['rating_id' => $rating->id, 'source' => RatingChange::CHESS, 'source_id' => $game->id, 'score' => 1, 'before' => 1030, 'after' => 1030 + 12, 'delta' => $i % 3 === 1 ? 12 : -9, 'results_before' => $i - 1]);
    }

    // A won Rocket League series of the lineup, half an hour ago.
    $won = SeriesMatch::factory()->create([
        'challenger_lineup_id' => $lineup->id, 'status' => SeriesStatus::Confirmed, 'winner' => 'challenger', 'start_at' => now()->subHour(), 'finished_at' => now()->subMinutes(30),
        'result_games' => [['challenger' => 3, 'challenged' => 1, 'winner' => 'challenger'], ['challenger' => 2, 'challenged' => 1, 'winner' => 'challenger']],
    ]);
    Rating::query()->create(['pool' => Rating::CASUAL, 'season' => '', 'game' => 'rocket-league', 'mode' => '3v3', 'subject' => 'lineup:'.$lineup->id, 'lineup_id' => $lineup->id,
        'rating' => 1016, 'results' => 1, 'wins' => 1, 'draws' => 0, 'losses' => 0]);

    // Signed up for the next tournament, three days out.
    $tournament = openTournament(['name' => 'Halving Cup', 'starts_at' => now()->addDays(3)]);
    TournamentSignup::query()->create(['tournament_id' => $tournament->id, 'user_id' => $me->id, 'name' => $me->displayName(), 'members' => [$me->id]]);

    // A casual 1v1 Satoshi sent (open), and one accepted for tomorrow evening.
    $at = now()->addDay()->setTime(20, 0)->getTimestamp();
    $sent = app(CasualChallenges::class)->challenge($me, $opponent(5), 'rocket-league', Platform::Pc, true, [$at], now()->addDay()->setTime(12, 0)->getTimestamp(), '');
    $scheduled = app(CasualChallenges::class)->challenge($opponent(6), $me, 'ea-sports-fc-26', Platform::Pc, true, [$at + 3600], now()->addDay()->setTime(12, 0)->getTimestamp(), '');
    $scheduled = app(CasualChallenges::class)->accept($scheduled, $me, $at + 3600, Platform::Pc, true);

    return ['me' => $me->refresh(), 'clan' => $clan, 'lineup' => $lineup, 'myMove' => $myMove, 'theirMove' => $theirMove, 'tournament' => $tournament, 'sent' => $sent, 'scheduled' => $scheduled->refresh(), 'won' => $won];
}
