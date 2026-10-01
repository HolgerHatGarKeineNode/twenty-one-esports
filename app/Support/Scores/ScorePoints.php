<?php

namespace App\Support\Scores;

use App\Enums\TournamentFormat;
use App\Enums\TournamentStatus;
use App\Games\ScoreGame;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Support\Payouts\TournamentPlacements;
use Carbon\CarbonInterface;

/**
 * The ladder of a score game (plan "AoE2 und Trackmania", P4): points per
 * place of every finished leaderboard, summed per player (F1 and Cup of the
 * Day style). No Elo: a leaderboard of N players against the clock is no
 * series of duels. The table is the game's own or `esports.score_games.points`
 * (defaults, the user confirms the values); a place beyond it scores 0, and
 * players sharing a place share the points of the places they hold.
 *
 * The places come from the same path the payouts read
 * (TournamentPlacements), so an entry without a valid value, which is left
 * unplaced there, scores nothing here either. Read, not stored: a correction
 * before the end moves the ladder with it.
 */
final class ScorePoints
{
    public function __construct(private TournamentPlacements $placements) {}

    /**
     * @return list<int>
     */
    public static function table(ScoreGame $game): array
    {
        return array_values(array_map(intval(...), $game->pointsTable() ?? (array) config('esports.score_games.points', [])));
    }

    /**
     * Points per user id of one finished leaderboard (empty for anything else).
     *
     * @return array<int, int>
     */
    public function awarded(Tournament $tournament, ScoreGame $game): array
    {
        if ($tournament->format !== TournamentFormat::Leaderboard || $tournament->game !== $game->slug()) {
            return [];
        }

        $places = $this->placements->of($tournament) ?? [];
        $table = self::table($game);
        $users = TournamentParticipant::query()->where('tournament_id', $tournament->id)->pluck('user_id', 'id');
        $points = [];

        foreach ($places as ['place' => $place, 'participants' => $ids]) {
            $sum = 0;

            for ($index = $place - 1; $index < $place - 1 + count($ids); $index++) {
                $sum += $table[$index] ?? 0;
            }

            $each = intdiv($sum, count($ids));

            foreach ($ids as $id) {
                $user = $users[$id] ?? null;

                if ($user !== null && $each > 0) {
                    $points[(int) $user] = ($points[(int) $user] ?? 0) + $each;
                }
            }
        }

        return $points;
    }

    /**
     * The ladder of a game and mode: points per user id, most first, from
     * the leaderboards that finished in [from, to) (all when null).
     *
     * @return array<int, int>
     */
    public function ladder(ScoreGame $game, string $mode, ?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        $totals = [];
        $tournaments = Tournament::query()->where(['game' => $game->slug(), 'mode' => $mode, 'status' => TournamentStatus::Finished, 'format' => TournamentFormat::Leaderboard])
            ->when($from !== null, fn ($query) => $query->where('starts_at', '>=', $from))
            ->when($to !== null, fn ($query) => $query->where('starts_at', '<', $to))
            ->orderBy('starts_at')->get();

        foreach ($tournaments as $tournament) {
            foreach ($this->awarded($tournament, $game) as $user => $points) {
                $totals[$user] = ($totals[$user] ?? 0) + $points;
            }
        }

        arsort($totals);

        return $totals;
    }
}
