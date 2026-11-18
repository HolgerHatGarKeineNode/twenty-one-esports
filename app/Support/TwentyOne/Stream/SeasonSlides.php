<?php

namespace App\Support\TwentyOne\Stream;

use App\Games\GameRegistry;
use App\Models\FairPlayVoid;
use App\Models\Season;
use App\Models\SeasonAttestation;
use App\Models\SeasonBlockVoid;
use App\Models\User;
use App\Support\Badges\BadgeCopy;
use App\Support\GameNames;
use App\Support\Matches\MatchBlocks;
use App\Support\Rating\RatingSettings;
use App\Support\SeasonChain\ChainDraft;
use App\Support\SeasonChain\ChainOverview;
use App\Support\SeasonChain\SeasonChains;
use App\Support\SeasonChain\SeasonParameters;
use App\Support\SeasonChain\SeasonRelease;
use App\Support\SeasonChain\Seasons;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The season scenes of the stream (plan "Season 1 readiness", stream scenes): the countdown to Block 0 (s1), the
 * launch right after it (s2) and one scene per game that mines (s3), and what the rotation needs to schedule them
 * (entries()).
 *
 * Every figure is the league's own: the planned Block 0 is the chain draft's (ChainDraft::block0At(), set by the
 * board on /admin/season), the supply, shares and genesis message the released season's or, before Block 0, the
 * draft's; a game's era, halving distance and per-mode pay are ChainOverview::keys() over the same parameters
 * /mining reads, so the slide and the page never disagree. A game gets a scene only while one of its weights can
 * mine (ChainOverview::mines(): switched on, rated play offered). A voided block never shows (SeasonBlockVoid,
 * FairPlayVoid), as on the mempool slide.
 *
 * read() is cached `twentyone.stream.stats.cache_seconds` as plain scalars and arrays (no Carbon, no models); the
 * countdown, the "ago" of a block and the images are framed per frame without a query. Stream copy is English.
 */
final class SeasonSlides
{
    /** The countdown to Block 0, its teaser without a date, its "any moment now" once the date has passed. */
    public const COUNTDOWN = 's1';

    /** The launch, for `season_launch_hours` after Block 0. */
    public const LAUNCH = 's2';

    /** One game that mines: what a win pays now, its era, the next halving, its latest block. */
    public const GAME = 's3';

    /** @var list<string> */
    public const SCENES = [self::COUNTDOWN, self::LAUNCH, self::GAME];

    public const CACHE_KEY = 'twentyone.stream.season';

    /** No season and no plan: the stream stays as it was. */
    public const NONE = 'none';

    /** Before Block 0 (the Pre-Season's draft, or a planned next season). */
    public const PRE = 'pre';

    /** A season is live. */
    public const LIVE = 'live';

    /** @var array<string, string|null> cover path => data URI, read once per path */
    private static array $covers = [];

    public function __construct(private GameRegistry $games, private StreamImages $images) {}

    /**
     * The cached read (a failing cache store reads fresh; a failing database throws to the caller, the daemon's
     * poll, which logs it once and shows no season scene).
     *
     * @return array<string, mixed>
     */
    public function state(): array
    {
        $seconds = max(1, (int) config('twentyone.stream.stats.cache_seconds', 15));

        try {
            $data = Cache::remember(self::CACHE_KEY, $seconds, fn (): array => $this->read());
        } catch (Throwable) {
            $data = $this->read();
        }

        return is_array($data) ? $data : $this->read();
    }

    /**
     * What the rotation schedules now (RotationPlanner::at() `$season`): the lead scene (s1 before Block 0, s2 in
     * the launch window, else none), the games that mine by their position in GameRegistry::listed(), and whether
     * the season leads the rotation (`boost`: live, Block 0 overdue, or due within `season_lead_days`). Before an
     * undated or a far Block 0 only the countdown slide shows, in place of a teaser.
     *
     * @param  array<string, mixed>  $state  state()
     * @return array{lead: string|null, games: list<int>, boost: bool}
     */
    public static function entries(array $state, int $now): array
    {
        $phase = $state['phase'] ?? self::NONE;
        $games = array_values(array_map(fn (array $game): int => (int) $game['index'], (array) ($state['games'] ?? [])));

        if ($phase === self::LIVE) {
            $genesis = (int) ($state['genesis'] ?? 0);
            $window = (int) round((float) config('twentyone.stream.rotation.season_launch_hours', 12) * 3600);

            return ['lead' => $now >= $genesis && $now < $genesis + $window ? self::LAUNCH : null, 'games' => $games, 'boost' => true];
        }

        if ($phase === self::PRE) {
            $block0 = is_int($state['block0'] ?? null) ? $state['block0'] : null;
            $lead = (int) round((float) config('twentyone.stream.rotation.season_lead_days', 7) * 86400);
            $boost = $block0 !== null && $block0 - $now <= $lead;

            return ['lead' => self::COUNTDOWN, 'games' => $boost ? $games : [], 'boost' => $boost];
        }

        return ['lead' => null, 'games' => [], 'boost' => false];
    }

