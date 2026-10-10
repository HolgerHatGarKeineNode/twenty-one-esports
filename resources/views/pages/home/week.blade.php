{{--
    This week's tournaments, right column, desktop only (Main.dc.html "Läuft diese Woche", HomeGuest.dc.html
    "Nächste Turniere"; rule R9): the featured tournament first with its accent edge and pot, the running league
    weeks, then the casual cups open for sign-up, each with its cover, a data line and its seats or "Läuft".

    $rows: HomeBoard::week(); $user: the viewer or null.
--}}
<section aria-labelledby="wk-h" class="rv-card hidden lg:block" data-test="home-week">
    <div class="flex items-center p-4">
        <h2 id="wk-h" class="rv-h3">{{ $user ? __('Running this week') : __('Next tournaments') }}</h2>
        <span class="grow"></span>
        <a href="{{ route('tournaments.index') }}" @navigate(route('tournaments.index')) class="rv-lk text-[13px]">{{ __('All tournaments') }}</a>
    </div>
    <table class="rv-tb">
        <tbody>
            @foreach ($rows as $row)
                <tr @class(['is-featured' => $row['featured']]) data-test="week-row" @if ($row['featured']) data-featured @endif>
                    <td class="w-[60px]">
                        <x-game-cover :game="$row['tournament']->game" size="thumb" class="h-[27px] w-12 rounded-[2px] [&_img]:object-cover" />
                    </td>
                    <td class="[overflow-wrap:anywhere]">
                        <a href="{{ $row['href'] }}" @navigate($row['href']) class="font-bold text-ink hover:text-ink">{{ $row['name'] }}</a>
                        <div class="rv-m">{{ $row['meta'] }}</div>
                    </td>
                    <td class="is-r">
                        @if ($row['running'])
                            <span class="rv-tag is-ok"><span class="rv-live is-ok" aria-hidden="true"><i></i></span>{{ __('Running') }}</span>
                        @else
                            <x-ui.seats :taken="$row['taken']" :places="$row['places']" class="flex-wrap justify-end max-xl:max-w-[94px]" />
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</section>
