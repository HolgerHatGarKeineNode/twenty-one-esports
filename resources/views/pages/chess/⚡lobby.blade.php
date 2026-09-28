<?php

use App\Enums\ChessGameStatus;
use App\Enums\TournamentStatus;
use App\Events\LookingToPlayChanged;
use App\Models\ChessChallenge;
use App\Models\ChessGame;
use App\Models\ChessInvite;
use App\Models\ChessQueueEntry;
use App\Models\Rating;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Chess\Broadcasts;
use App\Support\Chess\ChessGameService;
use App\Support\Chess\ChessInvites;
use App\Support\Chess\ChessQueue;
use App\Support\Chess\ChessRuleViolation;
use App\Support\Chess\DailyChallenges;
use App\Support\Chess\RatedChess;
use App\Support\PageMeta;
use App\Support\Rating\Ratings;
use App\Support\SeasonChain\Opponents;
use App\Support\SeasonChain\Seasons;
use App\Support\Series\CasualInvites;
use App\Support\Series\Ladders;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;
use Livewire\Component;

/*
 * Chess lobby, from ChessLobby.dc.html and MobileChessLobby.dc.html, with the
 * queue states of ChessStates ("Finding opponent", "Waiting for a friend").
 *
 * Real in P5a: the blitz queue (casual only), invites to a friend who is
 * online, the online list with "Looking to play" (presence channel `online`,
 * a panel the designs do not draw yet), and the live games. P5b: "Your
 * daily games" and the "Daily challenge" button; since P5b every logged-in
 * page is on `online`, so the list shows everyone online. Solo Elo, Clan
 * Hashrate and team matches belong to P7 and keep their places as "coming"
 * cards.
 *
 * P5c: joining the queue asks once whether to allow desktop notifications,
 * and a pairing plays the match-found sound before the page moves to the
 * board, so waiting in a background tab works.
 *
 * P5e: inviting a player who searches starts the game at once, and "Find
 * opponent" with an open invite pairs with its inviter (ChessInvites). The
 * "Looking to play" switch is Alpine's: it flips on the click and saves the
 * wanted state (setLookingToPlay), because a flip action lost clicks (see
 * there). The online list marks the player this one has invited.
 *
 * P7e: the Casual/Rated choice. Rated is selectable only while rated chess
 * is open for this player (RatedChess::refusal: season live, rated chess
 * offered, trust ranks computed, a Trusted account) and they list each
 * other with at least one player; otherwise the page says why. The choice
 * is the page's (Alpine `rated`) and travels with "Find opponent".
 *
 * Lobby v2 (2026-09-27): the first viewport shows every way to play as a
 * row of tiles (partials/lobby-play), after the Lichess quick-pairing pools
 * and Chess.com's mode tiles: Blitz 5+3 and the invite link open their
 * panel in place under the tiles, Daily chess and Tournaments lead into
 * their flow, "Challenge a player" goes to the online list, Team match is
 * "soon". Under the tiles the player's own business and the live lobby:
 * Your games | Live now (boards, who is online, "Looking to play") | the
 * blitz ladder's top five. Explanations sit behind the panel's "?"
 * (progressive disclosure), never in the first viewport.
 */
