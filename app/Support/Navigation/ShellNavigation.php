<?php

namespace App\Support\Navigation;

use App\Enums\InviteStatus;
use App\Enums\TournamentStatus;
use App\Games\Blockfill;
use App\Games\Contracts\Game;
use App\Games\GameKind;
use App\Games\GameRegistry;
use App\Models\BoardGame;
use App\Models\ChessGame;
use App\Models\ClanInvite;
use App\Models\SeriesMatch;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Dock\UpcomingEvents;
use App\Support\GameNames;
use App\Support\Matches\MempoolStrip;
use App\Support\SeasonChain\Seasons;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

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
 * @phpstan-type NavLink array{key: string, href: string, label: string, short: string, icon: string, test: ?string, mobileTest: ?string, tab: ?string, routes?: list<string>, count?: int}
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

    /** Cache key of the waiting count behind the Mempool badge. */
    public const MEMPOOL_KEY = 'shell:mempool-waiting';

    public readonly bool $isAdmin;

    public readonly bool $isOrganizer;

    /** @var list<NavGame>|null */
    private ?array $games = null;

    private ?string $pageGame = null;

    private bool $pageGameResolved = false;

    private ?string $seasonTag = null;

    private bool $seasonTagResolved = false;

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
     * games() with the board games (plan "Mühle und Dame", P7) as one block
     * next to chess: right after it, or where the first board game stands
     * when the viewer played one more recently than chess. /play and home
     * list the games in this order, so nine men's morris and checkers no
     * longer sit behind every series game at the end.
     *
     * @return list<NavGame>
     */
    public function playOrder(): array
    {
        $games = $this->games();
        $boards = array_values(array_filter($games, fn (array $game): bool => $this->registry->isBoard($game['slug'])));

        if ($boards === []) {
            return $games;
        }

        $ordered = [];
        $placed = false;

        foreach ($games as $game) {
            if ($this->registry->isBoard($game['slug'])) {
                if (! $placed) {
                    array_push($ordered, ...$boards);
                    $placed = true;
                }

                continue;
            }

            $ordered[] = $game;

            if ($game['slug'] === 'chess' && ! $placed) {
                array_push($ordered, ...$boards);
                $placed = true;
            }
        }

        return $ordered;
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
            // A board game's lobby and its games (plan "Mühle und Dame", P5): their own context bar, never chess's.
            $name === 'board.lobby', $name === 'board.correspondence' => $known($route->parameter('board')),
            $name === 'board.show' => $known($this->gameOfBoardGame($route->parameter('boardGame'))),
            $name === 'scores.show' => $known($route->parameter('game')),
            // Blockfill's game page (plan "Blockfill", P6): its own context bar, never the one of the game opened last.
            $name === 'stacker.play', $name === 'stacker.replay', $name === 'stacker.replays', $name === 'stacker.moment' => $known(Blockfill::SLUG),
            // The match list files board games too (plan "Mempool-Streifen", P2), but a board game's filter keeps row 2
            // on the game opened last: a board game's context bar has no Matches link, its games are in its lobby.
            $name === 'matches.index' => $this->registry->isBoard((string) $request->query('game')) ? null : $known($request->query('game')),
            $name === 'challenges.create' => $known($request->query('game')) ?? array_key_first($this->registry->series()),
            in_array($name, ['matches.show', 'matches.room'], true) => $this->gameOfMatch($route->parameter('match')),
            str_starts_with($name, 'tournaments.') && $route->parameter('tournament') !== null => $this->gameOfTournament($route->parameter('tournament')),
            default => null,
        };
    }

    /**
     * Cross-game destinations of row 1 besides the chain rail: Clans.
     *
     * @return list<array{key: string, href: string, label: string}>
     */
    public function community(): array
    {
        return [
            ['key' => 'clans', 'href' => route('clans.index'), 'label' => __('Clans')],
        ];
    }

    /**
     * The chain rail of row 1 (plan "Mempool-Streifen", P4) and its links
     * under Everywhere on phones: the mempool (/matches, the matches of every
     * game), the season chain (/mining, where rated wins mine blocks) and
     * the casual matches (/matches?chain=casual), which never mine. The
     * mempool carries how many matches wait in it, one cached count like
     * the Tournaments badge: it runs on every page.
     *
     * `name` is the accessible name (it holds the visible `label`),
     * `current` whether the link is the page on screen.
     *
     * @return list<array{key: string, href: string, label: string, name: string, icon: string, current: bool, count: int|null, tag: string|null}>
     */
    public function chain(?string $section = null): array
    {
        $waiting = (int) Cache::remember(self::MEMPOOL_KEY, 60, fn (): int => MempoolStrip::waiting());
        $onMatches = $this->request->routeIs('matches.index');
        $chain = $onMatches ? $this->request->query('chain') : null;
        $filtered = $onMatches && $this->request->query('game') !== null;
        $tag = $this->seasonTag();

        return [
            [
                'key' => 'mempool', 'href' => route('matches.index'), 'label' => __('Mempool'),
                'name' => $waiting > 0 ? __('Mempool').', '.trans_choice(':count match waiting|:count matches waiting', $waiting) : __('Mempool'),
                'icon' => 'matches', 'current' => $onMatches && $chain === null && ! $filtered, 'count' => $waiting > 0 ? $waiting : null, 'tag' => null,
            ],
            [
                'key' => 'mining', 'href' => route('mining'), 'label' => __('Season'), 'name' => $tag === null ? __('Season chain') : __('Season chain').', '.$tag,
                'icon' => 'mining', 'current' => $section === 'mining', 'count' => null, 'tag' => $tag,
            ],
            [
                'key' => 'casual', 'href' => route('matches.index', ['chain' => 'casual']), 'label' => __('Casual'), 'name' => __('Casual chain'),
                // A plain match cube in grey, not the season's blocks: a casual match never mines one.
                'icon' => 'matches', 'current' => $chain === 'casual' && ! $filtered, 'count' => null, 'tag' => null,
            ],
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

    /**
     * "Block 0 soon" before the first season, "Live now" while one runs,
     * nothing between seasons. Asked once per page: the chain rail and the
     * phone's sheet both show it, and the state is a query.
     */
    public function seasonTag(): ?string
    {
        if ($this->seasonTagResolved) {
            return $this->seasonTag;
        }

        $this->seasonTagResolved = true;
        $state = Seasons::state();

        if ($state === 'between') {
            return $this->seasonTag = null;
        }

        return $this->seasonTag = $state === 'live' ? __('Live now') : __('Block 0 soon');
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
            // The own hub (P30); the public player page is one click from there.
            self::link('page', route('dashboard'), __('Your page'), 'user', 'account-page', 'mobile-page'),
            $this->upcoming($user),
            match (true) {
                $clan !== null => self::link('clan', route('clans.show', $clan), __('Your clan'), 'clans', 'account-clan', 'mobile-clan'),
                $invite !== null => self::link('invite', route('invites.show', $invite), __('Clan invite from :clan', ['clan' => $invite->clan->name]), 'clans', 'account-clan-invite', 'mobile-clan-invite'),
                default => null,
            },
            // "Invite a friend": the picker, with the game of the context bar picked (InviteGames::contextGame()).
            self::link('invite-friend', route('invites.create'), __('Invite a friend'), 'link', 'account-invite', 'mobile-invite'),
            self::link('daily', route('me.correspondence'), __('Your daily games'), 'calendar'),
            self::link('settings', route('gaming.edit'), __('Settings'), 'settings', null, 'mobile-settings'),
            self::link('notifications', route('settings.notifications'), __('Notifications'), 'bell', 'account-menu-notifications', 'mobile-notifications'),
            self::link('chess-settings', route('settings.chess'), __('Chess settings'), 'settings', null, 'mobile-chess-settings'),
            self::link('badges', route('settings.badges'), __('Badges and sharing'), 'award', 'account-badges', 'mobile-badges'),
            $this->isAdmin || $this->isOrganizer ? self::link('tournaments', route('admin.tournaments'), __('Your tournaments'), 'trophy', 'account-tournaments', 'mobile-tournaments') : null,
        ]));
    }

    /**
     * The viewer's open match rooms and registered tournaments
     * (UpcomingEvents, 2026-10-02) with their count: the one event itself,
     * or home's "Your next match" card for several. Null with none.
     *
     * @return NavLink|null
     */
    public function upcoming(User $user): ?array
    {
        $items = app(UpcomingEvents::class)->for($user);

        if ($items->isEmpty()) {
            return null;
        }

        $href = $items->count() === 1 ? $items->first()->href : route('home').'#upcoming-h';

        return [...self::link('upcoming', $href, __('Your matches and events'), 'calendar', 'account-upcoming', 'mobile-upcoming'), 'count' => $items->count()];
    }

    /**
     * The strongest players across every game (P40), beside each game's
     * ladder in the context bar. It is one page for all games, so the game
     * hub leaves it out of the cards (it would repeat on each) and the phone
     * lists it once under Everywhere.
     *
     * @return NavLink
     */
    public static function strongest(): array
    {
        return self::link('strongest', route('ladder.strongest'), __('Strongest players'), 'award', null, 'mobile-strongest', __('Strongest'));
    }

    /**
     * Whether a link is the page on screen (`aria-current="page"` in the
     * context bar and the tab bar): its own URL, or a page of its `routes`
     * (the Replays tab on a replay, a week picked on the replays page).
     *
     * @param  NavLink  $link
     */
    public static function isCurrent(array $link): bool
    {
        $request = request();

        return $link['href'] === $request->fullUrl() || (($link['routes'] ?? []) !== [] && $request->routeIs(...$link['routes']));
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
                self::strongest(),
                $user ? self::link('settings', route('settings.chess'), __('Chess settings'), 'settings', null, null, __('Settings')) : null,
            ]));
        }

        if ($game->kind() === GameKind::Board) {
            return array_values(array_filter([
                self::link('play', GameNames::page($slug), __('Play blitz'), 'bolt', null, null, __('Play'), 'play'),
                // Correspondence (P8): one move a day, on its own page, for a board game that has the mode.
                // Only while the page is routed: a route table cached with the switch off has none, and every page would answer 500.
                $game->mode('correspondence') !== null && Route::has('board.correspondence') ? self::link('daily', route('board.correspondence', $slug), __('Correspondence'), 'calendar') : null,
                self::link('ladder', route('ladder.show', [$slug, array_key_first($game->modes())]), __('Ladder'), 'ladder', null, null, null, 'ladder'),
                self::link('rules', route('rules').'#'.$slug, __('Rules'), 'shield-check'),
                self::strongest(),
            ]));
        }

        // Blockfill (plan "Blockfill", P6): the game page first, then its weekly leaderboards in the ladder's place
        // (the tab bar is the same on both pages), its replays (a tab of their own on phones), and how a week works.
        if ($slug === Blockfill::SLUG && Route::has('stacker.play') && Route::has('scores.show')) {
            return array_values(array_filter([
                self::link('play', route('stacker.play'), __('Play'), 'bolt', null, null, __('Play'), 'play'),
                self::link('leaderboard', route('scores.show', $slug), __('Leaderboard'), 'trophy', null, null, null, 'ladder'),
                // Every replay the viewer may watch; the replay viewer and a shared moment (a replay too) mark this tab.
                Route::has('stacker.replays') ? [...self::link('replays', route('stacker.replays'), __('Replays'), 'play', null, null, null, 'replays'), 'routes' => ['stacker.replays', 'stacker.replay', 'stacker.moment']] : null,
                self::link('rules', route('rules').'#'.$slug, __('Rules'), 'shield-check'),
            ]));
        }

        // A score game (plan "AoE2 und Trackmania", P4): its leaderboards and points ladder on one page; no matches,
        // no challenge, no Elo ladder.
        if ($game->kind() === GameKind::Score) {
            return [self::link('play', GameNames::page($slug), __('Leaderboards'), 'trophy', null, null, __('Play'), 'play')];
        }

        if (in_array($slug, $series, true)) {
            return array_values(array_filter([
                self::link('play', GameNames::page($slug), __('Overview'), 'trophy', null, null, __('Play'), 'play'),
                self::link('matches', $matches, __('Matches'), 'matches', null, null, null, 'matches'),
                $user ? self::link('challenge', route('challenges.create', $firstSeries ? [] : ['game' => $slug]), __('Challenge a clan'), 'send',
                    $firstSeries ? 'games-menu-challenge-clan' : 'games-menu-challenge-clan-'.$slug, $firstSeries ? 'mobile-challenge-clan' : 'mobile-challenge-clan-'.$slug, __('Challenge')) : null,
                self::link('ladder', route('ladder.show', [$slug, array_key_first($game->modes())]), __('Ladder'), 'ladder', null, null, null, 'ladder'),
                self::strongest(),
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

        // The board games next to chess (plan "Mühle und Dame", P5), one query for all of them.
        if ($this->registry->boards() !== []) {
            $boards = DB::table('board_games')->where(fn ($query) => $query->where('white_id', $user->id)->orWhere('black_id', $user->id))
                ->groupBy('game')->selectRaw('game, max(created_at) as last_at')->pluck('last_at', 'game');

            foreach ($boards as $game => $at) {
                $last[(string) $game] = (string) $at;
            }
        }

        return $last;
    }

    private function gameOfBoardGame(mixed $boardGame): ?string
    {
        if ($boardGame instanceof BoardGame) {
            return $boardGame->game;
        }

        return is_numeric($boardGame) ? BoardGame::query()->whereKey((int) $boardGame)->value('game') : null;
    }

    /** The room's `{match}` arrives bound (its mount takes a SeriesMatch), the match page's as the number. */
    private function gameOfMatch(mixed $number): ?string
    {
        if ($number instanceof SeriesMatch) {
            return $number->game;
        }

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
