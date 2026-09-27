{{--
    The prize pot of a tournament in a list or teaser (P9): the pot in sats,
    the goal when there is one (the organizer's target, or the sum of fixed
    prizes), and "funded" once it is reached. Nothing when the tournament has
    no open pot, or when its wallet was never read (never a guessed number).
--}}
@props(['tournament'])
@php
    $chipPool = app(\App\Support\Prizes\PrizePool::class);
    $chipSats = $tournament->pool_opened_at === null ? null : $chipPool->potSats($tournament);
    $chipFunding = $chipSats === null ? null : $chipPool->funding($tournament);
    $chipTarget = $chipFunding['goal'] ?? null;
    $chipFunded = $chipFunding !== null && $chipFunding['funded'];
    $chipFormat = fn (int $value): string => \App\Support\Cards\ShareCard::sats($value);
@endphp
@if ($chipSats !== null)
    <span {{ $attributes->class(['inline-flex h-6 max-w-full items-center gap-1 self-start rounded-xs px-2 text-xs font-bold whitespace-nowrap', 'bg-win-tint text-win' => $chipFunded, 'bg-btc-chip text-btc-hi' => ! $chipFunded]) }} data-test="prize-chip">
        <x-icon name="bolt" :size="12" />
        @if ($chipFunded)
            {{ __(':sats sats pot, funded', ['sats' => $chipFormat($chipSats)]) }}
        @elseif ($chipTarget !== null)
            {{ __(':sats of :target sats pot', ['sats' => $chipFormat($chipSats), 'target' => $chipFormat($chipTarget)]) }}
        @else
            {{ __(':sats sats pot', ['sats' => $chipFormat($chipSats)]) }}
        @endif
    </span>
@endif