    /**
     * A season scene's data at `$nowMs`; `$game` is the game's position in GameRegistry::listed() for s3.
     *
     * @param  array<string, mixed>  $stats
     * @return array<string, mixed>
     */
    public function scene(string $scene, ?int $game, int $nowMs, array $stats): array
    {
        $state = $this->state();
        $brand = $this->images->backdrop(StreamImages::BRAND);
        $base = ['season' => self::frameSeason($state, $nowMs), 'stats' => $stats];

        if ($scene !== self::GAME) {
            return [...$base, 'backdrop' => $brand];
        }

        $row = collect((array) ($state['games'] ?? []))->firstWhere('index', $game);

        if (! is_array($row)) {
            return [...$base, 'game' => null, 'backdrop' => $brand];
        }

        return [...$base, 'game' => $this->frameGame($row, $nowMs),
            'backdrop' => $this->images->backdrop((string) $row['slug']) ?? $brand];
    }

    /**
     * The season part every scene shares, with the clock applied: `clock` is `countdown` (Block 0 ahead, `countdown`
     * "6d 06:05:01"), `due` (the date has passed and no season is live yet) or `soon` (no date); null when live.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public static function frameSeason(array $state, int $nowMs): array
    {
        $block0 = is_int($state['block0'] ?? null) ? $state['block0'] : null;
        $live = ($state['phase'] ?? self::NONE) === self::LIVE;
        $clock = match (true) {
            $live => null,
            $block0 === null => 'soon',
            $block0 * 1000 > $nowMs => 'countdown',
            default => 'due',
        };

        return [
            'phase' => $state['phase'] ?? self::NONE,
            'name' => (string) ($state['name'] ?? ''),
            'supply' => (int) ($state['supply'] ?? 0),
            'subsidy' => (int) ($state['subsidy'] ?? 0),
            'eras' => (int) ($state['eras'] ?? 0),
            'message' => is_string($state['message'] ?? null) ? $state['message'] : null,
            'shares' => self::sharePays($state),
            'blocks' => (int) ($state['blocks'] ?? 0),
            'clock' => $clock,
            'countdown' => $clock === 'countdown' ? TournamentSlides::countdown((int) $block0 * 1000, $nowMs) : null,
            'block0' => $block0,
            'games' => array_values(array_map(fn (array $game): array => ['name' => (string) $game['name'], 'url' => (string) $game['url']], (array) ($state['games'] ?? []))),
        ];
    }

    /**
     * The shares with what one win pays in each (`low`..`high` sats per winner over the modes that mine; 0 while no
     * game of the share mines).
     *
     * @param  array<string, mixed>  $state
     * @return list<array{label: string, share: int, pot: int, low: int, high: int}>
     */
    public static function sharePays(array $state): array
    {
        $rows = [];

        foreach ((array) ($state['shares'] ?? []) as $share) {
            $pays = [];

            foreach ((array) ($state['games'] ?? []) as $game) {
                if ($game['group'] === $share['label']) {
                    $pays = [...$pays, ...array_column($game['modes'], 'pays')];
                }
            }

            $rows[] = [...$share, 'low' => $pays === [] ? 0 : (int) min($pays), 'high' => $pays === [] ? 0 : (int) max($pays)];
        }

        return $rows;
    }

