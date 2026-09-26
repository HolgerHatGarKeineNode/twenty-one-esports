<?php

use App\Models\User;
use App\Support\PreSeason;
use App\Support\SeasonChain\ChainOverview;
use App\Support\SeasonChain\SeasonRelease;
use App\Support\SeasonChain\Seasons;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * /mining (Mining.dc.html, MobileMining.dc.html), from the real chain (P7c):
 * the live season's tip, supply mined and left, the era schedule with what a
 * win pays, the share of each game per era, the latest blocks, the top
 * miners, why wins did not mine, the rules in force and the public change
 * log. Before Block 0 and between seasons: the rest state with the countdown
 * and the Pre-Season draft (config/season.php), which the board releases.
 *
 * Not built here (later phases): the supply chart over time, fees and zaps,
 * the league reserve, payouts and the season review (P9, P10).
 */
new #[Title('Mining')] #[Layout('layouts::app', ['section' => null])] class extends Component {
    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function chain(): array
    {
        $season = Seasons::live();

        return $season === null ? app(ChainOverview::class)->draft() : app(ChainOverview::class)->live($season);
    }
}; ?>

@php
    $viewer = auth()->user();
    $zone = PreSeason::timezoneFor($viewer instanceof User ? $viewer : null);
    $chain = $this->chain;
    $live = $chain['season'] !== null;
    $state = Seasons::state();
    $sats = fn (int $value): string => PreSeason::formatSats($value);
    $date = fn ($at, string $format = 'D j M, H:i'): string => $at->copy()->setTimezone($zone)->locale(app()->getLocale())->translatedFormat($format);
    $inForce = $chain['in_force'];
    $games = array_keys($inForce->shares + $inForce->daily);
@endphp

