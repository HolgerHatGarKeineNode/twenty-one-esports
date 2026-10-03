/**
 * championMoment({ id }) — the champion hero at the top of a finished
 * tournament page (App\Support\Tournaments\TournamentChampionMoment).
 *
 * The first time this browser opens the page after the finish, the hero
 * celebrates once: blocks fall through it and a gold sheen runs over the
 * champion's name (the `is-celebrating` class, resources/css/app.css). It
 * remembers the tournament in localStorage, so a reload or a later visit is
 * calm. With prefers-reduced-motion nothing moves and nothing is stored.
 *
 * share(text, url, hint): the visitor's "Share the win": the system share
 * sheet (a Nostr app takes the link), else the link to the clipboard.
 */
const KEY = 'champion-seen';

export function championMoment({ id } = {}) {
    return {
        celebrating: false,
        hint: '',

        init() {
            if (window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) {
                return;
            }

            let seen = [];

            try {
                seen = JSON.parse(window.localStorage.getItem(KEY) ?? '[]');
            } catch {
                seen = [];
            }

            if (!Array.isArray(seen) || seen.includes(id)) {
                return;
            }

            try {
                window.localStorage.setItem(KEY, JSON.stringify([...seen, id].slice(-50)));
            } catch {
                // Private mode: it celebrates on every visit, which is fine.
            }

            this.celebrating = true;
            setTimeout(() => {
                this.celebrating = false;
            }, 2600);
        },

        async share(text, url, hint) {
            if (typeof navigator.share === 'function') {
                try {
                    await navigator.share({ title: document.title, text, url });

                    return;
                } catch (error) {
                    if (error?.name === 'AbortError') {
                        return;
                    }
                }
            }

            try {
                await navigator.clipboard.writeText(`${text} ${url}`);
                this.hint = hint;
                setTimeout(() => {
                    this.hint = '';
                }, 3000);
            } catch {
                this.hint = url;
            }
        },
    };
}

if (typeof document !== 'undefined') {
    document.addEventListener('alpine:init', () => {
        window.Alpine.data('championMoment', championMoment);
    });
}
