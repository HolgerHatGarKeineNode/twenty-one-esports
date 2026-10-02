<?php

namespace App\Support\Cards;

use App\Enums\TournamentStatus;
use App\Games\GameRegistry;
use App\Models\LineupSeat;
use App\Models\RankBadge;
use App\Models\RankBadgeVersion;
use App\Models\Rating;
use App\Models\ScoreRun;
use App\Models\Season;
use App\Models\SeasonAttestation;
use App\Models\StackerRun;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\Badges\BadgeCopy;
use App\Support\Prizes\PrizePool;
use App\Support\Rating\RankTiers;
use App\Support\Scores\ScoreRuns;
use App\Support\Scores\ScoreStanding;
use App\Support\Stacker\BlockfillRules;
use App\Support\Tournaments\Lobbies;
use App\Support\Tournaments\TournamentChampion;
use App\Support\Tournaments\TournamentSignups;
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
 * - Season Wrapped: the player's season on one card, blocks, sats, best rank;
 * - a Blockfill moment: a verified run that is a personal best, a new first
 *   place of its week, or holds the player's week place
 *   (App\Support\Stacker\BlockfillMoments);
 * - a TMNF moment, the same for a finish on our own server
 *   (App\Support\Tmnf\TmnfMoments).
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
     * A block of a score window (plan "Blockfill", P7) has no ladder and no
     * loser: `ladder` is the game, `window` the window's name in the page's
     * language and `value` the winning value; `opponents` are the next two
     * places of the window (scoreWindow()).
     *
     * @return array{name: string, pubkey: string, avatar_path: string|null, height: int, reward: int, label: string, ladder: string, opponents: list<string>, personal_height: int, era: int, pending: bool, window?: string, value?: string|null}
     */
    public static function block(SeasonAttestation $block, User $miner): array
    {
        $losers = (array) ($block->candidate['losers'] ?? []);
        $names = User::query()->whereIn('pubkey', $losers)->get()->keyBy('pubkey');
        $opponents = array_map(fn (string $pubkey): string => $names->get($pubkey)?->displayName() ?? 'npub1…'.substr($pubkey, -4), array_slice(array_values(array_map(strval(...), $losers)), 0, 3));
        $window = $block->source === SeasonAttestation::SCORE ? self::scoreWindow($block) : null;

        return [
            ...self::person($miner, $miner->pubkey),
            'height' => (int) $block->height,
            'reward' => $block->reward_per_player,
            'label' => $block->label,
            'ladder' => $window === null ? BadgeCopy::ladder($block->game, $block->mode) : app(GameRegistry::class)->name($block->game),
            'opponents' => $window['next'] ?? $opponents,
            'personal_height' => self::minedBy($miner, $block->season_id, (int) $block->height)->count(),
            'era' => (int) $block->era,
            'pending' => ! $block->season->ends_at->isPast(),
            ...($window === null ? [] : ['window' => $window['tournament']?->title() ?? $block->label, 'value' => $window['value']]),
        ];
    }

    /**
     * What a score window's block names besides its miner: the window's
     * tournament (null once it is gone), the winning value as its game
     * writes it (null when the board kept none), and the players on places
     * 2 and 3 as the end froze them.
     *
     * @return array{tournament: Tournament|null, value: string|null, next: list<string>}
     */
    public static function scoreWindow(SeasonAttestation $block): array
    {
        $tournament = Tournament::query()->find($block->source_id);

        if ($tournament === null) {
            return ['tournament' => null, 'value' => null, 'next' => []];
        }

        $runs = app(ScoreRuns::class);
        $placed = array_values(array_filter($runs->standings($tournament), fn (ScoreStanding $standing): bool => $standing->place !== null));
        $next = array_slice($placed, 1, 2);
        $users = User::query()->whereKey(array_filter(array_map(fn (ScoreStanding $standing): ?int => $standing->participant->user_id, $next)))->get()->keyBy('id');
        $value = $placed[0]->value ?? null;

        return [
            'tournament' => $tournament,
            'value' => $value === null ? null : $runs->metricOf($tournament)->format($value),
            'next' => array_map(fn (ScoreStanding $standing): string => $users->get($standing->participant->user_id)?->displayName() ?? $standing->participant->name, $next),
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
            // A lobby tournament (P10): its game and "One lobby match", never its 1v1 ladder.
            'detail' => (Lobbies::isLobby($tournament) ? Lobbies::gameLine($tournament) : BadgeCopy::ladder($tournament->game, $tournament->mode)).' · '.Lobbies::formatLabel($tournament).' · '.trans_choice(':count entry|:count entries', $tournament->participants()->count()),
            'members' => array_values($members->map(fn (User $user): array => self::person($user, $user->pubkey))->all()),
        ];
    }

    /**
     * The invite card of a published tournament (its link preview): what it
     * is, when it starts and how many places are taken. Raw values; the card
     * translates them when it draws.
     *
     * @return array{tournament: string, game: string, mode: string, format: string, lobby: bool, status: string, starts: string, taken: int, places: int, cover: string|null, pot: int|null, first_prize: int|null}
     */
    public static function tournamentInvite(Tournament $tournament): array
    {
        $places = app(TournamentSignups::class)->places($tournament);

        return [
            'tournament' => $tournament->name,
            'game' => $tournament->game,
            'mode' => $tournament->mode,
            'format' => $tournament->format->value,
            // A lobby tournament (P10) draws "One lobby match" without its mode.
            'lobby' => Lobbies::isLobby($tournament),
            'status' => $tournament->isSignupOpen() ? 'open' : $tournament->status->value,
            'starts' => $tournament->starts_at->copy()->timezone((string) config('esports.preseason.display_timezone'))->format('Y-m-d H:i T'),
            'taken' => $places['taken'],
            'places' => $places['places'],
            // The cover's file name, not just whether there is one: a new cover file draws a new card.
            'cover' => ($cover = app(GameRegistry::class)->coverPath($tournament->game)) === null ? null : basename($cover),
            // The prize pot as the tournament page shows it (P9): the pot, and in fixed mode what 1st place wins; null without an open pot.
            'pot' => $pot = ($tournament->pool_opened_at === null ? null : app(PrizePool::class)->potSats($tournament)),
            'first_prize' => $pot !== null && $tournament->prizeMode() === Tournament::PRIZES_FIXED ? ($tournament->prizeFixed()[0] ?? null) : null,
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
     * A Blockfill moment (App\Support\Stacker\BlockfillMoments::of()) of a
     * verified run: the player, the verified time in ticks, the blocks of its
     * rules (`goal`) and what it stands for.
     *
     * @param  array{kind: string, place: int|null, final: bool, pb: bool, first: bool, week: string}  $moment
     * @return array{name: string, pubkey: string, avatar_path: string|null, kind: string, place: int|null, final: bool, pb: bool, first: bool, week: string, ticks: int, goal: int}
     */
    public static function blockfill(StackerRun $run, array $moment): array
    {
        return [
            ...self::person($run->user, $run->user->pubkey ?? str_repeat('0', 64)),
            ...$moment,
            'ticks' => (int) $run->ticks,
            'goal' => BlockfillRules::goal($run->engine),
        ];
    }

    /**
     * A TMNF moment of a counted finish (App\Support\Tmnf\TmnfMoments::of()):
     * the player by their league name, the time in milliseconds, the track.
     *
     * @param  array{kind: string, place: int|null, final: bool, pb: bool, first: bool, week: string, track: string}  $moment
     * @return array<string, mixed>
     */
    public static function tmnf(ScoreRun $run, array $moment): array
    {
        return [
            ...self::person($run->user, $run->user->pubkey ?? str_repeat('0', 64)),
            ...$moment,
            'ms' => (int) $run->value,
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
            // A Blockfill week (plan "Blockfill", P6) is no tournament win.
            ->whereHas('tournament', fn ($query) => $query->where('status', TournamentStatus::Finished)->exceptLeagueWeeks()
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
