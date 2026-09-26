<?php

namespace App\Support\Engagement;

use App\Models\QuestCredit;
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
 * Idempotent: every credit is keyed by (player, quest, week, result) with a
 * unique index and written with insertOrIgnore. A result reported twice, a
 * double confirmation or a retried job counts once.
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

        $progress = [];

        foreach (self::TARGETS as $quest => $target) {
            $count = min($target, (int) ($counts[$quest] ?? 0));
            $progress[$quest] = ['progress' => $count, 'target' => $target, 'done' => $count >= $target];
        }

        return $progress;
    }
}