new #[Layout('layouts::app', ['section' => 'chess', 'realtime' => true, 'scripts' => ['resources/js/chess.js', 'resources/js/gameChannel.js']])] class extends Component
{
    public string $error = '';

    /**
     * Why a rated "Find next opponent" searches casual instead (P7e): set
     * once, when the page opens with `?search=1&rated=1` and rated is closed
     * for this player by now.
     */
    public string $notice = '';

    /**
     * Whom this player's open invite goes to, and until when (ms): the online
     * list shows "Invited · Withdraw" on that row. Set on every render.
     */
    #[Locked]
    public ?int $invitedUserId = null;

    #[Locked]
    public int $invitedUntilMs = 0;

    /**
     * "Find next opponent" / "Search again" land here with `?search=1`, and
     * after a rated game with `&rated=1` (P7e): rated again if rated is still
     * open for this player, else casual with the reason shown.
     */
    public function mount(): void
    {
        if (! request()->boolean('search') || ! auth()->check()) {
            return;
        }

        $rated = request()->boolean('rated');

        if ($rated && $this->ratedRefusal !== null) {
            $this->notice = __('Rated is closed for you right now, so this search is casual. :reason', ['reason' => $this->ratedRefusal]);
            $rated = false;
        }

        $this->findOpponent($rated);
    }

    public function findOpponent(bool $rated = false): void
    {
        $this->attempt(function (User $user) use ($rated): void {
            $refusal = $rated ? $this->ratedRefusal : null;

            if ($refusal !== null) {
                throw new ChessRuleViolation('rated_not_open', $refusal);
            }

            $this->goTo(app(ChessQueue::class)->join($user, 'blitz', rated: $rated));
        });
    }

    /**
     * Why this player cannot search a rated blitz game now, or null. The
     * queue itself pairs two rated players only if they list each other, so
     * a player who lists nobody back would wait forever: refused here.
     */
    #[Computed]
    public function ratedRefusal(): ?string
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return Ladders::isOpen('chess', 'blitz') ? __('Log in to play rated games.') : Seasons::restMessage();
        }

        $refusal = app(RatedChess::class)->refusal($user, 'blitz');

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

    public function cancelSearch(): void
    {
        $this->attempt(fn (User $user) => app(ChessQueue::class)->leave($user));
    }

    /**
     * Asked every few seconds while searching or waiting for a friend: the
     * widening range may pair now, or a friend may have accepted.
     */
    public function pollQueue(): void
    {
        $this->attempt(function (User $user): void {
            $queue = app(ChessQueue::class);
            $this->goTo($queue->entryOf($user) !== null ? $queue->pair($user) : app(ChessGameService::class)->activeGameOf($user));
        });
    }

    public function invite(int $userId): void
    {
        $this->attempt(fn (User $user) => $this->goTo(app(ChessInvites::class)->invite($user, User::query()->findOrFail($userId))->game));
    }

    public function withdrawInvite(): void
    {
        $this->attempt(function (User $user): void {
            $invite = app(ChessInvites::class)->outgoing($user);

            if ($invite !== null) {
                app(ChessInvites::class)->close($invite, $user);
            }
        });
    }

    public function acceptInvite(int $inviteId): void
    {
        $this->attempt(fn (User $user) => $this->goTo(app(ChessInvites::class)->accept(ChessInvite::query()->findOrFail($inviteId), $user)));
    }

    public function declineInvite(int $inviteId): void
    {
        $this->attempt(fn (User $user) => app(ChessInvites::class)->close(ChessInvite::query()->findOrFail($inviteId), $user));
    }

    /**
     * Stores the state the switch shows and answers with the stored state.
     * The page sends the wanted state, never "flip": Livewire squashes
     * identical calls queued behind a running request into one, so a flip
     * action lost every other quick click and the switch ended on the wrong
     * side (reproduced in tests/Browser/BlitzGameTest.php). No render: the
     * switch is the page's, and nothing else here depends on it.
     */
    #[Renderless]
    public function setLookingToPlay(bool $looking): bool
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            $this->redirectRoute('login');

            return false;
        }

        // The switch here is blitz only: off leaves a casual 1v1 choice (P23, `<game>/1v1`) alone, on replaces it.
        $previous = $user->looking_to_play;
        $wanted = $looking ? 'chess/blitz' : ($previous === 'chess/blitz' ? null : $previous);

        if ($previous !== $wanted) {
            $user->forceFill(['looking_to_play' => $wanted])->save();
            Broadcasts::send(new LookingToPlayChanged($user->id, $user->looking_to_play));

            // Off means no blitz invites: the ones still open are declined, never delivered later.
            if ($wanted === null) {
                app(ChessInvites::class)->declineAll($user);
            } else {
                app(CasualInvites::class)->declineAll($user);
            }
        }

        return $user->looking_to_play === 'chess/blitz';
    }

    public function rendering(\Illuminate\View\View $view): void
    {
        $view->title(__('Chess'));
        app(PageMeta::class)->describe(__('Chess'), __('Play blitz chess 5+3 live or daily chess against Bitcoiners: find an opponent, watch the live boards and follow your daily games.'));
        app(\App\Support\PageMeta::class)->card(fn () => \App\Support\Cards\PageCard::page('chess'));

        $outgoing = $this->outgoing;
        $this->invitedUserId = $outgoing?->invitee_id;
        $this->invitedUntilMs = $outgoing?->expires_at->getTimestampMs() ?? 0;
    }

    #[Computed]
    public function entry(): ?ChessQueueEntry
    {
        $user = auth()->user();

        return $user instanceof User ? app(ChessQueue::class)->entryOf($user) : null;
    }

    #[Computed]
    public function activeGame(): ?ChessGame
    {
        $user = auth()->user();

        return $user instanceof User ? app(ChessGameService::class)->activeGameOf($user) : null;
    }

    #[Computed]
    public function outgoing(): ?ChessInvite
    {
        $user = auth()->user();

        return $user instanceof User ? app(ChessInvites::class)->outgoing($user) : null;
    }

    /**
     * @return Collection<int, ChessInvite>
     */
    #[Computed]
    public function incoming(): Collection
    {
        $user = auth()->user();

        return $user instanceof User ? app(ChessInvites::class)->incoming($user) : collect();
    }

    /**
     * @return Collection<int, ChessGame>
     */
    #[Computed]
    public function liveGames(): Collection
    {
        return ChessGame::query()->live()->where('status', ChessGameStatus::Active)->with(['white', 'black'])->latest('id')->limit(3)->get();
    }

    /** Every running live game, not only the three boards shown. */
    #[Computed]
    public function liveCount(): int
    {
        return ChessGame::query()->live()->where('status', ChessGameStatus::Active)->count();
    }

    /**
     * Both players' ratings of each live board, from the game's own ladder
     * (the old boards printed the queue's start rating for everyone).
     *
     * @return array<int, array{w: array<string, mixed>, b: array<string, mixed>}>
     */
    #[Computed]
    public function liveRatings(): array
    {
        return $this->liveGames->mapWithKeys(fn (ChessGame $game): array => [$game->id => Ratings::forChessGame($game)])->all();
    }

    /**
     * @return Collection<int, ChessGame>
     */
    #[Computed]
    public function dailyGames(): Collection
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return collect();
        }

        // The games waiting for this player's move first, each group by its deadline.
        return ChessGame::query()->daily()->playedBy($user)->where('status', ChessGameStatus::Active)->with(['white', 'black'])->orderBy('deadline_ms')->get()
            ->sortBy(fn (ChessGame $game): int => $game->turn() === $game->colorOf($user) ? 0 : 1)
            ->values();
    }

    /** How many daily games wait for this player's move (the Daily chess tile). */
    #[Computed]
    public function yourMove(): int
    {
        $user = auth()->user();

        return $user instanceof User ? $this->dailyGames->filter(fn (ChessGame $game): bool => $game->turn() === $game->colorOf($user))->count() : 0;
    }

    /**
     * The next chess tournament open for sign-up, the same pick as
     * <x-next-tournament game="chess">: its tile shows name and closing time.
     */
    #[Computed]
    public function nextTournament(): ?Tournament
    {
        return Tournament::query()->special()->where('status', TournamentStatus::Signup)->where('signup_closes_at', '>', now())
            ->where('game', 'chess')->orderBy('signup_closes_at')->first();
    }

    /**
     * The blitz ladder's top five, from the view the ladder page opens on:
     * rated once it has a result, casual before (ladder/⚡show activePool()).
     *
     * @return array{pool: string, rows: Collection<int, Rating>}
     */
    #[Computed]
    public function ladderTop(): array
    {
        $season = Ratings::season(Rating::RATED, 'chess', 'blitz');
        $top = fn (string $pool, string $season): Collection => Rating::query()
            ->where(['pool' => $pool, 'season' => $season, 'game' => 'chess', 'mode' => 'blitz'])
            ->where('results', '>', 0)->whereNotNull('user_id')->with('user')
            ->orderByDesc('rating')->orderByDesc('results')->orderBy('id')->limit(5)->get();

        $rated = $season !== null ? $top(Rating::RATED, $season) : collect();

        return $rated->isNotEmpty() ? ['pool' => Rating::RATED, 'rows' => $rated] : ['pool' => Rating::CASUAL, 'rows' => $top(Rating::CASUAL, '')];
    }

    /**
     * @return Collection<int, ChessChallenge>
     */
    #[Computed]
    public function dailyChallenges(): Collection
    {
        $user = auth()->user();

        return $user instanceof User ? app(DailyChallenges::class)->incoming($user) : collect();
    }

    public function acceptDailyChallenge(int $id): void
    {
        $this->attempt(fn (User $user) => $this->redirectRoute('games.show', ['game' => app(DailyChallenges::class)->accept(ChessChallenge::query()->findOrFail($id), $user)]));
    }

    public function declineDailyChallenge(int $id): void
    {
        $this->attempt(fn (User $user) => app(DailyChallenges::class)->close(ChessChallenge::query()->findOrFail($id), $user));
        unset($this->dailyChallenges);
    }

    /**
     * When this lobby asks the server on its own (ms, server clock), or null
     * while there is nothing to wait for. Pairings and answers to an invite
     * arrive by push; the lobby asks when a wider search range could now
     * fit, when its invite expires, and otherwise every lobby_poll_seconds
     * as a net under a push that did not arrive.
     */
    #[Computed]
    public function checkAt(): ?int
    {
        if ($this->entry === null && $this->outgoing === null) {
            return null;
        }

        $now = now();
        $net = (int) $now->copy()->addSeconds(max(30, (int) config('esports.chess.lobby_poll_seconds')))->getTimestampMs();
        $due = $this->entry !== null ? app(ChessQueue::class)->nextWidening($this->entry, $now) : $this->outgoing?->expires_at;

        // Half a second late, so the server's clock has passed that moment too.
        return $due === null ? $net : min($net, (int) $due->getTimestampMs() + 500);
    }

    #[Computed]
    public function searching(): int
    {
        return ChessQueueEntry::query()->count();
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
        } catch (ChessRuleViolation $violation) {
            if ($violation->reason === 'already_playing' && $this->activeGame !== null) {
                $this->goTo($this->activeGame);

                return;
            }

            $this->error = match ($violation->reason) {
                'invite_closed' => __('That invite is no longer open.'),
                'opponent_playing' => __('That player is already in another live game, so the invite is closed.'),
                'accept_while_playing' => __('You are in a live game. One live game at a time: finish it, then accept the invite.'),
                'challenge_closed' => __('That challenge is no longer open.'),
                'invite_self' => __('You cannot invite yourself.'),
                'rated_not_open', 'not_looking' => $violation->getMessage(),
                'casual_playing' => __('Finish your casual 1v1 first.'),
                'lost_race' => __('Someone else answered first. Please try again.'),
                default => __('That did not work, please try again.'),
            };
        }

        unset($this->entry, $this->outgoing, $this->incoming, $this->activeGame);
    }

    private function goTo(?ChessGame $game): void
    {
        if ($game !== null) {
            $this->redirectRoute('games.show', ['game' => $game]);
        }
    }
}; ?>

