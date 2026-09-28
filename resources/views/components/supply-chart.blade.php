@props(['curve', 'zone', 'ended' => false])

@php
    /*
     * The supply chart of /mining (P33, Mining.dc.html "Supply: mined and
     * left"): sats mined over time on one axis, from Block 0 to the season
     * end. A solid line for what was mined (one point per UTC day with
     * blocks), a dashed line to the estimator's total at the season end,
     * a hairline per halving, the supply as the top line when the curve
     * comes near it. Hover or arrow keys: a crosshair and one readout;
     * the same numbers stay reachable in the table below.
     */
    $sats = fn (int $value): string => \App\Support\PreSeason::formatSats($value);
    $day = fn (\Carbon\CarbonImmutable $at): string => $at->setTimezone($zone)->locale(app()->getLocale())->translatedFormat('D j M');
    $from = $curve['from'];
    $to = $curve['to'];
    $span = max(1, $to->getTimestamp() - $from->getTimestamp());
    $x = fn (\Carbon\CarbonImmutable $at): float => round(min(1, max(0, ($at->getTimestamp() - $from->getTimestamp()) / $span)) * 100, 3);
    $mined = $curve['days'] === [] ? 0 : $curve['days'][array_key_last($curve['days'])]['total'];
    $forecast = $curve['forecast'];
    $top = max($mined, (int) $forecast, 1);

    // One axis: a round top a little above the curve, or the supply once the curve comes near it.
    $showSupply = $top * 1.1 >= $curve['supply'] * 0.75;
    if ($showSupply) {
        $yMax = $curve['supply'];
    } else {
        $magnitude = 10 ** (int) floor(log10($top * 1.1));
        $yMax = (int) (collect([1, 2, 2.5, 5, 10])->first(fn ($step) => $step * $magnitude >= $top * 1.1) * $magnitude);
    }
    $y = fn (int $value): float => round(100 - min(1, $value / max(1, $yMax)) * 100, 3);
    $ticks = array_map(fn (int $i): int => (int) round($yMax * $i / 4), [1, 2, 3]);

    // The points of the line and the readout: Block 0, every day with blocks, then today (or the end).
    $points = [['x' => 0.0, 'y' => 100.0, 'value' => '0', 'label' => __('Block 0, :day', ['day' => $day($from)]), 'detail' => '']];
    foreach ($curve['days'] as $row) {
        $points[] = [
            'x' => $x($row['at']), 'y' => $y($row['total']), 'value' => $sats($row['total']),
            'label' => $day($row['at']),
            'detail' => trans_choice(':count block, +:sats|:count blocks, +:sats', $row['blocks'], ['sats' => $sats($row['sats'])]),
        ];
    }
    $nowX = $x($curve['now']);
    if ($nowX > $points[array_key_last($points)]['x']) {
        $points[] = ['x' => $nowX, 'y' => $y($mined), 'value' => $sats($mined), 'label' => $forecast !== null ? __('Today') : __('Season end, :day', ['day' => $day($to)]), 'detail' => ''];
    }
    $minedLine = collect($points)->map(fn (array $p): string => ($p['x'] * 10).','.($p['y'] * 10))->implode(' ');
    $readout = $points;
    if ($forecast !== null) {
        $readout[] = ['x' => 100.0, 'y' => $y($forecast), 'value' => '~'.$sats($forecast), 'label' => __('Season end, :day', ['day' => $day($to)]), 'detail' => __('forecast from the last 4 weeks'), 'forecast' => true];
    }
    $halvings = collect($curve['halvings'] ?? [])->map(fn (array $era): array => ['x' => $x($era['from']), 'era' => $era['era']])->filter(fn (array $era): bool => $era['x'] > 0 && $era['x'] < 100)->values();
    $summary = $forecast !== null
        ? __('Sats mined over time: :mined of :supply by today, forecast :forecast at the season end.', ['mined' => $sats($mined), 'supply' => $sats($curve['supply']), 'forecast' => $sats($forecast)])
        : __('Sats mined over time: :mined of :supply by the season end.', ['mined' => $sats($mined), 'supply' => $sats($curve['supply'])]);
