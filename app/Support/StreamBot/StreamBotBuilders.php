<?php

namespace App\Support\StreamBot;

use App\Enums\ChessGameStatus;
use App\Enums\SeriesStatus;
use App\Enums\TournamentStatus;
use App\Games\GameRegistry;
use App\Models\ChessGame;
use App\Models\Clan;
use App\Models\RankBadgeVersion;
use App\Models\Rating;
use App\Models\SeriesMatch;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Nostr\NostrKeys;
use App\Support\PreSeason;
use App\Support\Prizes\PrizePool;
use App\Support\Rating\RankTiers;
use App\Support\Rating\Ratings;
use App\Support\SeasonChain\Seasons;
use App\Support\Tournaments\TournamentChampion;
use App\Support\Tournaments\TournamentLanding;
use Carbon\CarbonImmutable;
use Closure;
use LogicException;

/**
 * The stream chat bot's message builders (P22). Each one reads the database
 * (never a relay) and offers zero or more messages, best first; no data
 * means no message, so the bot never posts a stale or made-up fact. The
 * words are in StreamBotCopy; this class only picks the facts and links.
 *
 * Fact builders report what is going on (tournaments, live games, results);
 * feature builders explain what one can do on the site. The engine prefers
 * facts (FACT_WEIGHT) and keeps both from repeating.
 */
final class StreamBotBuilders
{
    /** How much likelier a fact builder is drawn than a feature tip. */
    public const FACT_WEIGHT = 3;

    /** A tournament whose sign-up closes within this many hours gets a "last call". */
    public const LAST_CALL_HOURS = 3;

    /** A series counts as happening from its start for this many hours. */
    public const SERIES_HOURS = 3;

    /** Messages with numbers are read in this locale, whatever the process runs in. */
    private const LOCALE = 'en';

    /** @var (Closure(int): int)|null picks a variant; random by default */
    private ?Closure $variantPicker = null;

    public function __construct(
        private GameRegistry $games,
        private TournamentChampion $champions,
        private PrizePool $pools,
    ) {}

    /**
     * Builder name => true for a fact builder, false for a feature tip.
     *
     * @return array<string, bool>
     */
    public function all(): array
    {
        return [
            'tournament_signup' => true,
            'tournament_last_call' => true,
            'tournament_live' => true,
            'live_game' => true,
            'live_games' => true,
            'live_series' => true,
            'tournament_winner' => true,
            'rank_up' => true,
            'new_clan' => true,
            'ladder_top' => true,
            'season' => true,
            'stats' => true,
            'play_blitz' => false,
            'daily_chess' => false,
            'clan_challenge' => false,
            'invite_friend' => false,
            'clans' => false,
            'badges' => false,
            'all_games' => false,
            'login' => false,
            'zap' => false,
        ];
    }

    /**
     * Tests pin the variant; production draws one at random.
     *
     * @param  (Closure(int): int)|null  $picker  gets the number of variants, returns an index
     */
    public function pickVariantsWith(?Closure $picker): void
    {
        $this->variantPicker = $picker;
    }

    /**
     * @return list<StreamBotMessage>
     */
    public function build(string $builder, CarbonImmutable $now): array
    {
        $previous = app()->getLocale();
        app()->setLocale(self::LOCALE);

        try {
            return match ($builder) {
                'tournament_signup' => $this->tournamentSignup($now),
                'tournament_last_call' => $this->tournamentLastCall($now),
                'tournament_live' => $this->tournamentLive(),
                'live_game' => $this->liveGame(),
                'live_games' => $this->liveGames(),
                'live_series' => $this->liveSeries($now),
                'tournament_winner' => $this->tournamentWinner($now),
                'rank_up' => $this->rankUp($now),
                'new_clan' => $this->newClan($now),
                'ladder_top' => $this->ladderTop($now),
                'season' => $this->season($now),
                'stats' => $this->stats($now),
                'play_blitz' => $this->feature('play_blitz', route('chess.lobby')),
                'daily_chess' => $this->feature('daily_chess', route('chess.challenge')),
                'clan_challenge' => $this->clanChallenge(),
                'invite_friend' => $this->feature('invite_friend', route('chess.lobby')),
                'clans' => $this->clans(),
                'badges' => $this->feature('badges', route('ladder.show', ['chess', 'blitz'])),
                'all_games' => $this->allGames(),
                'login' => $this->feature('login', route('login')),
                'zap' => $this->zap(),
                default => throw new LogicException("Unknown stream bot builder [{$builder}]."),
            };
        } finally {
            app()->setLocale($previous);
        }
    }

