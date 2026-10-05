<?php

use App\Models\Tournament;
use App\Models\User;
use App\Support\Series\SeriesPresenter;
use App\Support\Tournaments\MatchWait;
use App\Support\Tournaments\TournamentReminders;
use App\Support\Tournaments\TournamentRuleViolation;
use App\Support\Tournaments\TournamentWaits;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/*
 * "Who blocks what" (P18, slice 5), in the control section of a running
 * tournament for admins and the tournament's organizer (gate
 * `manage-tournament`, checked on mount and again in TournamentReminders):
 * every open match with what it waits for, on whom, since when, and the
 * countdown to the league's automatic decision (TournamentWaits), most
 * urgent first. One click reminds a waited-on player (rate-limited, in the
 * moderation log). Refreshed every 30 seconds while visible.
 */
new class extends Component {
    #[Locked]
    public Tournament $tournament;

    public string $error = '';

    public string $notice = '';

    public function mount(Tournament $tournament): void
    {
        Gate::authorize('manage-tournament', $tournament);

        $this->tournament = $tournament;
    }

    /**
     * @return list<MatchWait>
     */
    #[Computed]
    public function waits(): array
    {
        return TournamentWaits::of($this->tournament->refresh());
    }

    /**
     * The lobby check-in of each waiting series (user, 2026-10-04), by tournament match id.
     *
     * @return array<int, \App\Models\SeriesMatch>
     */
    #[Computed]
    public function checkIns(): array
    {
        $ids = array_map(fn (MatchWait $wait): int => $wait->matchId, $this->waits);

        return $ids === [] ? [] : \App\Models\SeriesMatch::query()->whereIn('tournament_match_id', $ids)->where('status', \App\Enums\SeriesStatus::Accepted)
            ->get(['id', 'tournament_match_id', 'challenger_name', 'challenged_name', 'ready_at_challenger', 'ready_at_challenged', 'origin'])
            ->reject(fn (\App\Models\SeriesMatch $series): bool => $series->isCasualPairing())->keyBy('tournament_match_id')->all();
    }

    #[On('tournament-controlled')]
    public function refreshWaits(): void
    {
        unset($this->waits);
    }

    public function remind(int $matchId, int $userId): void
    {
        $this->error = $this->notice = '';
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        try {
            app(TournamentReminders::class)->remind($this->tournament, $user, $matchId, $userId);
            $this->notice = __('Reminder sent.');
            $this->dispatch('tournament-reminded');
        } catch (TournamentRuleViolation $violation) {
            $this->error = $violation->getMessage();
        }

        unset($this->waits);
    }
}; ?>

@php
    $viewer = auth()->user();
    $waits = $this->waits;
@endphp

