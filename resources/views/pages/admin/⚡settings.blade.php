<?php

use App\Models\LeagueSettingChange;
use App\Models\User;
use App\Support\LeagueTime;
use App\Support\Settings\LeagueSettingRefused;
use App\Support\Settings\LeagueSettings;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * League settings (P44): the operational values of the league on one page,
 * grouped, each with its help text, its range and its default from the
 * config (LeagueSettings::definitions(), the allow-list). Saving writes one
 * row per changed value into the append-only log, shown below; "Use the
 * default" goes back to the config. Admins may change every value but the
 * ones marked board-only. Chain rules, rating values, NIP constants and
 * secrets are not on this page by construction.
 */
new #[Title('League settings')] #[Layout('layouts::app', ['section' => 'admin'])] class extends Component
{
    /** Field id (the config path with "-" for ".") => the input. */
    public array $form = [];

    public string $notice = '';

    public function mount(): void
    {
        Gate::authorize('admin');

        $this->fillForm();
    }

    /** The form field of a config path: Livewire reads dots as nesting. */
    public static function field(string $key): string
    {
        return str_replace('.', '-', $key);
    }

    /**
     * The newest changes, newest first.
     *
     * @return Collection<int, LeagueSettingChange>
     */
    #[Computed]
    public function log(): Collection
    {
        return LeagueSettingChange::query()->with('changedBy')->orderByDesc('id')->limit(50)->get();
    }

    public function save(): void
    {
        $this->store(collect(LeagueSettings::definitions())
            ->mapWithKeys(fn (array $definition, string $key): array => [$key => $this->form[self::field($key)] ?? null])
            ->filter(fn (mixed $input): bool => $input !== null)
            ->all());
    }

    public function useDefault(string $key): void
    {
        $this->store([$key => null]);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function store(array $values): void
    {
        Gate::authorize('admin');
        $admin = auth()->user();
        abort_unless($admin instanceof User, 403);
        $this->resetErrorBag();
        $this->notice = '';

        try {
            $changes = LeagueSettings::save($admin, $values);
        } catch (LeagueSettingRefused $refused) {
            foreach ($refused->errors as $key => $message) {
                $this->addError('form.'.self::field($key), $message);
            }

            return;
        }

        $this->notice = $changes === [] ? __('Nothing changed.') : trans_choice('Saved: :count value changed.|Saved: :count values changed.', count($changes));
        unset($this->log);
        $this->fillForm();
    }

    /** The form holds the values in force. */
    private function fillForm(): void
    {
        foreach (array_keys(LeagueSettings::definitions()) as $key) {
            $value = LeagueSettings::get($key);
            $this->form[self::field($key)] = is_array($value) ? implode(', ', $value) : (string) $value;
        }
    }
}; ?>

@php
    $input = 'h-11 w-full rounded-md border border-edge bg-ground px-3 text-[13px] text-ink disabled:opacity-60';
    $definitions = LeagueSettings::definitions();
    $overrides = LeagueSettings::overrides();
    $viewer = auth()->user();
    $byGroup = collect($definitions)->groupBy(fn (array $definition): string => $definition['group'], preserveKeys: true);
@endphp

<x-admin.page active="settings" :title="__('League settings')" :lead="__('The operational values of the league. Each field shows its default from the configuration; a change applies from the next page load or job and is logged below. Chain rules and rating values are on the season page; keys and secrets stay on the server.')" :notice="$notice" notice-test="settings-notice" data-test="admin-settings">
    <form wire:submit="save" class="flex flex-col gap-6" data-test="settings-form">
        @foreach (LeagueSettings::groups() as $group => $groupLabel)
            @continue(! $byGroup->has($group))
            @php
                // Neighbouring fields with the same help text (the 14 cup start days and times, the six pinned casual deadlines) share one note above them, not one copy each (measured at 375: the page 8177 → 7079 px, −1098).
                $sharedHelp = [];
                $runs = [];
                foreach ($byGroup[$group] as $key => $definition) {
                    $last = array_key_last($runs);
                    if ($last !== null && $runs[$last]['help'] === $definition['help']) {
                        $runs[$last]['keys'][] = $key;
                    } else {
                        $runs[] = ['help' => $definition['help'], 'keys' => [$key]];
                    }
                }
                foreach ($runs as $index => $run) {
                    if (count($run['keys']) > 1) {
                        foreach ($run['keys'] as $position => $key) {
                            $sharedHelp[$key] = ['id' => 'settings-help-'.$group.'-'.$index, 'first' => $position === 0];
                        }
                    }
                }
            @endphp
            <x-admin.panel :title="$groupLabel" :id="'settings-'.$group" data-test="settings-group-{{ $group }}">
                <div class="grid grid-cols-1 gap-x-6 gap-y-5 lg:grid-cols-2">
                    @foreach ($byGroup[$group] as $key => $definition)
                        @php
                            $field = $this->field($key);
                            $shared = $sharedHelp[$key] ?? null;
                            $overridden = array_key_exists($key, $overrides);
                            $mayChange = $viewer instanceof \App\Models\User && LeagueSettings::mayChange($viewer, $key);
                        @endphp
                        @if ($shared !== null && $shared['first'])
                            <p id="{{ $shared['id'] }}" class="m-0 text-xs leading-normal text-ink-2 lg:col-span-2" data-test="settings-shared-help">{{ $definition['help'] }}</p>
                        @endif
                        <div class="flex min-w-0 flex-col gap-1.5" wire:key="setting-{{ $field }}" data-test="setting-{{ $field }}">
                            <label for="setting-{{ $field }}" class="flex flex-wrap items-center gap-x-2 gap-y-1 text-[13px] font-bold text-ink">
                                {{ $definition['label'] }}
                                @if ($overridden)
                                    <span class="rounded-sm bg-well px-1.5 py-0.5 text-[11px] font-normal text-btc" data-test="setting-changed">{{ __('changed') }}</span>
                                @endif
                                @if ($definition['new_only'])
                                    <span class="rounded-sm bg-well px-1.5 py-0.5 text-[11px] font-normal text-ink-2" data-test="setting-new-only">{{ __('new ones only') }}</span>
                                @endif
                                @if ($definition['board_only'])
                                    <span class="rounded-sm bg-well px-1.5 py-0.5 text-[11px] font-normal text-ink-2" data-test="setting-board-only">{{ __('board only') }}</span>
                                @endif
                            </label>
                            @if ($definition['type'] === 'weekday')
                                <select id="setting-{{ $field }}" wire:model="form.{{ $field }}" class="{{ $input }}"@if ($shared !== null) aria-describedby="{{ $shared['id'] }}"@endif @disabled(! $mayChange)>
                                    @foreach (LeagueSettings::WEEKDAYS as $weekday)
                                        <option value="{{ $weekday }}">{{ __(ucfirst($weekday)) }}</option>
                                    @endforeach
                                </select>
                            @elseif ($definition['type'] === 'time')
                                <input id="setting-{{ $field }}" type="time" wire:model="form.{{ $field }}" class="{{ $input }}"@if ($shared !== null) aria-describedby="{{ $shared['id'] }}"@endif @disabled(! $mayChange)>
                            @else
                                <input id="setting-{{ $field }}" type="text" inputmode="{{ $definition['type'] === 'int' ? 'numeric' : 'text' }}" wire:model="form.{{ $field }}" class="{{ $input }}"@if ($shared !== null) aria-describedby="{{ $shared['id'] }}"@endif @disabled(! $mayChange)>
                            @endif
                            @error('form.'.$field)<span class="text-xs text-loss" role="alert" data-test="setting-error">{{ $message }}</span>@enderror
                            @if ($shared === null)
                                <span class="text-xs leading-normal text-ink-2">{{ $definition['help'] }}</span>
                            @endif
                            <span class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-ink-3">
                                <span>{{ LeagueSettings::rule($definition) }}</span>
                                <span data-test="setting-default">{{ __('Default: :value', ['value' => LeagueSettings::display(LeagueSettings::default($key))]) }}</span>
                                @if ($overridden && $mayChange)
                                    <button type="button" wire:click="useDefault('{{ $key }}')" wire:confirm="{{ __('Go back to the default for this value?') }}" class="inline-flex min-h-11 cursor-pointer items-center border-0 bg-transparent p-0 text-xs text-btc underline" data-test="setting-use-default">{{ __('Use the default') }}</button>
                                @endif
                            </span>
                            @if (! $mayChange)
                                <span class="text-xs text-ink-3">{{ __('Only a board member on the public admin list can change this value.') }}</span>
                            @endif
                        </div>
                    @endforeach
                </div>
            </x-admin.panel>
        @endforeach

        <span class="flex flex-wrap items-center gap-3">
            <x-button type="submit" icon="check" data-test="settings-save">{{ __('Save changes') }}</x-button>
        </span>
    </form>

    <x-admin.panel :title="__('Who changed what')" :meta="__('every change stays in the record')" flush data-test="settings-log">
        @forelse ($this->log as $change)
            @php($definition = $definitions[$change->key] ?? null)
            <div class="flex flex-col gap-0.5 border-t border-hairline py-2 text-[13px] first:border-t-0 sm:flex-row sm:items-baseline sm:gap-4" wire:key="change-{{ $change->id }}" data-test="settings-log-row">
                <span class="shrink-0 text-xs text-ink-2 sm:w-[180px]">{{ LeagueTime::stamp($change->created_at ?? now()) }}</span>
                <span class="min-w-0 [overflow-wrap:anywhere]">
                    <b>{{ $definition['label'] ?? $change->key }}</b>:
                    {{ LeagueSettings::display($change->before) }} → {{ $change->after === null ? __('default (:value)', ['value' => LeagueSettings::display(LeagueSettings::default($change->key))]) : LeagueSettings::display($change->after) }}
                    <span class="text-ink-2">{{ __('by :name', ['name' => $change->changedBy?->displayName() ?? substr($change->changed_by_pubkey, 0, 8)]) }}</span>
                </span>
            </div>
        @empty
            <x-admin.empty :text="__('No change yet: the configuration holds every value.')" />
        @endforelse
    </x-admin.panel>
</x-admin.page>
