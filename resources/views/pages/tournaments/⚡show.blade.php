<?php

use App\Enums\BoardGameStatus;
use App\Enums\BoardInviteStatus;
use App\Enums\ChessGameStatus;
use App\Enums\ChessInviteStatus;
use App\Enums\TournamentFormat;
use App\Enums\TournamentStatus;
use App\Models\BoardInvite;
use App\Models\ChessInvite;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\Board\BoardInvites;
use App\Support\Board\BoardRuleViolation;
use App\Support\Chess\ChessInvites;
use App\Support\Chess\ChessRuleViolation;
use App\Support\Hyper\HyperTournamentTeams;
use App\Support\LeagueTime;
use App\Support\PageMeta;
use App\Support\Seo\LocalizedUrls;
use App\Support\Seo\StructuredData;
use App\Support\Series\SeriesRuleViolation;
use App\Support\Tournaments\CasualCups;
use App\Support\Tournaments\CupSchedules;
use App\Support\Tournaments\Estimator;
use App\Support\Tournaments\FormatCopy;
use App\Support\Tournaments\LobbyResults;
use App\Support\Tournaments\MatchWait;
use App\Support\Tournaments\Preview;
use App\Support\Tournaments\TournamentChampion;
use App\Support\Tournaments\TournamentChampionMoment;
use App\Support\Tournaments\TournamentDesk;
use App\Support\Tournaments\TournamentLanding;
use App\Support\Tournaments\TournamentNow;
use App\Support\Tournaments\TournamentPrizePool;
use App\Support\Tournaments\TournamentPublisher;
use App\Support\Tournaments\TournamentRuleViolation;
use App\Support\Tournaments\TournamentView;
use App\Support\Tournaments\TournamentWaits;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/*
 * A tournament's public page (TournamentShow.dc.html, P8b), built as its
 * landing and invite page: the game's cover, the name, the call to action
 * for the viewer's state with the countdown and the places, who plays (with
 * the seed each entry would get now), the bracket (projected before the
 * draw, the engine's public view after it, the match up now highlighted),
 * the prize pool once P9 has one, then the facts, how it works, the FAQ and
 * the Nostr proof. A draft is only seen by its creator, its directors and
 * admins (everyone else gets the 404 of a tournament that does not exist);
 * its manager publishes it here. Sign-up itself is its own page.
 *
 * While sign-up, the draw or the matches move, the page polls every 15
 * seconds (only the TV view, pages::tournaments.tv, listens on the
 * tournament's Reverb channel); a new entry drops into the grid when it
 * arrives.
 *
 * The prize pool (P9): the pot, the split and the sponsors come from the
 * league's pool (App\Support\Prizes\WalletPrizePool behind
 * TournamentPrizePool); the zap panel, the payouts and the organizer's and
 * admin's links are their own component (components/⚡tournament-pool).
 *
 * A casual cup (P25, CasualCups) carries a "Casual" marker. Every running
 * page opens on "What to do now" (TournamentNow, partials/now): a player's
 * one next step (play, start, join the lobby, wait, bye, out, won) or a
 * spectator's live boards; a cup's invite and times live there.
 */
