<?php

namespace App\Support\Navigation;

use App\Enums\InviteStatus;
use App\Enums\TournamentStatus;
use App\Games\Contracts\Game;
use App\Games\GameRegistry;
use App\Models\ChessGame;
use App\Models\ClanInvite;
use App\Models\SeriesMatch;
use App\Models\Tournament;
use App\Models\User;
use App\Support\GameNames;
use App\Support\SeasonChain\Seasons;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Every list of the shell navigation (header concept B, "game tabs"), built
 * once per page by <x-shell.header>: the game tabs of row 1, the context bar
 * of the active game (row 2), the game hub, the account menu and the mobile
 * tab bar and sheets all read these lists, so a page linked in one of them
 * is linked in the others (P16, tests/Browser/NavigationMenusTest.php).
 *
 * The active game is the game of the current page; on a page that belongs
 * to no game (clans, tournaments, a profile, admin) it is the game last
 * opened in this session, then the viewer's last played game, then the first
 * registered game. Row 2 always shows the active game, so the rule is the
 * same on every page and row 2 always names the game it is about.
 *
 * @phpstan-type NavLink array{key: string, href: string, label: string, short: string, icon: string, test: ?string, mobileTest: ?string, tab: ?string}
 * @phpstan-type NavGame array{slug: string, name: string, short: string, colour: string, formats: string, kinds: list<string>, page: string, played: bool, actions: list<NavLink>}
 */
final class ShellNavigation
{
    /** The game last opened in this session, for pages that belong to no game. */
    public const SESSION_KEY = 'nav.game';

    /** Game tabs in row 1 at the widest tier; fewer at narrower widths (app.css `.gtab`). */
    public const TABS = 3;

    /** Cache key of the open-for-sign-up count behind the Tournaments badge. */
    public const OPEN_TOURNAMENTS_KEY = 'shell:tournaments-open';

    public readonly bool $isAdmin;

    public readonly bool $isOrganizer;

    /** @var list<NavGame>|null */
    private ?array $games = null;

    private ?string $pageGame = null;

    private bool $pageGameResolved = false;

    public function __construct(
        public readonly ?User $user,
        private readonly Request $request,
        private readonly GameRegistry $registry,
    ) {
        // Asked once per page: the gate reads the admins table (P5g).
        $this->isAdmin = (bool) $user?->can('admin');
        $this->isOrganizer = ! $this->isAdmin && (bool) $user?->isTournamentOrganizer();
    }

    public static function current(): self
    {
        $user = auth()->user();

        return new self($user instanceof User ? $user : null, request(), app(GameRegistry::class));
    }

    /**
     * Every registered game, the viewer's games first (by their last match),
     * the others in registry order.
     *
     * @return list<NavGame>
     */
    public function games(): array
    {
        if ($this->games !== null) {
            return $this->games;
        }

        $lastPlayed = $this->lastPlayed();
        $games = [];

        foreach (array_values($this->registry->all()) as $index => $game) {
            $games[] = [$game, $lastPlayed[$game->slug()] ?? null, $index];
        }

        // Played games by their last match, newest first; the rest keep the registry order.
        usort($games, fn (array $a, array $b): int => [$a[1] === null, $b[1] ?? '', $a[2]] <=> [$b[1] === null, $a[1] ?? '', $b[2]]);

        return $this->games = array_map(fn (array $entry): array => $this->describe($entry[0], $entry[1] !== null), $games);
    }

    /**
     * The game tabs of row 1: the active game first, then the next games in
     * their usual order, TABS in all. Narrower widths hide tabs from the end
     * (the gtab-N tiers), so the active game has to hold the first slot to
     * stay visible at every width.
     *
     * @return list<NavGame>
     */
    public function tabs(): array
    {
        $active = $this->activeGame();
        $others = array_values(array_filter($this->games(), fn (array $game): bool => $game['slug'] !== $active['slug']));

        return [$active, ...array_slice($others, 0, self::TABS - 1)];
    }

