@props(['ticks' => []])

{{--
    "gerade eben" (design canvas `.tick`, Main.dc.html): the league's latest events in one 32 px row under the key
    art: a move in a running game, a result, a checked run (App\Support\Engagement\HomeBoard::ticker()). Only event
    types the league records. It does not scroll by itself (readable tempo, rule R17); a narrow screen swipes it.
--}}
@if ($ticks !== [])
    <div role="log" aria-label="{{ __('just now') }}" {{ $attributes->class('rv-tick overflow-x-auto [scrollbar-width:none]') }} data-test="live-bar">
        <span class="rv-live h-5 shrink-0" aria-hidden="true"><i></i>{{ __('just now') }}</span>
        @foreach ($ticks as $index => $tick)
            @if ($index > 0)
                <span class="rv-tick-sep" aria-hidden="true"></span>
            @endif
            <a href="{{ $tick['href'] }}" class="flex shrink-0 items-center gap-2 text-ink hover:text-ink" data-test="live-bar-item">
                <span class="text-ink-3">{{ $tick['kind'] }}</span>
                <span>{{ $tick['text'] }}</span>
                <span class="text-ink-3">{{ $tick['at']->diffForHumans(['parts' => 1, 'short' => true]) }}</span>
            </a>
        @endforeach
    </div>
@endif
