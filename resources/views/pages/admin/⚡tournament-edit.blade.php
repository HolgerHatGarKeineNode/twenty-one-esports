<?php

use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Livewire\TournamentFormatChooser;
use App\Models\Tournament;
use App\Models\TournamentBan;
use App\Models\TournamentModerationEntry;
use App\Models\TournamentSignup;
use App\Models\User;
use App\Support\Tournaments\TournamentEditor;
use App\Support\Tournaments\TournamentGames;
use App\Support\Tournaments\TournamentModeration;
use App\Support\Tournaments\TournamentRuleViolation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;

/*
 * Editing a tournament: admins on every tournament, an organizer on their
 * own (route and every action: gate `manage-tournament`).
 *
 * Before the draw the whole tournament changes through the same format
 * chooser as on the create page (more sign-ups than expected may need
 * another system, a wrong game pick needs a fix), and the organizer
 * moderates the entries: remove one with a reason (the players are
 * notified), optionally block its players from signing up again. After the
 * draw only the name and the start change; the rest is locked with the
 * reason. The rules live in App\Support\Tournaments\TournamentEditor and
 * TournamentModeration; every step lands in the moderation log shown here.
 */
new #[Layout('layouts::app', ['section' => 'admin'])] class extends TournamentFormatChooser {
    public Tournament $tournament;

    public string $name = '';

    public string $description = '';

    public string $date = '';

    public string $time = '';

    /** Sign-up close as `Y-m-d\TH:i` in the viewer's zone; only while sign-up is open. */
    public string $closesAt = '';

    /** Remove the lineups a game or mode correction no longer fits. */
    public bool $confirmRemoval = false;

    public string $error = '';

    public string $notice = '';

    /** The entry whose removal form is open; null = none. */
    public ?int $removing = null;

    public string $removeReason = '';

    public bool $removeBlock = false;

    public string $moderationError = '';

    public function mount(Tournament $tournament): void
    {
        Gate::authorize('manage-tournament', $tournament);

        $this->tournament = $tournament;
        $this->fillFromTournament();
    }

    public function rendering(\Illuminate\View\View $view): void
    {
        $view->title(__('Edit :name', ['name' => $this->tournament->name]));
    }

    public function chooserCreator(): ?User
    {
        return $this->tournament->creator;
    }

    private function fillFromTournament(): void
    {
        $tournament = $this->tournament;
        $zone = $this->zone();
        $times = $tournament->times ?? [];

        $this->name = $tournament->name;
        $this->description = (string) $tournament->description;
        $this->date = $tournament->starts_at->copy()->timezone($zone)->format('Y-m-d');
        $this->time = $tournament->starts_at->copy()->timezone($zone)->format('H:i');
        $this->closesAt = $tournament->signup_closes_at?->copy()->timezone($zone)->format('Y-m-d\TH:i') ?? '';
        $this->game = TournamentGames::keyOf($tournament->game, $tournament->mode) ?? 'blitz';
        $this->players = (string) $tournament->capacity;
        $this->window = $tournament->time_window;
        $this->onSite = $tournament->on_site;
        $this->stations = $tournament->stations ?? 6;
        $this->gameLength = isset($times['game']) ? (string) ($times['game'] + 0) : '';
        $this->setup = isset($times['setup']) ? (string) ($times['setup'] + 0) : '';
        $this->break = isset($times['break']) ? (string) ($times['break'] + 0) : '';
        $this->options = $tournament->formatOptions()->toArray();
        $this->selected = $tournament->format->value;
        $this->resultsMode = $tournament->results_mode->value;
        $this->directorIds = array_values(array_map(intval(...), $tournament->directors()->pluck('users.id')->all()));
        $this->confirmRemoval = false;
    }

    /**
     * Lineup entries the game and mode picked in the chooser no longer fit.
     *
     * @return Collection<int, TournamentSignup>
     */
    #[Computed]
    public function incompatible(): Collection
    {
        $profile = $this->profile();

        if (! $this->tournament->isBeforeDraw() || ($profile->game === $this->tournament->game && $profile->mode === $this->tournament->mode)) {
            return collect();
        }

        return app(TournamentEditor::class)->incompatible($this->tournament, $profile->game, $profile->mode);
    }

    /**
     * @return Collection<int, TournamentSignup>
     */
    #[Computed]
    public function signups(): Collection
    {
        return TournamentSignup::query()->where('tournament_id', $this->tournament->id)->active()->with('lineup.clan')->orderBy('id')->get();
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function members(): Collection
    {
        return User::query()->whereKey($this->signups->pluck('members')->flatten()->all())->get()->keyBy('id');
    }

    /**
     * @return array<int, int|null>
     */
    #[Computed]
    public function seeds(): array
    {
        return TournamentModeration::seedRatings($this->tournament, $this->signups);
    }

    /**
     * @return Collection<int, TournamentBan>
     */
    #[Computed]
    public function bans(): Collection
    {
        return $this->tournament->bans()->with('user')->orderBy('id')->get();
    }

    /**
     * @return Collection<int, TournamentModerationEntry>
     */
    #[Computed]
    public function log(): Collection
    {
        return $this->tournament->moderationEntries()->limit(50)->get();
    }

    public function save(): void
    {
        Gate::authorize('manage-tournament', $this->tournament);

        $this->error = $this->notice = '';
        $tournament = $this->tournament;
        $beforeDraw = $tournament->isBeforeDraw();

        $this->validate([
            'name' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:1000'],
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
            ...($beforeDraw ? [
                'players' => ['required', 'integer', 'between:2,64'],
                'stations' => ['integer', 'between:1,32'],
                'gameLength' => ['nullable', 'numeric', 'between:0.5,1000'],
                'setup' => ['nullable', 'numeric', 'between:0,1000'],
                'break' => ['nullable', 'numeric', 'between:0,1000'],
            ] : []),
            ...($tournament->status === TournamentStatus::Signup ? ['closesAt' => ['required', 'date_format:Y-m-d\TH:i']] : []),
        ]);

        $zone = $this->zone();
        $changes = [
            'name' => trim($this->name),
            'description' => trim($this->description) === '' ? null : trim($this->description),
            'starts_at' => CarbonImmutable::createFromFormat('Y-m-d H:i', "{$this->date} {$this->time}", $zone)->utc(),
        ];

        if ($beforeDraw) {
            if (! $this->chosenFormatRuns()) {
                $this->error = __('Pick a format that can run with these settings.');

                return;
            }

            $profile = $this->profile();
            $changes = [...$changes,
                'game' => $profile->game,
                'mode' => $profile->mode,
                'format' => $this->format,
                'options' => $this->chosenOptions()->toArray(),
                'capacity' => $this->count(),
                'time_window' => $this->window,
                'on_site' => $this->stationLimit() !== null,
                'stations' => $this->stationLimit(),
                'times' => $this->chosenTimes(),
                'results_mode' => TournamentResultsMode::from($this->resultsMode),
            ];

            if ($this->resultsMode === TournamentResultsMode::Director->value) {
                $changes['director_ids'] = $this->directorIds;
            }

            if ($tournament->status === TournamentStatus::Signup) {
                $changes['signup_closes_at'] = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $this->closesAt, $zone)->utc();
            }
        }

        // One saved change every 2 s per tournament and per organizer: each one signs a new 31923 and queues the calendar.
        $throttles = ['tournament-edit:'.$tournament->id, 'tournament-edit-user:'.$this->user()->id];

        foreach ($throttles as $throttle) {
            if (RateLimiter::tooManyAttempts($throttle, 1)) {
                $this->error = __('Saved a moment ago. Wait :seconds s and save again.', ['seconds' => max(1, RateLimiter::availableIn($throttle))]);

                return;
            }
        }

        try {
            $changed = app(TournamentEditor::class)->update($tournament, $this->user(), $changes, $this->confirmRemoval);
        } catch (TournamentRuleViolation $violation) {
            $this->error = $violation->getMessage();

            return;
        }

        if ($changed !== []) {
            foreach ($throttles as $throttle) {
                RateLimiter::hit($throttle, 2);
            }
        }

        $this->tournament = $tournament->refresh();
        $this->fillFromTournament();
        $this->forget();
        $this->notice = $changed === [] ? __('Nothing changed.') : __('Saved.').($this->tournament->event_id !== null ? ' '.__('The league published the new version of the tournament.') : '');
    }

    public function startRemove(int $signupId): void
    {
        $this->removing = $signupId;
        $this->removeReason = '';
        $this->removeBlock = false;
        $this->moderationError = '';
    }

    public function cancelRemove(): void
    {
        $this->removing = null;
        $this->moderationError = '';
    }

    public function remove(): void
    {
        Gate::authorize('manage-tournament', $this->tournament);

        $this->moderationError = '';

        if ($this->removing === null) {
            return;
        }

        try {
            $signup = app(TournamentModeration::class)->remove($this->tournament, $this->user(), $this->removing, $this->removeReason, $this->removeBlock);
        } catch (TournamentRuleViolation $violation) {
            $this->moderationError = $violation->getMessage();

            return;
        }

        $this->removing = null;
        $this->notice = __(':entry was removed.', ['entry' => $signup->name]);
        $this->forget();
    }

    public function unblock(int $banId): void
    {
        Gate::authorize('manage-tournament', $this->tournament);

        try {
            app(TournamentModeration::class)->unblock($this->tournament, $this->user(), $banId);
        } catch (TournamentRuleViolation $violation) {
            $this->moderationError = $violation->getMessage();
        }

        $this->forget();
    }

    private function forget(): void
    {
        unset($this->incompatible, $this->signups, $this->members, $this->seeds, $this->bans, $this->log, $this->evaluation, $this->format, $this->directors);
    }

    /**
     * The chooser changed: the summary island (with the lineups a game
     * correction would remove) shows it too.
     */
    protected function changed(): void
    {
        unset($this->incompatible);
        parent::changed();
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function zone(): string
    {
        return (string) (auth()->user()->timezone ?? config('esports.preseason.display_timezone'));
    }
}; ?>

