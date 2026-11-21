{{--
    How the season pays: nobody during the season, each player once after the review, the claim window. A card in the
    right column of /mining before Block 0 and between seasons, a part of the closed reference in a live season (plan
    "mining-page-clarity" P5). Expects $chain, $live, $settlement, $sats and $date of the page.
--}}
<section aria-labelledby="payouts-h" @class(['flex flex-col gap-2', 'rv-card p-4' => ! $live]) data-test="mining-payouts">
    <h2 id="payouts-h" @class(['rv-h2' => ! $live, 'm-0 text-[15px] font-bold' => $live])>{{ __('Payouts') }}</h2>
    <p class="m-0 text-xs leading-normal text-ink-2">{{ __('Nobody is paid during the season. After the season review each player is paid once, to their Lightning address.') }}</p>
    <dl class="m-0 flex flex-col text-[13px]">
        @foreach ([
            [__('Season end'), $date($chain['season']->ends_at)],
            ...match (true) {
                $settlement === null => [[__('Mined so far, paid after the review'), __(':sats sats, :players', ['sats' => $sats($chain['mined']), 'players' => trans_choice(':count player|:count players', $chain['miner_count'])]), 'mined']],
                ! $settlement['approved'] => [[__('Waiting for the review, corrections may still lower it'), __(':sats sats, :players', ['sats' => $sats($settlement['owed']), 'players' => trans_choice(':count player|:count players', $settlement['owed_players'])]), 'review']],
                default => [
                    [__('Approved to pay'), __(':sats sats, :players', ['sats' => $sats($settlement['owed']), 'players' => trans_choice(':count player|:count players', $settlement['owed_players'])]), 'approved'],
                    [__('Paid so far'), __(':sats sats, :players', ['sats' => $sats($settlement['paid_sats']), 'players' => trans_choice(':count player|:count players', $settlement['paid']->count())]), 'paid'],
                ],
            },
            [__('Without a valid address'), __('the sats wait :days days for a claim, then go to the reserve', ['days' => intdiv($chain['season']->claim_seconds, 86400)])],
        ] as $item)
            <div class="flex flex-col gap-0.5 border-b border-hairline py-2.5 last:border-0" @isset($item[2]) data-test="payouts-{{ $item[2] }}" @endisset><dt class="text-xs text-ink-2">{{ $item[0] }}</dt><dd class="m-0">{{ $item[1] }}</dd></div>
        @endforeach
    </dl>
</section>
