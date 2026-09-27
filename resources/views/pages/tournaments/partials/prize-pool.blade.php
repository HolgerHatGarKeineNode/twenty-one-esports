{{--
    The prize pool of a tournament (P9): the pot in sats, the split per
    place and the sponsors. Rendered only when the league has a pool for the
    tournament (App\Support\Tournaments\TournamentPrizePool), so every number
    here is the league's own.

    $pool: the shape of TournamentPrizePool::for() — sats, split, sponsors,
    and the optional target/funded, as_of/stale (a pot in the tournament's
    own wallet: when its balance was read). A Lightning address is never shown
    as text here (user, 2026-09-27: at most an LNURL QR code).
--}}
@php
    $sats = fn (int $amount): string => \App\Support\Cards\ShareCard::sats($amount);
    $placeLabel = fn (int $place): string => match ($place) { 1 => __('1st place'), 2 => __('2nd place'), 3 => __('3rd place'), default => __(':place. place', ['place' => $place]) };
    $poolTarget = $pool['target'] ?? null;
    $poolAsOf = $pool['as_of'] ?? null;
    $poolZone = \App\Support\LeagueTime::zone();
@endphp
<section aria-labelledby="pool-h" class="flex flex-col gap-5 px-4 lg:px-12" data-test="prize-pool">
    <h2 id="pool-h" class="m-0 font-display text-xl font-bold lg:text-2xl">{{ __('Prize pool') }}</h2>

    <div class="grid gap-3 rounded-card bg-btc-chip p-4 shadow-ring-btc sm:p-6 lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)] lg:items-center lg:gap-10">
        <div class="flex min-w-0 flex-col gap-2">
            <p class="m-0 flex flex-col gap-1">
                <span class="font-display text-[40px] leading-none font-bold text-btc tabular-nums sm:text-[56px]" data-test="pool-sats">{{ $sats($pool['sats']) }}</span>
                <span class="text-[13px] text-ink-2">
                    @if ($poolTarget !== null)
                        {{ __('sats in the pot, target :target sats', ['target' => $sats($poolTarget)]) }}
                    @else
                        {{ __('sats in the pot') }}
                    @endif
                </span>
            </p>
            @if ($poolTarget !== null && $poolTarget > 0)
                <span class="h-2 w-full overflow-hidden rounded-full bg-raised" aria-hidden="true"><span class="block h-full bg-btc" style="width: {{ min(100, (int) floor(100 * $pool['sats'] / $poolTarget)) }}%"></span></span>
                @if ($pool['funded'] ?? false)
                    <span class="inline-flex h-6 items-center gap-1 self-start rounded-xs bg-win-tint px-2 text-xs font-bold text-win" data-test="pool-funded"><x-icon name="check" :size="12" />{{ __('Funded') }}</span>
                @else
                    <span class="text-xs text-ink-2" data-test="pool-progress">{{ __(':percent % of the target', ['percent' => min(100, (int) floor(100 * $pool['sats'] / $poolTarget))]) }}</span>
                @endif
            @endif
            @if ($poolAsOf !== null)
                <span @class(['text-xs', 'text-ink-3' => ! ($pool['stale'] ?? false), 'text-loss' => $pool['stale'] ?? false]) data-test="pool-as-of">
                    @if ($pool['stale'] ?? false)
                        {{ __('Wallet balance as of :time; the wallet has not answered since.', ['time' => $poolAsOf->copy()->timezone($poolZone)->format('Y-m-d H:i')]) }}
                    @else
                        {{ __('Wallet balance as of :time.', ['time' => $poolAsOf->copy()->timezone($poolZone)->format('H:i')]) }}
                    @endif
                </span>
            @endif
        </div>
        @if ($pool['split'] !== [])
            <ol class="m-0 grid list-none gap-2 p-0 sm:grid-cols-3">
                @foreach ($pool['split'] as $share)
                    <li class="flex flex-col gap-1 rounded-md bg-ground px-3.5 py-3 shadow-ring-hairline" wire:key="pool-{{ $share['place'] }}">
                        <span class="text-xs text-ink-2">{{ $placeLabel($share['place']) }}, {{ $share['percent'] }} %</span>
                        <span class="font-display text-lg font-bold tabular-nums">{{ __(':sats sats', ['sats' => $sats($share['sats'])]) }}</span>
                    </li>
                @endforeach
            </ol>
        @endif
    </div>

    @if ($pool['sponsors'] !== [])
        <div class="flex flex-col gap-2">
            <span class="text-xs text-ink-2">{{ __('Backed by') }}</span>
            <ul class="m-0 flex list-none flex-wrap gap-2 p-0">
                @foreach ($pool['sponsors'] as $sponsor)
                    <li class="flex h-14 items-center gap-2.5 rounded-md bg-card px-3.5 shadow-ring">
                        @if ($sponsor['logo'])
                            <img src="{{ $sponsor['logo'] }}" alt="" width="32" height="32" loading="lazy" class="size-8 rounded-sm object-contain">
                        @endif
                        @if ($sponsor['url'])
                            <a href="{{ $sponsor['url'] }}" target="_blank" rel="noopener noreferrer sponsored" class="text-[13px] font-bold text-ink hover:text-btc-hi">{{ $sponsor['name'] }}</a>
                        @else
                            <span class="text-[13px] font-bold">{{ $sponsor['name'] }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</section>
