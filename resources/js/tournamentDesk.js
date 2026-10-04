/**
 * Entry for the tournament page's desk drawer (pages/tournaments/show,
 * components/tournaments/desk-chat): the chat of the tournament desk
 * (resources/js/deskChat.js). Loaded only on that page (layout `scripts`);
 * the desk buttons elsewhere load the chat on demand (deskButton.js).
 */
import { deskChat } from './deskChat.js';

document.addEventListener('alpine:init', () => {
    window.Alpine.data('deskChat', deskChat);
});