<div class="flex grow flex-col gap-4 px-4 pb-10 lg:gap-6 lg:px-12" data-test="mining" data-state="{{ $state }}">
    <div class="flex flex-col gap-2">
        <h1 class="m-0 font-display text-[26px] font-bold lg:text-[34px]">{{ __('Mining') }}</h1>
        <p class="m-0 max-w-[80ch] text-[13px] leading-normal text-ink-2">{{ __('Every fair rated win is a block, counted in the order the league saves results. Rewards halve every era and are paid once, after the season review.') }}</p>
    </div>

    @unless ($live)
        <section class="flex flex-col gap-3 rounded-lg bg-card px-4 py-5 shadow-[inset_0_0_0_1px_#3A2A12] lg:px-8 lg:py-7" data-test="mining-rest">
            <x-empty-state :heading="$state === 'between' ? __('The chain rests between seasons') : __('The chain starts at Block 0')" :text="Seasons::restMessage($viewer instanceof User ? $viewer : null)">
                <a href="{{ route('chess.lobby') }}" class="btn-p inline-flex h-11 items-center rounded-md bg-btc px-5 text-sm font-bold text-on-btc hover:text-on-btc">{{ __('Play a casual game') }}</a>
                <a href="{{ route('home') }}#block0" class="inline-flex h-11 items-center rounded-md border border-edge bg-ground px-5 text-sm text-ink hover:text-ink">{{ __('Block 0 countdown') }}</a>
            </x-empty-state>
            <p class="m-0 text-xs text-ink-3">{{ __('The numbers below are the Pre-Season draft. The board can still change them before it releases Block 0.') }}</p>
        </section>
    @endunless

    <section aria-label="{{ __('Chain stats') }}" class="grid grid-cols-2 gap-3 lg:grid-cols-5 lg:gap-4" data-test="mining-stats">
        @php
            $firstKey = array_key_first($chain['rewards_now']);
            $stats = $live ? [
                [__('Era'), (string) $chain['era'], $chain['next_halving'] ? __('next halving :when', ['when' => $date($chain['next_halving'])]) : __('last era')],
                [__('Mined'), $sats($chain['mined']), __(':percent % of :supply', ['percent' => number_format($chain['mined'] / max(1, $chain['supply']) * 100, 1), 'supply' => $sats($chain['supply'])])],
                [__('Left in the pot'), $sats($chain['remaining']), __('mining stops at 0 or at the season end')],
                [__('Blocks'), (string) $chain['blocks'], trans_choice(':count today|:count today', $chain['blocks_today'])],
                [$firstKey ? __(':game win pays', ['game' => ChainOverview::keyLabel($firstKey)]) : __('A win pays'), $firstKey ? $sats($chain['rewards_now'][$firstKey]) : '0', __('sats per winning player, era :era', ['era' => $chain['era'] ?? 1])],
            ] : [
                [__('Era'), '0', __('eras of :days days', ['days' => intdiv($chain['parameters']->halvingSeconds, 86400)])],
                [__('Supply'), $sats($chain['supply']), __('fixed at Block 0')],
                [__('Mined'), '0', __('nothing before Block 0')],
                [__('Blocks'), '0', __('Block 1 follows Block 0')],
                [$firstKey ? __(':game win pays', ['game' => ChainOverview::keyLabel($firstKey)]) : __('A win pays'), $firstKey ? $sats($chain['rewards_now'][$firstKey]) : '0', __('sats per winning player, era :era', ['era' => 1])],
            ];
        @endphp
        @foreach ($stats as [$label, $value, $sub])
            <div @class(['flex min-w-0 flex-col gap-1 rounded-lg bg-card px-4 py-4', 'col-span-2 lg:col-span-1' => $loop->last])>
                <span class="text-xs text-ink-2">{{ $label }}</span>
                <b class="font-display text-[22px] leading-tight lg:text-[26px]">{{ $value }}</b>
                <span class="text-xs text-ink-3">{{ $sub }}</span>
            </div>
        @endforeach
    </section>

    @if ($live)
        <section aria-labelledby="supply-h" class="flex flex-col gap-3 rounded-lg bg-card px-4 py-5 lg:px-6">
            <span class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                <h2 id="supply-h" class="m-0 text-[15px] font-bold">{{ __('Supply: mined and left') }}</h2>
                <span class="text-xs text-ink-3">{{ __('Block 0 :from to the season end :to', ['from' => $date($chain['season']->genesis_at, 'D j M'), 'to' => $date($chain['season']->ends_at, 'D j M')]) }}</span>
            </span>
            @php($minedShare = $chain['mined'] / max(1, $chain['supply']) * 100)
            <div class="relative h-4 overflow-hidden rounded-sm bg-well" role="img" aria-label="{{ __(':mined of :supply sats mined', ['mined' => $sats($chain['mined']), 'supply' => $sats($chain['supply'])]) }}">
                <span class="absolute inset-y-0 left-0 bg-btc" style="width: {{ number_format($minedShare, 3, '.', '') }}%"></span>
                @foreach ([50, 75, 87.5, 93.75] as $mark)
                    <span class="absolute inset-y-0 w-px bg-ground" style="left: {{ $mark }}%" aria-hidden="true"></span>
                @endforeach
            </div>
            <p class="m-0 text-xs text-ink-2">{{ __('At the rate of the last 4 weeks about :sats sats get mined by the season end (:percent %).', ['sats' => $sats($chain['estimate']['end_mined']), 'percent' => $chain['estimate']['end_mined_percent']]) }}</p>
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
                            <th scope="col" class="py-2 pr-3 text-right font-normal">{{ ChainOverview::keyLabel($key) }}</th>
                        @endforeach
                        @foreach ($games as $game)
                            <th scope="col" class="py-2 pr-3 text-right font-normal">{{ __(':game mined', ['game' => ChainOverview::gameLabel($game)]) }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($chain['schedule'] as $row)
                        <tr @class(['border-b border-hairline last:border-0', 'text-btc-hi' => $row['current']])>
                            <td class="py-2 pr-3 font-bold">{{ $row['era'] }}</td>
                            <td class="py-2 pr-3 whitespace-nowrap">{{ $date($row['from'], 'D j M') }}</td>
                            @foreach ($row['rewards'] as $reward)
                                <td class="py-2 pr-3 text-right">{{ $sats($reward) }}</td>
                            @endforeach
                            @foreach ($games as $game)
                                @php($minedHere = (int) ($live ? ($chain['mined_by_game_and_era'][$game][$row['era']] ?? 0) : 0))
                                <td class="py-2 pr-3 text-right whitespace-nowrap">{{ $sats($minedHere) }} <span class="text-ink-3">/ {{ $sats($row['caps'][$game] ?? 0) }}</span></td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="m-0 text-xs text-ink-3">{{ __('Inside an era every valid win of a game pays the same, fixed when its block is saved; halvings never reverse. Mined / cap: no game can take more than its share of an era.') }}</p>
    </section>

    @if ($live)
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-12 lg:gap-6">
            <section aria-labelledby="latest-h" class="flex flex-col gap-3 rounded-lg bg-card px-4 py-5 lg:col-span-8 lg:px-6" data-test="mining-latest">
                <h2 id="latest-h" class="m-0 text-[15px] font-bold">{{ __('Latest blocks') }} <span class="font-normal text-ink-3">{{ __('mined, pending the season review') }}</span></h2>
                @forelse ($chain['latest'] as $block)
                    <div class="grid grid-cols-[56px_minmax(0,1fr)_auto] items-center gap-3 border-b border-hairline py-2.5 text-[13px] last:border-0">
                        <b class="font-display text-base">{{ $block['height'] }}</b>
                        <span class="flex min-w-0 flex-col gap-0.5">
                            <span class="truncate">{{ $block['label'] }} · {{ ChainOverview::keyLabel($block['key']) }}</span>
                            <span class="truncate text-xs text-ink-2">{{ implode(', ', $block['winners']) }}</span>
                        </span>
                        <span class="flex flex-col items-end gap-0.5"><b>{{ $sats($block['reward']) }}</b><span class="text-xs whitespace-nowrap text-ink-3">{{ $date($block['at'], 'D H:i') }}</span></span>
                    </div>
                @empty
                    <p class="m-0 py-4 text-[13px] text-ink-2">{{ __('No block yet. The first fair rated win mines block 1.') }}</p>
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

    <section aria-labelledby="rules-h" class="flex flex-col gap-3 rounded-lg bg-card px-4 py-5 lg:px-6" data-test="mining-rules">
        <h2 id="rules-h" class="m-0 text-[15px] font-bold">{{ $live ? __('Rules in force now') : __('Rules of the Pre-Season draft') }}</h2>
        <dl class="m-0 grid grid-cols-1 gap-x-6 text-[13px] md:grid-cols-2">
            @foreach ([
                [__('Game weights, per winning player'), collect($inForce->weights)->map(fn ($milli, $key) => ChainOverview::keyLabel((string) $key).' '.SeasonRelease::factor((int) $milli).'×')->implode(', ')],
                [__('Share cap per era'), collect($inForce->shares)->map(fn ($share, $game) => ChainOverview::gameLabel((string) $game).' '.$share.' %')->implode(', ')],
                [__('Blocks per player a day'), collect($inForce->daily)->map(fn ($daily, $game) => ChainOverview::gameLabel((string) $game).' '.$daily)->implode(', ')],
                [__('Blocks per pairing'), __(':day a day, :season in the season', ['day' => $inForce->pairLimitPerDay, 'season' => $inForce->pairLimitPerSeason])],
                [__('Same trust circle'), $inForce->subtree > 100 ? __('rule off') : __('no block when both get :percent % or more of their trust from one person', ['percent' => $inForce->subtree])],
                [__('Real chess game'), __(':moves moves or more', ['moves' => $inForce->moves])],
            ] as [$term, $value])
                <div class="flex flex-col gap-0.5 border-b border-hairline py-2.5"><dt class="text-xs text-ink-2">{{ $term }}</dt><dd class="m-0">{{ $value }}</dd></div>
            @endforeach
        </dl>

        @if ($live)
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
            <p class="m-0 text-xs text-ink-3">{{ __('Any board member can adjust a limit during the season. A change counts only for blocks saved after it takes effect, never back.') }}</p>
        @endif
    </section>
</div>
