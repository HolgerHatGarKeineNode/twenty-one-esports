<?php

namespace App\Support\TwentyOne\Stream;

use App\Enums\BoardEndReason;
use App\Enums\ChessEndReason;
use App\Enums\SeriesResolution;
use App\Games\GameRegistry;
use App\Models\BoardGame;
use App\Models\ChessGame;
use App\Models\Clan;
use App\Models\FairPlayVoid;
use App\Models\SeasonAttestation;
use App\Models\SeasonBlockVoid;
use App\Models\SeriesMatch;
use App\Models\User;
use App\Support\Badges\BadgeCopy;
use App\Support\GameNames;
use App\Support\Matches\MatchBlocks;
use App\Support\Matches\MempoolStrip;
use App\Support\PreSeason;
use App\Support\SeasonChain\Seasons;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The data of the mempool slide (m1), read from the same sources as the
 * mempool strip on /matches: MempoolStrip::build() for the cubes (every
 * game, casual and rated, played and running), the season chain's
 * attestations for the blocks. The app classes in App\Support\Matches are
 * read, never changed; this class only reshapes their output for a 1280x720
 * still.
 *
 * Two versions, switched by the chain alone (Seasons::live()):
 *
 * - `casual` while no season runs: the latest played games on the left, the
 *   running and scheduled ones on the right, at most COLUMNS cubes.
 * - `season` while a season runs: the latest mined blocks of the live
 *   season (SeasonAttestation with a height, oldest left, the tip next to
 *   the divider) with the block's reward in sats and who mined it, then the
 *   running games as the pending mempool.
 *
 * A voided result is never shown as a win: a series the league voided, and a
 * block the season review voided (SeasonBlockVoid) or whose match a fair
 * play link voided (FairPlayVoid), stay out. The board games (nine men's
 * morris, checkers) only while they are switched on and routed
 * (MempoolStrip::boardSlugs()), their blocks too.
 *
 * Read once per cache period (`twentyone.stream.stats.cache_seconds`, like
 * StreamStats and PrideSlides), with a fixed number of queries for any number
 * of matches and blocks; frame() turns the picture refs into data URIs from
 * the daemon's memory. Texts are English whatever the application locale:
 * the stream is.
 */
class MempoolSlides
{
    public const CACHE_KEY = 'twentyone.stream.mempool';

    /** The slide's scene id (RotationPlanner::VIEWS). */
    public const SCENE = 'm1';

    /** Cubes on the slide at most (blocks and games together). */
    public const COLUMNS = 5;

    /** Places the running side keeps while there are that many running games. */
    public const RUNNING = 2;

    public function __construct(private StreamImages $images) {}

    /**
     * The scene data of m1: the slide, the ticker counts and the brand backdrop.
     *
     * @param  array<string, mixed>  $stats  StreamStats::all()
     * @return array<string, mixed>
     */
    public function scene(array $stats): array
    {
        return ['mempool' => $this->all(), 'stats' => $stats, 'backdrop' => $this->images->backdrop(StreamImages::BRAND)];
    }

