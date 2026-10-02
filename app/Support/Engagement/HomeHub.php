<?php

namespace App\Support\Engagement;

use App\Enums\ChessGameStatus;
use App\Enums\SeriesStatus;
use App\Enums\TournamentFormat;
use App\Enums\TournamentStatus;
use App\Games\Blockfill;
use App\Games\GameRegistry;
use App\Games\ScoreGame;
use App\Games\TrackmaniaNationsForever;
use App\Models\ChessGame;
use App\Models\Clan;
use App\Models\Rating;
use App\Models\ScoreRun;
use App\Models\SeriesMatch;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Dock\DockItem;
use App\Support\Dock\OpenMatches;
use App\Support\GameNames;
use App\Support\Rating\Ratings;
use App\Support\Scores\ScoreRuns;
use App\Support\Scores\ScoreStanding;
use App\Support\Scores\ScoreWindow;
use App\Support\Stacker\BlockfillRules;
use App\Support\Stacker\BlockfillWeeks;
use App\Support\Tournaments\TournamentLanding;
use App\Support\Tournaments\TournamentPrizePool;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;

/**
 * What home shows as the league's hub (2026-09-27, "Hype auf der
 * Startseite"): the next tournaments with who is in, what is live, the
 * latest results, who joined, and the top of every ladder and score
 * leaderboard. Everything is read from the league's own tables; nothing is
 * sample data, and a part with nothing to show is empty (home then says so
 * and offers the action).
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
 * @phpstan-type ScoreBoard array{game: string, mode: string, name: string, weekly: bool, board: string|null, href: string, play: string, rows: list<array{place: int, value: string, name: string, user: User}>}
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
     * The special tournaments open for sign-up, the soonest start first:
     * published, sign-up still open. Never a casual cup: those are a side
     * mention (<x-tournaments.cup-mentions>, user 2026-09-28). Each with the viewer's call to action, the places,
     * the seats as faces in sign-up order and the pot when the league has one.
     *
     * @return list<Cup>
     */
    public function cups(): array
    {
        if ($this->cups !== null) {
            return $this->cups;
        }

        $tournaments = Tournament::query()->special()
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
        return Tournament::query()->where('status', TournamentStatus::Running)->whereNotNull('published_at')->exceptLeagueWeeks()->latest('starts_at')->limit(2)->get();
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

        // No score game (plan "AoE2 und Trackmania", P4): it has no Elo ladder.
        foreach ($registry->versus() as $game) {
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
     * The top three of every score game (Blockfill live, 2026-10-01), in the
     * grid of the ladders: the leaderboard whose window is open now (the
     * earliest, for a game with more than one; a Blockfill week is this
     * week's), its places by best value as ScoreRuns ranks them. A game with
     * no running leaderboard, or nobody placed on it, has no rows.
     *
     * The places are cached as ids and values under a stamp of these games'
     * score runs (newest id, last change, count), as StrongestList keeps its
     * ranking: a new or decided run shows at once, anything else (a
     * disqualification) within the minute the entry lives. A render costs the
     * running leaderboards, the stamp and the listed players, plus the
     * standings of each leaderboard on a miss, whatever the number of players
     * and runs (tests/Feature/HomeScoreBoardsTest.php counts them). No query
     * at all while no score game is registered.
     *
     * @return list<ScoreBoard>
     */
    public function scores(): array
    {
        $games = app(GameRegistry::class)->scores();

        // A route table cached before the switch went on has no score pages: no card to link.
        if ($games === [] || ! Route::has('scores.show')) {
            return [];
        }

        $slugs = array_values(array_map(fn (ScoreGame $game): string => $game->slug(), $games));
        $now = now();
        $boards = Tournament::query()
            ->whereIn('game', $slugs)
            ->where(['format' => TournamentFormat::Leaderboard, 'status' => TournamentStatus::Running])
            ->whereNotNull('published_at')->where('starts_at', '<=', $now)
            ->orderBy('starts_at')->orderBy('id')->get()
            ->filter(fn (Tournament $board): bool => ScoreWindow::of($board)->contains($now))
            ->unique('game')->keyBy('game');

        $places = [];

        if ($boards->isNotEmpty()) {
            $stamp = ScoreRun::query()->whereIn('game', $slugs)->selectRaw('max(id) as newest, max(updated_at) as touched, count(*) as runs')->first();
            $runs = app(ScoreRuns::class);

            foreach ($boards as $slug => $board) {
                $key = 'home:scores:'.$board->id.':'.$stamp?->getAttribute('newest').':'.$stamp?->getAttribute('touched').':'.$stamp?->getAttribute('runs');

                // Ids and values only: the players are loaded per list below.
                $places[$slug] = Cache::remember($key, 60, fn (): array => array_map(
                    fn (ScoreStanding $standing): array => ['place' => (int) $standing->place, 'user' => (int) $standing->participant->user_id, 'value' => (int) $standing->value],
                    array_slice(array_filter($runs->standings($board), fn (ScoreStanding $standing): bool => $standing->place !== null && $standing->participant->user_id !== null), 0, 3),
                ));
            }
        }

        $userIds = array_unique(array_merge(...array_map(fn (array $top): array => array_column($top, 'user'), array_values($places))));
        $users = $userIds === [] ? collect() : User::query()->whereKey($userIds)->get()->keyBy('id');

        return array_values(array_map(function (ScoreGame $game) use ($boards, $places, $users): array {
            $board = $boards->get($game->slug());
            $mode = $board->mode ?? (string) array_key_first($game->modes());
            $metric = $game->metric($game->mode($mode) ?? throw new InvalidArgumentException("Unknown mode [{$mode}]."));
            $rows = [];

            foreach ($places[$game->slug()] ?? [] as $place) {
                $user = $users->get($place['user']);

                if ($user instanceof User) {
                    $rows[] = ['place' => $place['place'], 'value' => $metric->format($place['value']), 'name' => $user->displayName(), 'user' => $user];
                }
            }

            return [
                'game' => $game->slug(),
                'mode' => $mode,
                // Blockfill's board by the blocks of the rules runs are played on now ("60 blocks")
                'name' => GameNames::game($game->slug()).' · '.($game->slug() === Blockfill::SLUG ? BlockfillRules::blocks(app(BlockfillWeeks::class)->difficultyAt()) : GameNames::mode($game->slug(), $mode)),
                // The league's weekly games (Blockfill, TMNF): "This week", never a board's name.
                'weekly' => in_array($game->slug(), [Blockfill::SLUG, TrackmaniaNationsForever::SLUG], true),
                'board' => $board?->title(),
                'href' => route('scores.show', $game->slug()),
                'play' => GameNames::page($game->slug()),
                'rows' => $rows,
            ];
        }, $games));
    }

    /**
     * What waits for the viewer, their move first (the match dock's own
     * list), without the open rooms and tournaments: those head home in
     * the "Your next match" card (<livewire:upcoming-events>).
     *
     * @return Collection<int, DockItem>
     */
    public function yourNext(int $limit = 3): Collection
    {
        if ($this->viewer === null) {
            return collect();
        }

        return app(OpenMatches::class)->for($this->viewer)
            ->reject(fn (DockItem $item): bool => $item->kind === 'tournament' || ($item->model instanceof SeriesMatch && $item->model->status->isRunning()))
            ->sortBy(fn (DockItem $item): int => $item->isLive() ? 0 : ($item->needsYou ? 1 : 2))
            ->take($limit)->values();
    }

    /**
     * One tournament as home's hero shows it, for the poster of a game page
     * (<x-tournaments.poster>).
     *
     * @return Cup
     */
    public function cupOf(Tournament $tournament): array
    {
        return $this->cup($tournament);
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
