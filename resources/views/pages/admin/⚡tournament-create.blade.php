<?php

use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Livewire\Concerns\EditsPrizePot;
use App\Livewire\TournamentFormatChooser;
use App\Models\Tournament;
use App\Models\User;
use App\Support\LeagueTime;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\TournamentRuleViolation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;

/*
 * AdminTournamentCreate (AdminTournamentCreate.dc.html + Mobile, P8a): a new
 * tournament with the format chooser. The organizer enters who plays, the
 * game and mode, the time and where; every format is estimated with
 * App\Support\Tournaments\Estimator (TOURNAMENT-FORMATS.md), one is
 * recommended, and the selected one is explained with a mini preview built
 * from the real bracket engine. The chooser's state and actions live in
 * App\Livewire\TournamentFormatChooser (the edit page shares them).
 *
 * The chooser and the results section are one island: changing an input
 * re-renders only them (and the summary island), never the page. Creating
 * saves a draft; sign-up and publishing follow on the tournament page.
 *
 * The prize pot is optional and off by default (P9 scope addition,
 * App\Livewire\Concerns\EditsPrizePot): a draft with a pot is created
 * only when the pot is valid too (a wallet connection is checked live,
 * before the transaction that writes the draft), and it opens when the
 * tournament is published.
 */
new #[Title('New tournament')] #[Layout('layouts::app', ['section' => 'admin'])] class extends TournamentFormatChooser {
    use EditsPrizePot;

    public string $name = '';

    public string $description = '';

    /** The start as `Y-m-d\TH:i` in the league's zone (LeagueTime). */
    public string $startsAt = '';

    public function mount(): void
    {
        Gate::authorize('create-tournaments');

        $this->startsAt = now()->addWeek()->timezone(LeagueTime::zone())->format('Y-m-d').'T19:00';
        $this->options = FormatOptions::defaults($this->profile())->toArray();
        $this->fillPot(null);
    }

    protected function potTournament(): ?Tournament
    {
        return null;
    }

    public function chooserCreator(): ?User
    {
        return auth()->user();
    }

    public function create(): void
    {
        Gate::authorize('create-tournaments');

        $this->validate([
            'name' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:1000'],
            'startsAt' => ['required', LeagueTime::rule()],
            'players' => ['required', 'integer', 'between:2,64'],
            'stations' => ['integer', 'between:1,32'],
            'gameLength' => ['nullable', 'numeric', 'between:0.5,1000'],
            'setup' => ['nullable', 'numeric', 'between:0,1000'],
            'break' => ['nullable', 'numeric', 'between:0,1000'],
            ...$this->deadlineRules(),
        ]);

        // Typed in the league's zone, whoever types it (the admin's own zone never applies); stored as UTC.
        $startsAt = LeagueTime::parse($this->startsAt);

        if ($startsAt->isPast()) {
            $this->addError('startsAt', __('Pick a start in the future.'));

            return;
        }

        $format = $this->format;

        if (! $this->chosenFormatRuns()) {
            $this->addError('format', __('Pick a format that can run with these settings.'));

            return;
        }

        $profile = $this->profile();

        // The wallet is asked before the transaction: its calls take up to a minute and must not hold SQLite's write lock (re-gate O1).
        if (! $this->checkNewPotWallet()) {
            return;
        }

        // The draft and its pot are one step: a refused pot creates nothing.
        try {
            $tournament = DB::transaction(function () use ($profile, $format, $startsAt): Tournament {
                $tournament = Tournament::query()->create([
                    'name' => $this->name,
                    'description' => trim($this->description) === '' ? null : trim($this->description),
                    'game' => $profile->game,
                    'mode' => $profile->mode,
                    'format' => $format,
                    'options' => $this->chosenOptions()->toArray(),
                    'capacity' => $this->count(),
                    'starts_at' => $startsAt,
                    'time_window' => $this->window,
                    'on_site' => $this->stationLimit() !== null,
                    'stations' => $this->stationLimit(),
                    'times' => $this->chosenTimes(),
                    'results_mode' => TournamentResultsMode::from($this->resultsMode),
                    'status' => TournamentStatus::Draft,
                    'created_by_id' => auth()->id(),
                    ...$this->chosenDeadlines(),
                ]);

                if ($tournament->results_mode === TournamentResultsMode::Director) {
                    $tournament->directors()->attach(User::query()->whereKey($this->directorIds)->pluck('id')->all(), ['added_by_id' => auth()->id()]);
                }

                if ($this->potEnabled && ! $this->savePot($tournament)) {
                    throw new TournamentRuleViolation('pot', $this->potError);
                }

                return $tournament;
            });
        } catch (TournamentRuleViolation) {
            return;
        }

        session()->flash('status', __(':name was created as a draft.', ['name' => $tournament->name]));

        // Straight onto the draft, whose banner at the top holds the publish form (user, 2026-10-01).
        $this->redirectRoute('tournaments.show', $tournament, navigate: false);
    }
}; ?>

