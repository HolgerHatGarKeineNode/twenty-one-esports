/**
 * Entry for the series match room (pages/matches/room): the group chat of the
 * two lineups. Loaded only on that page (layout `scripts`).
 */
import { roomChat } from './roomChat.js';

document.addEventListener('alpine:init', () => {
    window.Alpine.data('roomChat', roomChat);
});
