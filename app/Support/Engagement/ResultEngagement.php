<?php

namespace App\Support\Engagement;

use App\Models\ChessGame;
use App\Models\RatingChange;
use App\Models\SeriesMatch;
use App\Models\User;
use App\Support\Rating\RatingService;
use Throwable;

/**
 * What a finished result does besides the ratings (P10): the placement
 * reveal and the weekly quests of the players on both sides.
 *
 * {@see RatingService} calls this after the result's
 * transaction committed. A failure here is reported and swallowed: a quest
 * or a reveal is decoration, and must never undo or block a result. Both
 * writes are idempotent, so running this twice for a result changes
 * nothing.
 */
final class ResultEngagement
{
    public function __construct(
        private readonly Placements $placements,
        private readonly Quests $quests,
    ) {}

    public function handle(RatingChange $challenger, RatingChange $challenged): void
    {
        foreach ([[$challenger, $challenged], [$challenged, $challenger]] as [$own, $opponent]) {
            try {
                [$players, $forClan] = $this->playersOf($own);

                $this->placements->record($own, $players);
                $this->quests->credit($own->source.':'.$own->source_id, $players, $own->score === 1.0, $own->before, $opponent->before, $forClan);
            } catch (Throwable $error) {
                report($error);
            }
        }
    }

    /**
     * The players of this side of the result, and those of them who played
     * for their clan.
     *
     * - chess: the player; for the clan when the rated game pinned their clan
     *   at the pairing (it counts for the clan's hashrate).
     * - a series: the roster the result counts for this side, else the
     *   lineup's active seats, else the roster side's players; for the clan
     *   when the side is a clan lineup.
     *
     * @return array{0: list<int>, 1: list<int>}
     */
    private function playersOf(RatingChange $change): array
    {
        $rating = $change->rating;

        if ($change->source === RatingChange::CHESS) {
            $game = ChessGame::query()->find($change->source_id);
            $user = $rating->user_id === null ? null : User::query()->find($rating->user_id);

            if ($game === null || $user === null) {
                return [[], []];
            }

            $inClan = $game->rated && isset(($game->clans_at_accept ?? [])[$user->pubkey]);

            return [[$user->id], $inClan ? [$user->id] : []];
        }

        $match = SeriesMatch::query()->with(['challengerLineup.seats', 'challengedLineup.seats', 'latestReport'])->find($change->source_id);

        if ($match === null) {
            return [[], []];
        }

        $side = $this->sideOf($match, $change);

        if ($side === null) {
            return [$rating->user_id === null ? [] : [$rating->user_id], []];
        }

        $roster = array_values(array_unique(array_map(
            fn (array $entry): int => (int) $entry['user_id'],
            array_filter($match->countedRoster(), fn (array $entry): bool => $entry['side'] === $side),
        )));
        $lineup = $match->lineup($side);

        $players = match (true) {
            $roster !== [] => $roster,
            $lineup !== null => array_map(fn ($seat): int => $seat->user_id, $lineup->activeSeats()),
            default => $match->rosterSide($side),
        };

        return [$players, $lineup !== null ? $players : []];
    }

    /**
     * Which side of the series this rating change belongs to: by the
     * lineup of a lineup rating, else by the player of a player rating.
     */
    private function sideOf(SeriesMatch $match, RatingChange $change): ?string
    {
        $rating = $change->rating;

        foreach (SeriesMatch::SIDES as $side) {
            if ($rating->lineup_id !== null && $match->lineup($side)?->id === $rating->lineup_id) {
                return $side;
            }

            if ($rating->user_id !== null) {
                $onRoster = collect($match->countedRoster())->contains(fn (array $entry): bool => $entry['side'] === $side && (int) $entry['user_id'] === $rating->user_id);

                if ($onRoster || in_array($rating->user_id, $match->rosterSide($side), true) || $match->lineup($side)?->activeSeatOf(User::query()->find($rating->user_id)) !== null) {
                    return $side;
                }
            }
        }

        return null;
    }
}
