<?php

use App\Enums\BoardGameStatus;
use App\Events\LookingToPlayChanged;
use App\Games\GameRegistry;
use App\Models\BoardGame;
use App\Models\BoardInvite;
use App\Models\BoardQueueEntry;
use App\Models\Rating;
use App\Models\User;
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
use Livewire\Component;

/*
 * The lobby of one board game next to chess (plan "Mühle und Dame", P5):
 * nine men's morris or checkers, blitz 5+3, casual.
 *
 * Built after the chess lobby, not on it: "Find opponent" joins the board
 * game's own queue (BoardQueue), "Looking to play" lets others invite this
 * player (BoardInvites), the players looking right now can be invited, and
 * invites received are answered here. The live games of this board game,
 * its casual ladder's top five and its cups sit under the play card.
 *
 * While searching or waiting for an answer the page asks the server every
 * few seconds (wire:poll): a widening range may pair, an invite may have
 * been accepted. A pairing also arrives by push (`board.game-started` on
 * the player's own channel), which moves the page to the board at once.
 *
 * Rated (P6): while the rated queue of the board games is offered
 * (RatedBoard::offered()) a second button searches a rated game; it is
 * disabled with the reason while rated play is closed for this player
 * (RatedBoard::refusal: season live, trust ranks computed, a Trusted
 * account) or they list each other with nobody. While searching rated, the
 * P57 notice says in counts only how many others search rated and whether
 * any of them list each other with this player, with the fix next to it.
 * Off, the page is as before: casual only.
 *
 * The route exists only while `esports.board_games.enabled` is on
 * (routes/board.php); a board game whose own switch is off is a 404.
 */