    /** @return NavGame */
    public function activeGame(): array
    {
        $games = $this->games();
        $bySlug = array_column($games, null, 'slug');
        $remembered = $this->request->hasSession() ? $this->request->session()->get(self::SESSION_KEY) : null;

        foreach ([$this->pageGame(), $remembered] as $slug) {
            if (is_string($slug) && isset($bySlug[$slug])) {
                return $bySlug[$slug];
            }
        }

        // The viewer's last played game is first in games(); without any, the first registered game.
        return $games[0];
    }

    /** Whether the current page belongs to the active game (its tab is then `aria-current="page"`). */
    public function onGamePage(): bool
    {
        return $this->pageGame() !== null;
    }

    /** Remember the game of this page for the pages that belong to no game. */
    public function remember(): void
    {
        $slug = $this->pageGame();

        if ($slug !== null && $this->request->hasSession() && $this->request->session()->get(self::SESSION_KEY) !== $slug) {
            $this->request->session()->put(self::SESSION_KEY, $slug);
        }
    }

    /**
     * The game the current page belongs to, or null for a page of every game.
     */
    public function pageGame(): ?string
    {
        if ($this->pageGameResolved) {
            return $this->pageGame;
        }

        $this->pageGameResolved = true;
        $request = $this->request;
        $route = $request->route();
        $name = is_object($route) ? $route->getName() : null;
        $known = fn (mixed $slug): ?string => is_string($slug) && $this->registry->find($slug) !== null ? $slug : null;

        return $this->pageGame = match (true) {
            $name === null => null,
            str_starts_with($name, 'chess.'), in_array($name, ['games.index', 'games.show', 'me.correspondence', 'settings.chess'], true) => $known('chess'),
            $name === 'games.rocket-league' => $known('rocket-league'),
            $name === 'games.series' => $known($route->parameter('slug')),
            $name === 'ladder.show' => $known($route->parameter('game')),
            $name === 'matches.index' => $known($request->query('game')),
            $name === 'challenges.create' => $known($request->query('game')) ?? array_key_first($this->registry->series()),
            in_array($name, ['matches.show', 'matches.room'], true) => $this->gameOfMatch($route->parameter('match')),
            str_starts_with($name, 'tournaments.') && $route->parameter('tournament') !== null => $this->gameOfTournament($route->parameter('tournament')),
            default => null,
        };
    }

    /**
     * Cross-game destinations of row 1: Clans and Season.
     *
     * @return list<array{key: string, href: string, label: string}>
     */
    public function community(): array
    {
        return [
            ['key' => 'clans', 'href' => route('clans.index'), 'label' => __('Clans')],
            ['key' => 'mining', 'href' => route('mining'), 'label' => __('Season')],
        ];
    }

    /**
     * Tournaments, a top-level entry of row 1 and the tab bar, with the
     * number of tournaments open for sign-up right now (0: no badge). One
     * count query, cached for a minute: it runs on every page.
     *
     * @return array{href: string, label: string, open: int}
     */
    public function tournaments(): array
    {
        $open = (int) Cache::remember(self::OPEN_TOURNAMENTS_KEY, 60, fn (): int => Tournament::query()
            ->where('status', TournamentStatus::Signup)
            ->where('signup_closes_at', '>', now())
            ->count());

        return ['href' => route('tournaments.index'), 'label' => __('Tournaments'), 'open' => $open];
    }

    /** "Block 0 soon" before the first season, "Live now" while one runs, nothing between seasons. */
    public function seasonTag(): ?string
    {
        $state = Seasons::state();

        if ($state === 'between') {
            return null;
        }

        return $state === 'live' ? __('Live now') : __('Block 0 soon');
    }