@php
    $tournament = $this->tournament;
    $isAdmin = (bool) auth()->user()?->can('admin');
    $beforeDraw = $tournament->isBeforeDraw();
    $ended = in_array($tournament->status, [TournamentStatus::Finished, TournamentStatus::Cancelled], true);
    $zone = (string) (auth()->user()->timezone ?? config('esports.preseason.display_timezone'));
    $teams = $tournament->profile()->entersTeams();
    $places = app(\App\Support\Tournaments\TournamentSignups::class)->places($tournament);
    $field = 'h-11 w-full min-w-0 rounded-md border border-edge bg-ground px-3 text-[13px] text-ink';
    $actions = [
        'edited' => __('edited the tournament'),
        'removed' => __('removed an entry'),
        'blocked' => __('blocked a player'),
        'unblocked' => __('unblocked a player'),
        'reconfirm' => __('asked the entries to confirm again'),
    ];
    $shown = fn (mixed $value): string => match (true) {
        $value === null, $value === [] => '—',
        is_bool($value) => $value ? __('yes') : __('no'),
        is_array($value) => implode(', ', array_map(fn ($item): string => is_scalar($item) ? (string) $item : (string) json_encode($item), $value)),
        default => (string) $value,
    };
    $fields = [
        'name' => __('Name'), 'description' => __('Description'), 'starts_at' => __('Starts'), 'signup_closes_at' => __('Sign-up closes'), 'capacity' => __('Planned for'),
        'results_mode' => __('Results'), 'directors' => __('Tournament directors'), 'game' => __('Game'), 'mode' => __('Mode'),
        'format' => __('Format'), 'options' => __('Format options'), 'time_window' => __('Time you have'), 'on_site' => __('On site'),
        'stations' => __('Stations'), 'times' => __('Planning times'), 'ladder' => __('Ladder'),
    ];
