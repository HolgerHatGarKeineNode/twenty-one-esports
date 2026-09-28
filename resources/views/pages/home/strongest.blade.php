{{--
    The five strongest players across every game (P40, StrongestList): place,
    face, name and Global Rating, and the way to the full list. The places are
    a real order, so they are numbered, in the medal colours of the ladder
    tiles above. Before Block 0, between seasons or before anyone has enough
    rated results, one honest line says why the list is empty.

    $strongest: StrongestList::top(StrongestList::HOME)
    $live:      whether a season is live
--}}
@php
    $medal = [1 => 'text-btc', 2 => 'text-ink', 3 => 'text-btc-hi'];
    $minimum = (int) config('season.global_rating_min_weight');
@endphp

<section aria-labelledby="strongest-h" class="flex flex-col gap-4 px-4 lg:gap-5 lg:px-12" data-test="home-strongest">
    <div class="flex flex-wrap items-baseline justify-between gap-x-6 gap-y-1">
        <h2 id="strongest-h" class="m-0 font-display text-xl font-bold lg:text-2xl">{{ __('Strongest across all games') }}</h2>
        <a href="{{ route('ladder.strongest') }}" class="inline-flex min-h-11 items-center text-xs" data-test="home-strongest-link">{{ __('Full list') }}</a>
    </div>
    <div class="rounded-card bg-card p-3 lg:p-4">
        @if ($strongest['rows'] === [])
            <p class="m-0 text-[13px] leading-normal text-ink-2" data-test="home-strongest-empty">
                {{ $live
                    ? __('Nobody is ranked yet: :n rated results in any game of the season put a player here.', ['n' => $minimum])
                    : __('The list opens with a live season: from Block 0, :n rated results in any game put a player here.', ['n' => $minimum]) }}
            </p>
        @else
            {{-- One row per player below lg. From lg the five stand side by side, each its own column (a hairline apart): face and place, the name over the rating, so a rating never reads as the next player's. --}}
            <ol class="m-0 grid list-none grid-cols-1 p-0 lg:grid-cols-5">
                @foreach ($strongest['rows'] as $row)
                    <li class="min-w-0 border-t border-hairline first:border-t-0 lg:border-t-0 lg:border-l lg:px-4 lg:first:border-l-0 lg:first:pl-0 lg:last:pr-0" data-test="home-strongest-row">
                        <a href="{{ route('players.show', $row['user']->npub) }}" class="grid min-h-12 grid-cols-[16px_40px_minmax(0,1fr)_auto] items-center gap-x-2 text-[13px] text-ink hover:text-ink lg:grid-cols-[16px_40px_minmax(0,1fr)] lg:grid-rows-2 lg:gap-y-0.5 lg:py-1">
                            <b class="font-display text-sm lg:row-span-2 {{ $medal[$row['place']] ?? 'text-ink-2' }}">{{ $row['place'] }}</b>
                            <x-avatar :user="$row['user']" :size="40" class="rounded-tag lg:row-span-2" />
                            <span class="truncate lg:self-end">{{ $row['user']->displayName() }}</span>
                            <span class="font-display text-sm font-bold tabular-nums lg:col-start-3 lg:self-start lg:text-base" data-test="home-strongest-rating">{{ $row['rating'] }}</span>
                        </a>
                    </li>
                @endforeach
            </ol>
        @endif
    </div>
</section>
