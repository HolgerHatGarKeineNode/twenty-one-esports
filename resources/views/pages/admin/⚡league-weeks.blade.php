<?php

use App\Games\Blockfill;
use App\Games\ScoreMetric;
use App\Games\TrackmaniaNationsForever;
use App\Models\LeagueWeek;
use App\Models\Tournament;
use App\Models\User;
use App\Support\LeagueTime;
use App\Support\Scores\LeagueWeekDrafts;
use App\Support\Scores\ScoreWindow;
use App\Support\Stacker\BlockfillDifficulty;
use App\Support\Stacker\BlockfillWeeks;
use App\Support\Tmnf\TmnfWeeks;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * League weeks (user 2026-10-02): the weeks Blockfill and TMNF open by
 * themselves start only once an admin approved them (LeagueWeekDrafts).
 * Per registered game: the week that runs now, and every week still to
 * decide (next week's draft from Thursday 12:00 Berlin, a week that was not
 * approved in time) with its settings, a form to change them and "Approve
 * week". Blockfill: the difficulty (BlockfillDifficulty). TMNF: the track
 * from the stock tracks on our server (`esports.tmnf.tracks`) and, if the
 * admin wants one, a time limit per round. Admins only (LeagueWeekPolicy).
 */
new #[Title('League weeks')] #[Layout('layouts::app', ['section' => 'admin'])] class extends Component
{
    /** Week id => its form: `difficulty` (Blockfill), `track` and `limit` (TMNF, minutes or ''). */
    public array $form = [];

    public string $notice = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', LeagueWeek::class);

        $this->fillForm();
    }

    /**
     * The games the admins plan, in page order, with what runs and what waits.
     *
     * @return list<array{game: string, name: string, running: Tournament|null, open: list<LeagueWeek>, next_draft_at: \Carbon\CarbonImmutable}>
     */
    #[Computed]
    public function games(): array
    {
        $drafts = app(LeagueWeekDrafts::class);
        $games = [];

        foreach ([TrackmaniaNationsForever::SLUG, Blockfill::SLUG] as $game) {
            if (! $drafts->plans($game)) {
                continue;
            }

            $current = BlockfillWeeks::startOf(now());
            $games[] = [
                'game' => $game,
                'name' => LeagueWeekDrafts::gameName($game),
                'running' => $game === Blockfill::SLUG ? app(BlockfillWeeks::class)->find($current) : app(TmnfWeeks::class)->find($current),
                'open' => $drafts->open($game)->all(),
                'next_draft_at' => LeagueWeekDrafts::draftMoment(BlockfillWeeks::endOf($current)),
            ];
        }

        return $games;
    }

    public function save(int $id): void
    {
        $week = $this->week($id);
        Gate::authorize('update', $week);
        $this->notice = $this->store($week)
            ? __('Saved. Approve the week to let it start with these settings.')
            : __('Nothing changed.');
        $this->refreshPage();
    }

    public function approve(int $id): void
    {
        $week = $this->week($id);
        Gate::authorize('approve', $week);
        $admin = auth()->user();
        abort_unless($admin instanceof User, 403);

        // What the form shows is what is approved.
        $this->store($week);
        $week->refresh();
        $approved = app(LeagueWeekDrafts::class)->approve($week, $admin);
        $week->refresh();

        $this->notice = match (true) {
            ! $approved => __('This week can no longer be approved.'),
            $week->hasStarted() => __(':game week :week is approved and runs now.', ['game' => LeagueWeekDrafts::gameName($week->game), 'week' => $week->weekNumber()]),
            $week->starts_at->isPast() => __(':game week :week is approved. It starts once the server runs its track.', ['game' => LeagueWeekDrafts::gameName($week->game), 'week' => $week->weekNumber()]),
            default => __(':game week :week is approved. It starts :start.', ['game' => LeagueWeekDrafts::gameName($week->game), 'week' => $week->weekNumber(), 'start' => LeagueTime::stamp($week->starts_at)]),
        };
        $this->refreshPage();
    }

    private function week(int $id): LeagueWeek
    {
        $week = LeagueWeek::query()->find($id);
        abort_if($week === null, 404);

        return $week;
    }

    /** Validates the week's form and stores it; true when the settings changed. */
    private function store(LeagueWeek $week): bool
    {
        $this->resetErrorBag();
        $key = 'form.'.$week->id;

        if ($week->game === Blockfill::SLUG) {
            $values = $this->validate([$key.'.difficulty' => ['required', 'string', Rule::in(array_keys(BlockfillDifficulty::LEVELS))]])['form'][$week->id];

            return app(LeagueWeekDrafts::class)->update($week, ['difficulty' => $values['difficulty']]);
        }

        $values = $this->validate([
            $key.'.track' => ['required', 'string', Rule::in(array_keys((array) config('esports.tmnf.tracks', [])))],
            $key.'.limit' => ['nullable', 'integer', 'min:'.TmnfWeeks::MIN_ROUND_MINUTES, 'max:'.TmnfWeeks::MAX_ROUND_MINUTES],
        ], [], [$key.'.track' => __('Track'), $key.'.limit' => __('Time limit per round')])['form'][$week->id];
        $limit = $values['limit'] === null || $values['limit'] === '' ? null : (int) $values['limit'];
        $track = TmnfWeeks::track($values['track']);

        // A round shorter than the track's author time ends every run before the finish (E05-Endurance takes an hour).
        if ($track !== null && ($limit ?? TmnfWeeks::SERVER_ROUND_MINUTES) * 60_000 <= $track['author_ms']) {
            throw ValidationException::withMessages([$key.'.limit' => __('A round must be longer than the author time of :track (:time).', ['track' => $track['name'], 'time' => ScoreMetric::time()->format($track['author_ms'])])]);
        }

        return app(LeagueWeekDrafts::class)->update($week, ['track' => $values['track'], 'time_limit_minutes' => $limit]);
    }

    private function refreshPage(): void
    {
        unset($this->games);
        $this->fillForm();
    }

    /** The forms hold each open week's stored settings. */
    private function fillForm(): void
    {
        $this->form = [];

        foreach ($this->games as $entry) {
            foreach ($entry['open'] as $week) {
                $settings = LeagueWeekDrafts::normalize($week->game, $week->settings);
                $this->form[$week->id] = $week->game === Blockfill::SLUG
                    ? ['difficulty' => $settings['difficulty']]
                    : ['track' => $settings['track'], 'limit' => $settings['time_limit_minutes'] === null ? '' : (string) $settings['time_limit_minutes']];
            }
        }
    }
}; ?>

