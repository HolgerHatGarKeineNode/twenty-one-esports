<?php

use App\Enums\TournamentStatus;
use App\Models\Tournament;
use App\Models\TournamentSignup;
use App\Models\User;
use App\Support\LeagueTime;
use App\Support\Tournaments\TournamentRuleViolation;
use App\Support\Tournaments\TournamentStartVote;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/*
 * The start vote on the tournament page (TournamentStartVote, DerCaddy
 * 2026-11-09), in the hero under the main action:
 *
 * - while it is open, every offered start with the number of entries that can
 *   make it; the person who votes for an entry (the solo player, a lineup's
 *   captain) ticks the starts that suit it, each tick saved at once;
 * - in a draft, the organizer's preview: the starts players will vote on;
 * - once settled and while sign-up is open, one line: the start the vote set
 *   and how many entries can make it, and to an entry whose ticks do not
 *   include it (a non-voter's: the earliest start), until when it can withdraw.
 *
 * An entry without a tick counts for the earliest start (TournamentStartVote::fitsOf()).
 *
 * Nothing for a tournament without a vote, or once sign-up has closed.
 */
new class extends Component {
    #[Locked]
    public int $tournamentId;

    public string $error = '';

    public function mount(int $tournament): void
    {
        $this->tournamentId = $tournament;
    }

    #[Computed]
    public function tournament(): ?Tournament
    {
        return Tournament::query()->find($this->tournamentId);
    }

    #[Computed]
    public function entry(): ?TournamentSignup
    {
        $tournament = $this->tournament;
        $user = auth()->user();

        return $tournament !== null && $user instanceof User ? app(TournamentStartVote::class)->entryOf($tournament, $user) : null;
    }

    /** @return array{counts: array<string, int>, voted: int, entries: int, silent: int} */
    #[Computed]
    public function tally(): array
    {
        return $this->tournament === null ? ['counts' => [], 'voted' => 0, 'entries' => 0, 'silent' => 0] : TournamentStartVote::tally($this->tournament);
    }

    public function toggle(string $option): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $this->tournament !== null, 403);

        $this->error = '';

        try {
            app(TournamentStartVote::class)->toggle($this->tournament, $user, $option);
        } catch (TournamentRuleViolation $violation) {
            $this->error = $violation->getMessage();
        }

        unset($this->tournament, $this->entry, $this->tally);
    }
}; ?>

@php
    $tournament = $this->tournament;
    $status = $tournament?->status;
    $shown = $tournament !== null && $tournament->start_options !== null && in_array($status, [TournamentStatus::Draft, TournamentStatus::Signup], true)
        && ($tournament->hasOpenStartVote() || $tournament->isSignupOpen());
@endphp

