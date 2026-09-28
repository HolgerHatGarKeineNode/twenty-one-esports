<?php

namespace App\Support\SeasonChain;

use App\Games\GameRegistry;
use App\Models\Rating;
use App\Models\Season;
use App\Support\Rating\RankTiers;
use App\Support\Rating\RatingSettings;

/**
 * The review of an ended season on AdminSeason (P35): the champion of every
 * ladder, what the chain mined, and the season payouts made.
 *
 * A champion is the first standing of the ladder's final version (as
 * LadderEvents orders it: rating, then the older row), with at least one
 * rated result. Season payouts would be the paid ones only, never a wallet
 * balance; the season-chain payout (`2157`) and the settlement action are
 * not built (Settlement only computes what is owed), so the page states
 * that none were made.
 */
final class SeasonReview
{
    public function __construct(private GameRegistry $games, private SeasonChains $chains) {}

    /** The newest season whose end has passed, or null. */
    public static function latestEnded(): ?Season
    {
        return Season::query()->where('ends_at', '<=', now())->latest('ends_at')->first();
    }

    /**
     * @return array{champions: list<array{ladder: string, name: string, rating: int, results: int, tier: string}>, blocks: int, mined: int, remaining: int, supply: int, rejected: int}
     */
    public function of(Season $season): array
    {
        $settings = RatingSettings::forSeason($season);
        $tiers = new RankTiers($settings['tiers'], $settings['rating']['provisional']);
        $champions = [];

        foreach ($this->games->all() as $game) {
            foreach ($game->modes() as $mode) {
                /** @var Rating|null $top */
                $top = Rating::query()->with(['user', 'lineup.clan'])
                    ->where(['pool' => Rating::RATED, 'season' => $season->slug, 'game' => $game->slug(), 'mode' => $mode->slug])
                    ->where('results', '>', 0)
                    ->orderByDesc('rating')->orderBy('id')
                    ->first();

                if ($top === null) {
                    continue;
                }

                $champions[] = [
                    'ladder' => ChainOverview::keyLabel($game->slug().'/'.$mode->slug),
                    'name' => self::entityName($top),
                    'rating' => $top->rating,
                    'results' => $top->results,
                    'tier' => $tiers->tierFor($top->rating, $top->results),
                ];
            }
        }

        $chain = $this->chains->chain($season);

        return [
            'champions' => $champions,
            'blocks' => $season->attestations()->whereNotNull('height')->count(),
            'mined' => $chain->mined(),
            'remaining' => $chain->remaining(),
            'supply' => $season->supply,
            'rejected' => $season->attestations()->whereNotNull('candidate')->whereNull('height')->count(),
        ];
    }

    /** The player's display name, or the clan of a lineup. */
    public static function entityName(Rating $rating): string
    {
        return $rating->user?->displayName() ?? $rating->lineup?->clan->name ?? $rating->subject;
    }
}
