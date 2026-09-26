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

        foreach (app(GameRegistry::class)->all() as $game) {
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
    $input = 'h-11 w-full rounded-md border border-edge bg-ground px-3 text-sm text-ink';
    $days = [1 => __('Monday'), 2 => __('Tuesday'), 3 => __('Wednesday'), 4 => __('Thursday'), 5 => __('Friday'), 6 => __('Saturday'), 7 => __('Sunday')];
@endphp

<div class="flex grow flex-col" data-test="admin-events">
    <x-admin.nav active="events" />

    <div class="flex flex-col gap-5 px-4 pt-8 pb-10 lg:px-12">
        <span class="flex flex-wrap items-baseline gap-4"><h1 class="m-0 font-display text-[28px] font-bold lg:text-[34px]">{{ __('Weekly events') }}</h1><span class="text-[13px] text-ink-2">{{ __('Fixed evenings the league plays together') }}</span></span>
        <p class="m-0 max-w-[70ch] text-[13px] leading-normal text-ink-2">{{ __('A weekly event repeats on its day and time in its time zone. The next week of dates is created automatically every hour; home and the chess lobby show the next ones.') }}</p>

        <form wire:submit="add" class="grid grid-cols-1 gap-3 rounded-lg bg-card px-4 py-4 sm:grid-cols-2 lg:grid-cols-[minmax(0,2fr)_minmax(0,1.4fr)_minmax(0,1fr)_110px_minmax(0,1.4fr)_110px_auto] lg:items-end lg:px-6" data-test="slot-form">
            <label class="flex min-w-0 flex-col gap-1 text-xs text-ink-2">{{ __('Title') }}<input type="text" wire:model="title" maxlength="80" placeholder="{{ __('Blitz night') }}" class="{{ $input }}" data-test="slot-title"></label>
            <label class="flex min-w-0 flex-col gap-1 text-xs text-ink-2">{{ __('Game and mode') }}
                <select wire:model="ladder" class="{{ $input }}" data-test="slot-ladder">
                    @foreach ($this->ladders as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label class="flex min-w-0 flex-col gap-1 text-xs text-ink-2">{{ __('Day') }}
                <select wire:model="weekday" class="{{ $input }}" data-test="slot-weekday">
                    @foreach ($days as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label class="flex min-w-0 flex-col gap-1 text-xs text-ink-2">{{ __('Time') }}<input type="time" wire:model="time" class="{{ $input }}" data-test="slot-time"></label>
            <label class="flex min-w-0 flex-col gap-1 text-xs text-ink-2">{{ __('Time zone') }}<input type="text" wire:model="timezone" class="{{ $input }}" data-test="slot-timezone"></label>
            <label class="flex min-w-0 flex-col gap-1 text-xs text-ink-2">{{ __('Minutes') }}<input type="number" wire:model="duration" min="15" max="720" step="15" class="{{ $input }}" data-test="slot-duration"></label>
            <button type="submit" class="btn-p h-11 cursor-pointer rounded-md bg-btc px-4 text-[13px] font-bold text-on-btc" data-test="slot-add">{{ __('Add event') }}</button>
            @if ($errors->any())
                <p class="m-0 text-[13px] text-loss sm:col-span-2 lg:col-span-7" data-test="slot-error">{{ $errors->first() }}</p>
            @endif
        </form>

        <section aria-labelledby="slots-h" class="flex flex-col rounded-lg bg-card px-4 py-2 lg:px-6">
            <h2 id="slots-h" class="m-0 py-3 text-[15px] font-bold">{{ __('Events') }}</h2>
            @forelse ($this->weeklySlots as $slot)
                @php($next = $this->nextEvents[$slot->id] ?? null)
                <div wire:key="slot-{{ $slot->id }}" class="grid grid-cols-1 gap-2 border-t border-hairline py-3 text-[13px] lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] lg:items-center lg:gap-4" data-test="slot-row">
                    <span class="flex min-w-0 flex-col gap-0.5">
                        <b class="truncate">{{ $slot->title }}</b>
                        <span class="text-xs text-ink-2">{{ $this->ladders[$slot->game.'/'.$slot->mode] ?? $slot->game.' · '.$slot->mode }} · {{ __(':day :time (:zone), :minutes min', ['day' => $days[$slot->weekday] ?? '?', 'time' => $slot->time, 'zone' => $slot->timezone, 'minutes' => $slot->duration_minutes]) }}</span>
                    </span>
                    <span class="text-xs text-ink-2">
                        @if (! $slot->active)
                            <span class="text-ink-3" data-test="slot-paused">{{ __('Paused') }}</span>
                        @elseif ($next)
                            {{ __('Next: :when', ['when' => CarbonImmutable::parse($next->starts_at)->setTimezone($slot->timezone)->locale(app()->getLocale())->isoFormat('ddd YYYY-MM-DD HH:mm')]) }}
                        @endif
                    </span>
                    <span class="flex flex-wrap gap-2">
                        <button type="button" wire:click="toggle({{ $slot->id }})" class="h-11 cursor-pointer rounded-md border border-edge bg-transparent px-3 text-xs text-ink" data-test="slot-toggle">{{ $slot->active ? __('Pause') : __('Resume') }}</button>
                        <button type="button" wire:click="remove({{ $slot->id }})" wire:confirm="{{ __('Delete this weekly event and its dates?') }}" class="h-11 cursor-pointer rounded-md border border-[#5A2A2E] bg-transparent px-3 text-xs text-loss" data-test="slot-remove">{{ __('Delete') }}</button>
                    </span>
                </div>
            @empty
                <p class="m-0 border-t border-hairline py-6 text-[13px] text-ink-2">{{ __('No weekly events yet.') }}</p>
            @endforelse
        </section>
    </div>
</div>
