<?php

namespace App\Support\Broadcast;

use App\Enums\OverlayVariant;
use App\Enums\TournamentStatus;
use App\Games\GameRegistry;
use App\Models\Clan;
use App\Models\OverlayPreset;
use App\Models\Tournament;
use App\Support\Cards\ShareCard;
use App\Support\Engagement\HomeHub;
use App\Support\GameNames;
use App\Support\LeagueTime;
use App\Support\Matches\MempoolStrip;
use App\Support\Prizes\PrizePool;
use App\Support\QrCode;
use App\Support\Tournaments\CupBoard;
use App\Support\Tournaments\TournamentTv;
use App\Support\TwentyOne\Stream\PrideSlides;
use App\Support\TwentyOne\Stream\PublicName;
use App\Support\TwentyOne\Stream\StreamStats;
use App\Support\TwentyOne\Stream\TournamentSlides;
use BackedEnum;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Throwable;
use UnitEnum;

/**
 * Everything an OBS overlay needs for its variant (plan "OBS-Broadcast-Overlays", P2), served as
 * `/broadcast/{token}/snapshot.json` and embedded in the overlay page for its first frame:
 *
 * - `preset`: name, variant, language, modules;
 * - `site`: the league's URL and, with the `qr` module, its QR code as SVG;
 * - `upcoming`: tournaments open for sign-up (TournamentSlides::upcoming()), `nextCup` (CupBoard::next());
 *   a pot only when the `pots` module is on and PrizePool::shownPotSats() is above zero;
 * - `pride`: the pride moments (PrideSlides::read(), its 15 s cache) with the `pride` module;
 * - `stats`: the league's counts (StreamStats) with the `stats` module;
 * - `tournament`: the preset's tournament for the tournament and bracket variants (TournamentTv: stages, latest
 *   results, progress, champion; for the overlay its board, every match with seeds, a status line, its QR code)
 *   while it is public;
 * - `games`: every game of the league (the league live overlay's game spots);
 * - `recent`: the league's latest wins over every game (MempoolStrip), league live only;
 * - `ticker`: the crawl's segments, worded in the preset's language from the data above;
 * - `break`: the break scene's own data (plan P5, breakScene()): its state, what it counts down to, the next matches,
 *   the leaders of every ladder and the pot.
 *
 * PRIVACY: public data only, as the site shows it. clean() drops every key that could carry a key, an npub, an
 * email, a Lightning address, a game account or a picture ref, and every object (a model would serialise all its
 * columns); names are the public display names, every npub in them masked (withoutNpubs()). Cached CACHE_SECONDS per preset and language.
 */
final class OverlaySnapshot
{
    public const CACHE_SECONDS = 12;

    /** Keys never sent, at any depth. */
    public const PRIVATE_KEYS = [
        'pubkey', 'npub', 'email', 'lud16', 'lud06', 'lightning', 'lightning_address', 'lightningAddress', 'nip05',
        'gamer_tags', 'gamerTags', 'winnerTag', 'loserTag', 'ref', 'refs', 'ids', 'user', 'users', 'participant', 'clan',
        'avatar', 'avatars', 'faces', 'team', 'token', 'token_hash',
    ];

    /** Upcoming tournaments listed at most. */
    public const UPCOMING = 6;

