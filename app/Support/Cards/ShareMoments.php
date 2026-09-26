<?php

namespace App\Support\Cards;

use App\Enums\TournamentStatus;
use App\Models\LineupSeat;
use App\Models\RankBadge;
use App\Models\RankBadgeVersion;
use App\Models\Rating;
use App\Models\Season;
use App\Models\SeasonAttestation;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\Badges\BadgeCopy;
use App\Support\Rating\RankTiers;
use App\Support\Tournaments\TournamentChampion;
use Illuminate\Support\Collection;

/**
 * The moments a player can share (P11, "Stolz und Teilen") and the facts each
 * share card draws:
 *
 * - rank up: a version of the player's rank badge that is a step up (the
 *   first tier counts: the rank reveal);
 * - block mined: a rated win that mined a block of the season chain, with the
 *   player among its winners ("every win is a block with you as the miner");
 * - tournament win: a finished tournament whose champion the player is part of;
 * - Season Wrapped: the player's season on one card, blocks, sats, best rank.
 *
 * Only rated results and chain blocks count; casual play has no moments here.
 */
final class ShareMoments
{
    /**
     * @return array{name: string, pubkey: string, avatar_path: string|null, tier: string, previous: string|null, rating: int, ladder: string, season: string}
     */
    public static function rankUp(RankBadgeVersion $version): array
    {
        $badge = $version->badge;
        $user = $badge->user;

        return [
            ...self::person($user, $badge->pubkey),
            'tier' => $version->tier,
            'previous' => $version->previous_tier,
            'rating' => $version->rating,
            'ladder' => BadgeCopy::ladder($badge->game, $badge->mode),
            'season' => $version->season,
        ];
    }

    /**
     * @return array{name: string, pubkey: string, avatar_path: string|null, height: int, reward: int, label: string, ladder: string, opponents: list<string>, personal_height: int, era: int, pending: bool}
     */
    public static function block(SeasonAttestation $block, User $miner): array
    {
        $losers = (array) ($block->candidate['losers'] ?? []);
        $names = User::query()->whereIn('pubkey', $losers)->get()->keyBy('pubkey');
        $opponents = array_map(fn (string $pubkey): string => $names->get($pubkey)?->displayName() ?? 'npub1…'.substr($pubkey, -4), array_slice(array_values(array_map(strval(...), $losers)), 0, 3));

        return [
            ...self::person($miner, $miner->pubkey),
            'height' => (int) $block->height,
            'reward' => $block->reward_per_player,
            'label' => $block->label,
            'ladder' => BadgeCopy::ladder($block->game, $block->mode),
            'opponents' => $opponents,
            'personal_height' => self::minedBy($miner, $block->season_id, (int) $block->height)->count(),
            'era' => (int) $block->era,
            'pending' => ! $block->season->ends_at->isPast(),
        ];
    }

    /**
     * @return array{tournament: string, winner: string, detail: string, members: list<array{name: string, pubkey: string, avatar_path: string|null}>}
     */
    public static function tournament(Tournament $tournament, TournamentParticipant $winner): array
    {
        $members = User::query()->whereKey($winner->memberIds())->get()->sortBy('id');

        return [
            'tournament' => $tournament->name,
            'winner' => $winner->name,
            'detail' => BadgeCopy::ladder($tournament->game, $tournament->mode).' · '.$tournament->format->label().' · '.trans_choice(':count entry|:count entries', $tournament->participants()->count()),
            'members' => array_values($members->map(fn (User $user): array => self::person($user, $user->pubkey))->all()),
        ];
    }

