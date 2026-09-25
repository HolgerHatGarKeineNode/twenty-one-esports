<?php

namespace Database\Seeders;

use App\Enums\LineupRole;
use App\Enums\ReportStatus;
use App\Enums\SeriesResolution;
use App\Enums\SeriesStatus;
use App\Models\Clan;
use App\Models\Lineup;
use App\Models\LineupSeat;
use App\Models\MatchNumber;
use App\Models\SeriesMatch;
use App\Models\SeriesReport;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Rocket League series of the sample ledger (SAMPLE-LEDGER.md sections 4
 * and 5: #354, #359, #361, #374, #398, #402, #403, #411, #412) for local
 * development only, on the clans of ClanSeeder. All casual (before Block 0
 * no series is rated) and without signed events. Times are relative to now,
 * so every state shows. Idempotent: an existing number is skipped.
 */
class SeriesMatchSeeder extends Seeder
{
    /** number => [challenger, challenged, mode, bo, state, hours from now to start, games, reason] */
    private const SERIES = [
        354 => ['STK', 'LNB', '2v2', 3, 'disputed', -150, [[3, 1], [1, 3], [2, 1]], 'A substitute played who was not on the roster.'],
        359 => ['HDL', 'B21', '2v2', 3, 'confirmed', -125, [[null, null, 'challenger'], [null, null, 'challenger']], null],
        361 => ['OPS', 'MMP', '3v3', 5, 'disputed', -100, [[3, 1], [1, 2], [2, 0], [3, 2]], 'Game 4 was 2 : 3 for us, we equalized in overtime.'],
        374 => ['OPS', 'B21', '3v3', 5, 'confirmed', -50, [[2, 1], [1, 3], [3, 2], [0, 2], [4, 3]], null],
        398 => ['B21', 'LSR', '3v3', 3, 'open', 44, [], null],
        402 => ['LSR', 'MMP', '3v3', 5, 'reported', -1.5, [[3, 1], [1, 3], [2, 1], [3, 2]], null],
        403 => ['HDL', 'STK', '2v2', 3, 'confirmed', -2.5, [[2, 1], [1, 2], [3, 1]], null],
        411 => ['B21', 'STK', '3v3', 3, 'accepted', 0.7, [], null],
        412 => ['LSR', 'HDL', '2v2', 3, 'accepted', -0.3, [[2, 1]], null],
    ];

    /**
     * @return array<int, array{0: string, 1: string, 2: string, 3: int, 4: string, 5: float|int, 6: list<list<int|string|null>>, 7: string|null}>
     */
    private function series(): array
    {
        return self::SERIES;
    }

    public function run(): void
    {
        $now = CarbonImmutable::now()->startOfMinute();

        foreach ($this->series() as $number => [$a, $b, $mode, $bo, $state, $hours, $games, $reason]) {
            if (SeriesMatch::query()->where('number', $number)->exists()) {
                continue;
            }

            $challenger = $this->lineup($a, $mode);
            $challenged = $this->lineup($b, $mode);

            if ($challenger === null || $challenged === null) {
                continue;
            }

            $start = $now->addMinutes((int) round($hours * 60));
            $created = $state === 'open' ? $now->subHours(18) : $start->subHours(12);
            MatchNumber::query()->insert(['id' => $number, 'user_id' => $challenger->clan->owner_id, 'used_at' => $created, 'created_at' => $created, 'updated_at' => $created]);

            $games = array_map(fn (array $g) => [
                'winner' => $g[2] ?? ($g[0] > $g[1] ? 'challenger' : 'challenged'),
                'challenger' => $g[0],
                'challenged' => $g[1],
            ], $games);
            $wins = SeriesMatch::seriesScore($games);
            $finished = in_array($state, ['confirmed'], true) ? $start->addMinutes(50) : null;

            $match = SeriesMatch::query()->create([
                'number' => $number,
                'game' => 'rocket-league',
                'mode' => $mode,
                'best_of' => $bo,
                'rated' => false,
                'challenger_lineup_id' => $challenger->id,
                'challenged_lineup_id' => $challenged->id,
                'challenger_name' => $challenger->clan->name,
                'challenged_name' => $challenged->clan->name,
                'challenger_tag' => $challenger->clan->clantag,
                'challenged_tag' => $challenged->clan->clantag,
                'challenger_lineup_address' => $challenger->address(),
                'challenged_lineup_address' => $challenged->address(),
                'created_by_id' => $challenger->clan->owner_id,
                'status' => match ($state) {
                    'open' => SeriesStatus::Open,
                    'accepted' => SeriesStatus::Accepted,
                    'reported' => SeriesStatus::Reported,
                    'disputed' => SeriesStatus::Disputed,
                    default => SeriesStatus::Confirmed,
                },
                'proposals' => [$start->getTimestamp()],
                'respond_by' => $state === 'open' ? $start->subHour() : $start->subHours(2),
                'start_at' => $state === 'open' ? null : $start,
                'answered_by_id' => $state === 'open' ? null : $challenged->clan->owner_id,
                'answered_at' => $state === 'open' ? null : $start->subHours(3),
                'lobby_name' => $state === 'open' ? null : 'e21-'.strtolower($a.'-'.$b),
                'lobby_password' => $state === 'open' ? null : 'local-'.$number,
                'lobby_region' => $state === 'open' ? null : 'EU',
                'live_games' => $games === [] ? null : $games,
                'result_games' => $finished ? $games : null,
                'winner' => $finished ? ($wins['challenger'] > $wins['challenged'] ? 'challenger' : 'challenged') : null,
                'resolution' => $finished ? SeriesResolution::Confirmed : null,
                'finished_at' => $finished,
            ]);
            $match->forceFill(['created_at' => $created, 'updated_at' => $finished ?? $start->addMinutes(55)])->save();

            if (in_array($state, ['reported', 'disputed', 'confirmed'], true)) {
                SeriesReport::query()->create([
                    'series_match_id' => $match->id,
                    'user_id' => $challenger->clan->owner_id,
                    'side' => 'challenger',
                    'games' => $games,
                    'roster' => [...$this->roster($challenger, 'challenger'), ...$this->roster($challenged, 'challenged')],
                    'status' => match ($state) {
                        'reported' => ReportStatus::Open,
                        'disputed' => ReportStatus::Disputed,
                        default => ReportStatus::Confirmed,
                    },
                    'responded_by_id' => $state === 'reported' ? null : $challenged->clan->owner_id,
                    'response_reason' => $reason,
                    'responded_at' => $state === 'reported' ? null : $start->addMinutes(52),
                ])->forceFill(['created_at' => $start->addMinutes(44)])->save();
            }
        }
    }

    private function lineup(string $tag, string $mode): ?Lineup
    {
        $clan = Clan::query()->where('clantag', $tag)->first();

        return $clan === null ? null : Lineup::query()->with(['clan', 'seats.user'])->where(['clan_id' => $clan->id, 'game' => 'rocket-league', 'mode' => $mode])->first();
    }

    /**
     * @return list<array{user_id: int, pubkey: string, name: string, side: string, role: string}>
     */
    private function roster(Lineup $lineup, string $side): array
    {
        return array_values($lineup->seats
            ->filter(fn (LineupSeat $seat) => $seat->role !== LineupRole::Substitute && $seat->accepted_at !== null)
            ->map(fn (LineupSeat $seat) => ['user_id' => $seat->user_id, 'pubkey' => $seat->user->pubkey, 'name' => $seat->user->displayName(), 'side' => $side, 'role' => $seat->role->value])
            ->all());
    }
}
