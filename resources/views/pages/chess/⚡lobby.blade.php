<?php

use App\Enums\ChessGameStatus;
use App\Events\LookingToPlayChanged;
use App\Models\ChessGame;
use App\Models\ChessInvite;
use App\Models\ChessQueueEntry;
use App\Models\User;
use App\Support\Chess\Broadcasts;
use App\Support\Chess\ChessGameService;
use App\Support\Chess\ChessInvites;
use App\Support\Chess\ChessQueue;
use App\Support\Chess\ChessRuleViolation;
use App\Support\SampleData;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * Chess lobby, from ChessLobby.dc.html and MobileChessLobby.dc.html, with the
 * queue states of ChessStates ("Finding opponent", "Waiting for a friend").
 *
 * Real in P5a: the blitz queue (casual only), invites to a friend who is
 * online, the online list with "Looking to play" (presence channel `online`,
 * a panel the designs do not draw yet), and the live games. Daily games,
 * Solo Elo, Clan Hashrate and team matches belong to P5b/P7 and keep their
 * places as "coming" cards.
 */
new #[Title('Chess')] #[Layout('layouts::app', ['section' => 'chess', 'realtime' => true, 'scripts' => ['resources/js/chess.js']])] class extends Component {
    public string $error = '';

    /**
     * "Find next opponent" / "Search again" land here with `?search=1`.
     */
    public function mount(): void
    {
        if (request()->boolean('search') && auth()->check()) {
            $this->findOpponent();
        }
    }

    public function findOpponent(): void
    {
        $this->attempt(fn (User $user) => $this->goTo(app(ChessQueue::class)->join($user)));
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
        $this->attempt(fn (User $user) => app(ChessInvites::class)->invite($user, User::query()->findOrFail($userId)));
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

    public function toggleLookingToPlay(): void
    {
        $this->attempt(function (User $user): void {
            $user->forceFill(['looking_to_play' => $user->looking_to_play === null ? 'chess/blitz' : null])->save();
            Broadcasts::send(new LookingToPlayChanged($user->id, $user->looking_to_play));
        });
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
        return ChessGame::query()->where('status', ChessGameStatus::Active)->with(['white', 'black'])->latest('id')->limit(3)->get();
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
                'invite_self' => __('You cannot invite yourself.'),
                'rated_not_open' => __('Rated games start with Season 1.'),
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
    $entry = $this->entry;
    $outgoing = $this->outgoing;
    $active = $this->activeGame;
@endphp

<div class="flex grow flex-col" x-data="chessLobby(@js(['userId' => $user?->id]))">
    <x-block-strip :finished="SampleData::finishedBlocks()" :running="SampleData::runningBlocks()" focus="chess" class="max-lg:hidden" />

    <div class="grid grid-cols-1 gap-4 px-4 pt-5 pb-8 lg:grid-cols-3 lg:gap-5 lg:px-12 lg:pt-0 lg:pb-10">
        {{-- Mobile title (MobileChessLobby) --}}
        <div class="flex flex-col gap-1 lg:hidden">
            <h1 class="m-0 font-display text-[28px] font-bold">{{ __('Chess') }}</h1>
            <span class="flex items-center gap-1.5 text-[13px] text-ink-2">{{ __('Blitz') }} <b class="text-ink">{{ $rating }}</b> <x-rank-badge tier="provisional" size="sm" /></span>
        </div>
        <h1 class="sr-only max-lg:hidden">{{ __('Chess') }}</h1>

        @if ($error)
            <p role="alert" class="m-0 rounded-lg bg-loss-tint px-4 py-3 text-[13px] text-loss lg:col-span-3">{{ $error }}</p>
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
                    <x-avatar :name="$invite->inviter->displayName()" :src="$invite->inviter->avatarUrl()" :size="40" class="rounded-md" />
                    <span class="flex min-w-0 flex-col gap-0.5">
                        <b class="text-base">{{ __(':name invites you', ['name' => $invite->inviter->displayName()]) }}</b>
                        <span class="text-xs text-ink-2">{{ __('Blitz 5+3 · Casual · colours drawn at random') }}</span>
                    </span>
                </span>
                <span class="grid grid-cols-2 gap-2 lg:flex">
                    <x-button variant="quiet" wire:click="declineInvite({{ $invite->id }})">{{ __('Decline') }}</x-button>
                    <x-button icon="shield-check" wire:click="acceptInvite({{ $invite->id }})" data-test="accept-invite">{{ __('Accept') }}</x-button>
                </span>
            </div>
        @endforeach

        {{-- Find opponent --}}
        <section aria-labelledby="find-h" class="flex flex-col gap-4 lg:rounded-lg lg:bg-card lg:px-6 lg:py-5" data-test="find-opponent">
            <span class="flex items-baseline justify-between gap-3 max-lg:sr-only"><h2 id="find-h" class="m-0 text-[15px] font-bold">{{ __('Find opponent') }}</h2><span class="text-xs text-ink-2">{{ __('Blitz 5+3, live') }}</span></span>

            @if ($entry)
                {{-- ChessStates "Finding opponent" --}}
                <div wire:poll.2s="pollQueue" role="status" aria-live="polite" class="flex flex-col items-center gap-4 rounded-lg bg-ground p-4 shadow-ring-hairline" data-test="searching">
                    <div class="cube mt-4 flex size-[124px] flex-col items-center justify-between bg-[linear-gradient(180deg,#2A1F0E,#17120A)] px-2 py-2.5 text-center" aria-hidden="true">
                        <span class="text-[13px] font-bold">~{{ $entry->rating }} Elo</span>
                        <span class="text-[11px] text-btc-hi">{{ $entry->rating - app(ChessQueue::class)->range($entry) }} – {{ $entry->rating + app(ChessQueue::class)->range($entry) }}</span>
                        <span class="text-base font-bold">5+3</span>
                        <span class="text-[11px] text-ink-2">{{ __('Blitz · casual') }}</span>
                        <span class="text-[11px]" x-text="since({{ $entry->joined_at->getTimestampMs() }})"></span>
                    </div>
                    <span class="font-display text-lg font-bold">{{ __('Finding opponent … 5+3') }}</span>
                    <span class="block h-1 w-full max-w-[420px] overflow-hidden rounded-xs bg-raised"><span class="sweep block h-1 w-2/5 rounded-xs bg-btc"></span></span>
                    <span class="text-[13px] leading-normal text-ink-2">
                        {{ trans_choice(':count player searching right now.|:count players searching right now.', $this->searching) }}
                        {{ __('Your range: ±:range around :rating, it opens by :step every :seconds s. As soon as someone fits, the game starts, no extra click.', ['range' => app(ChessQueue::class)->range($entry), 'rating' => $entry->rating, 'step' => $range['step'], 'seconds' => $range['every_seconds']]) }}
                    </span>
                    <x-button variant="quiet" wire:click="cancelSearch" class="self-stretch" data-test="cancel-search">{{ __('Cancel') }}</x-button>
                </div>
            @elseif ($outgoing)
                {{-- ChessStates "Waiting for a friend" --}}
                <div wire:poll.5s="pollQueue" class="flex flex-col gap-3.5 rounded-lg bg-ground p-5 shadow-ring-hairline" data-test="waiting-for-friend">
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

                {{-- Casual / Rated: rated opens with Season 1 (P7) --}}
                <div role="radiogroup" aria-label="{{ __('Game kind') }}" class="grid grid-cols-2 gap-1 rounded-lg bg-ground p-1 shadow-ring">
                    <span role="radio" aria-checked="true" class="flex flex-col gap-0.5 rounded-md bg-raised px-3.5 py-2 shadow-[inset_0_-2px_0_#F7931A]"><b class="text-[13px] text-btc-hi">{{ __('Casual') }}</b><span class="text-[11px] text-ink-2">{{ __('no rating') }}</span></span>
                    <span role="radio" aria-checked="false" aria-disabled="true" class="flex flex-col gap-0.5 px-3.5 py-2 opacity-60"><b class="text-[13px]">{{ __('Rated') }}</b><span class="text-[11px] text-ink-2">{{ __('from Season 1') }}</span></span>
                </div>
                <p class="m-0 text-[13px] leading-normal text-ink-2 max-lg:hidden">{{ __('Until Season 1 starts every game is casual: no rating, and you play anyone who is online.') }}</p>

                <div class="flex flex-col gap-2 max-lg:hidden">
                    <span class="text-[13px] text-ink-2">{{ __('Opponent strength') }}</span>
                    <span class="flex h-11 items-center rounded-lg border border-edge bg-ground px-3.5 text-sm">{{ __('±:range around :rating, wider every :seconds s', ['range' => $range['initial'], 'rating' => $rating, 'seconds' => $range['every_seconds']]) }}</span>
                </div>

                @auth
                    <button type="button" wire:click="findOpponent" data-test="find-opponent-button"
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
                    <span class="flex min-h-11 flex-col items-center justify-center rounded-md border border-line bg-well px-2 py-2 text-center text-[13px] text-ink-3" aria-disabled="true">{{ __('Daily challenge') }}<span class="text-[11px]">{{ __('coming soon') }}</span></span>
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
                                <span class="flex items-center gap-2 text-[13px]"><x-avatar :name="$live->black->displayName()" :src="$live->black->avatarUrl()" :size="18" class="rounded-sm" /><b class="truncate">{{ $live->black->displayName() }}</b><span class="text-ink-2">{{ $rating }}</span></span>
                                <div class="hidden justify-center py-2 lg:flex" x-data="{ cells: window.chessBoardCells(@js($live->fen), { noCoords: true }), boardLabel: @js(__('Live board of :number', ['number' => $live->number()])) }">
                                    <x-chess.board class="mt-4 mr-4 max-w-40" />
                                </div>
                                <span class="flex items-center gap-2 text-[13px]"><x-avatar :name="$live->white->displayName()" :src="$live->white->avatarUrl()" :size="18" class="rounded-sm" /><b class="truncate">{{ $live->white->displayName() }}</b><span class="text-ink-2">{{ $rating }}</span></span>
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
                        <button type="button" wire:click="toggleLookingToPlay" role="switch" aria-checked="{{ $user->looking_to_play ? 'true' : 'false' }}" data-test="looking-toggle"
                                class="flex h-11 cursor-pointer items-center gap-2.5 rounded-md border border-line bg-well px-3 text-[13px] text-ink">
                            <span @class(['relative h-5 w-9 rounded-full transition-colors', 'bg-btc' => $user->looking_to_play, 'bg-raised shadow-ring' => ! $user->looking_to_play])><span @class(['absolute top-0.5 size-4 rounded-full bg-ink transition-all', 'left-[18px]' => $user->looking_to_play, 'left-0.5' => ! $user->looking_to_play])></span></span>
                            {{ __('Looking to play') }}
                        </button>
                    @endauth
                </span>
                @guest
                    <p class="m-0 text-[13px] text-ink-2">{{ __('Log in to see who is online and to invite a friend.') }}</p>
                @else
                    <p class="m-0 text-[13px] text-ink-2" x-show="connection !== 'connected'">{{ __('The online list needs the live connection. Connecting …') }}</p>
                    <p class="m-0 text-[13px] text-ink-2" x-show="connection === 'connected' && others.length === 0">{{ __('Nobody else is on the chess pages right now.') }}</p>
                    <ul class="m-0 flex list-none flex-col p-0" x-show="others.length > 0">
                        <template x-for="m in others" :key="m.id">
                            <li class="flex min-h-12 items-center gap-3 border-b border-hairline text-[13px] last:border-0" data-test="online-player">
                                <span class="relative flex size-6 shrink-0 items-center justify-center overflow-hidden rounded-sm bg-[linear-gradient(135deg,#F9B25F,#B9640A)] font-display text-[10px] font-extrabold text-on-btc" aria-hidden="true">
                                    <span x-text="m.name.slice(0, 1).toUpperCase()"></span>
                                    <template x-if="m.avatar"><img :src="m.avatar" alt="" class="absolute inset-0 size-full object-cover" referrerpolicy="no-referrer" onerror="this.remove()"></template>
                                </span>
                                <b class="min-w-0 grow truncate" x-text="m.name"></b>
                                <span x-show="m.looking" class="rounded-sm bg-[#122016] px-2 py-0.5 text-[11px] font-bold text-win">{{ __('looking: Blitz 5+3') }}</span>
                                @if (! $active)
                                    <button type="button" x-on:click="$wire.invite(m.id)" class="btn-w inline-flex h-9 cursor-pointer items-center rounded-md border border-line bg-well px-3 text-[13px] text-ink" data-test="invite">{{ __('Invite') }}</button>
                                @endif
                            </li>
                        </template>
                    </ul>
                @endguest
            </section>
        </div>

        {{-- Later phases keep their places (ChessLobby rows 2 and 3) --}}
        @foreach ([[__('Your daily games'), __('Daily chess, one move a day, comes with the next release.')], [__('Solo Elo'), __('The blitz ladder opens with Season 1. Until then games are casual.')], [__('Clan Hashrate'), __('Rated games of clan players count for their clan from Season 1.')]] as [$heading, $text])
            <section @class(['flex flex-col gap-2 rounded-lg bg-card px-4 py-5 lg:px-6', 'max-lg:hidden' => ! $loop->first])>
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
