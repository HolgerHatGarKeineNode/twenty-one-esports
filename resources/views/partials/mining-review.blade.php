{{--
    The season review: what is checked at the season end, the voided blocks once settled. A card of /mining before
    Block 0 and between seasons, a part of the closed reference in a live season (plan "mining-page-clarity" P5).
    Expects $chain, $live, $settlement and $date of the page.
--}}
<section aria-labelledby="review-h" @class(['flex flex-col gap-2', 'rv-card p-4 lg:p-6' => ! $live]) data-test="mining-review">
    <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
        <h2 id="review-h" @class(['rv-h2' => ! $live, 'm-0 text-[15px] font-bold' => $live])>{{ __('Season review') }}</h2>
        <span class="rv-m">{{ $live ? __('opens :when', ['when' => $date($chain['season']->ends_at)]) : __('mining stopped :when', ['when' => $date($chain['season']->ends_at)]) }}</span>
    </div>
    <p class="m-0 max-w-[68ch] text-[13px] leading-normal text-ink-2">{{ __('At the season end every game is checked for farming. Each correction is published with its block and reason; voided sats go to the reserve. Then each player is paid once.') }}</p>
    @if ($settlement !== null && $settlement['voids']->isNotEmpty())
        <ol class="m-0 flex list-none flex-col p-0" data-test="mining-voids">
            @foreach ($settlement['voids'] as $void)
                <li class="flex flex-col gap-0.5 border-b border-hairline py-2 text-[13px] last:border-0">
                    <b>{{ __('Block :height void', ['height' => $void->height]) }}</b>
                    <span class="text-ink-2 [overflow-wrap:anywhere]">{{ $void->reason }}</span>
                </li>
            @endforeach
        </ol>
    @endif
</section>
