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
use App\Support\Stacker\BlockfillRules;
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
 * week". Blockfill: the week's rules (BlockfillRules, user 2026-10-02:
 * "einstellen können, wie viele Blöcke auch gemint werden sollen und wie
 * schnell das Level steigt"): the lines of a run, the lines per level-up and
 * the gravity curve, with the old difficulties as quick picks and a summary
 * sentence that follows the fields. TMNF: the track
 * from the stock tracks on our server (`esports.tmnf.tracks`) and, if the
 * admin wants one, a time limit per round. Admins only (LeagueWeekPolicy).
 */
new #[Title('League weeks')] #[Layout('layouts::app', ['section' => 'admin'])] class extends Component
{
    /**
     * Week id => its form. Blockfill: `goal`, `every` (0: no level-ups), `start`, `step` and `cap`
     * (BlockfillRules), and `legacy`, the frozen id of a week planned before the rules (bf1hard, ...)
     * that stays as it is while its preset's fields are untouched. TMNF: `track` and `limit` (minutes or '').
     */
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

    /** A quick pick fills the week's rule fields (BlockfillRules::PRESETS); nothing is stored before Save or Approve. */
    public function preset(int $id, string $preset): void
    {
        abort_unless(isset($this->form[$id]['goal'], BlockfillRules::PRESETS[$preset]), 404);

        $this->form[$id] = [...$this->form[$id], ...array_map(strval(...), BlockfillRules::PRESETS[$preset])];
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
            [$goal, $every, $level, $step] = [BlockfillRules::LIMITS['goal'], BlockfillRules::LIMITS['every'], BlockfillRules::LIMITS['level'], BlockfillRules::LIMITS['step']];
            $values = $this->validate([
                $key.'.goal' => ['required', 'integer', 'min:'.$goal[0], 'max:'.$goal[1]],
                $key.'.every' => ['required', 'integer', 'min:0', 'max:'.$every[1]],
                $key.'.start' => ['required', 'integer', 'min:'.$level[0], 'max:'.$level[1]],
                // without level-ups the curve has nothing to climb
                $key.'.step' => ['exclude_if:'.$key.'.every,0', 'required', 'integer', 'min:'.$step[0], 'max:'.$step[1]],
                $key.'.cap' => ['exclude_if:'.$key.'.every,0', 'required', 'integer', 'gt:'.$key.'.start', 'max:'.$level[1]],
            ], [
                $key.'.cap.gt' => __('The top speed must be faster than the start. For one speed all run long, choose no level-ups.'),
            ], [
                $key.'.goal' => __('Blocks per run'), $key.'.every' => __('Level up every'), $key.'.start' => __('Start speed'),
                $key.'.step' => __('Faster per level'), $key.'.cap' => __('Top speed'),
            ])['form'][$week->id];

            $rules = ['goal' => (int) $values['goal'], 'every' => (int) $values['every'], 'start' => (int) $values['start']];
            $rules += $rules['every'] === 0 ? ['step' => 0, 'cap' => $rules['start']] : ['step' => (int) $values['step'], 'cap' => (int) $values['cap']];
            $legacy = $this->form[$week->id]['legacy'] ?? null;
            // A week planned on an old difficulty keeps it while its preset is untouched: its runs and board stay on that engine.
            $engine = is_string($legacy) && $rules === BlockfillRules::fields($legacy) ? $legacy : BlockfillRules::id($rules);

            return app(LeagueWeekDrafts::class)->update($week, ['difficulty' => $engine]);
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
                    ? [...array_map(strval(...), BlockfillRules::fields($settings['difficulty'])), 'legacy' => BlockfillRules::isLegacy($settings['difficulty']) ? $settings['difficulty'] : null]
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
                        <span data-test="league-weeks-running-rules">{{ __('Rules: :rules.', ['rules' => implode(' · ', BlockfillRules::chips(app(BlockfillWeeks::class)->difficultyOf($running)))]) }}</span>
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
                        @php
                            $rules = $this->form[$week->id] ?? [];
                            $preset = BlockfillRules::presetOf($rules);
                            $leveled = ($rules['every'] ?? '0') !== '0' && ($rules['every'] ?? '') !== '';
                            $pick = 'inline-flex h-11 min-w-11 items-center justify-center rounded-md border px-3 text-[13px] font-bold';
                            $levels = range(BlockfillRules::LIMITS['level'][0], BlockfillRules::LIMITS['level'][1]);
                        @endphp
                        <fieldset class="m-0 flex min-w-0 flex-col gap-4 border-0 p-0" data-test="rules">
                            <legend class="mb-2 p-0 text-[13px] font-bold text-ink">{{ __('Rules of the week') }}</legend>

                            {{-- Quick picks: they fill the fields below; Normal is the rules of every week so far --}}
                            <div class="flex min-w-0 flex-col gap-1.5">
                                <span class="text-xs text-ink-2">{{ __('Quick pick') }}</span>
                                <div class="flex flex-wrap gap-2" role="group" aria-label="{{ __('Quick pick') }}">
                                    @foreach (BlockfillRules::PRESET_LABELS as $name => [$label, $hint])
                                        <button type="button" wire:click="preset({{ $week->id }}, '{{ $name }}')" title="{{ __($hint) }}" aria-pressed="{{ $preset === $name ? 'true' : 'false' }}"
                                                @class([$pick, 'border-btc bg-btc-chip text-btc-hi' => $preset === $name, 'border-edge bg-ground text-ink' => $preset !== $name]) data-test="preset-{{ $name }}">{{ __($label) }}</button>
                                    @endforeach
                                </div>
                            </div>

                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div class="flex min-w-0 flex-col gap-1.5">
                                    <label for="goal-{{ $week->id }}" class="text-[13px] font-bold text-ink">{{ __('Blocks per run') }}</label>
                                    <div class="flex flex-wrap gap-2">
                                        <input id="goal-{{ $week->id }}" type="text" inputmode="numeric" wire:model.live.debounce.300ms="{{ $field }}.goal" class="h-11 w-20 shrink-0 rounded-md border border-edge bg-ground px-3 text-[13px] text-ink tabular-nums" data-test="rules-goal">
                                        @foreach ([20, 40, 60, 100] as $goal)
                                            <button type="button" wire:click="$set('{{ $field }}.goal', '{{ $goal }}')" @class([$pick, 'tabular-nums', 'border-btc text-btc-hi' => ($rules['goal'] ?? '') === (string) $goal, 'border-edge text-ink' => ($rules['goal'] ?? '') !== (string) $goal]) data-test="rules-goal-{{ $goal }}">{{ $goal }}</button>
                                        @endforeach
                                    </div>
                                    @error($field.'.goal')<span class="text-xs text-loss" role="alert">{{ $message }}</span>@enderror
                                    <span class="text-xs leading-normal text-ink-3">{{ __('The lines a run clears, :min to :max.', ['min' => BlockfillRules::LIMITS['goal'][0], 'max' => BlockfillRules::LIMITS['goal'][1]]) }}</span>
                                </div>

                                <div class="flex min-w-0 flex-col gap-1.5">
                                    <label for="every-{{ $week->id }}" class="text-[13px] font-bold text-ink">{{ __('Level up every') }}</label>
                                    <div class="flex flex-wrap gap-2">
                                        <input id="every-{{ $week->id }}" type="text" inputmode="numeric" wire:model.live.debounce.300ms="{{ $field }}.every" class="h-11 w-20 shrink-0 rounded-md border border-edge bg-ground px-3 text-[13px] text-ink tabular-nums" data-test="rules-every">
                                        @foreach ([10, 8, 5, 3] as $every)
                                            <button type="button" wire:click="$set('{{ $field }}.every', '{{ $every }}')" @class([$pick, 'tabular-nums', 'border-btc text-btc-hi' => ($rules['every'] ?? '') === (string) $every, 'border-edge text-ink' => ($rules['every'] ?? '') !== (string) $every]) data-test="rules-every-{{ $every }}">{{ $every }}</button>
                                        @endforeach
                                        <button type="button" wire:click="$set('{{ $field }}.every', '0')" @class([$pick, 'border-btc text-btc-hi' => ($rules['every'] ?? '') === '0', 'border-edge text-ink' => ($rules['every'] ?? '') !== '0']) data-test="rules-every-0">{{ __('Never') }}</button>
                                    </div>
                                    @error($field.'.every')<span class="text-xs text-loss" role="alert">{{ $message }}</span>@enderror
                                    <span class="text-xs leading-normal text-ink-3">{{ __('Blocks per level-up, 1 to :max; Never keeps one speed all run long.', ['max' => BlockfillRules::LIMITS['every'][1]]) }}</span>
                                </div>

                                <div class="flex min-w-0 flex-col gap-1.5">
                                    <label for="start-{{ $week->id }}" class="text-[13px] font-bold text-ink">{{ __('Start speed') }}</label>
                                    <select id="start-{{ $week->id }}" wire:model.live="{{ $field }}.start" class="{{ $input }}" data-test="rules-start">
                                        @foreach ($levels as $level)
                                            <option value="{{ $level }}">{{ __('Level :level · :speed', ['level' => $level, 'speed' => BlockfillRules::levelSpeed($level)]) }}</option>
                                        @endforeach
                                    </select>
                                    @error($field.'.start')<span class="text-xs text-loss" role="alert">{{ $message }}</span>@enderror
                                </div>

                                @if ($leveled)
                                    <div class="flex min-w-0 flex-col gap-1.5">
                                        <label for="step-{{ $week->id }}" class="text-[13px] font-bold text-ink">{{ __('Faster per level') }}</label>
                                        <select id="step-{{ $week->id }}" wire:model.live="{{ $field }}.step" class="{{ $input }}" data-test="rules-step">
                                            <option value="">{{ __('Choose…') }}</option>
                                            @foreach (range(BlockfillRules::LIMITS['step'][0], BlockfillRules::LIMITS['step'][1]) as $step)
                                                <option value="{{ $step }}">{{ trans_choice('One level of the curve|:count levels of the curve', $step) }}</option>
                                            @endforeach
                                        </select>
                                        @error($field.'.step')<span class="text-xs text-loss" role="alert">{{ $message }}</span>@enderror
                                    </div>

                                    <div class="flex min-w-0 flex-col gap-1.5 sm:col-start-2">
                                        <label for="cap-{{ $week->id }}" class="text-[13px] font-bold text-ink">{{ __('Top speed') }}</label>
                                        <select id="cap-{{ $week->id }}" wire:model.live="{{ $field }}.cap" class="{{ $input }}" data-test="rules-cap">
                                            <option value="">{{ __('Choose…') }}</option>
                                            @foreach ($levels as $level)
                                                <option value="{{ $level }}">{{ __('Level :level · :speed', ['level' => $level, 'speed' => BlockfillRules::levelSpeed($level)]) }}</option>
                                            @endforeach
                                        </select>
                                        @error($field.'.cap')<span class="text-xs text-loss" role="alert">{{ $message }}</span>@enderror
                                    </div>
                                @endif
                            </div>

                            {{-- The rules in one sentence, as the fields stand --}}
                            <p class="m-0 rounded-md bg-ground px-3 py-2.5 text-[13px] leading-normal text-ink" aria-live="polite" data-test="rules-summary">{{ BlockfillRules::summary($rules) }}</p>
                            <span class="text-xs leading-normal text-ink-3">{{ __('The speeds follow the guideline curve of Tetris Worlds (Tetris Wiki, Marathon). Ranked runs of the week are played and checked on exactly these rules; a run on other rules does not count for it.') }}</span>
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
