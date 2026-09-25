{{--
    Login buttons (P3). Logic: resources/js/nostrLogin.js.

    With a slot, this is only the Alpine scope: the page brings its own markup
    and calls loginWithGoogle() / loginWithNostr(), reading `busy` and `error`
    (see pages/auth/login.blade.php). Without a slot it renders plain buttons.
--}}
<div
    x-data="nostrLogin({
        challengeUrl: @js(route('auth.nostr.challenge')),
        messages: @js([
            'failed' => __('Login failed. Please try again.'),
            'noSigner' => __('No Nostr signer found. Install a Nostr browser extension or use a remote signer.'),
            'signRejected' => __('The confirmation was not given. Please try again.'),
            'googleFailed' => __('Google login did not work. Please try again or use Nostr.'),
            'connectAborted' => __('Connecting the signer was cancelled or timed out.'),
        ]),
    })"
    {{ $attributes->class(['flex flex-col gap-3' => $slot->isEmpty()]) }}
    data-test="nostr-login"
>
    @if ($slot->isEmpty())
        <flux:button variant="primary" x-on:click="loginWithGoogle()" x-bind:disabled="busy">
            {{ __('Continue with Google') }}
        </flux:button>

        <flux:button x-on:click="loginWithNostr()" x-bind:disabled="busy">
            {{ __('Continue with Nostr') }}
        </flux:button>

        <flux:text x-show="error" x-text="error" x-cloak class="text-red-500" role="alert"></flux:text>
    @else
        {{ $slot }}
    @endif
</div>