new #[Layout('layouts::app', ['realtime' => true])] class extends Component {
    #[Locked]
    public string $slug;

    public string $error = '';

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
            return Ladders::isOpen($this->slug, 'blitz') ? __('Log in to play rated games.') : Seasons::restMessage();
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
     * state, never a flip (Livewire squashes identical queued calls).
     */
    public function setLookingToPlay(bool $looking): void
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            $this->redirectRoute('login');

            return;
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

        unset($this->looking);
    }

    #[Computed]
    public function looking(): bool
    {
        return auth()->user()?->looking_to_play === $this->slug.'/blitz';
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

    /**
     * The players who look for a game of this board game right now, newest
     * switch first, at most twelve.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function lookingPlayers(): Collection
    {
        return User::query()->where('looking_to_play', $this->slug.'/blitz')
            ->when(auth()->id() !== null, fn ($query) => $query->whereKeyNot(auth()->id()))
            ->latest('updated_at')->limit(12)->get();
    }

    /**
     * The live games of this board game, newest first, at most eight.
     *
     * @return Collection<int, BoardGame>
     */
    #[Computed]
    public function liveGames(): Collection
    {
        return BoardGame::query()->where('game', $this->slug)->where('status', BoardGameStatus::Active)
            ->with(['white', 'black'])->latest('id')->limit(8)->get();
    }

    /**
     * The casual ladder's top five (board games have no rated ladder before P6).
     *
     * @return Collection<int, Rating>
     */
    #[Computed]
    public function ladderTop(): Collection
    {
        return Rating::query()->where(['pool' => Rating::CASUAL, 'season' => '', 'game' => $this->slug, 'mode' => 'blitz'])
            ->where('results', '>', 0)->whereNotNull('user_id')->with('user')
            ->orderByDesc('rating')->orderByDesc('results')->orderBy('id')->limit(5)->get();
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

        unset($this->entry, $this->outgoing, $this->incoming, $this->activeGame, $this->waiting);
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
    $rating = $user ? Ratings::forUser($user->id, $slug, 'blitz', Rating::CASUAL) : null;
@endphp

<div class="flex grow flex-col" @if ($this->waiting) wire:poll.4s="poll" @endif
     @auth x-data x-init="window.Echo?.private('App.Models.User.{{ $user->id }}').listen('.board.game-started', (e) => window.location.assign(e.url)).listen('.board.invite', () => $wire.$refresh())" @endauth
     data-test="board-lobby">
    <div class="flex flex-col gap-6 px-4 pb-8 lg:gap-8 lg:px-12 lg:pb-10">
        {{-- Title: the game, its cover, the player's casual rating and the rules. --}}
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:gap-6">
            <x-game-cover :game="$slug" size="card" class="w-full rounded-md sm:w-[240px]" loading="eager" />
            <div class="flex min-w-0 flex-col gap-2">
                <h1 class="m-0 font-display text-2xl leading-tight font-bold lg:text-[28px]">{{ $name }}</h1>
                <p class="m-0 max-w-[60ch] text-[13px] leading-normal text-ink-2">{{ __('Blitz 5+3, live on this site. The server checks every move; a win moves your casual rating of :game.', ['game' => $name]) }}</p>
                <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-[13px]">
                    @if ($rating)
                        <x-rating :rating="$rating" class="text-ink-2" data-test="lobby-rating" />
                    @endif
                    <a href="{{ route('rules') }}#{{ $slug }}" class="text-ink underline decoration-edge underline-offset-4 hover:decoration-btc" data-test="lobby-rules">{{ __('Rules of :game', ['game' => $name]) }}</a>
                    <a href="{{ route('ladder.show', [$slug, 'blitz']) }}" class="text-ink underline decoration-edge underline-offset-4 hover:decoration-btc" data-test="lobby-ladder">{{ __('Ladder') }}</a>
                </div>
            </div>
        </div>

        @if ($error)
            <p role="alert" class="m-0 rounded-lg bg-loss-tint px-4 py-3 text-[13px] text-loss" data-test="lobby-error">{{ $error }}</p>
        @endif

        {{-- Invites received: they expire in minutes, so they come first. --}}
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

        {{-- Play: the one thing to do first. --}}
        <section aria-labelledby="board-play-h" class="flex flex-col gap-4 rounded-lg bg-card px-4 py-5 lg:px-6" data-test="board-play">
            <h2 id="board-play-h" class="m-0 text-[15px] font-bold">{{ __('Play :game', ['game' => $name]) }}</h2>

            @if (! $user)
                <p class="m-0 text-[13px] text-ink-2">{{ __('Log in to find an opponent or invite a player.') }}</p>
                <div><x-button :href="route('login')" data-test="lobby-login">{{ __('Log in to play') }}</x-button></div>
            @elseif ($active)
                <p class="m-0 text-[13px] text-ink-2">{{ __('You are in a live game.') }}</p>
                <div><x-button :href="route('board.show', $active)" icon="play" data-test="lobby-active">{{ __('Back to your game') }}</x-button></div>
            @elseif ($entry)
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between" data-test="lobby-searching" data-rated="{{ $entry->rated ? 'true' : 'false' }}">
                    <span class="flex items-center gap-3 text-[13px]">
                        <span class="size-2 animate-live rounded-full bg-btc-hi"></span>
                        {{ $entry->rated ? __('Finding a rated opponent for :game …', ['game' => $name]) : __('Finding an opponent for :game …', ['game' => $name]) }}
                    </span>
                    <x-button variant="secondary" wire:click="cancelSearch" data-test="cancel-search">{{ __('Cancel') }}</x-button>
                </div>
                {{-- P57: the rated queue skips players who do not list each other; say so in counts (nobody is named: presence), and offer the fix. --}}
                @php($ratedQueue = $this->ratedQueue)
                @if ($entry->rated && $ratedQueue['others'] > 0 && $ratedQueue['mutual'] === 0)
                    <x-opponents.needs-mutual class="text-left"
                        :heading="trans_choice(':count other player searches rated right now, but you do not list each other, so the queue cannot pair you.|:count other players search rated right now, but you list each other with none of them, so the queue cannot pair you.', $ratedQueue['others'])"
                        :note="$ratedQueue['asking'] > 0
                            ? trans_choice(':count player in the queue lists you. Accept the request on your Opponents page and the queue can pair you.|:count players in the queue list you. Accept their requests on your Opponents page and the queue can pair you.', $ratedQueue['asking'])
                            : __('A rated game needs both of you to add the other as an opponent. Casual pairs you with anyone.')">
                        @if ($ratedQueue['asking'] > 0)
                            <x-button :href="route('settings.opponents').'#requests'" data-test="needs-mutual-requests">{{ __('Open your requests') }}</x-button>
                        @endif
                        <x-button variant="quiet" wire:click="searchCasualInstead" data-test="needs-mutual-casual">{{ __('Search casual instead') }}</x-button>
                    </x-opponents.needs-mutual>
                @endif
            @elseif ($outgoing)
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between" data-test="lobby-invited">
                    <span class="text-[13px]">{{ __('Invite sent. Waiting for :name to accept.', ['name' => $outgoing->invitee->displayName()]) }}</span>
                    <x-button variant="secondary" wire:click="withdrawInvite" data-test="withdraw-invite">{{ __('Withdraw') }}</x-button>
                </div>
            @else
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                    <x-button icon="bolt" wire:click="findOpponent" data-test="find-opponent">{{ __('Find opponent') }}</x-button>
                    <span class="text-xs text-ink-2">{{ __('Blitz 5+3 · Casual · paired by rating') }}</span>
                </div>
                {{-- Rated (P6): only while the rated queue is offered; disabled with the reason while it is closed for this player. --}}
                @if ($this->ratedOffered)
                    @php($ratedRefusal = $this->ratedRefusal)
                    <div class="flex flex-col gap-2 border-t border-line pt-3 sm:flex-row sm:items-center sm:gap-3" data-test="rated-search" data-rated-open="{{ $ratedRefusal === null ? 'true' : 'false' }}">
                        <x-button variant="secondary" icon="shield-check" wire:click="findOpponent(true)" :disabled="$ratedRefusal !== null" class="disabled:cursor-not-allowed disabled:opacity-50" data-test="find-rated-opponent">{{ __('Find rated opponent') }}</x-button>
                        <span class="max-w-[60ch] text-xs text-ink-2" data-test="rated-why">{{ $ratedRefusal ?? trans_choice('Rated pairs you only with a Trusted player you list each other with (you have :count). A win can mine a season block.|Rated pairs you only with Trusted players you list each other with (you have :count). A win can mine a season block.', $this->mutualOpponents) }}</span>
                    </div>
                @endif
            @endif

            @if ($user)
                <label class="flex min-h-11 cursor-pointer items-center gap-3 border-t border-line pt-3 text-[13px]" data-test="looking-to-play">
                    <input type="checkbox" class="size-4 accent-btc" @checked($this->looking) wire:change="setLookingToPlay($event.target.checked)">
                    <span>{{ __('Looking to play: others can invite me to :game', ['game' => $name]) }}</span>
                </label>
            @endif
        </section>

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-3 lg:items-start lg:gap-5">
            {{-- Who looks for a game right now: invite them. --}}
            <section aria-labelledby="board-looking-h" class="flex flex-col gap-3 rounded-lg bg-card px-4 py-5 lg:px-5" data-test="lobby-looking">
                <h2 id="board-looking-h" class="m-0 text-[15px] font-bold">{{ __('Looking to play') }}</h2>
                @forelse ($this->lookingPlayers as $player)
                    <div wire:key="looking-{{ $player->id }}" class="flex min-w-0 items-center gap-3">
                        <x-player-link :user="$player" class="flex min-w-0 grow items-center gap-3 text-ink hover:text-ink">
                            <x-avatar :user="$player" :size="32" class="rounded-md" />
                            <span class="truncate text-[13px]">{{ $player->displayName() }}</span>
                        </x-player-link>
                        @if ($user && ! $active && ! $outgoing)
                            <x-button variant="secondary" wire:click="invite({{ $player->id }})" data-test="invite-player">{{ __('Invite') }}</x-button>
                        @endif
                    </div>
                @empty
                    <p class="m-0 text-[13px] text-ink-2">{{ __('Nobody is looking right now. Find an opponent, or switch on "Looking to play".') }}</p>
                @endforelse
            </section>

            {{-- The live games of this board game. --}}
            <section aria-labelledby="board-live-h" class="flex flex-col gap-3 rounded-lg bg-card px-4 py-5 lg:px-5" data-test="lobby-live">
                <h2 id="board-live-h" class="m-0 text-[15px] font-bold">{{ __('Live now') }}</h2>
                @forelse ($this->liveGames as $game)
                    <a wire:key="live-{{ $game->id }}" href="{{ route('board.show', $game) }}" class="flex min-h-11 min-w-0 items-center justify-between gap-3 rounded-md bg-well px-3 text-[13px] text-ink hover:text-ink" data-test="live-game">
                        <span class="truncate">{{ ($game->white?->displayName() ?? __('Deleted player')).' – '.($game->black?->displayName() ?? __('Deleted player')) }}</span>
                        <x-icon name="eye" :size="16" class="text-ink-2" />
                    </a>
                @empty
                    <p class="m-0 text-[13px] text-ink-2">{{ __('No game is live right now.') }}</p>
                @endforelse
            </section>

            {{-- The casual ladder's top five. --}}
            <section aria-labelledby="board-ladder-h" class="flex flex-col gap-3 rounded-lg bg-card px-4 py-5 lg:px-5" data-test="lobby-top">
                <h2 id="board-ladder-h" class="m-0 text-[15px] font-bold">{{ __('Casual ladder') }}</h2>
                @forelse ($this->ladderTop as $row)
                    <div wire:key="top-{{ $row->id }}" class="flex min-w-0 items-center gap-3 text-[13px]">
                        <span class="w-5 shrink-0 text-ink-3 tabular-nums">{{ $loop->iteration }}</span>
                        <span class="min-w-0 grow truncate">{{ $row->user?->displayName() ?? __('Deleted player') }}</span>
                        <span class="shrink-0 font-bold tabular-nums">{{ $row->rating }}</span>
                    </div>
                @empty
                    <p class="m-0 text-[13px] text-ink-2">{{ __('No game rated yet. The first win puts you on top.') }}</p>
                @endforelse
                <a href="{{ route('ladder.show', [$slug, 'blitz']) }}" class="text-[13px] text-ink underline decoration-edge underline-offset-4 hover:decoration-btc">{{ __('Full ladder') }}</a>
            </section>
        </div>

        <x-tournaments.cup-mentions :game="$slug" />
    </div>
</div>
