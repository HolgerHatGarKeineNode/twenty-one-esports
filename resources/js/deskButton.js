/**
 * deskButton({ desk, config }) — a "Tournament desk" button with its unread
 * badge (components/tournaments/desk-button): in the "What to do now" and
 * champion heroes, on a tournament game's banner and on the admin edit page.
 *
 * It is a link to the desk on the tournament page (#desk). On that page,
 * where the desk is always open (components/tournaments/desk-chat), a click
 * brings it into view and puts the cursor into its field. The count comes
 * from Alpine.store('desk'), which the open desk fills; on a page without one,
 * the first button of a tournament starts a desk without a view for it
 * (resources/js/deskChat.js, loaded only then, so no other page carries the
 * chat's code).
 */
export function deskButton({ desk, config = null }) {
    return {
        init() {
            const Alpine = window.Alpine;

            // After the whole page started: the open desk further down the page claims it first.
            setTimeout(async () => {
                const store = Alpine.store('desk');
                if (config === null || store.running[desk]) return;
                store.running[desk] = 'badge';

                try {
                    const { startHeadlessDesk } = await import('./deskChat.js');
                    startHeadlessDesk(config);
                } catch (error) {
                    console.warn('[desk] the unread count could not start', error);
                }
            }, 0);
        },

        get unread() {
            return window.Alpine.store('desk')?.unread[desk] ?? 0;
        },

        go(event) {
            if (window.Alpine.store('desk')?.running[desk] !== 'view') return;
            event.preventDefault();
            window.dispatchEvent(new CustomEvent('desk-open', { detail: desk }));
        },
    };
}

document.addEventListener('alpine:init', () => {
    // unread: messages since last read; read: that marker (unix seconds); running: `view` or `badge`, per tournament.
    window.Alpine.store('desk', { unread: {}, read: {}, running: {} });
    window.Alpine.data('deskButton', deskButton);
});