    /**
     * The slide as the view reads it, cached like StreamStats.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $seconds = max(1, (int) config('twentyone.stream.stats.cache_seconds', 15));

        try {
            $data = Cache::remember(self::CACHE_KEY, $seconds, fn (): array => $this->read());
        } catch (Throwable $e) {
            report($e);

            $data = $this->read();
        }

        return $this->framed($data);
    }

    /**
     * The slide from the database, with picture refs instead of pictures
     * (plain arrays, so a cache can hold them).
     *
     * `mode` is `season` while a season runs, else `casual`; `rest` tells a
     * casual slide whether a season was ever released (`pre-launch`) or the
     * last one ended (`between`). `finished` (casual only) and `running` are
     * cubes, `blocks` (season only) the mined blocks, oldest first; together
     * at most COLUMNS.
     *
     * @return array{mode: string, rest: string, season: string|null, finished: list<array<string, mixed>>, running: list<array<string, mixed>>, blocks: list<array<string, mixed>>}
     */
    public function read(): array
    {
        $locale = App::getLocale();
        App::setLocale('en');

        try {
            $strip = MempoolStrip::build();
            $season = Seasons::live();
            $outcomes = $this->outcomes($strip['finished']);
            $finished = array_values(array_filter($strip['finished'], fn (array $cube): bool => ! in_array($cube['key'], $outcomes['void'], true)));
            $blocks = $season === null ? [] : $this->blocks($season->id);
            $left = $season === null ? count($finished) : count($blocks);
            // The running side keeps RUNNING places; what the other side leaves free it may use too.
            $running = array_slice($strip['running'], 0, max(self::RUNNING, self::COLUMNS - $left));
            // Without a running game the right side is one open cube (MempoolLayout), so it keeps a place too.
            $keep = self::COLUMNS - max(1, count($running));
            $finished = array_slice($finished, max(0, count($finished) - $keep));
            $players = $this->seriesPlayers([...($season === null ? $finished : []), ...$running]);

            return [
                'mode' => $season === null ? 'casual' : 'season',
                'rest' => $season === null ? Seasons::state() : 'live',
                'season' => $season === null ? null : BadgeCopy::season($season->slug),
                'finished' => $season === null ? array_map(fn (array $cube): array => $this->cube($cube, in_array($cube['key'], $outcomes['forfeit'], true), $players), $finished) : [],
                'running' => array_map(fn (array $cube): array => $this->cube($cube, false, $players), $running),
                'blocks' => array_slice($blocks, max(0, count($blocks) - $keep)),
            ];
        } finally {
            App::setLocale($locale);
        }
    }

    /**
     * read()'s data with every picture ref turned into a data URI.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function framed(array $data): array
    {
        foreach (['finished', 'running', 'blocks'] as $list) {
            foreach ((array) ($data[$list] ?? []) as $i => $item) {
                foreach ((array) ($item['sides'] ?? []) as $j => $side) {
                    $data[$list][$i]['sides'][$j]['avatar'] = $this->images->avatar(self::avatarRef($side['ref'] ?? null));
                    $data[$list][$i]['sides'][$j]['logo'] = $this->images->logo(is_string($side['logoRef'] ?? null) ? $side['logoRef'] : null);
                    unset($data[$list][$i]['sides'][$j]['ref'], $data[$list][$i]['sides'][$j]['logoRef']);
                }
            }
        }

        return $data;
    }

    /**
     * A picture ref as avatarRef() wrote it into the cache, null for anything else.
     *
     * @return array{id: int, pubkey: string, source: string|null}|null
     */
    private static function avatarRef(mixed $ref): ?array
    {
        if (! is_array($ref) || ! is_int($ref['id'] ?? null) || ! is_string($ref['pubkey'] ?? null)) {
            return null;
        }

        $source = $ref['source'] ?? null;

        return ['id' => $ref['id'], 'pubkey' => $ref['pubkey'], 'source' => is_string($source) ? $source : null];
    }

    /**
     * How the finished matches were decided, where the strip's cubes do not
     * say (MatchBlocks::shape() keeps no end reason): `void` the series the
     * league voided (no result, so no cube on the stream at all), `forfeit`
     * the wins nobody played for (a series resolved as a forfeit, a chess or
     * board game ended by forfeit, a director's no-show result as
     * SeasonChains attests it), shown without a crown. One query per kind
     * of match, for any number of them.
     *
     * @param  list<array<string, mixed>>  $finished
     * @return array{void: list<string>, forfeit: list<string>}
     */
    private function outcomes(array $finished): array
    {
        $ids = ['series' => [], 'chess' => [], 'board' => []];

        foreach ($finished as $cube) {
            [$kind, $id] = array_pad(explode('-', (string) $cube['key'], 2), 2, '');

            if (isset($ids[$kind]) && ctype_digit($id)) {
                $ids[$kind][] = (int) $id;
            }
        }

        $void = [];
        $forfeit = [];

        if ($ids['series'] !== []) {
            foreach (SeriesMatch::query()->whereIn('id', $ids['series'])->whereIn('resolution', [SeriesResolution::Void, SeriesResolution::Forfeit])->get(['id', 'resolution']) as $match) {
                if ($match->resolution === SeriesResolution::Void) {
                    $void[] = 'series-'.$match->id;
                } else {
                    $forfeit[] = 'series-'.$match->id;
                }
            }
        }

        if ($ids['chess'] !== []) {
            $chess = ChessGame::query()->whereIn('id', $ids['chess'])
                ->where(fn (EloquentBuilder $query) => $query->where('end_reason', ChessEndReason::Forfeit)
                    ->orWhere(fn (EloquentBuilder $query) => $query->where('end_reason', ChessEndReason::Director)
                        ->whereHas('tournamentMatch', fn (EloquentBuilder $match) => $match->where('result->forfeit', true))))
                ->pluck('id');

            foreach ($chess as $id) {
                $forfeit[] = 'chess-'.$id;
            }
        }

        if ($ids['board'] !== []) {
            foreach (BoardGame::query()->whereIn('id', $ids['board'])->where('end_reason', BoardEndReason::Forfeit->value)->pluck('id') as $id) {
                $forfeit[] = 'board-'.$id;
            }
        }

        return ['void' => $void, 'forfeit' => $forfeit];
    }

