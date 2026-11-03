{{--
    Google sign-ins start with a bare key: no Nostr profile, no name, no picture, no Lightning address, and no Nostr
    app to make one (user 2026-10-25). Until a profile of theirs is known, every page leads them to the profile page,
    where the league's client writes it with their own signer. Players with a Nostr profile never see this. Not on the
    dashboard, whose first step asks the same, nor on boards that are played on.
--}}
@php($viewer = auth()->user())
@if ($viewer !== null && $viewer->profile_event_at === null && ! request()->routeIs('settings.profile', 'dashboard', 'games.show', 'stacker.play', 'matches.room'))
    {{--
        Below lg (the shell's phone layout, with its tab bar) the banner is one line of 48 px: the welcome cut with an
        ellipsis, a chevron, and the whole row as the link's target. In two sentences with a button it stood 174 px tall
        at 375 (194 in German) and pushed a Blockfill week's Play now below the tab bar (rule R3; user 2026-11-03).
    --}}
    <aside class="relative border-b border-btc/40 bg-btc-tint px-4 py-3 max-lg:py-0 lg:px-12" data-test="profile-welcome">
        <div class="mx-auto flex max-w-[1344px] flex-wrap items-center gap-x-4 gap-y-2 max-lg:h-12 max-lg:flex-nowrap max-lg:gap-x-3">
            <x-icon name="user" :size="20" class="shrink-0 text-btc" />
            <p class="m-0 min-w-0 grow basis-64 text-[13px] leading-normal max-lg:basis-auto max-lg:truncate">
                <b>{{ __('Welcome! Give yourself a name and a picture.') }}</b>
                {{ __('Right now other players only see a code. Add a Lightning address too, so prizes can reach you. Takes two minutes, no extra app needed.') }}
            </p>
            <a href="{{ route('settings.profile') }}" aria-label="{{ __('Set up my profile') }}. {{ __('Welcome! Give yourself a name and a picture.') }}"
               class="rv-b h-11 shrink-0 px-5 max-lg:w-11 max-lg:border-transparent max-lg:bg-transparent max-lg:px-0 max-lg:text-btc max-lg:after:absolute max-lg:after:inset-0 max-lg:hover:bg-transparent max-lg:hover:text-btc-hi"
               data-test="profile-welcome-link"><span class="max-lg:hidden">{{ __('Set up my profile') }}</span><x-icon name="next" :size="20" class="lg:hidden" /></a>
        </div>
    </aside>
@endif
