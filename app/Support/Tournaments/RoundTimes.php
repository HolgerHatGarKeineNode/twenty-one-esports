<?php

namespace App\Support\Tournaments;

use App\Enums\TournamentStatus;
use App\Models\Tournament;
use App\Models\TournamentRound;

/**
 * How long rounds really took (P18, the measurement): a round starts with
 * its first match (`tournament_rounds.started_at`) and ends when it closes
 * (`closed_at`). Read-only; the profiles are not changed from it (user
 * decision 2026-09-27: show measured against assumed first).
 */
final class RoundTimes
{
    /**
     * The measured rounds of a tournament in play order, each with its
     * estimate: the typical online slot of its longest series (a Bo3 final
     * next to Bo1 rounds), the plan on site. Minute games only.
     *
     * @return list<array{stage: int, number: int, minutes: int, estimate: int}>
     */
    public static function forTournament(Tournament $tournament): array
    {
        $profile = $tournament->profile();

        if ($profile->isDaily()) {
            return [];
        }

        $bestOf = $tournament->formatOptions()->bestOf;
        $rounds = TournamentRound::query()->whereNotNull('started_at')->whereNotNull('closed_at')
            ->whereHas('stage', fn ($query) => $query->where('tournament_id', $tournament->id))
            ->with(['stage', 'matches.seriesMatch'])->get()
            ->sortBy(fn (TournamentRound $round): array => [$round->stage->number, $round->number])->values();

        return array_values($rounds->map(function (TournamentRound $round) use ($profile, $bestOf, $tournament): array {
            $longest = (int) ($round->matches->map(fn ($match) => $match->seriesMatch?->best_of)->filter()->max() ?? $bestOf);

            return [
                'stage' => $round->stage->number,
                'number' => $round->number,
                'minutes' => self::minutes($round),
                'estimate' => (int) round($tournament->on_site ? $profile->slot($longest) : $profile->onlineSlot($longest)),
            ];
        })->all());
    }

    /**
     * The median round of finished tournaments of a game and mode, in
     * minutes, and how many rounds it is taken over; null without any.
     *
     * @return array{minutes: float, rounds: int}|null
     */
    public static function median(string $game, string $mode): ?array
    {
        $minutes = TournamentRound::query()->whereNotNull('started_at')->whereNotNull('closed_at')
            ->whereHas('stage.tournament', fn ($query) => $query->where('status', TournamentStatus::Finished)->where('game', $game)->where('mode', $mode))
            ->get(['id', 'started_at', 'closed_at'])
            ->map(fn (TournamentRound $round): int => self::minutes($round))
            ->sort()->values();

        if ($minutes->isEmpty()) {
            return null;
        }

        return ['minutes' => (float) $minutes->median(), 'rounds' => $minutes->count()];
    }

    private static function minutes(TournamentRound $round): int
    {
        return (int) round($round->started_at->diffInSeconds($round->closed_at, true) / 60);
    }
}