<div class="flex flex-col gap-2 rounded-md bg-ground p-3" wire:poll.30s.visible data-test="waits">
    <span class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
        <h3 class="m-0 text-[13px] font-bold">{{ __('Who blocks what') }}</h3>
        <span class="text-xs text-ink-3">{{ __('Most urgent first. The league decides on its own when a countdown runs out.') }}</span>
    </span>

    @if ($notice !== '')
        <p class="m-0 text-[13px] text-win" role="status" data-test="waits-notice">{{ $notice }}</p>
    @endif
    @if ($error !== '')
        <p class="m-0 text-[13px] text-loss" role="alert" data-test="waits-error">{{ $error }}</p>
    @endif

    @if ($waits === [])
        <p class="m-0 text-[13px] text-ink-2" data-test="waits-empty">{{ __('No match is open right now.') }}</p>
    @else
        <ul class="m-0 flex list-none flex-col gap-2 p-0">
            @foreach ($waits as $wait)
                <li @class(['flex min-w-0 flex-col gap-1.5 rounded-md bg-raised px-3 py-2.5 text-[13px]', 'shadow-[inset_0_0_0_1px_#5A2A2E]' => $wait->needsAdmin])
                    wire:key="wait-{{ $wait->matchId }}" data-test="wait" data-state="{{ $wait->state }}" data-match="{{ $wait->matchId }}">
                    <span class="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1">
                        <a href="{{ $wait->url }}" class="shrink-0 text-[11px] font-bold text-ink-3">{{ $wait->label }}</a>
                        <span class="min-w-0 grow [overflow-wrap:anywhere]">{{ $wait->sides[0] }} <span class="text-ink-3">{{ __('vs') }}</span> {{ $wait->sides[1] }}</span>
                        <span @class(['inline-flex h-6 shrink-0 items-center rounded-xs px-2 text-xs font-bold',
                            'bg-loss-tint text-loss' => $wait->needsAdmin,
                            'bg-btc-chip text-btc-hi' => ! $wait->needsAdmin && $wait->waitingOn !== [],
                            'bg-well text-ink-2' => ! $wait->needsAdmin && $wait->waitingOn === []]) data-test="wait-state">{{ $wait->stateLabel() }}</span>
                    </span>

                    @if ($wait->decidesAt !== null)
                        <x-tournaments.auto-decision :wait="$wait" class="text-xs text-ink-2" />
                    @elseif ($wait->consequence !== null)
                        <span class="text-xs {{ $wait->needsAdmin ? 'font-bold text-loss' : 'text-ink-2' }}" data-test="wait-consequence">{{ $wait->needsAdmin ? __('Needs you: :what', ['what' => $wait->consequenceText()]) : $wait->consequenceText() }}</span>
                    @endif

                    @if ($checkIn = $this->checkIns[$wait->matchId] ?? null)
                        {{-- One chip per side: a check mark and the time when in, a clock when not (never colour alone). --}}
                        <span class="flex min-w-0 flex-wrap items-center gap-2 text-xs" data-test="wait-checkin">
                            <span class="text-ink-3">{{ __('Lobby check-in') }}</span>
                            @foreach (\App\Models\SeriesMatch::SIDES as $checkSide)
                                @php $checkedAt = $checkIn->readyAt($checkSide); @endphp
                                <span @class(['inline-flex min-w-0 max-w-full items-start gap-1.5 rounded-xs px-2 py-1', 'bg-win-tint text-win' => $checkedAt, 'bg-btc-chip text-btc-hi' => ! $checkedAt]) data-test="wait-checkin-{{ $checkSide }}">
                                    <x-icon :name="$checkedAt ? 'check' : 'clock'" :size="12" class="mt-0.5 shrink-0" />
                                    <span class="min-w-0 [overflow-wrap:anywhere]"><b>{{ $checkSide === 'challenger' ? $checkIn->challenger_name : $checkIn->challenged_name }}</b>
                                        {{ $checkedAt ? __('in since :time', ['time' => SeriesPresenter::time($checkedAt, $viewer, 'H:i')]) : __('not in yet') }}</span>
                                </span>
                            @endforeach
                        </span>
                    @endif

                    @if ($wait->waitingOn !== [] || $wait->since !== null)
                        <span class="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1.5 text-xs text-ink-2">
                            @if ($wait->since !== null)
                                <span class="text-ink-3" data-test="wait-since">{{ __('since :time (:minutes min)', ['time' => SeriesPresenter::time($wait->since, $viewer, 'H:i'), 'minutes' => max(0, (int) floor($wait->since->diffInMinutes(now())))]) }}</span>
                            @endif
                            @if ($wait->waitingOn !== [])
                                <span>{{ __('Waiting on') }}</span>
                                @foreach ($wait->waitingOn as $player)
                                    <span class="inline-flex min-w-0 items-center gap-1.5" wire:key="wait-{{ $wait->matchId }}-{{ $player['user_id'] }}">
                                        <b class="min-w-0 [overflow-wrap:anywhere]" data-test="wait-player">{{ $player['name'] }}</b>
                                        @if ($wait->action !== null)
                                            <x-button variant="quiet" wire:click="remind({{ $wait->matchId }}, {{ $player['user_id'] }})" class="h-9 px-3" data-test="wait-remind-{{ $player['user_id'] }}">{{ __('Remind') }}</x-button>
                                        @endif
                                    </span>
                                @endforeach
                            @endif
                        </span>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</div>