    /**
     * The admin entry of row 1, with the number of open cases (disputes waiting), for admins only.
     *
     * @return array{href: string, count: int}|null
     */
    public function admin(): ?array
    {
        return $this->isAdmin ? ['href' => route('admin.disputes'), 'count' => SeriesMatch::query()->openCase()->count()] : null;
    }

    /**
     * The account menu of a logged-in player (the "You" section on phones).
     *
     * @return list<NavLink>
     */
    public function account(): array
    {
        $user = $this->user;

        if ($user === null) {
            return [];
        }

        // The clan a player is in, else an invite waiting for their answer (P16: both were only a notification away).
        $clan = $user->clanMember?->clan;
        $invite = $clan === null
            ? ClanInvite::query()->where('invitee_id', $user->id)->where('status', InviteStatus::Pending)->with('clan')->latest()->first()
            : null;

        return array_values(array_filter([
            self::link('page', route('players.show', $user->npub), __('Your page'), 'user', 'account-page', 'mobile-page'),
            match (true) {
                $clan !== null => self::link('clan', route('clans.show', $clan), __('Your clan'), 'clans', 'account-clan', 'mobile-clan'),
                $invite !== null => self::link('invite', route('invites.show', $invite), __('Clan invite from :clan', ['clan' => $invite->clan->name]), 'clans', 'account-clan-invite', 'mobile-clan-invite'),
                default => null,
            },
            self::link('daily', route('me.correspondence'), __('Your daily games'), 'calendar'),
            self::link('settings', route('gaming.edit'), __('Settings'), 'settings', null, 'mobile-settings'),
            self::link('notifications', route('settings.chess').'#notifications', __('Notifications'), 'bell', 'account-menu-notifications', 'mobile-notifications'),
            self::link('chess-settings', route('settings.chess'), __('Chess settings'), 'settings', null, 'mobile-chess-settings'),
            self::link('badges', route('settings.badges'), __('Badges and sharing'), 'award', 'account-badges', 'mobile-badges'),
            $this->isAdmin || $this->isOrganizer ? self::link('tournaments', route('admin.tournaments'), __('Your tournaments'), 'trophy', 'account-tournaments', 'mobile-tournaments') : null,
        ]));
    }

    /** @return NavLink */
    public static function link(string $key, string $href, string $label, string $icon, ?string $test = null, ?string $mobileTest = null, ?string $short = null, ?string $tab = null): array
    {
        return ['key' => $key, 'href' => $href, 'label' => $label, 'short' => $short ?? $label, 'icon' => $icon, 'test' => $test, 'mobileTest' => $mobileTest, 'tab' => $tab];
    }

    /** @return NavGame */
    private function describe(Game $game, bool $played): array
    {
        $slug = $game->slug();
        $modes = $game->modes();
        $kinds = array_values(array_filter([
            array_filter($modes, fn ($mode) => $mode->rates === 'player') !== [] ? 'solo' : null,
            array_filter($modes, fn ($mode) => $mode->rates === 'lineup' || $mode->boards !== []) !== [] ? 'clan' : null,
        ]));

        return [
            'slug' => $slug,
            'name' => GameNames::game($slug),
            'short' => __($game->assets()->shortLabel),
            'colour' => $game->assets()->colour,
            'formats' => implode(', ', array_map(fn ($mode): string => __($mode->name), array_values($modes))),
            'kinds' => $kinds,
            'page' => GameNames::page($slug),
            'played' => $played,
            'actions' => $this->actions($game),
        ];
    }

