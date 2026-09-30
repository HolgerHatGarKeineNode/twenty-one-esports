<?php

use App\Models\Season;
use App\Models\SeasonBlockVoid;
use App\Models\SeasonPayout;
use App\Models\User;
use App\Support\Badges\BadgeCopy;
use App\Support\Cards\ShareCard;
use App\Support\Cards\ShareMoments;
use App\Support\Nostr\NostrKeys;
use App\Support\PageMeta;
use App\Support\PreSeason;
use App\Support\Prizes\PoolInvoices;
use App\Support\QrCode;
use App\Support\Rating\LadderBoard;
use App\Support\SeasonChain\ChainOverview;
use App\Support\SeasonChain\SeasonRelease;
use App\Support\SeasonChain\SeasonSettlement;
use App\Support\SeasonChain\Seasons;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/*
 * /mining, the Season page (Mining.dc.html, MobileMining.dc.html), from the
 * real chain (P7c, P33): the supply mined over time with the forecast, the
 * era schedule with what a win pays, the latest blocks, the top miners, why
 * wins did not mine, the league reserve with its zaps, the payout rules, the
 * season review and the rules in force with their change log. Between
 * seasons: the ended season as it closed. Before Block 0: the rest state
 * with the countdown and the chain draft of the board (ChainDraft), which it
 * releases.
 *
 * Amounts are configured, mined, or received per zap; no pot ever shows a
 * wallet balance. The reserve is zapped through a QR code of the league's
 * LNURL, never its Lightning address as text (P39).
 *
 * P37: between seasons the review's corrections (block and public reason)
 * and the season payouts that were paid, each with its Payout (2157); paid
 * amounts only, never what is still owed or what a wallet holds.
 */
new #[Layout('layouts::app', ['section' => 'mining'])] class extends Component
{
    public function rendering(\Illuminate\View\View $view): void
    {
        $view->title(__('Season'));
        app(PageMeta::class)->describe(__('Season'), __('Every fair rated win is a block, counted in the order the league saves results. Rewards halve every era and are paid once, after the season review.'));
        app(\App\Support\PageMeta::class)->card(fn () => \App\Support\Cards\PageCard::page('mining'));
    }

    /**
     * The live season, else the latest ended one as it closed, else the draft.
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function chain(): array
    {
        $season = Seasons::live();

        if ($season !== null) {
            return app(ChainOverview::class)->live($season);
        }

        $ended = Seasons::latest();

        return $ended === null ? app(ChainOverview::class)->draft() : app(ChainOverview::class)->ended($ended);
    }

    /**
     * The ended season's corrections, paid payouts and what is owed (P37),
     * null while a season is live or before Block 0. Before the approval
     * `owed` is the review's replay with the voids so far (it may still
     * change); after it, the approved payouts. Amounts owed or paid only,
     * never a wallet balance.
     *
     * @return array{paid: Collection<int, SeasonPayout>, voids: Collection<int, SeasonBlockVoid>, approved: bool, owed: int, owed_players: int, paid_sats: int}|null
     */
    #[Computed]
    public function settlement(): ?array
    {
        $season = $this->chain['season'];

        if (! $season instanceof Season || Seasons::state() !== 'between') {
            return null;
        }

        $paid = SeasonSettlement::paid($season);
        $approved = $season->settlement_approved_at !== null;

        if ($approved) {
            $owed = (int) $season->payouts()->sum('amount_sats');
            $players = $season->payouts()->count();
        } else {
            $rows = array_filter(app(SeasonSettlement::class)->review($season)['rows'], fn (array $row): bool => $row['payout'] > 0);
            $owed = array_sum(array_column($rows, 'payout'));
            $players = count($rows);
        }

        return ['paid' => $paid, 'voids' => $season->blockVoids()->orderBy('height')->get(), 'approved' => $approved,
            'owed' => $owed, 'owed_players' => $players, 'paid_sats' => (int) $paid->sum('amount_sats')];
    }

    /**
     * @return array{count: int, latest: list<array{name: string, sats: int, at: \Carbon\CarbonImmutable}>}
     */
    #[Computed]
    public function zaps(): array
    {
        return app(ChainOverview::class)->reserveZaps();
    }

    /**
     * The QR code (SVG) of the league's LNURL, the pool key's Lightning
     * address `pool@<host>` that takes zaps into the reserve; null while
     * that endpoint takes no payments.
     */
    #[Computed]
    public function reserveZapQr(): ?string
    {
        return PoolInvoices::receives() ? QrCode::svg('lightning:'.PoolInvoices::lnurl(), label: __('QR code to zap the league reserve')) : null;
    }
}; ?>

