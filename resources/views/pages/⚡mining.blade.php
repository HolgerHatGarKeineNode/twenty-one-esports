<?php

use App\Models\Season;
use App\Models\SeasonBlockVoid;
use App\Models\SeasonPayout;
use App\Models\User;
use App\Support\Badges\BadgeCopy;
use App\Support\Cards\ShareCard;
use App\Support\Cards\ShareMoments;
use App\Support\Engagement\HomeBoard;
use App\Support\GameNames;
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
        app(PageMeta::class)->describe(__('Season'), __('Every fair rated win is a block, counted in the order the league saves results. The reward of a game halves as that game mines its share; the sats are paid once, after the season review.'));
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
     * The ladders of the league per game (Season.dc.html "Ladders"): the registry's display order, each game's modes
     * and the page its first ladder opens; the score games keep leaderboards, not ladders.
     *
     * @return list<array{slug: string, name: string, modes: string, href: string}>
     */
    #[Computed]
    public function ladders(): array
    {
        $registry = app(\App\Games\GameRegistry::class);
        $rows = [];

        foreach ($registry->all() as $slug => $game) {
            $row = match ($game->kind()) {
                \App\Games\GameKind::Score => null,
                \App\Games\GameKind::Strategy => \Illuminate\Support\Facades\Route::has('hyper.ladder') ? ['modes' => __('Season ladder'), 'href' => route('hyper.ladder')] : null,
                \App\Games\GameKind::Arcade => \Illuminate\Support\Facades\Route::has('pong.ladder') ? ['modes' => 'Elo', 'href' => route('pong.ladder')] : null,
                default => (function () use ($slug, $game): ?array {
                    $modes = $slug === 'chess' ? \App\Support\Chess\ChessModes::all() : array_keys($game->modes());

                    return $modes === [] ? null : [
                        'modes' => implode(', ', array_map(fn (string $mode): string => $slug === 'chess' ? \App\Support\Chess\ChessModes::short($mode) : GameNames::mode($slug, $mode), $modes)),
                        'href' => route('ladder.show', [$slug, $modes[0]]),
                    ];
                })(),
            };

            if ($row !== null) {
                $rows[] = ['slug' => $slug, 'name' => GameNames::game($slug), ...$row];
            }
        }

        return $rows;
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
    $block0At = $live || $ended ? null : PreSeason::block0At();
    $stateTag = match (true) {
        $live => BadgeCopy::season($chain['season']->slug),
        $ended => __('Between seasons'),
        default => __('Pre-Season'),
    };
    // The era table's short heads (Season.dc.html: "RL 1v1", "Schach Blitz"): the registry's short name and the mode.
    $registry = app(\App\Games\GameRegistry::class);
    $shortKey = function (string $key) use ($registry): string {
        [$game, $mode] = array_pad(explode('/', $key, 2), 2, null);
        $found = $registry->find($game);
        if ($found === null || $mode === null || $game === 'chess') {
            return ChainOverview::keyLabel($key);
        }

        return __($found->assets()->shortLabel).' '.($registry->isBoard($game) ? GameNames::mode($game, $mode) : $mode);
    };
    $payKey = collect(array_keys($chain['rewards_now']))->first(fn (string $key): bool => ChainOverview::mines($key));
    $firstPay = $payKey === null ? 0 : (int) ($chain['schedule'][0]['rewards'][$payKey] ?? 0);
    // Block 0 signs the shares; before it they are the draft's and can still change.
    $shareTerm = $live || $ended ? __('Share of the supply, fixed at Block 0') : __('Share of the supply');
    // The share key of every game and mode: a game stands in the era of its own key (`season-chain-v2`).
    $shareOf = [];
    foreach (array_keys($chain['rewards_now']) as $weightKey) {
        $shareOf[$weightKey] = $inForce->shareKey(explode('/', (string) $weightKey, 2)[0]);
    }
@endphp

{{--
    The Season (Season.dc.html, SeasonPhone.dc.html; template T14; rules R1, R2, R7, R14): the name with its state, "gerade
    eben", before Block 0 the countdown hero with the genesis block, the chain stats, what a win pays per era, then the
    ladders, the latest blocks and the advanced statistics on the left, the strongest players, the league reserve and
    the payouts on the right, the Nostr line last. Between seasons the review and the payouts follow.
--}}
<div class="flex grow flex-col gap-4 px-4 pt-4 pb-10 lg:gap-6 lg:px-12 lg:pt-8" data-test="mining" data-state="{{ $state }}">
    <div class="flex flex-wrap items-center gap-4">
        <h1 class="rv-h1 max-lg:text-[24px]">{{ __('Season') }}</h1>
        <span class="rv-tag" data-test="season-state">{{ $stateTag }}</span>
    </div>

    <x-ui.live-bar :ticks="(new HomeBoard($viewer instanceof User ? $viewer : null))->ticker()" />

    @unless ($live)
        <section aria-labelledby="b0-h" class="sn-hero" data-test="mining-rest">
            <div class="flex min-w-0 flex-col gap-3">
                <span class="rv-tag is-btc self-start">{{ $ended ? __('Between seasons') : __('Pre-Season · countdown to Block 0') }}</span>
                <h2 id="b0-h" class="m-0 font-display text-[24px] leading-[1.1] font-bold lg:text-[36px]">{{ $ended ? __('The chain rests between seasons') : __('The chain starts at Block 0') }}</h2>
                <p class="m-0 max-w-[60ch] text-[14px] text-ink-2">{{ Seasons::restMessage($viewer instanceof User ? $viewer : null) }}</p>
                @unless ($ended)
                    <x-ui.countdown :at="$block0At" units="dhms" :label="__('Block 0 in')" class="sn-cd" />
                @endunless
                <div class="flex flex-wrap gap-3">
                    <a href="{{ route('chess.lobby') }}" class="rv-b h-[52px] px-6">{{ __('Play a casual game') }}</a>
                    @unless ($ended)
                        @guest
                            <a href="{{ route('login') }}" class="rv-b2 h-[52px]" data-test="notify-block0"><x-icon name="bell" :size="14" />{{ __('Notify me at Block 0') }}</a>
                        @else
                            @if ($viewer->notify_block0_at === null)
                                <form method="POST" action="{{ route('notify.block0') }}">
                                    @csrf
                                    <button type="submit" class="rv-b2 h-[52px]" data-test="notify-block0"><x-icon name="bell" :size="14" />{{ __('Notify me at Block 0') }}</button>
                                </form>
                            @else
                                <p role="status" class="m-0 flex h-[52px] items-center gap-2 text-[13px] text-btc-hi" data-test="notify-set"><x-icon name="check" :size="14" />{{ __("We'll tell you at Block 0") }}</p>
                            @endif
                        @endguest
                    @endunless
                </div>
                <p class="m-0 text-xs text-ink-3">{{ $ended ? __('The numbers below are the season that ended :when, as it closed.', ['when' => $date($chain['season']->ends_at, 'D j M')]) : __('The numbers below are the Pre-Season draft. The board can still change them before it releases Block 0.') }}</p>
            </div>
            {{-- Block 0 waits for the board; blocks 1 to 4 follow it (dashed, not mined yet). --}}
            <div class="flex items-center max-lg:hidden" aria-label="{{ __('Chain: Block 0 waits, then block 1 to 4') }}" role="img">
                <span class="sn-genesis"><span class="rv-cube-tp"></span><span class="rv-cube-sd"></span>
                    <span class="sn-genesis-fr" data-test="genesis-cube"><b class="text-xs">{{ __('Genesis') }}</b><span class="font-display text-[18px] leading-[1.2] font-bold whitespace-nowrap">{{ __('Block 0') }}</span><span class="text-xs leading-[1.2]">{{ $ended ? __('season ended') : __('waits for the board') }}</span></span>
                </span>
                @foreach ([66, 60, 54, 48] as $index => $size)
                    <span class="sn-link" aria-hidden="true"></span>
                    <span class="sn-ghost" style="width: {{ $size }}px; height: {{ $size }}px; opacity: {{ 0.85 - $index * 0.15 }}" aria-hidden="true">{{ $index + 1 }}</span>
                @endforeach
            </div>
        </section>
    @endunless

    {{-- P46: at season end, the player's own Season Wrapped card with its post (preview first, then the signer) --}}
    @if ($ended && $hasChain && $viewer instanceof User && ShareMoments::hasWrapped($chain['season'], $viewer))
        @php
            $wrapped = ShareCard::wrapped($chain['season'], $viewer);
        @endphp
        <section aria-labelledby="wrapped-h" class="rv-card flex flex-col gap-4 p-4 shadow-[inset_0_0_0_1px_#F7931A] sm:flex-row sm:items-start lg:p-6" data-test="season-wrapped">
            <img src="{{ $wrapped->path('wide') }}" alt="{{ __('Your :season on one card', ['season' => BadgeCopy::season($chain['season']->slug)]) }}" width="1200" height="630" loading="lazy"
                 class="aspect-[1200/630] h-auto w-full shrink-0 rounded-[4px] sm:w-[280px]">
            <div class="flex min-w-0 flex-col gap-2">
                <h2 id="wrapped-h" class="rv-h2">{{ __('Your :season, wrapped', ['season' => BadgeCopy::season($chain['season']->slug)]) }}</h2>
                <p class="m-0 max-w-[60ch] text-[13px] leading-normal text-ink-2">{{ __('Your blocks, sats, wins and best rank of the season on one card. Post it, or download it for your chats.') }}</p>
                <livewire:share-button type="wrapped" :moment="$chain['season']->slug" :label="__('Post my season')" :wire:key="'wrapped-share-'.$chain['season']->slug" />
            </div>
        </section>
    @endif

    <section aria-label="{{ __('Chain stats') }}" class="grid grid-cols-2 gap-3 lg:grid-cols-5 lg:gap-4" data-test="mining-stats">
        @php
            $minedOf = $hasChain ? __(':percent % of :supply', ['percent' => number_format($chain['mined'] / max(1, $chain['supply']) * 100, 1), 'supply' => $sats($chain['supply'])]) : '';
            $payLabel = $payKey ? __(':game pays', ['game' => $shortKey($payKey)]) : __('A win pays');
            $stats = match (true) {
                $live => [
                    [__('Eras'), (string) $chain['season']->eras, __('each game halves by what it mined')],
                    [__('Mined'), $sats($chain['mined']), $minedOf],
                    [__('Left to mine'), $sats($chain['remaining']), __('mining stops at 0 or at the season end')],
                    [__('Blocks'), (string) $chain['blocks'], trans_choice(':count today|:count today', $chain['blocks_today'])],
                    [$payLabel, $payKey ? $sats($chain['rewards_now'][$payKey]) : '0', __('sats per winner, now')],
                ],
                $ended => [
                    [__('Blocks'), (string) $chain['blocks'], __('at the season end')],
                    [__('Mined'), $sats($chain['mined']), $minedOf],
                    [__('Not mined'), $sats((int) $unmined), __('goes to the league reserve')],
                    [__('Players who mined'), (string) $chain['miner_count'], __('each paid once, after the review')],
                    [__('Season'), trans_choice(':count week|:count weeks', (int) round($chain['season']->genesis_at->diffInDays($chain['season']->ends_at) / 7)), __(':from to :to', ['from' => $date($chain['season']->genesis_at, 'j M'), 'to' => $date($chain['season']->ends_at, 'j M Y')])],
                ],
                default => [
                    [__('Eras'), (string) $chain['parameters']->eras(), __('each game halves by what it mined')],
                    // The board's saved chain draft only, as home and /rules: no server default is announced.
                    PreSeason::potSats() === null
                        ? [__('Supply'), '–', __('not announced yet')]
                        : [__('Supply'), $sats(PreSeason::potSats()), __('the most it pays out, after the season')],
                    [__('Mined'), '0', __('nothing before Block 0')],
                    [__('Blocks'), '0', __('Block 1 follows Block 0')],
                    [$payLabel, $payKey ? $sats($chain['rewards_now'][$payKey]) : '0', __('sats per winner, era :era', ['era' => 1])],
                ],
            };
        @endphp
        @foreach ($stats as [$label, $value, $sub])
            <div @class(['rv-card flex min-w-0 flex-col gap-1 p-4', 'col-span-2 lg:col-span-1' => $loop->last])>
                <span class="rv-m">{{ $label }}</span>
                <b class="font-display text-[22px] leading-tight lg:text-[26px]">{{ $value }}</b>
                <span class="rv-m">{{ $sub }}</span>
            </div>
        @endforeach
    </section>

    {{-- Every game in its own halving (`season-chain-v2`): no era of the season, no date. Before the board saved a
         draft the pots would announce a server default, so the section waits for it (as the supply tile). --}}
    @if ($live || $ended || PreSeason::potSats() !== null)
    <section aria-labelledby="keys-h" class="rv-card flex flex-col p-4 lg:p-6" data-test="mining-keys">
        <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
            <h2 id="keys-h" class="rv-h2">{{ __('Halving per game') }}</h2>
            <span class="rv-m">{{ match (true) { $ended => __('where each game stood at the season end'), $live => __('a game halves once it mined half of what its share still holds'), default => __('a game halves once it mined half of what its share still holds; values from the Pre-Season draft') } }}</span>
        </div>
        <x-key-halvings :keys="$chain['keys']" :live="$live" :ended="$ended" />
    </section>
    @endif

    @if ($hasChain)
        <section aria-labelledby="supply-h" class="rv-card flex flex-col gap-3 p-4 lg:p-6" data-test="mining-supply">
            <h2 id="supply-h" class="rv-h2">{{ __('Supply: mined and left') }}</h2>
            <x-supply-chart :curve="$chain['curve']" :zone="$zone" :ended="$ended" />
            @if ($chain['curve']['halvings'] !== [])
                {{-- The halvings that happened, in words: the chart's hairlines carry no numbers on a phone. --}}
                <ul class="m-0 flex list-none flex-col gap-1 p-0 text-xs text-ink-2" data-test="mining-halvings-done">
                    @foreach ($chain['curve']['halvings'] as $halving)
                        <li>{{ $halving['closed']
                            ? __(':game mined its whole share on :when', ['game' => ChainOverview::gameLabel($halving['key']), 'when' => $date($halving['at'], 'D j M')])
                            : __(':game halved on :when and entered era :era', ['game' => ChainOverview::gameLabel($halving['key']), 'when' => $date($halving['at'], 'D j M'), 'era' => $halving['era'] + 1]) }}</li>
                    @endforeach
                </ul>
            @endif
            @if ($live)
                <p class="m-0 text-xs text-ink-2">{{ __('At the rate of the last 4 weeks about :sats sats get mined by the season end (:percent %).', ['sats' => $sats($chain['estimate']['end_mined']), 'percent' => $chain['estimate']['end_mined_percent']]) }}</p>
            @endif
        </section>
    @endif

    <section aria-labelledby="eras-h" class="rv-card flex min-w-0 flex-col p-0" data-test="mining-eras">
        <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 p-4">
            <h2 id="eras-h" class="rv-h2">{{ __('What a win pays') }}</h2>
            <span class="rv-m">{{ $live || $ended ? __('sats per winner in each era of a game') : __('sats per winner in each era of a game, values from the Pre-Season draft') }}</span>
        </div>
        {{-- The table scrolls inside its card, never the page (it ran 970 px wide at 390, 2430 px in a 1342 px card at
             1440): the edge fades say there is more on that side (the chain viewport's pattern), the region takes the
             keyboard. --}}
        <x-ui.scroll-x :label="__('What a win pays, scrolls sideways')" data-test="mining-eras-scroll">
            <table class="rv-tb sn-tb">
                <thead>
                    <tr>
                        <th scope="col">{{ __('Era') }}</th>
                        <th scope="col"><span class="sr-only">{{ $payKey ? __(':game pays', ['game' => $shortKey($payKey)]) : '' }}</span></th>
                        @foreach (array_keys($chain['rewards_now']) as $key)
                            <th scope="col" class="is-r" @unless (ChainOverview::mines($key)) data-test="reward-not-open" @endunless>{{ $shortKey($key) }}</th>
                        @endforeach
                        @foreach ($games as $game)
                            <th scope="col" class="is-r">{{ __(':game mined', ['game' => ChainOverview::gameLabel($game)]) }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($chain['schedule'] as $row)
                        @php($pay = $payKey === null ? 0 : (int) ($row['rewards'][$payKey] ?? 0))
                        <tr>
                            <td class="text-btc">{{ $row['era'] }}</td>
                            <td>
                                @if ($firstPay > 0)
                                    <span class="flex items-center gap-2">
                                        <span class="sn-bar" style="width: {{ max(2, round(60 * $pay / $firstPay)) }}px" aria-hidden="true" data-test="era-pay-bar"></span>
                                        <span class="font-display text-[13px] text-btc-hi tabular-nums">{{ $sats($pay) }}</span>
                                    </span>
                                @endif
                            </td>
                            @foreach ($row['rewards'] as $key => $reward)
                                {{-- A game's own era, marked per cell: chess may stand in era 2 while the board games are in era 1. --}}
                                @php($payNow = $live && ChainOverview::mines((string) $key) && in_array($shareOf[$key] ?? '', $row['keys'], true))
                                <td @class(['is-r tabular-nums', 'text-ink-3' => ! ChainOverview::mines((string) $key), 'font-bold text-btc-hi' => $payNow]) @if ($payNow) data-test="pays-now" data-key="{{ $key }}" @endif>{{ ChainOverview::mines((string) $key) ? $sats($reward) : '–' }}</td>
                            @endforeach
                            @foreach ($games as $game)
                                @php($minedHere = (int) ($hasChain ? ($chain['mined_by_game_and_era'][$game][$row['era']] ?? 0) : 0))
                                @php($capHere = (int) ($row['caps'][$game] ?? 0))
                                <td @class(['is-r tabular-nums', 'text-ink-2' => ! ($live && in_array($game, $row['keys'], true)), 'text-btc-hi' => $live && in_array($game, $row['keys'], true)])>
                                    {{ $sats($minedHere) }} / {{ $sats($capHere) }}
                                    @if ($minedHere > 0)
                                        {{-- How much of the game's era cap is mined, as a bar under the numbers (P53). --}}
                                        <span class="mt-1 ml-auto block h-1 w-full max-w-24 overflow-hidden rounded-[2px] bg-raised" aria-hidden="true" data-test="era-cap-bar">
                                            <span class="block h-full bg-btc" style="width: {{ $capHere > 0 ? min(100, round(100 * $minedHere / $capHere, 2)) : 0 }}%"></span>
                                        </span>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-ui.scroll-x>
        <div class="flex flex-col gap-1 p-4 rv-m">
            @unless (collect(array_keys($chain['rewards_now']))->filter(fn (string $key): bool => str_starts_with($key, 'chess/'))->every(fn (string $key): bool => ChainOverview::mines($key)))
                <span data-test="rated-chess-not-open">{{ __('Chess counts once rated chess opens; until then –.') }}</span>
            @endunless
            @unless (collect(array_keys($chain['rewards_now']))->reject(fn (string $key): bool => str_starts_with($key, 'chess/'))->every(fn (string $key): bool => ChainOverview::mines($key)))
                <span data-test="rated-board-not-open">{{ __('Board games count once their rated games open; until then –.') }}</span>
            @endunless
            <span>{{ __('Mined / era size: a game enters its next era once it has mined this much; the block that crosses the line counts in full.') }}</span>
            @if ($live)
                <span>{{ __('Highlighted: the era each game stands in now.') }}</span>
            @endif
        </div>
    </section>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-12 lg:gap-6">
        <div class="flex min-w-0 flex-col gap-4 lg:col-span-8 lg:gap-6">
            @if ($hasChain)
                <section aria-labelledby="latest-h" class="rv-card flex flex-col gap-2 p-4 lg:p-6" data-test="mining-latest">
                    <div class="flex flex-wrap items-baseline gap-x-3"><h2 id="latest-h" class="rv-h2">{{ __('Latest blocks') }}</h2><span class="rv-m">{{ __('mined, pending the season review') }}</span></div>
                    @forelse ($chain['latest'] as $block)
                        {{-- Anchored by height: the Mempool chain links a mined match here. --}}
                        <div id="block-{{ $block['height'] }}" class="grid scroll-mt-24 grid-cols-[56px_minmax(0,1fr)_auto] items-center gap-3 border-b border-hairline py-2.5 text-[13px] last:border-0 target:bg-btc-chip">
                            <b class="font-display text-base">{{ $block['height'] }}</b>
                            <span class="flex min-w-0 flex-col gap-0.5">
                                <span class="truncate">{{ $block['label'] }}, {{ ChainOverview::keyLabel($block['key']) }}</span>
                                <span class="truncate text-xs text-ink-2">{{ implode(', ', $block['winners']) }}</span>
                            </span>
                            <span class="flex flex-col items-end gap-0.5"><b class="tabular-nums">{{ $sats($block['reward']) }}</b><span class="text-xs whitespace-nowrap text-ink-3">{{ $date($block['at'], 'D H:i') }}</span></span>
                        </div>
                    @empty
                        <p class="m-0 py-4 text-[13px] text-ink-2">{{ $ended ? __('No block was mined this season.') : __('No block yet. The first fair rated win mines block 1.') }}</p>
                    @endforelse
                </section>

                <section aria-labelledby="nomine-h" class="rv-card flex flex-col gap-2 p-4 lg:p-6" data-test="mining-rejected">
                    <h2 id="nomine-h" class="rv-h2">{{ __('Wins that did not mine') }}</h2>
                    @forelse ($chain['rejected'] as $reason => $count)
                        <div class="flex items-center justify-between gap-3 border-b border-hairline py-2 text-[13px] last:border-0"><span>{{ ChainOverview::reasonLabel((string) $reason) }}</span><b class="tabular-nums">{{ $count }}</b></div>
                    @empty
                        <p class="m-0 text-[13px] text-ink-2">{{ __('Every rated win so far mined a block.') }}</p>
                    @endforelse
                </section>
            @endif

            <section aria-labelledby="lad-h" class="rv-card flex flex-col p-0" data-test="mining-ladders">
                <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 p-4"><h2 id="lad-h" class="rv-h2">{{ __('Ladders') }}</h2><span class="rv-m">{{ $live ? __('rated and casual Elo per game and mode') : __('casual Elo per game and mode, until Block 0') }}</span></div>
                <table class="rv-tb">
                    <tbody>
                        @foreach ($this->ladders as $ladder)
                            <tr data-test="mining-ladder-{{ $ladder['slug'] }}">
                                {{-- Phones: no cover and the link as a 44 px chevron, so "Hyperbitcoinization" and the link fit 320 px (the row ran 418 px at 390). --}}
                                <td class="w-[60px] max-sm:hidden"><x-game-cover :game="$ladder['slug']" size="thumb" class="h-6 w-[43px] rounded-[2px] object-cover" /></td>
                                <td class="max-sm:w-full">{{ $ladder['name'] }}</td>
                                <td class="text-ink-2 max-sm:hidden">{{ $ladder['modes'] }}</td>
                                <td class="is-r max-sm:py-0 max-sm:pr-1"><a href="{{ $ladder['href'] }}" class="rv-lk inline-flex min-h-11 items-center justify-center whitespace-nowrap max-sm:min-w-11" data-test="mining-ladder-link"><span class="max-sm:sr-only">{{ __('Open the ladder') }}</span><x-icon name="next" :size="16" class="sm:hidden" /></a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </section>

            {{-- Advanced statistics (canvas NEU (Scope)): the halvings as bars and the rules in force, from the same chain. --}}
            <details class="rv-adv" open data-test="mining-rules">
                <summary><x-icon name="chevron-down" :size="16" />{{ __('Advanced statistics') }} <span class="rv-m font-normal max-sm:hidden">{{ $payKey ? __('Halvings of the season, :game', ['game' => $shortKey($payKey)]) : __('Halvings of the season') }}</span></summary>
                <div class="flex flex-col gap-6 p-4">
                    @if ($firstPay > 0)
                        <figure class="m-0 flex flex-col gap-2">
                            <figcaption class="text-[13px] text-ink-2">{{ __('Sats per win per era') }}</figcaption>
                            <div class="flex items-end gap-2 border-b border-edge" role="img" aria-label="{{ collect($chain['schedule'])->map(fn ($row) => __('Era :era', ['era' => $row['era']]).': '.$sats((int) ($row['rewards'][$payKey] ?? 0)))->implode(', ') }}">
                                @foreach ($chain['schedule'] as $row)
                                    @php($pay = (int) ($row['rewards'][$payKey] ?? 0))
                                    <div class="flex h-[150px] flex-1 flex-col items-center justify-end gap-1" aria-hidden="true">
                                        <span class="rv-m text-ink-2 tabular-nums">{{ $sats($pay) }}</span>
                                        <span class="block w-full bg-btc" style="height: {{ max(2, round(110 * $pay / $firstPay)) }}px"></span>
                                        <span class="rv-m">E{{ $row['era'] }}</span>
                                    </div>
                                @endforeach
                            </div>
                        </figure>
                    @endif
                    <table class="rv-tb">
                        <thead><tr><th class="max-sm:whitespace-normal">{{ match (true) { $live => __('Rules in force now'), $ended => __('Rules at the season end'), default => __('Rules of the Pre-Season draft') } }}</th><th class="is-r">{{ __('Value') }}</th></tr></thead>
                        <tbody>
                            @foreach ([
                                [__('Game weights, per winning player'), collect($inForce->weights)->map(fn ($milli, $key) => $shortKey((string) $key).' '.SeasonRelease::factor((int) $milli).'×')->implode(', ')],
                                [$shareTerm, collect($inForce->shares)->map(fn ($share, $game) => ChainOverview::gameLabel((string) $game).' '.$share.' %')->implode(', ')],
                                [__('Blocks per player a day'), collect($inForce->daily)->map(fn ($daily, $game) => ChainOverview::gameLabel((string) $game).' '.$daily)->implode(', ')],
                                [__('Blocks per pairing'), __(':day a day, :season in the season', ['day' => $inForce->pairLimitPerDay, 'season' => $inForce->pairLimitPerSeason])],
                                [__('Same trust circle'), $inForce->subtree > 100 ? __('rule off') : __('no block from :percent %', ['percent' => $inForce->subtree])],
                                [__('Real chess game'), __('from :moves moves', ['moves' => $inForce->moves])],
                            ] as [$term, $value])
                                <tr class="max-sm:flex max-sm:flex-col max-sm:border-b max-sm:border-hairline max-sm:py-2">
                                    <td class="whitespace-normal max-sm:h-auto max-sm:border-0 max-sm:p-0 max-sm:text-ink-2">{{ $term }}
                                        @if ($term === $shareTerm && array_sum($inForce->shares) > 0)
                                            {{-- The split as a bar in each game's colour (P53); the words carry the numbers, the colour never alone. --}}
                                            <span class="mt-1.5 flex h-2 max-w-80 gap-0.5 overflow-hidden rounded-[2px] bg-raised" aria-hidden="true" data-test="share-cap-bar">
                                                @foreach ($inForce->shares as $shareGame => $share)
                                                    <span @class(['h-full basis-0', match (true) { $shareGame === 'chess' => 'bg-chess', str_starts_with((string) $shareGame, 'rocket') => 'bg-rl', str_starts_with((string) $shareGame, 'ea-sports-fc') => 'bg-fc', $shareGame === 'age-of-empires-2' => 'bg-aoe', default => 'bg-ink-3' }]) style="flex-grow: {{ max(0, (float) $share) }}" title="{{ ChainOverview::gameLabel((string) $shareGame) }} {{ $share }} %"></span>
                                                @endforeach
                                                @if (array_sum($inForce->shares) < 100)
                                                    <span class="h-full basis-0" style="flex-grow: {{ 100 - array_sum($inForce->shares) }}"></span>
                                                @endif
                                            </span>
                                        @endif
                                    </td>
                                    <td class="is-r whitespace-normal max-sm:h-auto max-sm:border-0 max-sm:p-0 max-sm:text-left">{{ $value }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>

                    @if ($hasChain)
                        <div class="flex flex-col gap-2">
                            <h3 class="rv-h3">{{ __('Change log') }}</h3>
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
                        </div>
                    @endif
                </div>
            </details>
        </div>

        <div class="flex min-w-0 flex-col gap-4 lg:col-span-4 lg:gap-6">
            <section aria-labelledby="miners-h" class="rv-card flex flex-col gap-3 p-4" data-test="mining-strongest">
                <h2 id="miners-h" class="rv-h2">{{ $hasChain ? __('Top miners') : __('The strongest players') }}</h2>
                @if ($hasChain)
                    @forelse ($chain['miners'] as $index => $miner)
                        <div class="grid grid-cols-[24px_minmax(0,1fr)_auto] items-center gap-3 border-b border-hairline py-2 text-[13px] last:border-0">
                            <span class="text-ink-3">{{ $index + 1 }}</span><span class="truncate">{{ $miner['name'] }}</span>
                            <span class="text-right"><b class="tabular-nums">{{ $sats($miner['sats']) }}</b> <span class="text-xs text-ink-3">{{ trans_choice(':count block|:count blocks', $miner['blocks']) }}</span></span>
                        </div>
                    @empty
                        <p class="m-0 py-2 text-[13px] text-ink-2">{{ __('Nobody has mined yet.') }}</p>
                    @endforelse
                    <p class="m-0 text-xs text-ink-3">{{ __('Sats mined wait for the season review; nobody is paid during the season.') }}</p>
                @else
                    <div class="flex flex-col gap-3 rounded-[4px] border border-dashed border-line p-4">
                        <b class="text-[14px]">{{ __('The list opens at Block 0') }}</b>
                        <span class="text-[13px] text-ink-2">{{ __('From then on everyone with enough rated results stands here, with a Global Rating across all games.') }}</span>
                        <a href="{{ route('ladder.strongest') }}" class="rv-lk self-start text-[13px]">{{ __('How it is counted') }}</a>
                    </div>
                @endif
            </section>

            <section aria-labelledby="reserve-h" class="rv-card flex flex-col gap-3 p-4" data-test="mining-reserve">
                <div class="flex items-baseline gap-3">
                    <h2 id="reserve-h" class="rv-h2">{{ __('League reserve') }}</h2>
                    <span class="grow"></span>
                    <span class="rv-m" data-test="reserve-zap-count">{{ trans_choice(':count zap|:count zaps', $zaps['count']) }}</span>
                </div>
                @if ($unmined !== null)
                    <div class="flex items-baseline justify-between gap-3 border-b border-hairline pb-2.5 text-[13px]" data-test="reserve-unmined">
                        <span class="text-ink-2">{{ $live ? __('Unmined rest at the season end, at the current rate') : __('Unmined rest of the season') }}</span>
                        <b class="whitespace-nowrap tabular-nums">{{ $live ? '~' : '' }}{{ $sats($unmined) }}</b>
                    </div>
                @endif
                @if ($zaps['latest'] !== [])
                    <ul class="m-0 flex list-none flex-col p-0 text-[13px]" data-test="reserve-zaps">
                        @foreach ($zaps['latest'] as $zap)
                            <li class="grid grid-cols-[minmax(0,1fr)_auto_auto] items-baseline gap-3 border-b border-hairline py-2 last:border-0">
                                <span class="truncate">{{ $zap['name'] }}</span>
                                <span class="whitespace-nowrap"><b class="tabular-nums">{{ $sats($zap['sats']) }}</b> Sats</span>
                                <span class="text-xs whitespace-nowrap text-ink-3">{{ $date($zap['at'], 'j M') }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
                @if ($this->reserveZapQr !== null)
                    <div class="flex items-center gap-3" data-test="reserve-zap">
                        <div class="size-28 shrink-0 rounded-[4px] bg-white p-2" data-test="reserve-zap-qr">{!! $this->reserveZapQr !!}</div>
                        <p class="m-0 rv-m">{{ __('Scan with a Lightning wallet or a Nostr client.') }}</p>
                    </div>
                @endif
            </section>

            @if ($hasChain)
                <section aria-labelledby="payouts-h" class="rv-card flex flex-col gap-2 p-4" data-test="mining-payouts">
                    <h2 id="payouts-h" class="rv-h2">{{ __('Payouts') }}</h2>
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

            {{-- P45, rule R7: the season chain on Nostr (its genesis), the reserve's zap QR and a follow, one line at the end. --}}
            <x-nostr-bar :bar="\App\Support\Nostr\NostrBar::season($chain['season'], $this->reserveZapQr)" />
        </div>
    </div>

    @if ($hasChain)
        <section aria-labelledby="review-h" class="rv-card flex flex-col gap-2 p-4 lg:p-6" data-test="mining-review">
            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                <h2 id="review-h" class="rv-h2">{{ __('Season review') }}</h2>
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
    @endif

    @if ($settlement !== null)
        <section id="season-payouts" aria-labelledby="season-payouts-h" class="rv-card flex flex-col gap-3 p-4 lg:p-6" data-test="mining-season-payouts">
            <h2 id="season-payouts-h" class="rv-h2">{{ __('Season payouts') }}</h2>
            <p class="m-0 max-w-[68ch] text-xs leading-normal text-ink-2">{{ __('What each player was paid for their blocks, once, to the Lightning address in their Nostr profile. Each payment is a signed Payout event with the invoice and its preimage. No Lightning address in your profile? Add one so your sats can be paid.') }}</p>
            @forelse ($settlement['paid'] as $payout)
                <div class="grid grid-cols-[minmax(0,1fr)_auto] items-baseline gap-x-3 gap-y-0.5 border-b border-hairline py-2 text-[13px] last:border-0" data-test="mining-season-payout">
                    <span class="truncate">@if ($payout->user)<x-player-link :user="$payout->user" />@else{{ $payout->name }}@endif</span>
                    <b class="whitespace-nowrap">{{ $sats($payout->amount_sats) }} {{ __('sats') }}</b>
                    <span class="text-xs text-ink-3">{{ trans_choice(':count block|:count blocks', $payout->blocks) }}, {{ $payout->paid_at ? $date($payout->paid_at, 'j M Y') : '' }}</span>
                    @if ($payout->event)
                        <a href="{{ LadderBoard::NJUMP.NostrKeys::nevent($payout->event->event_id, $payout->event->pubkey, 2157) }}" rel="noopener noreferrer" target="_blank" class="text-xs whitespace-nowrap text-proof underline decoration-proof/50 underline-offset-2" data-test="mining-season-payout-proof">{{ __('Payout event') }}<span class="sr-only"> {{ __('(opens njump.me in a new tab)') }}</span></a>
                    @endif
                </div>
            @empty
                <p class="m-0 text-[13px] text-ink-2">{{ __('No season payout has been paid yet.') }}</p>
            @endforelse
        </section>
    @endif

    {{-- How the season works: when a rated win mines a block, the supply and the genesis message (moved here from home, P3). --}}
    @include('partials.season-rules', ['laterSeason' => $live && $chain['season']?->slug !== App\Support\SeasonChain\SeasonRelease::SLUG, 'liveSeason' => Seasons::live()])
</div>
