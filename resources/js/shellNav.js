/**
 * The shell navigation (header concept B, resources/views/components/shell/):
 * the header with its game hub, the More sheet of the phone's tab bar and the
 * guests' "New here?" strip.
 *
 * The hub and the More sheet close each other (a `nav-sheet` window event),
 * trap the focus while open (Alpine's x-trap) and give it back to the button
 * that opened them on Esc. "/" focuses the site search from anywhere except
 * a field, opening the search row under the header first.
 */

const STEPS_KEY = 'twentyone.firstSteps';
const DESKTOP = '(min-width: 64rem)';

function typing(event) {
    const target = event.target;

    return target instanceof HTMLElement && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName));
}

export function shellHeader() {
    return {
        search: false,
        bell: false,
        hub: false,
        filter: '',
        kind: 'all',
        opener: null,
        // Bumped by every deliberate focus move this component makes (the "/" shortcut, opening the hub,
        // closing it). closeHub()'s own restore is deferred behind x-trap's teardown -- see there -- and by
        // the time it runs, a later claim (e.g. "/" firing in between) may already have moved focus on
        // purpose; comparing the ticket it captured against the current one is how it tells "nothing else
        // touched focus since" from "something did", without guessing at document.activeElement, which a
        // hidden, auto-blurred element does not update on a fixed schedule.
        focusTicket: 0,

        init() {
            window.addEventListener('keydown', (event) => this.hotkey(event));
            window.addEventListener('nav-sheet', (event) => event.detail !== 'hub' && this.closeHub(false));
            this.$nextTick(() => this.revealActiveChip());
        },

        hotkey(event) {
            if (event.key !== '/' || event.defaultPrevented || event.ctrlKey || event.metaKey || event.altKey || typing(event)) return;
            const field = this.$refs.searchField;
            if (!field) return;
            event.preventDefault();
            this.focusTicket++;
            // The field sits in the search row under the header (plan "Mempool-Streifen", P4): open it first.
            this.closeHub(false);
            this.search = true;
            this.$nextTick(() => field.focus());
        },

        toggleHub(button) {
            this.hub ? this.closeHub() : this.openHub(button);
        },

        openHub(button) {
            this.opener = button;
            this.search = false;
            this.hub = true;
            window.dispatchEvent(new CustomEvent('nav-sheet', { detail: 'hub' }));
            this.focusTicket++;
            // The filter takes the focus on desktop only: on a phone it would open the keyboard over the grid.
            this.$nextTick(() => (window.matchMedia(DESKTOP).matches ? this.$refs.hubFilter : this.$root.querySelector('#game-hub'))?.focus({ preventScroll: true }));
        },

        closeHub(returnFocus = true) {
            if (!this.hub) return;
            this.hub = false;
            // After the panel is hidden and x-trap has let go: while the trap holds, focus cannot leave the panel.
            // That release is not always inside the same tick this runs in -- it can land tens of ms later, long
            // enough for something else (the "/" search shortcut, reopening the hub) to have deliberately
            // claimed focus by then. Only reclaim it if no later claim has been made since -- never steal it
            // back from whatever focused itself since.
            if (returnFocus) {
                const ticket = ++this.focusTicket;
                this.$nextTick(() => { if (this.focusTicket === ticket) this.opener?.focus({ preventScroll: true }); });
            }
        },

        shows(tile) {
            const query = this.filter.trim().toLowerCase();

            return (this.kind === 'all' || tile.dataset.kinds.split(' ').includes(this.kind)) && (query === '' || tile.dataset.name.includes(query));
        },

        anyShown() {
            return [...(this.$refs.hubTiles?.querySelectorAll('[data-kinds]') ?? [])].some((tile) => this.shows(tile));
        },

        /** Scroll the chip row (not the page) so the active game's chip is in view. */
        revealActiveChip() {
            const row = this.$refs.chips;
            const chip = row?.querySelector('[aria-current]');
            if (!row || !chip || row.scrollWidth <= row.clientWidth) return;
            row.scrollLeft = Math.max(0, chip.offsetLeft - row.offsetLeft - 16);
        },
    };
}

export function shellSheet() {
    return {
        open: false,
        opener: null,
        // Same arbitration as shellHeader.focusTicket above.
        focusTicket: 0,

        init() {
            window.addEventListener('nav-sheet', (event) => event.detail !== 'more' && this.close(false));
        },

        toggle(button) {
            if (this.open) {
                this.close();

                return;
            }
            this.opener = button;
            this.open = true;
            window.dispatchEvent(new CustomEvent('nav-sheet', { detail: 'more' }));
            this.focusTicket++;
            this.$nextTick(() => document.getElementById('more-sheet')?.focus({ preventScroll: true }));
        },

        close(returnFocus = true) {
            if (!this.open) return;
            this.open = false;
            // After the panel is hidden and x-trap has let go: while the trap holds, focus cannot leave the panel.
            // Same deferred-steal race as shellHeader.closeHub() above: only reclaim focus if no later claim
            // (reopening the sheet, or anything else that bumps focusTicket) has been made since.
            if (returnFocus) {
                const ticket = ++this.focusTicket;
                this.$nextTick(() => { if (this.focusTicket === ticket) this.opener?.focus({ preventScroll: true }); });
            }
        },
    };
}

export function firstSteps() {
    return {
        dismiss() {
            try {
                window.localStorage.setItem(STEPS_KEY, 'dismissed');
            } catch (e) {
                // Storage blocked (private mode, disabled): the cookie below still remembers it.
            }
            try {
                document.cookie = `${STEPS_KEY}=dismissed; max-age=31536000; path=/; SameSite=Lax`;
            } catch (e) {
                // No cookies either: the strip is gone for this page and comes back on the next.
            }
            document.documentElement.dataset.stepsDismissed = '1';
        },
    };
}