    /**
     * The player behind each side of a series cube that seats one player
     * without a clan (a casual or player-ladder 1v1 of Rocket League, EA FC,
     * Age of Empires II): the strip names such a side by its four-letter tag
     * and draws no picture; the stream shows the player's name and avatar,
     * as for a chess game. Two queries for any number of cubes.
     *
     * @param  list<array<string, mixed>>  $cubes  MatchBlocks::shape()
     * @return array<string, array<int, User>> cube key => side index => player
     */
    private function seriesPlayers(array $cubes): array
    {
        $ids = [];

        foreach ($cubes as $cube) {
            [$kind, $id] = array_pad(explode('-', (string) $cube['key'], 2), 2, '');
            $clanless = array_filter((array) $cube['sides'], fn (mixed $side): bool => is_array($side) && ! (($side['clan'] ?? null) instanceof Clan) && ! (($side['user'] ?? null) instanceof User));

            if ($kind === 'series' && ctype_digit($id) && $clanless !== []) {
                $ids[] = (int) $id;
            }
        }

        if ($ids === []) {
            return [];
        }

        $seats = DB::table('series_match_players')->whereIn('series_match_id', $ids)->get(['series_match_id', 'user_id', 'side'])->groupBy(fn ($seat): string => $seat->series_match_id.'|'.$seat->side);
        $solo = $seats->filter(fn ($side): bool => $side->count() === 1)->map(fn ($side): int => (int) $side->first()->user_id);
        $users = User::query()->whereKey($solo->values()->unique()->all())->get()->keyBy('id');
        $out = [];

        foreach ($solo as $key => $userId) {
            [$id, $side] = explode('|', (string) $key, 2);
            $user = $users->get($userId);

            if ($user !== null) {
                $out['series-'.$id][$side === 'challenger' ? 0 : 1] = $user;
            }
        }

        return $out;
    }

    /**
     * The latest mined blocks of the season, oldest first: never a voided one,
     * never a board game while it is switched off. The reward is the
     * attestation's (the sats /mining lists for the block); winners by the
     * pubkeys the league attested.
     *
     * @return list<array<string, mixed>>
     */
    private function blocks(int $seasonId): array
    {
        $boards = MempoolStrip::boardSlugs();

        $rows = SeasonAttestation::query()
            ->where('season_id', $seasonId)
            ->whereNotNull('height')
            ->whereNotIn('id', SeasonBlockVoid::query()->select('season_attestation_id'))
            ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')->from((new FairPlayVoid)->getTable())
                ->whereColumn('fair_play_voids.source', 'season_attestations.source')
                ->whereColumn('fair_play_voids.source_id', 'season_attestations.source_id'))
            ->where(fn ($query) => $boards === []
                ? $query->where('source', '!=', SeasonAttestation::BOARD)
                : $query->where('source', '!=', SeasonAttestation::BOARD)->orWhereIn('game', $boards))
            ->orderByDesc('height')
            ->limit(self::COLUMNS)
            ->get(['id', 'source', 'source_id', 'game', 'mode', 'height', 'reward', 'reward_per_player', 'candidate', 'attested_at']);