    public function __construct(
        private TournamentSlides $tournaments,
        private CupBoard $cups,
        private PrizePool $pools,
        private PrideSlides $pride,
        private StreamStats $stats,
        private GameRegistry $games,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(OverlayPreset $preset): array
    {
        $key = 'broadcast.snapshot.'.$preset->id.'.'.$preset->updated_at?->getTimestamp().'.'.$preset->locale;

        try {
            return Cache::remember($key, self::CACHE_SECONDS, fn (): array => $this->read($preset));
        } catch (Throwable $e) {
            report($e);

            return $this->read($preset);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function read(OverlayPreset $preset): array
    {
        $previous = app()->getLocale();
        app()->setLocale($preset->locale);

        try {
            $siteUrl = url('/');
            $upcoming = $this->guarded(fn (): array => $this->upcoming($preset), []);
            $nextCup = $this->guarded(fn (): ?array => $this->nextCup(), null);
            $pride = $preset->hasModule('pride') ? $this->guarded(fn (): array => $this->prideFor($preset), null) : null;
            $stats = $preset->hasModule('stats') ? $this->guarded(fn (): array => $this->statsData(), null) : null;
            $tournament = $preset->variant->takesTournament() && $preset->tournament !== null ? $this->guarded(fn (): ?array => $this->tournament($preset, $preset->tournament), null) : null;
            $games = $this->guarded(fn (): array => $this->gameList(), []);
            $recent = $preset->variant === OverlayVariant::LeagueLive ? $this->guarded(fn (): array => $this->recentWins(), []) : [];

            return self::withoutNpubs([
                'generatedAt' => now()->toIso8601String(),
                'preset' => ['name' => $preset->name, 'variant' => $preset->variant->value, 'locale' => $preset->locale, 'modules' => $preset->moduleStates()],
                'site' => [
                    'url' => $siteUrl,
                    'host' => (string) preg_replace('#^https?://#', '', rtrim($siteUrl, '/')),
                    'qr' => $preset->hasModule('qr') ? QrCode::svg($siteUrl, label: __('QR code for :url', ['url' => preg_replace('#^https?://#', '', rtrim($siteUrl, '/'))])) : null,
                ],
                'upcoming' => $upcoming,
                'nextCup' => $nextCup,
                'pride' => $pride,
                'stats' => $stats,
                'tournament' => $tournament,
                'games' => $games,
                'recent' => $recent,
                'ticker' => $this->ticker($preset, $upcoming, $nextCup, $pride, $stats, $tournament, $games, $recent),
                'break' => $preset->variant === OverlayVariant::Break ? $this->guarded(fn (): array => $this->breakScene($preset, $tournament, $upcoming, $nextCup), null) : null,
            ]);
        } finally {
            app()->setLocale($previous);
        }
    }

    /**
     * Every string at any depth with its npubs masked (PublicName::maskNpubs()): the names come from readers the
     * site shares (ladders, pride moments, cups, the mempool strip), several of which fall back to the truncated npub
     * of User::displayName(); on a stream none of it may stand next to a result.
     */
    public static function withoutNpubs(mixed $value): mixed
    {
        if (is_string($value)) {
            return PublicName::maskNpubs($value);
        }

        return is_array($value) ? array_map(self::withoutNpubs(...), $value) : $value;
    }

    /**
     * Drops every private key and every object at any depth; dates become ISO strings, enums their value.
     */
    public static function clean(mixed $value): mixed
    {
        if ($value instanceof CarbonInterface) {
            return $value->toIso8601String();
        }

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof UnitEnum) {
            return $value->name;
        }

        if (is_object($value)) {
            return null;
        }

        if (! is_array($value)) {
            return $value;
        }

        $clean = [];

        foreach ($value as $key => $item) {
            if (is_string($key) && (in_array($key, self::PRIVATE_KEYS, true) || str_ends_with($key, 'Ref') || str_ends_with($key, 'Refs'))) {
                continue;
            }

            if (is_object($item) && ! $item instanceof CarbonInterface && ! $item instanceof UnitEnum) {
                continue;
            }

            $clean[$key] = self::clean($item);
        }

        return $clean;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $read
     * @param  T  $empty
     * @return T
     */
    private function guarded(callable $read, mixed $empty): mixed
    {
        try {
            return $read();
        } catch (Throwable $e) {
            report($e);

            return $empty;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function upcoming(OverlayPreset $preset): array
    {
        return array_values($this->tournaments->upcoming()->take(self::UPCOMING)->map(fn (Tournament $tournament): array => [
            'id' => $tournament->id,
            'name' => PublicName::clean($tournament->name),
            'game' => $tournament->game,
            'gameName' => GameNames::game($tournament->game),
            'emblem' => self::emblem($tournament->game),
            'startsAt' => $tournament->starts_at->toIso8601String(),
            'starts' => self::when($tournament->starts_at),
            'signupClosesAt' => $tournament->signup_closes_at?->toIso8601String(),
            'url' => route('tournaments.show', $tournament),
            'pot' => $preset->hasModule('pots') ? $this->pools->shownPotSats($tournament) : null,
        ])->all());
    }

    /**
     * @return array<string, mixed>|null
     */
    private function nextCup(): ?array
    {
        $cup = CupBoard::next($this->cups->groups());

        if ($cup === null) {
            return null;
        }

        $tournament = $cup['tournament'];

        return [
            'id' => $tournament->id,
            'name' => PublicName::clean($tournament->name),
            'game' => $tournament->game,
            'gameName' => GameNames::game($tournament->game),
            'emblem' => self::emblem($tournament->game),
            'startsAt' => $tournament->starts_at->toIso8601String(),
            'starts' => self::when($tournament->starts_at),
            'taken' => $cup['taken'],
            'places' => $cup['places'],
            'url' => route('tournaments.show', $tournament),
        ];
    }

    /**
     * The pride moments, cleaned; without the `pots` module no sats of a pot (the prize slide, the sign-ups' pots).
     *
     * @return array<string, mixed>
     */
    private function prideFor(OverlayPreset $preset): array
    {
        $pride = self::clean($this->prideData());

        // The pride data is the stream's, worded in English; the overlay speaks its preset's language.
        if (is_array($pride['win'] ?? null)) {
            $pride['win']['mode'] = __((string) ($pride['win']['mode'] ?? ''));
        }

        if (! $preset->hasModule('pots')) {
            $pride['prizes'] = null;
            $pride['signups'] = array_map(fn (array $signup): array => array_diff_key($signup, ['pot' => true]), is_array($pride['signups'] ?? null) ? $pride['signups'] : []);
        }

        return $pride;
    }

    /**
     * The pride slides' data as the stream reads it, from their shared cache.
     *
     * @return array<string, mixed>
     */
    private function prideData(): array
    {
        $seconds = max(1, (int) config('twentyone.stream.stats.cache_seconds', 15));

        return Cache::remember(PrideSlides::CACHE_KEY, $seconds, fn (): array => $this->pride->read());
    }

    /**
     * @return array{players: int, clans: int, gamesPlayed: int, liveNow: int, gamesToday: int}
     */
    private function statsData(): array
    {
        $seconds = max(1, (int) config('twentyone.stream.stats.cache_seconds', 15));
        $counts = Cache::remember(StreamStats::CACHE_KEY, $seconds, fn (): array => $this->stats->count());

        return [
            'players' => (int) $counts['players'], 'clans' => (int) $counts['clans'], 'gamesPlayed' => (int) $counts['gamesPlayed'],
            'liveNow' => (int) $counts['liveNow'], 'gamesToday' => (int) $counts['gamesToday'],
        ];
    }

    /**
     * A public tournament as its TV reads it; null for a draft or an unpublished one. On top of the TV's data the
     * overlay gets what its banner, board and moments need (plan P4): `statusLine` (where the tournament stands, one
     * line), `board` (the round on now: its matches, a table's top rows, or the heats), `boxes` (every match with its
     * sides' public names, seeds and result, the overlay diffs them for won / upset / final reached), `entries` (sign-ups
     * while open) and, with the `qr` module, a QR code of its page.
     *
     * @return array<string, mixed>|null
     */
    private function tournament(OverlayPreset $preset, Tournament $tournament): ?array
    {
        if ($tournament->status === TournamentStatus::Draft || $tournament->published_at === null) {
            return null;
        }

        $tv = new TournamentTv($tournament);
        $champion = $tournament->status === TournamentStatus::Finished ? $tv->champion() : null;
        $stages = $tv->stages();
        $board = self::board($stages);
        $progress = $tv->progress();
        $url = route('tournaments.show', $tournament);

        return [
            'id' => $tournament->id,
            'name' => PublicName::clean($tournament->name),
            'status' => $tournament->status->value,
            'format' => $tournament->format->value,
            'game' => $tournament->game,
            'gameName' => GameNames::game($tournament->game),
            'emblem' => self::emblem($tournament->game),
            'startsAt' => $tournament->starts_at->toIso8601String(),
            'starts' => self::when($tournament->starts_at),
            'url' => $url,
            'qr' => $preset->hasModule('qr') ? QrCode::svg($url) : null,
            'pot' => $preset->hasModule('pots') ? $this->pools->shownPotSats($tournament) : null,
            'entries' => $tournament->status === TournamentStatus::Signup ? $tournament->signups()->whereNull('withdrawn_at')->whereNull('removed_at')->count() : null,
            'progress' => $progress,
            'statusLine' => match ($tournament->status) {
                TournamentStatus::Signup => __('Sign-up open, starts :when', ['when' => self::when($tournament->starts_at)]),
                TournamentStatus::Drawing => __('Drawing the pairings'),
                // The round and the format, never a count: the banner holds its line while results come in.
                TournamentStatus::Running => $board === null ? __('Running') : $board['round'].', '.$tournament->format->label(),
                TournamentStatus::Finished => $champion === null ? __('Finished') : __('Finished, won by :name', ['name' => PublicName::clean((string) $champion['name'])]),
                default => __('Called off'),
            },
            'results' => self::clean($tv->ticker()),
            'champion' => $champion === null ? null : PublicName::clean((string) $champion['name']),
            'board' => $board,
            'boxes' => array_values(array_map(self::box(...), array_filter(self::allBoxes($stages), fn (array $box): bool => $box['bracket'] !== 'bye'))),
            'stages' => self::clean($stages),
        ];
    }

    /** A break scene without a tournament of its own says "starting soon" when the next start is this close. */
    public const SOON_MINUTES = 120;

    /**
     * The break scene (plan P5): `state` (soon, break, end: the preset's pin, else from the preset's tournament,
     * else from the clock), `target` (what the countdown runs to: the preset's tournament, else the next cup, else the
     * first open tournament), `matches` (the preset's tournament's next matches, the ones up now first), `champion`,
     * `leaders` (the top three of every ladder with results, as home lists them) and `pot` (the target's, only above
     * zero and with the `pots` module).
     *
     * @param  array<string, mixed>|null  $tournament
     * @param  list<array<string, mixed>>  $upcoming
     * @param  array<string, mixed>|null  $nextCup
     * @return array<string, mixed>
     */
    private function breakScene(OverlayPreset $preset, ?array $tournament, array $upcoming, ?array $nextCup): array
    {
        $target = match (true) {
            $tournament !== null => array_intersect_key($tournament, array_flip(['id', 'name', 'game', 'gameName', 'emblem', 'startsAt', 'starts', 'url', 'qr', 'pot', 'entries', 'status'])),
            $nextCup !== null => $nextCup + ['pot' => $preset->hasModule('pots') ? $this->pools->shownPotSats(Tournament::query()->findOrFail((int) $nextCup['id'])) : null],
            $upcoming !== [] => $upcoming[0],
            default => null,
        };

        $state = $preset->scene;

        if (! in_array($state, OverlayPreset::SCENES, true)) {
            $state = match ($tournament['status'] ?? null) {
                TournamentStatus::Signup->value, TournamentStatus::Drawing->value => 'soon',
                TournamentStatus::Running->value => 'break',
                TournamentStatus::Finished->value, TournamentStatus::Cancelled->value => 'end',
                default => $target !== null && now()->lt($target['startsAt']) && now()->diffInMinutes($target['startsAt']) <= self::SOON_MINUTES ? 'soon' : 'break',
            };
        }

        $open = array_values(array_filter($tournament['boxes'] ?? [], fn (array $box): bool => in_array($box['status'], ['ready', 'waiting'], true)
            && count(array_filter($box['sides'], fn (array $side): bool => $side['known'])) > 0));
        usort($open, fn (array $a, array $b): int => (int) $b['live'] <=> (int) $a['live']);

        return [
            'state' => $state,
            'target' => $target,
            'matches' => array_slice($open, 0, 6),
            'champion' => $tournament['champion'] ?? null,
            'leaders' => $this->leaders(),
            'pot' => $preset->hasModule('pots') && ($target['pot'] ?? 0) > 0 ? (int) $target['pot'] : null,
        ];
    }

    /**
     * The top three of every ladder with results (HomeHub::ladders(), home's grid), public names only.
     *
     * @return list<array{game: string, name: string, emblem: string, rows: list<array{place: int, name: string, rating: string}>}>
     */
    private function leaders(): array
    {
        $ladders = Cache::remember('broadcast.leaders.'.app()->getLocale(), 60, fn (): array => array_map(fn (array $ladder): array => [
            'game' => $ladder['game'],
            'name' => (string) $ladder['name'],
            'emblem' => self::emblem($ladder['game']),
            'rows' => array_map(fn (array $row): array => ['place' => (int) $row['place'], 'name' => PublicName::clean((string) $row['name']), 'rating' => (string) $row['rating']], $ladder['rows']),
        ], (new HomeHub(null))->ladders()));

        return array_values(array_filter($ladders, fn (array $ladder): bool => $ladder['rows'] !== []));
    }

    /**
     * @param  list<array<string, mixed>>  $stages
     * @return list<array<string, mixed>>
     */
    private static function allBoxes(array $stages): array
    {
        return array_merge([], ...array_map(TournamentTv::boxesOf(...), $stages));
    }

    /**
     * One match as the overlay reads it: public names, seeds, scores, who won; `final` for the deciding match.
     *
     * @param  array<string, mixed>  $box
     * @return array<string, mixed>
     */
    private static function box(array $box): array
    {
        $round = (string) ($box['round'] ?? '');

        return [
            'key' => (string) $box['key'],
            'round' => $round,
            'bracket' => (string) $box['bracket'],
            'status' => (string) $box['status'],
            'live' => (bool) ($box['live'] ?? false),
            'final' => in_array($box['bracket'], ['grand-final', 'reset'], true) || ($round === __('Final') && $box['bracket'] === 'main'),
            'label' => is_string($box['label'] ?? null) ? $box['label'] : null,
            'sides' => array_values(array_map(fn (array $side): array => [
                'name' => PublicName::clean((string) $side['name']),
                'known' => (bool) $side['known'],
                'seed' => isset($side['entry']['seed']) ? (int) $side['entry']['seed'] : null,
                'score' => isset($side['score']) ? (string) $side['score'] : null,
                'won' => (bool) $side['won'],
            ], is_array($box['sides'] ?? null) ? $box['sides'] : [])),
        ];
    }

    /**
     * The round on now, as the overlay's board shows it: the first part of the current stage with a match to play,
     * and in it the first column (bracket), round (table) or the heats; else the last of them.
     *
     * @param  list<array<string, mixed>>  $stages
     * @return array{kind: string, title: string|null, round: string, matches: list<array<string, mixed>>, rows: list<array<string, mixed>>}|null
     */
    private static function board(array $stages): ?array
    {
        $stage = TournamentTv::currentStage($stages);

        if ($stage === null || $stage['parts'] === []) {
            return null;
        }

        $open = fn (array $box): bool => in_array($box['status'], ['waiting', 'ready'], true) && $box['bracket'] !== 'bye';
        /** @var list<array<string, mixed>> $parts */
        $parts = $stage['parts'];
        $part = collect($parts)->first(fn (array $part): bool => collect(TournamentTv::boxesOf(['parts' => [$part]]))->contains($open)) ?? $parts[count($parts) - 1];
        $title = is_string($part['title'] ?? null) ? $part['title'] : null;
        $real = fn (array $boxes): array => array_values(array_map(self::box(...), array_filter($boxes, fn (array $box): bool => $box['bracket'] !== 'bye')));

        if ($part['kind'] === 'table') {
            $round = TournamentTv::tableRound($part);

            return [
                'kind' => 'table',
                'title' => $title,
                'round' => trans_choice('Round :round', 1, ['round' => $round['number'] ?? 1]),
                'matches' => array_slice($real($round['boxes'] ?? []), 0, 8),
                'rows' => array_values(array_slice(array_map(fn (array $row): array => [
                    'rank' => (int) $row['rank'], 'name' => PublicName::clean((string) $row['name']), 'points' => (string) $row['points'],
                    'wins' => (int) $row['wins'], 'ties' => (int) $row['ties'], 'losses' => (int) $row['losses'],
                ], $part['rows']), 0, 8)),
            ];
        }

        if ($part['kind'] === 'heats') {
            return ['kind' => 'heats', 'title' => $title, 'round' => $title ?? (string) $stage['title'], 'matches' => array_slice($real($part['heats']), 0, 6), 'rows' => []];
        }

        /** @var list<array{0: string|null, 1: array{label: string, matches: list<array<string, mixed>>}}> $columns */
        $columns = [];

        foreach ($part['sections'] as $section) {
            foreach ($section['columns'] as $column) {
                $columns[] = [$section['title'], $column];
            }
        }

        $current = collect($columns)->first(fn (array $entry): bool => array_any($entry[1]['matches'], $open)) ?? $columns[count($columns) - 1];
        $label = (string) $current[1]['label'];

        return [
            'kind' => 'bracket',
            'title' => $title,
            'round' => $current[0] !== null && $label !== __('Final') ? $current[0].', '.$label : $label,
            'matches' => array_slice($real($current[1]['matches']), 0, 8),
            'rows' => [],
        ];
    }

    /** A start as a stream says it: "20:00" today, else the weekday with the time, in the league's zone. */
    private static function when(CarbonInterface $at): string
    {
        $local = $at->toImmutable()->setTimezone(LeagueTime::zone());

        if ($local->isSameDay(now()->setTimezone(LeagueTime::zone()))) {
            return LeagueTime::hour($at);
        }

        /** @var CarbonImmutable $localized */
        $localized = $local->locale(app()->getLocale());

        return $localized->isoFormat('dd').' '.LeagueTime::hour($at);
    }

    /**
     * Every game of the league, for the overlay's game spots.
     *
     * @return list<array{slug: string, name: string, emblem: string}>
     */
    private function gameList(): array
    {
        return array_map(fn (string $slug): array => ['slug' => $slug, 'name' => GameNames::game($slug), 'emblem' => self::emblem($slug)], array_keys($this->games->all()));
    }

    /**
     * The league's latest results over every game, newest first, as /matches lists them (MempoolStrip): who won,
     * whom they beat, the score. A side is its public name, a clan lineup its clan's name.
     *
     * @return list<array{game: string, gameName: string, emblem: string, winner: string, loser: string|null, score: string|null}>
     */
    private function recentWins(): array
    {
        $rows = [];

        foreach (array_reverse(MempoolStrip::build()['finished']) as $cube) {
            $sides = array_values((array) ($cube['sides'] ?? []));
            $won = array_values(array_filter($sides, fn (array $side): bool => (bool) ($side['won'] ?? false)));
            $lost = array_values(array_filter($sides, fn (array $side): bool => ! ($side['won'] ?? false)));

            if (count($won) !== 1) {
                continue;
            }

            $name = fn (array $side): string => PublicName::clean(($side['clan'] ?? null) instanceof Clan ? (string) $side['clan']->name : (string) ($side['name'] ?? ''));
            $slug = (string) ($cube['slug'] ?? $cube['game'] ?? '');
            $rows[] = [
                'game' => $slug,
                'gameName' => GameNames::game($slug),
                'emblem' => self::emblem($slug),
                'winner' => $name($won[0]),
                'loser' => count($lost) === 1 ? $name($lost[0]) : null,
                'score' => filled($cube['score'] ?? null) && ! ($cube['word'] ?? false) && self::scoreLabel((string) $cube['score']) !== '' ? (string) $cube['score'] : null,
            ];
        }

        return array_slice(array_values(array_filter($rows, fn (array $row): bool => $row['winner'] !== '')), 0, 6);
    }

    /**
     * The crawl's segments in the preset's language. A tournament overlay: the matches on now, the latest results,
     * the pot. Every overlay: the latest wins over every game (league live), the next cup and the open tournaments
     * with their start and pots, the week's climbs and rank-ups, the league's numbers, the invitation to play and a
     * spot for every game of the league. The overlay rebuilds the crawl from a fresh snapshot at each loop.
     *
     * @param  list<array<string, mixed>>  $upcoming
     * @param  array<string, mixed>|null  $nextCup
     * @param  array<string, mixed>|null  $pride
     * @param  array<string, mixed>|null  $stats
     * @param  array<string, mixed>|null  $tournament
     * @param  list<array<string, mixed>>  $games
     * @param  list<array<string, mixed>>  $recent
     * @return list<array{emblem: string, head: string, text: string}>
     */
    private function ticker(OverlayPreset $preset, array $upcoming, ?array $nextCup, ?array $pride, ?array $stats, ?array $tournament, array $games = [], array $recent = []): array
    {
        if (! $preset->hasModule('ticker')) {
            return [];
        }

        $items = [];

        if ($tournament !== null) {
            foreach (array_slice(array_filter($tournament['boxes'] ?? [], fn (array $box): bool => $box['live'] && count($box['sides']) === 2), 0, 4) as $box) {
                $items[] = ['emblem' => $tournament['emblem'], 'head' => __('Up now'), 'text' => __(':first against :second', ['first' => $box['sides'][0]['name'], 'second' => $box['sides'][1]['name']])];
            }

            foreach (array_slice($tournament['results'] ?? [], 0, 4) as $result) {
                $items[] = ['emblem' => $tournament['emblem'], 'head' => __('Result'), 'text' => $result['draw'] ?? false
                    ? __(':first and :second draw', ['first' => $result['winner'], 'second' => (string) $result['loser']])
                    : trim(__(':winner beats :loser', ['winner' => $result['winner'], 'loser' => (string) ($result['loser'] ?? '')]).' '.self::scoreLabel($result['label'] ?? null))];
            }

            if (($tournament['pot'] ?? null) !== null) {
                $items[] = ['emblem' => 'trophy', 'head' => __('Prize pot'), 'text' => __(':sats sats in :tournament', ['sats' => ShareCard::sats((int) $tournament['pot']), 'tournament' => $tournament['name']])];
            }
        }

        foreach ($recent as $win) {
            $items[] = ['emblem' => $win['emblem'], 'head' => $win['gameName'], 'text' => $win['loser'] !== null
                ? trim(__(':winner beats :loser', ['winner' => $win['winner'], 'loser' => $win['loser']]).' '.($win['score'] ?? ''))
                : __(':winner wins', ['winner' => $win['winner']])];
        }

        if ($recent === [] && $tournament === null && is_array($pride['win'] ?? null) && ($pride['win']['winner'] ?? '') !== '') {
            $items[] = ['emblem' => 'crown', 'head' => __('Latest win'), 'text' => ($pride['win']['loser'] ?? '') !== ''
                ? __(':winner beats :loser', ['winner' => $pride['win']['winner'], 'loser' => $pride['win']['loser']])
                : (string) $pride['win']['winner']];
        }

        if ($nextCup !== null) {
            $items[] = ['emblem' => $nextCup['emblem'], 'head' => __('Next cup'), 'text' => __(':game, starts :when, :taken of :places places taken', ['game' => $nextCup['gameName'], 'when' => $nextCup['starts'], 'taken' => $nextCup['taken'], 'places' => $nextCup['places']])];
        }

        foreach (array_slice(array_values(array_filter($upcoming, fn (array $next): bool => $next['id'] !== ($tournament['id'] ?? null))), 0, 3) as $next) {
            $items[] = ['emblem' => $next['emblem'], 'head' => __('Open now'), 'text' => __(':tournament, starts :when', ['tournament' => $next['name'], 'when' => $next['starts']])];

            if (($next['pot'] ?? null) !== null) {
                $items[] = ['emblem' => 'trophy', 'head' => __('Prize pot'), 'text' => __(':sats sats in :tournament', ['sats' => ShareCard::sats((int) $next['pot']), 'tournament' => $next['name']])];
            }
        }

        // A tournament overlay stays with its tournament: the league's own news is the league live overlay's.
        foreach (array_slice($tournament === null && is_array($pride['climbers'] ?? null) ? $pride['climbers'] : [], 0, 2) as $climber) {
            $items[] = ['emblem' => 'rank-up', 'head' => __('Climbing'), 'text' => __(':name gains :gain Elo this week', ['name' => $climber['name'], 'gain' => $climber['gain']])];
        }

        foreach (array_slice($tournament === null && is_array($pride['rankUps'] ?? null) ? $pride['rankUps'] : [], 0, 2) as $rankUp) {
            $items[] = ['emblem' => 'rank-up', 'head' => __('Rank up'), 'text' => __(':name reaches :tier in :ladder', ['name' => $rankUp['name'], 'tier' => $rankUp['tier'], 'ladder' => $rankUp['ladder']])];
        }

        if ($tournament === null && $stats !== null && $stats['gamesToday'] > 0) {
            $items[] = ['emblem' => 'mark', 'head' => __('Today'), 'text' => trans_choice(':count game played|:count games played', $stats['gamesToday'])];
        }

        if ($preset->hasModule('ads')) {
            $host = (string) preg_replace('#^https?://#', '', rtrim(url('/'), '/'));
            $items[] = ['emblem' => 'mark', 'head' => __('Join in'), 'text' => __('Play in the league for free at :host', ['host' => $host])];

            if ($tournament === null) {
                foreach ($games as $game) {
                    $items[] = ['emblem' => $game['emblem'], 'head' => __('In the league'), 'text' => $game['name']];
                }
            }
        }

        return $items;
    }

    /**
     * A result's label for the crawl, written after "A beats B": a one-game result as the board writes it from White's
     * side ("0–1") would read against the winner, so it is left out; a series score ("3–1") stays.
     */
    private static function scoreLabel(?string $label): string
    {
        return $label === null || preg_match('/^[01½]\s*[–-]\s*[01½]$/u', $label) === 1 ? '' : $label;
    }

    /** The emblem of a game in the asset pack (public/broadcast/art), the league mark for a game without one. */
    public static function emblem(string $game): string
    {
        return match (true) {
            $game === 'chess' => 'emblem-chess',
            $game === 'rocket-league' => 'emblem-rocket-league',
            str_starts_with($game, 'ea-sports-fc') => 'emblem-football',
            $game === 'age-of-empires-2' => 'emblem-age-of-empires-2',
            $game === 'tmnf' => 'emblem-tmnf',
            $game === 'proof-of-pong' => 'emblem-pong',
            $game === 'hyperbitcoinization' => 'emblem-hyper',
            $game === 'blockfill' => 'emblem-blockfill',
            $game === 'checkers' => 'emblem-checkers',
            $game === 'nine-mens-morris' => 'emblem-mill',
            $game === 'blockli' => 'emblem-blockli',
            default => 'mark',
        };
    }
}
