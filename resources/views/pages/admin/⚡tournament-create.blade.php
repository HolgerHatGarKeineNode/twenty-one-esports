<?php

use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Nostr\NostrKeys;
use App\Support\Tournaments\Estimator;
use App\Support\Tournaments\Evaluation;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * AdminTournamentCreate (AdminTournamentCreate.dc.html + Mobile, P8a): a new
 * tournament with the format chooser. The organizer enters who plays, the
 * game and mode, the time and where; every format is estimated with
 * App\Support\Tournaments\Estimator (TOURNAMENT-FORMATS.md), one is
 * recommended, and the selected one is explained with a mini preview built
 * from the real bracket engine.
 *
 * The chooser and the results section are one island: changing an input
 * re-renders only them (and the summary island), never the page. Creating
 * saves a draft; sign-up, the prize pot and publishing follow in P8b/P9.
 */
new #[Title('New tournament')] #[Layout('layouts::app', ['section' => 'admin'])] class extends Component {
    /** Estimator profile keys and their game and mode. */
    public const GAMES = [
        'blitz' => ['chess', 'blitz'],
        'daily' => ['chess', 'correspondence'],
        'rl1' => ['rocket-league', '1v1'],
        'rl2' => ['rocket-league', '2v2'],
        'rl3' => ['rocket-league', '3v3'],
    ];

    public string $name = '';

    public string $date = '';

    public string $time = '19:00';

    public string $game = 'blitz';

    /** Participants as typed; clamped to 2–64 for the estimate. */
    public string $players = '12';

    public int $window = 180;

    public bool $onSite = false;

    public int $stations = 6;

    /** The organizer's own planning values; '' = the league default. */
    public string $gameLength = '';

    public string $setup = '';

    public string $break = '';

    /** @var array<string, mixed> see FormatOptions */
    public array $options = [];

    /** The format the organizer picked; null = the recommended one. */
    public ?string $selected = null;

    public string $resultsMode = 'players';

    /** @var list<int> user ids of the named directors */
    public array $directorIds = [];

    public string $directorName = '';

    public string $directorError = '';

    public function mount(): void
    {
        Gate::authorize('create-tournaments');

        $this->date = now()->addWeek()->format('Y-m-d');
        $this->options = FormatOptions::defaults($this->profile())->toArray();
    }

    public function profile(): GameProfile
    {
        [$game, $mode] = self::GAMES[$this->game] ?? self::GAMES['blitz'];
        $number = fn (string $value): ?float => is_numeric($value) && (float) $value >= 0 && (float) $value <= 1000 ? (float) $value : null;

        return GameProfile::for($game, $mode)->withTimes($number($this->gameLength), $number($this->setup), $number($this->break));
    }

    public function count(): int
    {
        return is_numeric($this->players) ? max(2, min(64, (int) $this->players)) : 12;
    }

    public function formatOptions(): FormatOptions
    {
        return FormatOptions::fromArray($this->options, $this->profile());
    }

    public function stationLimit(): ?int
    {
        return $this->onSite && ! $this->profile()->isDaily() ? $this->stations : null;
    }

    #[Computed]
    public function evaluation(): Evaluation
    {
        return (new Estimator)->evaluate($this->count(), $this->profile(), $this->formatOptions(), $this->stationLimit(), $this->window);
    }

    /**
     * The selected format if it can run, else the recommended one.
     */
    #[Computed]
    public function format(): TournamentFormat
    {
        $picked = TournamentFormat::tryFrom((string) $this->selected);

        if ($picked !== null && $this->evaluation->row($picked)->enabled) {
            return $picked;
        }

        return $this->evaluation->recommended ?? TournamentFormat::SingleElimination;
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function directors(): Collection
    {
        return User::query()->whereKey($this->directorIds)->orderBy('name')->get();
    }

    public function pickGame(string $key): void
    {
        if (! isset(self::GAMES[$key])) {
            return;
        }

        $this->game = $key;
        $this->gameLength = $this->setup = $this->break = '';
        $this->window = $this->profile()->isDaily() ? 90 : 180;
        $this->options = FormatOptions::defaults($this->profile())->toArray();
        $this->selected = null;

        if ($this->profile()->isDaily()) {
            $this->onSite = false;
        }

        $this->changed();
    }

    public function stepPlayers(int $by): void
    {
        $this->players = (string) max(2, min(64, $this->count() + $by));
        $this->changed();
    }

    public function updatedPlayers(): void
    {
        if (is_numeric($this->players)) {
            $this->players = (string) max(2, min(64, (int) $this->players));
        }

        $this->changed();
    }

    public function pickWindow(int $window): void
    {
        if (in_array($window, array_column($this->windows(), 0), true)) {
            $this->window = $window;
        }

        $this->changed();
    }

    public function pickWhere(string $where): void
    {
        $this->onSite = $where === 'site' && ! $this->profile()->isDaily();

        // On site preselects the director mode (TOURNAMENT-FORMATS.md, section 6); the creator can switch back.
        if ($this->onSite) {
            $this->resultsMode = TournamentResultsMode::Director->value;
        }

        $this->changed();
    }

    public function stepStations(int $by): void
    {
        $this->stations = max(1, min(32, $this->stations + $by));
        $this->changed();
    }

    public function updatedGameLength(): void
    {
        $this->changed();
    }

    public function updatedSetup(): void
    {
        $this->changed();
    }

    public function updatedBreak(): void
    {
        $this->changed();
    }

    public function select(string $format): void
    {
        $this->selected = TournamentFormat::tryFrom($format)?->value;
        $this->changed();
    }

    public function useRecommended(): void
    {
        $this->selected = $this->evaluation->recommended?->value;
        $this->changed();
    }

    /**
     * One option of the selected format, normalized by FormatOptions.
     */
    public function option(string $key, mixed $value): void
    {
        if (array_key_exists($key, FormatOptions::defaults($this->profile())->toArray())) {
            $this->options = FormatOptions::fromArray([...$this->options, $key => $value], $this->profile())->toArray();
        }

        $this->changed();
    }

    /**
     * A tie-break picked that another place already holds: the two swap, so
     * the list keeps three distinct entries.
     */
    public function updatingOptions(mixed $value, string $key): void
    {
        if (preg_match('/^(swissTieBreaks|roundRobinTieBreaks)\.([0-2])$/', $key, $found) !== 1 || ! is_array($this->options[$found[1]] ?? null)) {
            return;
        }

        $list = $this->options[$found[1]];
        $other = array_search($value, $list, true);

        if ($other !== false && $other !== (int) $found[2]) {
            $this->options[$found[1]][$other] = $list[(int) $found[2]] ?? null;
        }
    }

    public function pickGroupStage(string $stage): void
    {
        $this->option('groupStage', $stage);
    }

    public function pickFinalStage(string $stage): void
    {
        $this->option('finalStage', $stage);
    }

    public function pickBestOf(int $bestOf): void
    {
        $this->option('bestOf', $bestOf);
    }

    public function pickFinalBestOf(int $bestOf): void
    {
        $this->option('finalBestOf', $bestOf);
    }

    /**
     * Chess: one game, or two (one with each color) — the final plays the same.
     */
    public function pickGamesPerMatch(int $games): void
    {
        $this->options = FormatOptions::fromArray([...$this->options, 'bestOf' => $games, 'finalBestOf' => $games], $this->profile())->toArray();
        $this->changed();
    }

    public function updatedOptions(): void
    {
        $this->options = FormatOptions::fromArray($this->options, $this->profile())->toArray();
        $this->changed();
    }

    public function stepOption(string $key, int $by): void
    {
        $current = match ($key) {
            'swissRounds' => $this->options['swissRounds'] ?? $this->evaluation->swissRounds,
            default => $this->options[$key] ?? 0,
        };

        $value = (int) $current + $by;

        if ($key === 'swissRounds') {
            $value = max(1, min(Estimator::swissMax($this->count()), $value));
        }

        $this->option($key, $value);
    }

    public function pickResultsMode(string $mode): void
    {
        $this->resultsMode = TournamentResultsMode::tryFrom($mode)?->value ?? $this->resultsMode;
        $this->changed();
    }

    public function addDirector(): void
    {
        $this->directorError = '';
        $search = trim($this->directorName);

        if ($search === '') {
            $this->directorError = __('Enter a player name or an npub.');

            return;
        }

        $pubkey = NostrKeys::toHex($search);
        $matches = $pubkey !== null
            ? User::query()->where('pubkey', $pubkey)->get()
            : User::query()->whereRaw('lower(name) = ?', [mb_strtolower($search)])->limit(2)->get();

        if ($matches->count() !== 1) {
            $this->directorError = $matches->isEmpty() ? __('No player with this name.') : __('Several players have this name. Enter their npub.');

            return;
        }

        $user = $matches->first();

        if ($user->id !== auth()->id() && ! in_array($user->id, $this->directorIds, true)) {
            $this->directorIds[] = $user->id;
        }

        $this->directorName = '';
        unset($this->directors);
        $this->changed();
    }

    public function removeDirector(int $userId): void
    {
        $this->directorIds = array_values(array_filter($this->directorIds, fn (int $id): bool => $id !== $userId));
        unset($this->directors);
        $this->changed();
    }

    /**
     * The chooser changed: the summary island shows the new choice too.
     */
    private function changed(): void
    {
        unset($this->evaluation, $this->format);
        $this->renderIsland('summary');
    }

    /**
     * @return list<array{0: int, 1: string}>
     */
    public function windows(): array
    {
        return $this->profile()->isDaily()
            ? [[30, __('1 month')], [90, __('3 months')], [180, __('6 months')]]
            : [[120, __('2 hours')], [180, __('One evening, 3 h')], [240, __('4 hours')], [480, __('A day, 8 h')]];
    }

    public function create(): void
    {
        Gate::authorize('create-tournaments');

        $this->validate([
            'name' => ['required', 'string', 'max:80'],
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
            'players' => ['required', 'integer', 'between:2,64'],
            'stations' => ['integer', 'between:1,32'],
            'gameLength' => ['nullable', 'numeric', 'between:0.5,1000'],
            'setup' => ['nullable', 'numeric', 'between:0,1000'],
            'break' => ['nullable', 'numeric', 'between:0,1000'],
        ]);

        $startsAt = CarbonImmutable::createFromFormat('Y-m-d H:i', "{$this->date} {$this->time}");

        if ($startsAt === null || $startsAt->isPast()) {
            $this->addError('date', __('Pick a start in the future.'));

            return;
        }

        $format = $this->format;

        if (! $this->evaluation->row($format)->enabled || ! in_array($this->window, array_column($this->windows(), 0), true)) {
            $this->addError('format', __('Pick a format that can run with these settings.'));

            return;
        }

        $profile = $this->profile();
        $times = array_filter([
            'game' => $profile->gameLength,
            'setup' => $profile->setup,
            'break' => $profile->break,
        ], fn (float $value, string $key): bool => $value !== [
            'game' => GameProfile::for($profile->game, $profile->mode)->gameLength,
            'setup' => GameProfile::for($profile->game, $profile->mode)->setup,
            'break' => GameProfile::for($profile->game, $profile->mode)->break,
        ][$key], ARRAY_FILTER_USE_BOTH);

        $options = $this->formatOptions();

        // Swiss keeps the rounds the chooser planned with when the organizer left them open.
        if ($format === TournamentFormat::Swiss && $options->swissRounds === null) {
            $options = $options->withSwissRounds($this->evaluation->swissRounds);
        }

        $tournament = Tournament::query()->create([
            'name' => $this->name,
            'game' => $profile->game,
            'mode' => $profile->mode,
            'format' => $format,
            'options' => $options->toArray(),
            'capacity' => $this->count(),
            'starts_at' => $startsAt,
            'time_window' => $this->window,
            'on_site' => $this->stationLimit() !== null,
            'stations' => $this->stationLimit(),
            'times' => $times === [] ? null : $times,
            'results_mode' => TournamentResultsMode::from($this->resultsMode),
            'status' => TournamentStatus::Draft,
            'created_by_id' => auth()->id(),
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
        </section>

        @island(name: 'chooser')
            @include('pages.admin.partials.tournament-chooser')
        @endisland

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
