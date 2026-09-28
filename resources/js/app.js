/**
 * Echo is NOT imported here: a page that needs realtime opts in through the
 * layout's `realtime` flag, which loads resources/js/echo.js as its own entry.
 * Pages without it open no websocket.
 */

import './toasts';

import nostrLogin from './nostrLogin.js';
import blockZeroCountdown from './blockZeroCountdown.js';
import { profileCardHost, profileStore } from './profiles.js';
import { dropFailedBunker, forgetForeignSession } from './millAuth.js';
import matchDock from './matchDock.js';
import playerPicker from './playerPicker.js';
import { firstSteps, shellHeader, shellSheet } from './shellNav.js';
import { livePlayer, liveStage } from './livePlayer.js';
import { liveStore, readSeed, startLiveFeed } from './liveFeed.js';
import './nostrSign.js';
import './casualPlay.js';
import './badgeShare.js';
import './captured.js';
import './sanNotation.js';
import './tournamentLanding.js';
import './autoDecision.js';
import './leagueTime.js';
import './tournamentTv.js';
import { dropAnswersForDetachedComponents } from './livewireDetached.js';

// A Livewire answer for a component that wire:navigate already took off the page is not morphed into it.
document.addEventListener('livewire:init', () => dropAnswersForDetachedComponents(window.Livewire));

// Nostr login (P3): Alpine component for <x-nostr-login />.
document.addEventListener('alpine:init', () => {
    window.Alpine.data('nostrLogin', nostrLogin);
    window.Alpine.data('blockZeroCountdown', blockZeroCountdown);
    // Nostr profiles of the players on a page, and the player card (P10a).
    window.Alpine.store('profiles', profileStore());
    window.Alpine.data('profileCardHost', profileCardHost);
    // The match dock of a logged-in player (P5f).
    window.Alpine.data('matchDock', matchDock);
    // The player picker combobox, <x-player-picker>.
    window.Alpine.data('playerPicker', playerPicker);
    // The shell navigation: header and game hub, the phone's More sheet, the guests' first steps.
    window.Alpine.data('shellHeader', shellHeader);
    window.Alpine.data('shellSheet', shellSheet);
    window.Alpine.data('firstSteps', firstSteps);
    // The live stream (P20): the floating player and the big one on /live; hls.js loads on first play.
    window.Alpine.data('livePlayer', livePlayer);
    window.Alpine.data('liveStage', liveStage);
    // Whether the stream is on air and how many watch (P20b): one store, one poller per window.
    const liveSeed = readSeed();
    window.Alpine.store('live', liveStore(liveSeed));
    startLiveFeed(liveSeed, window.Alpine.store('live'));
});

// Call before submitting the logout form so a remote signer is not inherited.
window.forgetNostrSigner = dropFailedBunker;

// A stored remote-signer session that is not the logged-in player's goes at once.
forgetForeignSession();
