@props(['profile'])

{{--
    The player card (ProfileHovercard.dc.html, MobileProfileHovercard.dc.html):
    Nostr profile on top, league facts below, copy npub and the player page at
    the foot. Every empty field is left out, never a dash.

    Without a cached profile the top shows one of three states, picked in the
    browser by the profile loader (resources/js/profiles.js, Alpine store
    `profiles`): loading, didn't load (with "Try again"), or no Nostr profile.
    The server renders "no Nostr profile"; the other two are x-cloak'ed.
--}}
@php
    $user = $profile->user;
    $name = $profile->name;
    $pubkey = $user->pubkey;
    $chips = \App\Support\Rating\Ratings::chipsFor($user);
@endphp
<div data-test="profile-card" data-pubkey="{{ $pubkey }}" data-name="{{ $name }}" class="flex flex-col"
     x-data="{ get status() { return this.$store.profiles?.statusOf(@js($pubkey)) ?? 'idle' } }">

    @if ($profile->hasProfile)
        {{-- Banner: the profile's own, or a short strip without one --}}
        @if ($profile->banner)
            <img src="{{ $profile->banner }}" width="360" height="88" alt="{{ __(':name banner', ['name' => $name]) }}" loading="lazy" decoding="async" referrerpolicy="no-referrer"
                 data-test="card-banner" class="block h-[88px] w-full bg-row-hover object-cover group-data-[sheet]/sheet:h-[104px]"
                 onerror="this.onerror=null;this.nextElementSibling.hidden=false;this.remove()">
            <div aria-hidden="true" hidden class="h-[88px] group-data-[sheet]/sheet:h-[104px]" style="{{ $profile->bannerPlaceholderStyle() }}"></div>
        @else
            <div aria-hidden="true" class="h-11 bg-linear-to-r from-row-hover to-well group-data-[sheet]/sheet:h-16"></div>
        @endif

        <div class="relative flex flex-col gap-2 px-4 pb-3 group-data-[sheet]/sheet:px-5">
            <div class="-mt-8 flex items-end gap-3">
                <x-avatar :user="$user" :size="64" class="rounded-[10px] shadow-[0_0_0_3px_#121215] group-data-[sheet]/sheet:size-[72px]!" />
                @if ($profile->isMember())
                    <span class="flex min-w-0 flex-wrap items-center gap-2 pb-1"><x-member-badge solid /></span>
                @endif
            </div>
            <h3 class="m-0 font-display text-lg leading-tight font-bold [overflow-wrap:anywhere]" data-test="card-name">{{ $name }}</h3>
            <x-nip05 :profile="$profile" />
            @if ($profile->about)
                <p class="m-0 line-clamp-3 text-[13px] leading-normal whitespace-pre-line text-ink-2" data-test="card-about">{{ $profile->about }}</p>
            @endif
        </div>
    @else
        {{-- Loading (the loader is asking the relays right now) --}}
        <div x-show="status === 'loading'" x-cloak>
            <div class="sk h-[88px] rounded-none! group-data-[sheet]/sheet:h-[104px]" aria-hidden="true"></div>
            <div class="relative flex flex-col gap-2 px-4 pb-3 group-data-[sheet]/sheet:px-5">
                <span class="sk -mt-8 block size-16 rounded-[10px]! shadow-[0_0_0_3px_#121215]" aria-hidden="true"></span>
                <h3 class="m-0 font-display text-lg leading-tight font-bold [overflow-wrap:anywhere]">{{ $name }}</h3>
                <span class="sk block h-3 w-[150px]" aria-hidden="true"></span>
                <span role="status" class="sr-only">{{ __('Loading :name\'s profile', ['name' => $name]) }}</span>
                <span aria-hidden="true" class="flex flex-col gap-2"><span class="sk block h-3"></span><span class="sk block h-3 w-[70%]"></span></span>
            </div>
        </div>

        {{-- Didn't load (no relay answered) --}}
        <div x-show="status === 'failed'" x-cloak data-test="card-failed">
            <div aria-hidden="true" class="h-[88px] group-data-[sheet]/sheet:h-[104px]" style="{{ $profile->bannerPlaceholderStyle() }}"></div>
            <div class="relative flex flex-col gap-2 px-4 pb-3 group-data-[sheet]/sheet:px-5">
                <img src="{{ $profile->generatedAvatar }}" width="64" height="64" alt="{{ __(':name avatar, generated while the profile is unavailable', ['name' => $name]) }}"
                     class="-mt-8 block size-16 rounded-[10px] bg-raised shadow-[0_0_0_3px_#121215]">
                <h3 class="m-0 font-display text-lg leading-tight font-bold [overflow-wrap:anywhere]">{{ $name }}</h3>
                <span class="flex items-center gap-1.5 text-xs text-btc-hi"><x-icon name="alert" :size="14" />{{ __('Profile didn\'t load') }}</span>
                <div class="flex items-center gap-3">
                    <p class="m-0 grow text-xs leading-normal text-ink-2">{{ __('The picture and bio couldn\'t be fetched. League data below is current.') }}</p>
                    <button type="button" x-on:click="$store.profiles.retry(@js($pubkey))"
                            class="inline-flex h-11 shrink-0 cursor-pointer items-center gap-1.5 rounded-md border border-line bg-well px-3 text-xs text-ink hover:bg-[#222228]"><x-icon name="retry" :size="14" />{{ __('Try again') }}</button>
                </div>
            </div>
        </div>

        {{-- No Nostr profile (the relays have none, or it was never asked for) --}}
        <div x-show="status !== 'loading' && status !== 'failed'" data-test="card-no-profile">
            <div aria-hidden="true" class="h-[88px] group-data-[sheet]/sheet:h-[104px]" style="{{ $profile->bannerPlaceholderStyle() }}"></div>
            <div class="relative flex flex-col gap-2 px-4 pb-3 group-data-[sheet]/sheet:px-5">
                <div class="-mt-8 flex items-end gap-3">
                    <x-avatar :user="$user" :size="64" class="rounded-[10px] shadow-[0_0_0_3px_#121215] group-data-[sheet]/sheet:size-[72px]!" />
                    @if ($profile->isMember())
                        <span class="flex min-w-0 flex-wrap items-center gap-2 pb-1"><x-member-badge solid /></span>
                    @endif
                </div>
                <h3 class="m-0 font-display text-lg leading-tight font-bold [overflow-wrap:anywhere]">{{ $name }}</h3>
                <span class="flex items-center gap-1.5 text-xs text-ink-2"><x-icon name="user" :size="14" />{{ __('No Nostr profile yet') }}</span>
                <p class="m-0 text-[13px] leading-normal text-ink-3">{{ __('A picture and bio show up here once :name sets up a Nostr profile.', ['name' => $name]) }}</p>
            </div>
        </div>
    @endif

    {{-- League facts; the rating line is always there (P7b) --}}
    <div class="flex flex-col border-t border-hairline px-4 py-2 text-xs">
        <div class="grid min-h-7 grid-cols-[104px_minmax(0,1fr)] items-center gap-2" data-test="card-ratings">
            <span class="text-ink-3">{{ __('Rating') }}</span>
            <span class="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1">
                @foreach ($chips as $chip)
                    <span class="inline-flex items-center gap-1 whitespace-nowrap"><span class="text-ink-3">{{ $chip['label'] }}</span><x-rating :rating="$chip['rating']" class="text-ink-2" /></span>
                @endforeach
            </span>
        </div>
        @if ($profile->chessGames > 0)
            <div class="grid min-h-7 grid-cols-[104px_minmax(0,1fr)] items-center gap-2" data-test="card-plays">
                <span class="text-ink-3">{{ __('Plays') }}</span>
                <span>{{ trans_choice('casual chess, :count game so far|casual chess, :count games so far', $profile->chessGames, ['count' => $profile->chessGames]) }}</span>
            </div>
        @endif
        @if ($profile->clan)
            <div class="grid min-h-7 grid-cols-[104px_minmax(0,1fr)] items-center gap-2" data-test="card-clan">
                <span class="text-ink-3">{{ __('Clan') }}</span>
                <span class="flex min-w-0 items-center gap-2">
                    <x-clan-tag :tag="$profile->clan->clantag" size="sm" />
                    <span class="truncate">{{ $profile->clan->name }}</span>
                    <span class="shrink-0 text-ink-3">{{ mb_strtolower((string) $profile->clanRole) }}</span>
                </span>
            </div>
        @endif
    </div>

    @if ($profile->hasProfile && $profile->lud16)
        <div class="relative flex min-w-0 items-center gap-2 border-t border-hairline px-4 py-2.5 text-xs" data-test="card-lud16">
            <span class="flex text-bolt" title="{{ __('Lightning address') }}"><x-icon name="bolt-toast" :size="14" /></span>
            <span class="sr-only">{{ __('Lightning address') }}</span>
            <span class="min-w-0 grow truncate">{{ $profile->lud16 }}</span>
            <x-zap-soon />
        </div>
    @endif

    <div class="flex gap-2 border-t border-hairline px-4 pt-3 pb-4">
        <x-copy-npub :npub="$user->npub" :name="$name" variant="button" />
        <a href="{{ route('players.show', $user->npub) }}" data-test="card-player-page"
           class="flex h-11 grow items-center justify-center rounded-md border border-line bg-well text-[13px] font-bold text-ink hover:bg-[#222228] hover:text-ink">{{ __('Open player page') }}</a>
    </div>
</div>
