<?php

namespace App\Support\Engagement;

use App\Enums\BoardGameStatus;
use App\Enums\ChessGameStatus;
use App\Enums\IncomingPaymentStatus;
use App\Enums\SeriesStatus;
use App\Enums\TournamentFormat;
use App\Enums\TournamentStatus;
use App\Games\Blockfill;
use App\Games\Contracts\PlayedOnOwnCopy;
use App\Games\GameKind;
use App\Games\GameRegistry;
use App\Games\Hyperbitcoinization;
use App\Games\ProofOfPong;
use App\Games\TrackmaniaNationsForever;
use App\Models\BoardGame;
use App\Models\ChessGame;
use App\Models\ChessMove;
use App\Models\Clan;
use App\Models\IncomingPayment;
use App\Models\Rating;
use App\Models\SeriesMatch;
use App\Models\StackerRun;
use App\Models\Tournament;
use App\Models\TournamentPayout;
use App\Models\User;
use App\Support\GameNames;
use App\Support\LeagueTime;
use App\Support\Matches\MempoolStrip;
use App\Support\Matches\ScoreAttempts;
use App\Support\Rating\EloRating;
use App\Support\Scores\ScoreWindow;
use App\Support\SeasonChain\ChainOverview;
use App\Support\SeasonChain\SeasonChains;
use App\Support\SeasonChain\Seasons;
use App\Support\Stacker\BlockfillRules;
use App\Support\Stacker\BlockfillWeeks;
use App\Support\Tournaments\CupBoard;
use App\Support\Tournaments\TournamentChampion;
use App\Support\TwentyOne\Stream\StreamStats;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * What the start page shows in the revamp (design canvas of plan "Refactor und Design-Revamp", boards Main,
 * HomeGuest, HomePhone, HomeGuestPhone, Login): the mempool strip with its counts, the "gerade eben" ticker,
 * the games split into browser games and own-copy games with one real data line each, this week's
 * tournaments, the Blockfill week, the proud moments under the strip and the season card. Everything is read
 * from the league's own tables; a part with nothing to show is left out by the page.
 *
 * The featured tournament is HomeHub::cups() (rule R9: organizer tournaments and pots first, never a casual
 * cup). Counts that run on every home render are cached for a minute; lists are capped, so the number of
 * queries never grows with the number of players or matches (tests/Feature/HomeBoardTest.php).
 *
 * @phpstan-type Tick array{kind: string, text: string, at: CarbonInterface, href: string}
 * @phpstan-type Tile array{slug: string, name: string, href: string, meta: string}
 * @phpstan-type WeekRow array{tournament: Tournament, name: string, meta: string, href: string, featured: bool, running: bool, taken: int, places: int}
 */
final class HomeBoard
{
    /** The browser games in the order of the plan (user 2026-10-10: Pong second, so the coloured covers lead). */
    public const BROWSER_ORDER = [Hyperbitcoinization::SLUG, ProofOfPong::SLUG, 'chess', Blockfill::SLUG, 'blockli', 'nine-mens-morris', 'checkers'];

    public const OWN_ORDER = ['rocket-league', 'ea-sports-fc-27', 'ea-sports-fc-26', 'age-of-empires-2', TrackmaniaNationsForever::SLUG];

    /** Entries of the ticker, and of the week list. */
    public const TICKS = 6;

    public const WEEK = 5;

    /** @var array{finished: list<array<string, mixed>>, running: list<array<string, mixed>>, live: bool}|null */
    private ?array $strip = null;

    /** @var array{week: Tournament, number: int}|false|null */
    private array|false|null $blockfillWeek = null;

    /** @var array{value: string, name: string}|false|null */
    private array|false|null $bestToday = null;

    /** @var list<array<string, mixed>>|null */
    private ?array $cupGroups = null;

    /** @var list<Tournament>|null */
    private ?array $leagueWeeks = null;

    public function __construct(private readonly ?User $viewer) {}

    /** German thousands dots in German, commas in English ("21.000 Sats", rule R9 and facts.md). */
    public static function sats(int $sats): string
    {
        return app()->getLocale() === 'de' ? number_format($sats, 0, ',', '.') : number_format($sats);
    }

