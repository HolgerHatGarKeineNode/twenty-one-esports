@props([
    'finished' => [],
    'running' => [],
    'size' => 'auto',
    'focus' => null,
    'legend' => true,
])

{{--
    Global block strip, 1:1 from BlockStrip.dc.html (approved 2026-09-25).
    A read-only feed across all games: finished blocks left (newest next to the
    divider), running and scheduled blocks right. When the row is wider than the
    screen it opens on the divider: the newest finished block sits at the left
    edge. Every cube shows its game by colour AND logo, and links to its detail
    page; there are no actions inside.

    size:  md (120 px cubes), sm (100 px, mobile), auto (sm below 1024 px, md above)
    focus: chess|rl marks that game's blocks on a game page; nothing is hidden
--}}
@php
    $knight = '<svg class="bs-logo" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" fill-rule="evenodd" d="M17 18C17.5 12 17 6.5 12.5 4L11.5 2L10 4.2C8 5.2 6 7.8 4.6 10.2C4.3 10.9 4.7 11.7 5.4 11.9L6.4 12.3C7.1 12.5 7.9 12.2 8.3 11.6L9.6 10.6C10.3 10.3 10.8 10.4 11.2 10.8C9.4 12.8 8 15 7.6 18ZM9.9 6.2a.9 .9 0 1 0 .01 0ZM5 19.5h14V22H5z"></path></svg>';
    $rlSlot = '<span class="bs-logo bs-slot" aria-hidden="true">RL</span>';
    $groups = [['fin', $finished], ['run', $running]];
@endphp

<section {{ $attributes->class(['bs', 'bs--sm' => $size === 'sm', 'bs--auto' => $size === 'auto', 'bs--focus-'.$focus => $focus]) }}
         aria-label="{{ __('Latest blocks across all games, finished on the left, running and next on the right') }}">
    <div class="bs-scroll w-full" x-data x-init="$nextTick(() => { if ($el.scrollWidth > $el.clientWidth) { const newest = $el.querySelector('.bs-grp--fin > .bs-col:last-child'); if (newest) { $el.scrollLeft = newest.offsetLeft - parseFloat(getComputedStyle($el.querySelector('.bs-pad')).paddingLeft); } } })">
        <div class="bs-row bs-pad mx-auto">
            @foreach ($groups as [$group, $blocks])
                @if ($group === 'run')
                    <span class="bs-div" aria-hidden="true"></span>
                @endif
                <div @class(['bs-grp', 'bs-grp--fin' => $group === 'fin'])>
                    @foreach ($blocks as $block)
                        <div @class(['bs-col', 'is-newest' => $block['newest']])>
                            <span @class(['bs-h', 'bs-h--next' => $block['state'] !== 'fin'])>
                                <span class="bs-num">{{ $block['height'] }}</span>
                                @if ($block['casual'])
                                    <span class="bs-tag">{{ __('casual') }}</span>
                                @endif
                            </span>
                            <a href="{{ $block['href'] }}"
                               @class(['bs-cube', 'g-'.$block['game'], 'is-'.$block['state'], 'is-casual' => $block['casual']])
                               style="--lvl: {{ $block['level'] }}"
                               aria-label="{{ $block['aria'] }}">
                                @if ($block['state'] === 'live')
                                    <span class="bs-fill" aria-hidden="true"></span>
                                @endif
                                <span class="bs-r1">{!! $block['game'] === 'chess' ? $knight : $rlSlot !!}{{ $block['mode'] }}</span>
                                <span @class(['bs-score', 'bs-score--word' => $block['word']])>{{ $block['score'] }}</span>
                                <span class="bs-who">{{ $block['who'] }}</span>
                                <span class="bs-when">
                                    @if ($block['dot'])
                                        <span class="bs-dot" aria-hidden="true"></span>
                                    @endif
                                    {{ $block['when'] }}
                                </span>
                            </a>
                            <span class="bs-cap" aria-hidden="true"><span>{{ $block['a'] }}</span><span>{{ $block['b'] }}</span></span>
                        </div>
                    @endforeach
                </div>
            @endforeach
        </div>
    </div>

    @if ($legend)
        <div class="bs-legend-wrap w-full">
            <div class="bs-legend">
                <span class="bs-key"><span class="bs-chip" aria-hidden="true" style="background: linear-gradient(180deg, #22D3EE, #0D9488)"></span><span class="flex text-chess">{!! $knight !!}</span>{{ __('Chess') }}</span>
                <span class="bs-key"><span class="bs-chip" aria-hidden="true" style="background: linear-gradient(180deg, #F97316, #EC4899)"></span><span class="flex text-rl">{!! $rlSlot !!}</span>Rocket League</span>
                <span class="bs-sep" aria-hidden="true"></span>
                <span class="bs-key"><span class="bs-chip bg-ink-2" aria-hidden="true"></span>{{ __('Finished') }}</span>
                <span class="bs-key"><span class="bs-chip" aria-hidden="true" style="border: 1px solid #ADADB0; background: linear-gradient(0deg, #ADADB0 50%, transparent 50%)"></span>{{ __('Playing, fills up as it goes') }}</span>
                <span class="bs-key"><span class="bs-chip" aria-hidden="true" style="border: 2px solid #ADADB0"></span>{{ __('Up next') }}</span>
                <span class="bs-key"><span class="bs-tag">{{ __('casual') }}</span>{{ __('Unrated') }}</span>
            </div>
        </div>
    @endif
</section>