@php
    $user = auth()->user();
    $entry = $this->entry;
    $outgoing = $this->outgoing;
    $active = $this->activeGame;
@endphp

<div class="flex grow flex-col" x-data="chessLobby(@js(['userId' => $user?->id, 'poll' => max(30, (int) config('esports.chess.lobby_poll_seconds'))]))" data-server-now="{{ (int) now()->getTimestampMs() }}" data-looking="{{ $user?->looking_to_play === 'chess/blitz' ? 'true' : 'false' }}">
    <div class="flex flex-col gap-6 px-4 pb-8 lg:gap-8 lg:px-12 lg:pb-10">
        {{-- The title below lg; from lg the header's chess bar names the page. --}}
        <div class="flex items-baseline justify-between gap-3 lg:hidden">
            <h1 class="m-0 font-display text-2xl leading-tight font-bold">{{ __('Chess') }}</h1>
            @auth<x-rating :rating="\App\Support\Rating\Ratings::headline($user->id, 'chess', 'blitz')" :label="__('Blitz')" class="text-[13px] text-ink-2" />@endauth
        </div>
        <h1 class="sr-only max-lg:hidden">{{ __('Chess') }}</h1>

        @if ($error || $notice || $this->incoming->isNotEmpty())
            <div class="flex flex-col gap-2 empty:hidden">
                @if ($error)
                    <p role="alert" class="m-0 rounded-lg bg-loss-tint px-4 py-3 text-[13px] text-loss">{{ $error }}</p>
                @endif

                @if ($notice)
                    <p role="status" class="m-0 rounded-lg bg-card px-4 py-3 text-[13px] leading-normal text-ink-2 shadow-ring" data-test="lobby-notice">{{ $notice }}</p>
                @endif

                {{-- Blitz invites received: they expire in minutes, so they come before everything else. --}}
                @foreach ($this->incoming as $invite)
                    <div wire:key="invite-{{ $invite->id }}" class="flex flex-col gap-3 rounded-lg bg-card p-3 shadow-ring-btc lg:flex-row lg:items-center lg:px-4" data-test="incoming-invite">
                        <span class="flex min-w-0 grow items-center gap-3">
                            <x-player-link :user="$invite->inviter" class="shrink-0"><x-avatar :user="$invite->inviter" :size="40" class="rounded-md" /></x-player-link>
                            <span class="flex min-w-0 flex-col gap-0.5">
                                <b class="text-[15px]">{{ __(':name invites you', ['name' => $invite->inviter->displayName()]) }}</b>
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

        @include('pages.chess.partials.lobby-play', ['user' => $user, 'entry' => $entry, 'outgoing' => $outgoing])

        {{-- The next chess tournament open for sign-up, as on every game page (user, 2026-09-28). --}}
        @if ($this->nextTournament)
            <x-tournaments.poster :tournament="$this->nextTournament" heading-id="lobby-next-h" />
        @else
            <x-tournaments.next-empty game="chess" heading-id="lobby-next-h" />
        @endif
        <x-tournaments.cup-mentions game="chess" class="-mt-4 lg:-mt-6" />

        {{-- The player's own business and the live lobby. Below lg in reading order: your games, live, ladder. --}}
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-12 lg:items-start lg:gap-5">
            @include('pages.chess.partials.lobby-games', ['user' => $user, 'active' => $active])
            @include('pages.chess.partials.lobby-live', ['user' => $user, 'active' => $active])
            @include('pages.chess.partials.lobby-ladder')
        </div>

        {{-- The global chat of chess (P21): a NIP-28 channel with polls, under the lobby, above the weekly events. --}}
        <livewire:game-channel game="chess" />

        {{-- Weekly events (P10): the next dates of the recurring slots, all games. --}}
        <x-weekly-events :events="app(App\Support\Engagement\WeeklySlots::class)->upcoming(4)" heading-id="lobby-weekly-h" class="rounded-lg bg-card px-4 py-5 lg:px-6" />
    </div>
</div>
