<?php

namespace App\Support\Nostr;

/**
 * What the player reads when signing with their Nostr signer fails, one
 * message per case (resources/js/signing.js tells the cases apart). Every page
 * that signs in the browser passes this set, so the cases read the same
 * everywhere and are translated.
 */
final class SignerMessages
{
    /**
     * @return array{noSigner: string, rejected: string, unreachable: string, wrongKey: string, signerFailed: string, failed: string}
     */
    public static function labels(): array
    {
        return [
            'noSigner' => __('No Nostr signer found. Install a Nostr browser extension or use a remote signer.'),
            'rejected' => __('The confirmation was not given. Please try again.'),
            'unreachable' => __('Your signer did not answer. Check that it is unlocked and online, then try again.'),
            'wrongKey' => __('This signer holds a different key than the one you logged in with.'),
            // ":reason" is filled in by the browser with the signer's own error.
            'signerFailed' => __('Your signer could not sign this (:reason). Please try again.'),
            'failed' => __('That did not work. Please try again.'),
        ];
    }
}
