/**
 * The shell navigation (header concept B, resources/views/components/shell/):
 * the header with its game hub, the More sheet of the phone's tab bar and the
 * guests' "New here?" strip.
 *
 * The hub and the More sheet close each other (a `nav-sheet` window event),
 * trap the focus while open (Alpine's x-trap) and give it back to the button
 * that opened them on Esc. "/" focuses the site search from anywhere except
 * a field.
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

        init() {
            window.addEventListener('keydown', (event) => this.hotkey(event));
            window.addEventListener('nav-sheet', (event) => event.detail !== 'hub' && this.closeHub(false));
            this.$nextTick(() => this.revealActiveChip());
        },

        hotkey(event) {
            if (event.key !== '/' || event.defaultPrevented || event.ctrlKey || event.metaKey || event.altKey || typing(event)) return;
            const field = document.getElementById('site-search');
            if (!field || !field.checkVisibility()) return;
            event.preventDefault();
            field.focus();
        },

        toggleHub(button) {
            this.hub ? this.closeHub() : this.openHub(button);
        },

        openHub(button) {
            this.opener = button;
            this.search = false;
            this.hub = true;
            window.dispatchEvent(new CustomEvent('nav-sheet', { detail: 'hub' }));
            // The filter takes the focus on desktop only: on a phone it would open the keyboard over the grid.
            this.$nextTick(() => (window.matchMedia(DESKTOP).matches ? this.$refs.hubFilter : this.$root.querySelector('#game-hub'))?.focus({ preventScroll: true }));
        },

        closeHub(returnFocus = true) {
            if (!this.hub) return;
            this.hub = false;
            // After the panel is hidden and x-trap has let go: while the trap holds, focus cannot leave the panel.
            if (returnFocus) this.$nextTick(() => this.opener?.focus({ preventScroll: true }));
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
            this.$nextTick(() => document.getElementById('more-sheet')?.focus({ preventScroll: true }));
        },

        close(returnFocus = true) {
            if (!this.open) return;
            this.open = false;
            // After the panel is hidden and x-trap has let go: while the trap holds, focus cannot leave the panel.
            if (returnFocus) this.$nextTick(() => this.opener?.focus({ preventScroll: true }));
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