new #[Layout('layouts::app', ['section' => 'tournaments', 'realtime' => true, 'scripts' => ['resources/js/tournamentDesk.js', 'resources/js/gameChannel.js']])] class extends Component {
    public Tournament $tournament;

    public string $closesAt = '';

    public string $error = '';

    public string $cupError = '';

    /** The page's shape at its last full render (shape()): a push renders the islands alone only while it holds. */
    #[Locked]
    public string $renderedShape = '';

    /** @var list<string> the times a player proposes for a cup series match (P25 S3), league time */
    public array $cupTimes = ['', '', ''];

    public function mount(Tournament $tournament): void
    {
        abort_unless($tournament->isVisibleTo(auth()->user()), 404);
        // A Blockfill week while Blockfill is switched off (P6): no page, its game has none either.
        abort_if($tournament->isSwitchedOffLeagueWeek(), 404);

        $this->tournament = $tournament;
        $this->closesAt = LeagueTime::input($tournament->starts_at->copy()->subHour());
    }

    public function rendering(\Illuminate\View\View $view): void
    {
        $tournament = $this->tournament;

        // Only a published tournament is public (TournamentPublisher sets both); a draft stays noindex.
        // The first title() call wins (Livewire merges page params first-come), so each branch sets it once.
        if ($tournament->status === TournamentStatus::Draft || $tournament->published_at === null) {
            $view->title($tournament->title());

            return;
        }

        $locale = app()->getLocale();
        $title = $tournament->title().' · '.__(':game tournament', ['game' => \App\Support\GameNames::game($tournament->game)]);
        $view->title($title);

        $meta = app(PageMeta::class)
            ->describe($title, $this->description())
            ->addStructuredData(StructuredData::tournament($tournament, LocalizedUrls::for($locale)))
            ->addStructuredData(StructuredData::breadcrumbs([
                [__('Home'), LocalizedUrls::for($locale, route('home'))],
                [__('Tournaments'), LocalizedUrls::for($locale, route('tournaments.index'))],
                [$tournament->title(), LocalizedUrls::for($locale, route('tournaments.show', $tournament))],
            ]));

        // The link preview (P54): places, pot and start while it is open, the podium once it is over.
        $meta->card(fn () => \App\Support\Cards\PageCard::tournament($tournament));
    }

    /** After the render: shape() reads page(), computed by then, so it adds no query. */
    public function rendered(): void
    {
        $this->renderedShape = $this->shape();
    }

    /**
     * The search and preview description: game, mode, format, size, place,
     * start and where the tournament stands, in the display time zone.
     */
    private function description(): string
    {
        $tournament = $this->tournament;
        $zone = (string) config('esports.preseason.display_timezone');
        $date = fn (\Carbon\CarbonInterface $at): string => $at->copy()->timezone($zone)->format('Y-m-d H:i T');
        $teams = $tournament->profile()->entersTeams();
        // A casual cup in sign-up names the format and the field it would play now (CasualCups::asPlanned()).
        $planned = CasualCups::asPlanned($tournament);

        $facts = [
            'game' => \App\Support\GameNames::game($tournament->game),
            // The mode's name, never its id: a Blockfill week names its own blocks ("60 blocks"), not "40-blocks".
            'mode' => match (true) {
                $tournament->mode === 'correspondence' => __('Daily'),
                $tournament->game === \App\Games\Blockfill::SLUG => \App\Support\Stacker\BlockfillRules::weekBlocks($tournament),
                default => \App\Support\GameNames::mode($tournament->game, $tournament->mode),
            },
            'format' => \App\Support\Tournaments\Lobbies::formatLabel($tournament),
            'who' => $teams ? trans_choice(':count team|:count teams', $planned->capacity) : trans_choice(':count player|:count players', $planned->capacity),
            'where' => $tournament->on_site ? __('on site') : __('online'),
            'date' => $date($tournament->starts_at),
        ];
        // A lobby tournament (P10) names no mode: one lobby holds up to 8 players, its "1v1" is no duel.
        $description = \App\Support\Tournaments\Lobbies::isLobby($tournament)
            ? __(':game tournament, :format, for :who, :where, starting :date.', $facts)
            : __(':game tournament (:mode), :format, for :who, :where, starting :date.', $facts);

        $champion = $this->champion;

        return $description.' '.match ($tournament->status) {
            TournamentStatus::Signup => $tournament->isSignupOpen() && $tournament->signup_closes_at !== null
                ? __('Sign-up is open until :date.', ['date' => $date($tournament->signup_closes_at)])
                : __('Sign-up has closed. The draw follows.'),
            TournamentStatus::Drawing => __('Sign-up has closed. The draw follows.'),
            TournamentStatus::Running => $tournament->isPaused() ? __('The tournament is paused: no match starts and no deadline runs until it goes on.') : __('The tournament is running.'),
            TournamentStatus::Finished => $champion === null
                ? ($this->sharedFirst === [] ? __('The tournament has finished.') : __('Finished. Shared 1st place: :names.', ['names' => implode(', ', $this->sharedFirst)]))
                : __('Finished. Winner: :name.', ['name' => $champion->name]),
            TournamentStatus::Cancelled => __('The tournament was called off.'),
            TournamentStatus::Draft => '',
        };
    }

    /**
     * The viewer's open chess match in this casual cup, with its invites.
     *
     * @return array{match: TournamentMatch, opponent: string, endsAt: \Carbon\CarbonInterface, slot: \Carbon\CarbonImmutable, zone: string, evening: bool, game: \App\Models\ChessGame|\App\Models\BoardGame|null, gameUrl: string|null, incoming: ChessInvite|BoardInvite|null, outgoing: ChessInvite|BoardInvite|null, series: bool, agreed: \Carbon\CarbonImmutable|null, seriesMatch: \App\Models\SeriesMatch|null, mine: bool}|null
     */
    #[Computed]
    public function cupMatch(): ?array
    {
        $user = auth()->user();

        if (! $user instanceof User || ! $this->tournament->isCasualCup() || $this->tournament->status !== TournamentStatus::Running) {
            return null;
        }

        // A lobby cup (P10) shows the viewer's lobby on its lobby card instead (tournament-lobbies).
        $match = TournamentMatch::query()->where('tournament_id', $this->tournament->id)->where('status', 'ready')->whereNull('result')->whereNull('lobby')
            ->where('bracket', '!=', 'bye')->whereHas('round', fn ($query) => $query->whereNotNull('window_ends_at'))
            ->whereHas('slots.participant', fn ($query) => $query->where('user_id', $user->id))
            ->with(['round', 'slots.participant', 'chessGame', 'seriesMatch', 'boardGame'])->first();

        if ($match === null || $match->round->window_ends_at === null) {
            return null;
        }

        $opponent = $match->slots->first(fn ($slot): bool => $slot->participant !== null && $slot->participant->user_id !== $user->id)?->participant;
        // A board game's cup (plan "Mühle und Dame", P5) plays as chess, through its own invites and board.
        $board = $this->tournament->profile()->isBoard();
        $open = fn () => ($board ? BoardInvite::query()->where('status', BoardInviteStatus::Pending) : ChessInvite::query()->where('status', ChessInviteStatus::Pending))
            ->where('tournament_match_id', $match->id)->where('expires_at', '>', now())->latest('id');
        $game = $board
            ? ($match->boardGame?->status === BoardGameStatus::Active ? $match->boardGame : null)
            : ($match->chessGame?->status === ChessGameStatus::Active ? $match->chessGame : null);

        return [
            'match' => $match,
            'opponent' => $opponent->name ?? '',
            'endsAt' => $match->round->window_ends_at,
            'slot' => CasualCups::autoSlot($match->round->window_ends_at, CasualCups::timezoneOf($this->tournament)),
            'zone' => CasualCups::timezoneOf($this->tournament),
            'evening' => CasualCups::isEvening($this->tournament),
            'game' => $game,
            'gameUrl' => $game === null ? null : ($board ? route('board.show', $game) : route('games.show', ['game' => $game])),
            'incoming' => $open()->where('invitee_id', $user->id)->first(),
            'outgoing' => $open()->where('inviter_id', $user->id)->first(),
            // Rocket League and EA Sports FC (S3): the proposed and agreed time, and the series once the league started it.
            'series' => $this->tournament->profile()->isSeries(),
            'agreed' => CupSchedules::agreedAt($match),
            'seriesMatch' => $match->seriesMatch,
            'mine' => ($match->schedule['by'] ?? null) === $user->id,
        ];
    }

    public function proposeCupTimes(): void
    {
        $this->cupSchedule(function (User $user, TournamentMatch $match): void {
            $times = [];

            foreach (array_filter($this->cupTimes, fn (mixed $time): bool => is_string($time) && $time !== '') as $time) {
                try {
                    $times[] = LeagueTime::parse($time)->getTimestamp();
                } catch (\InvalidArgumentException $invalid) {
                    throw new SeriesRuleViolation('proposal_time', $invalid->getMessage());
                }
            }

            app(CupSchedules::class)->propose($match, $user, $times);
            $this->cupTimes = ['', '', ''];
        });
    }

    public function acceptCupTime(int $at): void
    {
        $this->cupSchedule(fn (User $user, TournamentMatch $match) => app(CupSchedules::class)->accept($match, $user, $at));
    }

    /**
     * @param  \Closure(User, TournamentMatch): mixed  $action
     */
    private function cupSchedule(\Closure $action): void
    {
        $user = auth()->user();
        $match = $this->cupMatch['match'] ?? null;

        if (! $user instanceof User || $match === null) {
            $this->redirectRoute('login');

            return;
        }

        $this->cupError = '';

        try {
            $action($user, $match);
        } catch (SeriesRuleViolation $violation) {
            $this->cupError = $violation->getMessage();
        }

        unset($this->cupMatch, $this->now);
    }

    /**
     * A Hyperbitcoinization clan bracket (P5b): the viewer, a captain, names who plays the match for the clan.
     *
     * @param  list<int>  $userIds
     */
    public function nameHyperLineup(int $matchId, array $userIds): void
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            $this->redirectRoute('login');

            return;
        }

        $this->cupError = '';
        $match = TournamentMatch::query()->where('tournament_id', $this->tournament->id)->find($matchId);

        try {
            if ($match === null) {
                throw new TournamentRuleViolation('lineup_closed', __('This match takes no lineup any more.'));
            }

            app(HyperTournamentTeams::class)->name($this->tournament, $match, $user, array_values(array_map(intval(...), $userIds)));
        } catch (TournamentRuleViolation $violation) {
            $this->cupError = $violation->getMessage();
        }

        unset($this->now);
    }

    public function playCupMatch(): void
    {
        $this->cupAttempt(function (User $user): void {
            $match = $this->cupMatch['match'] ?? null;

            if ($match === null) {
                throw new ChessRuleViolation('match_not_open');
            }

            if ($this->tournament->profile()->isBoard()) {
                $game = app(BoardInvites::class)->inviteToCupMatch($user, $match)->boardGame;

                if ($game !== null) {
                    $this->redirectRoute('board.show', ['boardGame' => $game]);
                }

                return;
            }

            $game = app(ChessInvites::class)->inviteToCupMatch($user, $match)->game;

            if ($game !== null) {
                $this->redirectRoute('games.show', ['game' => $game]);
            }
        });
    }

    public function acceptCupInvite(int $inviteId): void
    {
        $this->cupAttempt(function (User $user) use ($inviteId): void {
            if ($this->tournament->profile()->isBoard()) {
                $game = app(BoardInvites::class)->accept(BoardInvite::query()->whereNotNull('tournament_match_id')->findOrFail($inviteId), $user);
                $this->redirectRoute('board.show', ['boardGame' => $game]);

                return;
            }

            $game = app(ChessInvites::class)->accept(ChessInvite::query()->whereNotNull('tournament_match_id')->findOrFail($inviteId), $user);
            $this->redirectRoute('games.show', ['game' => $game]);
        });
    }

    private function cupAttempt(\Closure $action): void
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            $this->redirectRoute('login');

            return;
        }

        $this->cupError = '';

        try {
            $action($user);
        } catch (ChessRuleViolation|BoardRuleViolation $violation) {
            $this->cupError = match ($violation->reason) {
                'already_playing', 'accept_while_playing', 'playing_elsewhere', 'casual_playing' => __('You are in a live game. One live game at a time: finish it, then play your cup match.'),
                'opponent_playing' => __('Your opponent is in another live game right now. Try again when it is over.'),
                'invite_closed' => __('That invite is no longer open.'),
                'match_not_open', 'not_your_match' => __('This match cannot be started now.'),
                default => __('That did not work, please try again.'),
            };
        }

        unset($this->cupMatch, $this->now);
    }

    /**
     * "What to do now" for the viewer (TournamentNow): the hero at the very top of a running or finished
     * tournament page; null when there is nothing to say (a draft, sign-up, a score leaderboard).
     *
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function now(): ?array
    {
        return TournamentNow::of($this->tournament, auth()->user(), $this->cupMatch, $this->myLobby);
    }

    /**
     * The viewer's own lobby of a running lobby tournament (P10), still to
     * be played: pinned above the hero with the league's name and password
     * (2026-10-02, the lobby list sits far down the page). Only for a player
     * of that lobby (LobbyResults::plays()), as on its card; a director sees
     * every lobby's access on the cards and has none of his own here.
     */
    #[Computed]
    public function myLobby(): ?TournamentMatch
    {
        $user = auth()->user();

        if (! $user instanceof User || $this->tournament->status !== TournamentStatus::Running) {
            return null;
        }

        return TournamentMatch::query()->where('tournament_id', $this->tournament->id)->whereNotNull('lobby')->whereNull('result')->where('status', 'ready')
            ->with(['slots.participant', 'tournament'])->orderBy('position')->get()
            ->first(fn (TournamentMatch $match): bool => LobbyResults::plays($match, $user));
    }

    /**
     * The viewer's open matches of this running tournament with a countdown
     * to the league's automatic decision (P18, slice 5, TournamentWaits).
     *
     * @return list<MatchWait>
     */
    #[Computed]
    public function myWaits(): array
    {
        $user = auth()->user();

        if (! $user instanceof User || $this->tournament->status !== TournamentStatus::Running) {
            return [];
        }

        return array_values(array_filter(TournamentWaits::ofPlayer($this->tournament, $user), fn (MatchWait $wait): bool => $wait->decidesAt !== null));
    }

    #[Computed]
    public function canManage(): bool
    {
        return auth()->check() && Gate::allows('manage-tournament', $this->tournament);
    }

    /**
     * The tournament desk for the viewer (TournamentDesk): its chat config,
     * or null while it is closed or the viewer is not a member. Only a
     * member's page carries the member list.
     *
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function desk(): ?array
    {
        return TournamentDesk::for($this->tournament, auth()->user());
    }

    #[Computed]
    public function canDirect(): bool
    {
        return auth()->check() && Gate::allows('direct-tournament', $this->tournament);
    }

    public function publish(): void
    {
        $user = auth()->user();
        abort_unless($user !== null && Gate::allows('manage-tournament', $this->tournament), 403);

        $this->error = '';

        try {
            $closes = LeagueTime::parse($this->closesAt);
        } catch (\InvalidArgumentException $invalid) {
            $this->error = $this->closesAt === '' ? __('Pick the date and time sign-up closes.') : $invalid->getMessage();

            return;
        }

        try {
            $this->tournament = app(TournamentPublisher::class)->publish($this->tournament, $user, $closes);
        } catch (TournamentRuleViolation $violation) {
            $this->error = $violation->getMessage();
        }
    }

    #[Computed]
    public function landing(): TournamentLanding
    {
        return new TournamentLanding($this->tournament, auth()->user());
    }

    /**
     * The champion moment at the top of a finished tournament page (TournamentChampionMoment): null while it runs,
     * for a league week and when no place 1 can be read.
     *
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function championMoment(): ?array
    {
        return TournamentChampionMoment::of($this->tournament, auth()->user());
    }

    #[Computed]
    public function champion(): ?TournamentParticipant
    {
        return $this->tournament->status === TournamentStatus::Finished ? app(TournamentChampion::class)->of($this->tournament) : null;
    }

    /**
     * A finished tournament whose place 1 is shared: a lobby tournament's (P10)
     * across all its lobbies, or a table's tie the organizer chose to share
     * (FirstPlaceTie). The names on it (there is no single champion then).
     *
     * @return list<string>
     */
    #[Computed]
    public function sharedFirst(): array
    {
        if ($this->tournament->status !== TournamentStatus::Finished
            || (! \App\Support\Tournaments\Lobbies::isLobby($this->tournament) && \App\Support\Tournaments\FirstPlaceTie::mode($this->tournament) === \App\Support\Tournaments\FormatOptions::FIRST_PLACE_TIE_SEED)) {
            return [];
        }

        $first = app(\App\Support\Payouts\TournamentPlacements::class)->of($this->tournament)[0] ?? null;

        if ($first === null || $first['place'] !== 1 || count($first['participants']) < 2) {
            return [];
        }

        $names = TournamentParticipant::query()->whereKey($first['participants'])->pluck('name', 'id');

        return array_values(array_map(fn (int $id): string => (string) ($names[$id] ?? ''), $first['participants']));
    }

    /** The "Fill the pot" card saw its invoice paid: render the pot and the sponsors' wall again. */
    #[On('pot-filled')]
    public function potFilled(): void
    {
        unset($this->pool);
    }

    /**
     * @return array{sats: int, split: list<array{place: int, percent: int, sats: int}>, sponsors: list<array{name: string, logo: string|null, url: string|null}>}|null
     */
    #[Computed]
    public function pool(): ?array
    {
        // A Blockfill week (P6) has no prize pool.
        return $this->tournament->isLeagueWeek() ? null : app(TournamentPrizePool::class)->for($this->tournament);
    }

    /**
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function stages(): array
    {
        return in_array($this->tournament->status, [TournamentStatus::Running, TournamentStatus::Finished], true)
            ? (new TournamentView($this->tournament))->stages()
            : [];
    }

    /**
     * Everything the template reads about the tournament and the viewer, computed once per request. A computed and
     * not a @php block: the islands ("now", "board") render on their own and see no locals of the page around them,
     * so the page and each island read the same values from here.
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function page(): array
    {
        // Read once: a computed that returns null is not remembered (Livewire's `??=`), and each read of
        // TournamentDesk::for() costs about six queries.
        $desk = $this->desk;
        $now = $this->now;
        $tournament = $this->tournament;
        $landing = $this->landing;
        $profile = $tournament->profile();
        $options = $tournament->formatOptions();
        $zone = (string) (auth()->user()->timezone ?? config('esports.preseason.display_timezone'));
        $teams = $profile->entersTeams();
        $chess = $profile->isChess();
        // Chess and the board games (plan "Mühle und Dame", P5) play their games here, with a clock; the series are reported.
        $playsHere = $chess || $profile->isBoard();
        // A score game's leaderboard (plan "AoE2 und Trackmania", P4): no match, no Elo, no seeds that matter.
        $score = $profile->isScore();
        // A Blockfill week (P6): the league opens it, a verified run enters the player; no sign-up, no no-shows, no invites.
        $week = $tournament->isLeagueWeek();
        // A TMNF week (plan "Trackmania und Restposten", P2) as a Blockfill week, played on our own server: How to join instead of Play.
        $tmnfWeek = $tournament->isTmnfWeek();
        $tmnfTrack = $tmnfWeek ? \App\Support\Tmnf\TmnfWeeks::track($tournament->score_course) : null;
        // A Blockfill week's page as TMNF's week board (plan "Restposten nach TMNF", P4): the game's hero with Play now,
        // the rules as chips, the podium and the board, the way onward; no roster, steps text, facts or questions.
        $blockfillWeek = $week && ! $tmnfWeek && \Illuminate\Support\Facades\Route::has('stacker.play');
        $weekStandings = $blockfillWeek && $tournament->participants()->exists() ? app(\App\Support\Scores\ScoreRuns::class)->standings($tournament) : [];
        $scoreGame = $score ? app(\App\Games\GameRegistry::class)->get($tournament->game) : null;
        $scoreMetric = $scoreGame instanceof \App\Games\ScoreGame ? $scoreGame->metric($scoreGame->mode($tournament->mode) ?? throw new \LogicException('A score profile has its mode.')) : null;
        $status = $tournament->status;
        // A lobby tournament (plan "AoE2 und Trackmania", P10): one lobby match, its lobby cards instead of a bracket.
        $lobbies = \App\Support\Tournaments\Lobbies::isLobby($tournament);
        $drawn = $landing->drawn();
        $published = $status !== TournamentStatus::Draft && $tournament->published_at !== null;
        $places = $landing->places();
        $roster = $landing->roster();
        $cta = $landing->cta();
        $deadline = $landing->deadline();
        $open = $landing->openSeats();
        $yourSeed = $landing->yourSeed();
        $champion = $this->champion;
        $championMoment = $this->championMoment;
        $pageUrl = route('tournaments.show', $tournament);
        // A lobby tournament names its game alone and "One lobby match", never "1v1" or Free for All.
        $gameLine = \App\Support\Tournaments\Lobbies::gameLine($tournament);
        $formatLabel = \App\Support\Tournaments\Lobbies::formatLabel($tournament);
        $at = fn (\Carbon\CarbonInterface $moment): string => LeagueTime::stamp($moment);
        $poll = in_array($status, [TournamentStatus::Signup, TournamentStatus::Drawing, TournamentStatus::Running], true) && $published;

        // Who is in, for the line under the button: the two best seeds by name, the rest counted.
        $named = array_map(fn (array $row): string => $row['name'], array_slice($roster, 0, 2));
        $inLine = match (count($roster)) {
            0 => __('Nobody is in yet. The first seat is yours.'),
            1 => __(':a is in.', ['a' => $named[0]]),
            2 => __(':a and :b are in.', ['a' => $named[0], 'b' => $named[1]]),
            default => trans_choice(':a, :b and :count other are in.|:a, :b and :count others are in.', count($roster) - 2, ['a' => $named[0], 'b' => $named[1]]),
        };
        $faces = array_values(array_filter(array_map(fn (array $row) => $row['users'][0] ?? null, array_slice($roster, 0, 5))));

        $countdown = $landing->countdown();

        $shareText = $status === TournamentStatus::Signup && $tournament->isSignupOpen()
            ? __('Play :tournament with me on TWENTY ONE Esports: :game, :spots.', ['tournament' => $tournament->title(), 'game' => $gameLine, 'spots' => trans_choice(':count spot left|:count spots left', $open)])
            : __(':tournament on TWENTY ONE Esports: :game.', ['tournament' => $tournament->title(), 'game' => $gameLine]);

        $chips = [
            ['tournaments', __('Format'), $formatLabel.($tournament->format === TournamentFormat::Swiss && $options->swissRounds !== null ? ', '.trans_choice(':count round|:count rounds', $options->swissRounds) : ''), 'format'],
            // Online the end is open (P18): the start, and when it is expected to end, never a planned duration.
            ['flag', __('Starts'), $at($tournament->starts_at).(($openEnd = $landing->openEndLine($zone)) !== null ? ' · '.$openEnd : ''), 'starts'],
            ...($openEnd === null ? [['clock', __('Planned duration'), __('about :duration', ['duration' => Estimator::format($tournament->plannedDuration(), $profile)]), 'duration']] : []),
            [$tournament->on_site ? 'home' : 'wifi', __('Where'), $tournament->on_site ? __('On site').', '.trans_choice(':count station|:count stations', (int) $tournament->stations) : __('Online'), 'where'],
            ['shield-check', __('Results'), match (true) {
                $tmnfWeek => __('every finish timed by our own TMNF server; a time far below the author time waits for an admin'),
                $week => __('every ranked run replayed by the league; only a run that reaches the same time counts'),
                $score => __('values read from the game, or submitted with a proof link an admin checks'),
                // As the form and the published rules say it (GameProfile::playedOnLeague()), as a fact chip's value line (R2): the
                // sentence ran 2 lines, 59 px, beside the chat rail at 1280 (TournamentNowTest).
                $profile->playedOnLeague($tournament->isDirectorMode()) => __('Played on the league, nobody reports'),
                default => $tournament->results_mode->label(),
            }, 'results'],
            ['ladder', __('Rated'), match (true) {
                $score => __('no: a leaderboard has no Elo ladder; its places score points on the game\'s points ladder'),
                $status === TournamentStatus::Draft => __('decided when it is published'),
                $tournament->ladder_address === null => __('no: published before Block 0, so every match is casual'),
                // A board game (plan "Mühle und Dame", P6) is rated like chess when played here.
                $profile->isBoard() && $tournament->isDirectorMode() => __('no: a board game result the directors enter was not played on the league server'),
                ! $chess && ! $profile->isBoard() && ! $tournament->isDirectorMode() => __('yes, if its ladder is open at the pairing and the trust gate passes; counts once the other side confirms. Mix teams and same-clan pairings play casual'),
                default => __('yes, on its ladder while that is open and the trust gate passes'),
            }, 'rated'],
            ...($score && $scoreGame instanceof \App\Games\ScoreGame ? [
                // A Blockfill week's course is its mode: by its own blocks ("60 blocks", BlockfillRules), not its slug (P6).
                ['flag', __($scoreGame->courseLabel()), match (true) {
                    $tmnfWeek => $tmnfTrack['name'] ?? (string) $tournament->score_course,
                    $week => \App\Support\Stacker\BlockfillRules::weekBlocks($tournament),
                    default => $tournament->score_course ?? __('the directors set it before the start'),
                }, 'course'],
                ['award', __('Wins'), $scoreMetric?->lowerIsBetter() ? __('the fastest time; a tie goes to the earlier record') : __('the highest score; a tie goes to the earlier record'), 'wins'],
            ] : [['award', __('Seeding'), __('by Elo at sign-up close'), 'seeding']]),
            ['mining', __('Season chain'), $week ? __('separate: a week mines no season blocks') : __('separate: tournament matches never mine season blocks'), 'chain'],
        ];

        if (! $drawn && $tournament->signup_closes_at !== null) {
            array_splice($chips, 2, 0, [['lock', __('Sign-up closes'), $at($tournament->signup_closes_at), 'closes']]);
        }

        // A round robin's or Swiss's tie for place 1 that points and every tie-break leave (FirstPlaceTie): what the
        // organizer chose, before the season chain (DerCaddy, 2026-11-06: "und dann auch irgendwo sichtbar sein").
        $firstTie = \App\Support\Tournaments\FirstPlaceTie::mode($tournament);

        if ($firstTie !== \App\Support\Tournaments\FormatOptions::FIRST_PLACE_TIE_SEED) {
            array_splice($chips, count($chips) - 1, 0, [['award', __('Tie for 1st place'), $firstTie === 'playoff'
                ? __('a tie-break match among those level on points and every tie-break; no draw, as in a knockout')
                : __('those level on points and every tie-break share 1st place and the prizes of the places they hold'), 'first-tie']]);
        }

        // A two-leg race game (Blockli): how a 1:1 and a drawn third game are settled (draw_decider, user 2026-10-11).
        if (\App\Support\Tournaments\TournamentRunner::canDecideByStanding($tournament->game)) {
            // Per stage what really happens (TournamentRunner::stagePlaysTwoLegs(), stageAllowsDraw()): a knockout plays
            // a third game at 1:1; Swiss and a round-robin table take 1:1 as a draw, and a round-robin table plays what the
            // organizer chose (FormatOptions::$legs, $legPoints; DerCaddy, 2026-10-18). Two stages name both.
            $legOptions = $tournament->formatOptions();
            $stagePairings = function (\App\Enums\TournamentFormat $stage) use ($tournament, $legOptions): string {
                if (! \App\Support\Tournaments\TournamentRunner::stagePlaysTwoLegs($tournament, $stage)) {
                    return __('one game per pairing; the colours even out over the rounds');
                }

                if (\App\Support\Tournaments\TournamentRunner::stageAllowsDraw($stage)) {
                    return \App\Support\Tournaments\TournamentRunner::choosesLegs($tournament->game, $stage) && $legOptions->perLeg()
                        ? __('two games with the colours swapped, counted game by game: a win 1, a draw ½ each')
                        : __('two games with the colours swapped, counted as one pairing: 1:1 is a draw');
                }

                return $tournament->decidesDrawByStanding()
                    ? __('two games with the colours swapped; at 1:1 a third game decides, and if it is drawn the race standing of its final position decides (a block counts :rate steps); a level standing is replayed', ['rate' => app()->getLocale() === 'de' ? str_replace('.', ',', (string) \App\Support\Board\BlockliRules::BLOCK_STEPS) : (string) \App\Support\Board\BlockliRules::BLOCK_STEPS])
                    : trans_choice('two games with the colours swapped; at 1:1 a third game decides, a drawn one is replayed up to :count time, then the higher seed goes through|two games with the colours swapped; at 1:1 a third game decides, a drawn one is replayed up to :count times, then the higher seed goes through', \App\Support\Tournaments\TournamentRunner::drawnReplays($tournament));
            };

            // Two stages that play apart get a fact each: one card holding both squeezed to 18 lines at 1280 (design-lead).
            $groups = $tournament->format === \App\Enums\TournamentFormat::TwoStage ? $stagePairings(\App\Enums\TournamentFormat::from($legOptions->groupStage)) : null;
            $final = $tournament->format === \App\Enums\TournamentFormat::TwoStage ? $stagePairings(\App\Enums\TournamentFormat::from($legOptions->finalStage)) : $stagePairings($tournament->format);

            array_splice($chips, 1, 0, $groups !== null && $groups !== $final
                ? [['award', __('Group stage'), $groups, 'pairings-groups'], ['award', __('Final stage'), $final, 'pairings-final']]
                : [['award', __('Pairings'), $final, 'pairings']]);
        }

        $proof = array_values(array_filter([
            $tournament->naddr() ? ['naddr', $tournament->naddr()] : null,
            $tournament->event ? [__('Calendar event'), $tournament->event->event_id] : null,
            $tournament->draw_height ? [__('Draw block'), (string) $tournament->draw_height] : null,
            $tournament->draw_hash ? [__('Block hash'), $tournament->draw_hash] : null,
            $tournament->drawEvent ? [__('Draw event'), $tournament->drawEvent->event_id] : null,
        ]));

        $steps = [
            [__('Sign up'), $teams
                ? __('Captains enter their lineup; everyone else enters solo and is drawn into a mix team. You confirm with your Nostr key and can pull out until sign-up closes.')
                : __('Confirm with your Nostr key. You can pull out until sign-up closes.')],
            [__('The draw'), $teams
                ? __('When sign-up closes, lineups are seeded by Elo. The hash of the next Bitcoin block draws the mix teams and seeds the bracket, so anyone can re-check it.')
                : __('When sign-up closes, players are seeded by Elo, equal Elo by earlier sign-up. The hash of the next Bitcoin block seeds the bracket.')],
            [__('Play'), $tournament->isDirectorMode()
                ? __('Play your match; the tournament directors enter the result. Winners move on until the last match decides.')
                : ($playsHere ? __('Your games start here on the site, with a clock. Winners move on until the last round decides.') : __('Report your series; the other side confirms it. Winners move on until the last match decides.'))],
        ];

        if ($lobbies) {
            $steps = [
                $steps[0],
                [__('The draw'), __('When sign-up closes, players are seeded by Elo, and the hash of the next Bitcoin block splits them evenly into lobbies of up to :max.', ['max' => \App\Support\Tournaments\Lobbies::maxPlayers($tournament->game)])],
                [__('Play'), __('Join your lobby with the name and password on its card. One diplomacy game; then report the places with a screenshot of the end screen, and a director confirms them.')],
            ];
        }

        if ($score) {
            $steps = [
                $steps[0],
                [__('The window'), __('From the start until the end, play the course alone as often as you like. Your best value inside that time counts; a record set before or after does not.')],
                [__('Your value'), __('Submit your best with a link that proves it, and an admin checks it; or the league reads it from the game. When the window has closed, the best value wins.')],
            ];
        }

        if ($tmnfWeek) {
            $steps = [
                [__('Join'), __('Join our TMNF server and link your login once: the site gives you a code to type in the server chat.')],
                [__('Drive'), __('Drive the track of the week as often as you like. The server times every finish; no sign-up, your first finish puts you on this board.')],
                [__('Leaderboard'), __('Your best finish of the week ranks. The fastest time wins, a tie goes to the earlier finish; after the week its places score points.')],
            ];
        } elseif ($week) {
            $steps = [
                [__('Play'), __('Play a ranked run of Blockfill, logged in and with a keyboard. No sign-up: your first verified run of the week puts you on this board.')],
                [__('Verified'), __('The league replays your run from its seed and your inputs. Only a run that reaches the same time counts; practice runs never do.')],
                [__('Leaderboard'), __('Your best verified run of the week ranks. The fastest time wins, a tie goes to the earlier run; after the week its places score points.')],
            ];
        }

        // A casual cup in sign-up is described and drawn in the format its sign-ups pick now (CasualCups::plannedFormat()).
        $planned = $landing->planned();
        $followNote = CasualCups::followNote($tournament);
        $formatCopy = FormatCopy::for($planned->format, is_string($tournament->options['groupStage'] ?? null) ? $tournament->options['groupStage'] : null);
        $noShow = $score
            ? __('Nobody waits for anybody here: whoever sets no value inside the window gets no place.')
            : ($tournament->isDirectorMode()
            ? __('A tournament director can record a no-show. The other side wins by forfeit, and no Elo changes hands.')
            : ($playsHere
                ? __('Games run here with a clock, like every game on the site.')
                : __('Results come from the players: one side reports, the other confirms. If they disagree, an admin decides.')));

        // Projected bracket before the draw: the chooser's animated preview for the planned size, and round 1 if sign-up closed now.
        $projection = $landing->projection();
        $plannedOptions = $planned->formatOptions();
        $previewOptions = $planned->format === TournamentFormat::Swiss && $plannedOptions->swissRounds === null ? $plannedOptions->withSwissRounds(Estimator::swissDefault($planned->capacity)) : $plannedOptions;
        $preview = $projection !== null ? Preview::for($planned->format, $planned->capacity, $previewOptions, 480, 208) : null;
        $previewSmall = $projection !== null ? Preview::for($planned->format, $planned->capacity, $previewOptions, 326, 196) : null;
        $previewWho = $teams ? trans_choice(':count team|:count teams', $planned->capacity) : trans_choice(':count player|:count players', $planned->capacity);

        $cellCount = $places['places'] <= 96 ? $places['places'] : 0;
        $fillStep = $places['taken'] > 0 ? min(70, (int) round(900 / $places['taken'])) : 0;
        // Sign-up and the draw have no hero of their own: the open desk sits under the sign-up box (user, 2026-10-04),
        // so a phone opens on the tournament's name. With "What to do now" or the champion it comes right after those.
        $deskInHero = $desk !== null && ! $championMoment && ! $now && ! $week && $cta !== 'draft';

        return get_defined_vars();
    }

    /**
     * A push or the fallback poll (tournamentLive, `action: 'refreshLive'`): while the tournament runs and the
     * page keeps its shape, only "What to do now" and the board render again (performance plan P4); anything else
     * (sign-up, the draw, the end, a champion, the desk or the now box appearing) renders the whole page.
     */
    public function refreshLive(): void
    {
        if ($this->renderedShape === '' || $this->renderedShape !== $this->shape()) {
            return;
        }

        $this->renderIsland('now');
        $this->renderIsland('board');
        $this->skipRender();
    }

    /**
     * What decides the page around the islands: the status, the champion, the now box and the desk. Empty when the
     * islands may not render alone: not running, or a score board (its boards hold their own Livewire children).
     */
    private function shape(): string
    {
        if ($this->tournament->status !== TournamentStatus::Running || $this->tournament->profile()->isScore() || $this->tournament->isLeagueWeek()) {
            return '';
        }

        $page = $this->page;

        return implode('|', [$this->tournament->status->value, $page['championMoment'] === null ? 0 : 1, $page['now'] === null ? 0 : 1, $page['desk'] === null ? 0 : 1]);
    }
}; ?>

