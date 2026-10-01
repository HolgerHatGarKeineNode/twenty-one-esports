{{--
    One player's replays on the replays page (StackerReplays::ofPlayer()), newest first: the time large, its ISO week,
    the place the run holds on that week's board (a medal for the first three; held runs say so), and the play square.
    The whole row is the link. $entries: list<array{run, week, place, href}>; $test: the rows' data-test.
--}}
@php
    $medal = [1 => 'bg-rank-gold', 2 => 'bg-rank-silver', 3 => 'bg-rank-bronze'];
    $metric = \App\Games\ScoreMetric::time();
@endphp
<ul class="m-0 flex list-none flex-col p-0">
    @foreach ($entries as $entry)
        @php
            $run = $entry['run'];
            // The run's own week (its Monday), so a run shows it before its week's board is opened too.
            $local = $run->week !== null ? \Carbon\CarbonImmutable::parse((string) $run->week) : null;
            $held = $run->status === \App\Enums\StackerRunStatus::Review;
        @endphp
        <li wire:key="run-{{ $run->id }}">
            <a href="{{ $entry['href'] }}" data-test="{{ $test }}" @if ($entry['place'] !== null) data-place="{{ $entry['place'] }}" @endif
               class="group grid min-h-14 grid-cols-[minmax(0,1fr)_auto_auto] items-center gap-x-3 rounded-md px-3 py-2 text-ink hover:bg-row-hover hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-btc">
                <span class="flex min-w-0 flex-col">
                    <b class="font-display text-[18px] leading-tight font-bold tabular-nums">{{ $metric->format(\App\Games\Blockfill::milliseconds((int) $run->ticks)) }}</b>
                    <span class="truncate text-xs text-ink-2">{{ $local ? __('Week :week, :year', ['week' => $local->isoWeek(), 'year' => $local->isoWeekYear()]) : '' }}</span>
                </span>
                @if ($held)
                    <span class="inline-flex h-7 items-center rounded-xs bg-btc-chip px-2 text-xs font-bold whitespace-nowrap text-btc-hi" data-test="replay-row-held">{{ __('Held') }}</span>
                @elseif (isset($medal[$entry['place']]))
                    <span class="flex size-7 items-center justify-center rounded-full font-display text-[13px] font-bold text-on-btc tabular-nums {{ $medal[$entry['place']] }}"><span class="sr-only">{{ __('Place') }} </span>{{ $entry['place'] }}</span>
                @elseif ($entry['place'] !== null)
                    <span class="font-display text-[13px] font-bold whitespace-nowrap text-ink-2 tabular-nums"><span class="sr-only">{{ __('Place') }} </span>#{{ $entry['place'] }}</span>
                @else
                    <span></span>
                @endif
                <span class="flex size-11 shrink-0 items-center justify-center rounded-md border border-line bg-well text-btc-hi group-hover:border-btc group-hover:bg-btc group-hover:text-on-btc">
                    <x-icon name="play" :size="18" /><span class="sr-only">{{ __('Watch replay') }}</span>
                </span>
            </a>
        </li>
    @endforeach
</ul>
