/**
 * The bell's client part (resources/views/components/⚡notification-bell.blade.php):
 * the panel's open state, and a new render when a notification arrives. The
 * notification comes from the shell's one dispatcher (playerEvents.js), in
 * the same batch as the dock's and the cup badge's renders, so all of them
 * leave as one request (performance plan P3, F5); before, the bell asked at
 * once and the others 250 ms later, two requests for one notification.
 */
import { subscribePlayerEvents } from './playerEvents.js';

export default function notificationBell(config) {
    return {
        open: false,
        unsubscribe: null,

        init() {
            this.$watch('open', (value) => {
                this.$dispatch('bell-toggle', value);
                if (value) {
                    this.$wire.loadList();
                } else {
                    this.$wire.showList = false;
                }
            });
            this.unsubscribe = subscribePlayerEvents(config, (reasons) => {
                if (reasons.has('notification')) this.$wire.$refresh();
            });
        },

        destroy() {
            this.unsubscribe?.();
        },
    };
}