    /**
     * One game's row with its images and the age of its latest block.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function frameGame(array $row, int $nowMs): array
    {
        $latest = is_array($row['latest'] ?? null) ? $row['latest'] : null;

        if ($latest !== null) {
            $latest['miners'] = array_map(fn (array $miner): array => ['name' => $miner['name'], 'avatar' => $this->images->avatar($miner['ref'])], $latest['miners']);
            $latest['ago'] = CarbonImmutable::createFromTimestamp((int) $latest['at'])->diffForHumans(CarbonImmutable::createFromTimestampMs($nowMs), ['short' => true, 'syntax' => CarbonImmutable::DIFF_RELATIVE_TO_NOW]);
        }

        $path = $row['coverPath'] ?? null;
        $cover = is_string($path) ? (self::$covers[$path] ??= TournamentSlides::coverUri($path)) : null;

        return [...$row, 'latest' => $latest, 'cover' => $cover];
    }

    /**
     * @return array<string, mixed>
     */
    private function read(): array
    {
        // The daemon is one long request: the draft's memo would keep the date the board set before it started.
        RatingSettings::forget();
        $now = CarbonImmutable::createFromTimestamp(now()->getTimestamp());
        $season = Seasons::live($now);

        if ($season !== null) {
            return $this->readLive($season, $now);
        }

        // Before the first Block 0 the draft's date (or none: the teaser); after a season only a planned next one.
        $chain = ChainDraft::current();
        $block0 = ChainDraft::block0At($chain);

        if (Seasons::latest() !== null && $block0 === null) {
            return ['phase' => self::NONE, 'games' => []];
        }

        $parameters = SeasonParameters::fromDraft([...$chain, 'weeks' => ChainDraft::weeks($chain)], SeasonRelease::draft()['slug'], $now);
        $keys = app(ChainOverview::class)->keys($parameters, $parameters->genesis, []);

        return [
            ...$this->header($parameters, BadgeCopy::season(SeasonRelease::draft()['slug']), is_string($chain['message'] ?? null) ? $chain['message'] : null),
            'phase' => self::PRE,
            'block0' => $block0?->getTimestamp(),
            'genesis' => null,
            'blocks' => 0,
            'games' => $this->gameRows($parameters, $keys, null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function readLive(Season $season, CarbonImmutable $now): array
    {
        $parameters = $season->chainParameters();
        $readout = app(SeasonChains::class)->readout($season);
        $keys = app(ChainOverview::class)->keys($parameters, $parameters->inForceAt($now), array_map(array_sum(...), $readout['mined_by_game_and_era']));

        return [
            ...$this->header($parameters, BadgeCopy::season($season->slug), is_string($season->genesis_message) ? $season->genesis_message : null),
            'phase' => self::LIVE,
            'block0' => null,
            'genesis' => $season->genesis_at->getTimestamp(),
            'blocks' => $this->blocks($season)->count(),
            'games' => $this->gameRows($parameters, $keys, $season, $now),
        ];
    }

    /**
     * @return array{name: string, supply: int, subsidy: int, eras: int, message: string|null, shares: list<array{label: string, share: int, pot: int}>}
     */
    private function header(SeasonParameters $parameters, string $name, ?string $message): array
    {
        $shares = [];

        foreach ($parameters->genesis->shares as $key => $share) {
            if ((int) $share > 0) {
                $shares[] = ['label' => ChainOverview::gameLabel((string) $key), 'share' => (int) $share, 'pot' => $parameters->pot((string) $key)];
            }
        }

        return ['name' => $name, 'supply' => $parameters->supply, 'subsidy' => $parameters->subsidy, 'eras' => $parameters->eras, 'message' => $message, 'shares' => $shares];
    }

    /**
     * One row per listed game with a weight that can mine, in the registry's order.
     *
     * @param  array<string, array{key: string, share: int, pot: int, mined: int, era: int|null, eras: int, closed: bool, last: bool, to_halving: int, wins: int, wins_key: string|null, modes: array<string, array{pays: int, wins: int}>}>  $keys
     * @return list<array<string, mixed>>
     */
    private function gameRows(SeasonParameters $parameters, array $keys, ?Season $season, ?CarbonImmutable $now = null): array
    {
        $consensus = $now === null ? $parameters->genesis : $parameters->inForceAt($now);
        $host = rtrim(preg_replace('#^https?://#', '', (string) config('twentyone.stream.scene.url')) ?? '', '/');
        $rows = [];
        $index = -1;

        foreach ($this->games->listed() as $slug => $game) {
            $index++;
            $covered = $this->games->covered($slug);
            $weights = array_filter($consensus->weights, fn (string $key): bool => in_array(explode('/', $key, 2)[0], $covered, true) && ChainOverview::mines($key), ARRAY_FILTER_USE_KEY);
            $shareKey = $consensus->shareKey($slug);
            $row = $keys[$shareKey] ?? null;

            if ($weights === [] || $row === null || $row['pot'] === 0) {
                continue;
            }

            // The modes of the game: one line per mode, the sizes of a group together while they pay the same.
            $modes = [];
            foreach (array_keys($weights) as $key) {
                $mode = explode('/', $key, 2)[1];
                $modes[$mode][$key] = $row['modes'][$key]['pays'] ?? 0;
            }

            $lines = [];
            foreach ($modes as $mode => $pays) {
                if (count(array_unique($pays)) === 1) {
                    $first = (string) array_key_first($pays);
                    $lines[] = ['label' => $slug === 'chess' ? ChainOverview::keyLabel($first) : ($this->games->mode(explode('/', $first, 2)[0], $mode)->name ?? $mode), 'pays' => (int) reset($pays)];
                } else {
                    foreach ($pays as $key => $sats) {
                        $lines[] = ['label' => ChainOverview::keyLabel($key), 'pays' => (int) $sats];
                    }
                }
            }

            usort($lines, fn (array $a, array $b): int => $a['pays'] <=> $b['pays']);

            // The halving figure in the wins of the game's own first mode that mines (the group's may be another game's).
            $winsKey = (string) array_key_first($weights);
            $path = parse_url(GameNames::page($slug), PHP_URL_PATH);

            $rows[] = [
                'index' => $index,
                'slug' => $slug,
                'name' => $this->games->listedName($slug),
                'group' => ChainOverview::gameLabel($shareKey),
                'grouped' => $shareKey !== $slug,
                'share' => $row['share'],
                'pot' => $row['pot'],
                'mined' => $row['mined'],
                'era' => $row['era'],
                'eras' => $row['eras'],
                'closed' => $row['closed'],
                'last' => $row['last'],
                'to_halving' => $row['to_halving'],
                'wins' => $row['modes'][$winsKey]['wins'] ?? 0,
                'winsLabel' => ChainOverview::keyLabel($winsKey),
                'modes' => array_slice($lines, 0, 3),
                'colour' => MempoolLayout::gameColours($slug, MatchBlocks::family($slug))[0],
                'url' => $host.(is_string($path) && $path !== '/' ? $path : ''),
                'coverPath' => $this->games->coverPath($slug),
                ...($season === null ? ['latest' => null, 'blocks' => 0] : $this->gameBlocks($season, $covered)),
            ];
        }

        return $rows;
    }

    /**
     * A game's blocks this season and its latest one, voided blocks left out.
     *
     * @param  list<string>  $covered
     * @return array{latest: array{height: int, perPlayer: int, at: int, miners: list<array{name: string, ref: array{id: int, pubkey: string, source: string|null}|null}>}|null, blocks: int}
     */
    private function gameBlocks(Season $season, array $covered): array
    {
        $query = $this->blocks($season)->whereIn('game', $covered);
        $count = (clone $query)->count();
        /** @var SeasonAttestation|null $row */
        $row = $query->orderByDesc('height')->first(['id', 'game', 'mode', 'height', 'reward_per_player', 'candidate', 'attested_at']);

        if ($row === null) {
            return ['latest' => null, 'blocks' => $count];
        }

        $users = User::query()->whereIn('pubkey', $row->winners())->get()->keyBy('pubkey');
        $miners = array_map(function (string $pubkey) use ($users): array {
            $user = $users->get($pubkey);

            return ['name' => PublicName::clean($user instanceof User ? $user->displayName() : substr($pubkey, 0, 8)), 'ref' => StreamImages::avatarRef($user instanceof User ? $user : null)];
        }, $row->winners());

        return ['latest' => ['height' => (int) $row->height, 'perPlayer' => max(0, (int) $row->reward_per_player), 'at' => $row->attested_at->getTimestamp(), 'miners' => $miners], 'blocks' => $count];
    }

    /**
     * The season's stored blocks that stand: no block void, no fair-play void of its match.
     *
     * @return Builder<SeasonAttestation>
     */
    private function blocks(Season $season): Builder
    {
        return SeasonAttestation::query()
            ->where('season_id', $season->id)
            ->whereNotNull('height')
            ->whereNotIn('id', SeasonBlockVoid::query()->select('season_attestation_id'))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from((new FairPlayVoid)->getTable())
                ->whereColumn('fair_play_voids.source', 'season_attestations.source')
                ->whereColumn('fair_play_voids.source_id', 'season_attestations.source_id'));
    }
}