    /**
     * The league's three counts (footer on every page, login page): fresh for 60 s, then served stale for up to
     * another 60 s while one request recounts them after its response (P5g). A failing cache store is reported
     * and the counts are taken directly: the footer never takes a page down.
     *
     * @return array{players: int, clans: int, games: int}
     */
    public static function stats(): array
    {
        $count = fn (): array => [
            'players' => User::query()->count(),
            'clans' => Clan::query()->count(),
            // Every game the league plays, in one query: the stream's own count (StreamStats), so the two never disagree.
            'games' => StreamStats::played(),
        ];

        try {
            /** @var array{players: int, clans: int, games: int} */
            return Cache::flexible('footer.stats', [60, 120], $count);
        } catch (Throwable $e) {
            report($e);

            return $count();
        }
    }

    /**
     * The mempool strip of every game, highscore attempts included, as /matches draws it.
     *
     * @return array{finished: list<array<string, mixed>>, running: list<array<string, mixed>>, live: bool}
     */
    public function strip(): array
    {
        return $this->strip ??= MempoolStrip::build($this->viewer, null, runs: true);
    }

    /**
     * The strip's counts: playing now, waiting for a confirmation, and done (the games played, the footer's
     * own number, so the two never disagree). Cached for a minute: home is the busiest page.
     *
     * @return array{live: int, confirm: int, done: int}
     */
    public static function counts(): array
    {
        $read = function (): array {
            $boards = MempoolStrip::boardSlugs();
            $live = ChessGame::query()->where('status', ChessGameStatus::Active)->count()
                + SeriesMatch::query()->where('status', SeriesStatus::Accepted)->count()
                + ($boards === [] ? 0 : BoardGame::query()->whereIn('game', $boards)->where('status', BoardGameStatus::Active)->count());
            $scores = ScoreAttempts::scoreSlugs(ScoreAttempts::slugs());
            $confirm = SeriesMatch::query()->where('status', SeriesStatus::Reported)->count()
                + (ScoreAttempts::blockfill() ? ScoreAttempts::stacker('waiting')->count() : 0)
                + ($scores === [] ? 0 : ScoreAttempts::scores($scores, 'waiting')->count());

            return ['live' => $live, 'confirm' => $confirm, 'done' => self::stats()['games']];
        };

        try {
            /** @var array{live: int, confirm: int, done: int} */
            return Cache::flexible('home:mempool-counts', [60, 120], $read);
        } catch (Throwable $e) {
            report($e);

            return $read();
        }
    }

    /**
     * "gerade eben": the latest moves of running chess games, finished chess games and checked Blockfill runs,
     * newest first. Only event types the league records; three small queries.
     *
     * @return list<Tick>
     */
    public function ticker(): array
    {
        $ticks = [];

        $moves = ChessMove::query()
            ->whereIn('chess_game_id', ChessGame::query()->where('status', ChessGameStatus::Active)->select('id'))
            ->with(['game.white', 'game.black'])
            ->latest('id')->limit(2)->get();

        foreach ($moves as $move) {
            $game = $move->game;
            $white = $move->ply % 2 === 1;
            $player = ($white ? $game->white : $game->black)->displayName();
            $number = (int) ceil($move->ply / 2);
            $ticks[] = [
                'kind' => __('Chess move'),
                'text' => __(':player played :move in :game', ['player' => $player, 'move' => $number.($white ? '. ' : '… ').$move->san, 'game' => $game->number === null ? GameNames::game('chess') : $game->number()]),
                'at' => $move->created_at ?? $game->updated_at,
                'href' => route('games.show', $game),
            ];
        }

        $finished = ChessGame::query()->where('status', ChessGameStatus::Finished)->whereNotNull('ended_at')
            ->with(['white', 'black'])->latest('ended_at')->latest('id')->limit(2)->get();

        foreach ($finished as $game) {
            $mode = GameNames::mode('chess', $game->mode);
            $label = $game->number === null ? $mode : $mode.' '.$game->number();
            $ticks[] = [
                'kind' => __('Result'),
                'text' => match ($game->result) {
                    '1-0' => __(':game: :winner beats :loser', ['game' => $label, 'winner' => $game->white->displayName(), 'loser' => $game->black->displayName()]),
                    '0-1' => __(':game: :winner beats :loser', ['game' => $label, 'winner' => $game->black->displayName(), 'loser' => $game->white->displayName()]),
                    default => __(':game: :white and :black draw', ['game' => $label, 'white' => $game->white->displayName(), 'black' => $game->black->displayName()]),
                },
                'at' => $game->ended_at,
                'href' => route('games.show', $game),
            ];
        }

        if (ScoreAttempts::blockfill()) {
            $runs = ScoreAttempts::stacker('done')->whereNotNull('verified_at')->with('user')->latest('verified_at')->limit(2)->get();
            $links = $runs->isEmpty() ? [] : ScoreAttempts::links($runs->all());

            foreach ($runs as $run) {
                $ticks[] = [
                    'kind' => __('Checked run'),
                    'text' => __(':player :time in :game', ['player' => $run->user->displayName(), 'time' => (string) ScoreAttempts::value($run), 'game' => GameNames::game(Blockfill::SLUG)]),
                    'at' => $run->verified_at,
                    'href' => $links[ScoreAttempts::key($run)] ?? route('stacker.play'),
                ];
            }
        }

        usort($ticks, fn (array $a, array $b): int => $b['at'] <=> $a['at']);

        return array_slice($ticks, 0, self::TICKS);
    }

