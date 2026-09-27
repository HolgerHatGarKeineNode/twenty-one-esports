{{--
    How the season works, closed by default at the end of home: when a
    rated win mines a block, the Pre-Season pot and the genesis message
    when they are set, and the way to the chain.

    $laterSeason: a season after the Pre-Season is live; $liveSeason: the live season or null.
--}}
@php
    use App\Support\Badges\BadgeCopy;
    use App\Support\PreSeason;

    $pot = PreSeason::potSats();
    $genesis = PreSeason::genesisMessage();
    $heading = $laterSeason ? __('How :season works', ['season' => BadgeCopy::season($liveSeason->slug)]) : __('How the Pre-Season works');
@endphp

<details class="group mx-4 rounded-card bg-card lg:mx-12" data-test="home-rules">
    <summary class="flex min-h-14 cursor-pointer list-none items-center justify-between gap-3 px-4 py-3 lg:px-6 [&::-webkit-details-marker]:hidden">
        <h2 id="how-h" class="pl-h2" data-test="how-season">{{ $heading }}</h2>
        <x-icon name="chevron-down" :size="18" class="shrink-0 text-ink-2 transition-transform duration-200 group-open:rotate-180" />
    </summary>
    <div class="flex flex-col gap-3 border-t border-hairline px-4 pt-4 pb-5 lg:px-6">
        <p class="m-0 max-w-[75ch] text-[13px] leading-[1.6] text-ink-2">{{ __('From Block 0 on, every fair rated win mines a block. Mined blocks earn sats from the pot. Rewards are paid once, after the season review.') }}</p>
        @if ($pot && $liveSeason === null)
            <p class="m-0 max-w-[75ch] text-[13px] leading-[1.6] text-ink-2">{{ __('The pot: :sats sats, the whole Pre-Season supply. It is fixed at Block 0 and mined out win by win. Nothing in it is promised to a later season.', ['sats' => PreSeason::formatSats($pot)]) }}</p>
        @endif
        @if ($genesis && $liveSeason === null)
            <figure class="m-0 flex flex-col gap-1.5" data-test="genesis">
                <figcaption class="text-xs text-ink-3">{{ __('Written into Block 0') }}</figcaption>
                <p class="m-0 max-w-[72ch] text-sm leading-[1.6] break-words">“{{ $genesis }}”</p>
            </figure>
        @endif
        <h3 class="m-0 mt-2 text-[13px] font-bold">{{ __('A rated win mines a block when') }}</h3>
        <ul class="m-0 grid list-none grid-cols-1 gap-x-6 gap-y-2 p-0 md:grid-cols-2 xl:grid-cols-3">
            @foreach ([
                1 => __('Both players are Trusted and have added each other as opponents'),
                2 => __('It is a real game: 20 moves or more in chess, a fully played series in Rocket League'),
                3 => __('The two sides are not from the same clan'),
                4 => __('One block per pairing a day'),
                5 => __('At most 5 blocks per player a day, per game'),
                7 => __('The two players do not both get 90 % or more of their trust from the same person'),
                8 => __('At most 3 blocks per pairing until the season ends'),
                9 => __('The game has not used up its share of the era'),
            ] as $number => $rule)
                <li class="grid grid-cols-[16px_minmax(0,1fr)] gap-2 text-xs leading-[1.5] text-ink-2 lg:grid-cols-[20px_minmax(0,1fr)]"><span class="text-ink-3">{{ $number }}</span><span>{{ $rule }}</span></li>
            @endforeach
        </ul>
        <p class="pl-note">{{ $laterSeason ? __('Draws mine nothing. Admins can tune these limits during the season; a change only counts for blocks after it.') : __('Draws mine nothing. Admins can tune these limits during the Pre-Season; a change only counts for blocks after it.') }} <a href="{{ route('mining') }}" data-test="mining-link">{{ __('The chain on the mining page') }}</a></p>
        <p class="pl-note">{{ __('Casual games run before and after Block 0: casual Elo only, no rank, no reward.') }}</p>
    </div>
</details>
