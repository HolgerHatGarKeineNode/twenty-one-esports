/**
 * The mempool strip of the start page (design canvas Main.dc.html `.chain`, plan "Refactor und Design-Revamp",
 * user: "Wie bei mempool.space mit der Maus die Blockchain nach rechts und links scrollen"): drag with the mouse,
 * the wheel or trackpad, the arrow keys (the viewport is focusable and scrolls natively), swipe on a phone.
 * It opens with the divider between done and waiting in view; once it is out of view, "Zur Gegenwart" brings it
 * back. The edge fades show on the side that has more. A drag never opens the cube it started on. With reduced
 * motion every jump is instant.
 */
export default function mempoolChain() {
    // Closure, not `this.*`: Alpine writes a property a parent scope also has to that parent.
    const teardown = new AbortController();

    return {
        left: false,
        right: false,
        away: false,
        dragging: false,

        init() {
            const viewport = this.$refs.viewport;
            const signal = teardown.signal;
            let start = null;
            let moved = false;

            viewport.addEventListener('scroll', () => this.measure(), { passive: true, signal });
            window.addEventListener('resize', () => this.measure(), { signal });

            viewport.addEventListener('pointerdown', (event) => {
                if (event.pointerType !== 'mouse' || event.button !== 0) return;
                start = { x: event.clientX, scroll: viewport.scrollLeft };
                moved = false;
            }, { signal });
            window.addEventListener('pointermove', (event) => {
                if (start === null) return;
                const dx = event.clientX - start.x;
                if (!moved && Math.abs(dx) < 5) return;
                moved = true;
                this.dragging = true;
                viewport.scrollLeft = start.scroll - dx;
            }, { signal });
            window.addEventListener('pointerup', () => {
                start = null;
                // The click that ends a drag lands after pointerup: let it see `dragging` first.
                setTimeout(() => { this.dragging = false; }, 0);
            }, { signal });
            viewport.addEventListener('click', (event) => {
                if (moved) {
                    event.preventDefault();
                    event.stopPropagation();
                    moved = false;
                }
            }, { capture: true, signal });
            // A vertical wheel moves the chain sideways while it can move that way; at either end the page scrolls on.
            viewport.addEventListener('wheel', (event) => {
                if (Math.abs(event.deltaY) <= Math.abs(event.deltaX)) return;
                const max = viewport.scrollWidth - viewport.clientWidth;
                const next = viewport.scrollLeft + event.deltaY;
                if ((event.deltaY < 0 && viewport.scrollLeft <= 0) || (event.deltaY > 0 && viewport.scrollLeft >= max)) return;
                event.preventDefault();
                viewport.scrollLeft = Math.max(0, Math.min(max, next));
            }, { passive: false, signal });

            this.$nextTick(() => {
                const divider = this.$refs.divider;
                if (divider && divider.offsetLeft > viewport.clientWidth - 48) {
                    // The divider near the middle, the row starting on a whole cube, never half of one.
                    const target = divider.offsetLeft - viewport.clientWidth / 2;
                    const cubes = [...viewport.querySelectorAll('.rv-cw')].map((cube) => cube.offsetLeft - viewport.firstElementChild.offsetLeft);
                    viewport.scrollLeft = Math.max(0, ...cubes.filter((left) => left <= target));
                }
                this.measure();
            });
        },

        destroy() {
            teardown.abort();
        },

        measure() {
            const viewport = this.$refs.viewport;
            const max = viewport.scrollWidth - viewport.clientWidth;
            this.left = viewport.scrollLeft > 2;
            this.right = viewport.scrollLeft < max - 2;
            const divider = this.$refs.divider;
            if (divider) {
                const x = divider.offsetLeft - viewport.scrollLeft;
                this.away = x < 0 || x > viewport.clientWidth;
            }
        },

        present() {
            const viewport = this.$refs.viewport;
            const divider = this.$refs.divider;
            if (!divider) return;
            const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            viewport.scrollTo({ left: divider.offsetLeft - viewport.clientWidth / 2, behavior: reduced ? 'auto' : 'smooth' });
        },
    };
}