    /**
     * The running Blockfill week with its number in the Berlin calendar, null when none runs.
     *
     * @return array{week: Tournament, number: int}|null
     */
    public function blockfillWeek(): ?array
    {
        if ($this->blockfillWeek === null) {
            $week = ScoreAttempts::blockfill() ? app(BlockfillWeeks::class)->current() : null;
            $open = $week !== null && $week->status === TournamentStatus::Running && ScoreWindow::of($week)->contains(now());
            $this->blockfillWeek = $open ? ['week' => $week, 'number' => self::isoWeek($week->starts_at)] : false;
        }

        return $this->blockfillWeek === false ? null : $this->blockfillWeek;
    }

    /**
     * The fastest checked Blockfill run of today (Berlin), the strip's proud moment and the Blockfill tile's line.
     *
     * @return array{value: string, name: string}|null
     */
    public function bestToday(): ?array
    {
        if ($this->bestToday === null) {
            $run = ScoreAttempts::blockfill()
                ? ScoreAttempts::stacker('done')->whereNotNull('ticks')
                    ->where('verified_at', '>=', CarbonImmutable::now(BlockfillWeeks::TIMEZONE)->startOfDay())
                    ->with('user')->orderBy('ticks')->orderBy('id')->first()
                : null;
            $this->bestToday = $run instanceof StackerRun ? ['value' => (string) ScoreAttempts::value($run), 'name' => $run->user->displayName()] : false;
        }

        return $this->bestToday === false ? null : $this->bestToday;
    }

    /**
     * The registered games as tiles: the browser games (played on our site) first, then the own-copy games,
     * each with its one real data line.
     *
     * @return array{browser: list<Tile>, own: list<Tile>}
     */
    public function games(): array
    {
        $registry = app(GameRegistry::class);
        $browser = [];
        $own = [];

        foreach ($registry->all() as $slug => $game) {
            $tile = ['slug' => $slug, 'name' => GameNames::game($slug), 'href' => GameNames::page($slug), 'meta' => $this->meta($slug, $game->kind())];

            if ($game instanceof PlayedOnOwnCopy) {
                $own[] = $tile;
            } else {
                $browser[] = $tile;
            }
        }

        return ['browser' => self::ordered($browser, self::BROWSER_ORDER), 'own' => self::ordered($own, self::OWN_ORDER)];
    }

