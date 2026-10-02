@props(['item', 'nowMs', 'suffix'])

{{--
    The state of an upcoming event and its running number (<livewire:upcoming-events>):
    "Starts in 3 h 20", "Check in now 4:10 left" (pulsing), "Live". `data-tick` lets
    resources/js/upcomingEvents.js count down and render the list again at zero.
--}}
@php
    $pulse = $item->phase === 'checkin' && $item->needsYou;
    $hot = $item->needsYou || $item->isLive();
@endphp
<span {{ $attributes->class(['flex min-w-0 flex-wrap items-center gap-x-1.5', 'text-btc' => $hot, 'text-ink-2' => ! $hot]) }}>
    @if ($item->isLive())
        <span aria-hidden="true" @class(['size-2 shrink-0 rounded-full bg-btc', 'animate-live' => $pulse])></span>
    @endif
    <span @class(['min-w-0 break-words', 'font-bold animate-live' => $pulse]) data-test="upcoming-state">{{ $item->state }}</span>
    @if ($item->tick)
        <span @class(['shrink-0 tabular-nums', 'text-loss' => $item->isUrgent($nowMs), 'text-ink' => ! $item->isUrgent($nowMs)]) data-tick='@json($item->tick)' data-suffix="{{ $suffix }}" data-test="upcoming-countdown">{{ str_replace(':left', $item->trailing, $suffix) }}</span>
    @elseif ($item->trailing !== '')
        <span class="min-w-0 truncate text-ink-2">{{ $item->trailing }}</span>
    @endif
</span>
