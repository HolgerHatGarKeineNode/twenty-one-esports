/**
 * Echo is NOT imported here: a page that needs realtime opts in through the
 * layout's `realtime` flag, which loads resources/js/echo.js as its own entry.
 * Pages without it open no websocket.
 */

import './toasts';

import nostrLogin from './nostrLogin.js';
import { dropFailedBunker } from './millAuth.js';

// Nostr login (P3): Alpine component for <x-nostr-login />.
document.addEventListener('alpine:init', () => {
    window.Alpine.data('nostrLogin', nostrLogin);
});

// Call before submitting the logout form so a remote signer is not inherited.
window.forgetNostrSigner = dropFailedBunker;
