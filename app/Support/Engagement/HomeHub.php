<?php

namespace App\Support\Engagement;

use App\Enums\ChessGameStatus;
use App\Enums\SeriesStatus;
use App\Enums\TournamentStatus;
use App\Games\GameRegistry;
use App\Models\ChessGame;
use App\Models\Clan;
use App\Models\Rating;
use App\Models\SeriesMatch;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Dock\DockItem;
use App\Support\Dock\OpenMatches;
use App\Support\GameNames;
use App\Support\Rating\Ratings;
use App\Support\Tournaments\TournamentLanding;
use App\Support\Tournaments\TournamentPrizePool;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * What home shows as the league's hub (2026-09-27, "Hype auf der
 * Startseite"): the next tournaments with who is in, what is live, the
 * latest results, who joined, and the top of every ladder. Everything is
 * read from the league's own tables; nothing is sample data, and a part
 * with nothing to show is empty (home then says so and offers the action).
 *
 * The number of queries depends on how many tournaments, games and ladders
 * are shown (each capped here), never on how many players are in them:
 * avatars, clans and ratings are loaded per list, not per row
 * (tests/Feature/HomeHubTest.php counts them).
 *
 * @phpstan-type Seat array{user: User|null, clan: Clan|null, name: string, you: bool}
 * @phpstan-type Cup array{tournament: Tournament, cta: string, places: array{taken: int, places: int, lineups: int, solos: int}, open: int, seats: list<Seat>, pot: array<string, mixed>|null, startsIn: array{ms: int, text: string}|null}
 * @phpstan-type Result array{kind: string, at: CarbonInterface|null, href: string, winner: string, loser: string, face: User|null, clan: Clan|null, draw: bool, game: string}
 * @phpstan-type Ladder array{game: string, mode: string, name: string, pool: string, href: string, rows: list<array{place: int, rating: int, name: string, user: User|null, clan: Clan|null}>}
 */
final class HomeHub
{
    /** Open tournaments on home: the hero and up to two more. */
    public const CUPS = 3;

    /** Seats drawn as faces; more places end in one "+N" seat. */
    public const SEATS = 24;

    /** Live boards listed next to the featured one. */
    public const BOARDS = 4;

    public const RESULTS = 6;

    public const NEWCOMERS = 10;

    /** @var list<Cup>|null */
    private ?array $cups = null;

    public function __construct(private readonly ?User $viewer) {}

    /**
     * The tournaments open for sign-up, the soonest start first: published,
     * sign-up still open. Each with the viewer's call to action, the places,
     * the seats as faces in sign-up order and the pot when the league has one.
     *
     * @return list<Cup>
     */
    public function cups(): array
    {
        if ($this->cups !== null) {
            return $this->cups;
        }

        $tournaments = Tournament::query()
            ->where('status', TournamentStatus::Signup)
            ->whereNotNull('published_at')
            ->where('signup_closes_at', '>', now())
            ->orderBy('starts_at')->orderBy('id')
            ->limit(self::CUPS)
            ->get();

        return $this->cups = array_values(array_map(fn (Tournament $tournament): array => $this->cup($tournament), $tournaments->all()));
    }

    /**
     * Live blitz boards (the latest first) and how many blitz and daily games run now.
     *
     * @return array{boards: EloquentCollection<int, ChessGame>, blitz: int, daily: int}
     */
    public function live(): array
    {
        $active = ChessGame::query()->where('status', ChessGameStatus::Active);

        return [
            'boards' => (clone $active)->live()->with(['white', 'black'])->latest('id')->limit(self::BOARDS)->get(),
            'blitz' => (clone $active)->live()->count(),
            'daily' => (clone $active)->daily()->count(),
        ];
    }

    /**
     * Tournaments being played now, for "Watch" and the TV.
     *
     * @return EloquentCollection<int, Tournament>
     */
    public function running(): EloquentCollection
    {
        return Tournament::query()->where('status', TournamentStatus::Running)->whereNotNull('published_at')->latest('starts_at')->limit(2)->get();
    }

    /**
     * The latest results, chess games and series together, newest first.
     *
     * @return list<Result>
     */
    public function results(): array
    {
        $games = ChessGame::query()->where('status', ChessGameStatus::Finished)->with(['white', 'black'])
            ->latest('updated_at')->latest('id')->limit(self::RESULTS)->get()
            ->map(function (ChessGame $game): array {
                $whiteWon = $game->result === '1-0';
                $draw = ! $whiteWon && $game->result !== '0-1';
                $winner = $whiteWon || $draw ? $game->white : $game->black;
                $loser = $whiteWon || $draw ? $game->black : $game->white;

                return [
                    'kind' => 'chess', 'at' => $game->updated_at, 'href' => route('games.show', $game),
                    'winner' => $winner->displayName(), 'loser' => $loser->displayName(), 'face' => $winner, 'clan' => null,
                    'draw' => $draw, 'game' => GameNames::full('chess', $game->mode),
                ];
            });

        $series = SeriesMatch::query()->whereIn('status', [SeriesStatus::Confirmed, SeriesStatus::Resolved])->whereIn('winner', SeriesMatch::SIDES)
            ->with(['challengerLineup.clan', 'challengedLineup.clan'])
            ->latest('finished_at')->latest('id')->limit(self::RESULTS)->get()
            ->map(function (SeriesMatch $match): array {
                $side = (string) $match->winner;
                $other = SeriesMatch::otherSide($side);

                return [
                    'kind' => 'series', 'at' => $match->finished_at ?? $match->updated_at, 'href' => route('matches.show', $match),
                    'winner' => $match->sideName($side), 'loser' => $match->sideName($other), 'face' => null, 'clan' => $match->sideClan($side),
                    'draw' => false, 'game' => GameNames::full($match->game, $match->mode),
                ];
            });

        return array_values($games->concat($series)
            ->sortByDesc(fn (array $result): int => $result['at']?->getTimestamp() ?? 0)
            ->take(self::RESULTS)->all());
    }

