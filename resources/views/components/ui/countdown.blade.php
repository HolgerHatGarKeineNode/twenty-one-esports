@props(['at' => null, 'size' => 'md', 'label' => null])

{{--
    Segmented countdown (design canvas Components.dc.html "Countdown-Segmente", Main.dc.html hero and season card):
    days, hours and minutes as three Unbounded figures in their own boxes, a quiet colon between. Without a moment
    the boxes show "--" (a date that is not set yet). The first frame comes from the server; Alpine counts on once
    a minute, so nothing jumps. `label`: the accessible name ahead of the time ("Start in").
--}}
@php
    $ms = $at?->getTimestampMs();
    $left = $at === null ? null : max(0, (int) ceil(now()->diffInSeconds($at, false)));
    $parts = $left === null ? ['--', '--', '--'] : [sprintf('%02d', intdiv($left, 86400)), sprintf('%02d', intdiv($left % 86400, 3600)), sprintf('%02d', intdiv($left % 3600, 60))];
    $units = [__('Days'), __('Hrs'), __('Mins')];
    $spoken = $left === null
        ? __('date coming soon')
        : trans_choice(':count day|:count days', (int) $parts[0]).', '.trans_choice(':count hour|:count hours', (int) $parts[1]).', '.trans_choice(':count minute|:count minutes', (int) $parts[2]);
@endphp

<span role="timer" aria-label="{{ trim(($label ? $label.' ' : '').$spoken) }}" {{ $attributes->class(['rv-cd', 'is-sm' => $size === 'sm']) }}
      @if ($ms) x-data="segmentCountdown({{ $ms }}, @js($parts))" @endif
      data-test="countdown-segments">
    @foreach ($parts as $index => $part)
        @if ($index > 0)
            <span class="rv-cd-colon" aria-hidden="true">:</span>
        @endif
        <span class="rv-cd-seg" aria-hidden="true">
            <b @if ($ms) x-text="p[{{ $index }}]" @endif>{{ $part }}</b>
            <span class="rv-m">{{ $units[$index] }}</span>
        </span>
    @endforeach
</span>
