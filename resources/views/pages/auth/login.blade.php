{{--
    Login, 1:1 from Login.dc.html without the Lightning option (user decision:
    Google and Nostr only). The card is the <x-nostr-login> scope: the buttons
    run the P3 flow, the pending line reuses the "waiting for your wallet" hint
    of Login.dc.html, the error line the offline banner of States.dc.html.
--}}
@php
    $perks = [
        [__('Member badge'), __('on your profile, in rankings and on your clan.')],
        [__('Cosmetics:'), __('board themes, piece sets, profile frames, animated clan logos, pick your own meme team name.')],
        [__("Members' prize pools:"), __('some tournaments pay their sats to members only. Everyone can still play them.')],
        [__('A say and early access:'), __('vote on the next game, try new features first.')],
    ];
@endphp

<x-layouts::app :title="__('Log in')">
    <div class="flex grow flex-col items-center gap-6 p-4 lg:flex-row lg:items-start lg:justify-center lg:gap-12 lg:p-12">
        <x-nostr-login class="flex w-full max-w-[460px] flex-col gap-4 rounded-lg bg-card p-5 lg:w-[460px] lg:p-8">
            <div class="flex flex-col items-center gap-3 pb-2 text-center">
                <x-logo :size="56" />
                <h1 class="m-0 font-display text-2xl leading-[1.2] font-bold">{{ __('Log in to play') }}</h1>
                <span class="text-[13px] leading-normal text-ink-2">{{ __('New here? The same buttons create your player.') }}<br>{{ __('No password to remember.') }}</span>
            </div>

            <button type="button" class="btn-p flex h-14 w-full cursor-pointer items-center gap-3 rounded-lg bg-btc px-4 text-sm font-bold text-on-btc disabled:cursor-wait"
                    x-on:click="loginWithGoogle()" x-bind:disabled="busy" data-test="login-google">
                <x-icon name="google" />
                {{ __('Continue with Google') }}
            </button>

            <button type="button" class="btn-w flex h-12 w-full cursor-pointer items-center gap-3 rounded-lg border border-line bg-well px-4 text-left text-[13px] text-ink disabled:cursor-wait"
                    x-on:click="loginWithNostr()" x-bind:disabled="busy" data-test="login-nostr">
                <x-icon name="key" :size="18" />
                <span class="flex flex-col items-start gap-0.5">
                    <span>{{ __('Nostr extension or app') }}</span>
                    <span class="text-[11px] text-ink-2">{{ __('for people who already have a Nostr key') }}</span>
                </span>
            </button>

            <span class="flex items-center gap-1.5 text-xs text-btc-hi" role="status" x-show="busy" x-cloak data-test="login-pending">
                <span class="inline-block size-[7px] animate-live rounded-full bg-btc-hi" aria-hidden="true"></span>
                {{ __('Waiting for your confirmation') }}
            </span>

            <div class="flex items-start gap-2.5 rounded-md bg-loss-tint px-3.5 py-2.5 text-xs leading-normal text-ink shadow-[inset_0_0_0_1px_#5A2A2E]"
                 role="alert" x-show="error" x-cloak data-test="login-error">
                <x-icon name="alert" :size="16" class="mt-px text-loss" />
                <span x-text="error"></span>
            </div>

            <p class="mt-1 mb-0 text-xs leading-[1.6] text-ink-3">
                {{ __('Everyone can play everything: ladders, clans, challenges, tournaments. By continuing you accept the') }}
                <a href="{{ route('rules') }}">{{ __('rules') }}</a>.
            </p>
        </x-nostr-login>

        <div class="flex w-full max-w-[480px] flex-col gap-5 lg:w-[480px]">
            <section aria-labelledby="perk-h" class="flex flex-col gap-4 rounded-lg bg-card px-5 py-6 shadow-ring-btc lg:px-8 lg:py-7">
                <span class="flex items-center gap-3"><x-member-badge long /></span>
                <h2 id="perk-h" class="m-0 font-display text-xl leading-[1.3] font-bold">{{ __('Members of EINUNDZWANZIG get a little extra') }}</h2>
                <ul class="m-0 flex list-none flex-col gap-2.5 p-0 text-[13px] leading-normal text-ink-2">
                    @foreach ($perks as [$lead, $rest])
                        <li class="flex gap-2.5">
                            <span class="flex pt-0.5 text-btc"><x-icon name="check" :size="14" /></span>
                            <span><b class="text-ink">{{ $lead }}</b> {{ $rest }}</span>
                        </li>
                    @endforeach
                </ul>
                <p class="m-0 border-t border-hairline pt-3 text-xs leading-[1.6] text-ink-3">{{ __('Perks never change a rating, a pairing or who may play. Already a member? Your badge shows up by itself once you log in.') }}</p>
                <div class="flex flex-wrap gap-3">
                    <a href="https://verein.einundzwanzig.space" class="inline-flex h-11 items-center rounded-md border border-btc-deep px-[18px] text-[13px] font-bold text-btc-hi hover:bg-btc-press">{{ __('Become a member') }}</a>
                </div>
            </section>
            <p class="m-0 px-2 text-xs leading-[1.6] text-ink-3">{{ __('Every result on TWENTY ONE is a public record anyone can check. You never have to deal with that part.') }} <a href="{{ route('protocol') }}">{{ __('How it works') }}</a></p>
        </div>
    </div>
</x-layouts::app>
