<?php

namespace App\Support\StreamBot;

use App\Enums\ChessGameStatus;
use App\Enums\SeriesStatus;
use App\Enums\TournamentStatus;
use App\Games\Blockfill;
use App\Games\Blockli;
use App\Games\Checkers;
use App\Games\GameRegistry;
use App\Games\Hyperbitcoinization;
use App\Games\NineMensMorris;
use App\Games\ProofOfPong;
use App\Games\ScoreMetric;
use App\Games\TrackmaniaNationsForever;
use App\Models\ChessGame;
use App\Models\Clan;
use App\Models\RankBadgeVersion;
use App\Models\Rating;
use App\Models\ScoreRun;
use App\Models\SeriesMatch;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Chess\ChessModes;
use App\Support\LeagueTime;
use App\Support\Nostr\NostrKeys;
use App\Support\PreSeason;
use App\Support\Prizes\PrizePool;
use App\Support\Rating\RankTiers;
use App\Support\Rating\Ratings;
use App\Support\Scores\ScoreRuns;
use App\Support\Scores\ScoreWindow;
use App\Support\SeasonChain\Seasons;
use App\Support\Tmnf\TmnfWeeks;
use App\Support\Tournaments\Lobbies;
use App\Support\Tournaments\TournamentChampion;
use App\Support\Tournaments\TournamentLanding;
use App\Support\TwentyOne\Stream\BlockfillSlides;
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
 *
 * A player named for an achievement (a tournament win, a rank up, the
 * ladder's top 3) is tagged as the bot's notes tag them (PrideNotes,
 * BlockfillNotes): `nostr:npub1…` in place of the name and a `p` tag on the
 * message; the plain name without a valid Nostr key.
 */
final class StreamBotBuilders
{
    /** How much likelier a fact builder is drawn than a general feature tip. */
    public const FACT_WEIGHT = 3;

    /** A game tip, between a fact and a general feature, so a new game is not drowned out. */
    public const GAME_TIP_WEIGHT = 2;

    /** A general feature tip (login, clans, a zap). */
    public const FEATURE_WEIGHT = 1;

    /** A tournament whose sign-up closes within this many hours gets a "last call". */
    public const LAST_CALL_HOURS = 3;

    /** A series counts as happening from its start for this many hours. */
    public const SERIES_HOURS = 3;

    /** A TMNF week's best time is news for this many hours after it was verified. */
    public const TMNF_TOP_HOURS = 6;

    /** A finished TMNF week's podium is told for this many hours after the week's end (its review time included). */
    public const TMNF_PODIUM_HOURS = 48;

    /** Messages with numbers are read in this locale, whatever the process runs in. */
    private const LOCALE = 'en';

    /** @var (Closure(int): int)|null picks a variant; the first unused one by default */
    private ?Closure $variantPicker = null;

    /** @var (Closure(string): bool)|null true when that exact chat text already went out */
    private ?Closure $usedContent = null;

    public function __construct(
        private GameRegistry $games,
        private TournamentChampion $champions,
        private PrizePool $pools,
        private TmnfWeeks $tmnfWeeks,
        private TmnfNotes $tmnfNotes,
        private ScoreRuns $runs,
    ) {}

    /**
     * Builder name => draw weight. Facts outweigh a game tip, a game tip a
     * general feature. A game tip is listed only while that game is registered,
     * so a switched-off game never enters the rotation.
     *
     * @return array<string, int>
     */
    public function all(): array
    {
        return [
            'tournament_signup' => self::FACT_WEIGHT,
            'tournament_last_call' => self::FACT_WEIGHT,
            'tournament_live' => self::FACT_WEIGHT,
            'live_game' => self::FACT_WEIGHT,
            'live_games' => self::FACT_WEIGHT,
            'live_series' => self::FACT_WEIGHT,
            'tournament_winner' => self::FACT_WEIGHT,
            'rank_up' => self::FACT_WEIGHT,
            'new_clan' => self::FACT_WEIGHT,
            'ladder_top' => self::FACT_WEIGHT,
            'season' => self::FACT_WEIGHT,
            'stats' => self::FACT_WEIGHT,
            'tmnf_week' => self::FACT_WEIGHT,
            'tmnf_top' => self::FACT_WEIGHT,
            'tmnf_podium' => self::FACT_WEIGHT,
            'play_blitz' => self::FEATURE_WEIGHT,
            'daily_chess' => self::FEATURE_WEIGHT,
            'clan_challenge' => self::FEATURE_WEIGHT,
            'invite_friend' => self::FEATURE_WEIGHT,
            'clans' => self::FEATURE_WEIGHT,
            'badges' => self::FEATURE_WEIGHT,
            'all_games' => self::FEATURE_WEIGHT,
            'login' => self::FEATURE_WEIGHT,
            'zap' => self::FEATURE_WEIGHT,
            ...$this->gameTips(),
        ];
    }

    /**
     * Game tips that may speak right now: name => weight.
     *
     * @return array<string, int>
     */
    private function gameTips(): array
    {
        $tips = [];

        foreach ([
            'proof_of_pong' => ProofOfPong::SLUG,
            'hyperbitcoinization' => Hyperbitcoinization::SLUG,
            'blockfill_play' => Blockfill::SLUG,
            'board_blockli' => Blockli::SLUG,
            'board_morris' => NineMensMorris::SLUG,
            'board_checkers' => Checkers::SLUG,
            'aoe2' => 'age-of-empires-2',
            'ea_fc' => 'ea-sports-fc-27',
        ] as $builder => $slug) {
            if ($this->games->find($slug) !== null || ($builder === 'ea_fc' && $this->games->find('ea-sports-fc-26') !== null)) {
                $tips[$builder] = self::GAME_TIP_WEIGHT;
            }
        }

        return $tips;
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
     * Skip a rendered line the chat already carried. Set for a tick, cleared
     * after it. Null (the tests) keeps the picked variant and does not walk.
     *
     * @param  (Closure(string): bool)|null  $used
     */
    public function skipPosted(?Closure $used): void
    {
        $this->usedContent = $used;
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
                'tmnf_week' => $this->tmnfWeek($now),
                'tmnf_top' => $this->tmnfTop($now),
                'tmnf_podium' => $this->tmnfPodium($now),
                'play_blitz' => $this->feature('play_blitz', route('chess.lobby')),
                'daily_chess' => $this->feature('daily_chess', route('chess.challenge')),
                'clan_challenge' => $this->clanChallenge(),
                'invite_friend' => $this->feature('invite_friend', route('chess.lobby')),
                'clans' => $this->clans(),
                'badges' => $this->feature('badges', route('ladder.show', ['chess', ChessModes::DEFAULT])),
                'all_games' => $this->allGames(),
                'login' => $this->feature('login', route('login')),
                'zap' => $this->zap(),
                'proof_of_pong' => $this->feature('proof_of_pong', route('pong.index')),
                'hyperbitcoinization' => $this->feature('hyperbitcoinization', route('hyper.index')),
                'blockfill_play' => $this->feature('blockfill_play', route('stacker.play')),
                'board_blockli' => $this->boardTip('board_blockli', Blockli::SLUG),
                'board_morris' => $this->boardTip('board_morris', NineMensMorris::SLUG),
                'board_checkers' => $this->boardTip('board_checkers', Checkers::SLUG),
                'aoe2' => $this->feature('aoe2', route('games.series', 'age-of-empires-2')),
                'ea_fc' => $this->eaFc(),
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

            $this->add($messages, $this->message('tournament_signup', 'tournament-signup:'.$tournament->id, [
                'name' => $name,
                'game' => Lobbies::isLobby($tournament) ? $this->games->name($tournament->game).', one lobby match' : $this->gameLine($tournament->game, $tournament->mode),
                'starts' => $this->berlin($tournament->starts_at),
                'spots' => $places['taken'].' of '.$places['places'].' spots taken',
                'pot' => $pot === null ? '' : ' · 💰 '.number_format($pot).' sats in the pot',
                'url' => route('tournaments.show', $tournament),
            ]));
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

            $this->add($messages, $this->message('tournament_last_call', 'tournament-last-call:'.$tournament->id, [
                'name' => $name,
                'left' => $this->duration($closes->getTimestamp() - $now->getTimestamp()),
                'open' => $open.' '.($open === 1 ? 'spot' : 'spots'),
                'game' => Lobbies::isLobby($tournament) ? $this->games->name($tournament->game).', one lobby match' : $this->gameLine($tournament->game, $tournament->mode),
                'url' => route('tournaments.signup', $tournament),
            ]));
        }

        return $messages;
    }

    /**
     * @return list<StreamBotMessage>
     */
    private function tournamentLive(): array
    {
        $messages = [];

        // A Blockfill week (plan "Blockfill", P6) has no TV view and is announced by its own notes (BlockfillNotes).
        foreach (Tournament::query()->where('status', TournamentStatus::Running)->exceptLeagueWeeks()->orderBy('starts_at')->orderBy('id')->get() as $tournament) {
            $name = StreamBotCopy::clean($tournament->name);

            if ($name !== '') {
                $this->add($messages, $this->message('tournament_live', 'tournament-live:'.$tournament->id, [
                    'name' => $name,
                    'url' => route('tournaments.tv', $tournament),
                ]));
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

            $tags = [];
            $white = $this->mention($game->white, StreamBotCopy::clean($game->white->displayName(), 24), $tags);
            $black = $this->mention($game->black, StreamBotCopy::clean($game->black->displayName(), 24), $tags);

            if ($white === '' || $black === '') {
                continue;
            }

            $this->add($messages, $this->message('live_game', 'live-game:'.$game->id, [
                'white' => $white,
                'black' => $black,
                'mode' => $this->games->mode('chess', $game->mode)->name ?? 'Chess',
                'url' => route('games.show', $game),
            ], tags: $tags));
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

        return $this->one($this->message('live_games', 'live-games:'.implode(',', $ids), [
            'count' => count($ids),
            'url' => route('games.index'),
        ]));
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
            $tags = [];
            $home = $this->sideName($match, 'challenger', $match->challenger_name, $tags);
            $away = $this->sideName($match, 'challenged', $match->challenged_name, $tags);

            if ($home === '' || $away === '') {
                continue;
            }

            if ($match->isTeamMatch()) {
                // Before its lock a team match may still end as a forfeit: posted once its boards are set.
                if ($match->lineup_locked_at !== null) {
                    $this->add($messages, $this->message('live_team_match', 'live-series:'.$match->id, [
                        'home' => $home,
                        'away' => $away,
                        'boards' => (int) $match->boards,
                        'url' => route('matches.show', $match->number),
                    ], 'live_series', $tags));
                }

                continue;
            }

            $this->add($messages, $this->message('live_series', 'live-series:'.$match->id, [
                'game' => $this->games->name($match->game),
                'home' => $home,
                'away' => $away,
                'best_of' => $match->best_of,
                'url' => route('matches.show', $match->number),
            ], tags: $tags));
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
        $finished = Tournament::query()->where('status', TournamentStatus::Finished)->exceptLeagueWeeks()
            ->where('updated_at', '>=', $now->subDays((int) config('esports.stream_bot.winner_days', 14)))
            ->latest('updated_at')->limit(3)->get();

        foreach ($finished as $tournament) {
            $champion = $this->champions->of($tournament);
            $winner = StreamBotCopy::clean($champion?->user?->displayName() ?? $champion?->name, 32);
            $name = StreamBotCopy::resultName($tournament->name);

            if ($winner === '' || $name === '') {
                continue;
            }

            $tags = [];
            $this->add($messages, $this->message('tournament_winner', 'tournament-winner:'.$tournament->id, [
                'winner' => $this->mention($champion?->user, $winner, $tags),
                'name' => $name,
                'url' => route('tournaments.show', $tournament),
            ], tags: $tags));
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

            $tags = [];
            $this->add($messages, $this->message('rank_up', 'rank-up:'.$version->id, [
                'player' => $this->mention($user, $player, $tags),
                'tier' => RankTiers::label($version->tier),
                'game' => $this->gameLine($version->badge->game, $version->badge->mode),
                'url' => route('players.show', NostrKeys::hexToNpub($user->pubkey)),
            ], tags: $tags));
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

            $this->add($messages, $this->message('new_clan', 'new-clan:'.$clan->id, [
                'name' => $name,
                'tag' => $tag,
                'url' => route('clans.show', $clan),
            ]));
        }

        return $messages;
    }

    /**
     * The top three of a casual live chess ladder, as the ladder page orders
     * it: the first live mode with results, rapid first (plan "Schach Rapid
     * und Clan", P3), so a young rapid ladder does not silence the message.
     *
     * @return list<StreamBotMessage>
     */
    private function ladderTop(CarbonImmutable $now): array
    {
        $mode = ChessModes::DEFAULT;
        $rows = collect();

        foreach (ChessModes::live() as $mode) {
            $rows = Rating::query()
                ->where(['pool' => Rating::CASUAL, 'season' => Ratings::season(Rating::CASUAL, 'chess', $mode), 'game' => 'chess', 'mode' => $mode])
                ->where('results', '>', 0)
                ->with('user')
                ->orderByDesc('rating')->orderByDesc('results')->orderBy('id')
                ->limit(3)->get();

            if ($rows->isNotEmpty()) {
                break;
            }
        }

        $podium = [];
        $tags = [];

        foreach ($rows->values() as $index => $row) {
            $name = StreamBotCopy::clean($row->user?->displayName(), 20);

            if ($name !== '') {
                $podium[] = ['🥇', '🥈', '🥉'][$index].' '.$this->mention($row->user, $name, $tags).' '.$row->rating;
            }
        }

        if ($podium === []) {
            return [];
        }

        return $this->one($this->message('ladder_top', 'ladder-top:'.$now->format('Y-m-d').':'.implode(',', $rows->pluck('user_id')->all()), [
            'ladder' => ucfirst($mode),
            'podium' => implode(' · ', $podium),
            'url' => route('ladder.show', ['chess', $mode]),
        ], tags: $tags));
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
            return $this->one($this->message('season_live', 'season-live:'.Seasons::live()?->slug, ['url' => route('mining')], 'season'));
        }

        $block0At = PreSeason::block0At();

        if ($state !== 'pre-launch' || $block0At === null || $block0At->getTimestamp() <= $now->getTimestamp()) {
            return [];
        }

        return $this->one($this->message('season_countdown', 'season-countdown:'.$block0At->getTimestamp(), [
            'left' => $this->duration($block0At->getTimestamp() - $now->getTimestamp()),
            'url' => route('mining'),
        ], 'season'));
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

        return $this->one($this->message('stats', 'stats:'.$now->format('Y-m-d'), [
            'players' => number_format($players),
            'clans' => number_format(Clan::query()->count()),
            'games' => number_format(ChessGame::query()->where('status', ChessGameStatus::Finished)->count()),
            'url' => route('home'),
        ]));
    }

    /**
     * The running TMNF week (plan "Trackmania und Restposten"): its track,
     * our server, its end and the week page, which leads with How to join
     * and its favourite link. Not the link itself: `tmtp://#addfavourite=…`
     * would render as a hashtag in zap.stream (its parser knows no tmtp
     * scheme and takes `#addfavourite` for a tag).
     *
     * @return list<StreamBotMessage>
     */
    private function tmnfWeek(CarbonImmutable $now): array
    {
        $week = $this->runningTmnfWeek($now);

        if ($week === null) {
            return [];
        }

        $server = StreamBotCopy::clean((string) config('esports.tmnf.server.name'), 32);

        return $this->one($this->message('tmnf_week', 'tmnf-week:'.$week->id, [
            'name' => StreamBotCopy::clean($week->title()),
            'track' => TmnfNotes::track($week),
            'server' => $server === '' ? null : $server,
            'ends' => LeagueTime::stamp(ScoreWindow::of($week)->end),
            'url' => route('tournaments.show', $week),
        ]));
    }

    /**
     * The running TMNF week's first place, while it is at most
     * TMNF_TOP_HOURS old: its driver tagged, the time, the track and how much
     * faster than the first place before it. Once per first place.
     *
     * @return list<StreamBotMessage>
     */
    private function tmnfTop(CarbonImmutable $now): array
    {
        $week = $this->runningTmnfWeek($now);
        $first = $week === null ? null : ($this->runs->standings($week)[0] ?? null);

        if ($week === null || $first === null || $first->place !== 1 || $first->value === null || $first->runId === null) {
            return [];
        }

        $run = ScoreRun::query()->find($first->runId);
        $name = StreamBotCopy::clean($first->participant->user?->displayName() ?? $first->participant->name, 32);

        if ($run?->verified_at === null || $run->verified_at->lessThan($now->subHours(self::TMNF_TOP_HOURS)) || $name === '') {
            return [];
        }

        $before = $this->tmnfNotes->previousBest($week, $run);
        $gap = $before === null ? 0 : $before - (int) $run->value;
        $tags = [];

        return $this->one($this->message('tmnf_top', 'tmnf-top:'.$run->id, [
            'name' => StreamBotCopy::clean($week->title()),
            'player' => $this->mention($first->participant->user, $name, $tags),
            'time' => ScoreMetric::time()->format((int) $run->value),
            'track' => TmnfNotes::track($week),
            'gap' => $gap > 0 ? ', '.BlockfillSlides::seconds($gap).' faster' : '',
            'url' => route('tournaments.show', $week),
        ], tags: $tags));
    }

    /**
     * The last finished TMNF week's top 3, each driver tagged, for
     * TMNF_PODIUM_HOURS after the week's end.
     *
     * @return list<StreamBotMessage>
     */
    private function tmnfPodium(CarbonImmutable $now): array
    {
        $week = $this->tmnfWeeks->game() === null ? null : $this->tmnfWeeks->previous($now);

        if ($week === null || $week->status !== TournamentStatus::Finished || ScoreWindow::of($week)->end->lessThan($now->subHours(self::TMNF_PODIUM_HOURS))) {
            return [];
        }

        $tags = [];
        $podium = [];
        $winner = null;

        foreach ($this->runs->standings($week) as $row) {
            $name = StreamBotCopy::clean($row->participant->user?->displayName() ?? $row->participant->name, 24);

            if ($row->place === null || $row->value === null || $name === '' || count($podium) >= WeeklyBoardNotes::PODIUM) {
                continue;
            }

            $driver = $this->mention($row->participant->user, $name, $tags);
            $winner ??= $driver;
            $podium[] = ['🥇', '🥈', '🥉'][count($podium)].' '.$driver.' '.ScoreMetric::time()->format((int) $row->value);
        }

        if ($winner === null) {
            return [];
        }

        return $this->one($this->message('tmnf_podium', 'tmnf-podium:'.$week->id, [
            'name' => StreamBotCopy::clean($week->title()),
            'winner' => $winner,
            'track' => TmnfNotes::track($week),
            'podium' => implode(' · ', $podium),
            'url' => route('scores.show', TrackmaniaNationsForever::SLUG),
        ], tags: $tags));
    }

    /** The TMNF week `$now` lies in while it runs; null while TMNF is off or no week is open. */
    private function runningTmnfWeek(CarbonImmutable $now): ?Tournament
    {
        $week = $this->tmnfWeeks->game() === null ? null : $this->tmnfWeeks->current($now);

        return $week !== null && $week->status === TournamentStatus::Running && ScoreWindow::of($week)->contains($now) ? $week : null;
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

        return $this->feature('clan_challenge', route('challenges.create'), ['games' => $this->shortList($names)]);
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
        // A board game is announced once it is playable (plan "Mühle und Dame", P5); a score game (plan "AoE2 und
        // Trackmania", P4) not before its own announcement is decided.
        $names = array_values(array_unique(array_map(fn ($game): string => $game->name(), array_diff_key($this->games->versus(), $this->games->boards()))));

        if ($names === []) {
            return [];
        }

        return $this->feature('all_games', route('play'), ['games' => $this->shortList($names)]);
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
        return $this->one($this->message($name, 'feature:'.$name, ['url' => $url, ...$values]));
    }

    /**
     * A board game's own tip, while it is registered. The lobby route exists
     * only then (routes/board.php).
     *
     * @return list<StreamBotMessage>
     */
    private function boardTip(string $builder, string $slug): array
    {
        $game = $this->games->find($slug);

        return $game === null ? [] : $this->featureNamed($builder, 'board_game', route('board.lobby', $slug), ['game' => $game->name()]);
    }

    /**
     * EA Sports FC, the newest registered edition. The page is the series page.
     *
     * @return list<StreamBotMessage>
     */
    private function eaFc(): array
    {
        $slug = $this->games->find('ea-sports-fc-27') !== null ? 'ea-sports-fc-27' : 'ea-sports-fc-26';

        return $this->games->find($slug) === null ? [] : $this->feature('ea_fc', route('games.series', $slug));
    }

    /** @param list<StreamBotMessage> $messages */
    private function add(array &$messages, ?StreamBotMessage $message): void
    {
        if ($message !== null) {
            $messages[] = $message;
        }
    }

    /**
     * @return list<StreamBotMessage>
     */
    private function one(?StreamBotMessage $message): array
    {
        return $message === null ? [] : [$message];
    }

    /**
     * A feature tip whose builder name is not the template name (a board game
     * shares `board_game`).
     *
     * @param  array<string, string|int|null>  $values
     * @return list<StreamBotMessage>
     */
    private function featureNamed(string $builder, string $template, string $url, array $values = []): array
    {
        return $this->one($this->message($template, 'feature:'.$builder, ['url' => $url, ...$values], $builder));
    }

    /**
     * @param  array<string, string|int|null>  $values
     * @param  list<list<string>>  $tags  the `p` tags of the players it names
     */
    private function message(string $template, string $factKey, array $values, ?string $builder = null, array $tags = []): ?StreamBotMessage
    {
        $variants = StreamBotCopy::variants($template);
        $start = $this->variantPicker !== null ? ($this->variantPicker)($variants) : random_int(0, max(0, $variants - 1));
        $tries = $this->usedContent === null ? 1 : $variants;

        if ($this->usedContent !== null && $this->variantPicker === null) {
            $start = 0;
        }

        for ($i = 0; $i < $tries; $i++) {
            $content = StreamBotCopy::render($template, ($start + $i) % max(1, $variants), $values);

            if ($this->usedContent !== null && ($this->usedContent)($content)) {
                continue;
            }

            return new StreamBotMessage($builder ?? $template, $factKey, $content, $tags);
        }

        return null;
    }

    /**
     * A series side as the chat names it: the stored name, plus each listed
     * player's `nostr:npub1…` in brackets. The plain name when the side lists
     * no account.
     *
     * @param  list<list<string>>  $tags
     */
    private function sideName(SeriesMatch $match, string $side, string $fallback, array &$tags): string
    {
        $name = StreamBotCopy::clean($fallback, 24);
        $ids = array_values(array_unique(array_map(intval(...), ($match->sides ?? [])[$side] ?? [])));

        if ($name === '' || $ids === []) {
            return $name;
        }

        $mentions = [];

        foreach (User::query()->whereIn('id', $ids)->get() as $user) {
            $who = $this->mention($user, '', $tags);

            if (str_starts_with($who, 'nostr:')) {
                $mentions[] = $who;
            }
        }

        return $mentions === [] ? $name : $name.' ('.implode(', ', $mentions).')';
    }

    /**
     * A player named for an achievement, as PrideNotes names them:
     * `nostr:npub1…` of their Nostr key, its `p` tag added once, while the
     * message tags fewer than PrideNotes::LOBBY_MENTIONS players; `$name`
     * (already cleaned) for a player without a valid key or past the cap.
     * Only the account's Nostr key, never a game account.
     *
     * @param  list<list<string>>  $tags
     */
    private function mention(?User $user, string $name, array &$tags): string
    {
        $pubkey = (string) $user?->pubkey;

        if (preg_match('/^[0-9a-f]{64}$/', $pubkey) !== 1) {
            return $name;
        }

        if (! in_array(['p', $pubkey], $tags, true)) {
            if (count($tags) >= PrideNotes::LOBBY_MENTIONS) {
                return $name;
            }

            $tags[] = ['p', $pubkey];
        }

        return 'nostr:'.NostrKeys::hexToNpub($pubkey);
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
        return StreamBotCopy::duration($seconds);
    }

    /**
     * At most three names, then "and more", so a chat line stays a line.
     *
     * @param  list<string>  $names
     */
    private function shortList(array $names, int $max = 3): string
    {
        if (count($names) <= $max) {
            return $this->list($names);
        }

        return implode(', ', array_slice($names, 0, $max)).' and more';
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
