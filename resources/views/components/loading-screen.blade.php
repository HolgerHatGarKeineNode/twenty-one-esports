@props(['fullscreen' => true])

{{--
    Loading screen from States.dc.html ("Mining blocks"): five cubes drop in one
    after another while the nonce rolls. With reduced motion the cubes simply
    stand still. `fullscreen` covers the viewport (first visit), otherwise it
    fills its container.
--}}
<div role="status" aria-label="{{ __('Loading') }}"
     {{ $attributes->class([
         'flex flex-col items-center justify-center gap-[22px] p-5',
         'fixed inset-0 z-[60] bg-ground' => $fullscreen,
     ]) }}>
    <div class="flex h-14 items-end gap-5 pt-[18px] sm:gap-[26px]" aria-hidden="true">
        @foreach (['#B9640A', '#E88710', '#F7931A', '#F8A23A', '#F9B25F'] as $index => $colour)
            <span class="cube mine size-9 sm:size-11" style="background: {{ $colour }}; animation-delay: {{ $index * 0.4 }}s"></span>
        @endforeach
    </div>
    <span class="font-display text-lg font-bold">{{ __('Mining blocks') }}</span>
    <span class="flex items-center gap-2 text-[13px] text-ink-2" aria-hidden="true">
        Nonce 0x3f2a
        @foreach (['1.6s', '.4s'] as $index => $duration)
            <span @class(['inline-block h-[1.3em] overflow-hidden leading-[1.3em] text-btc', '-ml-2' => $index === 1])>
                <span class="roll" style="animation-duration: {{ $duration }}">
                    @foreach (range(0, 9) as $digit)
                        <span class="block">{{ $digit }}</span>
                    @endforeach
                </span>
            </span>
        @endforeach
    </span>
</div>