{{-- `contents`: with nothing to show the component takes no room in the hero's flex column. --}}
<div class="contents">
    @if ($shown)
        @php
            $entry = $this->entry;
            $tally = $this->tally;
            $open = TournamentStartVote::isOpen($tournament);
            $fits = $entry?->start_fits ?? [];
            $earliest = __('An entry that does not vote counts for the earliest start.');
        @endphp
        @if ($tournament->hasOpenStartVote())
            <section aria-labelledby="start-vote-h" class="flex max-w-[640px] flex-col gap-3 rounded-card bg-card px-4 py-4 shadow-[inset_0_0_0_1px_var(--color-btc)] lg:px-5" data-test="start-vote" data-state="{{ $status === TournamentStatus::Draft ? 'draft' : ($open ? 'open' : 'closing') }}">
                <span class="flex flex-col gap-1">
                    <h2 id="start-vote-h" class="m-0 text-[15px] font-bold">{{ $entry !== null && $open ? __('When can you play?') : __('The start is put to a vote') }}</h2>
                    <span class="text-[13px] leading-normal text-ink-2" data-test="start-vote-rule">{{ match (true) {
                        $status === TournamentStatus::Draft => __('Once you publish, the entries tick every start they can make until :time. Then the start most of them can make is set.', ['time' => LeagueTime::stampUnbroken($tournament->start_vote_until)]),
                        ! $open => __('The vote has closed. The league sets the start in a moment.'),
                        $entry !== null => __('Tick every start you can make, until :time. Then the start most entries can make is set; on a tie the earlier one.', ['time' => LeagueTime::stampUnbroken($tournament->start_vote_until)]).' '.$earliest,
                        default => __('The entries tick every start they can make, until :time. Then the start most of them can make is set.', ['time' => LeagueTime::stampUnbroken($tournament->start_vote_until)]).' '.$earliest,
                    } }}</span>
                </span>
                <ul role="list" class="m-0 flex list-none flex-col gap-1 p-0" data-test="start-vote-options">
                    @foreach (TournamentStartVote::options($tournament) as $index => $at)
                        @php
                            $key = TournamentStartVote::key($at);
                            $count = $tally['counts'][$key] ?? 0;
                        @endphp
                        {{-- The row wraps (the count under the start) rather than spill out of the card at 320 px; the clock keeps
                             its "8:00 PM CET" together (LeagueTime::stampUnbroken()). --}}
                        <li class="flex min-h-11 flex-wrap items-center gap-x-3 gap-y-1" wire:key="start-option-{{ $key }}" data-option="{{ $key }}">
                            @if ($entry !== null && $open)
                                <x-ui.check :checked="in_array($key, $fits, true)" wire:click="toggle('{{ $key }}')" wire:loading.attr="aria-disabled" wire:loading.class="pointer-events-none" data-test="start-vote-tick"><time datetime="{{ $at->format('Y-m-d\TH:i:s\Z') }}" class="text-[13px] text-ink">{{ LeagueTime::stampUnbroken($at) }}</time></x-ui.check>
                            @else
                                <time datetime="{{ $at->format('Y-m-d\TH:i:s\Z') }}" class="min-w-0 text-[13px] text-ink">{{ LeagueTime::stampUnbroken($at) }}</time>
                            @endif
                            @if ($status !== TournamentStatus::Draft)
                                <span class="ml-auto flex shrink-0 flex-col items-end text-xs text-ink-2 tabular-nums" data-test="start-vote-count">{{ trans_choice(':count can make it|:count can make it', $count) }}
                                    {{-- The earliest start's count holds the entries without a tick (TournamentStartVote::fitsOf()): said, so a tick
                                         elsewhere never changes this row without a reason. --}}
                                    @if ($index === 0 && $tally['silent'] > 0)
                                        <span data-test="start-vote-silent">{{ __('incl. :count without a vote', ['count' => $tally['silent']]) }}</span>
                                    @endif
                                </span>
                            @endif
                        </li>
                    @endforeach
                </ul>
                @if ($status !== TournamentStatus::Draft)
                    <span class="text-xs text-ink-2" data-test="start-vote-turnout">{{ trans_choice(':voted of :count entry has voted.|:voted of :count entries have voted.', $tally['entries'], ['voted' => $tally['voted']]) }}@if ($entry !== null && $open && ! TournamentStartVote::hasVoted($entry, $tournament->start_options ?? [])) {{ __('Yours has not yet.') }}@endif</span>
                @endif
                @if ($error !== '')
                    <p class="m-0 text-[13px] text-loss" role="alert">{{ $error }}</p>
                @endif
            </section>
        @elseif (TournamentStartVote::settledStart($tournament) === TournamentStartVote::key($tournament->starts_at))
            {{-- Settled, sign-up still open, the start still the one the vote set (not moved by hand since): that start, and an
                 entry whose ticks do not include it can still withdraw. --}}
            @php($winner = TournamentStartVote::key($tournament->starts_at))
            <p class="m-0 max-w-[75ch] text-[13px] leading-normal text-ink-2" data-test="start-vote-settled">
                {{ trans_choice('The vote set :time: :count entry can make it.|The vote set :time: :count entries can make it.', $tally['counts'][$winner] ?? 0, ['time' => LeagueTime::stampUnbroken($tournament->starts_at)]) }}
                @if ($entry !== null && TournamentStartVote::missedBy($tournament, $entry))
                    <b class="text-ink" data-test="start-vote-cannot">{{ __('Your entry did not tick this start. If you cannot play then, withdraw it by :time.', ['time' => LeagueTime::stampUnbroken($tournament->signup_closes_at)]) }}</b>
                @endif
            </p>
        @endif
    @endif
</div>
