{{--
    Player page. The header follows PlayerHeader.dc.html (1440) and
    MobilePlayerHeader.dc.html (390): banner, picture, name, NIP-05, bio,
    clan, website, Lightning address, npub, and the rating chips (P7b: chess
    blitz and each Rocket League lineup, casual before Block 0) and, for a
    signed-in visitor, "Add as opponent" (P7e). Trust and the
    stats below the header come with their phases; until then the rest of the
    page is the "coming soon" state.
--}}
@php
    $user = $profile->user;
    $name = $profile->name;
    $isMe = auth()->id() === $user->id;
    $chips = \App\Support\Rating\Ratings::chipsFor($user);

    $plays = $profile->games();
    $description = ($plays !== null
        ? __(':name plays :games in the TWENTY ONE esports league.', ['name' => $name, 'games' => $plays])
        : __(':name is a player in the TWENTY ONE esports league, the chess and Rocket League ladder of the Bitcoin community EINUNDZWANZIG.', ['name' => $name]))
        .($profile->clan !== null ? ' '.__('Clan: :clan.', ['clan' => $profile->clan->name]) : '')
        .(filled($profile->about) ? ' '.\Illuminate\Support\Str::limit(\Illuminate\Support\Str::squish($profile->about), 100, '…') : '');
    app(\App\Support\PageMeta::class)
        ->describe($name, $description)
        ->addStructuredData(\App\Support\Seo\StructuredData::profilePage($profile, \App\Support\Seo\LocalizedUrls::for(app()->getLocale())));
