@props(['run', 'hints' => []])

{{--
    The replay viewer of one Blockfill run (plan "Blockfill", P5): the stored inputs played again on the shared engine
    and drawn by the game's own renderer (resources/js/stacker/replay-page.js, loaded by resources/js/stacker/page.js:
    the page's layout lists it), inside `wire:ignore`. The chain of 40 mined blocks is the seek bar. The replay page
    (`stacker.replay`) and a moment's page (`stacker.moment`) show it; who may see it is the page's question.
    $run: a StackerRun that keeps its replay (StackerReplays::watchable()); $hints: an admin's cheat hint lines.
    The slot is the top of the side column (whose run, or the moment's headline); `actions` the buttons under the facts.
--}}
@php
    $config = \App\Support\Stacker\StackerReplays::viewerConfig($run);
    $time = \App\Games\ScoreMetric::time()->format(\App\Games\Blockfill::milliseconds((int) $run->ticks));
    // The touch labels of the game page: the action, not the player's key.
    $actions_ = ['left' => '←', 'right' => '→', 'soft' => '↓', 'hard' => '⤓', 'ccw' => '↺', 'cw' => '↻', 'flip' => '180', 'hold' => __('Hold')];
    $actionNames = ['left' => __('Move left'), 'right' => __('Move right'), 'soft' => __('Soft drop'), 'hard' => __('Hard drop'), 'ccw' => __('Turn left'), 'cw' => __('Turn right'), 'flip' => __('Turn 180°'), 'hold' => __('Hold')];
    $order = ['left' => 0, 'right' => 1, 'soft' => 2, 'hard' => 3, 'cw' => 4, 'ccw' => 5, 'flip' => 6, 'hold' => 7];
@endphp
{{--
    One grid, in the order a phone reads it: the slot (who and when), the run, the chain with its controls, the details.
    From lg on the run stands left over two rows, the slot and the details to its right, the chain below both.
--}}
<div wire:ignore x-data="stackerReplay(@js($config))" class="mx-auto grid w-full max-w-[1340px] grid-cols-1 gap-6 pt-4 lg:grid-cols-[auto_minmax(0,1fr)] lg:gap-x-16 lg:gap-y-6 lg:pt-8" data-test="replay">
    {{-- What the page says above the run: whose run and which week, or a moment's headline --}}
    <header class="flex min-w-0 flex-col gap-3 lg:col-start-2 lg:row-start-1">
        {{ $slot }}
    </header>

    {{-- The run played again: hold, the well, next — the game's own layout --}}
    <section class="flex min-w-0 items-start justify-center gap-3 lg:col-start-1 lg:row-span-2 lg:row-start-1 lg:gap-6" aria-label="{{ __('The replayed run') }}">
        <div class="flex w-[56px] shrink-0 flex-col gap-2 bg-card p-2 lg:w-[112px] lg:p-3">
            <span class="text-[12px] font-bold text-ink-2 lg:text-sm">{{ __('Hold') }}</span>
            <canvas x-ref="hold" class="block h-[30px] w-full lg:h-[48px]" aria-hidden="true"></canvas>
        </div>
        <div x-ref="wellSlot" class="flex min-w-0 max-w-[300px] grow justify-center">
            <div class="relative border-2 border-[#24242B] bg-[#0E0E11]" style="box-shadow: -8px -8px 0 #141418;">
                <canvas x-ref="well" class="block" role="img" aria-label="{{ __('The well at the moment shown') }}" data-test="replay-well"></canvas>
                <p x-show="error" x-text="error" class="absolute inset-0 m-0 flex items-center justify-center bg-[#0A0A0B]/85 p-4 text-center text-[13px] text-loss" role="alert" data-test="replay-error"></p>
            </div>
        </div>
        <div class="flex w-[48px] shrink-0 flex-col gap-2 lg:w-[112px] lg:gap-3">
            <span class="text-[12px] font-bold text-ink-2 lg:text-sm">{{ __('Next') }}</span>
            @foreach (range(0, 4) as $i)
                <canvas data-next="{{ $i }}" @class(['block w-full bg-card', 'h-[36px] lg:h-[56px]' => $i === 0, 'h-[28px] lg:h-[44px]' => $i > 0]) aria-hidden="true"></canvas>
            @endforeach
        </div>
    </section>

    {{-- The seek bar: the chain of 40 mined blocks over the run's time, and the controls --}}
    <section class="flex min-w-0 flex-col gap-3 lg:col-span-2 lg:row-start-3" aria-labelledby="replay-chain-h">
        <div class="flex flex-wrap items-baseline justify-between gap-x-6 gap-y-1">
            <h2 id="replay-chain-h" class="m-0 text-sm font-bold">{{ __('The chain') }}</h2>
            <p class="m-0 text-[13px] text-ink-2">{{ __('Each cube is one mined block. Click one to jump to the moment it was mined.') }}</p>
        </div>
        <div x-ref="track" tabindex="0" role="slider" aria-labelledby="replay-chain-h" aria-valuemin="0" x-bind:aria-valuemax="total" x-bind:aria-valuenow="tick" x-bind:aria-valuetext="sliderText()"
             x-on:pointerdown="trackDown($event)" x-on:pointermove="trackMove($event)" x-on:pointerup="trackUp($event)" x-on:pointercancel="trackUp($event)" x-on:keydown="trackKey($event)"
             class="relative h-[52px] cursor-pointer touch-none border-b border-line outline-none select-none focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-btc lg:h-[84px]" data-test="replay-chain">
            <template x-for="column in columns" :key="column.tick">
                <span class="absolute bottom-[6px] flex -translate-x-1/2 flex-col-reverse gap-[2px] lg:gap-[5px]" x-bind:style="`left: ${column.left}%`">
                    <template x-for="n in column.count" :key="n">
                        <i class="block size-[7px] lg:size-[14px]" x-bind:class="column.tick <= tick ? 'bg-btc shadow-[-2px_-2px_0_var(--color-cube-side-mined)]' : 'border border-dashed border-edge'" data-test="replay-cube"></i>
                    </template>
                </span>
            </template>
            <span class="pointer-events-none absolute inset-y-0 w-[2px] -translate-x-1/2 bg-ink" x-bind:style="`left: ${total ? (tick / total) * 100 : 0}%`" aria-hidden="true" data-test="replay-head"></span>
        </div>
        <div class="flex justify-between text-xs text-ink-3 tabular-nums" aria-hidden="true">
            <span>0:00.00</span>
            <span x-text="time(total)"></span>
        </div>

        <div class="flex flex-wrap items-center gap-x-6 gap-y-3">
            <div class="flex items-center gap-2">
                <button type="button" x-on:click="step(-1)" class="inline-flex size-11 items-center justify-center rounded-md border border-line bg-well text-ink hover:border-edge" aria-label="{{ __('One tick back') }}" data-test="replay-back">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M6 5h2v14H6zM20 5v14L9 12z"/></svg>
                </button>
                <button type="button" x-on:click="toggle()" class="inline-flex h-11 min-w-[104px] items-center justify-center gap-2 rounded-md bg-btc px-4 text-[13px] font-bold text-on-btc" data-test="replay-play">
                    <svg x-show="!playing" width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M7 4v16l13-8z"/></svg>
                    <svg x-show="playing" width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M6 4h4v16H6zM14 4h4v16h-4z"/></svg>
                    <span x-text="playing ? t.pause : t.play">{{ __('Play') }}</span>
                </button>
                <button type="button" x-on:click="step(1)" class="inline-flex size-11 items-center justify-center rounded-md border border-line bg-well text-ink hover:border-edge" aria-label="{{ __('One tick on') }}" data-test="replay-forward">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M16 5h2v14h-2zM4 5v14l11-7z"/></svg>
                </button>
            </div>

            <div class="flex items-center gap-1" role="radiogroup" aria-label="{{ __('Playback speed') }}" data-test="replay-speed">
                @foreach (['0.5' => '0.5×', '1' => '1×', '2' => '2×', '4' => '4×'] as $value => $label)
                    <button type="button" role="radio" x-on:click="setSpeed({{ $value }})" x-bind:aria-checked="speed === {{ $value }} ? 'true' : 'false'"
                            x-bind:class="speed === {{ $value }} ? 'border-edge bg-raised text-ink' : 'border-transparent text-ink-2 hover:bg-well hover:text-ink'"
                            class="inline-flex h-11 min-w-11 items-center justify-center rounded-md border px-2 text-[13px] font-bold tabular-nums" data-test="replay-speed-{{ $value }}">{{ $label }}</button>
                @endforeach
            </div>

            <p class="m-0 text-[13px] whitespace-nowrap tabular-nums" data-test="replay-clock"><b x-text="time(tick)">0:00.00</b><span class="text-ink-3"> / </span><span class="text-ink-2" x-text="time(total)"></span> <span class="pl-2 text-ink-3" x-text="t.tick.replace(':n', tick)" data-test="replay-tick">{{ __('Tick :n', ['n' => 0]) }}</span></p>
        </div>
        <p class="m-0 text-xs text-ink-3 max-lg:hidden">{{ __('Space plays and pauses. Comma and full stop step one tick, the arrow keys on the chain jump from block to block.') }}</p>
    </section>

    {{-- The moment shown and the run as a whole --}}
    <section class="flex min-w-0 flex-col gap-5 lg:col-start-2 lg:row-start-2 lg:gap-6" aria-label="{{ __('The moment shown') }}">
        <div class="flex flex-col gap-3" data-test="replay-keys">
            <p class="m-0 text-sm font-bold" data-test="replay-block"><span x-text="blockLine()"></span> <span class="font-normal text-ink-2 tabular-nums" x-text="minedLine()"></span></p>
            <h2 class="m-0 text-xs font-normal text-ink-3"><span>{{ __('Keys down at') }}</span> <span class="tabular-nums" x-text="time(tick)"></span></h2>
            <ul class="m-0 flex list-none flex-wrap gap-2 p-0">
                @foreach ($actions_ as $action => $label)
                    <li>
                        <kbd class="inline-flex h-7 min-w-8 items-center justify-center rounded-sm border border-b-2 px-1.5 font-mono text-[13px]"
                             x-bind:class="held[{{ $order[$action] }}] ? 'border-btc bg-btc-chip text-btc-hi' : 'border-line text-ink-3'"
                             title="{{ $actionNames[$action] }}" data-test="replay-key-{{ $action }}">{{ $label }}</kbd>
                    </li>
                @endforeach
            </ul>
        </div>

        <dl class="m-0 grid grid-cols-3 gap-4 border-y border-line py-4 lg:max-w-[480px]" data-test="replay-facts">
            <div>
                <dt class="text-xs text-ink-3">{{ __('Time') }}</dt>
                <dd class="m-0 text-[18px] font-bold tabular-nums lg:text-[20px]">{{ $time }}</dd>
            </div>
            <div>
                <dt class="text-xs text-ink-3">{{ __('Pieces') }}</dt>
                <dd class="m-0 text-[18px] font-bold tabular-nums lg:text-[20px]" x-text="pieces" data-test="replay-pieces">–</dd>
            </div>
            <div>
                <dt class="text-xs text-ink-3">{{ __('Per second') }}</dt>
                <dd class="m-0 text-[18px] font-bold tabular-nums lg:text-[20px]" x-text="pps">–</dd>
            </div>
        </dl>

        @if ($hints !== [])
            <div class="flex flex-col gap-2 rounded-md bg-card p-4" data-test="replay-hints">
                <h2 class="m-0 text-sm font-bold">{{ __('Why the league holds this run') }}</h2>
                <ul class="m-0 flex list-disc flex-col gap-1 pl-5 text-[13px] text-ink-2">
                    @foreach ($hints as $line)
                        <li>{{ $line }}</li>
                    @endforeach
                </ul>
                <a href="{{ route('admin.blockfill') }}" class="inline-flex min-h-11 items-center self-start text-[13px] font-bold text-ink underline decoration-edge underline-offset-4 hover:decoration-ink">{{ __('Decide on the review list') }}</a>
            </div>
        @endif

        <p class="m-0 text-xs text-ink-3" x-show="ended !== null" x-text="ended ? @js(__('Played again here, the run ends exactly where the league\'s check ended.')) : @js(__('Played again here, the run does not end where the league\'s check ended.'))" data-test="replay-ended"></p>

        @isset($actions)
            <div class="flex flex-wrap gap-3">{{ $actions }}</div>
        @endisset
    </section>
</div>