@php
    $viewer = auth()->user();
    $zone = PreSeason::timezoneFor($viewer instanceof User ? $viewer : null);
    $chain = $this->chain;
    $state = Seasons::state();
    $live = $state === 'live';
    $ended = $state === 'between';
    $hasChain = $chain['season'] !== null;
    $sats = fn (int $value): string => PreSeason::formatSats($value);
    $date = fn ($at, string $format = 'D j M, H:i'): string => $at->copy()->setTimezone($zone)->locale(app()->getLocale())->translatedFormat($format);
    $inForce = $chain['in_force'];
    $games = array_keys($inForce->shares + $inForce->daily);
    $zaps = $this->zaps;
    $settlement = $this->settlement;
    $unmined = $hasChain ? max(0, $chain['supply'] - ($live ? $chain['estimate']['end_mined'] : $chain['mined'])) : null;
@endphp

<div class="flex grow flex-col gap-4 px-4 pb-10 lg:gap-6 lg:px-12" data-test="mining" data-state="{{ $state }}">
    <div class="flex flex-col gap-2">
        <h1 class="m-0 font-display text-[26px] font-bold lg:text-[34px]">{{ __('Season') }}</h1>
        <p class="m-0 max-w-[80ch] text-[13px] leading-normal text-ink-2">{{ __('Every fair rated win is a block, counted in the order the league saves results. Rewards halve every era and are paid once, after the season review.') }}</p>
    </div>

    {{-- P45: the season chain on Nostr (its genesis), the reserve's zap QR, a follow of the league key --}}
    <x-nostr-bar :bar="\App\Support\Nostr\NostrBar::season($chain['season'], $this->reserveZapQr)" />

    @unless ($live)
        <section class="flex flex-col gap-3 rounded-lg bg-card px-4 py-5 shadow-[inset_0_0_0_1px_#3A2A12] lg:px-8 lg:py-7" data-test="mining-rest">
            <x-empty-state :heading="$ended ? __('The chain rests between seasons') : __('The chain starts at Block 0')" :text="Seasons::restMessage($viewer instanceof User ? $viewer : null)">
                <a href="{{ route('chess.lobby') }}" class="btn-p inline-flex h-11 items-center rounded-md bg-btc px-5 text-sm font-bold text-on-btc hover:text-on-btc">{{ __('Play a casual game') }}</a>
                @unless ($ended)
                    <a href="{{ route('home') }}#block0" class="inline-flex h-11 items-center rounded-md border border-edge bg-ground px-5 text-sm text-ink hover:text-ink">{{ __('Block 0 countdown') }}</a>
                @endunless
            </x-empty-state>
            @if ($ended)
                <p class="m-0 text-xs text-ink-3">{{ __('The numbers below are the season that ended :when, as it closed.', ['when' => $date($chain['season']->ends_at, 'D j M')]) }}</p>
            @else
                <p class="m-0 text-xs text-ink-3">{{ __('The numbers below are the Pre-Season draft. The board can still change them before it releases Block 0.') }}</p>
            @endif
        </section>
    @endunless

    {{-- P46: at season end, the player's own Season Wrapped card with its post (preview first, then the signer) --}}
    @if ($ended && $hasChain && $viewer instanceof User && ShareMoments::hasWrapped($chain['season'], $viewer))
        @php
            $wrapped = ShareCard::wrapped($chain['season'], $viewer);
        @endphp
        <section aria-labelledby="wrapped-h" class="flex flex-col gap-4 rounded-lg bg-card px-4 py-5 shadow-[inset_0_0_0_1px_#F7931A] sm:flex-row sm:items-start lg:px-6" data-test="season-wrapped">
            <img src="{{ $wrapped->path('wide') }}" alt="{{ __('Your :season on one card', ['season' => BadgeCopy::season($chain['season']->slug)]) }}" width="1200" height="630" loading="lazy"
                 class="aspect-[1200/630] h-auto w-full shrink-0 rounded-md shadow-ring sm:w-[280px]">
            <div class="flex min-w-0 flex-col gap-2">
                <h2 id="wrapped-h" class="m-0 font-display text-lg font-bold lg:text-[22px]">{{ __('Your :season, wrapped', ['season' => BadgeCopy::season($chain['season']->slug)]) }}</h2>
                <p class="m-0 max-w-[60ch] text-[13px] leading-normal text-ink-2">{{ __('Your blocks, sats, wins and best rank of the season on one card. Post it, or download it for your chats.') }}</p>
                <livewire:share-button type="wrapped" :moment="$chain['season']->slug" :label="__('Post my season')" :wire:key="'wrapped-share-'.$chain['season']->slug" />
            </div>
        </section>
    @endif

    <section aria-label="{{ __('Chain stats') }}" class="grid grid-cols-2 gap-3 lg:grid-cols-5 lg:gap-4" data-test="mining-stats">
        @php
            // Only a game whose wins can mine now headlines what a win pays (rated chess may be off).
            $firstKey = collect(array_keys($chain['rewards_now']))->first(fn (string $key): bool => ChainOverview::mines($key));
            $minedOf = $hasChain ? __(':percent % of :supply', ['percent' => number_format($chain['mined'] / max(1, $chain['supply']) * 100, 1), 'supply' => $sats($chain['supply'])]) : '';
            $stats = match (true) {
                $live => [
                    [__('Era'), (string) $chain['era'], $chain['next_halving'] ? __('next halving :when', ['when' => $date($chain['next_halving'])]) : __('last era')],
                    [__('Mined'), $sats($chain['mined']), $minedOf],
                    [__('Left to mine'), $sats($chain['remaining']), __('mining stops at 0 or at the season end')],
                    [__('Blocks'), (string) $chain['blocks'], trans_choice(':count today|:count today', $chain['blocks_today'])],
                    [$firstKey ? __(':game win pays', ['game' => ChainOverview::keyLabel($firstKey)]) : __('A win pays'), $firstKey ? $sats($chain['rewards_now'][$firstKey]) : '0', __('sats per winning player, era :era', ['era' => $chain['era'] ?? 1])],
                ],
                $ended => [
                    [__('Blocks'), (string) $chain['blocks'], __('at the season end')],
                    [__('Mined'), $sats($chain['mined']), $minedOf],
                    [__('Not mined'), $sats((int) $unmined), __('goes to the league reserve')],
                    [__('Players who mined'), (string) $chain['miner_count'], __('each paid once, after the review')],
                    [__('Season'), trans_choice(':count week|:count weeks', (int) round($chain['season']->genesis_at->diffInDays($chain['season']->ends_at) / 7)), __(':from to :to', ['from' => $date($chain['season']->genesis_at, 'j M'), 'to' => $date($chain['season']->ends_at, 'j M Y')])],
                ],
                default => [
                    [__('Era'), '0', __('eras of :days days', ['days' => intdiv($chain['parameters']->halvingSeconds, 86400)])],
                    // The board's saved chain draft only, as home and /rules: no server default is announced.
                    PreSeason::potSats() === null
                        ? [__('Supply'), '–', __('not announced yet')]
                        : [__('Supply'), $sats(PreSeason::potSats()), __('the most it pays out, after the season')],
                    [__('Mined'), '0', __('nothing before Block 0')],
                    [__('Blocks'), '0', __('Block 1 follows Block 0')],
                    [$firstKey ? __(':game win pays', ['game' => ChainOverview::keyLabel($firstKey)]) : __('A win pays'), $firstKey ? $sats($chain['rewards_now'][$firstKey]) : '0', __('sats per winning player, era :era', ['era' => 1])],
                ],
            };
        @endphp
        @foreach ($stats as [$label, $value, $sub])
            <div @class(['flex min-w-0 flex-col gap-1 rounded-lg bg-card px-4 py-4', 'col-span-2 lg:col-span-1' => $loop->last])>
                <span class="text-xs text-ink-2">{{ $label }}</span>
                <b class="font-display text-[22px] leading-tight lg:text-[26px]">{{ $value }}</b>
                <span class="text-xs text-ink-3">{{ $sub }}</span>
            </div>
        @endforeach
    </section>

    @if ($hasChain)
        <section aria-labelledby="supply-h" class="flex flex-col gap-3 rounded-lg bg-card px-4 py-5 lg:px-6" data-test="mining-supply">
            <h2 id="supply-h" class="m-0 text-[15px] font-bold">{{ __('Supply: mined and left') }}</h2>
            <x-supply-chart :curve="$chain['curve']" :zone="$zone" :ended="$ended" />
            @if ($live)
                <p class="m-0 text-xs text-ink-2">{{ __('At the rate of the last 4 weeks about :sats sats get mined by the season end (:percent %).', ['sats' => $sats($chain['estimate']['end_mined']), 'percent' => $chain['estimate']['end_mined_percent']]) }}</p>
            @endif
        </section>
    @endif

    <section aria-labelledby="eras-h" class="flex flex-col gap-3 rounded-lg bg-card px-4 py-5 lg:px-6" data-test="mining-eras">
        <span class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
            <h2 id="eras-h" class="m-0 text-[15px] font-bold">{{ __('Halvings and what a win pays') }}</h2>
            <span class="text-xs text-ink-3">{{ __('sats per winning player; a Rocket League block pays every winning player') }}</span>
        </span>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[560px] border-collapse text-left text-xs">
                <thead class="text-ink-2">
                    <tr class="border-b border-hairline">
                        <th scope="col" class="py-2 pr-3 font-normal">{{ __('Era') }}</th>
                        <th scope="col" class="py-2 pr-3 font-normal">{{ __('From') }}</th>
                        @foreach (array_keys($chain['rewards_now']) as $key)
                            <th scope="col" class="py-2 pr-3 text-right font-normal" @unless (ChainOverview::mines($key)) data-test="reward-not-open" @endunless>{{ ChainOverview::keyLabel($key) }}@unless (ChainOverview::mines($key)) <span class="text-ink-3">· {{ __('not open') }}</span>@endunless</th>
                        @endforeach
                        @foreach ($games as $game)
                            <th scope="col" class="py-2 pr-3 text-right font-normal">{{ __(':game mined', ['game' => ChainOverview::gameLabel($game)]) }}</th>
                        @endforeach
                    </tr>
                </thead>
                @php
                    // The halving as a bar beside each era (P53: the table read as text only): what a win pays
                    // relative to era 1, read from the first reward that mines. Supplementary; the sats stay in the cells.
                    $payKey = collect(array_keys($chain['rewards_now']))->first(fn (string $key): bool => ChainOverview::mines($key));
                    $firstPay = $payKey === null ? 0 : (int) ($chain['schedule'][0]['rewards'][$payKey] ?? 0);
                @endphp
                <tbody>
                    @foreach ($chain['schedule'] as $row)
                        <tr @class(['border-b border-hairline last:border-0', 'text-btc-hi' => $row['current']])>
                            <td class="py-2 pr-3 font-bold">
                                <span class="flex items-center gap-2">
                                    <span class="w-3">{{ $row['era'] }}</span>
                                    @if ($firstPay > 0)
                                        <span class="block h-1 w-14 overflow-hidden rounded-[2px] bg-raised" aria-hidden="true" data-test="era-pay-bar">
                                            <span class="block h-full rounded-[2px] bg-btc" style="width: {{ round(100 * (int) ($row['rewards'][$payKey] ?? 0) / $firstPay, 2) }}%"></span>
                                        </span>
                                    @endif
                                </span>
                            </td>
                            <td class="py-2 pr-3 whitespace-nowrap">{{ $date($row['from'], 'D j M') }}</td>
                            @foreach ($row['rewards'] as $key => $reward)
                                <td class="py-2 pr-3 text-right">{{ ChainOverview::mines((string) $key) ? $sats($reward) : '–' }}</td>
                            @endforeach
                            @foreach ($games as $game)
                                @php($minedHere = (int) ($hasChain ? ($chain['mined_by_game_and_era'][$game][$row['era']] ?? 0) : 0))
                                @php($capHere = (int) ($row['caps'][$game] ?? 0))
                                <td class="py-2 pr-3 text-right whitespace-nowrap">
                                    {{ $sats($minedHere) }} <span class="text-ink-3">/ {{ $sats($capHere) }}</span>
                                    {{-- How much of the game's era cap is mined, as a bar under the numbers (P53). --}}
                                    <span class="mt-1 ml-auto block h-1 w-full max-w-24 overflow-hidden rounded-[2px] bg-raised" aria-hidden="true" data-test="era-cap-bar">
                                        <span class="block h-full rounded-[2px] bg-btc" style="width: {{ $capHere > 0 ? min(100, round(100 * $minedHere / $capHere, 2)) : 0 }}%"></span>
                                    </span>
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="m-0 text-xs text-ink-3">{{ __('Inside an era every valid win of a game pays the same, fixed when its block is saved; halvings never reverse. Mined / cap: no game can take more than its share of an era.') }}</p>
        @unless (collect(array_keys($chain['rewards_now']))->filter(fn (string $key): bool => str_starts_with($key, 'chess/'))->every(fn (string $key): bool => ChainOverview::mines($key)))
            <p class="m-0 text-xs text-ink-2" data-test="rated-chess-not-open">{{ __('Rated chess is not open yet, so chess wins do not mine. Its rewards apply from the day rated blitz opens.') }}</p>
        @endunless
        @unless (collect(array_keys($chain['rewards_now']))->reject(fn (string $key): bool => str_starts_with($key, 'chess/'))->every(fn (string $key): bool => ChainOverview::mines($key)))
            <p class="m-0 text-xs text-ink-2" data-test="rated-board-not-open">{{ __('Rated board games are not open yet, so their wins do not mine. Their rewards apply from the day their rated games open.') }}</p>
        @endunless
    </section>

    @if ($hasChain)
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-12 lg:gap-6">
            <section aria-labelledby="latest-h" class="flex flex-col gap-3 rounded-lg bg-card px-4 py-5 lg:col-span-8 lg:px-6" data-test="mining-latest">
                <h2 id="latest-h" class="m-0 text-[15px] font-bold">{{ __('Latest blocks') }} <span class="font-normal text-ink-3">{{ __('mined, pending the season review') }}</span></h2>
                @forelse ($chain['latest'] as $block)
                    {{-- Anchored by height: the mempool strip on /matches links a mined match here. --}}
                    <div id="block-{{ $block['height'] }}" class="grid scroll-mt-24 grid-cols-[56px_minmax(0,1fr)_auto] items-center gap-3 border-b border-hairline py-2.5 text-[13px] last:border-0 target:bg-btc-chip">
                        <b class="font-display text-base">{{ $block['height'] }}</b>
                        <span class="flex min-w-0 flex-col gap-0.5">
                            <span class="truncate">{{ $block['label'] }} · {{ ChainOverview::keyLabel($block['key']) }}</span>
                            <span class="truncate text-xs text-ink-2">{{ implode(', ', $block['winners']) }}</span>
                        </span>
                        <span class="flex flex-col items-end gap-0.5"><b>{{ $sats($block['reward']) }}</b><span class="text-xs whitespace-nowrap text-ink-3">{{ $date($block['at'], 'D H:i') }}</span></span>
                    </div>
                @empty
                    <p class="m-0 py-4 text-[13px] text-ink-2">{{ $ended ? __('No block was mined this season.') : __('No block yet. The first fair rated win mines block 1.') }}</p>
                @endforelse
            </section>

            <section aria-labelledby="miners-h" class="flex flex-col gap-3 rounded-lg bg-card px-4 py-5 lg:col-span-4 lg:px-6">
                <h2 id="miners-h" class="m-0 text-[15px] font-bold">{{ __('Top miners') }}</h2>
                @forelse ($chain['miners'] as $index => $miner)
                    <div class="grid grid-cols-[24px_minmax(0,1fr)_auto] items-center gap-3 border-b border-hairline py-2 text-[13px] last:border-0">
                        <span class="text-ink-3">{{ $index + 1 }}</span><span class="truncate">{{ $miner['name'] }}</span>
                        <span class="text-right"><b>{{ $sats($miner['sats']) }}</b> <span class="text-xs text-ink-3">{{ trans_choice(':count block|:count blocks', $miner['blocks']) }}</span></span>
                    </div>
                @empty
                    <p class="m-0 py-4 text-[13px] text-ink-2">{{ __('Nobody has mined yet.') }}</p>
                @endforelse
                <p class="m-0 text-xs text-ink-3">{{ __('Sats mined wait for the season review; nobody is paid during the season.') }}</p>
            </section>
        </div>

        <section aria-labelledby="nomine-h" class="flex flex-col gap-3 rounded-lg bg-card px-4 py-5 lg:px-6" data-test="mining-rejected">
            <h2 id="nomine-h" class="m-0 text-[15px] font-bold">{{ __('Wins that did not mine') }}</h2>
            @forelse ($chain['rejected'] as $reason => $count)
                <div class="flex items-center justify-between gap-3 border-b border-hairline py-2 text-[13px] last:border-0"><span>{{ ChainOverview::reasonLabel((string) $reason) }}</span><b>{{ $count }}</b></div>
            @empty
                <p class="m-0 text-[13px] text-ink-2">{{ __('Every rated win so far mined a block.') }}</p>
            @endforelse
        </section>
    @endif

    <div @class(['grid grid-cols-1 gap-4 lg:gap-6', 'lg:grid-cols-2' => $hasChain])>
        <section aria-labelledby="reserve-h" class="flex flex-col gap-3 rounded-lg bg-card px-4 py-5 lg:px-6" data-test="mining-reserve">
            <h2 id="reserve-h" class="m-0 text-[15px] font-bold">{{ __('League reserve') }}</h2>
            <p class="m-0 text-xs leading-normal text-ink-2">{{ __('Zaps to the league, the unmined rest of a season and voided blocks. Nothing here is promised to a later season.') }}</p>
            <dl class="m-0 flex flex-col text-[13px]">
                @if ($unmined !== null)
                    <div class="flex items-baseline justify-between gap-3 border-b border-hairline py-2.5" data-test="reserve-unmined">
                        <dt class="text-ink-2">{{ $live ? __('Unmined rest at the season end, at the current rate') : __('Unmined rest of the season') }}</dt>
                        <dd class="m-0 font-bold whitespace-nowrap">{{ $live ? '~' : '' }}{{ $sats($unmined) }}</dd>
                    </div>
                @endif
                <div class="flex items-baseline justify-between gap-3 py-2.5">
                    <dt class="text-ink-2">{{ __('Zaps') }}</dt>
                    <dd class="m-0 text-ink-3" data-test="reserve-zap-count">{{ trans_choice(':count zap|:count zaps', $zaps['count']) }}</dd>
                </div>
            </dl>
            @if ($zaps['latest'] !== [])
                <ul class="m-0 -mt-2 flex list-none flex-col p-0 text-[13px]" data-test="reserve-zaps">
                    @foreach ($zaps['latest'] as $zap)
                        <li class="grid grid-cols-[minmax(0,1fr)_auto_auto] items-baseline gap-3 border-b border-hairline py-2 last:border-0">
                            <span class="truncate">{{ $zap['name'] }}</span>
                            <b class="whitespace-nowrap">{{ $sats($zap['sats']) }}</b>
                            <span class="text-xs whitespace-nowrap text-ink-3">{{ $date($zap['at'], 'j M') }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
            @if ($this->reserveZapQr !== null)
                <div class="flex items-center gap-4 border-t border-hairline pt-4" data-test="reserve-zap">
                    <div class="size-28 shrink-0 rounded-sm bg-white p-2" data-test="reserve-zap-qr">{!! $this->reserveZapQr !!}</div>
                    <p class="m-0 text-xs leading-normal text-ink-2">{{ __('Zap the league reserve: scan the code with a Lightning wallet or a Nostr client.') }}</p>
                </div>
            @endif
        </section>

        @if ($hasChain)
            <section aria-labelledby="payouts-h" class="flex flex-col gap-3 rounded-lg bg-card px-4 py-5 lg:px-6" data-test="mining-payouts">
                <h2 id="payouts-h" class="m-0 text-[15px] font-bold">{{ __('Payouts') }}</h2>
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
        @endif
    </div>

    @if ($hasChain)
        <section aria-labelledby="review-h" class="flex flex-col gap-2 rounded-lg bg-card px-4 py-5 lg:px-6" data-test="mining-review">
            <span class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                <h2 id="review-h" class="m-0 text-[15px] font-bold">{{ __('Season review') }}</h2>
                <span class="text-xs text-ink-3">{{ $live ? __('opens :when', ['when' => $date($chain['season']->ends_at)]) : __('mining stopped :when', ['when' => $date($chain['season']->ends_at)]) }}</span>
            </span>
            <p class="m-0 max-w-[80ch] text-[13px] leading-normal text-ink-2">{{ __('At the season end every game is checked for farming. Each correction is published with its block and reason; voided sats go to the reserve. Then each player is paid once.') }}</p>
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
    @endif

    @if ($settlement !== null)
        <section id="season-payouts" aria-labelledby="season-payouts-h" class="flex flex-col gap-3 rounded-lg bg-card px-4 py-5 lg:px-6" data-test="mining-season-payouts">
            <h2 id="season-payouts-h" class="m-0 text-[15px] font-bold">{{ __('Season payouts') }}</h2>
            <p class="m-0 max-w-[80ch] text-xs leading-normal text-ink-2">{{ __('What each player was paid for their blocks, once, to the Lightning address in their Nostr profile. Each payment is a signed Payout event with the invoice and its preimage. No Lightning address in your profile? Add one so your sats can be paid.') }}</p>
            @forelse ($settlement['paid'] as $payout)
                <div class="grid grid-cols-[minmax(0,1fr)_auto] items-baseline gap-x-3 gap-y-0.5 border-b border-hairline py-2 text-[13px] last:border-0" data-test="mining-season-payout">
                    <span class="truncate">@if ($payout->user)<x-player-link :user="$payout->user" />@else{{ $payout->name }}@endif</span>
                    <b class="whitespace-nowrap">{{ $sats($payout->amount_sats) }} {{ __('sats') }}</b>
                    <span class="text-xs text-ink-3">{{ trans_choice(':count block|:count blocks', $payout->blocks) }} · {{ $payout->paid_at ? $date($payout->paid_at, 'j M Y') : '' }}</span>
                    @if ($payout->event)
                        <a href="{{ LadderBoard::NJUMP.NostrKeys::nevent($payout->event->event_id, $payout->event->pubkey, 2157) }}" rel="noopener noreferrer" target="_blank" class="text-xs whitespace-nowrap text-proof underline decoration-proof/50 underline-offset-2" data-test="mining-season-payout-proof">{{ __('Payout event') }}<span class="sr-only"> {{ __('(opens njump.me in a new tab)') }}</span></a>
                    @endif
                </div>
            @empty
                <p class="m-0 text-[13px] text-ink-2">{{ __('No season payout has been paid yet.') }}</p>
            @endforelse
        </section>
    @endif

    <section aria-labelledby="rules-h" class="flex flex-col gap-3 rounded-lg bg-card px-4 py-5 lg:px-6" data-test="mining-rules">
        <h2 id="rules-h" class="m-0 text-[15px] font-bold">{{ match (true) { $live => __('Rules in force now'), $ended => __('Rules at the season end'), default => __('Rules of the Pre-Season draft') } }}</h2>
        <dl class="m-0 grid grid-cols-1 gap-x-6 text-[13px] md:grid-cols-2">
            @foreach ([
                [__('Game weights, per winning player'), collect($inForce->weights)->map(fn ($milli, $key) => ChainOverview::keyLabel((string) $key).' '.SeasonRelease::factor((int) $milli).'×')->implode(', ')],
                [__('Share cap per era'), collect($inForce->shares)->map(fn ($share, $game) => ChainOverview::gameLabel((string) $game).' '.$share.' %')->implode(', ')],
                [__('Blocks per player a day'), collect($inForce->daily)->map(fn ($daily, $game) => ChainOverview::gameLabel((string) $game).' '.$daily)->implode(', ')],
                [__('Blocks per pairing'), __(':day a day, :season in the season', ['day' => $inForce->pairLimitPerDay, 'season' => $inForce->pairLimitPerSeason])],
                [__('Same trust circle'), $inForce->subtree > 100 ? __('rule off') : __('no block when both get :percent % or more of their trust from one person', ['percent' => $inForce->subtree])],
                [__('Real chess game'), __(':moves moves or more', ['moves' => $inForce->moves])],
            ] as [$term, $value])
                <div class="flex flex-col gap-0.5 border-b border-hairline py-2.5"><dt class="text-xs text-ink-2">{{ $term }}</dt><dd class="m-0">{{ $value }}</dd>
                    @if ($term === __('Share cap per era') && array_sum($inForce->shares) > 0)
                        {{-- The split as a bar in each game's colour (P53); the words above carry the numbers, the colour never alone. --}}
                        <div class="mt-1.5 flex h-2 max-w-80 gap-0.5 overflow-hidden rounded-[2px] bg-raised" aria-hidden="true" data-test="share-cap-bar">
                            @foreach ($inForce->shares as $shareGame => $share)
                                <span @class(['h-full basis-0', match (true) { $shareGame === 'chess' => 'bg-chess', str_starts_with((string) $shareGame, 'rocket') => 'bg-rl', str_starts_with((string) $shareGame, 'ea-sports-fc') => 'bg-fc', $shareGame === 'age-of-empires-2' => 'bg-aoe', default => 'bg-ink-3' }]) style="flex-grow: {{ max(0, (float) $share) }}" title="{{ ChainOverview::gameLabel((string) $shareGame) }} {{ $share }} %"></span>
                            @endforeach
                            @if (array_sum($inForce->shares) < 100)
                                <span class="h-full basis-0" style="flex-grow: {{ 100 - array_sum($inForce->shares) }}"></span>
                            @endif
                        </div>
                    @endif
                </div>
            @endforeach
        </dl>

        @if ($hasChain)
            <h3 class="m-0 mt-2 text-[13px] font-bold">{{ __('Change log') }}</h3>
            <ol class="m-0 flex list-none flex-col p-0" data-test="mining-changes">
                @foreach ($chain['changes'] as $change)
                    <li class="flex flex-col gap-1 border-b border-hairline py-2.5 text-[13px]">
                        <span class="flex flex-wrap gap-x-3"><b>{{ $date($change->effective_at) }}</b><span class="text-ink-2">{{ __('by :name', ['name' => $change->changedBy?->displayName() ?? substr($change->changed_by_pubkey, 0, 8)]) }}</span></span>
                        <span class="text-ink-2">{{ $change->reason }}</span>
                    </li>
                @endforeach
                <li class="flex flex-col gap-1 py-2.5 text-[13px]">
                    <span class="flex flex-wrap gap-x-3"><b>{{ $date($chain['season']->genesis_at) }}</b><span class="text-ink-2">{{ __('Block 0, released by :name', ['name' => $chain['season']->releasedBy?->displayName() ?? substr($chain['season']->released_by_pubkey, 0, 8)]) }}</span></span>
                    <span class="text-ink-2">{{ $chain['season']->genesis_message }}</span>
                </li>
            </ol>
            @if ($live)
                <p class="m-0 text-xs text-ink-3">{{ __('Any board member can adjust a limit during the season. A change counts only for blocks saved after it takes effect, never back.') }}</p>
            @endif
        @endif
    </section>
</div>