    /**
     * Every tournament open for sign-up, soonest sign-up close first.
     *
     * @return list<StreamBotMessage>
     */
    private function tournamentSignup(CarbonImmutable $now): array
    {
        $messages = [];

        foreach ($this->openTournaments($now) as $tournament) {
            $name = StreamBotCopy::clean($tournament->name);

            if ($name === '') {
                continue;
            }

            $landing = new TournamentLanding($tournament, null);

            // Inside the last-call window the "last call" says it; one of the two is enough.
            if ($this->inLastCall($tournament, $now) && $landing->openSeats() > 0) {
                continue;
            }

            $places = $landing->places();
            $pot = $this->potSats($tournament);

            $messages[] = $this->message('tournament_signup', 'tournament-signup:'.$tournament->id, [
                'name' => $name,
                'game' => $this->gameLine($tournament->game, $tournament->mode),
                'starts' => $this->berlin($tournament->starts_at),
                'spots' => $places['taken'].' of '.$places['places'].' spots taken',
                'pot' => $pot === null ? '' : ' · 💰 '.number_format($pot).' sats in the pot',
                'url' => route('tournaments.show', $tournament),
            ]);
        }

        return $messages;
    }

    /**
     * Sign-up closes within LAST_CALL_HOURS and there is still room.
     *
     * @return list<StreamBotMessage>
     */
    private function tournamentLastCall(CarbonImmutable $now): array
    {
        $messages = [];

        foreach ($this->openTournaments($now) as $tournament) {
            $closes = $tournament->signup_closes_at;
            $name = StreamBotCopy::clean($tournament->name);

            if ($closes === null || ! $this->inLastCall($tournament, $now) || $name === '') {
                continue;
            }

            $open = (new TournamentLanding($tournament, null))->openSeats();

            if ($open === 0) {
                continue;
            }

            $messages[] = $this->message('tournament_last_call', 'tournament-last-call:'.$tournament->id, [
                'name' => $name,
                'left' => $this->duration($closes->getTimestamp() - $now->getTimestamp()),
                'open' => $open.' '.($open === 1 ? 'spot' : 'spots'),
                'game' => $this->gameLine($tournament->game, $tournament->mode),
                'url' => route('tournaments.signup', $tournament),
            ]);
        }

        return $messages;
    }

    /**
     * @return list<StreamBotMessage>
     */
    private function tournamentLive(): array
    {
        $messages = [];

        foreach (Tournament::query()->where('status', TournamentStatus::Running)->orderBy('starts_at')->orderBy('id')->get() as $tournament) {
            $name = StreamBotCopy::clean($tournament->name);

            if ($name !== '') {
                $messages[] = $this->message('tournament_live', 'tournament-live:'.$tournament->id, [
                    'name' => $name,
                    'url' => route('tournaments.tv', $tournament),
                ]);
            }
        }

        return $messages;
    }

