<?php

use App\Enums\ChessGameStatus;
use App\Events\LookingToPlayChanged;
use App\Models\ChessChallenge;
use App\Models\ChessGame;
use App\Models\ChessInvite;
use App\Models\ChessQueueEntry;
use App\Models\User;
use App\Support\Chess\Broadcasts;
use App\Support\Chess\ChessGameService;
use App\Support\Chess\ChessInvites;
use App\Support\Chess\ChessQueue;
use App\Support\Chess\ChessRuleViolation;
use App\Support\Chess\DailyChallenges;
use App\Support\Chess\RatedChess;
use App\Support\PageMeta;
use App\Support\SeasonChain\Opponents;
use App\Support\SeasonChain\Seasons;
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
 */
new #[Layout('layouts::app', ['section' => 'chess', 'realtime' => true, 'scripts' => ['resources/js/chess.js']])] class extends Component {
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

        $wanted = $looking ? 'chess/blitz' : null;

        if ($user->looking_to_play !== $wanted) {
            $user->forceFill(['looking_to_play' => $wanted])->save();
            Broadcasts::send(new LookingToPlayChanged($user->id, $user->looking_to_play));
        }

        return $user->looking_to_play !== null;
    }

    public function rendering(\Illuminate\View\View $view): void
    {
        $view->title(__('Chess'));
        app(PageMeta::class)->describe(__('Chess'), __('Play blitz chess 5+3 live or daily chess against Bitcoiners: find an opponent, watch the live boards and follow your daily games.'));

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

    /**
     * @return Collection<int, ChessGame>
     */
    #[Computed]
    public function dailyGames(): Collection
    {
        $user = auth()->user();

        return $user instanceof User
            ? ChessGame::query()->daily()->playedBy($user)->where('status', ChessGameStatus::Active)->with(['white', 'black'])->orderBy('deadline_ms')->get()
            : collect();
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
                'rated_not_open' => $violation->getMessage(),
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
    $range = config('esports.chess.queue.range');
    $rating = (int) config('esports.chess.queue.start_rating');
    $ownBlitz = \App\Support\Rating\Ratings::headline($user?->id, 'chess', 'blitz');
    $dailyPool = \App\Support\Rating\Ratings::headline(null, 'chess', 'correspondence')['pool'];
    $entry = $this->entry;
    $outgoing = $this->outgoing;
    $active = $this->activeGame;
@endphp

<div class="flex grow flex-col" x-data="chessLobby(@js(['userId' => $user?->id, 'poll' => max(30, (int) config('esports.chess.lobby_poll_seconds'))]))" data-server-now="{{ (int) now()->getTimestampMs() }}" data-looking="{{ $user?->looking_to_play !== null ? 'true' : 'false' }}">

    <div class="grid grid-cols-1 gap-4 px-4 pb-8 lg:grid-cols-3 lg:gap-5 lg:px-12 lg:pb-10">
        {{-- Mobile title (MobileChessLobby) --}}
        <div class="flex flex-col gap-1 lg:hidden">
            <h1 class="m-0 font-display text-[28px] font-bold">{{ __('Chess') }}</h1>
            @auth<x-rating :rating="$ownBlitz" :label="__('Blitz')" class="text-[13px] text-ink-2" />@endauth
        </div>
        <h1 class="sr-only max-lg:hidden">{{ __('Chess') }}</h1>

        @if ($error)
            <p role="alert" class="m-0 rounded-lg bg-loss-tint px-4 py-3 text-[13px] text-loss lg:col-span-3">{{ $error }}</p>
        @endif

        @if ($notice)
            <p role="status" class="m-0 rounded-lg bg-card px-4 py-3 text-[13px] leading-normal text-ink-2 shadow-ring lg:col-span-3" data-test="lobby-notice">{{ $notice }}</p>
        @endif

        @if ($active)
            <a href="{{ route('games.show', $active) }}" class="flex items-center gap-3 rounded-lg bg-btc-press px-4 py-3 text-[13px] font-bold text-btc-hi hover:text-btc-hi lg:col-span-3" data-test="resume-game">
                <span class="size-2 animate-live rounded-full bg-btc-hi"></span>{{ __('You are in a live game (:number). Back to the board', ['number' => $active->number()]) }} →
            </a>
        @endif

        {{-- Invites received (ChessOverlays "Daily challenge received", as a blitz invite) --}}
        @foreach ($this->incoming as $invite)
            <div wire:key="invite-{{ $invite->id }}" class="flex flex-col gap-3 rounded-lg bg-card p-4 shadow-ring lg:col-span-3 lg:flex-row lg:items-center lg:px-6" data-test="incoming-invite">
                <span class="flex min-w-0 grow items-center gap-3">
                    <x-player-link :user="$invite->inviter" class="shrink-0"><x-avatar :user="$invite->inviter" :size="40" class="rounded-md" /></x-player-link>
                    <span class="flex min-w-0 flex-col gap-0.5">
                        <b class="text-base">{{ __(':name invites you', ['name' => $invite->inviter->displayName()]) }}</b>
                        <span class="text-xs text-ink-2">{{ __('Blitz 5+3 · Casual · colours drawn at random') }}</span>
                    </span>
                </span>
                <span class="grid grid-cols-2 gap-2 lg:flex">
                    <x-button variant="quiet" wire:click="declineInvite({{ $invite->id }})" data-test="decline-invite">{{ __('Decline') }}</x-button>
                    <x-button icon="shield-check" wire:click="acceptInvite({{ $invite->id }})" data-test="accept-invite">{{ __('Accept') }}</x-button>
                </span>
            </div>
        @endforeach

        {{-- Find opponent --}}
        <section aria-labelledby="find-h" class="flex flex-col gap-4 lg:rounded-lg lg:bg-card lg:px-6 lg:py-5" data-test="find-opponent" x-data="{ rated: false }">
            <span class="flex items-baseline justify-between gap-3 max-lg:sr-only"><h2 id="find-h" class="m-0 text-[15px] font-bold">{{ __('Find opponent') }}</h2><span class="text-xs text-ink-2">{{ __('Blitz 5+3, live') }}</span></span>

            @if ($entry)
                {{-- ChessStates "Finding opponent" --}}
                <div data-check-at="{{ $this->checkAt }}" role="status" aria-live="polite" class="flex flex-col items-center gap-4 rounded-lg bg-ground p-4 shadow-ring-hairline" data-test="searching">
                    <div class="cube mt-4 flex size-[124px] flex-col items-center justify-between bg-[linear-gradient(180deg,#2A1F0E,#17120A)] px-2 py-2.5 text-center" aria-hidden="true">
                        <span class="text-[13px] font-bold">~{{ $entry->rating }} Elo</span>
                        <span class="text-[11px] text-btc-hi">{{ $entry->rating - app(ChessQueue::class)->range($entry) }} – {{ $entry->rating + app(ChessQueue::class)->range($entry) }}</span>
                        <span class="text-base font-bold">5+3</span>
                        <span class="text-[11px] text-ink-2" data-test="searching-kind">{{ $entry->rated ? __('Blitz · rated') : __('Blitz · casual') }}</span>
                        <span class="text-[11px]" x-text="since({{ $entry->joined_at->getTimestampMs() }})"></span>
                    </div>
                    <span class="font-display text-lg font-bold">{{ __('Finding opponent … 5+3') }}</span>
                    <span class="block h-1 w-full max-w-[420px] overflow-hidden rounded-xs bg-raised"><span class="sweep block h-1 w-2/5 rounded-xs bg-btc"></span></span>
                    <span class="text-[13px] leading-normal text-ink-2">
                        {{ trans_choice(':count player searching right now.|:count players searching right now.', $this->searching) }}
                        {{ __('Your range: ±:range around :rating, it opens by :step every :seconds s. As soon as someone fits, the game starts, no extra click.', ['range' => app(ChessQueue::class)->range($entry), 'rating' => $entry->rating, 'step' => $range['step'], 'seconds' => $range['every_seconds']]) }}
                    </span>
                    {{-- P5c: asked once per browser, when the player joins the queue; the browser's own prompt only after "Allow". --}}
                    <div x-show="askNotify" x-cloak class="flex flex-col gap-2.5 self-stretch rounded-md bg-toast-challenge p-3 text-left shadow-ring-btc" data-test="notify-prompt">
                        <span class="flex items-start gap-2.5 text-[13px]"><x-icon name="bell" :size="16" class="mt-0.5 shrink-0 text-btc" /><span><b>{{ __('Hear about it in another tab?') }}</b> <span class="text-ink-2">{{ __('A desktop notification when an opponent is found, while this tab is in the background.') }}</span></span></span>
                        <span class="grid grid-cols-2 gap-2">
                            <x-button variant="quiet" x-on:click="answerNotify(false)" data-test="notify-prompt-no">{{ __('Not now') }}</x-button>
                            <x-button icon="bell" x-on:click="answerNotify(true)" data-test="notify-prompt-allow">{{ __('Allow') }}</x-button>
                        </span>
                    </div>
                    <span class="flex flex-wrap items-center gap-3 self-stretch">
                        <x-button variant="quiet" wire:click="cancelSearch" class="grow" data-test="cancel-search">{{ __('Cancel') }}</x-button>
                        <a href="{{ route('chess.challenge') }}" class="text-[13px] text-ink">{{ __('Play daily chess instead') }}</a>
                    </span>
                </div>
            @elseif ($outgoing)
                {{-- ChessStates "Waiting for a friend" --}}
                <div data-check-at="{{ $this->checkAt }}" class="flex flex-col gap-3.5 rounded-lg bg-ground p-5 shadow-ring-hairline" data-test="waiting-for-friend">
                    <span class="flex items-center gap-3"><span aria-hidden="true" class="block size-5 shrink-0 animate-spin rounded-full border-2 border-line border-t-btc"></span><b class="text-base">{{ __('Waiting for :name', ['name' => $outgoing->invitee->displayName()]) }}</b></span>
                    <div class="flex flex-col">
                        <div class="grid h-9 grid-cols-[110px_minmax(0,1fr)] items-center border-b border-hairline text-[13px]"><span class="text-ink-2">{{ __('Time control') }}</span><span>{{ __('Blitz 5+3, colours at random') }}</span></div>
                        <div class="grid h-9 grid-cols-[110px_minmax(0,1fr)] items-center border-b border-hairline text-[13px]"><span class="text-ink-2">{{ __('Friend') }}</span><span>{{ __('online when invited') }}</span></div>
                        <div class="grid h-9 grid-cols-[110px_minmax(0,1fr)] items-center border-b border-hairline text-[13px]"><span class="text-ink-2">{{ __('Open for') }}</span><span role="timer" x-text="since({{ $outgoing->created_at?->getTimestampMs() ?? 0 }})"></span></div>
                    </div>
                    <span class="flex gap-2.5">
                        <button type="button" wire:click="withdrawInvite" class="inline-flex h-11 cursor-pointer items-center justify-center rounded-md border border-[#5A2A2E] bg-transparent px-4 text-[13px] text-loss">{{ __('Withdraw') }}</button>
                    </span>
                </div>
            @else
                <div class="flex items-center justify-between gap-3 rounded-lg bg-ground px-4 py-4 shadow-ring-hairline max-lg:hidden">
                    <span class="flex flex-col gap-1.5">
                        <span class="flex items-center gap-2 text-[13px]"><span class="size-2 rounded-full bg-win"></span>{{ __('players searching now') }}</span>
                        <span class="text-xs text-ink-2">{{ __('your range right now') }}</span>
                    </span>
                    <span class="flex flex-col items-end gap-1"><b class="font-display text-[28px] leading-none">{{ $this->searching }}</b><b class="text-[13px]">±{{ $range['initial'] }}</b></span>
                </div>

                {{-- Casual / Rated (P7e): Rated only while rated chess is open for this player; else the reason --}}
                @php($ratedRefusal = $this->ratedRefusal)
                <div role="radiogroup" aria-label="{{ __('Game kind') }}" class="grid grid-cols-2 gap-1 rounded-lg bg-ground p-1 shadow-ring" data-test="game-kind" data-rated-open="{{ $ratedRefusal === null ? 'true' : 'false' }}">
                    <button type="button" role="radio" aria-checked="true" x-bind:aria-checked="rated ? 'false' : 'true'" x-on:click="rated = false" data-test="kind-casual"
                            class="flex min-h-11 cursor-pointer flex-col items-start justify-center gap-0.5 rounded-md bg-raised px-3.5 py-2 text-left text-ink shadow-[inset_0_-2px_0_#F7931A]"
                            x-bind:class="rated ? 'bg-transparent! shadow-none!' : ''">
                        <b class="text-[13px] text-btc-hi" x-bind:class="rated ? 'text-ink!' : ''">{{ __('Casual') }}</b><span class="text-[11px] text-ink-2">{{ __('casual Elo only') }}</span>
                    </button>
                    <button type="button" role="radio" aria-checked="false" x-bind:aria-checked="rated ? 'true' : 'false'" x-on:click="rated = true" data-test="kind-rated"
                            @disabled($ratedRefusal !== null) aria-describedby="kind-why"
                            class="flex min-h-11 cursor-pointer flex-col items-start justify-center gap-0.5 rounded-md px-3.5 py-2 text-left text-ink disabled:cursor-not-allowed disabled:opacity-60"
                            x-bind:class="rated ? 'bg-raised shadow-[inset_0_-2px_0_#F7931A]' : ''">
                        <b class="text-[13px]" x-bind:class="rated ? 'text-btc-hi' : ''">{{ __('Rated') }}</b><span class="text-[11px] text-ink-2">{{ $ratedRefusal === null ? __('counts for Elo') : (Ladders::isOpen('chess', 'blitz') ? __('not open for you yet') : __('from Block 0')) }}</span>
                    </button>
                </div>
                <p id="kind-why" class="m-0 text-[13px] leading-normal text-ink-2" data-test="kind-why">
                    @if ($ratedRefusal !== null)
                        {{ $ratedRefusal }}
                    @else
                        <span x-show="! rated">{{ __('Casual pairs you with anyone online and moves only your casual Elo.') }}</span>
                        <span x-show="rated" x-cloak>{{ trans_choice('Rated pairs you only with a Trusted player you list each other with (you have :count).|Rated pairs you only with Trusted players you list each other with (you have :count).', $this->mutualOpponents) }}</span>
                    @endif
                </p>

                <div class="flex flex-col gap-2 max-lg:hidden">
                    <span class="text-[13px] text-ink-2">{{ __('Opponent strength') }}</span>
                    <span class="flex h-11 items-center rounded-lg border border-edge bg-ground px-3.5 text-sm">{{ __('±:range around :rating, wider every :seconds s', ['range' => $range['initial'], 'rating' => $rating, 'seconds' => $range['every_seconds']]) }}</span>
                </div>

                @auth
                    <button type="button" x-on:click="joinQueue(rated)" data-test="find-opponent-button"
                            class="btn-p flex min-h-14 cursor-pointer items-center justify-between gap-3 rounded-md bg-btc px-4 py-2 text-left text-on-btc lg:justify-center lg:gap-2">
                        <span class="flex flex-col gap-0.5">
                            <span class="flex items-center gap-2 font-display text-lg font-bold lg:font-mono lg:text-base"><x-icon name="pawn" :size="18" class="max-lg:hidden" />{{ __('Find opponent') }}<span class="max-lg:hidden">· {{ __('Blitz 5+3') }}</span></span>
                            <span class="text-xs lg:hidden">{{ __('Blitz 5+3') }} · {{ trans_choice(':count searching|:count searching', $this->searching) }}</span>
                        </span>
                        <span class="text-xs font-bold lg:hidden">{{ __('Join queue') }}</span>
                    </button>
                @else
                    <x-button :href="route('login')" class="min-h-14">{{ __('Log in to play') }}</x-button>
                @endauth
                <p class="m-0 text-[13px] leading-normal text-ink-2 max-lg:hidden">{{ __('You join the queue and can cancel any time. The game starts as soon as someone in your range is found.') }}</p>

                <div class="grid grid-cols-2 gap-2">
                    <a href="{{ route('chess.challenge') }}" class="btn-w flex min-h-11 flex-col items-center justify-center rounded-md border border-line bg-well px-2 py-2 text-center text-[13px] text-ink hover:text-ink" data-test="daily-challenge-button">{{ __('Daily challenge') }}</a>
                    <span class="flex min-h-11 flex-col items-center justify-center rounded-md border border-line bg-well px-2 py-2 text-center text-[13px] text-ink-3 max-lg:hidden" aria-disabled="true">{{ __('Team match') }}<span class="text-[11px]">{{ __('coming soon') }}</span></span>
                    <a href="#online-h" class="btn-w flex min-h-11 flex-col items-center justify-center rounded-md border border-line bg-well px-2 py-2 text-center text-[13px] text-ink hover:text-ink lg:col-span-2 lg:px-4">{{ __('Invite a friend who is online') }}</a>
                </div>
            @endif
        </section>

        {{-- Now playing + Online now --}}
        <div class="flex flex-col gap-4 lg:col-span-2 lg:gap-5">
            <section aria-labelledby="now-h" class="flex flex-col gap-4 rounded-lg bg-card px-4 py-5 lg:px-6" data-test="now-playing">
                <span class="flex items-baseline justify-between gap-3"><h2 id="now-h" class="m-0 text-[15px] font-bold">{{ __('Now playing') }}</h2><span class="text-xs text-ink-2">{{ trans_choice(':count live blitz board|:count live blitz boards', $this->liveGames->count()) }}</span></span>
                @if ($this->liveGames->isEmpty())
                    <p class="m-0 text-[13px] text-ink-2">{{ __('No live game right now. Find an opponent and yours shows up here.') }}</p>
                @else
                    <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
                        @foreach ($this->liveGames as $live)
                            <div wire:key="live-{{ $live->id }}" class="flex flex-col gap-2.5 border-b border-hairline pb-3 lg:border-0 lg:pb-0">
                                <span class="flex items-center gap-2 text-[13px]"><x-player-link :user="$live->black" class="flex min-w-0 items-center gap-2"><x-avatar :user="$live->black" :size="18" class="rounded-sm" /><b class="truncate">{{ $live->black->displayName() }}</b></x-player-link><span class="text-ink-2">{{ $rating }}</span></span>
                                <div class="hidden justify-center py-2 lg:flex" x-data="{ cells: window.chessBoardCells(@js($live->fen), { noCoords: true }), boardLabel: @js(__('Live board of :number', ['number' => $live->number()])) }">
                                    <x-chess.board class="mt-4 mr-4 max-w-40" />
                                </div>
                                <span class="flex items-center gap-2 text-[13px]"><x-player-link :user="$live->white" class="flex min-w-0 items-center gap-2"><x-avatar :user="$live->white" :size="18" class="rounded-sm" /><b class="truncate">{{ $live->white->displayName() }}</b></x-player-link><span class="text-ink-2">{{ $rating }}</span></span>
                                <span class="text-xs text-ink-2">{{ __('move :n', ['n' => intdiv($live->ply, 2) + 1]) }} · {{ $live->number() }}</span>
                                <x-button variant="quiet" icon="eye" :href="route('games.show', $live)">{{ __('Watch') }}</x-button>
                            </div>
                        @endforeach
                    </div>
                @endif
            </section>

            {{-- Online now: presence channel `online` (no artboard yet, built in the lobby's panel style) --}}
            <section aria-labelledby="online-h" class="flex flex-col gap-3 rounded-lg bg-card px-4 py-5 lg:px-6" data-test="online-now">
                <span class="flex flex-wrap items-center justify-between gap-3">
                    <h2 id="online-h" class="m-0 flex items-center gap-2 text-[15px] font-bold"><span class="size-2 rounded-full bg-win"></span>{{ __('Online now') }} <span class="font-normal text-ink-2" x-show="connection === 'connected'" x-text="others.length"></span></h2>
                    @auth
                        {{-- The page's own state (chessLobby.looking): a Livewire render never touches it. --}}
                        <button type="button" wire:ignore x-on:click="toggleLooking()" role="switch" aria-checked="{{ $user->looking_to_play ? 'true' : 'false' }}" x-bind:aria-checked="looking ? 'true' : 'false'" data-test="looking-toggle"
                                class="flex h-11 cursor-pointer items-center gap-2.5 rounded-md border border-line bg-well px-3 text-[13px] text-ink">
                            <span class="relative h-5 w-9 shrink-0 rounded-full transition-colors" x-bind:class="looking ? 'bg-btc' : 'bg-raised shadow-ring'"><span class="absolute top-0.5 size-4 rounded-full bg-ink transition-all" x-bind:class="looking ? 'left-[18px]' : 'left-0.5'"></span></span>
                            {{ __('Looking to play') }}
                            <b class="min-w-7 text-left" x-bind:class="looking ? 'text-win' : 'text-ink-2'" x-text="looking ? @js(__('On')) : @js(__('Off'))" data-test="looking-state">{{ $user->looking_to_play ? __('On') : __('Off') }}</b>
                        </button>
                    @endauth
                </span>
                @auth
                    <p x-show="lookingFailed" x-cloak role="alert" class="m-0 text-[13px] text-loss" data-test="looking-failed">{{ __('That did not save. The switch is back where it was, please try again.') }}</p>
                @endauth
                @guest
                    <p class="m-0 text-[13px] text-ink-2">{{ __('Log in to see who is online and to invite a friend.') }}</p>
                @else
                    <p class="m-0 text-[13px] text-ink-2" x-show="connection !== 'connected'">{{ __('The online list needs the live connection. Connecting …') }}</p>
                    <p class="m-0 text-[13px] text-ink-2" x-show="connection === 'connected' && others.length === 0">{{ __('Nobody else is online right now.') }}</p>
                    <ul class="m-0 flex list-none flex-col p-0" x-show="others.length > 0">
                        <template x-for="m in others" :key="m.id">
                            <li class="flex min-h-12 items-center gap-3 border-b border-hairline text-[13px] last:border-0" data-test="online-player">
                                {{-- Picture or Blockpile (P10a); the name opens the player card (resources/js/profiles.js). --}}
                                <a :href="@js(url('players')) + '/' + m.npub" :data-player-card="m.npub" :data-pubkey="m.pubkey" :data-player-name="m.name" aria-haspopup="dialog"
                                   class="flex min-h-11 min-w-0 grow items-center gap-3 text-ink hover:text-ink" data-test="online-player-link">
                                    <img :src="m.avatar || m.generated" :data-fallback="m.generated" width="24" height="24" loading="lazy" referrerpolicy="no-referrer"
                                         :alt="(m.avatar ? @js(__(':name avatar')) : @js(__(':name avatar, generated'))).replace(':name', m.name)"
                                         x-on:error.once="$el.src = m.generated; $el.alt = @js(__(':name avatar, generated')).replace(':name', m.name)" class="block size-6 shrink-0 rounded-sm bg-raised object-cover">
                                    <b class="min-w-0 truncate" x-text="m.name"></b>
                                </a>
                                <span class="shrink-0 text-xs text-ink-2" x-show="m.elo" data-test="online-elo"><span x-text="m.elo"></span><span class="text-ink-3" x-show="m.provisional"> · {{ __('provisional') }}</span></span>
                                <span x-show="m.looking" class="rounded-sm bg-[#122016] px-2 py-0.5 text-[11px] font-bold text-win">{{ __('looking: Blitz 5+3') }}</span>
                                @if (! $active)
                                    <template x-if="invited(m)">
                                        <span class="flex shrink-0 items-center gap-1 text-[13px]" data-test="invited">
                                            <span class="text-ink-2">{{ __('Invited') }} ·</span>
                                            <button type="button" x-on:click="$wire.withdrawInvite()" class="inline-flex h-11 cursor-pointer items-center rounded-md px-2 text-[13px] text-loss" data-test="withdraw-invite">{{ __('Withdraw') }}</button>
                                        </span>
                                    </template>
                                    <template x-if="! invited(m)">
                                        <button type="button" x-on:click="$wire.invite(m.id)" class="btn-w inline-flex h-11 shrink-0 cursor-pointer items-center rounded-md border border-line bg-well px-3 text-[13px] text-ink" data-test="invite">{{ __('Invite') }}</button>
                                    </template>
                                @endif
                            </li>
                        </template>
                    </ul>
                @endguest
            </section>
        </div>

        {{-- Your daily games (ChessLobby row 2) --}}
        <section aria-labelledby="daily-h" class="flex flex-col gap-3 rounded-lg bg-card px-4 py-5 lg:px-6" data-test="lobby-daily">
            <span class="flex items-baseline justify-between gap-3"><h2 id="daily-h" class="m-0 text-[15px] font-bold">{{ __('Your daily games') }}</h2>@auth<a href="{{ route('me.correspondence') }}" class="text-xs text-ink">{{ __('All :count', ['count' => $this->dailyGames->count()]) }}</a>@endauth</span>
            @guest
                <p class="m-0 text-[13px] leading-normal text-ink-2">{{ __('Log in to play daily chess: one move a day, at your pace.') }}</p>
            @else
                @foreach ($this->dailyChallenges->take(2) as $challenge)
                    <div wire:key="dc-{{ $challenge->id }}" class="flex flex-col gap-2 border-b border-hairline pb-3">
                        <span class="flex items-baseline justify-between gap-2 text-[13px]"><b class="truncate">{{ $challenge->challenger->displayName() }}</b><span class="text-xs text-btc-hi">{{ __('challenges you') }}</span></span>
                        <span class="text-xs text-ink-2">{{ __('Daily chess · Casual · you play :color', ['color' => match ($challenge->color) { 'white' => __('Black'), 'black' => __('White'), default => __('a random colour') }]) }}</span>
                        <span class="grid grid-cols-2 gap-2">
                            <x-button icon="shield-check" wire:click="acceptDailyChallenge({{ $challenge->id }})">{{ __('Accept') }}</x-button>
                            <x-button variant="quiet" wire:click="declineDailyChallenge({{ $challenge->id }})">{{ __('Decline') }}</x-button>
                        </span>
                    </div>
                @endforeach
                @forelse ($this->dailyGames->take(5) as $daily)
                    @php($opp = $daily->opponentOf($user))
                    @php($mine = $daily->turn() === $daily->colorOf($user))
                    @php($left = intdiv(max(0, (int) $daily->deadline_ms - (int) now()->getTimestampMs()), 60_000))
                    <a wire:key="dg-{{ $daily->id }}" href="{{ route('games.show', $daily) }}" class="flex flex-col gap-1 border-b border-hairline pb-2.5 text-ink last:border-0 hover:text-ink">
                        <span class="flex items-center justify-between gap-2 text-[13px]"><span class="truncate">{{ $opp?->displayName() }} <span class="text-ink-2" data-test="daily-opponent-elo">{{ \App\Support\Rating\Ratings::forUser($opp?->id, 'chess', 'correspondence', $dailyPool)['rating'] }}</span></span>@if ($mine)<span class="rounded-sm bg-btc px-1.5 py-0.5 text-[11px] font-bold text-on-btc">{{ __('Your move') }}</span>@else<span class="text-[11px] text-ink-3">{{ __('Their move') }}</span>@endif</span>
                        <span class="flex justify-between gap-2 text-xs text-ink-2"><span>{{ __('You play :color', ['color' => $daily->colorOf($user) === 'w' ? __('White') : __('Black')]) }}</span><span>{{ $mine ? __(':h h :m min left', ['h' => intdiv($left, 60), 'm' => str_pad((string) ($left % 60), 2, '0', STR_PAD_LEFT)]) : __('move :n', ['n' => intdiv($daily->ply, 2) + 1]) }}</span></span>
                    </a>
                @empty
                    @if ($this->dailyChallenges->isEmpty())
                        <p class="m-0 text-[13px] leading-normal text-ink-2">{{ __('No daily game yet. One move a day, at your pace.') }}</p>
                    @endif
                @endforelse
                <x-button variant="quiet" :href="route('chess.challenge')" class="self-start">{{ __('Daily challenge') }}</x-button>
            @endguest
        </section>

        {{-- The blitz ladder: lands on the view with rows (casual before Block 0). --}}
        <section aria-labelledby="ladder-card-h" class="flex flex-col gap-2 rounded-lg bg-card px-4 py-5 lg:px-6" data-test="lobby-ladder">
            <span class="flex items-baseline justify-between gap-3"><h2 id="ladder-card-h" class="m-0 text-[15px] font-bold">{{ __('Blitz ladder') }}</h2><span class="text-xs text-ink-2">{{ Ladders::isOpen('chess', 'blitz') ? __('rated season') : __('casual until Block 0') }}</span></span>
            <p class="m-0 text-[13px] leading-normal text-ink-2">{{ Ladders::isOpen('chess', 'blitz') ? __('Rank, Elo and results of every blitz player.') : __('Every casual blitz game counts here. The rated ladder starts at Block 0.') }}</p>
            <x-button variant="quiet" :href="route('ladder.show', ['chess', 'blitz'])" class="self-start" data-test="lobby-ladder-link">{{ __('Open the blitz ladder') }}</x-button>
        </section>

        {{-- Later phases keep their places (ChessLobby row 2) --}}
        @foreach ([[__('Clan Hashrate'), __('Rated games of clan players count for their clan from Block 0.')]] as [$heading, $text])
            <section class="flex flex-col gap-2 rounded-lg bg-card px-4 py-5 max-lg:hidden lg:px-6">
                <span class="flex items-baseline justify-between gap-3"><h2 class="m-0 text-[15px] font-bold">{{ $heading }}</h2><span class="rounded-sm bg-btc-tint px-2 py-0.5 text-[11px] font-bold text-btc">{{ __('coming soon') }}</span></span>
                <p class="m-0 text-[13px] leading-normal text-ink-2">{{ $text }}</p>
            </section>
        @endforeach
        <section class="flex flex-col gap-2 rounded-lg bg-card px-4 py-5 max-lg:hidden lg:col-span-3 lg:px-6">
            <span class="flex items-baseline justify-between gap-3"><h2 class="m-0 text-[15px] font-bold">{{ __('Team matches in progress') }}</h2><span class="rounded-sm bg-btc-tint px-2 py-0.5 text-[11px] font-bold text-btc">{{ __('coming soon') }}</span></span>
            <p class="m-0 text-[13px] leading-normal text-ink-2">{{ __('Clan team matches over several boards follow after the live blitz core.') }}</p>
        </section>
    </div>
</div>