@php
    $input = 'h-11 w-full rounded-md border border-edge bg-ground px-3 text-[13px] text-ink';
    $badge = 'inline-flex h-6 items-center rounded-xs px-2 text-xs font-bold';
    $tracks = collect(array_keys((array) config('esports.tmnf.tracks', [])))->map(fn (string $uid): ?array => TmnfWeeks::track($uid))->filter()->values();
@endphp

<x-admin.page active="league-weeks" :title="__('League weeks')" :lead="__('Blockfill and TMNF open a new week every Monday 00:00 Berlin, but only once an admin approved it. Check the settings of the next week, change them if you like, then approve it. Not approved, no week runs and players see “Next week starts soon”.')" :notice="$notice" notice-test="league-weeks-notice" data-test="admin-league-weeks">
    @forelse ($this->games as $entry)
        @php
            $running = $entry['running'];
            $window = $running === null ? null : ScoreWindow::of($running);
        @endphp
        <x-admin.panel :title="$entry['name']" :id="'league-weeks-'.$entry['game']" data-test="league-weeks-{{ $entry['game'] }}">
            <p class="m-0 text-[13px] leading-normal text-ink-2" data-test="league-weeks-running">
                @if ($running !== null)
                    {{ __(':title runs until :end.', ['title' => $running->title(), 'end' => LeagueTime::stamp($window->end)]) }}
                    @if ($entry['game'] === TrackmaniaNationsForever::SLUG && ($runningTrack = TmnfWeeks::track($running->score_course)) !== null)
                        {{ __('Track: :track.', ['track' => $runningTrack['name']]) }}
                    @elseif ($entry['game'] === Blockfill::SLUG)
                        {{ __('Difficulty: :level', ['level' => BlockfillDifficulty::label(app(BlockfillWeeks::class)->difficultyOf($running))]) }}.
                    @endif
                @else
                    {{ __('No week runs right now.') }}
                @endif
            </p>

            @forelse ($entry['open'] as $week)
                @php
                    $field = 'form.'.$week->id;
                    $late = $week->starts_at->isPast();
                    $state = match (true) {
                        ! $week->isApproved() => ['label' => __('Waiting for approval'), 'class' => 'bg-btc-chip text-btc-hi', 'test' => 'draft'],
                        $late => ['label' => __('Approved, waiting for the server'), 'class' => 'bg-raised text-ink', 'test' => 'waiting'],
                        default => ['label' => __('Approved'), 'class' => 'bg-raised text-win', 'test' => 'approved'],
                    };
                @endphp
                <form wire:submit="save({{ $week->id }})" wire:key="week-{{ $week->id }}" class="flex flex-col gap-4 border-t border-hairline pt-4" data-test="league-week" data-week="{{ $week->id }}">
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                        <h3 class="m-0 text-[15px] font-bold">{{ __(':game week :week', ['game' => $entry['name'], 'week' => $week->weekNumber()]) }}</h3>
                        <span class="{{ $badge }} {{ $state['class'] }}" data-test="league-week-state" data-state="{{ $state['test'] }}">{{ $state['label'] }}</span>
                    </div>
                    <p class="m-0 text-xs leading-normal text-ink-2" data-test="league-week-when">
                        @if ($late)
                            {{ __('Planned start :start has passed. Approved now, it starts at once and still ends :end.', ['start' => LeagueTime::stamp($week->starts_at), 'end' => LeagueTime::stamp($week->endsAt())]) }}
                        @else
                            {{ __('Starts :start, ends :end.', ['start' => LeagueTime::stamp($week->starts_at), 'end' => LeagueTime::stamp($week->endsAt())]) }}
                        @endif
                        @if ($week->isApproved())
                            {{ __('Approved by :name.', ['name' => $week->approver?->displayName() ?? __('an admin')]) }}
                        @endif
                    </p>

                    @if ($entry['game'] === Blockfill::SLUG)
                        <fieldset class="m-0 flex min-w-0 flex-col gap-2 border-0 p-0">
                            <legend class="mb-2 p-0 text-[13px] font-bold text-ink">{{ __('Difficulty') }}</legend>
                            <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                @foreach (BlockfillDifficulty::LEVELS as $engine => $level)
                                    <label class="flex min-h-11 cursor-pointer items-start gap-3 rounded-md border border-edge bg-ground px-3 py-2.5 has-[:checked]:border-btc" data-test="difficulty-{{ $engine }}">
                                        <input type="radio" wire:model="{{ $field }}.difficulty" value="{{ $engine }}" class="mt-0.5 size-4 shrink-0 accent-btc">
                                        <span class="flex min-w-0 flex-col gap-0.5">
                                            <b class="text-[13px] text-ink">{{ BlockfillDifficulty::label($engine) }}</b>
                                            <span class="text-xs leading-normal text-ink-2">{{ BlockfillDifficulty::hint($engine) }}</span>
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                            @error($field.'.difficulty')<span class="text-xs text-loss" role="alert">{{ $message }}</span>@enderror
                            <span class="text-xs leading-normal text-ink-3">{{ __('Ranked runs of the week are played and checked on this difficulty; a run on another one does not count for it.') }}</span>
                        </fieldset>
                    @else
                        <div class="grid grid-cols-1 gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                            <div class="flex min-w-0 flex-col gap-1.5">
                                <label for="track-{{ $week->id }}" class="text-[13px] font-bold text-ink">{{ __('Track') }}</label>
                                <select id="track-{{ $week->id }}" wire:model="{{ $field }}.track" class="{{ $input }}" data-test="league-week-track">
                                    @foreach ($tracks as $track)
                                        <option value="{{ $track['uid'] }}">{{ $track['name'] }} · {{ __('author time :time', ['time' => ScoreMetric::time()->format($track['author_ms'])]) }}</option>
                                    @endforeach
                                </select>
                                @error($field.'.track')<span class="text-xs text-loss" role="alert">{{ $message }}</span>@enderror
                                <span class="text-xs leading-normal text-ink-3">{{ __('Nadeo’s stock tracks on our server. At the start of the week the server switches to it.') }}</span>
                            </div>
                            <div class="flex min-w-0 flex-col gap-1.5">
                                <label for="limit-{{ $week->id }}" class="text-[13px] font-bold text-ink">{{ __('Time limit per round') }}</label>
                                <input id="limit-{{ $week->id }}" type="text" inputmode="numeric" wire:model="{{ $field }}.limit" placeholder="{{ __('Server default') }}" class="{{ $input }}" data-test="league-week-limit">
                                @error($field.'.limit')<span class="text-xs text-loss" role="alert">{{ $message }}</span>@enderror
                                <span class="text-xs leading-normal text-ink-3">{{ __('Minutes, :min to :max, longer than the author time. Empty: the server’s own (:default).', ['min' => TmnfWeeks::MIN_ROUND_MINUTES, 'max' => TmnfWeeks::MAX_ROUND_MINUTES, 'default' => TmnfWeeks::SERVER_ROUND_MINUTES]) }}</span>
                            </div>
                        </div>
                    @endif

                    <span class="flex flex-wrap items-center gap-3">
                        @can('approve', $week)
                            <x-button type="button" icon="check" wire:click="approve({{ $week->id }})" data-test="league-week-approve">{{ __('Approve week') }}</x-button>
                        @endcan
                        <x-button type="submit" variant="quiet" data-test="league-week-save">{{ $week->isApproved() ? __('Save (needs approving again)') : __('Save settings') }}</x-button>
                    </span>
                </form>
            @empty
                <x-admin.empty :text="__('Nothing to decide right now. The draft of next week appears :at.', ['at' => LeagueTime::stamp($entry['next_draft_at'])])" />
            @endforelse
        </x-admin.panel>
    @empty
        <x-admin.empty :text="__('Neither Blockfill nor TMNF is switched on.')" />
    @endforelse
</x-admin.page>
