/**
 * Entry for the tournament page's open desk (pages/tournaments/show,
 * components/tournaments/desk-chat): the chat of the tournament desk
 * (resources/js/deskChat.js). Loaded only on that page (layout `scripts`);
 * the desk buttons elsewhere load the chat on demand (deskButton.js).
 */
import { deskChat } from './deskChat.js';
import { registerAlpine } from './registerAlpine.js';

registerAlpine(() => {
    window.Alpine.data('deskChat', deskChat);
});
