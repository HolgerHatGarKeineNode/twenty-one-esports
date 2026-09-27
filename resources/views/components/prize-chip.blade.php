{{--
    The prize pot of a tournament in a list or teaser (P9 scope addition):
    the pot in sats, the target when one is set, and "funded" once it is
    reached. Nothing when the tournament has no open pot, or when an own
    wallet was never read (never a guessed number).
--}}
@props(['tournament'])
@php
    $chipSats = $tournament->pool_opened_at === null ? null : app(\App\Support\Prizes\PrizePool::class)->potSats($tournament);
    $chipTarget = $tournament->prize_target_sats;
    $chipFunded = $chipSats !== null && $chipTarget !== null && $chipSats >= $chipTarget;
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
