{{--
    "Mitspielen" for a guest (HomeGuest.dc.html aside, HomeGuestPhone.dc.html): one account for every game, no
    password, the two ways in. The buttons run the login flow right here (<x-nostr-login>, resources/js/nostrLogin.js).

    $count: how many games the league has.
--}}
<x-nostr-login class="rv-card flex flex-col gap-3 p-4 max-lg:rounded-none max-lg:border-x-0 max-lg:border-b-0 max-lg:bg-transparent lg:order-none" data-test="home-join">
    <h2 class="rv-h3">{{ __('Join in') }}</h2>
    <span class="rv-m">{{ trans_choice('One account for all :count game. No password.|One account for all :count games. No password.', $count) }}</span>
    <button type="button" class="rv-b w-full" x-on:click="loginWithGoogle()" x-bind:disabled="busy" data-test="join-google">{{ __('Continue with Google') }}</button>
    <button type="button" class="rv-b2 w-full" x-on:click="loginWithNostr()" x-bind:disabled="busy" data-test="join-nostr"><span class="max-lg:hidden">{{ __('Log in with Nostr') }}</span><span class="lg:hidden">{{ __('With Nostr') }}</span></button>
    <span class="flex items-center gap-1.5 text-xs text-btc-hi" role="status" x-show="busy" x-cloak>
        <span class="inline-block size-[7px] animate-live rounded-full bg-btc-hi" aria-hidden="true"></span>{{ __('Waiting for your confirmation') }}
    </span>
    <p class="m-0 text-xs text-loss" role="alert" x-show="error" x-text="error" x-cloak></p>
</x-nostr-login>