@endphp

<div {{ $attributes->class('flex flex-col gap-3') }} data-test="supply-chart">
    <ul class="m-0 flex list-none flex-wrap gap-x-5 gap-y-1 p-0 text-xs text-ink-2" aria-hidden="true">
        <li class="flex items-center gap-2"><span class="h-0.5 w-4 rounded-full bg-btc"></span>{{ __('Mined') }}</li>
        @if ($forecast !== null)
            <li class="flex items-center gap-2"><span class="w-4 border-t-2 border-dashed border-btc"></span>{{ __('Forecast from the last 4 weeks') }}</li>
        @endif
        @if ($halvings->isNotEmpty())
            <li class="flex items-center gap-2"><span class="h-3 w-px bg-edge"></span>{{ __('Halving') }}</li>
        @endif
    </ul>

    <div
        class="relative h-[200px] outline-offset-4 lg:h-[260px]"
        role="img"
        aria-label="{{ $summary }}"
        tabindex="0"
        data-test="supply-plot"
        x-data="{ points: @js($readout), active: null,
            pick(event) { const box = this.$el.getBoundingClientRect(); const at = (event.clientX - box.left) / box.width * 100; let best = 0; this.points.forEach((p, i) => { if (Math.abs(p.x - at) < Math.abs(this.points[best].x - at)) best = i; }); this.active = best; },
            step(by) { this.active = Math.min(this.points.length - 1, Math.max(0, (this.active ?? this.points.length - 1) + by)); } }"
        x-on:pointermove="pick($event)"
        x-on:pointerleave="active = null"
        x-on:focus="active = points.findLastIndex((p) => ! p.forecast)"
        x-on:blur="active = null"
        x-on:keydown.left.prevent="step(-1)"
        x-on:keydown.right.prevent="step(1)"
    >
        {{-- Gridlines and their values: recessive, one step off the card. --}}
        @foreach ($ticks as $tick)
            <span class="absolute inset-x-0 border-t border-line" style="top: {{ $y($tick) }}%" aria-hidden="true"></span>
            <span class="absolute left-0 -translate-y-full pb-0.5 text-[11px] text-ink-3 [text-shadow:0_0_2px_var(--color-card),0_0_4px_var(--color-card)]" style="top: {{ $y($tick) }}%" aria-hidden="true">{{ $sats($tick) }}</span>
        @endforeach
        @if ($showSupply)
            <span class="absolute inset-x-0 top-0 border-t border-edge" aria-hidden="true"></span>
            <span class="absolute top-0 right-0 translate-y-1 text-[11px] text-ink-2" aria-hidden="true">{{ __('Supply :sats', ['sats' => $sats($curve['supply'])]) }}</span>
        @endif
        <span class="absolute inset-x-0 bottom-0 border-t border-edge" aria-hidden="true"></span>

        @foreach ($halvings as $halving)
            <span class="absolute inset-y-0 w-px bg-dash" style="left: {{ $halving['x'] }}%" aria-hidden="true"></span>
            {{-- Near the right edge the label sits left of its line, so it never leaves the plot. --}}
            <span @class(['absolute bottom-1 text-[11px] whitespace-nowrap text-ink-3 max-lg:hidden', 'ml-1.5' => $halving['x'] <= 90, '-ml-1.5 -translate-x-full' => $halving['x'] > 90]) style="left: {{ $halving['x'] }}%" aria-hidden="true">{{ __('Era :era', ['era' => $halving['era']]) }}</span>
        @endforeach

        <svg class="absolute inset-0 size-full overflow-visible" viewBox="0 0 1000 1000" preserveAspectRatio="none" aria-hidden="true">
            @if ($forecast !== null)
                <line x1="{{ $nowX * 10 }}" y1="{{ $y($mined) * 10 }}" x2="1000" y2="{{ $y($forecast) * 10 }}" class="stroke-btc" stroke-width="2" stroke-dasharray="6 5" vector-effect="non-scaling-stroke" />
            @endif
            <polyline points="{{ $minedLine }}" fill="none" class="stroke-btc" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" />
        </svg>

        {{-- The line's end: a dot with a ring of the card, and its value. --}}
        <span class="absolute size-2.5 -translate-x-1/2 -translate-y-1/2 rounded-full bg-btc ring-2 ring-card" style="left: {{ $nowX }}%; top: {{ $y($mined) }}%" aria-hidden="true"></span>

        {{-- Hover and focus: the crosshair finds the day, one readout. --}}
        <template x-if="active !== null">
            <div aria-hidden="true">
                <span class="pointer-events-none absolute inset-y-0 w-px bg-ink-3" :style="`left: ${points[active].x}%`"></span>
                <span class="pointer-events-none absolute size-2.5 -translate-x-1/2 -translate-y-1/2 rounded-full bg-btc ring-2 ring-card" :style="`left: ${points[active].x}%; top: ${points[active].y}%`"></span>
                <div class="pointer-events-none absolute top-2 z-10 flex w-44 flex-col gap-0.5 rounded-md bg-raised px-3 py-2 text-xs shadow-[0_0_0_1px_var(--color-line)]" :style="`left: clamp(0px, calc(${points[active].x}% - 88px), calc(100% - 176px))`" data-test="supply-tooltip">
                    <b class="font-display text-[15px] text-ink" x-text="points[active].value"></b>
                    <span class="text-ink-2" x-text="points[active].label"></span>
                    <span class="text-ink-3" x-show="points[active].detail !== ''" x-text="points[active].detail"></span>
                </div>
            </div>
        </template>
    </div>

    <div class="flex justify-between gap-4 text-[11px] text-ink-3" aria-hidden="true">
        <span>{{ __('Block 0, :day', ['day' => $day($from)]) }}</span>
        <span class="text-right">{{ __('Season end, :day', ['day' => $day($to)]) }}</span>
    </div>

    <details class="group" data-test="supply-table">
        <summary class="inline-flex h-11 cursor-pointer items-center gap-2 text-xs text-ink-2 hover:text-ink">
            <span class="transition-transform duration-150 group-open:rotate-90 motion-reduce:transition-none" aria-hidden="true">▸</span>{{ __('Show as a table') }}
        </summary>
        <div class="max-h-72 overflow-auto">
            <table class="w-full border-collapse text-left text-xs">
                <caption class="sr-only">{{ $summary }}</caption>
                <thead class="text-ink-2">
                    <tr class="border-b border-hairline">
                        <th scope="col" class="py-2 pr-3 font-normal">{{ __('Day') }}</th>
                        <th scope="col" class="py-2 pr-3 text-right font-normal">{{ __('Blocks') }}</th>
                        <th scope="col" class="py-2 pr-3 text-right font-normal">{{ __('Sats that day') }}</th>
                        <th scope="col" class="py-2 text-right font-normal">{{ __('Mined in total') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($curve['days'] as $row)
                        <tr class="border-b border-hairline last:border-0">
                            <td class="py-2 pr-3 whitespace-nowrap">{{ $day($row['at']) }}</td>
                            <td class="py-2 pr-3 text-right">{{ $row['blocks'] }}</td>
                            <td class="py-2 pr-3 text-right whitespace-nowrap">{{ $sats($row['sats']) }}</td>
                            <td class="py-2 text-right whitespace-nowrap">{{ $sats($row['total']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="py-3 text-ink-2">{{ $ended ? __('No block was mined this season.') : __('No block yet. The first fair rated win mines block 1.') }}</td></tr>
                    @endforelse
                    @if ($forecast !== null)
                        <tr class="text-ink-2">
                            <td class="py-2 pr-3 whitespace-nowrap">{{ __('Season end, :day', ['day' => $day($to)]) }}</td>
                            <td class="py-2 pr-3 text-right">–</td>
                            <td class="py-2 pr-3 text-right">–</td>
                            <td class="py-2 text-right whitespace-nowrap">~{{ $sats($forecast) }} <span class="text-ink-3">{{ __('forecast') }}</span></td>
                        </tr>
                    @endif
                </tbody>
            </table>
        </div>
    </details>
</div>