    /**
     * Live (not daily) chess games, newest first, both players still with an account.
     *
     * @return list<StreamBotMessage>
     */
    private function liveGame(): array
    {
        $messages = [];
        $games = ChessGame::query()->live()->where('status', ChessGameStatus::Active)
            ->with(['white', 'black'])->latest('id')->limit(5)->get();

        foreach ($games as $game) {
            // A player who deleted the account leaves a null id (the relation is typed as always set).
            if ($game->white_id === null || $game->black_id === null) {
                continue;
            }

            $white = StreamBotCopy::clean($game->white->displayName(), 24);
            $black = StreamBotCopy::clean($game->black->displayName(), 24);

            if ($white === '' || $black === '') {
                continue;
            }

            $messages[] = $this->message('live_game', 'live-game:'.$game->id, [
                'white' => $white,
                'black' => $black,
                'mode' => $this->games->mode('chess', $game->mode)->name ?? 'Chess',
                'url' => route('games.show', $game),
            ]);
        }

        return $messages;
    }

    /**
     * Two or more live chess games: the page with all of them.
     *
     * @return list<StreamBotMessage>
     */
    private function liveGames(): array
    {
        $ids = ChessGame::query()->live()->where('status', ChessGameStatus::Active)->orderBy('id')->pluck('id')->all();

        if (count($ids) < 2) {
            return [];
        }

        return [$this->message('live_games', 'live-games:'.implode(',', $ids), [
            'count' => count($ids),
            'url' => route('games.index'),
        ])];
    }

    /**
     * Accepted series whose start was within the last SERIES_HOURS.
     *
     * @return list<StreamBotMessage>
     */
    private function liveSeries(CarbonImmutable $now): array
    {
        $messages = [];
        $series = SeriesMatch::query()->where('status', SeriesStatus::Accepted)
            ->whereBetween('start_at', [$now->subHours(self::SERIES_HOURS), $now])
            ->orderByDesc('start_at')->limit(5)->get();

        foreach ($series as $match) {
            $home = StreamBotCopy::clean($match->challenger_name, 24);
            $away = StreamBotCopy::clean($match->challenged_name, 24);

            if ($home === '' || $away === '') {
                continue;
            }

            $messages[] = $this->message('live_series', 'live-series:'.$match->id, [
                'game' => $this->games->name($match->game),
                'home' => $home,
                'away' => $away,
                'best_of' => $match->best_of,
                'url' => route('matches.show', $match->number),
            ]);
        }

        return $messages;
    }

    /**
     * Winners of tournaments that finished within `winner_days`, newest first.
     *
     * @return list<StreamBotMessage>
     */
    private function tournamentWinner(CarbonImmutable $now): array
    {
        $messages = [];
        $finished = Tournament::query()->where('status', TournamentStatus::Finished)
            ->where('updated_at', '>=', $now->subDays((int) config('esports.stream_bot.winner_days', 14)))
            ->latest('updated_at')->limit(3)->get();

        foreach ($finished as $tournament) {
            $champion = $this->champions->of($tournament);
            $winner = StreamBotCopy::clean($champion?->user?->displayName() ?? $champion?->name, 32);
            $name = StreamBotCopy::clean($tournament->name);

            if ($winner === '' || $name === '') {
                continue;
            }

            $messages[] = $this->message('tournament_winner', 'tournament-winner:'.$tournament->id, [
                'winner' => $winner,
                'name' => $name,
                'url' => route('tournaments.show', $tournament),
            ]);
        }

        return $messages;
    }

    /**
     * Rank ups (a first rank or a higher tier) of the last `rank_up_hours`.
     *
     * @return list<StreamBotMessage>
     */
    private function rankUp(CarbonImmutable $now): array
    {
        $messages = [];
        $versions = RankBadgeVersion::query()->with('badge.user')
            ->where('created_at', '>=', $now->subHours((int) config('esports.stream_bot.rank_up_hours', 48)))
            ->latest('id')->limit(10)->get();

        foreach ($versions as $version) {
            $user = $version->badge->user;
            $player = StreamBotCopy::clean($user?->displayName(), 32);

            if ($user === null || $player === '' || ! $version->isRankUp()) {
                continue;
            }

            $messages[] = $this->message('rank_up', 'rank-up:'.$version->id, [
                'player' => $player,
                'tier' => RankTiers::label($version->tier),
                'game' => $this->gameLine($version->badge->game, $version->badge->mode),
                'url' => route('players.show', NostrKeys::hexToNpub($user->pubkey)),
            ]);
        }

        return $messages;
    }

