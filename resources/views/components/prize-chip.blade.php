{{--
    The prize pot of a tournament in a list or teaser (P9): the pot as the
    tournament sets it (the fixed prizes' sum or the target, user 2026-09-28),
    and "funded" once the wallet holds it. Nothing when the tournament has
    no open pot, or when its wallet was never read (never a guessed number).
--}}
@props(['tournament'])
@php
    $chipPool = app(\App\Support\Prizes\PrizePool::class);
    $chipSats = $tournament->pool_opened_at === null ? null : $chipPool->potSats($tournament);
    $chipFunding = $chipSats === null ? null : $chipPool->funding($tournament);
    $chipFunded = $chipFunding !== null && $chipFunding['funded'];
    $chipFormat = fn (int $value): string => \App\Support\Cards\ShareCard::sats($value);
@endphp
@if ($chipSats !== null)
    <span {{ $attributes->class(['inline-flex h-6 max-w-full items-center gap-1 self-start rounded-xs px-2 text-xs font-bold whitespace-nowrap', 'bg-win-tint text-win' => $chipFunded, 'bg-btc-chip text-btc-hi' => ! $chipFunded]) }} data-test="prize-chip">
        <x-icon name="bolt" :size="12" />
        @if ($chipFunded)
            {{ __(':sats sats pot, funded', ['sats' => $chipFormat($chipSats)]) }}
        @else
            {{ __(':sats sats pot', ['sats' => $chipFormat($chipSats)]) }}
        @endif
    </span>
@endif