        $pubkeys = $rows->flatMap(fn (SeasonAttestation $row): array => $row->winners())->unique()->values()->all();
        $users = $pubkeys === [] ? collect() : User::query()->whereIn('pubkey', $pubkeys)->get()->keyBy('pubkey');

        return array_values($rows->reverse()->map(function (SeasonAttestation $row) use ($users): array {
            $family = MatchBlocks::family($row->game);
            $winners = array_map(function (string $pubkey) use ($users): array {
                $user = $users->get($pubkey);

                return ['name' => PublicName::clean($user instanceof User ? $user->displayName() : substr($pubkey, 0, 8)), 'won' => true, 'tag' => '', 'ref' => StreamImages::avatarRef($user instanceof User ? $user : null), 'logoRef' => null];
            }, $row->winners());

            return [
                'game' => $family,
                'slug' => $row->game,
                'name' => GameTitle::short(GameNames::game($row->game)),
                'icon' => app(GameRegistry::class)->find($row->game)?->assets()->icon ?? 'trophy',
                'mode' => self::modeLabel($row->game, $row->mode),
                'height' => (int) $row->height,
                'reward' => max(0, (int) $row->reward),
                'perPlayer' => max(0, (int) $row->reward_per_player),
                'when' => $row->attested_at->diffForHumans(['short' => true]),
                'sides' => $winners,
            ];
        })->all());
    }

    /** "Blitz 5+3", "Daily", "RL 3v3": the mode line of a block, as the strip's cubes name it. */
    private static function modeLabel(string $game, string $mode): string
    {
        $registry = app(GameRegistry::class);

        if ($registry->isSeries($game)) {
            return ($registry->find($game)?->assets()->shortLabel ?? $game).' '.$mode;
        }

        return $mode === 'correspondence' ? 'Daily' : __($registry->mode($game, $mode)->name ?? $mode);
    }

    /**
     * One cube of MempoolStrip as plain data: no models, picture refs only.
     * `forfeit`: a win nobody played for (outcomes()), shown without a crown.
     *
     * @param  array<string, mixed>  $cube  MatchBlocks::shape()
     * @param  array<string, array<int, User>>  $players  seriesPlayers()
     * @return array<string, mixed>
     */
    private function cube(array $cube, bool $forfeit, array $players = []): array
    {
        $sides = [];

        foreach (array_values((array) $cube['sides']) as $index => $side) {
            $clan = $side['clan'] ?? null;
            $player = $clan instanceof Clan ? null : ($players[(string) $cube['key']][$index] ?? null);
            $user = $player ?? $side['user'] ?? null;
            // A series side of one player: the player's public name, not the strip's tag (seriesPlayers()).
            $side['name'] = $player?->displayName() ?? $side['name'];
            $sides[] = [
                // A clan lineup is its clan's name on the stream; the strip's tag is too short to be proud of.
                'name' => PublicName::clean($clan instanceof Clan ? (string) $clan->name : (string) $side['name']),
                'tag' => (string) ($clan instanceof Clan ? $side['name'] : ''),
                'won' => (bool) $side['won'],
                'ref' => StreamImages::avatarRef($user instanceof User ? $user : null),
                'logoRef' => StreamImages::logoRef($clan instanceof Clan ? $clan : null),
            ];
        }

        $when = (string) $cube['when'];

        // A scheduled series names its time ("20:00", the cube is narrow) and on the stream in which zone.
        if ($cube['state'] === 'next' && $when !== '') {
            $when = preg_replace('/^at\s+/', '', $when).' '.now()->timezone(PreSeason::timezoneFor(null))->format('T');
        }

        return [
            'game' => (string) $cube['game'],
            'slug' => (string) $cube['slug'],
            'name' => GameTitle::short(GameNames::game((string) $cube['slug'])),
            'icon' => (string) $cube['icon'],
            'mode' => (string) $cube['mode'],
            'score' => (string) $cube['score'],
            'word' => (bool) $cube['word'],
            'when' => $when,
            'state' => (string) $cube['state'],
            'casual' => (bool) $cube['casual'],
            'level' => (int) $cube['level'],
            'forfeit' => $forfeit,
            'sides' => $sides,
        ];
    }
}
