<?php

namespace App\Support\TwentyOne\Stream;

use App\Enums\BoardGameStatus;
use App\Enums\ChessGameStatus;
use App\Enums\SeriesResolution;
use App\Enums\SeriesStatus;
use App\Games\GameRegistry;
use App\Models\BoardGame;
use App\Models\ChessGame;
use App\Models\Clan;
use App\Models\ClanMember;
use App\Models\Rating;
use App\Models\SeriesMatch;
use App\Models\User;
use App\Support\Clans\ClanPride;
use App\Support\Rating\Ratings;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The real numbers the stream's teaser scenes show, counted from the
 * database and kept for `twentyone.stream.stats.cache_seconds`, so a scene
 * change does not query every second. Plain arrays for the Blade scenes.
 *
 * Every count means what the site means by it: players and clans as the
 * footer counts them; games played, live and today over every game the
 * league runs (chess, the board games that are switched on, and the Rocket
 * League and EA Sports FC series with a result; a voided result never
 * counts, a series is never "live" here); the ladders as the ladder page
 * orders them. Names are raw display names; the scene views clean and
 * escape them. Nothing here is estimated.
 *
 * `ladders` are the two casual chess ladders (blitz, daily); `boards` every
 * ladder of every registered game and mode with a result, in the registry's
 * order: the season's rated ladder once it has rows, else the casual one
 * (as the home page's ladder tiles choose). A lineup ladder (Rocket League
 * 2v2 and up) names the clan.
 *
 * The cached counts carry plain picture refs (StreamImages::avatarRef(),
 * ::logoRef()); all() turns them into data URIs from the daemon's memory:
 * every ladder row gets `avatar` (a lineup row its clan's logo), the clan
 * spotlight `logo` (our redrawn logo, else null) and its members' `faces`.
 */
class StreamStats
{
    public function __construct(private StreamImages $images) {}

    public const CACHE_KEY = 'twentyone.stream.stats';

    /** Ladder slug => chess mode. */
    public const LADDERS = ['blitz' => 'blitz', 'daily' => ChessGame::CORRESPONDENCE];

    /** Rows each ladder of `boards` lists. */
    public const BOARD_ROWS = 4;

    /** Member faces the clan spotlight shows. */
    public const CLAN_FACES = 5;

    /**
     * @return array{players: int, clans: int, gamesPlayed: int, liveNow: int, gamesToday: int, ladders: array{blitz: list<array{rank: int, name: string, elo: int, games: int, wins: int, draws: int, losses: int, avatar: string|null}>, daily: list<array{rank: int, name: string, elo: int, games: int, wins: int, draws: int, losses: int, avatar: string|null}>}, boards: list<array<string, mixed>>, clan: array{name: string, tag: string, members: int, games: int, founded: string|null, logoUrl: string|null, logo: string|null, pride: string|null, faces: list<string|null>}|null}
     */
    public function all(): array
    {
        $seconds = max(1, (int) config('twentyone.stream.stats.cache_seconds', 15));

        // A failing cache store must not stop the scene: count directly then.
        try {
            $counts = Cache::remember(self::CACHE_KEY, $seconds, fn (): array => $this->count());
        } catch (Throwable $e) {
            report($e);

            $counts = $this->count();
        }

        return $this->withPictures($counts);
    }

    /**
     * count() with its refs turned into data URIs; a count cached before
     * the refs existed (just after a deploy) gets no picture, not an error.
     *
     * @param  array<string, mixed>  $counts  count()
     * @return array{players: int, clans: int, gamesPlayed: int, liveNow: int, gamesToday: int, ladders: array{blitz: list<array{rank: int, name: string, elo: int, games: int, wins: int, draws: int, losses: int, avatar: string|null}>, daily: list<array{rank: int, name: string, elo: int, games: int, wins: int, draws: int, losses: int, avatar: string|null}>}, boards: list<array<string, mixed>>, clan: array{name: string, tag: string, members: int, games: int, founded: string|null, logoUrl: string|null, logo: string|null, pride: string|null, faces: list<string|null>}|null}
     */
    private function withPictures(array $counts): array
    {
        $clan = $counts['clan'];

        return [
            'players' => $counts['players'],
            'clans' => $counts['clans'],
            'gamesPlayed' => $counts['gamesPlayed'],
            'liveNow' => $counts['liveNow'],
            'gamesToday' => $counts['gamesToday'],
            'ladders' => ['blitz' => $this->ladderWithAvatars($counts['ladders']['blitz']), 'daily' => $this->ladderWithAvatars($counts['ladders']['daily'])],
            'boards' => $this->boardsWithAvatars(is_array($counts['boards'] ?? null) ? $counts['boards'] : []),
            'clan' => $clan === null ? null : [
                'name' => $clan['name'], 'tag' => $clan['tag'], 'members' => $clan['members'], 'games' => $clan['games'],
                'founded' => $clan['founded'], 'logoUrl' => $clan['logoUrl'], 'logo' => $this->images->logo($clan['logoRef'] ?? null),
                'pride' => is_string($clan['pride'] ?? null) ? $clan['pride'] : null,
                'faces' => $this->faces(is_array($clan['faceRefs'] ?? null) ? $clan['faceRefs'] : []),
            ],
        ];
    }

    /**
     * @return array{players: int, clans: int, gamesPlayed: int, liveNow: int, gamesToday: int, ladders: array{blitz: list<array{rank: int, name: string, elo: int, games: int, wins: int, draws: int, losses: int, avatarRef: array{id: int, pubkey: string, source: string|null}|null}>, daily: list<array{rank: int, name: string, elo: int, games: int, wins: int, draws: int, losses: int, avatarRef: array{id: int, pubkey: string, source: string|null}|null}>}, boards: list<array<string, mixed>>, clan: array{name: string, tag: string, members: int, games: int, founded: string|null, logoUrl: string|null, logoRef: string|null, pride: string|null, faceRefs: list<array{id: int, pubkey: string, source: string|null}>}|null}
     */
    public function count(): array
    {
        $timezone = (string) config('twentyone.stream.stats.timezone', 'Europe/Berlin');
        // ended_at and finished_at are stored in UTC; the day starts at local midnight.
        $today = now($timezone)->startOfDay()->utc();

        return [
            'players' => User::query()->count(),
            'clans' => Clan::query()->count(),
            'gamesPlayed' => $this->played(),
            'liveNow' => ChessGame::query()->where('status', ChessGameStatus::Active)->count()
                + $this->boardGames()->where('status', BoardGameStatus::Active)->count(),
            'gamesToday' => $this->played($today),
            'ladders' => [
                'blitz' => $this->ladder(self::LADDERS['blitz']),
                'daily' => $this->ladder(self::LADDERS['daily']),
            ],
            'boards' => $this->boards(),
            'clan' => $this->clanSpotlight(),
        ];
    }

    /**
     * Games played (since `$since`): finished chess games, finished board
     * games of a board game that is switched on, and series with a result
     * that was not voided.
     */
    private function played(?CarbonInterface $since = null): int
    {
        $chess = ChessGame::query()->where('status', ChessGameStatus::Finished);
        $boards = $this->boardGames()->where('status', BoardGameStatus::Finished);
        $series = self::decidedSeries();

        if ($since !== null) {
            $chess->where('ended_at', '>=', $since);
            $boards->where('ended_at', '>=', $since);
            $series->where('finished_at', '>=', $since);
        }

        return $chess->count() + $boards->count() + $series->count();
    }

    /**
     * Series with a result that stands: confirmed or decided by an admin, never voided.
     *
     * @return Builder<SeriesMatch>
     */
    public static function decidedSeries(): Builder
    {
        return SeriesMatch::query()->whereIn('status', [SeriesStatus::Confirmed, SeriesStatus::Resolved])
            ->where(fn (Builder $query) => $query->whereNull('resolution')->orWhere('resolution', '!=', SeriesResolution::Void));
    }

    /**
     * The board games of the games switched on; none while they are all off.
     *
     * @return Builder<BoardGame>
     */
    private function boardGames(): Builder
    {
        $slugs = array_keys(app(GameRegistry::class)->boards());

        return BoardGame::query()->when($slugs === [], fn (Builder $query) => $query->whereRaw('1 = 0'), fn (Builder $query) => $query->whereIn('game', $slugs));
    }

    /**
     * Every ladder with a result, in registry order: game, mode, their names,
     * the pool shown and its top BOARD_ROWS rows, in one window query.
     *
     * @return list<array{game: string, mode: string, gameName: string, modeName: string, pool: string, rows: list<array<string, mixed>>}>
     */
    private function boards(): array
    {
        $ladders = [];

        foreach (app(GameRegistry::class)->all() as $game) {
            foreach ($game->modes() as $slug => $mode) {
                $ladders[] = ['game' => $game->slug(), 'mode' => (string) $slug, 'gameName' => $game->name(), 'modeName' => $mode->name, 'season' => Ratings::season(Rating::RATED, $game->slug(), (string) $slug)];
            }
        }

        if ($ladders === []) {
            return [];
        }

        $ranked = Rating::query()
            ->select('ratings.*')
            ->selectRaw('row_number() over (partition by pool, game, mode order by rating desc, results desc, id) as place')
            ->where('results', '>', 0)
            ->where(function (Builder $query) use ($ladders): void {
                foreach ($ladders as $ladder) {
                    $query->orWhere(fn (Builder $one) => $one->where('game', $ladder['game'])->where('mode', $ladder['mode'])
                        ->where(fn (Builder $pool) => $pool->where(fn (Builder $casual) => $casual->where('pool', Rating::CASUAL)->where('season', ''))
                            ->when($ladder['season'] !== null, fn (Builder $rated) => $rated->orWhere(fn (Builder $open) => $open->where('pool', Rating::RATED)->where('season', $ladder['season'])))));
                }
            });
        $rows = Rating::query()->fromSub($ranked, 'ratings')->where('place', '<=', self::BOARD_ROWS)
            ->with(['user', 'lineup.clan'])->orderBy('place')->get()
            ->groupBy(fn (Rating $row): string => $row->game.'|'.$row->mode.'|'.$row->pool);
        $boards = [];

        foreach ($ladders as $ladder) {
            $key = $ladder['game'].'|'.$ladder['mode'].'|';
            $pool = ($rows->get($key.Rating::RATED)?->isNotEmpty() ?? false) ? Rating::RATED : Rating::CASUAL;
            $list = $rows->get($key.$pool);

            if ($list === null || $list->isEmpty()) {
                continue;
            }

            $boards[] = [
                'game' => $ladder['game'], 'mode' => $ladder['mode'], 'gameName' => $ladder['gameName'], 'modeName' => $ladder['modeName'], 'pool' => $pool,
                'rows' => array_values($list->map(fn (Rating $row): array => [
                    'rank' => (int) $row->getAttribute('place'),
                    // A lineup ladder names the clan behind the lineup; a deleted account in its English form.
                    'name' => $row->user?->displayName() ?? $row->lineup->clan->name ?? 'Deleted account',
                    'elo' => $row->rating,
                    'games' => $row->results,
                    'wins' => $row->wins,
                    'draws' => $row->draws,
                    'losses' => $row->losses,
                    'avatarRef' => StreamImages::avatarRef($row->user),
                    'logoRef' => $row->user_id === null ? StreamImages::logoRef($row->lineup?->clan) : null,
                    'tag' => $row->user_id === null ? $row->lineup?->clan?->clantag : null,
                ])->all()),
            ];
        }

        return $boards;
    }

    /**
     * boards() with every row's picture as a data URI (`avatar`): the player's, a lineup row its clan's logo.
     *
     * @param  array<mixed>  $boards
     * @return list<array<string, mixed>>
     */
    private function boardsWithAvatars(array $boards): array
    {
        $out = [];

        foreach ($boards as $board) {
            if (! is_array($board)) {
                continue;
            }

            $rows = [];

            foreach (is_array($board['rows'] ?? null) ? $board['rows'] : [] as $row) {
                $lineup = array_key_exists('tag', $row) && $row['tag'] !== null;
                $rows[] = [
                    'rank' => $row['rank'], 'name' => $row['name'], 'elo' => $row['elo'], 'games' => $row['games'],
                    'wins' => $row['wins'], 'draws' => $row['draws'], 'losses' => $row['losses'],
                    'avatar' => $lineup ? $this->images->logo(is_string($row['logoRef'] ?? null) ? $row['logoRef'] : null) : $this->images->avatar($row['avatarRef'] ?? null),
                    'tag' => $lineup ? (string) $row['tag'] : null,
                ];
            }

            $out[] = [...$board, 'rows' => $rows];
        }

        return $out;
    }

    /**
     * @param  list<array{rank: int, name: string, elo: int, games: int, wins: int, draws: int, losses: int, avatarRef: array{id: int, pubkey: string, source: string|null}|null}>  $rows
     * @return list<array{rank: int, name: string, elo: int, games: int, wins: int, draws: int, losses: int, avatar: string|null}>
     */
    private function ladderWithAvatars(array $rows): array
    {
        $ladder = [];

        foreach ($rows as $row) {
            $ladder[] = [
                'rank' => $row['rank'], 'name' => $row['name'], 'elo' => $row['elo'], 'games' => $row['games'],
                'wins' => $row['wins'], 'draws' => $row['draws'], 'losses' => $row['losses'],
                'avatar' => $this->images->avatar($row['avatarRef'] ?? null),
            ];
        }

        return $ladder;
    }

    /**
     * @param  array<mixed>  $refs
     * @return list<string|null>
     */
    private function faces(array $refs): array
    {
        $faces = [];

        foreach ($refs as $ref) {
            $faces[] = $this->images->avatar($ref);
        }

        return $faces;
    }

    /**
     * The top of a casual chess ladder, in the ladder page's order: rating,
     * then more results, then first rated; only players with a result.
     *
     * @return list<array{rank: int, name: string, elo: int, games: int, wins: int, draws: int, losses: int, avatarRef: array{id: int, pubkey: string, source: string|null}|null}>
     */
    private function ladder(string $mode): array
    {
        return array_values(Rating::query()
            ->where(['pool' => Rating::CASUAL, 'season' => Ratings::season(Rating::CASUAL, 'chess', $mode), 'game' => 'chess', 'mode' => $mode])
            ->where('results', '>', 0)
            ->with('user')
            ->orderByDesc('rating')->orderByDesc('results')->orderBy('id')
            ->limit(max(1, (int) config('twentyone.stream.stats.ladder_rows', 4)))
            ->get()
            ->values()
            ->map(fn (Rating $row, int $index): array => [
                'rank' => $index + 1,
                // The ladder page's name, "Deleted account" in its English form.
                'name' => $row->user?->displayName() ?? 'Deleted account',
                'elo' => $row->rating,
                'games' => $row->results,
                'wins' => $row->wins,
                'draws' => $row->draws,
                'losses' => $row->losses,
                'avatarRef' => StreamImages::avatarRef($row->user),
            ])
            ->all());
    }

    /**
     * One clan, taking turns every `clan_spotlight_seconds` in founding
     * order. Games are finished chess and board games a current member
     * played in, and the decided series of the clan's lineups; `pride` is
     * its proudest moment (ClanPride), `faceRefs` its longest-standing
     * members.
     *
     * @return array{name: string, tag: string, members: int, games: int, founded: string|null, logoUrl: string|null, logoRef: string|null, pride: string|null, faceRefs: list<array{id: int, pubkey: string, source: string|null}>}|null
     */
    private function clanSpotlight(): ?array
    {
        $count = Clan::query()->count();

        if ($count === 0) {
            return null;
        }

        $turn = intdiv(now()->getTimestamp(), max(1, (int) config('twentyone.stream.stats.clan_spotlight_seconds', 600))) % $count;
        $clan = Clan::query()->withCount('members')->orderBy('id')->skip($turn)->first();

        if ($clan === null) {
            return null;
        }

        $members = $clan->members()->pluck('user_id');
        $lineups = $clan->lineups()->pluck('id');
        $played = fn (Builder $query) => $query->whereIn('white_id', $members)->orWhereIn('black_id', $members);
        $faces = [];

        foreach ($clan->members()->with('user')->orderBy('joined_at')->orderBy('id')->limit(self::CLAN_FACES)->get() as $member) {
            /** @var ClanMember $member */
            $ref = StreamImages::avatarRef($member->user);

            if ($ref !== null) {
                $faces[] = $ref;
            }
        }

        return [
            'name' => $clan->name,
            'tag' => $clan->clantag,
            'members' => (int) $clan->getAttribute('members_count'),
            'games' => ChessGame::query()->where('status', ChessGameStatus::Finished)->where($played)->count()
                + $this->boardGames()->where('status', BoardGameStatus::Finished)->where($played)->count()
                + ($lineups->isEmpty() ? 0 : self::decidedSeries()
                    ->where(fn (Builder $query) => $query->whereIn('challenger_lineup_id', $lineups)->orWhereIn('challenged_lineup_id', $lineups))->count()),
            'founded' => $clan->created_at?->format('M j, Y'),
            'logoUrl' => filled($clan->picture) ? $clan->picture : null,
            'logoRef' => StreamImages::logoRef($clan),
            'pride' => $this->clanMoment($clan->id),
            'faceRefs' => $faces,
        ];
    }

    /**
     * The clan's proudest moment (ClanPride, as /clans shows it) as one
     * English line, or null without one. A failing read costs the line only.
     */
    private function clanMoment(int $clanId): ?string
    {
        try {
            $moment = app(ClanPride::class)->all()[$clanId][0] ?? null;
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        if (! is_array($moment)) {
            return null;
        }

        $count = (int) ($moment['count'] ?? 0);
        $player = PublicName::clean((string) ($moment['player'] ?? ''));
        $tournament = PublicName::clean((string) ($moment['tournament'] ?? ''));
        $place = (int) ($moment['place'] ?? 0);

        return match ($moment['type'] ?? null) {
            'tournament' => ($player === '' ? '' : $player.' ').match (true) {
                $place === 1 => $player === '' ? 'Won '.$tournament : 'won '.$tournament,
                default => ($player === '' ? 'Place ' : 'took place ').$place.' in '.$tournament,
            },
            'series' => 'Beat '.PublicName::clean((string) ($moment['opponent'] ?? '')).' '.str_replace(':', '-', (string) ($moment['score'] ?? '')),
            'streak' => $player.' won '.$count.' in a row',
            'wins' => $count.' '.($count === 1 ? 'win' : 'wins').' this week',
            'joined' => $count.' new '.($count === 1 ? 'player' : 'players').' this week',
            'founded' => $count > 1 ? 'Brand new and already '.$count.' strong' : 'Brand new this week',
            default => null,
        };
    }
}
