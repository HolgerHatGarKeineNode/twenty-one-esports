<?php

use App\Enums\TournamentStatus;
use App\Models\Tournament;
use App\Support\Tournaments\TournamentDesk;
use App\Support\Tournaments\TournamentRunner;
use App\Support\Tournaments\TournamentView;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

/*
 * The live control page of one tournament (user, 2026-10-04: "Die Turnierleitung braucht eine für den Live Betrieb
 * optimierte eigene Seite (nicht die bearbeiten seite) … Disqualifizieren. Turnier-Chat. Live Ansicht Bracket"):
 * everything the direction needs while it runs, on one screen. It holds no logic of its own: the control
 * (components/⚡tournament-control: results, disqualify, pause, restart, call-off, message to all, who blocks what),
 * the desk chat (TournamentDesk, resources/js/deskChat.js) and the public bracket (TournamentView). The route and
 * mount check `manage-tournament`, the control checks it again for every action.
 */
new #[Layout('layouts::app', ['section' => 'tournaments', 'realtime' => true, 'scripts' => ['resources/js/tournamentDesk.js']])] class extends Component {
    public Tournament $tournament;

    public function mount(Tournament $tournament): void
    {
        // The organizer and the admins control it; a named director gets the chat, the bracket and the director desk.
        abort_unless(Gate::any(['manage-tournament', 'direct-tournament'], $tournament), 403);
        abort_if($tournament->isLeagueWeek(), 404);

        $this->tournament = $tournament;
    }

    public function rendering(\Illuminate\View\View $view): void
    {
        $view->title(__('Live control').': '.$this->tournament->title());
    }

    /** A control step changed the bracket: draw it again at once. */
    #[On('tournament-controlled')]
    public function controlled(): void
    {
        $this->tournament->refresh();
        unset($this->stages, $this->round);
    }

    #[Computed]
    public function desk(): ?array
    {
        return TournamentDesk::for($this->tournament, auth()->user());
    }

    #[Computed]
    public function round(): ?\App\Models\TournamentRound
    {
        return $this->tournament->status === TournamentStatus::Running ? TournamentRunner::currentRound($this->tournament) : null;
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

    #[Computed]
    public function canManage(): bool
    {
        return Gate::allows('manage-tournament', $this->tournament);
    }

    /** The director desk only where the directors enter the results (director mode), not where the players report. */
    #[Computed]
    public function canDirect(): bool
    {
        return Gate::allows('direct-tournament', $this->tournament) && $this->tournament->isDirectorMode() && $this->tournament->status === TournamentStatus::Running;
    }
}; ?>

@php
    $tournament = $this->tournament;
    $live = in_array($tournament->status, [TournamentStatus::Signup, TournamentStatus::Drawing, TournamentStatus::Running], true);
    $statusLabel = match ($tournament->status) {
        TournamentStatus::Draft => __('Draft'),
        TournamentStatus::Signup => __('Sign-up open'),
        TournamentStatus::Drawing => __('Draw pending'),
        TournamentStatus::Running => $tournament->isPaused() ? __('Paused') : __('Live now'),
        TournamentStatus::Finished => __('Finished'),
        TournamentStatus::Cancelled => __('Called off'),
    };
@endphp

<div @class(['flex flex-col gap-6 px-4 pb-16 lg:px-12', 'chat-rail-host xl:[--chat-rail-own:3rem]' => $this->desk !== null])
     data-test="tournament-live" @if ($live) wire:poll.15s.visible x-data="tournamentLive({ id: {{ $tournament->id }} })" @endif>
    <header class="flex flex-col gap-3">
        <span class="flex flex-wrap items-center gap-2 text-xs">
            <span @class(['inline-flex items-center gap-1.5 rounded px-2 py-1 font-bold',
                'bg-btc/15 text-btc' => $tournament->status === TournamentStatus::Running && ! $tournament->isPaused(),
                'bg-raised text-ink-2' => $tournament->status !== TournamentStatus::Running || $tournament->isPaused()]) data-test="live-status">
                @if ($tournament->status === TournamentStatus::Running && ! $tournament->isPaused())<span class="size-1.5 rounded-full bg-btc"></span>@endif
                {{ $statusLabel }}
            </span>
            @if ($this->round)
                <span class="rounded bg-raised px-2 py-1 text-ink-2" data-test="live-round">{{ __('Round :number', ['number' => $this->round->number]) }}</span>
            @endif
            <span class="text-ink-3">{{ __('Live control') }}</span>
        </span>
        <h1 class="m-0 font-display text-2xl font-extrabold leading-tight lg:text-3xl">{{ $tournament->title() }}</h1>
        <nav class="flex flex-wrap gap-2" aria-label="{{ __('Tournament') }}" data-test="live-links">
            @if ($this->canDirect)
                <x-button :href="route('tournaments.director', $tournament)" icon="flag" data-test="live-director">{{ __('Director desk') }}</x-button>
            @endif
            <x-button variant="secondary" :href="route('tournaments.show', $tournament)" icon="tournaments" data-test="live-public">{{ __('Tournament page') }}</x-button>
            <x-button variant="secondary" :href="route('tournaments.tv', $tournament)" icon="expand" target="_blank" data-test="live-tv">{{ __('TV view') }}</x-button>
            @if ($this->canManage)
                <x-button variant="quiet" :href="route('admin.tournaments.edit', $tournament)" icon="settings" data-test="live-edit">{{ __('Edit') }}</x-button>
            @endif
        </nav>
    </header>

    @if ($this->desk)
        <div class="chat-rail xl:mx-0" data-test="desk-rail"><x-tournaments.desk-chat :desk="$this->desk" /></div>
    @endif

    <section id="bracket" aria-labelledby="live-bracket-h" class="flex flex-col gap-3" data-test="live-bracket">
        <h2 id="live-bracket-h" class="m-0 text-[15px] font-bold">{{ __('Bracket') }}</h2>
        @if ($this->stages !== [])
            @include('pages.tournaments.partials.stages', ['stages' => $this->stages, 'tournament' => $tournament])
        @else
            <p class="m-0 rounded-lg bg-card px-4 py-5 text-[13px] text-ink-2 lg:px-6" data-test="live-no-bracket">{{ __('The bracket appears here once it is drawn.') }}</p>
        @endif
    </section>

    @if ($this->canManage && $tournament->status !== TournamentStatus::Draft)
        {{-- Results, disqualifications, pause, restarts, call-off, message to all players, who blocks what. --}}
        <livewire:tournament-control :tournament="$tournament" :wire:key="'live-control-'.$tournament->id" />
    @endif
</div>
