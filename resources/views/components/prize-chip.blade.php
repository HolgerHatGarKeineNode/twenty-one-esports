{{--
    The prize pot of a tournament in a list or teaser (P9): what is still to
    be won of the pot as the tournament sets it (the fixed prizes' sum or the
    target), less only what was paid out; never the wallet balance (user,
    2026-09-28). Nothing when the tournament has no open pot.
--}}
@props(['tournament'])
@php
    $chipPool = app(\App\Support\Prizes\PrizePool::class);
    $chipSats = $tournament->pool_opened_at === null ? null : $chipPool->potSats($tournament);
    $chipLeft = $chipSats === null ? null : $chipPool->remainingSats($tournament);
    $chipFormat = fn (int $value): string => \App\Support\Cards\ShareCard::sats($value);
@endphp
@if ($chipSats !== null)
    <span {{ $attributes->class('inline-flex h-6 max-w-full items-center gap-1 self-start rounded-xs bg-btc-chip px-2 text-xs font-bold whitespace-nowrap text-btc-hi') }} data-test="prize-chip">
        <x-icon name="bolt" :size="12" />
        {{ __(':sats of :target sats pot', ['sats' => $chipFormat((int) $chipLeft), 'target' => $chipFormat($chipSats)]) }}
    </span>
@endif
