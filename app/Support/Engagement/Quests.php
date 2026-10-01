<?php

namespace App\Support\Engagement;

use App\Games\GameRegistry;
use App\Models\QuestCredit;
use App\Models\ScoreRun;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Weekly quests (P10), counted on the server from finished results.
 *
 * A result counts when it moved a rating (casual or rated), so the farming
 * guards of the ratings apply here as well: a pairing past its daily cap
 * earns no quest progress either. Weeks are ISO weeks in UTC.
 *
 * A score game has no rating (Blockfill live, 2026-10-01): each of its
 * verified runs set this week counts as one game for "play 3 games", read
 * from score_runs when progress is shown (ScoreRun rows are never
 * overwritten, so a run is counted once). A Blockfill practice run is never
 * verified and never becomes a score run; an unchecked, rejected or
 * director-entered value does not count, nor a game no longer registered.
 *
 * Idempotent: every credit is keyed by (player, quest, week, result) with a
 * unique index and written with insertOrIgnore. A result reported twice, a
 * double confirmation or a retried job counts once. A corrected result
 * (RatingService::correct()) is the same result: a credit already given
 * stays, the corrected side earns only what it had not earned yet.
 */
final class Quests
{
    /** Three results of any game this week. */
    public const THREE_GAMES = 'three-games';

    /** A win against an opponent rated higher before the game. */
    public const GIANT_SLAYER = 'giant-slayer';

    /** A result for your clan: a series of a clan lineup, or a rated game while in a clan. */
    public const FOR_THE_CLAN = 'for-the-clan';

    /** @var array<string, int> quest => target */
    public const TARGETS = [
        self::THREE_GAMES => 3,
        self::GIANT_SLAYER => 1,
        self::FOR_THE_CLAN => 1,
    ];

    public static function period(?CarbonInterface $at = null): string
    {
        return ($at ?? CarbonImmutable::now())->utc()->format('o-\WW');
    }

    /**
     * Credits one side of a result to its players.
     *
     * @param  list<int>  $players
     * @param  list<int>  $forClan  the players of this side who played for their clan
     * @return int the number of new credits
     */
    public function credit(string $source, array $players, bool $won, int $before, int $opponentBefore, array $forClan = [], ?CarbonInterface $at = null): int
    {
        $period = self::period($at);
        $rows = [];

        foreach ($players as $userId) {
            $quests = [self::THREE_GAMES];

            if ($won && $opponentBefore > $before) {
                $quests[] = self::GIANT_SLAYER;
            }

            if (in_array($userId, $forClan, true)) {
                $quests[] = self::FOR_THE_CLAN;
            }

            foreach ($quests as $quest) {
                $rows[] = ['user_id' => $userId, 'quest' => $quest, 'period' => $period, 'source' => $source, 'created_at' => now(), 'updated_at' => now()];
            }
        }

        return $rows === [] ? 0 : QuestCredit::query()->insertOrIgnore($rows);
    }

    /**
     * This week's quests of a player, in display order.
     *
     * @return array<string, array{progress: int, target: int, done: bool}>
     */
    public function progress(User $user): array
    {
        $counts = QuestCredit::query()
            ->where('user_id', $user->id)
            ->where('period', self::period())
            ->selectRaw('quest, count(*) as credits')
            ->groupBy('quest')
            ->pluck('credits', 'quest');

        $counts[self::THREE_GAMES] = (int) ($counts[self::THREE_GAMES] ?? 0) + $this->scoreRuns($user);
        $progress = [];

        foreach (self::TARGETS as $quest => $target) {
            $count = min($target, (int) ($counts[$quest] ?? 0));
            $progress[$quest] = ['progress' => $count, 'target' => $target, 'done' => $count >= $target];
        }

        return $progress;
    }

    /**
     * The player's verified runs of a registered score game set in this
     * week: no query while no score game is registered.
     */
    private function scoreRuns(User $user): int
    {
        $games = array_keys(app(GameRegistry::class)->scores());

        if ($games === []) {
            return 0;
        }

        $start = CarbonImmutable::now()->utc()->startOfWeek(CarbonInterface::MONDAY);

        return ScoreRun::query()
            ->where('user_id', $user->id)
            ->whereIn('game', $games)
            ->where('source', '!=', ScoreRun::DIRECTOR)
            ->whereNotNull('verified_at')->whereNull('rejected_at')->whereNotNull('value')
            ->where('achieved_at', '>=', $start)->where('achieved_at', '<', $start->addWeek())
            ->count();
    }
}
