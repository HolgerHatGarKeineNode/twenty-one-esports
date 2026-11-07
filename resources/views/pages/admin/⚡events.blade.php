<?php

use App\Games\GameRegistry;
use App\Models\SlotEvent;
use App\Models\WeeklySlot;
use App\Support\Engagement\WeeklySlots;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * Weekly events (P10): an admin defines recurring slots ("Blitz night,
 * Wednesday 20:00"); `events:schedule-weekly` dates them hourly for the
 * next week, and saving a slot dates it at once. Home and the chess lobby
 * show the upcoming events. Pausing a slot hides its events; deleting it
 * removes them.
 */
new #[Title('Weekly events')] #[Layout('layouts::app', ['section' => 'admin'])] class extends Component {
    public string $title = '';

    public string $ladder = 'chess/blitz';

    public int $weekday = 3;

    public string $time = '20:00';

    public string $timezone = '';

    public int $duration = 120;

    public function mount(): void
    {
        Gate::authorize('admin');

        $this->timezone = (string) config('esports.preseason.display_timezone', 'UTC');
    }

    /**
     * Every game and mode a slot can be for, `game/mode` => label.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function ladders(): array
    {
        $ladders = [];

        // Every game of the registry, the board games that are switched on included (plan "Mühle und Dame", P6); no score
        // game, which has no Elo ladder (plan "AoE2 und Trackmania", P4).
        foreach (app(GameRegistry::class)->versus() as $game) {
            // Hyperbitcoinization (P6) and Proof of Pong (P4) keep their ratings in their own tables: no Rating ladder an event could point at.
            if (in_array($game->kind(), [\App\Games\GameKind::Strategy, \App\Games\GameKind::Arcade], true)) {
                continue;
            }

            foreach ($game->modes() as $mode) {
                $ladders[$game->slug().'/'.$mode->slug] = __($game->name()).' · '.__($mode->name);
            }
        }

        return $ladders;
    }

    /**
     * @return Collection<int, WeeklySlot>
     */
    #[Computed]
    public function weeklySlots(): Collection
    {
        return WeeklySlot::query()->orderBy('weekday')->orderBy('time')->get();
    }

    /**
     * The next event of every slot, slot id => event.
     *
     * @return array<int, SlotEvent>
     */
    #[Computed]
    public function nextEvents(): array
    {
        return SlotEvent::query()->where('ends_at', '>', now())->orderBy('starts_at')->get()->unique('weekly_slot_id')->keyBy('weekly_slot_id')->all();
    }

    public function add(WeeklySlots $slots): void
    {
        Gate::authorize('admin');

        $this->validate([
            'title' => ['required', 'string', 'max:80'],
            'ladder' => ['required', Rule::in(array_keys($this->ladders))],
            'weekday' => ['required', 'integer', 'between:1,7'],
            'time' => ['required', 'regex:'.WeeklySlot::TIME_PATTERN],
            'timezone' => ['required', Rule::in(timezone_identifiers_list())],
            'duration' => ['required', 'integer', 'between:15,720'],
        ]);

        [$game, $mode] = explode('/', $this->ladder, 2);

        WeeklySlot::query()->create([
            'title' => trim($this->title),
            'game' => $game,
            'mode' => $mode,
            'weekday' => $this->weekday,
            'time' => $this->time,
            'timezone' => $this->timezone,
            'duration_minutes' => $this->duration,
            'created_by_id' => auth()->id(),
        ]);

        $slots->schedule();

        $this->reset('title');
        unset($this->weeklySlots, $this->nextEvents);
    }

    public function toggle(int $slotId, WeeklySlots $slots): void
    {
        Gate::authorize('admin');

        $slot = WeeklySlot::query()->findOrFail($slotId);
        $slot->update(['active' => ! $slot->active]);
        $slots->schedule();

        unset($this->weeklySlots, $this->nextEvents);
    }

    public function remove(int $slotId): void
    {
        Gate::authorize('admin');

        WeeklySlot::query()->whereKey($slotId)->delete();

        unset($this->weeklySlots, $this->nextEvents);
    }
}; ?>

@php
    $input = 'rv-in';
    $days = [1 => __('Monday'), 2 => __('Tuesday'), 3 => __('Wednesday'), 4 => __('Thursday'), 5 => __('Friday'), 6 => __('Saturday'), 7 => __('Sunday')];
@endphp

