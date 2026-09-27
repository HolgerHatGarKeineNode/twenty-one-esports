<?php

use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Livewire\TournamentFormatChooser;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Tournaments\FormatOptions;
use Carbon\CarbonImmutable;
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
 * saves a draft; sign-up, the prize pot and publishing follow in P8b/P9.
 */
new #[Title('New tournament')] #[Layout('layouts::app', ['section' => 'admin'])] class extends TournamentFormatChooser {
    public string $name = '';

    public string $description = '';

    public string $date = '';

    public string $time = '19:00';

    public function mount(): void
    {
        Gate::authorize('create-tournaments');

        $this->date = now()->addWeek()->timezone($this->zone())->format('Y-m-d');
        $this->options = FormatOptions::defaults($this->profile())->toArray();
    }

    /**
     * The zone date and time are entered in: the one the tournament pages show them in.
     */
    private function zone(): string
    {
        return (string) (auth()->user()->timezone ?? config('esports.preseason.display_timezone'));
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
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
            'players' => ['required', 'integer', 'between:2,64'],
            'stations' => ['integer', 'between:1,32'],
            'gameLength' => ['nullable', 'numeric', 'between:0.5,1000'],
            'setup' => ['nullable', 'numeric', 'between:0,1000'],
            'break' => ['nullable', 'numeric', 'between:0,1000'],
            ...$this->deadlineRules(),
        ]);

        // Typed in the zone the show and edit pages display (the player's, else the league's); stored as UTC.
        $startsAt = CarbonImmutable::createFromFormat('Y-m-d H:i', "{$this->date} {$this->time}", $this->zone())?->utc();

        if ($startsAt === null || $startsAt->isPast()) {
            $this->addError('date', __('Pick a start in the future.'));

            return;
        }

        $format = $this->format;

        if (! $this->chosenFormatRuns()) {
            $this->addError('format', __('Pick a format that can run with these settings.'));

            return;
        }

        $profile = $this->profile();

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

        session()->flash('status', __(':name was created as a draft.', ['name' => $tournament->name]));

        $this->redirectRoute('admin.tournaments', navigate: false);
    }
}; ?>

@php
    $isAdmin = (bool) auth()->user()?->can('admin');
@endphp

<div class="flex grow flex-col" data-test="tournament-create">
    @if ($isAdmin)
        <x-admin.nav active="tournaments" />
    @endif

    <div class="flex flex-col gap-4 px-4 pt-4 pb-10 lg:px-12 lg:pt-6">
        <a href="{{ route('admin.tournaments') }}" class="inline-flex min-h-11 items-center gap-1.5 self-start text-[13px]">
            <x-icon name="prev" :size="16" />{{ __('All tournaments') }}
        </a>

        <div class="flex flex-col gap-1 lg:flex-row lg:items-center lg:gap-4">
            <h1 class="m-0 font-display text-2xl font-bold lg:text-[28px]">{{ __('New tournament') }}</h1>
            <span class="text-[13px] text-ink-2">{{ __('Draft. Players see it once you publish.') }}</span>
        </div>

        <section aria-label="{{ __('Basics') }}" class="grid grid-cols-2 gap-3 rounded-lg bg-card p-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)] lg:gap-4 lg:px-6 lg:py-5">
            <label class="col-span-2 flex flex-col gap-1.5 text-xs text-ink-2 lg:col-span-1">
                {{ __('Name') }}
                <input wire:model="name" maxlength="80" placeholder="{{ __('Blitz Night Munich') }}" data-test="tournament-name"
                       class="h-11 w-full rounded-md border border-edge bg-ground px-3 text-[13px] text-ink">
                @error('name')<span class="text-loss" role="alert">{{ $message }}</span>@enderror
            </label>
            <label class="flex min-w-0 flex-col gap-1.5 text-xs text-ink-2">
                {{ __('Date') }}
                <input type="date" wire:model="date" class="h-11 w-full min-w-0 rounded-md border border-edge bg-ground px-3 text-[13px] text-ink [color-scheme:dark]">
                @error('date')<span class="text-loss" role="alert">{{ $message }}</span>@enderror
            </label>
            <label class="flex min-w-0 flex-col gap-1.5 text-xs text-ink-2">
                {{ __('Starts') }}
                <input type="time" wire:model="time" class="h-11 w-full min-w-0 rounded-md border border-edge bg-ground px-3 text-[13px] text-ink [color-scheme:dark]">
                @error('time')<span class="text-loss" role="alert">{{ $message }}</span>@enderror
            </label>
            <span class="col-span-2 text-xs text-ink-3 lg:col-span-3">{{ __('Times in :zone.', ['zone' => (string) (auth()->user()->timezone ?? config('esports.preseason.display_timezone'))]) }}</span>
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
                    <span class="text-xs text-ink-2">{{ __('Next: sign-up, prize money and sponsors on the tournament page. The format can change until sign-up closes.') }}</span>
                </span>
            @endisland
            <span class="hidden grow lg:block"></span>
            @error('format')<span class="text-[13px] text-loss" role="alert">{{ $message }}</span>@enderror
            <x-button wire:click="create" data-test="tournament-create-button">{{ __('Create tournament') }}</x-button>
        </div>
    </div>
</div>
