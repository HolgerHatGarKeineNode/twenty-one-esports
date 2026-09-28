@props(['wait'])

{{--
    "Auto-decision in 04:12: Anna loses by forfeit" (P18, slice 5): the
    countdown to the league's automatic decision of a tournament match
    (App\Support\Tournaments\MatchWait), counted in the browser on the
    server's clock (resources/js/autoDecision.js). A paused tournament holds
    its series' deadlines, so it says so instead of counting.
--}}
@php
    /** @var \App\Support\Tournaments\MatchWait $wait */
    $left = $wait->decidesAt === null ? 0 : max(0, $wait->decidesAt->getTimestamp() - now()->getTimestamp());
    $clock = $left >= 86400
        ? sprintf('%d d %02d:%02d:%02d', intdiv($left, 86400), intdiv($left % 86400, 3600), intdiv($left % 3600, 60), $left % 60)
        : ($left >= 3600 ? sprintf('%d:%02d:%02d', intdiv($left, 3600), intdiv($left % 3600, 60), $left % 60) : sprintf('%02d:%02d', intdiv($left, 60), $left % 60));
    $soon = __('any moment');
@endphp

@if ($wait->decidesAt !== null)
    <span {{ $attributes->class('min-w-0 [overflow-wrap:anywhere]') }} data-test="auto-decision" data-at="{{ $wait->decidesAt->getTimestamp() }}">
        @if ($wait->paused)
            <span class="font-bold text-ink-2">{{ __('Paused: no deadline runs.') }}</span>
            <span class="text-ink-3">{{ __('Then: :what', ['what' => $wait->consequenceText()]) }}</span>
        @else
            {{ __('Auto-decision in') }}
            <b class="font-bold text-btc-hi tabular-nums" role="timer" data-test="auto-decision-clock"
               x-data="autoDecision({ at: {{ $wait->decidesAt->getTimestampMs() }}, now: {{ now()->getTimestampMs() }}, soon: @js($soon) })" x-text="text">{{ $left > 0 ? $clock : $soon }}</b>:
            {{ $wait->consequenceText() }}
        @endif
    </span>
@endif