@endphp

<div class="flex grow flex-col" data-test="tournament-edit">
    @if ($isAdmin)
        <x-admin.nav active="tournaments" />
    @endif

    <div class="flex flex-col gap-4 px-4 pt-4 pb-10 lg:px-12 lg:pt-6">
        <span class="flex flex-wrap gap-x-5">
            <a href="{{ route('admin.tournaments') }}" class="inline-flex min-h-11 items-center gap-1.5 text-[13px]"><x-icon name="prev" :size="16" />{{ __('All tournaments') }}</a>
            <a href="{{ route('tournaments.show', $tournament) }}" class="inline-flex min-h-11 items-center text-[13px]" data-test="to-tournament">{{ __('Tournament page') }}</a>
        </span>

        <div class="flex flex-col gap-2 lg:flex-row lg:items-center lg:gap-4">
            <h1 class="m-0 min-w-0 font-display text-2xl font-bold [overflow-wrap:anywhere] lg:text-[28px]">{{ __('Edit :name', ['name' => $tournament->name]) }}</h1>
            <span class="inline-flex h-7 items-center self-start rounded-xs bg-btc-chip px-2 text-xs font-bold text-btc-hi lg:self-auto" data-test="edit-status">{{ $tournament->status->label() }}</span>
        </div>

        @if ($notice !== '')
            <p class="m-0 rounded-md bg-win-tint px-4 py-3 text-[13px] text-win shadow-[inset_0_0_0_1px_#1F5A34]" role="status" data-test="edit-notice">{{ $notice }}</p>
        @endif
        @if ($error !== '')
            <p class="m-0 rounded-md px-4 py-3 text-[13px] text-loss shadow-[inset_0_0_0_1px_#5A2A2E]" role="alert" data-test="edit-error">{{ $error }}</p>
        @endif

        @if ($ended)
            <p class="m-0 rounded-lg bg-card px-4 py-4 text-[13px] text-ink-2" data-test="edit-ended">{{ __('This tournament has ended; it can no longer be changed.') }}</p>
        @else
            <section aria-label="{{ __('Basics') }}" class="grid grid-cols-2 gap-3 rounded-lg bg-card p-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)_minmax(0,1.3fr)] lg:gap-4 lg:px-6 lg:py-5">
                <label class="col-span-2 flex min-w-0 flex-col gap-1.5 text-xs text-ink-2 lg:col-span-1">
                    {{ __('Name') }}
                    <input wire:model="name" maxlength="80" data-test="edit-name" class="{{ $field }}">
                    @error('name')<span class="text-loss" role="alert">{{ $message }}</span>@enderror
                </label>
                <label class="flex min-w-0 flex-col gap-1.5 text-xs text-ink-2">
                    {{ __('Date') }}
                    <input type="date" wire:model="date" class="{{ $field }} [color-scheme:dark]" data-test="edit-date">
                    @error('date')<span class="text-loss" role="alert">{{ $message }}</span>@enderror
                </label>
                <label class="flex min-w-0 flex-col gap-1.5 text-xs text-ink-2">
                    {{ __('Starts') }}
                    <input type="time" wire:model="time" class="{{ $field }} [color-scheme:dark]" data-test="edit-time">
                    @error('time')<span class="text-loss" role="alert">{{ $message }}</span>@enderror
                </label>
                @if ($tournament->status === TournamentStatus::Signup)
                    <label class="col-span-2 flex min-w-0 flex-col gap-1.5 text-xs text-ink-2 lg:col-span-1">
                        {{ __('Sign-up closes') }}
                        <input type="datetime-local" wire:model="closesAt" class="{{ $field }} [color-scheme:dark]" data-test="edit-closes-at">
                        @error('closesAt')<span class="text-loss" role="alert">{{ $message }}</span>@enderror
                    </label>
                @endif
                <span class="col-span-2 text-xs text-ink-3 lg:col-span-4">{{ __('Times in :zone.', ['zone' => $zone]) }}</span>
                <label class="col-span-2 flex min-w-0 flex-col gap-1.5 text-xs text-ink-2 lg:col-span-4">
                    {{ __('Description (optional)') }}
                    <textarea wire:model="description" maxlength="1000" rows="3" data-test="edit-description"
                              class="min-h-24 w-full rounded-md border border-edge bg-ground px-3 py-2.5 text-[13px] leading-normal text-ink"></textarea>
                    <span class="text-ink-3">{{ __('Shown at the top of the tournament page and published with it on Nostr. Up to 1000 characters.') }}</span>
                    @error('description')<span class="text-loss" role="alert">{{ $message }}</span>@enderror
                </label>
            </section>

            @if ($beforeDraw)
                @island(name: 'chooser')
                    @include('pages.admin.partials.tournament-chooser')
                @endisland
            @else
                <section class="flex flex-col gap-2 rounded-lg bg-card px-4 py-5 shadow-[inset_0_0_0_1px_#F7931A] lg:px-6" data-test="format-locked">
                    <h2 class="m-0 text-[15px] font-bold">{{ __('Format locked') }}</h2>
                    <p class="m-0 max-w-[80ch] text-[13px] leading-normal text-ink-2">{{ __('The draw committed to a Bitcoin block with these entries and this format: :format, :who. Game, format, capacity and sign-up can no longer change, and the draw is never redone, because the league would then pick among block hashes it has already seen. Name, description and start time can still change. To play in another system, call this tournament off and create a new one.', [
                        'format' => $tournament->format->label(),
                        'who' => $teams ? trans_choice(':count team|:count teams', $tournament->capacity) : trans_choice(':count player|:count players', $tournament->capacity),
                    ]) }}</p>
                </section>
            @endif

            <div class="flex flex-col gap-3 rounded-lg bg-card px-4 py-4 lg:flex-row lg:items-center lg:gap-4 lg:px-6 lg:py-5">
                @island(name: 'summary')
                    {{-- An island renders in its own scope: nothing from the @php block above reaches it. --}}
                    <span class="flex min-w-0 flex-col gap-2" data-test="edit-summary">
                        @if ($this->tournament->isBeforeDraw())
                            <b class="text-sm">{{ $this->format->label() }}, {{ $this->profile()->entersTeams() ? trans_choice(':count team|:count teams', $this->count()) : trans_choice(':count player|:count players', $this->count()) }},
                                {{ __('about :duration', ['duration' => \App\Support\Tournaments\Estimator::format($this->evaluation->row($this->format)->total(), $this->profile())]) }}</b>
                        @endif
                        <span class="text-xs text-ink-2">{{ $this->tournament->event_id !== null ? __('Saving publishes a new version of the tournament to the league calendar. Its address stays the same.') : __('A draft: nothing is published until you publish it on the tournament page.') }}</span>
                        @if ($this->incompatible->isNotEmpty())
                            <span class="flex flex-col gap-2 rounded-md px-3 py-3 shadow-[inset_0_0_0_1px_#5A2A2E]" data-test="incompatible">
                                <span class="text-[13px] font-bold text-loss">{{ __('These lineups were entered for the old game or mode') }}</span>
                                <span class="text-[13px] [overflow-wrap:anywhere]">{{ $this->incompatible->pluck('name')->implode(', ') }}</span>
                                <label class="flex items-start gap-2 text-xs leading-normal text-ink-2">
                                    <input type="checkbox" wire:model="confirmRemoval" class="mt-0.5 size-4 shrink-0 accent-btc" data-test="confirm-removal">
                                    {{ __('Remove them when saving. Their players are notified, and the removal is logged. Solo entries stay.') }}
                                </label>
                            </span>
                        @endif
                    </span>
                @endisland
                <span class="hidden grow lg:block"></span>
                <x-button wire:click="save" class="shrink-0" data-test="edit-save">{{ __('Save changes') }}</x-button>
            </div>
        @endif

        <section id="signups" aria-labelledby="signups-h" class="flex flex-col gap-3 rounded-lg bg-card px-4 py-5 lg:px-6" data-test="moderation">
            <span class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                <h2 id="signups-h" class="m-0 text-[15px] font-bold">{{ __('Sign-ups') }}</h2>
                <span class="text-xs text-ink-3">{{ $teams
                    ? __(':lineups lineups and :solos solo players, :taken of :places places', ['lineups' => $places['lineups'], 'solos' => $places['solos'], 'taken' => $places['taken'], 'places' => $places['places']])
                    : __(':taken of :places players', ['taken' => $places['taken'], 'places' => $places['places']]) }}</span>
            </span>

            @if (! $beforeDraw)
                <p class="m-0 text-[13px] text-ink-2" data-test="moderation-closed">{{ __('The draw has run: entries can no longer be changed.') }}</p>
            @elseif ($this->signups->isEmpty())
                <p class="m-0 text-[13px] text-ink-2">{{ __('Nobody has signed up yet.') }}</p>
            @else
                <p class="m-0 text-xs leading-normal text-ink-2">{{ __('Removing an entry frees its places and tells its players why. Their signed sign-up stays on record; nothing is published.') }}</p>
                @if ($this->signups->contains(fn ($signup) => $signup->needsReconfirm()))
                    <p class="m-0 text-xs leading-normal text-btc-hi" data-test="reconfirm-note">{{ __('The rules changed after these sign-ups. Entries marked “needs re-confirm” are dropped at sign-up close unless their players confirm again.') }}</p>
                @endif
                @if ($moderationError !== '')
                    <p class="m-0 text-[13px] text-loss" role="alert" data-test="moderation-error">{{ $moderationError }}</p>
                @endif
                <ul class="m-0 flex list-none flex-col p-0">
                    @foreach ($this->signups as $signup)
                        @php($seed = $this->seeds[$signup->id] ?? null)
                        <li class="flex flex-col gap-2 border-t border-hairline py-3" wire:key="signup-{{ $signup->id }}" data-test="signup-row">
                            <div class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1 text-[13px] lg:grid-cols-[minmax(0,1.4fr)_minmax(0,2fr)_190px_90px_auto]">
                                <span class="flex min-w-0 items-center gap-2">
                                    @if ($signup->lineup?->clan)
                                        <x-clan-tag :clan="$signup->lineup->clan" size="sm" />
                                    @else
                                        <span class="inline-flex h-5 shrink-0 items-center rounded-xs bg-raised px-1.5 text-[10px] text-ink-2">{{ $teams ? __('solo') : __('player') }}</span>
                                    @endif
                                    <b class="min-w-0 truncate">{{ $signup->name }}</b>
                                    @if ($signup->needsReconfirm())
                                        <span class="inline-flex h-5 shrink-0 items-center rounded-xs bg-btc-chip px-1.5 text-[10px] font-bold text-btc-hi" data-test="needs-reconfirm">{{ __('needs re-confirm') }}</span>
                                    @elseif ($signup->reconfirm_event_id)
                                        <span class="inline-flex h-5 shrink-0 items-center rounded-xs bg-win-tint px-1.5 text-[10px] text-win" data-test="reconfirmed">{{ __('re-confirmed') }}</span>
                                    @endif
                                </span>
                                <x-button variant="secondary" wire:click="startRemove({{ $signup->id }})" class="h-9 px-3 lg:order-last" data-test="remove-{{ $signup->id }}">{{ __('Remove') }}</x-button>
                                <span class="col-span-2 min-w-0 text-xs text-ink-2 [overflow-wrap:anywhere] lg:col-span-1">
                                    {{ $signup->lineup_id ? collect($signup->members)->map(fn ($id) => $this->members[$id]?->displayName())->filter()->implode(', ') : ($signup->lineup?->clan?->name ?? '') }}
                                </span>
                                <span class="text-xs whitespace-nowrap text-ink-3">{{ __('signed up :date', ['date' => $signup->created_at?->copy()->timezone($zone)->format('Y-m-d H:i')]) }}</span>
                                <span class="text-right text-xs text-ink-2 lg:text-left">{{ $seed === null ? __('mix pool') : __('Elo :rating', ['rating' => $seed]) }}</span>
                            </div>

                            @if ($removing === $signup->id)
                                <form wire:submit="remove" class="flex flex-col gap-2 rounded-md bg-ground p-3 shadow-ring" data-test="remove-form">
                                    <label class="flex flex-col gap-1.5 text-xs text-ink-2">
                                        {{ __('Reason (the players read it)') }}
                                        <input wire:model="removeReason" maxlength="500" class="{{ $field }}" data-test="remove-reason">
                                    </label>
                                    <label class="flex items-start gap-2 text-xs leading-normal text-ink-2">
                                        <input type="checkbox" wire:model="removeBlock" class="mt-0.5 size-4 shrink-0 accent-btc" data-test="remove-block">
                                        {{ $signup->lineup_id ? __('Also block its players from signing up for this tournament again') : __('Also block this player from signing up for this tournament again') }}
                                    </label>
                                    <span class="flex flex-wrap gap-2">
                                        <x-button type="submit" data-test="remove-confirm">{{ __('Remove entry') }}</x-button>
                                        <x-button variant="quiet" wire:click="cancelRemove">{{ __('Cancel') }}</x-button>
                                    </span>
                                </form>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif

            @if ($this->bans->isNotEmpty())
                <div class="flex flex-col gap-2 border-t border-hairline pt-3" data-test="blocked">
                    <h3 class="m-0 text-[13px] font-bold">{{ __('Blocked from this tournament') }}</h3>
                    <ul class="m-0 flex list-none flex-col gap-2 p-0">
                        @foreach ($this->bans as $ban)
                            <li class="flex flex-wrap items-center gap-x-3 gap-y-1 text-[13px]" wire:key="ban-{{ $ban->id }}">
                                <span class="min-w-0 grow [overflow-wrap:anywhere]"><b>{{ $ban->user->displayName() }}</b> <span class="text-xs text-ink-3">{{ $ban->reason }}</span></span>
                                @if ($beforeDraw)
                                    <x-button variant="quiet" wire:click="unblock({{ $ban->id }})" class="h-9 px-3" data-test="unblock-{{ $ban->id }}">{{ __('Unblock') }}</x-button>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </section>

        <section aria-labelledby="modlog-h" class="flex flex-col gap-2 rounded-lg bg-card px-4 py-5 lg:px-6" data-test="moderation-log">
            <span class="flex items-baseline justify-between gap-3">
                <h2 id="modlog-h" class="m-0 text-[15px] font-bold">{{ __('Moderation log') }}</h2>
                <span class="text-xs text-ink-3">{{ __('newest first') }}</span>
            </span>
            @forelse ($this->log as $entry)
                <div class="flex flex-col gap-1 border-t border-hairline pt-2 text-xs leading-normal" wire:key="log-{{ $entry->id }}">
                    <span class="[overflow-wrap:anywhere]"><span class="text-ink-3">{{ $entry->created_at->copy()->timezone($zone)->format('Y-m-d H:i') }}</span>
                        <b>{{ $entry->user_name }}</b> {{ $actions[$entry->action] ?? $entry->action }}@if ($entry->subject): <b>{{ $entry->subject }}</b>@endif
                        @if ($entry->reason) <span class="text-ink-2">— {{ $entry->reason }}</span>@endif</span>
                    @foreach ($entry->details ?? [] as $key => [$old, $new])
                        <span class="text-ink-2 [overflow-wrap:anywhere]">{{ $fields[$key] ?? $key }}: {{ $shown($old) }} → {{ $shown($new) }}</span>
                    @endforeach
                </div>
            @empty
                <p class="m-0 text-xs text-ink-2">{{ __('Nothing moderated yet.') }}</p>
            @endforelse
        </section>
    </div>
</div>
