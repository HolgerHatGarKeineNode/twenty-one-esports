<?php

namespace App\Support\Broadcast;

use App\Enums\TournamentStatus;
use App\Games\GameRegistry;
use App\Models\OverlayPreset;
use App\Models\Tournament;
use App\Support\Cards\ShareCard;
use App\Support\Prizes\PrizePool;
use App\Support\QrCode;
use App\Support\Tournaments\CupBoard;
use App\Support\Tournaments\TournamentTv;
use App\Support\TwentyOne\Stream\PrideSlides;
use App\Support\TwentyOne\Stream\PublicName;
use App\Support\TwentyOne\Stream\StreamStats;
use App\Support\TwentyOne\Stream\TournamentSlides;
use BackedEnum;
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
 *   results, progress, champion) while it is public;
 * - `ticker`: the crawl's segments, worded in the preset's language from the data above.
 *
 * PRIVACY: public data only, as the site shows it. clean() drops every key that could carry a key, an npub, an
 * email, a Lightning address, a game account or a picture ref, and every object (a model would serialise all its
 * columns); names are the public display names. Cached CACHE_SECONDS per preset and language.
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
            $tournament = $preset->variant->needsTournament() && $preset->tournament !== null ? $this->guarded(fn (): ?array => $this->tournament($preset, $preset->tournament), null) : null;

            return [
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
                'ticker' => $this->ticker($preset, $upcoming, $nextCup, $pride, $stats, $tournament),
            ];
        } finally {
            app()->setLocale($previous);
        }
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
            'gameName' => $this->games->name($tournament->game),
            'emblem' => self::emblem($tournament->game),
            'startsAt' => $tournament->starts_at->toIso8601String(),
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
            'gameName' => $this->games->name($tournament->game),
            'emblem' => self::emblem($tournament->game),
            'startsAt' => $tournament->starts_at->toIso8601String(),
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
     * A public tournament as its TV reads it; null for a draft or an unpublished one.
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

        return [
            'id' => $tournament->id,
            'name' => PublicName::clean($tournament->name),
            'status' => $tournament->status->value,
            'format' => $tournament->format->value,
            'game' => $tournament->game,
            'gameName' => $this->games->name($tournament->game),
            'emblem' => self::emblem($tournament->game),
            'startsAt' => $tournament->starts_at->toIso8601String(),
            'url' => route('tournaments.show', $tournament),
            'pot' => $preset->hasModule('pots') ? $this->pools->shownPotSats($tournament) : null,
            'progress' => $tv->progress(),
            'results' => self::clean($tv->ticker()),
            'champion' => $champion === null ? null : PublicName::clean((string) $champion['name']),
            'stages' => self::clean($tv->stages()),
        ];
    }

    /**
     * The crawl's segments in the preset's language: the tournament's latest results, the next cup, the upcoming
     * tournaments with their pots, the latest win, the league's numbers and the invitation to play.
     *
     * @param  list<array<string, mixed>>  $upcoming
     * @param  array<string, mixed>|null  $nextCup
     * @param  array<string, mixed>|null  $pride
     * @param  array<string, mixed>|null  $stats
     * @param  array<string, mixed>|null  $tournament
     * @return list<array{emblem: string, head: string, text: string}>
     */
    private function ticker(OverlayPreset $preset, array $upcoming, ?array $nextCup, ?array $pride, ?array $stats, ?array $tournament): array
    {
        if (! $preset->hasModule('ticker')) {
            return [];
        }

        $items = [];

        foreach (array_slice($tournament['results'] ?? [], 0, 4) as $result) {
            $items[] = ['emblem' => $tournament['emblem'], 'head' => __('Result'), 'text' => $result['draw'] ?? false
                ? __(':first and :second draw', ['first' => $result['winner'], 'second' => (string) $result['loser']])
                : trim(__(':winner beats :loser', ['winner' => $result['winner'], 'loser' => (string) ($result['loser'] ?? '')]).' '.($result['label'] ?? ''))];
        }

        if ($nextCup !== null) {
            $items[] = ['emblem' => $nextCup['emblem'], 'head' => __('Next cup'), 'text' => __(':game, :taken of :places places taken', ['game' => $nextCup['gameName'], 'taken' => $nextCup['taken'], 'places' => $nextCup['places']])];
        }

        foreach (array_slice($upcoming, 0, 3) as $next) {
            $items[] = ['emblem' => $next['emblem'], 'head' => __('Open now'), 'text' => $next['name']];

            if (($next['pot'] ?? null) !== null) {
                $items[] = ['emblem' => 'trophy', 'head' => __('Prize pot'), 'text' => __(':sats sats in :tournament', ['sats' => ShareCard::sats((int) $next['pot']), 'tournament' => $next['name']])];
            }
        }

        if (is_array($pride['win'] ?? null) && ($pride['win']['winner'] ?? '') !== '') {
            $items[] = ['emblem' => 'crown', 'head' => __('Latest win'), 'text' => ($pride['win']['loser'] ?? '') !== ''
                ? __(':winner beats :loser', ['winner' => $pride['win']['winner'], 'loser' => $pride['win']['loser']])
                : (string) $pride['win']['winner']];
        }

        if ($stats !== null && $stats['gamesToday'] > 0) {
            $items[] = ['emblem' => 'mark', 'head' => __('Today'), 'text' => trans_choice(':count game played|:count games played', $stats['gamesToday'])];
        }

        if ($preset->hasModule('ads')) {
            $host = (string) preg_replace('#^https?://#', '', rtrim(url('/'), '/'));
            $items[] = ['emblem' => 'mark', 'head' => __('Join in'), 'text' => __('Play in the league for free at :host', ['host' => $host])];
        }

        return $items;
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