    /**
     * This week on the right of the start page: the featured tournaments (HomeHub), the running league weeks
     * (Blockfill, TMNF) and the casual cups open for sign-up, in that order.
     *
     * @param  list<array<string, mixed>>  $featured  HomeHub::cups()
     * @return list<WeekRow>
     */
    public function week(array $featured): array
    {
        $rows = [];

        foreach ($featured as $cup) {
            /** @var Tournament $tournament */
            $tournament = $cup['tournament'];
            $pot = $cup['pot']['sats'] ?? null;
            $places = $cup['places'];
            $rows[] = [
                'tournament' => $tournament, 'name' => self::shortName($tournament), 'href' => route('tournaments.show', $tournament), 'featured' => true, 'running' => false,
                'meta' => implode(', ', array_filter([$pot ? self::sats((int) $pot).' Sats' : null, self::dayClock($tournament->starts_at, false), $places['taken'].'/'.$places['places']])),
                'taken' => (int) $places['taken'], 'places' => (int) $places['places'],
            ];
        }

        foreach ($this->leagueWeeks() as $week) {
            $rows[] = [
                'tournament' => $week, 'name' => __(':game week number :week', ['game' => GameNames::cube($week->game), 'week' => self::isoWeek($week->starts_at)]),
                'href' => route('scores.show', $week->game), 'featured' => false, 'running' => true, 'meta' => __('Best time wins'), 'taken' => 0, 'places' => 0,
            ];
        }

        foreach ($this->cups() as $cup) {
            if ($cup['tournament']->status !== TournamentStatus::Signup) {
                continue;
            }
            $rows[] = [
                'tournament' => $cup['tournament'], 'name' => $cup['tournament']->name, 'href' => route('tournaments.show', $cup['tournament']), 'featured' => false, 'running' => false,
                'meta' => self::dayClock($cup['tournament']->starts_at, false).', '.$cup['taken'].'/'.$cup['places'], 'taken' => (int) $cup['taken'], 'places' => (int) $cup['places'],
            ];
        }

        return array_slice($rows, 0, self::WEEK);
    }

    /**
     * Proud moments under the strip (canvas NEU (Scope), with real data only): today's fastest Blockfill run,
     * the last casual cup's winner, the latest zap into the league reserve. Each is left out without data.
     *
     * @return list<array{kind: string, label: string, value: string, name: string|null, title: string|null}>
     */
    public function pride(): array
    {
        $moments = [];

        if (($best = $this->bestToday()) !== null) {
            $moments[] = ['kind' => 'best', 'label' => __('Best time today'), 'value' => $best['value'], 'name' => $best['name'], 'title' => null];
        }

        $winner = app(CupBoard::class)->lastWinner();
        if ($winner !== null) {
            $moments[] = ['kind' => 'cup', 'label' => __('Cup win'), 'value' => $winner['name'], 'name' => null, 'title' => $winner['cup']->name];
        }

        $zap = IncomingPayment::query()->where('pot', IncomingPayment::RESERVE)->where('status', IncomingPaymentStatus::Settled)
            ->latest('settled_at')->latest('id')->first(['amount_sats', 'settled_at']);
        if ($zap !== null) {
            // The latest zap in, never what the reserve holds (the wallet is external; no balance on any screen).
            $moments[] = ['kind' => 'zap', 'label' => __('League reserve'), 'value' => '+'.self::sats((int) $zap->amount_sats).' Sats', 'name' => null, 'title' => __('The latest zap into the league reserve')];
        }

        return $moments;
    }

    /**
     * The season card: Pre-Season (countdown to Block 0 or "date coming soon") or the live season, and the
     * first three eras with what a win pays. Cached for a minute (the forecast behind the draft reads wins).
     *
     * @return array{state: string, slug: string|null, live: bool, height: int|null, eras: list<array{era: int, from: CarbonImmutable, sats: int}>, payKey: string|null}
     */
    public static function season(): array
    {
        $read = function (): array {
            $live = Seasons::live();
            $overview = app(ChainOverview::class);
            $chain = $live !== null ? $overview->live($live) : $overview->draft();
            $payKey = null;

            foreach (array_keys($chain['rewards_now']) as $key) {
                if (is_string($key) && ChainOverview::mines($key)) {
                    $payKey = $key;
                    break;
                }
            }

            $eras = [];

            foreach (array_slice($chain['schedule'], 0, 3) as $row) {
                $eras[] = ['era' => (int) $row['era'], 'from' => CarbonImmutable::instance($row['from']), 'sats' => (int) ($payKey === null ? 0 : ($row['rewards'][$payKey] ?? 0))];
            }

            return [
                'state' => Seasons::state(),
                'slug' => $live?->slug,
                'live' => $live !== null,
                'height' => $live !== null ? (int) (app(SeasonChains::class)->tip($live)['height'] ?? 0) : null,
                'eras' => $eras,
                'payKey' => $payKey,
            ];
        };

        try {
            /** @var array{state: string, slug: string|null, live: bool, height: int|null, eras: list<array{era: int, from: CarbonImmutable, sats: int}>, payKey: string|null} */
            return Cache::remember('home:season-card:'.app()->getLocale(), 60, $read);
        } catch (Throwable $e) {
            report($e);

            return $read();
        }
    }

