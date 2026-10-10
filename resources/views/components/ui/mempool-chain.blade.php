@props(['finished' => [], 'running' => [], 'label' => null])

{{--
    The mempool as a chain of cubes (design canvas Main.dc.html `.chain .cube`, rule R14): done on the left, the
    newest next to the dashed divider and lit in its game's colour, playing and waiting on the right, filling up as
    they go. Every cube links to its match. Drag, wheel, arrow keys or swipe move it (resources/js/mempoolChain.js);
    "Zur Gegenwart" brings the divider back. The cubes are App\Support\Matches\MempoolStrip's, the same as /matches.
--}}
@php
    $registry = app(\App\Games\GameRegistry::class);
    $groups = [['fin', $finished], ['run', $running]];
@endphp

<div class="rv-chain" x-data="mempoolChain" data-test="mempool-chain">
    <div class="rv-chain-vp" x-ref="viewport" tabindex="0" role="region" x-bind:class="dragging && 'is-dragging'"
         aria-label="{{ $label ?? __('Mempool chain, scrolls sideways: drag, wheel or arrow keys') }}">
        <div class="flex w-max items-start gap-3">
            @foreach ($groups as [$group, $blocks])
                @if ($group === 'run')
                    <span class="rv-chain-div" x-ref="divider" aria-hidden="true"></span>
                @endif
                <div class="flex items-start gap-3" role="list" aria-label="{{ $group === 'fin' ? __('Done, newest on the right') : __('Waiting and playing') }}">
                    @foreach ($blocks as $block)
                        @php
                            $score = $registry->isScore($block['slug']);
                            $waiting = $block['state'] !== 'fin';
                            $sides = array_values($block['sides']);
                        @endphp
                        <a href="{{ $block['href'] }}" @if ($block['blank'] ?? false) target="_blank" @endif class="rv-cw" role="listitem" aria-label="{{ $block['aria'] }}" draggable="false"
                           data-test="chain-cube" data-game="{{ $block['slug'] }}" data-state="{{ $block['state'] }}">
                            <span class="rv-cw-h">
                                <span>{{ $block['number'] }}</span>
                                @if ($score)
                                    <span class="rv-tag h-[18px]">{{ __('highscore') }}</span>
                                @elseif ($block['casual'])
                                    <span class="rv-tag h-[18px]">{{ __('casual') }}</span>
                                @endif
                            </span>
                            <span @class(['rv-cube', 'g-'.$block['game'], 'is-wait' => $waiting, 'is-newest' => $block['newest'] && ! $waiting]) aria-hidden="true">
                                <span class="rv-cube-tp"></span>
                                <span class="rv-cube-sd"></span>
                                <span class="rv-cube-fr">
                                    @if ($waiting)
                                        <span class="rv-cube-fill" style="height: {{ $block['level'] }}"></span>
                                    @endif
                                    <span class="rv-cube-k font-bold">{{ \App\Support\GameNames::cube($block['slug']) }}</span>
                                    <span class="rv-cube-v">{{ $block['score'] }}</span>
                                    <span class="rv-cube-k">{{ $block['mode'] }}</span>
                                    <span class="rv-cube-k">{{ $waiting && ! $score ? __('running') : $block['when'] }}</span>
                                </span>
                            </span>
                            <span class="rv-cw-p" aria-hidden="true">
                                @foreach ($sides as $index => $side)
                                    <span>{{ $index > 0 ? 'vs ' : '' }}{{ $side['name'] }}</span>
                                @endforeach
                            </span>
                        </a>
                    @endforeach
                </div>
            @endforeach
        </div>
    </div>
    <span class="rv-chain-fade is-l" x-bind:style="{ opacity: left ? 1 : 0 }" style="opacity: 0" aria-hidden="true"></span>
    <span class="rv-chain-fade is-r" x-bind:style="{ opacity: right ? 1 : 0 }" aria-hidden="true"></span>
    <button type="button" class="rv-b2 is-sm absolute top-1/2 right-0 -translate-y-1/2 bg-ground" x-show="away" x-cloak x-on:click="present()" data-test="chain-present">
        {{ __('Back to the present') }}
    </button>
</div>
