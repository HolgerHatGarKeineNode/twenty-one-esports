{{--
    The top three of every game's first ladder (HomeHub::ladders()): the
    game's cover, the three faces with their Elo, and the way to the full
    ladder. The places are a real order, so they are numbered. A ladder
    nobody has a result in yet says so.

    $ladders: HomeHub::ladders().
--}}
@php
    $medal = ['1' => 'text-btc', '2' => 'text-ink', '3' => 'text-btc-hi'];
@endphp

@if ($ladders !== [])
    <section aria-labelledby="ladders-h" class="flex flex-col gap-4 px-4 lg:gap-5 lg:px-12" data-test="ladders">
        <h2 id="ladders-h" class="m-0 font-display text-xl font-bold lg:text-2xl">{{ __('Top of the ladders') }}</h2>
        <ul class="m-0 grid list-none grid-cols-1 gap-3 p-0 sm:grid-cols-2 lg:grid-cols-4 lg:gap-5">
            @foreach ($ladders as $ladder)
                <li class="flex min-w-0 flex-col gap-2 rounded-card bg-card p-3 lg:p-4" data-test="ladder-top" data-game="{{ $ladder['game'] }}">
                    <a href="{{ $ladder['href'] }}" class="grid grid-cols-[64px_minmax(0,1fr)] items-center gap-3 text-ink hover:text-ink">
                        <x-game-cover :game="$ladder['game']" size="thumb" class="w-16 rounded-tag" />
                        <span class="flex min-w-0 flex-col">
                            <b class="truncate text-[13px]">{{ $ladder['name'] }}</b>
                            <span class="text-xs text-ink-3">{{ $ladder['pool'] === \App\Models\Rating::RATED ? __('Rated') : __('Casual') }}</span>
                        </span>
                    </a>
                    @if ($ladder['rows'] === [])
                        <p class="m-0 border-t border-hairline pt-2 text-[13px] text-ink-2">{{ __('Nobody on it yet. One result puts you on top.') }}</p>
                    @else
                        <ol class="m-0 list-none p-0">
                            @foreach ($ladder['rows'] as $row)
                                <li class="grid min-h-11 grid-cols-[16px_28px_minmax(0,1fr)_auto] items-center gap-2 border-t border-hairline text-[13px]" data-test="ladder-row">
                                    <b class="font-display text-sm {{ $medal[$row['place']] ?? 'text-ink-2' }}">{{ $row['place'] }}</b>
                                    @if ($row['user'])
                                        <x-avatar :user="$row['user']" :size="28" class="rounded-tag" />
                                    @elseif ($row['clan'])
                                        <x-clan-tag :clan="$row['clan']" :tile="28" class="size-7 rounded-tag" />
                                    @else
                                        <span></span>
                                    @endif
                                    <span class="truncate">{{ $row['name'] }}</span>
                                    <span class="font-display text-sm font-bold tabular-nums">{{ $row['rating'] }}</span>
                                </li>
                            @endforeach
                        </ol>
                    @endif
                    <a href="{{ $ladder['href'] }}" class="mt-auto inline-flex min-h-11 items-center text-xs">{{ __('Full ladder') }}</a>
                </li>
            @endforeach
        </ul>
    </section>
@endif
