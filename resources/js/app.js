/**
 * Echo is NOT imported here: a page that needs realtime opts in through the
 * layout's `realtime` flag, which loads resources/js/echo.js as its own entry.
 * Pages without it open no websocket.
 */

import './toasts';

import nostrLogin from './nostrLogin.js';
import blockZeroCountdown from './blockZeroCountdown.js';
import { profileCardHost, profileStore } from './profiles.js';
import { dropFailedBunker } from './millAuth.js';
import matchDock from './matchDock.js';
import './nostrSign.js';
import './captured.js';

// Nostr login (P3): Alpine component for <x-nostr-login />.
document.addEventListener('alpine:init', () => {
    window.Alpine.data('nostrLogin', nostrLogin);
    window.Alpine.data('blockZeroCountdown', blockZeroCountdown);
    // Nostr profiles of the players on a page, and the player card (P10a).
    window.Alpine.store('profiles', profileStore());
    window.Alpine.data('profileCardHost', profileCardHost);
    // The match dock of a logged-in player (P5f).
    window.Alpine.data('matchDock', matchDock);
});

// Call before submitting the logout form so a remote signer is not inherited.
window.forgetNostrSigner = dropFailedBunker;