@endphp
<x-layouts::app :title="$name">
    <div class="flex flex-col gap-6 pb-6 lg:px-12 lg:pb-8">
        <section aria-labelledby="ph-name" class="relative flex flex-col" data-test="player-header">
            {{-- Banner --}}
            @if ($profile->banner)
                <img src="{{ $profile->banner }}" width="1344" height="200" alt="{{ __(':name banner', ['name' => $name]) }}" loading="lazy" decoding="async" referrerpolicy="no-referrer"
                     data-test="header-banner" class="block h-[132px] w-full bg-row-hover object-cover lg:h-[200px] lg:rounded-lg"
                     onerror="this.onerror=null;this.nextElementSibling.hidden=false;this.remove()">
                <div aria-hidden="true" hidden class="h-[132px] lg:h-[200px] lg:rounded-lg" style="{{ $profile->bannerPlaceholderStyle() }}"></div>
            @else
                <div aria-hidden="true" class="h-[132px] lg:h-[200px] lg:rounded-lg" style="{{ $profile->bannerPlaceholderStyle() }}"></div>
            @endif

            {{-- Picture, name, address, challenge --}}
            <div class="-mt-11 flex flex-col gap-3 px-4 lg:-mt-16 lg:grid lg:grid-cols-[136px_minmax(0,1fr)_auto] lg:items-end lg:gap-x-6 lg:gap-y-0 lg:pr-0 lg:pl-8">
                <div class="flex items-end gap-3">
                    <x-avatar :user="$user" :size="128" class="size-[88px]! rounded-xl shadow-[0_0_0_4px_#0A0A0B] lg:size-32! lg:rounded-[14px]" />
                    @if ($profile->isMember())
                        <span class="pb-1.5 lg:hidden"><x-member-badge solid /></span>
                    @endif
                </div>
                <div class="flex min-w-0 flex-col gap-1.5 lg:pb-1">
                    <span class="flex flex-wrap items-center gap-3">
                        <h1 id="ph-name" class="m-0 font-display text-[26px] leading-tight font-bold [overflow-wrap:anywhere] lg:text-4xl">{{ $name }}</h1>
                        @if ($profile->isMember())
                            <x-member-badge solid class="max-lg:hidden" />
                        @endif
                    </span>
                    @if ($profile->hasProfile)
                        <x-nip05 :profile="$profile" size="md" />
                    @else
                        <span class="flex items-center gap-1.5 text-[13px] text-ink-2"><x-icon name="user" :size="14" />{{ __('No Nostr profile yet') }}</span>
                    @endif
                </div>
                @unless ($isMe)
                    <div class="flex min-w-0 items-center gap-3 max-lg:order-last lg:max-w-[360px] lg:pb-1">
                        <a href="{{ route('chess.challenge', ['to' => $user->npub]) }}" data-test="challenge"
                           class="flex h-11 min-w-0 grow items-center justify-center gap-2 rounded-md bg-btc px-5 text-sm font-bold whitespace-nowrap text-on-btc hover:bg-btc-hi hover:text-on-btc lg:grow-0">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" class="shrink-0"><path d="M8 5v14l11-7z"></path></svg><span class="truncate">{{ __('Challenge :name', ['name' => $name]) }}</span>
                        </a>
                    </div>
                @endunless
            </div>

            {{-- Bio and chips | links --}}
            <div class="flex flex-col gap-4 px-4 pt-3 lg:grid lg:grid-cols-[minmax(0,1fr)_460px] lg:items-start lg:gap-12 lg:pt-5 lg:pr-0 lg:pl-8">
                <div class="flex flex-col gap-4">
                    @if ($profile->hasProfile && $profile->about)
                        <p class="m-0 max-w-[64ch] text-sm leading-relaxed whitespace-pre-line [overflow-wrap:anywhere]" data-test="header-about">{{ $profile->about }}</p>
                    @elseif (! $profile->hasProfile)
                        <p class="m-0 max-w-[64ch] text-sm leading-relaxed text-ink-2">{{ __('A picture, banner and bio appear here once :name has a Nostr profile. Until then the avatar is drawn from the player key, so it stays the same everywhere.', ['name' => $name]) }}</p>
                    @endif
                    <div class="flex flex-wrap gap-2">
                        @foreach ($chips as $chip)
                            <span class="inline-flex h-8 max-w-full min-w-0 items-center gap-2 rounded-md bg-card px-3 text-xs whitespace-nowrap shadow-ring" data-test="header-rating">
                                <span class="text-ink-3">{{ $chip['label'] }}</span><x-rating :rating="$chip['rating']" class="text-ink-2" />
                            </span>
                        @endforeach
                        @if ($profile->clan)
                            <a href="{{ route('clans.show', $profile->clan) }}" data-test="header-clan"
                               class="relative inline-flex h-8 max-w-full min-w-0 items-center gap-2 rounded-md bg-card px-3 text-xs whitespace-nowrap text-ink shadow-ring after:absolute after:inset-x-0 after:-inset-y-1.5 hover:text-ink">
                                <x-clan-tag :clan="$profile->clan" size="sm" /><span class="truncate">{{ $profile->clan->name }}</span><span class="shrink-0 text-ink-3">{{ mb_strtolower((string) $profile->clanRole) }}</span>
                            </a>
                        @endif
                    </div>
                </div>
                <div class="flex flex-col border-t border-hairline lg:border-t-0 lg:border-l lg:pl-6">
                    {{-- Add as opponent (P7e): signed-in players on someone else's page --}}
                    <livewire:opponent-button :player="$user" />
                    @if ($profile->hasProfile && $profile->website)
                        <div class="flex min-h-11 min-w-0 items-center border-b border-hairline">
                            <a href="{{ $profile->website }}" rel="nofollow noopener noreferrer" target="_blank" data-test="header-website"
                               class="inline-flex min-h-11 min-w-0 items-center gap-1.5 text-[13px]"><x-icon name="link" :size="14" /><span class="truncate">{{ $profile->websiteLabel }}</span></a>
                        </div>
                    @endif
                    @if ($profile->hasProfile && $profile->lud16)
                        <div class="relative flex min-h-12 min-w-0 items-center gap-2 border-b border-hairline text-[13px] lg:min-h-11" data-test="header-lud16">
                            <span class="flex text-bolt" title="{{ __('Lightning address') }}"><x-icon name="bolt-toast" :size="14" /></span>
                            <span class="sr-only">{{ __('Lightning address') }}</span>
                            <span class="min-w-0 truncate">{{ $profile->lud16 }}</span>
                            <x-zap-soon />
                        </div>
                    @endif
                    <div class="flex min-h-14 items-center gap-3 lg:min-h-[52px]">
                        <span class="text-xs text-ink-3">
                            {{ __('joined :date', ['date' => $user->created_at?->translatedFormat('M j, Y')]) }}@if ($profile->games()){{ __(', plays :games', ['games' => $profile->games()]) }}@endif
                        </span>
                        <span class="grow"></span>
                        <x-copy-npub :npub="$user->npub" :name="$name" variant="button" :label="$user->shortNpub()" />
                    </div>
                </div>
            </div>
        </section>

        <div class="mx-4 rounded-lg shadow-ring-hairline lg:mx-0">
            <x-empty-state class="px-5 py-8 lg:px-10 lg:py-10"
                           :heading="__('Coming soon')"
                           :text="__('Ratings, results and the season record of this player are still being built.')" />
        </div>
    </div>
</x-layouts::app>
