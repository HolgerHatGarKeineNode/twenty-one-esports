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
        // The phone's More sheet (shellSheet) is open: the header's menu button mirrors it in aria-expanded.
        more: false,
        // The account menu (desktop chip): a WAI-ARIA menu button, see openAccount() and accountKey().
        account: false,
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
            // Removed in destroy(): every wire:navigate swap brings a new header.
            this.teardown = new AbortController();
            const signal = this.teardown.signal;
            window.addEventListener('keydown', (event) => this.hotkey(event), { signal });
            window.addEventListener('nav-sheet', (event) => event.detail !== 'hub' && this.closeHub(false), { signal });
            window.addEventListener('matches-filter', (event) => this.followMatchesFilter(event.detail), { signal });
            // The phone's tab bar "Spiele" opens the same game hub (it sits outside this scope).
            window.addEventListener('hub-toggle', (event) => this.toggleHub(event.detail), { signal });
            window.addEventListener('more-state', (event) => { this.more = event.detail; }, { signal });
            this.$nextTick(() => this.revealActiveChip());
        },

        destroy() {
            this.teardown?.abort();
        },

        hotkey(event) {
            if (event.key !== '/' || event.defaultPrevented || event.ctrlKey || event.metaKey || event.altKey || typing(event)) return;
            // From lg the field stands in row 1 (Header.dc.html): focus it there, no row to open.
            const inline = this.$refs.searchInline;
            if (inline && inline.getClientRects().length > 0) {
                event.preventDefault();
                this.focusTicket++;
                this.closeHub(false);
                this.account = false;
                inline.focus();

                return;
            }
            const field = this.$refs.searchField;
            if (!field) return;
            event.preventDefault();
            this.focusTicket++;
            // The field sits in the search row under the header (plan "Mempool-Streifen", P4): open it first.
            this.closeHub(false);
            this.account = false;
            this.search = true;
            this.$nextTick(() => field.focus());
        },

        /**
         * Close the search row. Esc gives the focus back to the search button, as the hub and the More sheet
         * give it back to their opener, when it sat in the row (or nowhere, once the row is hidden).
         */
        closeSearch() {
            if (!this.search) return;
            const row = document.getElementById('mobile-search');
            const inRow = row?.contains(document.activeElement) || document.activeElement === document.body;
            this.search = false;
            if (inRow) {
                const ticket = ++this.focusTicket;
                this.$nextTick(() => { if (this.focusTicket === ticket) this.$refs.searchToggle?.focus({ preventScroll: true }); });
            }
        },

        /**
         * /matches (plan "Mempool-Streifen", P4): the Chain and Game filters change the page without a reload, so the
         * chain rail and the phone's sheet move their aria-current along (the rule of ShellNavigation::chain()).
         */
        followMatchesFilter(detail) {
            const current = { mempool: detail?.chain === 'all' && detail?.game === 'all', casual: detail?.chain === 'casual' && detail?.game === 'all' };
            for (const link of document.querySelectorAll('[data-chain-key]')) {
                if (current[link.dataset.chainKey]) link.setAttribute('aria-current', 'page');
                else link.removeAttribute('aria-current');
            }
        },

        toggleHub(button) {
            this.hub ? this.closeHub() : this.openHub(button);
        },

        openHub(button) {
            this.opener = button;
            this.search = false;
            this.account = false;
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

        toggleAccount() {
            this.account ? this.closeAccount() : this.openAccount('first');
        },

        /** Open the account menu with the focus on its first or last item (Enter/Space/ArrowDown, ArrowUp). */
        openAccount(which = 'first') {
            this.closeHub(false);
            this.search = false;
            this.account = true;
            this.focusTicket++;
            this.$nextTick(() => {
                const items = this.accountItems();
                (which === 'last' ? items[items.length - 1] : items[0])?.focus({ preventScroll: true });
            });
        },

        closeAccount(returnFocus = true) {
            if (!this.account) return;
            this.account = false;
            if (returnFocus) {
                const ticket = ++this.focusTicket;
                this.$nextTick(() => { if (this.focusTicket === ticket) this.$refs.accountChip?.focus({ preventScroll: true }); });
            }
        },

        accountItems() {
            return [...(this.$refs.accountMenu?.querySelectorAll('[role=menuitem]') ?? [])];
        },

        /** Arrows, Home and End move between the items; Tab leaves the menu and closes it. */
        accountKey(event) {
            const items = this.accountItems();
            const at = items.indexOf(document.activeElement);
            const move = { ArrowDown: at + 1, ArrowUp: at - 1, Home: 0, End: items.length - 1 }[event.key];
            if (event.key === 'Tab') {
                this.closeAccount(false);
                return;
            }
            if (move === undefined || items.length === 0) return;
            event.preventDefault();
            items[(move + items.length) % items.length].focus({ preventScroll: true });
        },

        /** The focus left the chip and the menu (a click elsewhere, a screen reader jump): close without taking it back. */
        accountFocusOut(event) {
            if (this.account && event.relatedTarget && !event.currentTarget.contains(event.relatedTarget)) this.closeAccount(false);
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
            this.teardown = new AbortController();
            window.addEventListener('nav-sheet', (event) => event.detail !== 'more' && this.close(false), { signal: this.teardown.signal });
            // The header's menu button (phone top bar, Header.dc.html) sits outside this scope.
            window.addEventListener('more-toggle', (event) => this.toggle(event.detail), { signal: this.teardown.signal });
        },

        destroy() {
            this.teardown?.abort();
        },

        toggle(button) {
            if (this.open) {
                this.close();

                return;
            }
            this.opener = button;
            this.open = true;
            window.dispatchEvent(new CustomEvent('nav-sheet', { detail: 'more' }));
            window.dispatchEvent(new CustomEvent('more-state', { detail: true }));
            this.focusTicket++;
            this.$nextTick(() => document.getElementById('more-sheet')?.focus({ preventScroll: true }));
        },

        close(returnFocus = true) {
            if (!this.open) return;
            this.open = false;
            window.dispatchEvent(new CustomEvent('more-state', { detail: false }));
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

/**
 * The header's "Aktionen" entry (Header.dc.html): how many entries the match dock holds, read from the dock's
 * own root (`data-open`, rendered by components/⚡match-dock and kept current by its refreshes), so the header
 * runs no query of its own. A click opens the dock; with nothing in it the link goes to the player's page.
 */
export function dockCount() {
    // Closure state, never `this.*`: the entry sits inside the header's and the More sheet's scopes, and Alpine writes
    // a property the parent also has (teardown) to the parent, which then aborted the wrong controller and leaked.
    let observer = null;
    let watched = null;
    const teardown = new AbortController();

    return {
        dockOpen: 0,

        init() {
            const read = () => {
                const root = document.querySelector('[data-test=match-dock-root]');
                this.dockOpen = root ? Number(root.dataset.open || 0) : 0;
                if (root && root !== watched) {
                    observer?.disconnect();
                    watched = root;
                    observer = new MutationObserver(read);
                    observer.observe(root, { attributes: true, attributeFilter: ['data-open'] });
                }
            };
            this.$nextTick(read);
            document.addEventListener('livewire:navigated', read, { signal: teardown.signal });
        },

        destroy() {
            observer?.disconnect();
            teardown.abort();
        },

        openDock(event) {
            window.dispatchEvent(new CustomEvent('dock-open', { detail: event }));
        },
    };
}
