<?php

use App\Models\SeriesMatch;
use App\Models\User;
use App\Support\Dock\OpenMatches;
use App\Support\Series\CasualInvites;
use App\Support\Series\CasualLobby;
use App\Support\Series\CasualMatches;
use App\Support\Series\CasualQueue;
use App\Support\Series\CasualScheduler;
use App\Support\Series\SeriesRuleViolation;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/*
 * The ready check of a casual 1v1 (P23 S3), on every page of a logged-in
 * player: once a pairing gives them a match, a sheet takes the whole
 * attention with both seats and the 60 s clock. Both ready, the page moves
 * to the match room; the clock runs out, the sheet says who missed it and
 * whether this player searches again (CasualScheduler::voidUnready puts the
 * ready one back at the front of the queue).
 *
 * The one poller of casual play: while the player searches, waits for an
 * answer to an invite, or sits in a ready check, it asks every POLL_SECONDS
 * (and pairs a waiting player, like the chess lobby's pollQueue). Pushes on
 * the player's channel make it ask at once. Without either state it renders
 * an empty element and polls nothing. The room of the match itself shows
 * its own Ready step instead of the sheet.
 */
new class extends Component
{
    public const POLL_SECONDS = 5;

    /** The series on screen (the room): its ready check is the room's. */
    #[Locked]
    public ?int $onScreen = null;

    /** The match number the sheet showed, so its start moves the page into its room. */
    #[Locked]
    public ?int $prompted = null;

    public string $error = '';

    public function mount(): void
    {
        $this->onScreen = OpenMatches::onScreen(request())['series'];
    }

    /** A push, the module's own action or the sheet's clock: look again, and pair a waiting player. */
    #[On('casual-changed')]
    public function check(): void
    {
        $user = $this->user();
        $queue = app(CasualQueue::class);

        if ($queue->entryOf($user) !== null) {
            $queue->pair($user);
        }

        $match = app(CasualLobby::class)->readyCheckOf($user);

        if ($match !== null && $match->ready_by?->isPast()) {
            app(CasualScheduler::class)->voidUnready($match);
        }

        // The module on the page shows the same state (searching, invited): it looks again too.
        $this->dispatch('casual-refresh');
        $this->enterStarted();
    }

    public function ready(): void
    {
        $this->error = '';
        $match = app(CasualLobby::class)->readyCheckOf($this->user());

        if ($match === null) {
            $this->enterStarted();

            return;
        }

        try {
            $match = app(CasualMatches::class)->ready($match, $this->user());
        } catch (SeriesRuleViolation $refused) {
            $this->error = $refused->getMessage();

            return;
        }

        $this->enterStarted($match);
    }

    /** The missed message is read: gone until the next one. */
    public function dismiss(bool $stopSearching = false): void
    {
        $missed = app(CasualLobby::class)->missedReadyCheckOf($this->user());

        if ($missed !== null) {
            session()->put('casual.missed_seen', $missed->number);
        }

        if ($stopSearching) {
            app(CasualQueue::class)->leave($this->user());
        }

        $this->prompted = null;
        $this->dispatch('casual-refresh');
    }

    /** The match the sheet showed has started: into its room. */
    private function enterStarted(?SeriesMatch $match = null): void
    {
        $match ??= $this->prompted === null ? null : SeriesMatch::query()->where('number', $this->prompted)->first();

        // Once per match: a push and a poll both in flight gave a second redirect, which reloaded the room just opened.
        if ($match !== null && $match->isCasualPairing() && $match->start_at !== null && ! $match->status->hasResult() && $match->number !== $this->onScreen
            && session('casual.entered.'.$this->user()->id) !== $match->number) {
            $this->prompted = null;
            session()->put('casual.entered.'.$this->user()->id, $match->number);
            $this->redirectRoute('matches.room', $match);
        }
    }

    /**
     * What the view shows, read once per render: the ready check (not the
     * one of the room on screen), else a missed one not yet dismissed, and
     * whether anything is waiting (then the element polls).
     */
    public function rendering(\Illuminate\View\View $view): void
    {
        $user = $this->user();
        $lobby = app(CasualLobby::class);
        $match = $lobby->readyCheckOf($user);
        $match = $match !== null && $match->number === $this->onScreen ? null : $match;
        $missed = $match === null ? $lobby->missedReadyCheckOf($user) : null;
        $missed = $missed !== null && $missed->number !== session('casual.missed_seen') && $missed->number !== $this->onScreen ? $missed : null;
        $searching = app(CasualQueue::class)->entryOf($user) !== null;

        if ($match !== null) {
            $this->prompted = $match->number;
        }

        $view->with([
            'match' => $match,
            'missed' => $missed,
            'searching' => $searching,
            'polling' => $match !== null || $searching || app(CasualInvites::class)->outgoing($user) !== null,
        ]);
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}; ?>

@php
    $user = auth()->user();
@endphp

<div
     x-data="casualWatch({{ $user->id }}, {{ $this::POLL_SECONDS }})"
     data-test="casual-ready-root" data-polling="{{ $polling ? '1' : '0' }}">
    @if ($match !== null)
        @php
            $side = $match->isRosterSideMember('challenger', $user) ? 'challenger' : 'challenged';
            $other = SeriesMatch::otherSide($side);
            $opponent = User::query()->find($match->rosterSide($other)[0] ?? 0);
            $meReady = $match->readyAt($side) !== null;
            $themReady = $match->readyAt($other) !== null;
            $total = $match->casualSetting('ready_seconds') * 1000;
            $config = [
                'userId' => $user->id,
                'readyBy' => (int) $match->ready_by?->getTimestampMs(),
                'total' => max(1, $total),
                'serverNow' => (int) now()->getTimestampMs(),
                'messages' => App\Support\Nostr\SignerMessages::labels() + ['noNip44' => __('Your signer cannot encrypt messages (NIP-44), so you cannot get the lobby. Use a Nostr extension or signer app with NIP-44 to play casual 1v1.')],
            ];
            $gameName = App\Support\GameNames::game($match->game);
        @endphp
        {{-- Full attention: nothing else on the page matters for these 60 seconds. --}}
        <div wire:key="ready-{{ $match->number }}" class="fixed inset-0 z-[60] flex items-end justify-center bg-[rgba(10,10,11,.82)] backdrop-blur-[2px] lg:items-center"
             x-data="casualReadyPrompt(@js($config))" data-test="ready-prompt" data-match="{{ $match->number }}">
            <section role="dialog" aria-modal="true" aria-labelledby="ready-h" aria-describedby="ready-d" x-trap.noscroll="true"
                     class="dk-sheet flex max-h-[100svh] w-full flex-col gap-5 overflow-y-auto rounded-t-2xl bg-card px-4 pt-5 pb-6 shadow-[0_0_0_1px_var(--color-btc-ring),0_-24px_48px_rgba(10,10,11,.6)] lg:max-w-[560px] lg:rounded-lg lg:px-8 lg:py-8"
                     data-test="ready-sheet">
                <div class="flex items-center gap-3">
                    <x-game-cover :game="$match->game" size="thumb" loading="eager" class="w-14 shrink-0 rounded-sm shadow-ring" />
                    <span class="flex min-w-0 flex-col gap-0.5">
                        <h2 id="ready-h" class="m-0 font-display text-xl leading-tight font-bold lg:text-2xl">{{ __('Match found') }}</h2>
                        <span class="truncate text-xs text-ink-2">{{ __(':game 1v1, casual, best of :n', ['game' => $gameName, 'n' => $match->best_of]) }}</span>
                    </span>
                </div>

                {{-- The two seats: each lights up when its player pressed Ready. --}}
                <div class="grid grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] items-center gap-3" data-test="ready-seats">
                    @foreach ([[$user, $meReady, true], [$opponent, $themReady, false]] as $index => [$player, $isReady, $isMe])
                        @if ($index === 1)
                            <span class="font-display text-sm font-bold text-ink-3" aria-hidden="true">vs</span>
                        @endif
                        <span @class(['flex min-w-0 flex-col items-center gap-2 rounded-lg px-2 py-3 text-center', 'bg-win-tint shadow-[inset_0_0_0_1px_var(--color-win-ring)]' => $isReady, 'bg-well shadow-ring' => ! $isReady])
                              data-test="ready-seat-{{ $isMe ? 'me' : 'them' }}" data-ready="{{ $isReady ? '1' : '0' }}">
                            <x-avatar :user="$player" :size="56" class="rounded-md" />
                            <b class="w-full truncate text-[13px]">{{ $isMe ? __('You') : $player?->displayName() }}</b>
                            <span @class(['inline-flex items-center gap-1 text-xs font-bold', 'text-win' => $isReady, 'text-ink-2' => ! $isReady])>
                                @if ($isReady)<x-icon name="check" :size="14" />{{ __('Ready') }}@else{{ __('Not ready yet') }}@endif
                            </span>
                        </span>
                    @endforeach
                </div>

                {{-- The clock: one big number and a fuse that burns down. --}}
                <div class="flex flex-col gap-2" role="timer" aria-live="off">
                    <span class="flex items-baseline justify-between gap-3">
                        <span id="ready-d" class="text-[13px] leading-normal text-ink-2">{{ $meReady ? __('Waiting for :name. The match room opens when both are ready.', ['name' => $opponent?->displayName() ?? '']) : __('Press Ready within :seconds seconds. The match room opens when both are ready.', ['seconds' => $match->casualSetting('ready_seconds')]) }}</span>
                        <b class="font-display text-[40px] leading-none font-bold tabular-nums lg:text-5xl" x-bind:class="urgent ? 'text-loss' : 'text-ink'" x-text="left" data-test="ready-clock">{{ App\Support\Dock\OpenMatches::format((int) $match->ready_by?->getTimestampMs() - (int) now()->getTimestampMs(), 'clock') }}</b>
                    </span>
                    <span class="block h-1.5 overflow-hidden rounded-xs bg-raised" aria-hidden="true">
                        <span class="block h-1.5 rounded-xs" x-bind:class="urgent ? 'bg-loss' : 'bg-btc'" x-bind:style="{ width: (share * 100) + '%' }"></span>
                    </span>
                </div>

                @if ($meReady)
                    <p class="m-0 flex min-h-14 items-center justify-center gap-2 rounded-md bg-win-tint text-[15px] font-bold text-win" role="status" data-test="ready-done">
                        <x-icon name="check" :size="18" />{{ __('You are ready') }}
                    </p>
                @else
                    <button type="button" x-ref="ready" x-on:click="ready()" x-bind:disabled="busy" data-test="ready-button"
                            class="btn-p inline-flex min-h-14 w-full cursor-pointer items-center justify-center gap-2 rounded-md bg-btc font-display text-lg font-bold text-on-btc disabled:cursor-wait disabled:opacity-70">
                        <x-icon name="check" :size="20" />{{ __('Ready') }}
                    </button>
                @endif
                <p x-show="error" x-text="error" x-cloak class="m-0 text-[13px] text-loss" role="alert" data-test="ready-error"></p>
                @if ($error)
                    <p class="m-0 text-[13px] text-loss" role="alert">{{ $error }}</p>
                @endif
            </section>
        </div>
    @elseif ($missed !== null)
        @php
            $side = $missed->isRosterSideMember('challenger', $user) ? 'challenger' : 'challenged';
            $other = SeriesMatch::otherSide($side);
            $iMissed = $missed->readyAt($side) === null;
            $opponentName = $missed->sideName($other);
            $text = match (true) {
                $iMissed && $missed->readyAt($other) === null => __('Neither of you pressed Ready in time, so the match is off.'),
                $iMissed => __('You did not press Ready in time, so the match is off and you left the queue.'),
                $searching => __(':name did not press Ready in time. You are back in the queue, first in line.', ['name' => $opponentName]),
                default => __(':name did not press Ready in time, so the match is off.', ['name' => $opponentName]),
            };
        @endphp
        <div wire:key="missed-{{ $missed->number }}" class="fixed inset-0 z-[60] flex items-end justify-center bg-[rgba(10,10,11,.82)] lg:items-center" data-test="ready-missed">
            <section role="alertdialog" aria-modal="true" aria-labelledby="missed-h" aria-describedby="missed-d" x-data x-trap.noscroll="true"
                     class="dk-sheet flex w-full flex-col gap-4 rounded-t-2xl bg-card px-4 pt-5 pb-6 shadow-[0_0_0_1px_var(--color-line)] lg:max-w-[480px] lg:rounded-lg lg:px-8 lg:py-7">
                <span class="flex items-center gap-3">
                    <span class="flex size-10 shrink-0 items-center justify-center rounded-md bg-loss-tint text-loss"><x-icon name="clock" :size="20" /></span>
                    <h2 id="missed-h" class="m-0 font-display text-lg leading-tight font-bold">{{ __('Ready check missed') }}</h2>
                </span>
                <p id="missed-d" class="m-0 text-[13px] leading-normal text-ink-2" data-test="ready-missed-text">{{ $text }}</p>
                <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                    @if ($searching)
                        <x-button variant="quiet" wire:click="dismiss(true)" data-test="missed-stop">{{ __('Stop searching') }}</x-button>
                        <x-button wire:click="dismiss" data-test="missed-ok">{{ __('Keep searching') }}</x-button>
                    @else
                        <x-button wire:click="dismiss" class="sm:col-span-2" data-test="missed-ok">{{ __('OK') }}</x-button>
                    @endif
                </div>
            </section>
        </div>
    @endif
</div>
