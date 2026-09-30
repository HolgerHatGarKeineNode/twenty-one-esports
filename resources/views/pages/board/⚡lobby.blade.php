<?php

use App\Enums\BoardGameStatus;
use App\Enums\TournamentStatus;
use App\Events\LookingToPlayChanged;
use App\Games\GameRegistry;
use App\Models\BoardGame;
use App\Models\BoardInvite;
use App\Models\BoardQueueEntry;
use App\Models\Rating;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Board\BoardChallenges;
use App\Support\Board\BoardGameService;
use App\Support\Board\BoardInvites;
use App\Support\Board\BoardQueue;
use App\Support\Board\BoardRuleViolation;
use App\Support\Board\RatedBoard;
use App\Support\Chess\Broadcasts;
use App\Support\Chess\ChessGameService;
use App\Support\GameNames;
use App\Support\PageMeta;
use App\Support\Rating\Ratings;
use App\Support\SeasonChain\Opponents;
use App\Support\SeasonChain\Seasons;
use App\Support\Series\Ladders;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Renderless;
use Livewire\Component;

/*
 * The lobby of one board game next to chess (plan "Mühle und Dame", P5):
 * nine men's morris or checkers, blitz 5+3, casual.
 *
 * The board game's own services on the chess lobby's page: "Find opponent"
 * joins the board game's queue (BoardQueue), "Looking to play" lets others
 * invite this player (BoardInvites), invites received are answered at the
 * top of the page.
 *
 * While searching or waiting for an answer the page asks the server every
 * few seconds (wire:poll): a widening range may pair, an invite may have
 * been accepted. A pairing also arrives by push (`board.game-started` on
 * the player's own channel, resources/js/boardLobby.js), which moves the
 * page to the board at once.
 *
 * Rated (P6): the Blitz panel's Casual/Rated choice, as in chess. Rated is
 * disabled with a badge and the reason behind "?" while the rated queue of
 * the board games is off (RatedBoard::offered()) or rated play is closed for
 * this player (RatedBoard::refusal: season live, trust ranks computed, a
 * Trusted account) or they list each other with nobody. While searching
 * rated, the P57 notice says in counts only how many others search rated
 * and whether any of them list each other with this player, with the fix.
 *
 * Correspondence (P8): the Correspondence tile (in Daily chess's slot) leads
 * to the board game's correspondence page (board.correspondence) with what
 * waits there as its count; "Your games" lists those games and the
 * challenges to answer. "Live now" lists live games only.
 *
 * The route exists only while `esports.board_games.enabled` is on
 * (routes/board.php); a board game whose own switch is off is a 404.
 *
 * Arranged as the chess lobby (P5 of plan mempool-streifen; user,
 * 2026-09-29: "ich hätte genau die selbe Anordnung erwartet, um mich
 * schnell zurecht zu finden, ich sehe auch keine Online Leute"): the same
 * sections in the same order with the same parts (x-chess.lobby-tile,
 * x-lobby.online-now, the ladder card). Title, the ways to play as tiles
 * with the blitz panel under them, the next tournament and the casual cups,
 * then Your games | Live now with who is online | the ladder, and the
 * weekly events. What stays different, in chess's slots: Correspondence in
 * Daily chess's tile; no invite link and no team match tile (board games
 * have neither); the rules link in the title row. "Your follows here"
 * (plan brettspiel-chat-und-follows, P2) and the game chat (P1: the board
 * game's own NIP-28 channel, GameChannels, NIP rev. 9.15) sit in chess's
 * slots and order, under the lobby and above the weekly events: the
 * follows' challenge is a correspondence game, their invite the blitz
 * invite of "Online now".
 */