    /**
     * Clans founded within `clan_days`, newest first.
     *
     * @return list<StreamBotMessage>
     */
    private function newClan(CarbonImmutable $now): array
    {
        $messages = [];
        $clans = Clan::query()->where('created_at', '>=', $now->subDays((int) config('esports.stream_bot.clan_days', 7)))
            ->latest('id')->limit(5)->get();

        foreach ($clans as $clan) {
            $name = StreamBotCopy::clean($clan->name, 32);
            $tag = StreamBotCopy::clean($clan->clantag, 8);

            if ($name === '' || $tag === '') {
                continue;
            }

            $messages[] = $this->message('new_clan', 'new-clan:'.$clan->id, [
                'name' => $name,
                'tag' => $tag,
                'url' => route('clans.show', $clan),
            ]);
        }

        return $messages;
    }

    /**
     * The top three of the casual blitz ladder, as the ladder page orders it.
     *
     * @return list<StreamBotMessage>
     */
    private function ladderTop(CarbonImmutable $now): array
    {
        $rows = Rating::query()
            ->where(['pool' => Rating::CASUAL, 'season' => Ratings::season(Rating::CASUAL, 'chess', 'blitz'), 'game' => 'chess', 'mode' => 'blitz'])
            ->where('results', '>', 0)
            ->with('user')
            ->orderByDesc('rating')->orderByDesc('results')->orderBy('id')
            ->limit(3)->get();

        $podium = [];

        foreach ($rows->values() as $index => $row) {
            $name = StreamBotCopy::clean($row->user?->displayName(), 20);

            if ($name !== '') {
                $podium[] = ['🥇', '🥈', '🥉'][$index].' '.$name.' '.$row->rating;
            }
        }

        if ($podium === []) {
            return [];
        }

        return [$this->message('ladder_top', 'ladder-top:'.$now->format('Y-m-d').':'.implode(',', $rows->pluck('user_id')->all()), [
            'podium' => implode(' · ', $podium),
            'url' => route('ladder.show', ['chess', 'blitz']),
        ])];
    }

    /**
     * A live season, or the countdown to a planned Block 0. Nothing between
     * seasons or before a date is set: no promise the board has not made.
     *
     * @return list<StreamBotMessage>
     */
    private function season(CarbonImmutable $now): array
    {
        $state = Seasons::state();

        if ($state === 'live') {
            return [$this->message('season_live', 'season-live:'.Seasons::live()?->slug, ['url' => route('mining')], 'season')];
        }

        $block0At = PreSeason::block0At();

        if ($state !== 'pre-launch' || $block0At === null || $block0At->getTimestamp() <= $now->getTimestamp()) {
            return [];
        }

        return [$this->message('season_countdown', 'season-countdown:'.$block0At->getTimestamp(), [
            'left' => $this->duration($block0At->getTimestamp() - $now->getTimestamp()),
            'url' => route('mining'),
        ], 'season')];
    }

    /**
     * @return list<StreamBotMessage>
     */
    private function stats(CarbonImmutable $now): array
    {
        $players = User::query()->count();

        if ($players === 0) {
            return [];
        }

        return [$this->message('stats', 'stats:'.$now->format('Y-m-d'), [
            'players' => number_format($players),
            'clans' => number_format(Clan::query()->count()),
            'games' => number_format(ChessGame::query()->where('status', ChessGameStatus::Finished)->count()),
            'url' => route('home'),
        ])];
    }

    /**
     * @return list<StreamBotMessage>
     */
    private function clanChallenge(): array
    {
        $names = array_values(array_unique(array_map(fn ($game): string => $game->name(), $this->games->series())));

        if ($names === []) {
            return [];
        }

        return $this->feature('clan_challenge', route('challenges.create'), ['games' => $this->list($names)]);
    }

    /**
     * @return list<StreamBotMessage>
     */
    private function clans(): array
    {
        $count = Clan::query()->count();

        return $this->feature('clans', route('clans.index'), ['count' => $count > 1 ? number_format($count) : null]);
    }

