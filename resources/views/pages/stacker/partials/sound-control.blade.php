{{--
    Blockfill sound (plan "Blockfill", P8): effects and music, each a switch and a
    volume of ten blocks, like the chain of mined blocks below the well. Silent until
    the first click or key press; a player's choice is saved, a guest's stays in this
    browser. Inside stackerGame's x-data (resources/js/stacker/page.js).

    Rendered twice: in the title row from lg, below the well on phones and tablets,
    so the title row stays one line high there and the well keeps its room above
    the touch panel. Only one is displayed at a time; `$class` says which.
--}}
<div @class(['items-center gap-4 lg:gap-6', $class]) role="group" aria-label="{{ __('Sound') }}" data-test="sound">
    @foreach (['effects' => [__('Effects'), 'volume', 'mute'], 'music' => [__('Music'), 'music', 'music-off']] as $channel => [$label, $iconOn, $iconOff])
        <div class="flex min-w-0 flex-1 items-center gap-2 lg:flex-none" data-test="sound-{{ $channel }}">
            <button type="button" x-on:click="toggleSound('{{ $channel }}')" x-bind:aria-pressed="sound.{{ $channel }}On ? 'true' : 'false'"
                    class="inline-flex h-11 min-w-11 shrink-0 cursor-pointer items-center justify-center gap-2 rounded-md border px-2.5 text-[13px] font-bold sm:px-3"
                    x-bind:class="sound.{{ $channel }}On ? 'border-btc-ring bg-btc-chip text-btc' : 'border-line text-ink-3 hover:text-ink-2'"
                    data-test="sound-{{ $channel }}-toggle">
                <span x-show="sound.{{ $channel }}On" class="inline-flex"><x-icon :name="$iconOn" :size="18" /></span>
                <span x-show="!sound.{{ $channel }}On" x-cloak class="inline-flex"><x-icon :name="$iconOff" :size="18" /></span>
                <span class="sr-only sm:not-sr-only">{{ $label }}</span>
            </button>
            <input type="range" min="0" max="100" step="10" class="blockfill-volume h-11 w-full min-w-0 lg:w-[120px]"
                   x-bind:value="sound.{{ $channel }}" x-on:input="setVolume('{{ $channel }}', $event.target.value)"
                   x-bind:style="`--fill: ${sound.{{ $channel }}}%`" x-bind:data-off="sound.{{ $channel }}On ? null : ''"
                   aria-label="{{ __('Volume: :channel', ['channel' => $label]) }}" x-bind:aria-valuetext="sound.{{ $channel }} + ' %'"
                   data-test="sound-{{ $channel }}-volume">
        </div>
    @endforeach
</div>
