{{--
    Player page. The header follows PlayerHeader.dc.html (1440) and
    MobilePlayerHeader.dc.html (390): banner, picture, name, NIP-05, bio,
    clan, website, whether zaps can be received, npub, the honours (tournament
    wins, other podium places, prizes paid; 2026-10-04) and the rating chips (P7b: chess
    blitz and each Rocket League lineup, casual before Block 0) and, for a
    signed-in visitor, "Add as opponent" (P7e). Below it the trophies
    (partials/trophies, App\Support\Players\PlayerTrophies), the rank badges
    (P11) and the public record (P31, partials/stats): ladders with rating,
    rank, form, share of wins and peak, the latest results, tournaments with
    place and paid prize, the season record and the clans. Everything here is
    public: nothing a guest must not see is read for it.
--}}
@php
    $user = $profile->user;
    $name = $profile->name;
    $isMe = auth()->id() === $user->id;
    $chips = \App\Support\Rating\Ratings::chipsFor($user);
    // P10: the frame both sides of an accepted invite link get.
    $inviteFrame = app(\App\Support\Engagement\Cosmetics::class)->owns($user, \App\Support\Engagement\Cosmetics::INVITE_FRAME);

    $plays = $profile->games();
    $description = ($plays !== null
        ? __(':name plays :games in the TWENTY ONE esports league.', ['name' => $name, 'games' => $plays])
        : __(':name plays in TWENTY ONE esports, the esports league of the Bitcoin community EINUNDZWANZIG.', ['name' => $name]))
        .($profile->clan !== null ? ' '.__('Clan: :clan.', ['clan' => $profile->clan->name]) : '')
        .(filled($profile->about) ? ' '.\Illuminate\Support\Str::limit(\Illuminate\Support\Str::squish($profile->about), 100, '…') : '');
    app(\App\Support\PageMeta::class)
        ->describe($name, $description)
        ->card(fn () => \App\Support\Cards\PageCard::player($user))
        ->addStructuredData(\App\Support\Seo\StructuredData::profilePage($profile, \App\Support\Seo\LocalizedUrls::for(app()->getLocale())));

    // The record is read once: the honours under the name and the side column below share it.
    $stats = new \App\Support\Players\PlayerStats($user);
    $tournaments = $stats->tournaments();
    $honours = app(\App\Support\Players\PlayerTrophies::class)->of($user);