    /**
     * @return list<StreamBotMessage>
     */
    private function allGames(): array
    {
        $names = array_values(array_unique(array_map(fn ($game): string => $game->name(), $this->games->all())));

        if ($names === []) {
            return [];
        }

        return $this->feature('all_games', route('play'), ['games' => $this->list($names)]);
    }

    /**
     * Only when the stream's profile has a Lightning address to zap (the
     * address itself is never written out).
     *
     * @return list<StreamBotMessage>
     */
    private function zap(): array
    {
        return filled(config('twentyone.profile.lud16')) ? $this->feature('zap', route('home')) : [];
    }

    /**
     * A feature tip: one message, its fact is the feature itself.
     *
     * @param  array<string, string|int|null>  $values
     * @return list<StreamBotMessage>
     */
    private function feature(string $name, string $url, array $values = []): array
    {
        return [$this->message($name, 'feature:'.$name, ['url' => $url, ...$values])];
    }

    /**
     * @param  array<string, string|int|null>  $values
     */
    private function message(string $template, string $factKey, array $values, ?string $builder = null): StreamBotMessage
    {
        $variants = StreamBotCopy::variants($template);
        $variant = $this->variantPicker !== null ? ($this->variantPicker)($variants) : random_int(0, max(0, $variants - 1));

        return new StreamBotMessage($builder ?? $template, $factKey, StreamBotCopy::render($template, $variant, $values));
    }

    /**
     * @return list<Tournament>
     */
    private function openTournaments(CarbonImmutable $now): array
    {
        return array_values(Tournament::query()->where('status', TournamentStatus::Signup)->where('signup_closes_at', '>', $now)
            ->orderBy('signup_closes_at')->orderBy('id')->limit(5)->get()->all());
    }

    private function inLastCall(Tournament $tournament, CarbonImmutable $now): bool
    {
        return $tournament->signup_closes_at !== null
            && $tournament->signup_closes_at->getTimestamp() <= $now->addHours(self::LAST_CALL_HOURS)->getTimestamp();
    }

    /**
     * The pot when it is real: sats the league holds for it, or a fresh
     * balance of its own wallet; null for none, zero or a stale read.
     */
    private function potSats(Tournament $tournament): ?int
    {
        $configured = $tournament->prizeMode() === Tournament::PRIZES_FIXED || $tournament->prize_target_sats !== null;

        if ($tournament->hasOwnWallet() && ! $configured && PrizePool::isBalanceStale($tournament)) {
            return null;
        }

        $pot = $this->pools->potSats($tournament);

        return $pot !== null && $pot > 0 ? $pot : null;
    }

    private function gameLine(string $game, string $mode): string
    {
        $modeName = $this->games->mode($game, $mode)->name ?? $mode;

        return trim($this->games->name($game).' '.$modeName);
    }

    /** "Sat 4 Oct, 20:00 CEST" in the bot's time zone (Berlin). */
    private function berlin(\DateTimeInterface $moment): string
    {
        return CarbonImmutable::instance($moment)->setTimezone((string) config('esports.stream_bot.timezone', 'Europe/Berlin'))->format('D j M, H:i T');
    }

    /** "3 days 4 h", "2 h 15 min", "40 min". */
    private function duration(int $seconds): string
    {
        $seconds = max(60, $seconds);
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        if ($days > 0) {
            return $days.' '.($days === 1 ? 'day' : 'days').($hours > 0 ? ' '.$hours.' h' : '');
        }

        return $hours > 0 ? $hours.' h'.($minutes > 0 ? ' '.$minutes.' min' : '') : $minutes.' min';
    }

    /**
     * "A", "A and B", "A, B and C".
     *
     * @param  list<string>  $names
     */
    private function list(array $names): string
    {
        $last = array_pop($names);

        return $names === [] ? (string) $last : implode(', ', $names).' and '.$last;
    }
}