    /**
     * The viewer's most recent ladder, for "Deine Woche": its name, the rating and whether it is still provisional.
     *
     * @return array{label: string, rating: string, provisional: bool}|null
     */
    public function rating(): ?array
    {
        if ($this->viewer === null) {
            return null;
        }

        $rating = Rating::query()->where('user_id', $this->viewer->id)->where('results', '>', 0)->latest('updated_at')->latest('id')->first();

        if ($rating === null || app(GameRegistry::class)->find($rating->game) === null) {
            return null;
        }

        return [
            'label' => ($rating->pool === 'casual' ? __('Casual Elo') : __('Elo')).' '.GameNames::full($rating->game, $rating->mode),
            'rating' => self::sats($rating->rating),
            'provisional' => $rating->results < EloRating::fromConfig($rating->pool === 'casual' ? 'casual' : 'rating')->provisional,
        ];
    }

    /**
     * The latest finished tournament with a champion and a paid first place, for the login page.
     *
     * @return array{name: string, tournament: string, prize: int|null}|null
     */
    public static function lastChampion(): ?array
    {
        $tournament = Tournament::query()->special()->where('status', TournamentStatus::Finished)->whereNotNull('published_at')
            ->latest('starts_at')->latest('id')->first();
        $winner = $tournament === null ? null : app(TournamentChampion::class)->of($tournament);

        if ($tournament === null || $winner === null) {
            return null;
        }

        $prize = TournamentPayout::query()->where('tournament_id', $tournament->id)->where('place', 1)->value('amount_sats');

        return ['name' => $winner->name, 'tournament' => self::shortName($tournament), 'prize' => $prize === null ? null : (int) $prize];
    }

    /** A tournament's name up to its colon ("Blockli Cup #1: Das erste Blockli-Turnier" → "Blockli Cup #1"). */
    public static function shortName(Tournament $tournament): string
    {
        return trim(explode(':', $tournament->name, 2)[0]);
    }

    /** "Mi 19:00" (this week) or "Mi 14.10., 19:00" in the viewer's zone. */
    public static function dayClock(CarbonInterface $at, bool $date = true): string
    {
        $german = app()->getLocale() === 'de';
        $format = match (true) {
            $date && $german => 'dd D.M., HH:mm',
            $date => 'ddd D MMM, h:mm A',
            $german => 'dd HH:mm',
            default => 'ddd h:mm A',
        };

        return self::iso($at->toImmutable()->setTimezone(LeagueTime::zone()), $format);
    }

    private static function iso(CarbonInterface $at, string $format): string
    {
        return $at->locale(app()->getLocale())->isoFormat($format);
    }