@php
    $isAdmin = (bool) auth()->user()?->can('admin');
@endphp

<x-admin.page active="tournaments" :title="__('New tournament')" :lead="__('Draft. Players see it once you publish.')" :crumbs="[[__('Tournaments'), route('admin.tournaments')]]" data-test="tournament-create">

    <section aria-label="{{ __('Basics') }}" class="grid grid-cols-2 gap-3 rounded-lg bg-card p-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)] lg:gap-4 lg:px-6 lg:py-5">
        <label class="col-span-2 flex flex-col gap-1.5 text-xs text-ink-2 lg:col-span-1">
            {{ __('Name') }}
            <input wire:model="name" maxlength="80" placeholder="{{ __('Blitz Night Munich') }}" data-test="tournament-name"
                   class="h-11 w-full rounded-md border border-edge bg-ground px-3 text-[13px] text-ink">
            @error('name')<span class="text-loss" role="alert">{{ $message }}</span>@enderror
        </label>
        <x-berlin-datetime-input model="startsAt" :label="__('Starts')" :value="$startsAt" test="create-starts-at" class="col-span-2" />
        <label class="col-span-2 flex flex-col gap-1.5 text-xs text-ink-2 lg:col-span-3">
            {{ __('Description (optional)') }}
            <textarea wire:model="description" maxlength="1000" rows="3" data-test="tournament-description"
                      placeholder="{{ __('What makes this one worth playing: the stakes, the stream, the after-party.') }}"
                      class="min-h-24 w-full rounded-md border border-edge bg-ground px-3 py-2.5 text-[13px] leading-normal text-ink"></textarea>
            <span class="text-ink-3">{{ __('Shown at the top of the tournament page and published with it on Nostr. Up to 1000 characters.') }}</span>
            @error('description')<span class="text-loss" role="alert">{{ $message }}</span>@enderror
        </label>
    </section>

    @island(name: 'chooser')
        @include('pages.admin.partials.tournament-chooser')
    @endisland

    @include('pages.admin.partials.tournament-deadlines')

    @include('pages.admin.partials.prize-pot', ['potTournament' => null, 'potSave' => null])

    <div class="flex flex-col gap-3 rounded-lg bg-card px-4 py-4 lg:flex-row lg:items-center lg:gap-4 lg:px-6 lg:py-5">
        @island(name: 'summary')
            @php
                $summaryRow = $this->evaluation->row($this->format);
                $summaryProfile = $this->profile();
            @endphp
            <span class="flex flex-col gap-1" data-test="tournament-summary">
                <b class="text-sm">{{ $this->format->label() }}@if ($this->format === \App\Enums\TournamentFormat::Swiss), {{ trans_choice(':count round|:count rounds', $this->evaluation->swissRounds) }}@endif,
                    {{ $summaryProfile->entersTeams() ? trans_choice(':count team|:count teams', $this->count()) : trans_choice(':count player|:count players', $this->count()) }},
                    {{ __('about :duration', ['duration' => \App\Support\Tournaments\Estimator::format($summaryRow->total(), $summaryProfile)]) }}</b>
                <span class="text-xs text-ink-2">{{ __('Next: sign-up and sponsors on the tournament page. The format can change until sign-up closes.') }}</span>
            </span>
        @endisland
        <span class="hidden grow lg:block"></span>
        @error('format')<span class="text-[13px] text-loss" role="alert">{{ $message }}</span>@enderror
        <x-button wire:click="create" data-test="tournament-create-button">{{ __('Create tournament') }}</x-button>
    </div>
</x-admin.page>
