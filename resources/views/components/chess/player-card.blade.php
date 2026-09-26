@props(['player', 'color', 'you' => false])

{{--
    One side's player in a chess game (ChessGame / MobileChessGame "opponent
    card", P10a): picture with the colour swatch, the name that opens the
    player card, clan tag, membership, copy npub, rating line; on desktop also
    the NIP-05 address, the bio and the banner faintly behind. The slot is the rating line. The caller's
    container is `relative`; the board layout around it is unchanged.
    `player` is the page's player array (games/⚡show::player()).
--}}
@php
    $user = $player['user'];
    $profile = App\Support\Nostr\PlayerProfile::for($user);
@endphp
@if ($profile->hasProfile && $profile->banner)
    <img src="{{ $profile->banner }}" width="380" height="96" alt="" loading="lazy" decoding="async" referrerpolicy="no-referrer"
         class="pointer-events-none absolute inset-0 hidden size-full object-cover opacity-30 lg:block" onerror="this.remove()">
    <span aria-hidden="true" class="pointer-events-none absolute inset-0 hidden bg-linear-to-r from-card from-35% to-[rgba(18,18,21,.55)] lg:block"></span>
@endif
<span class="relative block shrink-0">
    <x-avatar :user="$user" :size="48" class="size-10! rounded-lg lg:size-12!" />
    <span aria-hidden="true" class="absolute -right-1 -bottom-1 size-4 rounded-sm shadow-[0_0_0_2px_#121215,inset_0_0_0_1px_#63636A]" style="background: {{ $color === 'w' ? '#FFFFFF' : '#0A0A0B' }}"></span>
</span>
<span class="relative flex min-w-0 grow flex-col gap-0.5 lg:gap-1">
    <span class="flex min-w-0 flex-wrap items-center gap-x-1.5 gap-y-1 text-sm font-bold lg:text-[15px]">
        <x-player-link :user="$user" class="relative inline-flex min-h-6 min-w-0 items-center after:absolute after:inset-x-0 after:-inset-y-2.5" data-test="player-name"><span class="truncate">{{ $player['name'] }}</span></x-player-link>
        <x-clan-tag :clan="$user->clanMember?->clan" size="sm" />
        @if ($player['member'])<x-member-badge solid class="max-lg:hidden" /><x-member-badge class="lg:hidden" />@endif
        @if ($you)<span class="text-[11px] font-normal text-ink-3">{{ __('you') }}</span>@else<x-copy-npub :npub="$player['npub']" :name="$player['name']" />@endif
    </span>
    <span class="flex flex-wrap items-center gap-x-1 gap-y-0.5 text-xs whitespace-nowrap text-ink-2">{{ $slot }}</span>
    <x-nip05 :profile="$profile" class="max-lg:hidden" />
    @if ($profile->hasProfile && $profile->about)
        <span class="hidden text-xs leading-normal text-ink-3 lg:line-clamp-2" data-test="player-about">{{ $profile->about }}</span>
    @endif
</span>
