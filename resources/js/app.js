/**
 * Echo is NOT imported here: a page that needs realtime opts in through the
 * layout's `realtime` flag, which loads resources/js/echo.js as its own entry.
 * Pages without it open no websocket.
 */

import './toasts';
// wire:navigate only between the shell's navigable pages; every other page loads in full (P6b).
import './navigateGuard.js';

import nostrLogin from './nostrLogin.js';
import blockZeroCountdown from './blockZeroCountdown.js';
import { profileCardHost, profileStore } from './profiles.js';
import { dropFailedBunker, forgetForeignSession } from './millAuth.js';
import matchDock from './matchDock.js';
import teamMatchBoards from './teamMatchBoards.js';
import upcomingEvents from './upcomingEvents.js';
import cupMatch from './cupMatch.js';
import notificationBell from './notificationBell.js';
import boardLobby from './boardLobby.js';
import playerPicker from './playerPicker.js';
import { firstSteps, shellHeader, shellSheet } from './shellNav.js';
import { livePlayer, liveStage } from './livePlayer.js';
import { liveStore, readSeed, startLiveFeed } from './liveFeed.js';
import './nostrSign.js';
import './casualPlay.js';
import './badgeShare.js';
import './nostrBar.js';
import './zapWinner.js';
import './potZappers.js';
import './followsHereUi.js';
import './clanDmsUi.js';
import './captured.js';
import './sanNotation.js';
import './tournamentLanding.js';
import './autoDecision.js';
import './tournamentNow.js';
// The tournament desk's buttons and unread badge; the chat itself loads only where it runs.
import './deskButton.js';
import './championMoment.js';
import './leagueTime.js';
// The tournament TV's script (tournamentTv.js) loads only on the TV (layouts/tv), as its own entry.
// Comments, likes and RSVPs on Nostr (P48): tournament, game and match pages.
import './nostrComments.js';
import { dropAnswersForDetachedComponents } from './livewireDetached.js';
// The admin's moderation menu on chat messages (site-wide mute and ban).
import { siteModeration } from './siteHidden.js';

// A Livewire answer for a component that wire:navigate already took off the page is not morphed into it.
document.addEventListener('livewire:init', () => dropAnswersForDetachedComponents(window.Livewire));

// Nostr login (P3): Alpine component for <x-nostr-login />.
document.addEventListener('alpine:init', () => {
    window.Alpine.data('nostrLogin', nostrLogin);
    window.Alpine.data('siteModeration', siteModeration);
    window.Alpine.data('blockZeroCountdown', blockZeroCountdown);
    // A board game's lobby (pages/board/⚡lobby): online list and "Looking to play", as chessLobby.
    window.Alpine.data('boardLobby', boardLobby);
    // Nostr profiles of the players on a page, and the player card (P10a).
    window.Alpine.store('profiles', profileStore());
    window.Alpine.data('profileCardHost', profileCardHost);
    // The match dock of a logged-in player (P5f).
    window.Alpine.data('matchDock', matchDock);
    // The boards and team score of a chess team match on its public page (plan "Schach Rapid und Clan", P5).
    window.Alpine.data('teamMatchBoards', teamMatchBoards);
    // Open match rooms and registered tournaments on home, /matches, /tournaments and a game page.
    window.Alpine.data('upcomingEvents', upcomingEvents);
    // The player's open cup match: the header badge and the page banner (CupMatchNow).
    window.Alpine.data('cupMatch', cupMatch);
    // The bell's panel and its renders; one dispatcher feeds the dock, the badge and the bell (playerEvents.js).
    window.Alpine.data('notificationBell', notificationBell);
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