{{--
    The page as the boards TournamentDetail.dc.html and TournamentDetailPhone.dc.html draw it (plan "Refactor und
    Design-Revamp", P6, template T6): the key-art hero with the marks, the big name, the pot, the champion, the seats,
    the countdown and the one main action; "What to do now" for a player of a running tournament; then the page's
    tabs and the stage stepper, the bracket (2D or the three.js 3D view), who plays beside the prize card, the pot's
    working part and the rules, with the chat in the right rail (the tournament desk for its members, else the game's
    chat) and the Nostr bar under it.
--}}
@php
    extract([...$this->page]);
    $registry = app(\App\Games\GameRegistry::class);
    $hasPool = $this->pool !== null;
    $poolSats = $hasPool ? (int) $this->pool['sats'] : null;
    $poolZaps = $hasPool ? (int) ($this->pool['zaps'] ?? 0) : 0;
    $poolBase = $hasPool ? (int) ($this->pool['base'] ?? $poolSats) : null;
    $poolForwarded = $hasPool ? array_sum(array_column($this->pool['forwarded'] ?? [], 'sats')) : 0;
    $forwardedKeys = $hasPool ? array_column($this->pool['forwarded'] ?? [], 'pubkey') : [];
    $heroChampion = $championMoment['champions'][0] ?? null;
    $heroChampionUser = $heroChampion['users'][0] ?? null;
    // Drawn at the sign-up close, it runs before its start but nothing plays yet (user, 2026-10-25: "Läuft" and "Live
    // zuschauen" stood there 90 minutes before 21:00 with no game running).
    $beforeStart = $status === TournamentStatus::Running && ($countdown['kind'] ?? null) === 'start';
    $statusTag = $beforeStart ? ['rv-tag', 'clock'] : match ($status) {
        TournamentStatus::Finished => ['rv-tag is-ok', 'check'],
        TournamentStatus::Cancelled => ['rv-tag is-bad', 'close'],
        TournamentStatus::Running => ['rv-tag is-ok', null],
        default => ['rv-tag', null],
    };
    $seatsTaken = $drawn ? count($roster) : $places['taken'];
    $seatsAll = $drawn ? max(count($roster), $places['places']) : $places['places'];
    // The stage stepper: done steps green, the current one orange (rule R6); not for a leaderboard or a draft.
    $stepIndex = match ($status) {
        TournamentStatus::Signup => $tournament->isSignupOpen() ? 0 : 1,
        TournamentStatus::Drawing => 2,
        TournamentStatus::Running => 3,
        TournamentStatus::Finished => 4,
        default => null,
    };
    $stepper = $stepIndex !== null && ! $score && ! $week ? [
        ['check', __('Enter the tournament')], ['check', __('Check in')], ['mining', __('Drawing the bracket')], ['play', __('Matches')], ['trophy', __('Winner')],
    ] : [];
    $bracket3d = $drawn && ! $lobbies && $this->stages !== [] && ! $score && ! $week;
    $bracket3dConfig = $bracket3d ? ['url' => route('tournaments.bracket-data', ['tournament' => $tournament, 'lang' => app()->getLocale()]), 'id' => $tournament->id, 'three' => '/hyper/vendor/three.min.js', 'words' => ['live' => __('Live'), 'champion' => __('Champion'), 'standings' => __('Standings'), 'pairings' => __('Pairings')], 'art' => collect(['crown', 'medal-gold', 'medal-silver', 'medal-bronze'])->mapWithKeys(fn (string $id): array => [$id => '/broadcast/art/'.$id.'.webp'])->all()] : null;
    $tabs = array_values(array_filter([
        $score || $blockfillWeek ? ['#leaderboard', __('Leaderboard')] : ['#bracket', $lobbies ? __('Lobbies') : __('Bracket')],
        $blockfillWeek ? null : ['#entries', __('Participants')],
        $hasPool ? ['#prizes', __('Prizes')] : null,
        $published && $status !== TournamentStatus::Cancelled && ! $week ? [route('tournaments.tv', $tournament), __('Live')] : null,
        $blockfillWeek ? null : ['#rules', __('Rules')],
    ]));
    $rail = $desk !== null ? 'desk' : ($registry->find($tournament->game) !== null && ! $week ? 'game' : null);
@endphp

<div class="flex flex-col pb-16 max-lg:pb-36" data-test="tournament-show" data-cta="{{ $cta }}" @if ($poll) x-data="tournamentLive({ id: {{ $tournament->id }}, poll: 15, action: 'refreshLive' })" @endif>

    {{-- A draft at the very top, for whoever may publish it (user, 2026-10-01). --}}
    @if ($status === TournamentStatus::Draft && $this->canManage)
        <section id="publish" aria-labelledby="draft-h" class="mx-4 mb-6 flex scroll-mt-24 flex-col gap-3 rounded-card bg-card px-4 py-5 shadow-[inset_0_0_0_1px_#F7931A] lg:mx-12 lg:px-6" data-test="draft-banner">
            @if (is_string($created = session('status')) && $created !== '')
                <p role="status" class="m-0 rounded-md bg-win-tint px-3 py-2 text-[13px] text-win" data-test="draft-notice">{{ $created }}</p>
            @endif
            <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                <span class="inline-flex h-6 items-center rounded-xs bg-btc-chip px-2 text-xs font-bold text-btc-hi">{{ $status->label() }}</span>
                <h2 id="draft-h" class="m-0 text-[15px] font-bold">{{ __('Players cannot see this tournament yet') }}</h2>
            </div>
            <p class="m-0 max-w-[80ch] text-[13px] leading-normal text-ink-2">{{ __('It is a draft: only you, its tournament directors and the league’s admins can open this page.') }} {{ __('The league publishes the tournament to its calendar on Nostr. Players can sign up until the time you pick; then the draw runs from the next Bitcoin block.') }}</p>
            <form wire:submit="publish" class="flex flex-col gap-3" data-test="publish-form">
                <x-berlin-datetime-input model="closesAt" :label="__('Sign-up closes')" :value="$closesAt" test="closes-at" class="sm:max-w-[400px]" />
                @if ($error !== '')
                    <p class="m-0 text-[13px] text-loss" role="alert">{{ $error }}</p>
                @endif
                <div><x-button type="submit" icon="send" data-test="publish">{{ __('Publish tournament') }}</x-button></div>
            </form>
        </section>
    @endif

    {{-- A member of the tournament desk has the desk beside the whole page from xl, the hero included (user, 2026-10-04:
         the desk open without a click, its field in the first screen); everybody else gets the game's chat under the hero. --}}
    <div @class(['flex flex-col', 'chat-rail-host xl:[--chat-rail-own:3rem] xl:[--chat-rail-top:calc(var(--spacing-page-top-lg)*-1)]' => $rail === 'desk'])>
    @if ($blockfillWeek)
        {{-- A Blockfill week: the game's own hero (cover, the week and its rules as chips, the time to beat, Play now). --}}
        <div class="mx-auto flex w-full max-w-[1440px] flex-col gap-6 px-4 lg:px-12">
            @include('pages.scores.partials.blockfill-hero', ['heading' => $tournament->title(), 'week' => $tournament, 'standings' => $weekStandings, 'metric' => $scoreMetric])
        </div>
    @else
        {{--
            The hero (TournamentDetail.dc.html): the game's key art on the right, faded into the ground; the status and
            organizer marks with the data line, the name big, then the pot, the champion, the seats, the countdown and
            the one main action. On a phone the art sits on top (TournamentDetailPhone.dc.html).
        --}}
        {{-- With a step of their own ("What to do now") the hero is compact, so that step stays in the first screen (user, 2026-10-02/03). --}}
        @php($compactHero = $now && ! $championMoment)
        @php($heroOpenEnd = in_array($status, [TournamentStatus::Finished, TournamentStatus::Cancelled], true) ? null : $landing->openEndLine($zone))
        <section aria-labelledby="t-name" @class(['rv-band -mt-page-top lg:-mt-page-top-lg', 'lg:min-h-[300px]' => ! $compactHero]) data-test="tournament-hero" data-state="{{ $cta }}">
            {{-- With a step of their own to take ("What to do now"), a phone skips the art so the step stays in the first screen. --}}
            <x-game-cover :game="$tournament->game" size="hero" loading="eager" class="{{ ($now && ! $championMoment) || (in_array($cta, ['open', 'entered'], true) && app(\App\Games\GameRegistry::class)->find($tournament->game) instanceof \App\Games\Contracts\PlayedOnOwnCopy) ? 'max-lg:hidden ' : '' }}mx-4 mt-4 rounded-[4px] lg:absolute lg:top-0 lg:right-0 lg:m-0 lg:aspect-video lg:h-auto lg:w-[38%] lg:max-w-[533px] lg:rounded-none [&_img]:object-cover" data-test="game-cover" />
            <div class="absolute inset-y-0 right-0 hidden w-[37%] bg-[linear-gradient(90deg,#0A0A0B,rgba(10,10,11,.1)_45%)] lg:block" aria-hidden="true"></div>

            <div @class(['relative mx-auto flex w-full max-w-[1440px] flex-col gap-3 px-4 pt-4 lg:pr-[40%] lg:pl-12', 'pb-6 lg:pt-8 lg:pb-10' => ! $compactHero, 'pb-3 max-lg:gap-2 max-lg:pt-3 lg:pt-5 lg:pb-5' => $compactHero])>
                <span class="flex flex-wrap items-center gap-2">
                    <span class="{{ $statusTag[0] }}" data-test="tournament-status">@if ($statusTag[1])<x-icon :name="$statusTag[1]" :size="12" />@elseif (in_array($cta, ['open', 'live'], true))<span class="rv-live is-ok" aria-hidden="true"><i></i></span>@endif{{ $beforeStart ? __('Drawn') : $status->label() }}</span>
                    @if ($tournament->isCasualCup())
                        <span class="rv-tag" data-test="casual-marker">{{ __('Casual') }}</span>
                    @elseif (! $week)
                        <span class="rv-tag is-btc"><x-icon name="trophy" :size="12" />{{ __('Organizer tournament') }}</span>
                    @endif
                    <span class="rv-m">{{ $gameLine }}, <span data-test="tournament-format">{{ $formatLabel }}</span>, {{ \App\Support\Engagement\HomeBoard::dayClock($tournament->starts_at) }}@if ($compactHero && $heroOpenEnd)<span class="lg:hidden" data-test="hero-open-end-inline">. {{ $heroOpenEnd }}</span>@endif</span>
                </span>

                <h1 id="t-name" @class(['m-0 font-display leading-[1.1] font-bold [overflow-wrap:anywhere]', 'text-[28px] sm:text-[40px] xl:text-[52px]' => ! $compactHero, 'text-2xl lg:text-[30px]' => $compactHero]) data-test="tournament-name">{{ $tournament->title() }}</h1>

                {{-- A game played outside the site: "you need your own copy", above the action. --}}
                @if (in_array($cta, ['open', 'entered'], true))
                    <div class="max-w-[640px]">@include('pages.tournaments.partials.own-copy', ['tournament' => $tournament])</div>
                @endif

                <div @class(['flex flex-wrap items-end gap-x-8 gap-y-4 pt-1', 'max-lg:pt-0' => $compactHero])>
                    @if ($hasPool)
                        <span class="flex flex-col" data-test="prize-pot">
                            <span class="rv-m">{{ __('Prize pot in sats') }}</span>
                            <b class="font-display text-[26px] leading-tight font-bold whitespace-nowrap text-btc tabular-nums lg:text-[32px]"><span data-test="pool-sats">{{ \App\Support\Cards\ShareCard::sats($poolSats) }}</span> Sats</b>
                            @if ($poolZaps > 0)
                                <span class="rv-m">{{ __(':base + :zaps on top', ['base' => \App\Support\Cards\ShareCard::sats($poolBase), 'zaps' => \App\Support\Cards\ShareCard::sats($poolZaps)]) }}</span>
                            @endif
                            @if ($poolForwarded > 0)
                                <span class="rv-m text-btc-hi" data-test="pool-forwarded-hero">{{ __(':sats passed on', ['sats' => \App\Support\Cards\ShareCard::sats($poolForwarded)]) }}</span>
                            @endif
                        </span>
                    @endif
                    @if ($heroChampion !== null)
                        {{-- The winner of a finished tournament (badge pages link here); a shared 1st place keeps its own line below. --}}
                        <span class="contents" @unless ($championMoment['shared']) data-test="tournament-winner" @endunless>
                        <span class="flex flex-col gap-1" data-test="champion-hero" data-viewer="{{ $championMoment['viewer']['state'] ?? 'guest' }}" data-shared="{{ $championMoment['shared'] ? '1' : '0' }}">
                            <span class="rv-m">{{ $championMoment['shared'] ? __('Shared 1st place') : __('Champion') }}</span>
                            <span class="flex items-center gap-2.5">
                                @if ($heroChampionUser)
                                    <x-avatar :user="$heroChampionUser" :name="$heroChampion['name']" :size="32" class="flex-none" />
                                @else
                                    <span class="rv-av flex-none"><x-icon name="clans" :size="14" /></span>
                                @endif
                                <b class="text-lg [overflow-wrap:anywhere]" data-test="champion-name">{{ implode(', ', array_column($championMoment['champions'], 'name')) }}</b>
                            </span>
                            @if ($heroChampionUser && in_array($heroChampionUser->pubkey, $forwardedKeys, true))
                                <span class="rv-m" data-test="champion-forwarded">{{ __('Prize passed on') }}</span>
                            @endif
                        </span>
                        </span>
                    @endif
                    @if ($published && ! $week && $seatsAll > 0)
                        {{-- Compact on a phone: the label beside the seats, one row less above "What to do now" (user 2026-11-03: 360 x 740). --}}
                        <span @class(['flex flex-col gap-1', 'max-lg:flex-row max-lg:items-center max-lg:gap-3' => $compactHero]) data-test="places-meter">
                            <span class="rv-m">{{ __('Places') }}</span>
                            <span class="flex h-8 items-center gap-2"><x-ui.seats :taken="$seatsTaken" :places="$seatsAll" /><span class="tabular-nums">{{ $seatsTaken }}/{{ $seatsAll }}</span></span>
                        </span>
                    @endif
                    @if (($countdown['kind'] ?? null) === 'start')
                        {{-- The start itself, big, in the viewer's zone (user, 2026-10-25: it stood only at the page's end and in the grey data line). --}}
                        @php($startZone = auth()->user()?->timezone ?? (string) config('esports.preseason.display_timezone'))
                        {{-- A phone with "What to do now" reads the start there, so that card stays in the first screen. --}}
                        <span class="{{ $compactHero ? 'max-lg:!hidden ' : '' }}flex flex-col gap-1" data-test="tournament-start">
                            <span class="rv-m">{{ __('Starts') }}</span>
                            <time datetime="{{ $tournament->starts_at->copy()->utc()->format('Y-m-d\TH:i:s\Z') }}" class="flex items-baseline gap-2">
                                <b class="font-display text-[26px] leading-tight font-bold text-ink tabular-nums lg:text-[32px]" data-test="tournament-start-clock">{{ $tournament->starts_at->copy()->setTimezone($startZone)->format('H:i') }}</b>
                                <span class="rv-m" data-test="tournament-start-day">{{ $tournament->starts_at->copy()->setTimezone($startZone)->translatedFormat('D j M') }} · {{ __(':city time', ['city' => str_replace('_', ' ', \Illuminate\Support\Str::afterLast($startZone, '/'))]) }}</span>
                            </time>
                        </span>
                    @endif
                    {{-- With "What to do now" the start counts down there; the hero keeps its clock alone. --}}
                    @if ($countdown && ! ($compactHero && $countdown['kind'] === 'start'))
                        {{-- The one countdown of the page: sign-up close while it is open, else the start. --}}
                        <span class="flex flex-col gap-1" data-test="tournament-countdown">
                            <span class="rv-m">{{ $countdown['label'] }}</span>
                            <x-ui.countdown :at="\Illuminate\Support\Carbon::createFromTimestampMs($countdown['ms'])" size="sm" :label="$countdown['label']" />
                        </span>
                    @endif
                    @if ($cta !== 'draft')
                        {{-- On a phone the main action is a bar above the tab bar (TournamentDetailPhone, TournamentSignup-Phone.dc.html): always in reach. --}}
                        {{-- With "What to do now" a phone takes its action from there, not from a second bar. --}}
                        <span class="{{ $compactHero ? 'max-lg:!hidden ' : '' }}flex flex-wrap items-center gap-2 max-lg:fixed max-lg:inset-x-0 max-lg:bottom-[calc(var(--tabbar-h)+var(--dock-h))] max-lg:z-30 max-lg:flex-nowrap max-lg:border-t max-lg:border-hairline max-lg:bg-ground max-lg:px-4 max-lg:py-3 max-lg:[&>a]:grow" data-test="signup-cta" data-state="{{ $cta }}" data-floor-owner>
                            @if ($tmnfWeek)
                                <a href="#join" class="rv-b h-12 px-6" data-test="to-tmnf-join"><x-icon name="flag" :size="18" />{{ __('How to join') }}</a>
                            @elseif ($week && \Illuminate\Support\Facades\Route::has('stacker.play'))
                                <a href="{{ route('stacker.play') }}" class="rv-b h-12 px-6" data-test="to-blockfill"><x-icon name="bolt" :size="18" />{{ __('Play Blockfill') }}</a>
                            @else
                                @switch($cta)
                                    @case('open')
                                        <a href="{{ route('tournaments.signup', $tournament) }}" class="rv-b h-12 px-6" data-test="to-signup">{{ __('Enter the tournament') }}</a>
                                        @break
                                    @case('entered')
                                        <a href="{{ route('tournaments.signup', $tournament) }}" class="rv-b2 h-12 border-win-ring px-6 text-win" data-test="to-signup"><x-icon name="check" :size="18" />{{ __('You’re in') }}</a>
                                        @break
                                    @case('live')
                                        @if ($beforeStart)
                                            <a href="{{ $score ? '#leaderboard' : '#bracket' }}" class="rv-b h-12 px-6" data-test="to-bracket"><x-icon name="trophy" :size="18" />{{ __('See the draw') }}</a>
                                        @else
                                            <a href="{{ $score ? '#leaderboard' : '#bracket' }}" class="rv-b h-12 px-6" data-test="to-bracket"><x-icon name="eye" :size="18" />{{ $score ? __('See the leaderboard') : __('Watch live') }}</a>
                                        @endif
                                        @break
                                    @case('finished')
                                        {{-- A shared place 1 has no single winner to zap and no #zap-winner block (a lobby's allies, a table's tie, FirstPlaceTie). --}}
                                        @if ($heroChampion !== null && ! $championMoment['shared'] && ! $week)
                                            <a href="#zap-winner" class="rv-b h-12 px-6" data-test="to-zap-winner"><x-icon name="bolt" :size="18" />{{ __('Zap the winner') }}</a>
                                        @else
                                            <a href="{{ $score ? '#leaderboard' : '#bracket' }}" class="rv-b2 h-12 px-6" data-test="to-bracket">{{ __('See the results') }}</a>
                                        @endif
                                        @break
                                    @default
                                        <span class="rv-b2 h-12 cursor-default px-6 text-ink-2" data-test="cta-closed"><x-icon name="lock" :size="18" />{{ match ($cta) { 'full' => __('Sign-up is full'), 'cancelled' => __('Called off'), default => __('Sign-up closed') } }}</span>
                                @endswitch
                            @endif
                            {{-- The tournament desk stays open a day after the end (TournamentDesk): the button brings its chat into view. --}}
                            <x-tournaments.desk-button :desk="$desk" on-page class="h-12 max-lg:px-3 max-lg:[&>span:first-of-type]:sr-only" />
                            @if (($championMoment['viewer']['state'] ?? null) === 'won' && ! $championMoment['shared'])
                                <livewire:share-button type="tournament" :moment="(string) $tournament->id" :label="__('Share the win')" />
                            @endif
                        </span>
                    @endif
                </div>

                {{-- One data line under the action: what the state means for this viewer (rule: hybrid density, data stays). --}}
                @if (in_array($cta, ['open', 'entered', 'full', 'closed'], true) || ($week && $cta !== 'draft'))
                    <p class="rv-m m-0 max-w-[75ch] text-[13px]" data-test="cta-note">
                        @if ($week)
                            {{ $status === TournamentStatus::Finished
                                ? ($champion ? __('This week is over. Winner: :name. A new week is on.', ['name' => $champion->name]) : __('This week is over. A new week is on.'))
                                : ($tmnfWeek ? __('No sign-up: your first finish on our server puts you on the board below.') : __('No sign-up: your first verified ranked run puts you on the board below.')) }}
                        @else
                            @switch($cta)
                                @case('open') {{ trans_choice(':count spot left. You can pull out until sign-up closes.|:count spots left. You can pull out until sign-up closes.', $open) }} @break
                                @case('entered') {{ match (true) {
                                    $status === TournamentStatus::Drawing => __('Sign-up is closed. The draw waits for Bitcoin block :height; its hash seeds the mix teams and the bracket.', ['height' => $tournament->draw_height]),
                                    $yourSeed !== null => __('Seed :seed if sign-up closed now. Final seeds are set at sign-up close.', ['seed' => $yourSeed]),
                                    default => __('You are signed up: :entry. You can pull out until sign-up closes.', ['entry' => $landing->entry()?->name]),
                                } }} @break
                                @case('full') {{ __('Every spot is taken. Places open up when someone pulls out.') }} @break
                                @case('closed') {{ $status === TournamentStatus::Drawing ? __('Sign-up is closed. The draw waits for Bitcoin block :height; its hash seeds the mix teams and the bracket.', ['height' => $tournament->draw_height]) : __('Sign-up has closed. The draw follows.') }} @break
                            @endswitch
                        @endif
                    </p>
                @endif
                @if ($heroOpenEnd)
                    {{-- Compact on a phone, the expected end continues the data line above instead (user 2026-11-03: 360 x 740). --}}
                    <p @class(['rv-m m-0', 'max-lg:hidden' => $compactHero]) data-test="hero-open-end">{{ $heroOpenEnd }}</p>
                @endif

                @if (($championMoment['viewer']['state'] ?? null) === 'won')
                    <p class="m-0 text-[15px] font-bold text-rank-gold" data-test="champion-you">{{ __('You won!') }} {{ __('Congratulations, champion!') }}</p>
                @elseif (($championMoment['viewer']['state'] ?? null) === 'placed')
                    <p class="m-0 text-[13px] text-ink-2" data-test="champion-you">{{ __('You finished in place :place', ['place' => $championMoment['viewer']['place']]) }}. {{ __('Thanks for playing!') }}</p>
                @endif
                @if (auth()->check() && auth()->user()->lud16 === null && $tournament->hasPot() && ! in_array($status, [TournamentStatus::Finished, TournamentStatus::Cancelled], true))
                    {{-- Prizes need a Lightning address; Google sign-ins have none (user 2026-10-25): say so before a prize waits. --}}
                    <div class="flex max-w-[640px] flex-wrap items-center gap-x-4 gap-y-2 rounded-md border border-btc/50 bg-btc-tint px-4 py-3" data-test="prize-address-ahead">
                        <p class="m-0 min-w-0 grow basis-64 text-[13px] leading-normal">{{ __('This tournament pays prizes in sats. Add a Lightning address to your profile now, so a prize reaches you right away.') }}</p>
                        <a href="{{ route('settings.profile') }}" class="rv-b h-11 shrink-0 px-5"><x-icon name="bolt" :size="16" />{{ __('Add my Lightning address') }}</a>
                    </div>
                @endif
                @php($youPlaced = $championMoment['viewer']['place'] ?? null)
                @if ($youPlaced !== null && $tournament->hasPot() && $youPlaced <= count($tournament->prizeSplit()) && auth()->user()?->lud16 === null)
                    {{-- A prize that waits for a Lightning address (user 2026-10-25: Google sign-ins had none). --}}
                    <div class="flex max-w-[640px] flex-col gap-2 rounded-md border border-btc bg-btc-tint px-4 py-3" data-test="prize-needs-address">
                        <p class="m-0 text-[13px] leading-normal">{{ __('Your prize waits: add a Lightning address to your profile, and the league pays it. Free wallets that give you one are on that page.') }}</p>
                        <a href="{{ route('settings.profile') }}" class="rv-b h-11 self-start px-5"><x-icon name="bolt" :size="16" />{{ __('Add my Lightning address') }}</a>
                    </div>
                @endif
                @if ($championMoment !== null && $championMoment['prize'] !== null)
                    <span class="sr-only" data-test="champion-prize">{{ number_format($championMoment['prize']) }} {{ __('sats prize') }}</span>
                @endif

            </div>
        </section>
    @endif

    {{-- A TMNF week: How to join, big, right under the hero (plan "Trackmania und Restposten", P2). --}}
    @if ($tmnfWeek)
        <div class="mx-auto w-full max-w-[1440px] px-4 pt-6 lg:px-12">@include('pages.scores.partials.tmnf-join', ['week' => $tournament, 'track' => $tmnfTrack])</div>
    @endif

    {{--
        The main column and the chat rail (rule: chat is primary, the same place in every template): from xl the rail
        stands on the right from the tabs down; below xl it follows "What to do now".
        The bracket's 2D / 3D switch (bracketView, resources/js/bracketView.js) holds the whole column, so its toggle
        sits in the tab row; three.js loads only when a viewer picks 3D.
    --}}
    <div @class(['group/bv mx-auto flex w-full max-w-[1440px] flex-col gap-8 px-4 pt-6 lg:px-12 lg:pt-8', 'max-lg:pt-3' => $now && ! $championMoment && ! $blockfillWeek, 'chat-rail-host xl:[--chat-rail-top:2rem] xl:[--chat-rail-own:3rem]' => $rail === 'game'])
         data-bracket-view="2d" @if ($bracket3d) wire:ignore.self x-data="bracketView({{ \Illuminate\Support\Js::from($bracket3dConfig) }})" @endif data-test="tournament-body">
        @if ($bracket3d)
            {{-- A remembered 3D shows its box before the first paint, so the page does not jump once scripts run. --}}
            <script>(function (s) { try { if (localStorage.getItem('bracket-view') === '3d') { var c = document.createElement('canvas'); if (window.WebGLRenderingContext && (c.getContext('webgl2') || c.getContext('webgl'))) s.dataset.bracketView = '3d'; } } catch (e) {} })(document.currentScript.parentElement);</script>
        @endif

        {{-- "What to do now" (TournamentNow, user 2026-10-03): a player's one next step while the tournament runs. --}}
        @island(name: 'now', always: true)
        @php(extract([...$this->page]))
        <x-tournament-night-pause :tournament="$tournament" class="mx-4 lg:mx-12" />
        @if ($now && ! $championMoment)
            @include('pages.tournaments.partials.now', ['now' => $now, 'tournament' => $tournament, 'cup' => $this->cupMatch, 'error' => $cupError, 'waits' => $this->myWaits, 'lobby' => $this->myLobby, 'desk' => $desk])
        @endif
        @endisland

        @if ($this->canManage || $this->canDirect)
            {{-- The organizer's way to the T6 desk pages, after "What to do now" (user, 2026-10-03); on a phone one compact row. --}}
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:gap-4" data-test="manage-bar">
                <span class="rv-tag is-btc-soft max-sm:sr-only">{{ __('Organizers only') }}</span>
                <x-tournaments.manage-actions :tournament="$tournament" :except="['publish']" compact />
            </div>
        @endif

        @if ($rail === 'desk')
            <div class="chat-rail flex flex-col gap-4" data-test="desk-rail"><x-tournaments.desk-chat :desk="$desk" /></div>
        @elseif ($rail === 'game')
            <div class="chat-rail tl-rail flex flex-col gap-4" x-data="{ fit() { const top = this.$el.getBoundingClientRect().top + scrollY; this.$el.style.setProperty('--tl-rail-h', Math.max(480, innerHeight - top - 24) + 'px'); } }" x-init="fit()" x-on:resize.window.debounce.150ms="fit()" data-test="game-rail"><livewire:game-channel :game="$tournament->game" :wire:key="'game-channel-'.$tournament->game" /></div>
        @endif

        {{-- The tale of the tape while the final waits (plan "KI-Stimme der Liga", P4). --}}
        @php($tapeFinal = \App\Support\Cards\PrideMoments::finalMayBeNext($this->stages) ? \App\Support\Cards\PrideMoments::upcomingFinal($tournament) : null)
        @if ($tapeFinal !== null)
            @include('pages.tournaments.partials.tape', ['tournament' => $tournament, 'final' => $tapeFinal])
        @endif

        {{-- The page's tabs (one order on every tournament page) and, beside them, the bracket view's switch. --}}
        <div class="flex items-end gap-4">
            <nav class="rv-tabs grow" aria-label="{{ __('Tournament') }}" data-test="tournament-tabs">
                @foreach ($tabs as [$href, $label])
                    <a href="{{ $href }}" class="rv-tab" @if ($loop->first) aria-current="true" @endif>{{ $label }}</a>
                @endforeach
            </nav>
            @if ($bracket3d)
                <span role="radiogroup" aria-label="{{ __('Bracket view') }}" wire:ignore class="mb-1.5 flex flex-none gap-1" data-test="bracket-view-toggle">
                    @foreach (['2d' => '2D', '3d' => '3D'] as $choice => $text)
                        <button type="button" role="radio" x-on:click="choose('{{ $choice }}')" :aria-checked="view === '{{ $choice }}' ? 'true' : 'false'" aria-checked="{{ $choice === '2d' ? 'true' : 'false' }}"
                                @if ($choice === '3d') :disabled="! webgl" :title="webgl ? '' : @js(__('The 3D view needs WebGL, which this browser has switched off.'))" @endif
                                @class(['rv-chip min-w-11 justify-center disabled:cursor-not-allowed disabled:opacity-50',
                                    'border-btc bg-btc font-bold text-on-btc group-data-[bracket-view=3d]/bv:border-line group-data-[bracket-view=3d]/bv:bg-ground group-data-[bracket-view=3d]/bv:font-normal group-data-[bracket-view=3d]/bv:text-ink-2' => $choice === '2d',
                                    'group-data-[bracket-view=3d]/bv:border-btc group-data-[bracket-view=3d]/bv:bg-btc group-data-[bracket-view=3d]/bv:font-bold group-data-[bracket-view=3d]/bv:text-on-btc' => $choice === '3d'])
                                data-test="bracket-view-{{ $choice }}">{{ $text }}</button>
                    @endforeach
                </span>
            @endif
        </div>

        @if ($stepper !== [])
            <ol class="rv-steps m-0 list-none p-0 max-sm:hidden" aria-label="{{ __('Stages') }}" data-test="stage-stepper">
                @foreach ($stepper as $index => [$icon, $label])
                    <li @class(['is-done' => $index < $stepIndex]) @if ($index === $stepIndex) aria-current="step" @endif>
                        <x-icon :name="$index < $stepIndex ? 'check' : $icon" :size="16" class="flex-none" /><span class="min-w-0">{{ $label }}</span>
                    </li>
                @endforeach
            </ol>
        @endif

        {{-- The board: the bracket (or the leaderboard), who plays and the prizes; rendered alone on a push while the tournament runs (refreshLive). --}}
        @island(name: 'board', always: true)
        @php(extract([...$this->page]))
        @php($bracket3d = $drawn && ! $lobbies && $this->stages !== [] && ! $score && ! $week)
@php($bracketChampion = $championMoment && ! $championMoment['shared'] ? ['name' => $championMoment['champions'][0]['name'], 'line' => implode(', ', array_filter([$championMoment['prize'] !== null ? __(':sats Sats prize money', ['sats' => \App\Support\Engagement\HomeBoard::sats($championMoment['prize'])]) : null, $championMoment['record'] !== null ? trans_choice(':count win|:count wins', $championMoment['record']['wins']) : null, $championMoment['record'] !== null ? ($championMoment['record']['losses'] > 0 ? trans_choice(':count loss|:count losses', $championMoment['record']['losses']) : __('no loss')) : null]))] : null)
        @if ($blockfillWeek)
            @include('pages.tournaments.partials.blockfill-board', ['tournament' => $tournament, 'standings' => $weekStandings, 'metric' => $scoreMetric])
        @elseif ($score)
            @include('pages.scores.partials.tournament-board', ['tournament' => $tournament, 'metric' => $scoreMetric, 'drawn' => $drawn])
        @else
            <section id="bracket" aria-labelledby="bracket-h" class="flex scroll-mt-24 flex-col gap-4" data-test="bracket">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <span class="flex flex-wrap items-center gap-3">
                        <h2 id="bracket-h" class="rv-h3">{{ $lobbies ? __('Lobbies') : __('Bracket') }}</h2>
                        @if ($projection !== null)
                            <span class="rv-tag border-dashed" data-test="projected-chip">{{ __('Projected') }}</span>
                        @endif
                    </span>
                    <span class="flex flex-wrap items-center gap-2">
                        @if ($published && $status !== TournamentStatus::Cancelled && ! $tournament->isLeagueWeek())
                            <a href="{{ route('tournaments.tv', $tournament) }}" class="rv-b2 is-sm" data-test="to-tv"><x-icon name="eye" :size="14" />{{ __('TV view') }}</a>
                        @endif
                        @if ($drawn && $tournament->draw_hash)
                            <a href="{{ route('tournaments.draw', $tournament) }}" class="rv-b2 is-sm"><x-icon name="shield-check" :size="14" />{{ __('The draw') }}</a>
                        @endif
                        @if ($drawn && $this->canDirect && $tournament->isDirectorMode())
                            <a href="{{ route('tournaments.director', $tournament) }}" class="rv-b2 is-sm" data-test="to-director">{{ __('Director desk') }}</a>
                        @endif
                    </span>
                </div>

                @if ($followNote !== null && $projection !== null)
                    <p class="m-0 inline-flex items-center gap-2 self-start rounded-sm bg-btc-chip px-3 py-1.5 text-[13px] text-btc-hi" data-test="format-follows">
                        <x-icon name="tournaments" :size="14" />{{ $followNote }}
                    </p>
                @endif

                @if ($drawn && $lobbies)
                    <livewire:tournament-lobbies :tournament="$tournament" :key="'lobbies-'.$tournament->id" />
                @elseif ($drawn && $bracket3d)
                    <div wire:ignore data-bracket-3d class="hidden flex-col overflow-hidden rounded-card bg-ground shadow-ring-hairline group-data-[bracket-view=3d]/bv:flex" data-test="bracket-3d">
                        <div class="relative">
                            <canvas class="block aspect-[3/4] max-h-[75vh] w-full touch-pan-y sm:aspect-video sm:max-h-[min(70vh,720px)]" role="img" aria-label="{{ __('The bracket in 3D. The 2D view lists every match as text.') }}"></canvas>
                            <p class="absolute inset-0 m-0 flex items-center justify-center text-[13px] text-ink-2" x-show="state === 'loading'" x-cloak>{{ __('Loading the 3D view') }}</p>
                        </div>
                        <div class="flex items-center gap-2 border-t border-hairline px-2 py-1.5">
                            <button type="button" x-on:click="prev()" class="inline-flex size-11 shrink-0 cursor-pointer items-center justify-center rounded-sm border-0 bg-transparent text-ink hover:bg-raised" aria-label="{{ __('Previous part of the bracket') }}" data-test="bracket-3d-prev">
                                <x-icon name="chevron-down" :size="18" class="rotate-90" />
                            </button>
                            <span class="min-w-0 grow truncate text-center text-[13px] font-bold" aria-live="polite" x-text="page ? page.title : ''" data-test="bracket-3d-title"></span>
                            <button type="button" x-on:click="next()" class="inline-flex size-11 shrink-0 cursor-pointer items-center justify-center rounded-sm border-0 bg-transparent text-ink hover:bg-raised" aria-label="{{ __('Next part of the bracket') }}" data-test="bracket-3d-next">
                                <x-icon name="chevron-down" :size="18" class="-rotate-90" />
                            </button>
                        </div>
                    </div>
                    <div class="rv-card p-4 group-data-[bracket-view=3d]/bv:hidden lg:p-6" data-test="bracket-2d">
                        @include('pages.tournaments.partials.stages', ['stages' => $this->stages, 'tournament' => $tournament, 'champion' => $bracketChampion])
                    </div>
                @elseif ($drawn)
                    <div class="rv-card p-4 lg:p-6">@include('pages.tournaments.partials.stages', ['stages' => $this->stages, 'tournament' => $tournament, 'champion' => $bracketChampion])</div>
                @elseif ($projection !== null)
                    <div class="grid gap-4 lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)] lg:items-start" data-test="bracket-preview" data-format="{{ $planned->format->value }}" data-players="{{ $planned->capacity }}">
                        <figure class="rv-card m-0 flex flex-col gap-2 p-4" x-data x-ref="figure">
                            @foreach ([['desktop', $preview, 480, 208, 'hidden lg:flex'], ['mobile', $previewSmall, 326, 196, 'flex lg:hidden']] as [$size, $shape, $width, $height, $visibility])
                                {{-- The top row's labels sit above the drawing as HTML (Preview::heads): 12 px, wrapping in their column, never past the edge. --}}
                                @php(['heads' => $heads, 'texts' => $inside] = \App\Support\Tournaments\Preview::heads($shape, $width))
                                @php($top = $heads === [] ? 0 : 12)
                                <div class="{{ $visibility }} flex-col gap-1" wire:key="pv-{{ $size }}" data-test="preview-{{ $size }}">
                                @if ($heads !== [])
                                    <div class="grid text-xs leading-[1.25] text-ink-3" aria-hidden="true" data-test="preview-heads">
                                        @foreach ($heads as $head)
                                            <span @class(['col-start-1 row-start-1 self-end [overflow-wrap:anywhere]', 'text-right' => $head['end']]) style="margin-left: {{ $head['left'] }}%; width: {{ $head['width'] }}%" data-test="preview-head">{{ __($head['text'], $head['replace']) }}</span>
                                        @endforeach
                                    </div>
                                @endif
                                <svg viewBox="0 {{ $top }} {{ $width }} {{ $height - $top }}" class="tf-preview h-auto w-full" role="img"
                                     aria-label="{{ __('Preview of :format for :who', ['format' => $formatLabel, 'who' => $previewWho]) }}">
                                    @foreach ($shape['lines'] as $line)
                                        <path d="{{ $line['d'] }}" fill="none" stroke="#3A3A42" stroke-width="1" style="animation-delay: {{ $line['delay'] }}s" />
                                    @endforeach
                                    @foreach ($shape['rects'] as $rect)
                                        <rect x="{{ $rect['x'] }}" y="{{ $rect['y'] }}" width="{{ $rect['w'] }}" height="{{ $rect['h'] }}" rx="2"
                                              fill="{{ $rect['ghost'] ? 'transparent' : '#F7931A' }}" stroke="{{ $rect['ghost'] ? '#8B8B90' : 'none' }}" @if ($rect['ghost']) stroke-dasharray="3 3" @endif
                                              style="animation-delay: {{ $rect['delay'] }}s" />
                                    @endforeach
                                    @foreach ($inside as $text)
                                        <text x="{{ $text['x'] }}" y="{{ $text['y'] }}" text-anchor="{{ $text['anchor'] }}" fill="#8B8B90" font-size="12" font-family="JetBrains Mono, monospace">{{ __($text['text'], $text['replace']) }}</text>
                                    @endforeach
                                </svg>
                                </div>
                            @endforeach
                            <figcaption class="flex items-start gap-3 text-xs leading-normal text-ink-2">
                                <span class="grow" data-test="preview-caption">{{ __(':format for :who. Each block is one match, lit in the round it is played.', ['format' => $formatLabel, 'who' => $previewWho]) }}</span>
                                <button type="button" class="min-h-6 shrink-0 cursor-pointer text-btc hover:text-btc-hi" data-test="preview-replay"
                                        x-on:click="$refs.figure.querySelectorAll('svg').forEach((svg) => { svg.classList.remove('tf-preview'); void svg.getBoundingClientRect(); svg.classList.add('tf-preview'); })">{{ __('Play again') }}</button>
                            </figcaption>
                        </figure>

                        <div class="rv-card flex min-w-0 flex-col gap-3 p-4">
                            <h3 class="m-0 text-[15px] font-bold">{{ $projection['groups'] !== [] ? __('Groups if sign-up closed now') : ($lobbies ? __('Lobbies if sign-up closed now') : __('Round 1 if sign-up closed now')) }}</h3>
                            @if ($projection['groups'] !== [])
                                <div class="grid grid-cols-2 gap-2 sm:gap-3">
                                    @foreach ($projection['groups'] as $number => $members)
                                        <div class="flex flex-col gap-1.5 rounded-md bg-ground p-3 shadow-ring-hairline" wire:key="pg-{{ $number }}">
                                            <span class="text-xs font-bold text-ink-2">{{ __('Group :group', ['group' => chr(64 + $number)]) }}</span>
                                            @foreach ($members as $side)
                                                @include('pages.tournaments.partials.projected-side', ['side' => $side])
                                            @endforeach
                                        </div>
                                    @endforeach
                                </div>
                            @else
                                <ol class="m-0 grid list-none gap-2 p-0 sm:grid-cols-2">
                                    @foreach ($projection['matches'] as $match)
                                        <li class="flex flex-col gap-1 rounded-md bg-ground p-2.5 shadow-ring-hairline" wire:key="pm-{{ $match['key'] }}" data-test="projected-match">
                                            @foreach ($match['sides'] as $side)
                                                @include('pages.tournaments.partials.projected-side', ['side' => $side])
                                            @endforeach
                                        </li>
                                    @endforeach
                                </ol>
                                @if ($projection['byes'] !== [])
                                    <p class="m-0 text-xs leading-normal text-ink-2">{{ trans_choice('Seed :seeds skips round 1 (bye).|Seeds :seeds skip round 1 (bye).', count($projection['byes']), ['seeds' => implode(', ', $projection['byes'])]) }}</p>
                                @endif
                            @endif
                            <p class="m-0 text-xs leading-normal text-ink-3">{{ __('A preview, not the draw. Seeds are fixed when sign-up closes; the hash of the next Bitcoin block seeds the real bracket.') }}</p>
                        </div>
                    </div>
                @else
                    <p class="m-0 text-[13px] text-ink-2">{{ __('The tournament was called off.') }}</p>
                @endif

                @if ($drawn && $tournament->isDirectorMode())
                    {{-- The director log is public (TOURNAMENT-FORMATS.md, section 6). --}}
                    <details class="group rounded-card bg-card" data-test="public-log">
                        <summary class="flex min-h-12 cursor-pointer items-center gap-3 px-4 lg:px-6">
                            <h3 class="m-0 text-[15px] font-bold">{{ __('Director log') }}</h3>
                            <span class="grow text-xs text-ink-3">{{ __('public, newest first') }}</span>
                            <x-icon name="chevron-down" :size="16" class="transition-transform group-open:rotate-180" />
                        </summary>
                        <div class="flex flex-col gap-2 px-4 pb-4 lg:px-6">
                            @forelse ($tournament->resultEntries()->with('match')->limit(20)->get() as $entry)
                                <p class="m-0 flex gap-3 border-t border-hairline pt-2 text-xs leading-normal">
                                    <span class="shrink-0 text-ink-3">{{ $entry->created_at->copy()->timezone($zone)->format('H:i') }}</span>
                                    <span><b>{{ strtoupper($entry->match->key) }} {{ $entry->result['label'] ?? '' }}</b> {{ __('by :name', ['name' => $entry->user_name]) }}@if ($entry->isCorrection()), <span class="text-btc">{{ __('correction, was :old', ['old' => $entry->replaced['label'] ?? '']) }}</span>@endif</span>
                                </p>
                            @empty
                                <p class="m-0 text-xs text-ink-2">{{ __('No result entered yet.') }}</p>
                            @endforelse
                        </div>
                    </details>
                @endif
            </section>
        @endif

        @include('pages.tournaments.partials.recap', ['tournament' => $tournament])

        {{-- Who plays beside the prize card (TournamentDetail.dc.html: "Teilnehmer, Setzliste" and "Preispool"). --}}
        <div @class(['grid grid-cols-1 items-start gap-6', 'lg:grid-cols-2' => $this->pool !== null && ! $blockfillWeek])>
            @unless ($blockfillWeek)
                <section id="entries" aria-labelledby="entries-h" class="flex scroll-mt-24 flex-col gap-3" data-test="entries">
                    <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                        <h2 id="entries-h" class="rv-h3">{{ $score ? __('Who plays') : __('Participants, seeds') }}</h2>
                        @unless ($score)
                            <span class="rv-m">{{ $drawn ? __('Seeded by Elo at sign-up close') : __('Seeds if sign-up closed now, by Elo; equal Elo by earlier sign-up') }}</span>
                        @endunless
                    </div>
                    <ul class="m-0 grid list-none grid-cols-1 gap-2 p-0 sm:grid-cols-2">
                        @foreach ($roster as $row)
                            @php($isChampion = $champion && $row['key'] === 'participant-'.$champion->id)
                            <li wire:key="{{ $row['key'] }}" data-test="roster-entry" @if ($row['you']) data-you @endif
                                @class(['relative flex h-[52px] min-w-0 items-center gap-2.5 rounded-[4px] border px-3', 'border-hairline bg-card' => ! $row['you'] && ! $isChampion, 'border-btc bg-btc-chip' => $row['you'] || $isChampion])>
                                @if ($row['seed'] !== null && ! $week)
                                    <span class="w-6 flex-none text-[13px] text-ink-3 tabular-nums" title="{{ __('Seed :seed', ['seed' => $row['seed']]) }}"><span class="sr-only">{{ __('Seed') }} </span>#{{ $row['seed'] }}</span>
                                @endif
                                @if ($row['kind'] === 'lineup' && $row['clan'])
                                    <x-clan-tag :clan="$row['clan']" :tile="24" class="flex size-6 shrink-0 items-center justify-center rounded-full bg-btc-tint text-[9px] font-bold text-btc" />
                                @elseif (isset($row['users'][0]) && in_array($row['kind'], ['player', 'solo'], true))
                                    <x-avatar :user="$row['users'][0]" :name="$row['name']" :size="24" class="flex-none" />
                                @else
                                    <span class="rv-av is-sm flex-none"><x-icon name="clans" :size="12" /></span>
                                @endif
                                <span class="flex min-w-0 grow flex-col" @if ($row['rating'] !== null && ! $score) title="{{ __(':rating Elo', ['rating' => $row['rating']]) }}" @endif>
                                    @if ($row['href'])
                                        <a href="{{ $row['href'] }}" class="truncate text-sm font-bold text-ink hover:text-btc-hi">{{ $row['name'] }}</a>
                                    @else
                                        <span class="truncate text-sm font-bold">{{ $row['name'] }}</span>
                                    @endif
                                    @if ($row['kind'] === 'solo')
                                        <span class="rv-m truncate">{{ __('Solo, drawn into a mix team') }}</span>
                                    @elseif ($row['kind'] === 'mix')
                                        <span class="rv-m truncate">{{ __('Mix team') }}</span>
                                    @endif
                                </span>
                                @if ($row['you'])
                                    <span class="absolute -top-2 left-3 inline-flex h-5 items-center rounded-xs bg-btc px-1.5 text-[11px] font-bold text-on-btc" data-test="you-chip">{{ __('You') }}</span>
                                @endif
                                @if ($isChampion)
                                    <x-icon name="trophy" :size="14" class="flex-none text-btc" />
                                @endif
                            </li>
                        @endforeach

                        @if (! $drawn && $published && $status !== TournamentStatus::Cancelled && $open > 0)
                            @for ($seat = 0; $seat < min($open, \App\Support\Tournaments\TournamentLanding::OPEN_TILES); $seat++)
                                <li wire:key="open-{{ $seat }}" data-test="open-seat" @class(['flex h-[52px] items-center gap-2.5 rounded-[4px] border border-dashed border-dash px-3', 'max-sm:hidden' => $seat >= 3])>
                                    <span class="flex size-6 flex-none items-center justify-center rounded-full border border-dashed border-dash text-ink-3"><x-icon name="user" :size="12" /></span>
                                    @if ($seat === 0 && $cta === 'open')
                                        <a href="{{ route('tournaments.signup', $tournament) }}" class="text-[13px] font-bold" data-test="your-seat">{{ __('Your spot?') }}</a>
                                    @else
                                        <span class="text-[13px] text-ink-3">{{ __('Open spot') }}</span>
                                    @endif
                                </li>
                            @endfor
                            @if ($open > \App\Support\Tournaments\TournamentLanding::OPEN_TILES)
                                <li wire:key="open-more" class="flex h-[52px] items-center justify-center rounded-[4px] border border-dashed border-dash px-3 text-[13px] text-ink-3 max-sm:hidden" data-test="open-more">
                                    {{ trans_choice('+:count more open spot|+:count more open spots', $open - \App\Support\Tournaments\TournamentLanding::OPEN_TILES) }}
                                </li>
                            @endif
                        @endif
                    </ul>

                    @if ($roster === [] && $tmnfWeek)
                        <p class="m-0 text-[13px] text-ink-2" data-test="entries-empty">{{ __('No finish yet this week. Drive the first one.') }}</p>
                    @elseif ($roster === [] && $week)
                        <p class="m-0 text-[13px] text-ink-2" data-test="entries-empty">{{ __('No verified run yet this week. Play the first one.') }}</p>
                    @elseif ($roster === [] && ($drawn || ! $published || $status === TournamentStatus::Cancelled))
                        <p class="m-0 text-[13px] text-ink-2" data-test="entries-empty">{{ __('Nobody has signed up yet.') }}</p>
                    @endif
                    @if (! $drawn && $published)
                        {{-- P48: NIP-52 RSVPs from the relays, under the sign-ups and never added to them --}}
                        <livewire:rsvp-summary :tournament="$tournament->id" :key="'rsvps-'.$tournament->id" />
                    @endif
                </section>
            @endunless

            @if ($this->pool !== null)
                <div id="prizes" class="min-w-0 scroll-mt-24">
                    @include('pages.tournaments.partials.prize-pool', ['pool' => $this->pool, 'tournament' => $tournament, 'manage' => $this->canManage,
                        'topUp' => \App\Support\Prizes\PotTopUps::enabled($tournament), 'results' => $championMoment['podium'] ?? []])
                </div>
            @endif
        </div>
        @endisland

        {{-- A casual cup names the other region's cup of its game (EU and US, user 2026-09-28). --}}
        @if ($tournament->isCasualCup())
            <div data-test="cup-other-regions"><x-tournaments.cup-mentions :game="$tournament->game" :except="$tournament->id" /></div>
        @endif

        {{-- The pot's working part (P9): its state, "Fill the pot" (#pot-fill) and the payouts. --}}
        @if (! $week && ($tournament->pool_opened_at !== null || ($this->canManage && ! in_array($tournament->status, [TournamentStatus::Draft, TournamentStatus::Cancelled], true))))
            <livewire:tournament-pool :tournament="$tournament" :key="'pool-'.$tournament->id" />
        @endif

        {{-- The secondary slot: the tournament on Nostr, who of your follows plays, a tip for the winners. --}}
        @if ($published && ! $week)
            <x-nostr-bar :bar="\App\Support\Nostr\NostrBar::tournament($tournament)" />
        @endif
        @if ($published && $status === TournamentStatus::Signup)
            <div class="has-[>.hidden]:hidden"><livewire:follows-here context="tournament" :subject="(string) $tournament->id" :wire:key="'follows-here-t-'.$tournament->id" /></div>
        @endif
        @if ($champion && ! $tournament->isLeagueWeek())
            <div id="zap-winner" class="scroll-mt-24 has-[>.hidden]:hidden"><livewire:zap-winner type="tournament" :subject="(string) $tournament->id" :wire:key="'zap-tournament-'.$tournament->id" /></div>
        @elseif ($this->sharedFirst !== [])
            <p class="rv-card m-0 p-4 text-[13px]" data-test="tournament-shared-first"><b>{{ __('Shared 1st place: :names', ['names' => implode(', ', $this->sharedFirst)]) }}</b></p>
        @endif

        {{-- The rules of this tournament: the steps, the facts, the questions and the proof (rule: explanations live here, not in the views above). --}}
        @unless ($blockfillWeek)
            <section id="rules" aria-labelledby="how-h" class="grid scroll-mt-24 grid-cols-1 gap-8 lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)] lg:gap-12">
                <div class="flex flex-col gap-4">
                    <h2 id="how-h" class="rv-h3">{{ __('How it works') }}</h2>
                    <ol class="m-0 flex list-none flex-col gap-4 p-0" data-test="how-it-works">
                        @foreach ($steps as $index => [$title, $text])
                            <li class="flex gap-3.5">
                                <span class="flex size-8 shrink-0 items-center justify-center rounded-[4px] bg-btc-chip text-sm font-bold text-btc">{{ $index + 1 }}</span>
                                <span class="flex flex-col gap-1"><b class="text-[15px]">{{ $title }}</b><span class="text-[13px] leading-normal text-ink-2">{{ $text }}</span></span>
                            </li>
                        @endforeach
                    </ol>
                    @unless ($week)
                        {{-- Its own block under the steps: a hairline between two 16 px steps, 33 px in all, not the steps' 16 px rhythm (the date read as step 4). --}}
                        <div class="border-t border-hairline pt-4" data-test="when-block">
                            @include('pages.tournaments.partials.when', ['tournament' => $tournament, 'startsIn' => $landing->startsIn(), 'published' => $published])
                        </div>
                    @endunless
                    @if ($lobbies)
                        <div class="flex flex-col gap-2 rounded-card bg-card p-4 lg:p-5" data-test="lobby-rules">
                            <h3 class="m-0 text-[15px] font-bold">{{ __('How the lobbies are played') }}</h3>
                            <ul role="list" class="m-0 flex list-disc flex-col gap-1 pl-4 text-[13px] leading-normal break-words text-ink-2">
                                @foreach (\App\Support\Tournaments\Lobbies::rules($tournament->game) as $rule)
                                    <li>{{ $rule }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </div>

                {{-- Two fact columns only where the column holds them (40rem): beside the chat rail at 1280 a half card kept 141 px and "nein: vor Block 0 veröffentlicht …" ran 78 px high (V5). --}}
                <div class="@container flex flex-col gap-4">
                    <h2 class="rv-h3">{{ __('The facts') }}</h2>
                    <dl class="m-0 grid gap-2 @min-[40rem]:grid-cols-2" data-test="facts">
                        @foreach ($chips as [$icon, $term, $value, $key])
                            {{-- The pairing rules are a sentence, not a value: they take the full width (design-lead, 141 px cards at 1024–1600). --}}
                            <div @class(['flex gap-3 rounded-[4px] bg-card px-3.5 py-3', '@min-[40rem]:col-span-2' => str_starts_with($key, 'pairings')]) data-fact="{{ $key }}">
                                <x-icon :name="$icon" :size="18" class="mt-0.5 shrink-0 text-btc" />
                                <span class="flex min-w-0 flex-col gap-0.5">
                                    <dt class="text-xs text-ink-3">{{ $term }}</dt>
                                    <dd class="m-0 max-w-[68ch] text-[13px] leading-normal">{{ $value }}</dd>
                                </span>
                            </div>
                        @endforeach
                    </dl>
                    @if (($modeSwitched = app(\App\Support\Tournaments\TournamentModeSwitch::class)->lastSwitch($tournament)) !== null)
                        {{-- The time control was switched after publishing (Blockli, user 2026-10-11): one line, what and when. --}}
                        <p class="m-0 rounded-[4px] border border-btc-ring bg-feature px-3.5 py-2.5 text-[13px] leading-normal text-ink" data-test="mode-switched">{{ __('Switched from :from to :to on :date. Games already under way finish as they started.', [
                            'from' => \App\Support\GameNames::mode($tournament->game, $modeSwitched['from']), 'to' => \App\Support\GameNames::mode($tournament->game, $modeSwitched['to']),
                            'date' => $at($modeSwitched['at']),
                        ]) }}</p>
                    @endif
                    @if ($tournament->isDirectorMode())
                        <p class="m-0 text-xs leading-normal text-ink-2" data-test="director-notice">{{ __('Results are entered by the tournament directors.') }}</p>
                    @endif
                    @if (filled($tournament->description))
                        <p class="m-0 max-w-[65ch] text-[13px] leading-relaxed whitespace-pre-line text-ink-2 [overflow-wrap:anywhere]" data-test="tournament-description">{{ $tournament->description }}</p>
                    @endif
                    @if ($published && $status !== TournamentStatus::Cancelled && ! $week)
                        @include('pages.tournaments.partials.share', ['tournament' => $tournament, 'text' => $shareText, 'label' => $cta === 'open' || $cta === 'entered' ? __('Bring your friends') : __('Share this tournament')])
                    @endif
                </div>
            </section>
        @endunless

        {{-- Questions, and the proof for the nerds --}}
        @if (! $blockfillWeek || $proof !== [])
            <section @unless ($blockfillWeek) aria-labelledby="faq-h" @endunless class="flex flex-col gap-3" data-test="{{ $blockfillWeek ? 'week-proof' : 'faq' }}">
                @unless ($blockfillWeek)
                    <h2 id="faq-h" class="rv-h3">{{ __('Questions') }}</h2>
                    <div class="grid gap-2 lg:grid-cols-2 lg:items-start">
                        @foreach ([
                            [$lobbies ? __('How is the lobby match played?') : __('How does :format work?', ['format' => $formatLabel]), __($formatCopy['how']).' '.__('Good for:').' '.__($formatCopy['good'])],
                            ...($week ? [] : [[__('What if someone does not show up?'), $noShow]]),
                            $score ? [__('How is the winner found?'), $scoreMetric?->lowerIsBetter()
                                ? __('By the fastest time on the course inside the window. A tie goes to whoever set it first.')
                                : __('By the highest score on the course inside the window. A tie goes to whoever set it first.')]
                            : [__('How are seeds set?'), \Illuminate\Support\Str::ucfirst($teams
                                ? __('by Elo at sign-up close, equal Elo by earlier sign-up; mix teams after the lineups, in draw order')
                                : __('by Elo at sign-up close, equal Elo by earlier sign-up')).'.'],
                            ...($this->pool !== null ? [[__('How is the prize pool paid out?'), __('When the tournament has ended, an admin checks it and closes the pool. The pool is split by place as shown; tied places share their percentages and a team’s share is split equally among its roster. Each player’s share goes to the Lightning address in their Nostr profile, and the league publishes every payment on Nostr with its proof.')]] : []),
                        ] as [$question, $answer])
                            <details class="group rounded-[4px] bg-card">
                                <summary class="flex min-h-12 cursor-pointer items-center gap-3 px-4 text-[13px] font-bold">
                                    <span class="grow">{{ $question }}</span>
                                    <x-icon name="chevron-down" :size="16" class="shrink-0 transition-transform group-open:rotate-180" />
                                </summary>
                                <p class="m-0 px-4 pb-4 text-[13px] leading-relaxed text-ink-2">{{ $answer }}</p>
                            </details>
                        @endforeach
                    </div>
                @endunless

                @if ($proof !== [])
                    <details class="group rounded-[4px] bg-card" data-test="nerds">
                        <summary class="flex min-h-12 cursor-pointer items-center gap-3 px-4 text-[13px] font-bold">
                            <x-icon name="shield-check" :size="16" class="shrink-0 text-proof" />
                            <span class="grow">{{ __('For the nerds: the proof on Nostr') }}</span>
                            <x-icon name="chevron-down" :size="16" class="shrink-0 transition-transform group-open:rotate-180" />
                        </summary>
                        <div class="flex flex-col gap-3 px-4 pb-4">
                            <p class="m-0 text-xs leading-normal text-ink-2">{{ __('The league signs the tournament as a calendar event and, when it has a solo pool, the draw. Anyone can re-check them with these ids.') }}</p>
                            <x-proof :rows="$proof" open />
                        </div>
                    </details>
                @endif
            </section>
        @endif

        {{-- P48: comments and likes on the tournament's calendar event (NIP-22, NIP-25), read from the league relays --}}
        @if ($published)
            <livewire:nostr-comments type="tournament" :target="(string) $tournament->id" :key="'comments-'.$tournament->id" />
        @endif
    </div>
    </div>
</div>