    /**
     * Who joined lately: the newest players and clans, and how many came this week.
     *
     * @return array{players: EloquentCollection<int, User>, clans: EloquentCollection<int, Clan>, playersThisWeek: int, clansThisWeek: int, clanCount: int}
     */
    public function newcomers(): array
    {
        $week = now()->subWeek();

        return [
            'players' => User::query()->latest('created_at')->latest('id')->limit(self::NEWCOMERS)->get(),
            'clans' => Clan::query()->withCount('members')->latest('created_at')->latest('id')->limit(4)->get(),
            'playersThisWeek' => User::query()->where('created_at', '>=', $week)->count(),
            'clansThisWeek' => Clan::query()->where('created_at', '>=', $week)->count(),
            'clanCount' => Clan::query()->count(),
        ];
    }

    /**
     * The top three of every game's first ladder: the rated ladder while it
     * is open and has results, else the casual one (as the ladder page
     * picks). One query for every ladder, avatars and clans per list.
     *
     * @return list<Ladder>
     */
    public function ladders(): array
    {
        $registry = app(GameRegistry::class);
        $ladders = [];

        foreach ($registry->all() as $game) {
            $mode = array_key_first($game->modes());

            if ($mode !== null) {
                $ladders[] = ['game' => $game->slug(), 'mode' => (string) $mode, 'season' => Ratings::season(Rating::RATED, $game->slug(), (string) $mode)];
            }
        }

        if ($ladders === []) {
            return [];
        }

        $ranked = Rating::query()
            ->select('ratings.*')
            ->selectRaw('row_number() over (partition by pool, game, mode order by rating desc, results desc, id) as place')
            ->where('results', '>', 0)
            ->where(function ($query) use ($ladders): void {
                foreach ($ladders as $ladder) {
                    $query->orWhere(fn ($one) => $one->where('game', $ladder['game'])->where('mode', $ladder['mode'])
                        ->where(fn ($pool) => $pool->where(fn ($casual) => $casual->where('pool', Rating::CASUAL)->where('season', ''))
                            ->when($ladder['season'] !== null, fn ($rated) => $rated->orWhere(fn ($open) => $open->where('pool', Rating::RATED)->where('season', $ladder['season'])))));
                }
            });

        $rows = Rating::query()->fromSub($ranked, 'ratings')->where('place', '<=', 3)
            ->with(['user.clanMember.clan', 'lineup.clan'])
            ->orderBy('place')->get()
            ->groupBy(fn (Rating $row): string => $row->game.'|'.$row->mode.'|'.$row->pool);

        return array_map(function (array $ladder) use ($rows): array {
            $key = $ladder['game'].'|'.$ladder['mode'].'|';
            $rated = $rows->get($key.Rating::RATED);
            $pool = $rated !== null && $rated->isNotEmpty() ? Rating::RATED : Rating::CASUAL;

            return [
                'game' => $ladder['game'],
                'mode' => $ladder['mode'],
                'name' => GameNames::full($ladder['game'], $ladder['mode']),
                'pool' => $pool,
                'href' => route('ladder.show', [$ladder['game'], $ladder['mode']]),
                'rows' => array_values(($rows->get($key.$pool) ?? collect())->map(fn (Rating $row): array => [
                    'place' => (int) $row->getAttribute('place'),
                    'rating' => (int) $row->rating,
                    'name' => $row->user?->displayName() ?? $row->lineup->clan->name ?? '',
                    'user' => $row->user,
                    'clan' => $row->lineup->clan ?? $row->user?->clanMember?->clan,
                ])->all()),
            ];
        }, $ladders);
    }

    /**
     * What waits for the viewer, their move first (the match dock's own list).
     *
     * @return Collection<int, DockItem>
     */
    public function yourNext(int $limit = 3): Collection
    {
        if ($this->viewer === null) {
            return collect();
        }

        return app(OpenMatches::class)->for($this->viewer)
            ->sortBy(fn (DockItem $item): int => $item->isLive() ? 0 : ($item->needsYou ? 1 : 2))
            ->take($limit)->values();
    }

    /**
     * @return Cup
     */
    private function cup(Tournament $tournament): array
    {
        $landing = new TournamentLanding($tournament, $this->viewer);
        $seats = [];

        foreach ($landing->roster() as $row) {
            // One seat per player: a lineup fills a seat for every member it entered with.
            $members = $row['users'] !== [] ? $row['users'] : [null];

            foreach ($members as $member) {
                $seats[] = [
                    'user' => $member,
                    'clan' => $member === null ? $row['clan'] : null,
                    'name' => $member?->displayName() ?? $row['name'],
                    'you' => $row['you'],
                ];
            }
        }

        // Seats in the roster's order: the seeds sign-up close would give now, best first.
        return [
            'tournament' => $tournament,
            'cta' => $landing->cta(),
            'places' => $landing->places(),
            'open' => $landing->openSeats(),
            'seats' => $seats,
            'pot' => $tournament->pool_opened_at === null ? null : app(TournamentPrizePool::class)->for($tournament),
            'startsIn' => $landing->startsIn(),
        ];
    }
}