    /**
     * Tiles in the given slug order, unknown slugs after them in registry order.
     *
     * @param  list<Tile>  $tiles
     * @param  list<string>  $order
     * @return list<Tile>
     */
    private static function ordered(array $tiles, array $order): array
    {
        $rank = array_flip($order);
        $keyed = [];

        foreach ($tiles as $index => $tile) {
            $keyed[] = [$rank[$tile['slug']] ?? PHP_INT_MAX, $index, $tile];
        }

        usort($keyed, fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return array_map(fn (array $row): array => $row[2], $keyed);
    }

    /** The calendar week of a moment in Berlin, as the league's weeks are numbered. */
    private static function isoWeek(CarbonInterface $at): int
    {
        return (int) $at->toImmutable()->setTimezone(BlockfillWeeks::TIMEZONE)->format('W');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function cups(): array
    {
        if ($this->cupGroups === null) {
            $groups = app(CupBoard::class)->groups(null, null, $this->viewer?->timezone, $this->viewer?->id);
            $cups = array_merge(...array_map(fn (array $group): array => $group['cups'], $groups ?: [['cups' => []]]));
            usort($cups, fn (array $a, array $b): int => $a['tournament']->starts_at <=> $b['tournament']->starts_at);
            $this->cupGroups = $cups;
        }

        return $this->cupGroups;
    }

    /** One real data line per game tile. */
    private function meta(string $slug, GameKind $kind): string
    {
        $registry = app(GameRegistry::class);
        $modes = fn (array $order = []): string => implode(', ', array_map(fn (string $mode): string => GameNames::mode($slug, $mode),
            array_values(array_unique([...array_intersect($order, array_keys($registry->find($slug)?->modes() ?? [])), ...array_keys($registry->find($slug)?->modes() ?? [])]))));

        return match (true) {
            $slug === Hyperbitcoinization::SLUG => __('Correspondence :hours h, season ladder', ['hours' => (int) config('esports.hyper.correspondence_hours')]),
            $slug === ProofOfPong::SLUG => __('First to :points, bot or live 1v1', ['points' => (int) config('esports.pong.points_to_win')]),
            $slug === Blockfill::SLUG => ($best = $this->bestToday()) !== null
                ? __(':blocks, today :time', ['blocks' => BlockfillRules::blocks(app(BlockfillWeeks::class)->difficultyAt()), 'time' => $best['value']])
                : BlockfillRules::blocks(app(BlockfillWeeks::class)->difficultyAt()),
            $slug === TrackmaniaNationsForever::SLUG => $this->tmnfLine() ?? $modes(),
            $kind === GameKind::Series => $this->cupLine($slug) ?? $modes(),
            $slug === 'chess' => implode(', ', [GameNames::mode('chess', 'blitz'), __('Rapid chess'), GameNames::mode('chess', 'correspondence')]),
            default => $modes(['blitz', 'rapid', 'correspondence']),
        };
    }

    /** "Cup EU #2: 4/8 Plätze" while a casual cup of the game is open for sign-up. */
    private function cupLine(string $slug): ?string
    {
        foreach ($this->cups() as $cup) {
            if ($cup['tournament']->game === $slug && $cup['tournament']->status === TournamentStatus::Signup) {
                $name = $cup['tournament']->name;
                $short = ($at = mb_strpos($name, 'Cup')) !== false ? mb_substr($name, $at) : $name;

                return __(':cup: :taken/:places places', ['cup' => $short, 'taken' => $cup['taken'], 'places' => $cup['places']]);
            }
        }

        return null;
    }

    /** "Woche 41 läuft" while a TMNF week runs. */
    private function tmnfLine(): ?string
    {
        foreach ($this->leagueWeeks() as $week) {
            if ($week->game === TrackmaniaNationsForever::SLUG) {
                return (string) __('Week :week running', ['week' => self::isoWeek($week->starts_at)]);
            }
        }

        return null;
    }

    /**
     * The league weeks running now (Blockfill, TMNF), one query for the week list and the TMNF tile.
     *
     * @return list<Tournament>
     */
    private function leagueWeeks(): array
    {
        if ($this->leagueWeeks === null) {
            $games = array_values(array_filter([Blockfill::SLUG, TrackmaniaNationsForever::SLUG], fn (string $slug): bool => app(GameRegistry::class)->find($slug) !== null));
            $this->leagueWeeks = $games === [] || ! Route::has('scores.show') ? [] : array_values(Tournament::query()->whereNotNull('published_at')
                ->where(['format' => TournamentFormat::Leaderboard, 'status' => TournamentStatus::Running])
                ->whereIn('game', $games)->where('starts_at', '<=', now())->orderBy('starts_at')->limit(4)->get()
                ->filter(fn (Tournament $week): bool => ScoreWindow::of($week)->contains(now()))
                ->unique('game')->all());
        }

        return $this->leagueWeeks;
    }
}
