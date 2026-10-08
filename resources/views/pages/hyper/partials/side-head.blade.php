{{--
    One side of a clan table or team match (HyperTeams::side()): the clan's logo (or its tag on a tile), the
    side's name (a clan linked to a meetup shows the meetup and its city), or the bots' side. `small` for the
    open tables' cards; `open` for a side no clan has taken yet.
--}}
@php
    $small ??= false;
    $open ??= false;
    $px = $small ? 28 : 44;
@endphp
<div @class(['flex min-w-0 items-center gap-2', 'flex-row-reverse text-right' => $small && $side['side'] === 1]) data-test="hyper-side-head" data-clan="{{ $side['clan_id'] }}" data-meetup="{{ $side['meetup'] ? '1' : '0' }}">
    @if ($side['logo'])
        <img src="{{ $side['logo'] }}" alt="" width="{{ $px }}" height="{{ $px }}" class="shrink-0 rounded-md bg-card object-cover shadow-ring" style="width: {{ $px }}px; height: {{ $px }}px" loading="lazy" decoding="async" data-clan-logo>
    @elseif ($side['tag'])
        <span class="grid shrink-0 place-items-center rounded-md bg-btc-tint font-bold text-btc shadow-ring" style="width: {{ $px }}px; height: {{ $px }}px; font-size: {{ $small ? 10 : 13 }}px">{{ $side['tag'] }}</span>
    @else
        <span class="grid shrink-0 place-items-center rounded-md border border-dashed border-line bg-ground text-ink-3" style="width: {{ $px }}px; height: {{ $px }}px" aria-hidden="true">{{ $open ? '?' : '🤖' }}</span>
    @endif
    <span class="flex min-w-0 flex-col">
        <b @class(['truncate', 'text-xs' => $small, 'font-display text-base' => ! $small])>{{ $side['clan_id'] === null && $open ? __('Open for a clan') : $side['name'] }}</b>
        @unless ($small)
            <span class="truncate text-[11px] text-ink-2">
                @if ($side['meetup'])
                    {{ $side['city'] ? __('Meetup · :city', ['city' => $side['city']]) : __('Meetup') }} · {{ $side['tag'] }}
                @elseif ($side['clan_id'] !== null)
                    {{ __('Clan') }} · {{ $side['tag'] }}
                @else
                    {{ $open ? __('The first player of another clan takes this side.') : __('Bots fill this side.') }}
                @endif
            </span>
        @endunless
    </span>
</div>
