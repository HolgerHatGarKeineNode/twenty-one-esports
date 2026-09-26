{{--
    One tournament match as a box: both sides with their score, the result
    label, a link to the normal match, and the director marker.

    $box: TournamentView::box()
--}}
@php
    $done = $box['status'] === 'done';
    $skipped = $box['status'] === 'skipped';
@endphp
<div @class([
        'relative flex min-w-0 flex-col gap-1 rounded-md px-3 py-2.5 text-[13px]',
        'bg-raised' => ! $done,
        'bg-card shadow-[inset_0_0_0_1px_#F7931A]' => $done,
        'opacity-50' => $skipped,
    ]) data-test="match-box" data-status="{{ $box['status'] }}">
    <span class="flex items-center justify-between gap-2 text-[11px] text-ink-3">
        <span>
            @if ($box['number'])
                <a href="{{ $box['href'] }}" class="font-bold">#{{ $box['number'] }}</a>
            @else
                {{ strtoupper($box['key']) }}
            @endif
        </span>
        <span>
            @if ($skipped)
                {{ __('not needed') }}
            @elseif ($done)
                {{ __('done') }}
            @elseif ($box['status'] === 'ready')
                {{ __('ready') }}
            @else
                {{ __('waiting') }}
            @endif
        </span>
    </span>
    @foreach ($box['sides'] as $side)
        <span class="flex min-w-0 items-center gap-2">
            @if ($side['tag'])
                <x-clan-tag :clan="$side['clan']" :tag="$side['tag']" />
            @elseif ($side['mix'])
                <span class="inline-flex h-5 items-center rounded-xs bg-raised px-1.5 text-[10px] text-ink-2">{{ __('mix') }}</span>
            @endif
            <span @class(['min-w-0 grow truncate', 'font-bold text-ink' => $side['won'], 'text-ink-3' => ! $side['known']])>{{ $side['name'] }}</span>
            @if ($side['score'] !== null)
                <span @class(['tabular-nums', 'font-bold text-btc' => $side['won']])>{{ $side['score'] }}</span>
            @endif
        </span>
    @endforeach
    @if ($box['director'])
        @include('pages.tournaments.partials.marker', ['marker' => $box['director']])
    @endif
</div>