new #[Layout('layouts::app', ['realtime' => true, 'scripts' => ['resources/js/gameChannel.js']])] class extends Component {
    #[Locked]
    public string $slug;

    public string $error = '';

    /**
     * Whom this player's open invite goes to, and until when (ms): the online
     * list shows "Invited · Withdraw" on that row. Set on every render.
     */
    #[Locked]
    public ?int $invitedUserId = null;

    #[Locked]
    public int $invitedUntilMs = 0;

    /**
     * Whether this player may invite from the lobby now: not while in a live
     * game (the invite would only lead back to it). The blitz invite of
     * "Your follows here" reads it ($wire.$parent.canInvite); "Online now"
     * gets the same activeGame in the template (! $active), since a value set
     * here in rendering() reaches $wire, not the Blade render already under
     * way. Set on every render.
     */
    #[Locked]
    public bool $canInvite = true;

    public function mount(string $board): void
    {
        abort_unless(app(GameRegistry::class)->isBoard($board), 404);

        $this->slug = $board;
    }

    public function rendering(\Illuminate\View\View $view): void
    {
        $name = GameNames::game($this->slug);
        $view->title($name);
        app(PageMeta::class)->describe($name, __('Play :game blitz 5+3 live against Bitcoiners: find an opponent, invite a player and climb the casual ladder. The server checks every move.', ['game' => $name]));

        $outgoing = $this->outgoing;
        $this->invitedUserId = $outgoing?->invitee_id;
        $this->invitedUntilMs = $outgoing?->expires_at->getTimestampMs() ?? 0;
        $this->canInvite = $this->activeGame === null;
    }

    public function findOpponent(bool $rated = false): void
    {
        $this->attempt(function (User $user) use ($rated): void {
            $refusal = $rated ? $this->ratedRefusal : null;

            if ($refusal !== null) {
                throw new BoardRuleViolation('rated_not_open', $refusal);
            }

            $this->goTo(app(BoardQueue::class)->join($user, $this->slug, rated: $rated));
        });
    }

    /** Whether the page offers a rated search at all (the rated queue switch, P6). */
    #[Computed]
    public function ratedOffered(): bool
    {
        return RatedBoard::offered();
    }

    /**
     * Why this player cannot search a rated game of this board game now, or
     * null. The queue pairs two rated players only if they list each other,
     * so a player who lists nobody back would wait forever: refused here.
     */
    #[Computed]
    public function ratedRefusal(): ?string
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return match (true) {
                Ladders::isOpen($this->slug, 'blitz') => __('Log in to play rated games.'),
                // A season live since before the board games joined: their ladder opens with the board's next rule change.
                Seasons::live() !== null => __('Rated :game starts when the board adds it to the running season.', ['game' => GameNames::game($this->slug)]),
                default => Seasons::restMessage(),
            };
        }

        $refusal = app(RatedBoard::class)->refusal($user, $this->slug, 'blitz');

        if ($refusal !== null) {
            return $refusal;
        }

        return $this->mutualOpponents === 0
            ? __('Rated play needs a player you list each other with. Add opponents on their player pages; they add you back.')
            : null;
    }

    /** How many players this one lists each other with (the rated queue pairs only those). */
    #[Computed]
    public function mutualOpponents(): int
    {
        $user = auth()->user();

        return $user instanceof User ? count(app(Opponents::class)->mutual($user)) : 0;
    }

    /**
     * P57, as in the chess lobby: while searching rated, how many others
     * search this board game rated, how many of them list each other with
     * this player, and how many list this player without being on their
     * list. Counts only: who searches right now is live presence.
     *
     * @return array{others: int, mutual: int, asking: int}
     */
    #[Computed]
    public function ratedQueue(): array
    {
        $user = auth()->user();
        $entry = $this->entry;

        if (! $user instanceof User || $entry === null || ! $entry->rated) {
            return ['others' => 0, 'mutual' => 0, 'asking' => 0];
        }

        $opponents = app(Opponents::class);
        $others = BoardQueueEntry::query()->where('rated', true)->where('game', $entry->game)->where('mode', $entry->mode)->where('user_id', '!=', $user->id)
            ->join('users', 'users.id', '=', 'board_queue_entries.user_id')->pluck('users.pubkey');
        $mine = $opponents->entries($user);
        $listingMe = $opponents->listedBy($user);

        return [
            'others' => $others->count(),
            'mutual' => $others->filter(fn (string $other): bool => in_array($other, $mine, true) && in_array($other, $listingMe, true))->count(),
            'asking' => $others->filter(fn (string $other): bool => ! in_array($other, $mine, true) && in_array($other, $listingMe, true))->count(),
        ];
    }

    /** P57: leave the rated search and search casual, which pairs with anyone. */
    public function searchCasualInstead(): void
    {
        $this->attempt(fn (User $user) => app(BoardQueue::class)->leave($user));
        $this->findOpponent(false);
    }

    /** P57: an accept from the searching card; the next poll can pair the two. */
    #[On('opponent-list-changed')]
    public function opponentListChanged(): void
    {
        unset($this->ratedQueue, $this->mutualOpponents, $this->ratedRefusal);
    }

    public function cancelSearch(): void
    {
        $this->attempt(fn (User $user) => app(BoardQueue::class)->leave($user));
    }

    /**
     * Asked every few seconds while searching or waiting for an answer: the
     * widening range may pair now, or an invite may have been accepted.
     */
    public function poll(): void
    {
        $this->attempt(function (User $user): void {
            $queue = app(BoardQueue::class);
            $this->goTo($queue->entryOf($user) !== null ? $queue->pair($user) : app(BoardGameService::class)->activeGameOf($user));
        });
    }

    public function invite(int $userId): void
    {
        $this->attempt(fn (User $user) => $this->goTo(app(BoardInvites::class)->invite($user, User::query()->findOrFail($userId), $this->slug)->boardGame));
    }

    public function withdrawInvite(): void
    {
        $this->attempt(fn (User $user) => app(BoardInvites::class)->withdrawOutgoing($user));
    }

    public function acceptInvite(int $inviteId): void
    {
        $this->attempt(fn (User $user) => $this->goTo(app(BoardInvites::class)->accept(BoardInvite::query()->whereNull('tournament_match_id')->findOrFail($inviteId), $user)));
    }

    public function declineInvite(int $inviteId): void
    {
        $this->attempt(fn (User $user) => app(BoardInvites::class)->close(BoardInvite::query()->findOrFail($inviteId), $user));
    }

    /**
     * "Looking to play" for this board game: on, others may invite this
     * player (and any other game's switch goes off: one at a time); off,
     * the invites still open are declined. The page sends the wanted
     * state, never a flip (Livewire squashes identical queued calls), and
     * gets the stored state back; no render, the switch is the page's
     * (boardLobby in resources/js/boardLobby.js).
     */
    #[Renderless]
    public function setLookingToPlay(bool $looking): bool
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            $this->redirectRoute('login');

            return false;
        }

        $mine = $this->slug.'/blitz';
        $previous = $user->looking_to_play;
        $wanted = $looking ? $mine : ($previous === $mine ? null : $previous);

        if ($previous !== $wanted) {
            $user->forceFill(['looking_to_play' => $wanted])->save();
            Broadcasts::send(new LookingToPlayChanged($user->id, $user->looking_to_play));

            if (! $looking) {
                app(BoardInvites::class)->declineAll($user);
            }
        }

        return $user->looking_to_play === $mine;
    }

    #[Computed]
    public function entry(): ?BoardQueueEntry
    {
        $user = auth()->user();

        return $user instanceof User ? app(BoardQueue::class)->entryOf($user) : null;
    }

    #[Computed]
    public function activeGame(): ?BoardGame
    {
        $user = auth()->user();

        return $user instanceof User ? app(BoardGameService::class)->activeGameOf($user) : null;
    }

    #[Computed]
    public function outgoing(): ?BoardInvite
    {
        $user = auth()->user();
        $invite = $user instanceof User ? app(BoardInvites::class)->outgoing($user) : null;

        return $invite?->game === $this->slug && $invite->tournament_match_id === null ? $invite : null;
    }

    /**
     * @return Collection<int, BoardInvite>
     */
    #[Computed]
    public function incoming(): Collection
    {
        $user = auth()->user();

        return $user instanceof User
            ? app(BoardInvites::class)->incoming($user)->where('game', $this->slug)->whereNull('tournament_match_id')->values()
            : collect();
    }

    /** How many players search a game of this board game right now (the Blitz tile). */
    #[Computed]
    public function searching(): int
    {
        return BoardQueueEntry::query()->where('game', $this->slug)->count();
    }

    /**
     * The live games of this board game, newest first: three boards, as in
     * the chess lobby.
     *
     * @return Collection<int, BoardGame>
     */
    #[Computed]
    public function liveGames(): Collection
    {
        return BoardGame::query()->live()->where('game', $this->slug)->where('status', BoardGameStatus::Active)
            ->with(['white', 'black'])->latest('id')->limit(3)->get();
    }

    /** Every running live game of this board game, not only the three boards shown. */
    #[Computed]
    public function liveCount(): int
    {
        return BoardGame::query()->live()->where('game', $this->slug)->where('status', BoardGameStatus::Active)->count();
    }

    /**
     * Both players' ratings of each live board, from the ladder the game
     * counts on (rated or casual): one query per pool, however many boards.
     * A live game has no rating change yet, so Ratings::forBoardGame's
     * second query per board would find nothing.
     *
     * @return array<int, array{w: int, b: int}>
     */
    #[Computed]
    public function liveRatings(): array
    {
        $ratings = [];

        foreach ($this->liveGames->groupBy(fn (BoardGame $game): string => Ratings::pool($game->rated)) as $pool => $games) {
            $ids = $games->flatMap(fn (BoardGame $game): array => [$game->white_id, $game->black_id])->filter()->unique()->values()->all();
            $now = Ratings::forUsers($ids, $this->slug, 'blitz', (string) $pool);
            $none = Ratings::summary(null, (string) $pool)['rating'];

            foreach ($games as $game) {
                $ratings[$game->id] = ['w' => $now[$game->white_id]['rating'] ?? $none, 'b' => $now[$game->black_id]['rating'] ?? $none];
            }
        }

        return $ratings;
    }

    /**
     * The blitz ladder's top five, from the view the ladder page opens on:
     * rated once it has a result, casual before (as the chess lobby).
     *
     * @return array{pool: string, rows: Collection<int, Rating>}
     */
    #[Computed]
    public function ladderTop(): array
    {
        $season = Ratings::season(Rating::RATED, $this->slug, 'blitz');
        $top = fn (string $pool, string $season): Collection => Rating::query()
            ->where(['pool' => $pool, 'season' => $season, 'game' => $this->slug, 'mode' => 'blitz'])
            ->where('results', '>', 0)->whereNotNull('user_id')->with('user')
            ->orderByDesc('rating')->orderByDesc('results')->orderBy('id')->limit(5)->get();

        $rated = $season !== null ? $top(Rating::RATED, $season) : collect();

        return $rated->isNotEmpty() ? ['pool' => Rating::RATED, 'rows' => $rated] : ['pool' => Rating::CASUAL, 'rows' => $top(Rating::CASUAL, '')];
    }

    /**
     * The next tournament of this board game open for sign-up (no cup), as
     * on every game page: its tile and its poster.
     */
    #[Computed]
    public function nextTournament(): ?Tournament
    {
        return Tournament::query()->special()->where('status', TournamentStatus::Signup)->where('signup_closes_at', '>', now())
            ->where('game', $this->slug)->orderBy('signup_closes_at')->first();
    }

    /**
     * This player's correspondence games of this board game (P8), those
     * waiting for their move first, each group by its deadline.
     *
     * @return Collection<int, BoardGame>
     */
    #[Computed]
    public function correspondenceGames(): Collection
    {
        $user = auth()->user();

        if (! $user instanceof User || app(GameRegistry::class)->mode($this->slug, BoardGame::CORRESPONDENCE) === null) {
            return collect();
        }

        return BoardGame::query()->correspondence()->where('game', $this->slug)->where('status', BoardGameStatus::Active)->playedBy($user)
            ->with(['white', 'black'])->orderBy('deadline_ms')->get()
            ->sortBy(fn (BoardGame $game): int => $game->turn === $game->colorOf($user) ? 0 : 1)
            ->values();
    }

    /**
     * The opponents' correspondence ratings of the five games shown, one query.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function correspondenceRatings(): array
    {
        $user = auth()->user();
        $games = $this->correspondenceGames->take(5);

        if (! $user instanceof User || $games->isEmpty()) {
            return [];
        }

        $pool = Ratings::headline(null, $this->slug, BoardGame::CORRESPONDENCE)['pool'];
        $ids = $games->map(fn (BoardGame $game): ?int => $game->opponentOf($user)?->id)->filter()->unique()->values()->all();

        return array_map(fn (array $rating): int => $rating['rating'], Ratings::forUsers($ids, $this->slug, BoardGame::CORRESPONDENCE, $pool));
    }

    /**
     * What waits for this player in the correspondence games of this board
     * game (P8): challenges to answer, games whose move is theirs, games
     * running.
     *
     * @return array{challenges: int, yourMove: int, running: int}
     */
    #[Computed]
    public function correspondence(): array
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return ['challenges' => 0, 'yourMove' => 0, 'running' => 0];
        }

        $games = $this->correspondenceGames;

        return [
            'challenges' => app(BoardChallenges::class)->incoming($user, $this->slug)->count(),
            'yourMove' => $games->filter(fn (BoardGame $game): bool => $game->turn === $game->colorOf($user))->count(),
            'running' => $games->count(),
        ];
    }

    /** Whether the page asks the server on its own: searching, or waiting for an answer to an invite. */
    #[Computed]
    public function waiting(): bool
    {
        return $this->entry !== null || $this->outgoing !== null;
    }

    /**
     * @param  Closure(User): mixed  $action
     */
    private function attempt(Closure $action): void
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            $this->redirectRoute('login');

            return;
        }

        $this->error = '';

        try {
            $action($user);
        } catch (BoardRuleViolation $violation) {
            if ($violation->reason === 'already_playing' && $this->activeGame !== null) {
                $this->goTo($this->activeGame);

                return;
            }

            $this->error = match ($violation->reason) {
                'playing_elsewhere' => app(ChessGameService::class)->activeGameOf($user) !== null
                    ? __('You are in a live chess game. One live game at a time: finish it first.')
                    : __('Finish your casual 1v1 first.'),
                'invite_closed' => __('That invite is no longer open.'),
                'opponent_playing' => __('That player is already in another live game, so the invite is closed.'),
                'invite_self' => __('You cannot invite yourself.'),
                'not_looking', 'rated_not_open' => $violation->getMessage(),
                default => __('That did not work, please try again.'),
            };
        }

        unset($this->entry, $this->outgoing, $this->incoming, $this->activeGame, $this->waiting, $this->searching);
    }

    private function goTo(?BoardGame $game): void
    {
        if ($game !== null) {
            $this->redirectRoute('board.show', ['boardGame' => $game]);
        }
    }
}; ?>