@endphp
<x-layouts::app :title="$name" flush>
    <div class="flex flex-col gap-6 pb-6 lg:px-12 lg:pb-8">
        {{--
            The head band (PlayerPublic.dc.html, template T7, P7): picture, name, address, honours and the ways to play on
            the left, on the right the player's Nostr banner (or the drawn one) as the band's picture. The band is the page's
            key art (R1): it starts right under the header (flush) and from lg runs edge to edge, its picture touching the
            right edge (data-bleed), as on the board.
        --}}
        <section aria-labelledby="ph-name" class="relative flex flex-col" data-test="player-header">
            <div class="grid grid-cols-1 overflow-hidden border-b border-hairline bg-[linear-gradient(90deg,#14110C,var(--color-ground)_70%)] lg:-mx-12 lg:min-h-60 lg:grid-cols-[minmax(0,1fr)_minmax(0,30%)]" data-bleed data-test="player-band">
                <div class="order-2 flex flex-col gap-3 px-4 py-6 lg:order-1 lg:grid lg:grid-cols-[96px_minmax(0,1fr)] lg:items-start lg:gap-x-6 lg:gap-y-3 lg:px-12 lg:py-10">
                <div class="flex items-end gap-3 lg:row-span-3">
                    <x-avatar :user="$user" :size="128" :class="\Illuminate\Support\Arr::toCssClasses(['size-[72px]! rounded-full lg:size-24!', 'shadow-[0_0_0_4px_#0A0A0B]' => ! $inviteFrame, 'shadow-[0_0_0_4px_#0A0A0B,0_0_0_7px_var(--color-btc),0_0_24px_rgba(247,147,26,0.35)]' => $inviteFrame])" :data-test="$inviteFrame ? 'invite-frame' : null" />
                    @if ($profile->isMember())
                        <span class="pb-1.5 lg:hidden"><x-member-badge solid /></span>
                    @endif
                </div>
                <div class="flex min-w-0 flex-col gap-1.5">
                    <span class="flex flex-wrap items-center gap-3">
                        <h1 id="ph-name" class="rv-h1 [overflow-wrap:anywhere] max-lg:text-[26px]">{{ $name }}</h1>
                        @if ($isMe && (! filled($user->name) || $user->lud16 === null))
                            {{-- Google sign-ins start without a profile (user 2026-10-25): the way to a name and a prize address. --}}
                            <a href="{{ route('settings.profile') }}" class="inline-flex min-h-11 items-center gap-1.5 text-[13px] font-bold text-btc-hi" data-test="player-profile-missing"><x-icon name="bolt" :size="14" />{{ ! filled($user->name) ? __('Add your name and Lightning address') : __('Add a Lightning address for prizes') }}</a>
                        @endif
                        @if ($profile->isMember())
                            <x-member-badge solid class="max-lg:hidden" />
                        @endif
                        @if ($inviteFrame)
                            <span class="inline-flex h-6 items-center gap-1 rounded-sm border border-btc-ring bg-btc-chip px-2 text-[11px] font-bold whitespace-nowrap text-btc-hi" data-test="invite-frame-chip">{{ __('Brought a friend') }}</span>
                        @endif
                    </span>
                    @if ($profile->hasProfile)
                        <x-nip05 :profile="$profile" size="md" />
                    @else
                        <span class="flex items-center gap-1.5 text-[13px] text-ink-2"><x-icon name="user" :size="14" />{{ __('No Nostr profile yet') }}</span>
                    @endif
                    {{-- The honours (PlayerTrophies, PlayerStats): tournament wins, other podium places, prizes the league paid; nothing when there are none. --}}
                    @if ($honours['wins'] > 0 || $honours['podiums'] > 0 || $tournaments['prizes'] > 0)
                        <span class="mt-1.5 flex flex-wrap items-center gap-2" data-test="player-honours">
                            @if ($honours['wins'] > 0)
                                <span class="inline-flex h-8 items-center gap-2 rounded-md bg-[color-mix(in_oklab,var(--color-rank-gold)_14%,var(--color-card))] px-3 text-[13px] font-bold whitespace-nowrap text-rank-gold shadow-[inset_0_0_0_1px_color-mix(in_oklab,var(--color-rank-gold)_40%,transparent)]" data-test="player-honours-wins">
                                    <x-icon name="trophy" :size="16" />{{ trans_choice(':count tournament win|:count tournament wins', $honours['wins']) }}
                                </span>
                            @endif
                            @if ($honours['podiums'] > 0)
                                <span class="inline-flex h-8 items-center rounded-md bg-card px-3 text-[13px] whitespace-nowrap text-ink-2 shadow-ring" data-test="player-honours-podiums">{{ __(':count× 2nd or 3rd place', ['count' => $honours['podiums']]) }}</span>
                            @endif
                            @if ($tournaments['prizes'] > 0)
                                <span class="inline-flex h-8 items-center gap-1.5 rounded-md bg-btc-chip px-3 text-[13px] font-bold whitespace-nowrap text-btc-hi" data-test="player-honours-prizes">
                                    <x-icon name="bolt" :size="14" />{{ __(':sats sats won', ['sats' => \App\Support\PreSeason::formatSats($tournaments['prizes'])]) }}
                                </span>
                            @endif
                        </span>
                    @endif
                </div>
                @unless ($isMe)
                    <div class="flex min-w-0 flex-wrap items-center gap-3 lg:col-start-2 lg:max-w-[520px]">
                        {{-- Every game this player can be challenged to, picked on the next page (DerCaddy, 2026-11-13: it was daily chess only). --}}
                        <a href="{{ route('challenge', ['to' => $user->npub]) }}" data-test="challenge"
                           class="flex h-11 min-w-0 grow items-center justify-center gap-2 rounded-md bg-btc px-5 text-sm font-bold whitespace-nowrap text-on-btc hover:bg-btc-hi hover:text-on-btc lg:grow-0">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" class="shrink-0"><path d="M8 5v14l11-7z"></path></svg><span class="truncate">{{ __('Challenge :name', ['name' => $name]) }}</span>
                        </a>
                        {{-- A scheduled casual 1v1 in Rocket League or EA FC (P23 S4), with this player filled in; the game they look for first,
                             for logged-in visitors only: "Looking to play" is shown to fellow players, never to guests (public profile). --}}
                        @php($casualGame = auth()->check() ? explode('/', (string) $user->looking_to_play)[0] : '')
                        <a href="{{ route('challenges.casual', array_filter(['to' => $user->id, 'game' => \App\Support\Series\CasualLobby::offers($casualGame) ? $casualGame : null])) }}" data-test="schedule-1v1"
                           class="btn-s flex h-11 shrink-0 items-center justify-center gap-2 rounded-md border border-edge px-4 text-sm whitespace-nowrap text-ink hover:text-ink max-lg:grow">
                            <x-icon name="calendar" :size="16" class="shrink-0" />{{ __('Schedule a 1v1') }}
                        </a>
                    </div>
                @endunless
                </div>
                <div class="order-1 lg:order-2" aria-hidden="false">
                    {{-- The band's picture: the Nostr banner, else the drawn one. --}}
                    @if ($profile->banner)
                        <img src="{{ $profile->banner }}" width="1344" height="200" alt="{{ __(':name banner', ['name' => $name]) }}" loading="lazy" decoding="async" referrerpolicy="no-referrer"
                             data-test="header-banner" class="block h-[132px] w-full bg-row-hover object-cover lg:h-full"
                             onerror="this.onerror=null;this.nextElementSibling.hidden=false;this.remove()">
                        <div aria-hidden="true" hidden class="h-[132px] lg:h-full" style="{{ $profile->bannerPlaceholderStyle() }}"></div>
                    @else
                        <div aria-hidden="true" class="h-[132px] lg:h-full" style="{{ $profile->bannerPlaceholderStyle() }}"></div>
                    @endif
                </div>
            </div>

            {{-- Bio and chips | links --}}
            <div class="flex flex-col gap-4 px-4 pt-3 lg:grid lg:grid-cols-[minmax(0,1fr)_460px] lg:items-start lg:gap-12 lg:pt-5 lg:pr-0 lg:pl-8">
                <div class="flex flex-col gap-4">
                    @if ($profile->hasProfile && $profile->about)
                        <p class="m-0 max-w-[64ch] text-sm leading-relaxed whitespace-pre-line [overflow-wrap:anywhere]" data-test="header-about">{{ $profile->about }}</p>
                    @elseif (! $profile->hasProfile)
                        <p class="m-0 max-w-[64ch] text-sm leading-relaxed text-ink-2 [overflow-wrap:anywhere]">{{ __('A picture, banner and bio appear here once :name has a Nostr profile. Until then the avatar is drawn from the player key, so it stays the same everywhere.', ['name' => $name]) }}</p>
                    @endif
                    <div class="flex flex-wrap gap-2">
                        @foreach ($chips as $chip)
                            <span class="inline-flex h-8 max-w-full min-w-0 items-center gap-2 rounded-md bg-card px-3 text-xs whitespace-nowrap shadow-ring" data-test="header-rating" @isset($chip['mode']) data-chess-mode="{{ $chip['mode'] }}" @endisset>
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
                    {{-- A Lightning address is never shown as text, only that one exists --}}
                    @if ($profile->hasProfile && $profile->lud16)
                        <div class="relative flex min-h-12 min-w-0 items-center gap-2 border-b border-hairline text-[13px] text-ink-2 lg:min-h-11" data-test="header-lud16">
                            <span class="flex text-bolt"><x-icon name="bolt-toast" :size="14" /></span>
                            <span class="min-w-0 truncate">{{ __('Can receive zaps') }}</span>
                        </div>
                    @endif
                    <div class="flex min-h-14 items-center gap-3 lg:min-h-[52px]">
                        <span class="text-xs text-ink-3">
                            {{ __('joined :date', ['date' => \App\Support\Formats::day($user->created_at)]) }}@if ($profile->games()){{ __(', plays :games', ['games' => $profile->games()]) }}@endif
                        </span>
                        <span class="grow"></span>
                        <x-copy-npub :npub="$user->npub" :name="$name" variant="button" :label="$user->shortNpub()" />
                    </div>
                </div>
            </div>
        </section>

        {{-- The trophies: places 1 to 3, the newest win first and, while recent, landing once --}}
        @if ($honours['trophies'] !== [])
            @include('pages.players.partials.trophies', ['trophies' => $honours['trophies']])
        @endif

        {{-- P45: the player on Nostr: open, share, message, zap (QR of their LNURL only), follow --}}
        <x-nostr-bar :bar="\App\Support\Nostr\NostrBar::player($user, $profile->hasProfile ? $profile->lud16 : null)" class="mx-4 lg:mx-0" />

        {{-- On the own page: bring a friend (a daily chess link, the one every player can make) --}}
        @if ($isMe)
            <div class="mx-4 lg:mx-0"><livewire:invite-link place="profile" /></div>
        @endif

        {{-- Rank badges (P11): the NIP-58 badges of this player; on the own page "Show on my Nostr profile" and the way to the share cards. --}}
        <div class="mx-4 lg:mx-0">
            <livewire:rank-badges :player="$user" />
        </div>

        {{-- The player card and the Wrapped story (plan "KI-Stimme der Liga", P4). --}}
        <livewire:pride-card :player="$user" :wire:key="'pride-card-'.$user->id" />

        @include('pages.players.partials.stats', ['stats' => $stats, 'tournaments' => $tournaments, 'name' => $name, 'isMe' => $isMe])
    </div>
</x-layouts::app>