{{-- AdminEvents.dc.html: "Termin anlegen" (six fields in one row, the button below), then "Termine". --}}
<x-admin.page active="events" :title="__('Weekly events')" :lead="__('A weekly event repeats on its day and time in its time zone. The next week of dates is created automatically every hour; home and the chess lobby show the next ones.')" data-test="admin-events">
    <x-slot:readout>
        <span class="flex items-baseline gap-2"><span class="rv-m">{{ __('Weekly events') }}</span><span class="rv-num text-base text-ink" data-test="readout-value">{{ $this->weeklySlots->count() }}</span></span>
    </x-slot:readout>

    <x-admin.panel :title="__('Add event')" id="slot-form-h">
        <form wire:submit="add" class="flex flex-col gap-3" data-test="slot-form">
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-[minmax(0,2fr)_minmax(0,2fr)_minmax(8.5rem,1fr)_minmax(8.5rem,1fr)_minmax(0,2fr)_minmax(0,1fr)] lg:items-end">
                <label class="min-w-0"><span class="rv-lb">{{ __('Title') }}</span><input type="text" wire:model="title" maxlength="80" placeholder="{{ __('Blitz night') }}" class="{{ $input }}" data-test="slot-title"></label>
                <div class="min-w-0"><span class="rv-lb" id="slot-ladder-l">{{ __('Game and mode') }}</span>
                    <x-ui.select-menu model="ladder" labelledby="slot-ladder-l" :value="$this->ladder" :options="$this->ladders" data-test="slot-ladder" /></div>
                <div class="min-w-0"><span class="rv-lb" id="slot-weekday-l">{{ __('Day') }}</span>
                    <x-ui.select-menu model="weekday" labelledby="slot-weekday-l" :value="$this->weekday" :options="$days" data-test="slot-weekday" /></div>
                <label class="min-w-0"><span class="rv-lb">{{ __('Time') }}</span><input type="time" wire:model="time" class="{{ $input }}" data-test="slot-time"></label>
                <label class="min-w-0"><span class="rv-lb">{{ __('Time zone') }}</span><input type="text" wire:model="timezone" class="{{ $input }}" data-test="slot-timezone"></label>
                <label class="min-w-0"><span class="rv-lb">{{ __('Minutes') }}</span><input type="number" wire:model="duration" min="15" max="720" step="15" class="{{ $input }}" data-test="slot-duration"></label>
            </div>
            @if ($errors->any())
                <p class="m-0 text-[13px] text-loss" role="alert" data-test="slot-error">{{ $errors->first() }}</p>
            @endif
            <button type="submit" class="rv-b self-start" data-test="slot-add">{{ __('Add event') }}</button>
        </form>
    </x-admin.panel>

    <x-admin.panel :title="__('Events')" id="slots-h">
        <div class="rv-rows flex flex-col">
            @forelse ($this->weeklySlots as $slot)
                @php($next = $this->nextEvents[$slot->id] ?? null)
                <div wire:key="slot-{{ $slot->id }}" class="grid grid-cols-1 gap-2 py-3 text-[13px] lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] lg:items-center lg:gap-4" data-test="slot-row">
                    <span class="flex min-w-0 flex-col gap-0.5">
                        <b class="truncate">{{ $slot->title }}</b>
                        <span class="rv-m">{{ $this->ladders[$slot->game.'/'.$slot->mode] ?? $slot->game.', '.$slot->mode }}, {{ __(':day :time (:zone), :minutes min', ['day' => $days[$slot->weekday] ?? '?', 'time' => $slot->time, 'zone' => $slot->timezone, 'minutes' => $slot->duration_minutes]) }}</span>
                    </span>
                    <span class="text-xs text-ink-2">
                        @if (! $slot->active)
                            <span class="rv-tag" data-test="slot-paused">{{ __('Paused') }}</span>
                        @elseif ($next)
                            {{ __('Next: :when', ['when' => \App\Support\Formats::date(CarbonImmutable::parse($next->starts_at), true, $slot->timezone)]) }}
                        @endif
                    </span>
                    <span class="flex flex-wrap gap-2">
                        <button type="button" wire:click="toggle({{ $slot->id }})" class="rv-b2 is-sm" data-test="slot-toggle">{{ $slot->active ? __('Pause') : __('Resume') }}</button>
                        <button type="button" wire:click="remove({{ $slot->id }})" wire:confirm="{{ __('Delete this weekly event and its dates?') }}" class="rv-bd is-sm" data-test="slot-remove">{{ __('Delete') }}</button>
                    </span>
                </div>
            @empty
                <x-admin.empty :text="__('No weekly events yet. Add the first one above.')" />
            @endforelse
        </div>
    </x-admin.panel>
</x-admin.page>