    /**
     * What a player does in one game: the context bar of row 2, the game's
     * links in the hub and the first three tabs of the phone's tab bar
     * (`tab`: play, matches, ladder). These are the links of the games menu
     * before concept B, with their test hooks.
     *
     * @return list<NavLink>
     */
    private function actions(Game $game): array
    {
        $slug = $game->slug();
        $user = $this->user;
        $series = array_keys($this->registry->series());
        $firstSeries = ($series[0] ?? null) === $slug;
        $matches = route('matches.index', ['game' => $slug]);

        if ($slug === 'chess') {
            return array_values(array_filter([
                self::link('play', route('chess.lobby'), __('Play blitz'), 'bolt', null, null, __('Play'), 'play'),
                $user ? self::link('daily', route('me.correspondence'), __('Daily games'), 'calendar', null, null, __('Daily')) : null,
                $user ? self::link('challenge', route('chess.challenge'), __('Challenge a player'), 'send', 'games-menu-challenge', 'mobile-challenge-player', __('Challenge')) : null,
                self::link('watch', route('games.index'), __('Watch live'), 'eye', 'games-menu-live', 'mobile-live-games', __('Watch')),
                self::link('matches', $matches, __('Matches'), 'matches', null, null, null, 'matches'),
                self::link('ladder', route('ladder.show', ['chess', 'blitz']), __('Ladder'), 'ladder', null, null, null, 'ladder'),
                $user ? self::link('settings', route('settings.chess'), __('Chess settings'), 'settings', null, null, __('Settings')) : null,
            ]));
        }

        if (in_array($slug, $series, true)) {
            return array_values(array_filter([
                self::link('play', GameNames::page($slug), __('Overview'), 'trophy', null, null, __('Play'), 'play'),
                self::link('matches', $matches, __('Matches'), 'matches', null, null, null, 'matches'),
                $user ? self::link('challenge', route('challenges.create', $firstSeries ? [] : ['game' => $slug]), __('Challenge a clan'), 'send',
                    $firstSeries ? 'games-menu-challenge-clan' : 'games-menu-challenge-clan-'.$slug, $firstSeries ? 'mobile-challenge-clan' : 'mobile-challenge-clan-'.$slug, __('Challenge')) : null,
                self::link('ladder', route('ladder.show', [$slug, array_key_first($game->modes())]), __('Ladder'), 'ladder', null, null, null, 'ladder'),
            ]));
        }

        // A registered game without its own pages yet: its page is the only link.
        return [self::link('play', GameNames::page($slug), __('Overview'), 'trophy', null, null, __('Play'), 'play')];
    }

    /**
     * When the viewer last played each game: one query for chess, one for the
     * series games (by the lineups they sit in). Empty for guests.
     *
     * @return array<string, string>
     */
    private function lastPlayed(): array
    {
        $user = $this->user;

        if ($user === null) {
            return [];
        }

        $last = [];
        $chess = ChessGame::query()->where(fn ($query) => $query->where('white_id', $user->id)->orWhere('black_id', $user->id))->max('created_at');

        if ($chess !== null) {
            $last['chess'] = (string) $chess;
        }

        $series = DB::table('series_matches')
            ->join('lineup_seats', fn ($join) => $join->on('lineup_seats.lineup_id', '=', 'series_matches.challenger_lineup_id')->orOn('lineup_seats.lineup_id', '=', 'series_matches.challenged_lineup_id'))
            ->where('lineup_seats.user_id', $user->id)
            ->groupBy('series_matches.game')
            ->selectRaw('series_matches.game as game, max(series_matches.created_at) as last_at')
            ->pluck('last_at', 'game');

        foreach ($series as $game => $at) {
            $last[(string) $game] = (string) $at;
        }

        return $last;
    }

    private function gameOfMatch(mixed $number): ?string
    {
        if (! is_numeric($number)) {
            return null;
        }

        $game = SeriesMatch::query()->where('number', (int) $number)->value('game');

        return is_string($game) ? $game : (ChessGame::query()->where('number', (int) $number)->exists() ? 'chess' : null);
    }

    private function gameOfTournament(mixed $tournament): ?string
    {
        $game = $tournament instanceof Tournament ? $tournament->game : (is_numeric($tournament) ? Tournament::query()->whereKey((int) $tournament)->value('game') : null);

        return is_string($game) && $this->registry->find($game) !== null ? $game : null;
    }
}