    /**
     * @return array{name: string, pubkey: string, avatar_path: string|null, season: string, live: bool, blocks: int, sats: int, wins: int, tournaments: int, best: array{tier: string, rating: int, ladder: string}|null}
     */
    public static function wrapped(Season $season, User $user): array
    {
        $blocks = self::minedBy($user, $season->id);
        $ratings = Rating::query()->where(['pool' => Rating::RATED, 'season' => $season->slug])
            ->where(fn ($query) => $query->where('user_id', $user->id)->orWhereIn('lineup_id', LineupSeat::query()->where('user_id', $user->id)->whereNotNull('accepted_at')->pluck('lineup_id')))
            ->get();
        $tiers = RankTiers::fromConfig();
        $order = array_keys($tiers->ascending());
        $best = null;

        foreach ($ratings as $rating) {
            $tier = $tiers->tierFor($rating->rating, $rating->results);
            $rank = array_search($tier, $order, true);

            if ($rank !== false && ($best === null || $rank > $best['rank'] || ($rank === $best['rank'] && $rating->rating > $best['rating']))) {
                $best = ['rank' => $rank, 'tier' => $tier, 'rating' => $rating->rating, 'ladder' => BadgeCopy::ladder($rating->game, $rating->mode)];
            }
        }

        return [
            ...self::person($user, $user->pubkey),
            'season' => $season->slug,
            'live' => ! $season->ends_at->isPast(),
            'blocks' => $blocks->count(),
            'sats' => (int) $blocks->sum('reward_per_player'),
            'wins' => (int) $ratings->sum('wins'),
            'tournaments' => count(self::tournamentWins($user, $season)),
            'best' => $best === null ? null : ['tier' => $best['tier'], 'rating' => $best['rating'], 'ladder' => $best['ladder']],
        ];
    }

    /**
     * Whether the player has a Season Wrapped card: rated results in that
     * season, their own (a player ladder) or their lineup's (gate F4: a card
     * is drawn and stored only for players who played).
     */
    public static function hasWrapped(Season $season, User $user): bool
    {
        return $season->genesis_at->isPast() && Rating::query()->where(['pool' => Rating::RATED, 'season' => $season->slug])->where('results', '>', 0)
            ->where(fn ($query) => $query->where('user_id', $user->id)
                ->orWhereIn('lineup_id', LineupSeat::query()->where('user_id', $user->id)->whereNotNull('accepted_at')->select('lineup_id')))
            ->exists();
    }

    /**
     * The player's rank-up versions, newest first.
     *
     * @return Collection<int, RankBadgeVersion>
     */
    public static function rankUpsOf(User $user, int $limit = 6): Collection
    {
        return RankBadgeVersion::query()
            ->whereIn('rank_badge_id', RankBadge::query()->where('pubkey', $user->pubkey)->select('id'))
            ->with('badge.user')->orderByDesc('signed_at')->orderByDesc('id')->limit(40)->get()
            ->filter(fn (RankBadgeVersion $version): bool => $version->isRankUp())->take($limit)->values();
    }

    /**
     * Blocks the player mined, newest first; in one season when given.
     *
     * @return Collection<int, SeasonAttestation>
     */
    public static function minedBy(User $user, ?int $seasonId = null, ?int $upToHeight = null): Collection
    {
        return SeasonAttestation::query()
            ->whereNotNull('height')
            ->when($seasonId !== null, fn ($query) => $query->where('season_id', $seasonId))
            ->when($upToHeight !== null, fn ($query) => $query->where('height', '<=', $upToHeight))
            ->where('candidate', 'like', '%'.$user->pubkey.'%')
            ->with('season')->orderByDesc('height')->get()
            ->filter(fn (SeasonAttestation $block): bool => in_array($user->pubkey, $block->winners(), true))->values();
    }

    /**
     * Finished tournaments the player won (their entry is the champion), newest first.
     *
     * @return list<array{tournament: Tournament, winner: TournamentParticipant}>
     */
    public static function tournamentWins(User $user, ?Season $season = null): array
    {
        $champions = app(TournamentChampion::class);
        $wins = [];
        $entries = TournamentParticipant::query()
            ->where(fn ($query) => $query->where('user_id', $user->id)->orWhere('members', 'like', '%'.$user->id.'%'))
            ->whereHas('tournament', fn ($query) => $query->where('status', TournamentStatus::Finished)
                ->when($season !== null, fn ($query) => $query->whereBetween('starts_at', [$season->genesis_at, $season->ends_at])))
            ->with('tournament')->get();

        foreach ($entries as $entry) {
            if (! in_array($user->id, $entry->memberIds(), true)) {
                continue;
            }

            $champion = $champions->of($entry->tournament);

            if ($champion?->id === $entry->id) {
                $wins[] = ['tournament' => $entry->tournament, 'winner' => $entry];
            }
        }

        usort($wins, fn (array $a, array $b): int => $b['tournament']->starts_at <=> $a['tournament']->starts_at);

        return $wins;
    }

    /**
     * @return array{name: string, pubkey: string, avatar_path: string|null}
     */
    private static function person(?User $user, string $pubkey): array
    {
        return ['name' => $user?->displayName() ?? 'npub1…'.substr($pubkey, -4), 'pubkey' => $pubkey, 'avatar_path' => $user?->avatar_path];
    }
}
