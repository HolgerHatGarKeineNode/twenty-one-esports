{{--
    How the season works, closed by default at the end of the Season page (it stood at the end of home until the
    revamped start page, plan "Refactor und Design-Revamp": explanations go to the pages they explain): when a
    rated win mines a block, the Pre-Season supply and the genesis message
    when the board saved them (ChainDraft, P43), and the way to the chain.
    Every limit is the one in force: the live season's, else the draft's.
    The supply is the most that is paid out after the season, never money
    held now (user decision 2026-09-28).

    $laterSeason: a season after the Pre-Season is live; $liveSeason: the live season or null.
--}}
@php
    use App\Support\Badges\BadgeCopy;
    use App\Support\PreSeason;
    use App\Support\SeasonChain\ChainOverview;
    use App\Support\SeasonChain\ChainDraft;

    $pot = PreSeason::potSats();
    $genesis = PreSeason::genesisMessage();
    $rules = ChainDraft::inForce();
    $daily = array_unique(array_values($rules->daily));
    $heading = $laterSeason ? __('How :season works', ['season' => BadgeCopy::season($liveSeason->slug)]) : __('How the Pre-Season works');
@endphp

<details class="group rounded-lg bg-card" data-test="season-rules">
    <summary class="flex min-h-14 cursor-pointer list-none items-center justify-between gap-3 px-4 py-3 lg:px-6 [&::-webkit-details-marker]:hidden">
        <h2 id="how-h" class="pl-h2" data-test="how-season">{{ $heading }}</h2>
        <x-icon name="chevron-down" :size="18" class="shrink-0 text-ink-2 transition-transform duration-200 group-open:rotate-180" />
    </summary>
    <div class="flex flex-col gap-3 border-t border-hairline px-4 pt-4 pb-5 lg:px-6">
        <p class="m-0 max-w-[75ch] text-[13px] leading-[1.6] text-ink-2">{{ __('From Block 0 on, every fair rated win mines a block, and every block earns sats. Rewards are paid once, after the season review.') }}</p>
        @if ($pot && $liveSeason === null)
            <p class="m-0 max-w-[75ch] text-[13px] leading-[1.6] text-ink-2" data-test="home-supply">{{ __('Up to :sats sats can be mined in the Pre-Season. That is the most the league pays out, once, after the season review; nothing is set aside before. Nothing of it carries over to a later season.', ['sats' => PreSeason::formatSats($pot)]) }}</p>
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
                2 => __('It is a real game: :moves moves or more in chess, a fully played series in the other games', ['moves' => $rules->moves]),
                3 => __('The two sides are not from the same clan'),
                4 => trans_choice('One block per pairing a day|At most :count blocks per pairing a day', $rules->pairLimitPerDay),
                5 => count($daily) === 1
                    ? __('At most :count blocks per player a day, per game', ['count' => $daily[0]])
                    : __('At most this many blocks per player a day: :list', ['list' => collect($rules->daily)->map(fn (int $count, string $key): string => ChainOverview::gameLabel($key).' '.$count)->implode(', ')]),
                7 => $rules->subtree > 100
                    ? __('Rule 7, the same trust circle, is off')
                    : __('The two players do not both get :percent % or more of their trust from the same person', ['percent' => $rules->subtree]),
                8 => __('At most :count blocks per pairing until the season ends', ['count' => $rules->pairLimitPerSeason]),
                9 => __('The game has not used up its share of the era'),
            ] as $number => $rule)
                <li class="grid grid-cols-[16px_minmax(0,1fr)] gap-2 text-xs leading-[1.5] text-ink-2 lg:grid-cols-[20px_minmax(0,1fr)]"><span class="text-ink-3">{{ $number }}</span><span>{{ $rule }}</span></li>
            @endforeach
        </ul>
        <p class="pl-note">{{ $laterSeason ? __('Draws mine nothing. Admins can tune these limits during the season; a change only counts for blocks after it.') : __('Draws mine nothing. Admins can tune these limits during the Pre-Season; a change only counts for blocks after it.') }}</p>
        <p class="pl-note">{{ __('Casual games run before and after Block 0: casual Elo only, no rank, no reward.') }}</p>
    </div>
</details>
