/**
 * The cup match signal on the client (resources/views/components/⚡cup-match.blade.php:
 * the header badge and the page banner; App\Support\Tournaments\CupMatchNow).
 * What it shows comes from the server; this module only asks for a new render
 * when something may have changed: a notification (the league's "your cup
 * game is on" included), a game that started, a series that changed, and the
 * slow poll in case an event was missed. All of them come from the shell's
 * one dispatcher (playerEvents.js), in the same batch as the dock's and the
 * bell's, so the renders leave as one request (performance plan P3, F5).
 * A guest has no cup match: nothing is asked.
 */
import { subscribePlayerEvents } from './playerEvents.js';

const REASONS = ['notification', 'game', 'series', 'poll'];

export default function cupMatch(config) {
    return {
        unsubscribe: null,

        init() {
            if (!config.userId) return;
            this.unsubscribe = subscribePlayerEvents(config, (reasons) => {
                if (REASONS.some((reason) => reasons.has(reason))) this.$wire.$refresh();
            });
        },

        destroy() {
            this.unsubscribe?.();
        },
    };
}
