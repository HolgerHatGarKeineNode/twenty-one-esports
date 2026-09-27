@props([
    'model',
    'label',
    'value' => '',
    'test' => null,
    'inputClass' => 'h-11 w-full min-w-0 rounded-md border border-edge bg-ground px-3 text-[13px] text-ink [color-scheme:dark]',
])

{{--
    A date and time typed in the league's zone (App\Support\LeagueTime,
    Europe/Berlin), bound with wire:model to a `Y-m-d\TH:i` string property.
    Right under the input the zone as it stands on that date (MEZ or MESZ,
    with the UTC offset), then what will be saved, in the zone and in UTC,
    both updated live by resources/js/leagueTime.js. The label row holds only
    the label, so the input lines up with its neighbours in a form row. A time in the spring gap or
    the autumn hour shows the server's refusal before saving. Validate the
    property with LeagueTime::rule() and read it with LeagueTime::parse().
    `value`: the property's current value, so the first frame is the server's.
--}}
@php
    $id = 'lt-'.str_replace('.', '-', $model);
    $preview = \App\Support\LeagueTime::preview((string) $value);
@endphp

<div x-data="leagueTimeInput(@js(\App\Support\LeagueTime::client()))" {{ $attributes->class('flex min-w-0 flex-col gap-1.5 text-xs text-ink-2') }} @if ($test) data-test="{{ $test }}" @endif>
    <label for="{{ $id }}">{{ $label }}</label>
    <input id="{{ $id }}" type="datetime-local" wire:model="{{ $model }}" value="{{ $value }}" x-ref="input"
           x-on:input="update($event.target.value)" x-on:change="update($event.target.value)"
           aria-describedby="{{ $id }}-zone {{ $id }}-preview" class="{{ $inputClass }}" data-input>
    <span id="{{ $id }}-zone" class="text-ink-2" data-zone-label x-text="zone">{{ \App\Support\LeagueTime::zoneLabel((string) $value) }}</span>
    <span id="{{ $id }}-preview" aria-live="polite" data-preview data-state="{{ $preview['state'] }}" x-bind:data-state="state" x-text="text"
          class="min-h-[18px] leading-[18px] text-ink tabular-nums data-[state=invalid]:text-loss">{{ $preview['text'] }}</span>
    {{-- The server's refusal repeats the preview's word for word; say it once. --}}
    @error($model)@if ($message !== $preview['text'])<span class="text-loss" role="alert">{{ $message }}</span>@endif @enderror
</div>