@php
    $user = auth()->user();
    $name = GameNames::game($slug);
    $entry = $this->entry;
    $outgoing = $this->outgoing;
    $active = $this->activeGame;
@endphp

<div class="flex grow flex-col" @if ($this->waiting) wire:poll.4s="poll" @endif
     x-data="boardLobby(@js(['userId' => $user?->id, 'lookingKey' => $slug.'/blitz', 'looking' => $user?->looking_to_play === $slug.'/blitz']))"
     data-test="board-lobby" data-game="{{ $slug }}">
    <div class="flex flex-col gap-6 px-4 pb-8 lg:gap-8 lg:px-12 lg:pb-10">
        {{-- The title below lg, with the rating and the rules; from lg the header's context bar names the page and links the rules. --}}
        <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1 lg:hidden" data-test="lobby-title">
            <h1 class="m-0 font-display text-2xl leading-tight font-bold">{{ $name }}</h1>
            <span class="flex items-baseline gap-3 text-[13px]">
                @auth<x-rating :rating="Ratings::headline($user->id, $slug, 'blitz')" :label="__('Blitz')" class="text-ink-2" data-test="lobby-rating" />@endauth
                <a href="{{ route('rules') }}#{{ $slug }}" class="inline-flex min-h-11 items-center text-ink underline decoration-edge underline-offset-4 hover:decoration-btc" data-test="lobby-rules">{{ __('Rules') }}</a>
            </span>
        </div>
        <h1 class="sr-only max-lg:hidden">{{ $name }}</h1>

        @if ($error || $this->incoming->isNotEmpty())
            <div class="flex flex-col gap-2 empty:hidden">
                @if ($error)
                    <p role="alert" class="m-0 rounded-lg bg-loss-tint px-4 py-3 text-[13px] text-loss" data-test="lobby-error">{{ $error }}</p>
                @endif

                {{-- Invites received: they expire in minutes, so they come before everything else. --}}
                @foreach ($this->incoming as $invite)
                    <div wire:key="invite-{{ $invite->id }}" class="flex flex-col gap-3 rounded-lg bg-card p-3 shadow-ring-btc lg:flex-row lg:items-center lg:px-4" data-test="incoming-invite">
                        <span class="flex min-w-0 grow items-center gap-3">
                            <x-player-link :user="$invite->inviter" class="shrink-0"><x-avatar :user="$invite->inviter" :size="40" class="rounded-md" /></x-player-link>
                            <span class="flex min-w-0 flex-col gap-0.5">
                                <b class="truncate text-[15px]">{{ __(':name invites you', ['name' => $invite->inviter->displayName()]) }}</b>
                                <span class="text-xs text-ink-2">{{ __('Blitz 5+3 · Casual · colours drawn at random') }}</span>
                            </span>
                        </span>
                        <span class="grid grid-cols-2 gap-2 lg:flex">
                            <x-button variant="quiet" wire:click="declineInvite({{ $invite->id }})" data-test="decline-invite">{{ __('Decline') }}</x-button>
                            <x-button icon="shield-check" wire:click="acceptInvite({{ $invite->id }})" data-test="accept-invite">{{ __('Accept') }}</x-button>
                        </span>
                    </div>
                @endforeach
            </div>
        @endif

        @include('pages.board.partials.lobby-play', ['user' => $user, 'entry' => $entry, 'outgoing' => $outgoing, 'name' => $name])

        {{-- The next tournament of this board game open for sign-up, then its casual cups, as on every game page. --}}
        @if ($this->nextTournament)
            <x-tournaments.poster :tournament="$this->nextTournament" heading-id="lobby-next-h" />
        @else
            <x-tournaments.next-empty :game="$slug" heading-id="lobby-next-h" />
        @endif
        <x-tournaments.cup-mentions :game="$slug" titled />

        {{-- The player's own business and the live lobby. Below lg in reading order: your games, live, ladder. --}}
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-12 lg:items-start lg:gap-5">
            @include('pages.board.partials.lobby-games', ['user' => $user, 'active' => $active, 'name' => $name])
            @include('pages.board.partials.lobby-live', ['user' => $user, 'active' => $active, 'name' => $name])
            @include('pages.chess.partials.lobby-ladder', ['ladderGame' => $slug])
        </div>

        {{-- Plan brettspiel-chat-und-follows, P2: who of the player's Nostr follows plays here, to invite or challenge, as in chess --}}
        <livewire:follows-here context="board" :subject="$slug" :wire:key="'follows-here-'.$slug" />
        {{-- The global chat of this board game (P1 of the same plan): as chess's, under the follows, above the weekly events. --}}
        <livewire:game-channel :game="$slug" />

        {{-- Weekly events (P10): the next dates of the recurring slots, all games. --}}
        <x-weekly-events :events="app(App\Support\Engagement\WeeklySlots::class)->upcoming(4)" heading-id="lobby-weekly-h" class="rounded-lg bg-card px-4 py-5 lg:px-6" />
    </div>
</div>
