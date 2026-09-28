<?php

namespace App\Support\Rating;

use App\Games\GameMode;
use App\Games\GameRegistry;
use App\Models\Clan;
use App\Models\NostrEvent;
use App\Models\Rating;
use App\Models\RatingChange;
use App\Models\SeasonAttestation;
use App\Models\SeriesMatch;
use App\Support\Clans\ClanStats;
use App\Support\Nostr\NostrKeys;
use App\Support\SeasonChain\Seasons;
use App\Support\Series\Ladders;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Everything the ladder page shows beside the rows themselves (P32), each in
 * a fixed number of queries whatever the ladder's size:
 *
 * - totals: entries, results, wins and the average rating of the ladder;
 * - share of wins: the five entries with the most wins and their share;
 * - form: the last five results of every listed entry (one window query);
 * - Block Height and Global Rating of the listed players (NIP "Global
 *   score"), for the rated ladder of the live season only, cached until the
 *   next rating change;
 * - the clan view: every clan with an entry on one of the game's ladders of
 *   this pool, its value per mode (a lineup's Elo on a lineup ladder, the
 *   top-three average of its members on a player ladder, NIP "Terminology");
 * - the last result and the Proof rows (the ladder event, the last
 *   attestation, the league key and relays).
 *
 * `season` is the pool's season: the live season's slug for rated, '' for
 * casual; the page does not build a board for a closed rated ladder.
 */
final class LadderBoard
{
    /** Rows the ladder lists at most (the page's own limit). */
    public const LIMIT = 200;

    /** Entries in the share-of-wins list. */
    public const SHARES = 5;

    /** Results in the form strip. */
    public const FORM = 5;

    /** Clans the clan view lists at most. */
    public const CLANS = 100;

    public function __construct(
        public readonly string $game,
        public readonly string $mode,
        public readonly string $pool,
        public readonly string $season,
    ) {}

    public function rated(): bool
    {
        return $this->pool === Rating::RATED;
    }

    /**
     * `games` counts each game or series once (a result moves two ratings).
     *
     * @return array{entries: int, results: int, wins: int, games: int, average: int|null}
     */
    public function totals(): array
    {
        $row = $this->ladder()->toBase()
            ->selectRaw('count(*) as entries, coalesce(sum(results), 0) as results, coalesce(sum(wins), 0) as wins, avg(rating) as average')
            ->first();

        $games = (int) RatingChange::query()->toBase()
            ->join('ratings', 'ratings.id', '=', 'rating_changes.rating_id')
            ->where(['ratings.pool' => $this->pool, 'ratings.season' => $this->season, 'ratings.game' => $this->game, 'ratings.mode' => $this->mode])
            ->whereNull('rating_changes.reverted_at')
            ->selectRaw("count(distinct rating_changes.source || ':' || rating_changes.source_id) as games")
            ->value('games');

        return [
            'entries' => (int) ($row->entries ?? 0),
            'results' => (int) ($row->results ?? 0),
            'wins' => (int) ($row->wins ?? 0),
            'games' => $games,
            'average' => isset($row->average) ? (int) round((float) $row->average) : null,
        ];
    }

    /**
     * The entries with the most wins, each with its share of all wins on the
     * ladder (0..1). Taken from the listed rows when they are the whole
     * ladder, else read apart (one query plus its eager loads).
     *
     * @param  Collection<int, Rating>  $rows  the listed rows
     * @return list<array{row: Rating, wins: int, share: float}>
     */
    public function shares(Collection $rows, int $entries, int $wins): array
    {
        if ($wins === 0) {
            return [];
        }

        $top = $entries <= $rows->count()
            ? $rows->filter(fn (Rating $row): bool => $row->wins > 0)->sortBy([['wins', 'desc'], ['rating', 'desc'], ['id', 'asc']])->take(self::SHARES)
            : $this->ladder()->where('wins', '>', 0)->with(['user.clanMember.clan', 'lineup.clan'])
                ->orderByDesc('wins')->orderByDesc('rating')->orderBy('id')->limit(self::SHARES)->get();

        return array_values($top->map(fn (Rating $row): array => ['row' => $row, 'wins' => $row->wins, 'share' => (float) ($row->wins / $wins)])->all());
    }

    /**
     * The last results of each rating, oldest first: 1 win, 0.5 draw, 0 loss.
     *
     * @param  list<int>  $ratingIds
     * @return array<int, list<float>>
     */
    public function form(array $ratingIds): array
    {
        if ($ratingIds === []) {
            return [];
        }

        $recent = RatingChange::query()
            ->select(['rating_id', 'score', 'id'])
            ->selectRaw('row_number() over (partition by rating_id order by created_at desc, revision desc, id desc) as recent')
            ->whereIn('rating_id', $ratingIds);

        $form = [];

        foreach (DB::query()->fromSub($recent, 'recent')->where('recent', '<=', self::FORM)->orderBy('rating_id')->orderByDesc('recent')->get() as $change) {
            $form[(int) $change->rating_id][] = (float) $change->score;
        }

        return $form;
    }

    /**
     * Block Height (all seasons) and Global Rating (this season) of the
     * players on the list; rated player ladders only. Cached until a rating
     * changes anywhere (the key carries the newest change).
     *
     * @param  list<int>  $userIds
     * @return array{heights: array<int, int>, global: array<int, int>}
     */
    public function globalScore(array $userIds): array
    {
        if (! $this->rated() || $userIds === []) {
            return ['heights' => [], 'global' => []];
        }

        $stamp = RatingChange::query()->withoutGlobalScope(RatingChange::LIVE)->toBase()
            ->selectRaw('max(id) as id, max(updated_at) as at, count(reverted_at) as reverted')->first();
        $key = 'ladder-score:'.$this->season.':'.$this->game.':'.$this->mode.':'.md5(implode(',', $userIds)).':'
            .($stamp->id ?? 0).':'.($stamp->at ?? '').':'.($stamp->reverted ?? 0);

        /** @var array{heights: array<int, int>, global: array<int, int>} */
        return Cache::remember($key, now()->addMinutes(10), fn (): array => [
            'heights' => ClanStats::blockHeights($userIds),
            'global' => array_intersect_key(self::globalRatings($this->season), array_flip($userIds)),
        ]);
    }

    /**
     * The Global Rating of every player with one in a season (NIP "Global
     * Rating"): per ladder with standings and per entity the player played
     * for, the weight (a player's results; on a lineup ladder the rated
     * series that list the player on that lineup's roster) and the entity's
     * percentile, through GlobalRating.
     *
     * @return array<int, int> user id => Global Rating
     */
    public static function globalRatings(string $season): array
    {
        $rows = Rating::query()->where(['pool' => Rating::RATED, 'season' => $season])->where('results', '>', 0)
            ->get(['id', 'game', 'mode', 'user_id', 'lineup_id', 'rating', 'wins', 'draws', 'losses']);

        if ($rows->isEmpty()) {
            return [];
        }

        $ladders = $rows->groupBy(fn (Rating $row): string => $row->game.'/'.$row->mode)
            ->map(fn (Collection $group): array => array_values($group->pluck('rating')->map(fn ($rating): int => (int) $rating)->all()));

        /** @var array<int, list<array{weight: int, rating: int, ladder: list<int>}>> $entries */
        $entries = [];

        foreach ($rows->whereNotNull('user_id') as $row) {
            $entries[(int) $row->user_id][] = ['weight' => $row->wins + $row->draws + $row->losses, 'rating' => $row->rating, 'ladder' => $ladders[$row->game.'/'.$row->mode]];
        }

        $lineupRows = $rows->whereNotNull('lineup_id')->keyBy('id');

        if ($lineupRows->isNotEmpty()) {
            $changes = RatingChange::query()->where('source', RatingChange::SERIES)->whereIn('rating_id', $lineupRows->keys())->get(['rating_id', 'source_id']);
            $matches = SeriesMatch::query()->with('latestReport')->whereKey($changes->pluck('source_id')->unique()->values())->get()->keyBy('id');
            $weights = [];

            foreach ($changes as $change) {
                $row = $lineupRows->get($change->rating_id);
                $match = $matches->get($change->source_id);

                if (! $row instanceof Rating || ! $match instanceof SeriesMatch) {
                    continue;
                }

                $side = (int) $match->challenger_lineup_id === (int) $row->lineup_id ? 'challenger' : 'challenged';

                foreach (array_unique(array_map(fn (array $entry): int => (int) $entry['user_id'], array_filter($match->countedRoster(), fn (array $entry): bool => $entry['side'] === $side))) as $userId) {
                    $weights[$userId][$row->id] = ($weights[$userId][$row->id] ?? 0) + 1;
                }
            }

            foreach ($weights as $userId => $perRating) {
                foreach ($perRating as $ratingId => $weight) {
                    $row = $lineupRows->get($ratingId);

                    if ($row instanceof Rating) {
                        $entries[$userId][] = ['weight' => $weight, 'rating' => $row->rating, 'ladder' => $ladders[$row->game.'/'.$row->mode]];
                    }
                }
            }
        }

        $engine = GlobalRating::fromConfig();
        $out = [];

        foreach ($entries as $userId => $list) {
            $value = $engine->compute($list);

            if ($value !== null) {
                $out[$userId] = $value;
            }
        }

        return $out;
    }

    /**
     * The clan view: every clan with an entry on one of the game's ladders
     * of this pool and season, its value in each mode and its rank among
     * the clans there, ordered by this mode's value (clans without one
     * last). A lineup ladder takes the clan's lineup's Elo, a player ladder
     * the average of its three best members (none below three).
     *
     * @return list<array{clan: Clan, modes: array<string, array{value: int|null, count: int, rank: int|null}>, wins: int}>
     */
    public function clans(): array
    {
        $registry = app(GameRegistry::class);
        $modes = $registry->get($this->game)->modes();

        $rows = Rating::query()->toBase()
            ->leftJoin('lineups', 'lineups.id', '=', 'ratings.lineup_id')
            ->leftJoin('clan_members', 'clan_members.user_id', '=', 'ratings.user_id')
            ->where(['ratings.pool' => $this->pool, 'ratings.season' => $this->season, 'ratings.game' => $this->game])
            ->where('ratings.results', '>', 0)
            ->whereRaw('coalesce(lineups.clan_id, clan_members.clan_id) is not null')
            ->selectRaw('ratings.mode, ratings.rating, ratings.wins, ratings.lineup_id, coalesce(lineups.clan_id, clan_members.clan_id) as clan_id')
            ->orderByDesc('ratings.rating')
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $engine = ClanRating::fromConfig();
        /** @var array<int, array<string, array{value: int|null, count: int}>> $values */
        $values = [];
        /** @var array<int, int> $wins */
        $wins = [];

        foreach ($rows->groupBy('clan_id') as $clanId => $group) {
            foreach ($modes as $slug => $mode) {
                $ratings = array_values($group->where('mode', $slug)->pluck('rating')->map(fn ($rating): int => (int) $rating)->all());
                $values[(int) $clanId][$slug] = [
                    'value' => $ratings === [] ? null : ($mode->rates === 'player' ? $engine->rating($ratings) : max($ratings)),
                    'count' => count($ratings),
                ];
            }

            $wins[(int) $clanId] = (int) $group->where('mode', $this->mode)->sum('wins');
        }

        // Rank per mode among the clans that have a value there.
        /** @var array<int, array<string, int>> $ranks */
        $ranks = [];

        foreach (array_keys($modes) as $slug) {
            $ranked = [];

            foreach ($values as $clanId => $perMode) {
                if ($perMode[$slug]['value'] !== null) {
                    $ranked[$clanId] = $perMode[$slug]['value'];
                }
            }

            arsort($ranked);
            $place = 0;

            foreach (array_keys($ranked) as $clanId) {
                $ranks[$clanId][$slug] = ++$place;
            }
        }

        $clans = Clan::query()->whereKey(array_keys($values))->get()->keyBy('id');
        $out = [];

        foreach ($values as $clanId => $perMode) {
            $clan = $clans->get($clanId);

            if (! $clan instanceof Clan) {
                continue;
            }

            $cells = [];

            foreach ($perMode as $slug => $cell) {
                $cells[$slug] = ['value' => $cell['value'], 'count' => $cell['count'], 'rank' => $ranks[$clanId][$slug] ?? null];
            }

            $best = max([0, ...array_map(fn (array $cell): int => $cell['value'] ?? 0, $perMode)]);
            $out[] = ['clan' => $clan, 'modes' => $cells, 'wins' => $wins[$clanId] ?? 0, 'best' => $best];
        }

        $mode = $this->mode;
        usort($out, fn (array $a, array $b): int => [$a['modes'][$mode]['rank'] ?? PHP_INT_MAX, $b['best'], $a['clan']->name]
            <=> [$b['modes'][$mode]['rank'] ?? PHP_INT_MAX, $a['best'], $b['clan']->name]);

        return array_map(fn (array $row): array => ['clan' => $row['clan'], 'modes' => $row['modes'], 'wins' => $row['wins']], array_slice($out, 0, self::CLANS));
    }

    /**
     * The newest result on this ladder: its match number and when it moved
     * the ratings.
     *
     * @return array{number: int|null, at: Carbon}|null
     */
    public function lastResult(): ?array
    {
        $change = RatingChange::query()
            ->join('ratings', 'ratings.id', '=', 'rating_changes.rating_id')
            ->where(['ratings.pool' => $this->pool, 'ratings.season' => $this->season, 'ratings.game' => $this->game, 'ratings.mode' => $this->mode])
            ->orderByDesc('rating_changes.id')
            ->first(['rating_changes.match_number', 'rating_changes.created_at']);

        return $change?->created_at === null ? null : ['number' => $change->match_number, 'at' => $change->created_at];
    }

    /**
     * The live season's context for the header: name, start and chain tip.
     *
     * @return array{slug: string, since: Carbon, height: int|null}|null
     */
    public function seasonContext(): ?array
    {
        $season = $this->rated() ? Seasons::live() : null;

        if ($season === null) {
            return null;
        }

        $height = $season->attestations()->max('height');

        return ['slug' => $season->slug, 'since' => $season->genesis_at, 'height' => $height === null ? null : (int) $height];
    }

    /**
     * Where to check this ladder: the rated ladder's event, its signer, the
     * newest attestation and the relays; a casual ladder has no events.
     *
     * @return list<array{0: string, 1: string}>
     */
    public function proof(): array
    {
        if (! $this->rated()) {
            return [[__('Record'), __('casual: no Nostr events, league data only')]];
        }

        $address = Ladders::address($this->game, $this->mode);

        if ($address === null) {
            return [[__('Record'), __('not published yet')]];
        }

        [, $pubkey, $d] = explode(':', $address, 3);
        $event = NostrEvent::query()->where(['kind' => Ladders::KIND, 'pubkey' => $pubkey, 'd' => $d])->orderByDesc('signed_at')->orderByDesc('id')->first(['signed_at']);
        $last = SeasonAttestation::query()->where('ladder_address', $address)->orderByDesc('id')->first(['event_id', 'match_number']);
        $relays = array_values((array) config('esports.relays', []));
        $short = fn (string $bech): string => substr($bech, 0, 12).'…'.substr($bech, -4);

        return [
            [__('Ladder record'), $short(NostrKeys::naddr(Ladders::KIND, $pubkey, $d)).' · kind 32152'],
            [__('Published'), $event === null ? __('not published yet') : Carbon::createFromTimestamp($event->signed_at)->diffForHumans()],
            [__('Signed by'), __('league key :npub', ['npub' => $short(NostrKeys::hexToNpub($pubkey))])],
            [__('Last league record'), $last === null ? __('none yet') : NostrKeys::shortNevent($last->event_id, $pubkey, 2154).' · kind 2154'.($last->match_number === null ? '' : ' · #'.$last->match_number)],
            [__('Relay'), $relays === [] ? __('No relay is set up on this server.') : implode(', ', $relays)],
        ];
    }

    /**
     * The tier a rating sits in by the thresholds alone (a provisional entry
     * sits between the same lines), and the label of its line.
     *
     * @return array{token: string, label: string, colour: string}
     */
    public static function line(int $rating): array
    {
        $tiers = RankTiers::fromConfig();
        $token = $tiers->tierFor($rating, PHP_INT_MAX);
        $ascending = $tiers->ascending();
        $minimum = $ascending[$token];
        $keys = array_keys($ascending);
        $next = $ascending[$keys[array_search($token, $keys, true) + 1] ?? $token];
        $label = $minimum <= 0 && $next > 0
            ? __(':tier below :elo', ['tier' => RankTiers::label($token), 'elo' => $next])
            : __(':tier from :elo', ['tier' => RankTiers::label($token), 'elo' => $minimum]);

        return ['token' => $token, 'label' => $label, 'colour' => RankTiers::colour($token)];
    }

    /**
     * @return Builder<Rating>
     */
    private function ladder(): Builder
    {
        return Rating::query()
            ->where(['pool' => $this->pool, 'season' => $this->season, 'game' => $this->game, 'mode' => $this->mode])
            ->where('results', '>', 0);
    }

    public function gameMode(): GameMode
    {
        return app(GameRegistry::class)->mode($this->game, $this->mode) ?? throw new \LogicException('Unknown ladder '.$this->game.'/'.$this->mode);
    }
}
