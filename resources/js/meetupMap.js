/**
 * The meetup map on /clans (pages/clans/⚡index): each dot stays on its city, and each label takes
 * the first place beside its dot that covers no other dot or label and stays inside the map (user,
 * 2026-10-07: "DAR Darmstadt" covered "Aschaffenburg" next door). Greedy, in the server's order:
 * right, left, then above and below on either side; if nothing is free, the place with the least
 * overlap. Without script every label sits to the right, as before.
 */
const GAP = 8;

const PLACES = [
    (dot, _w, h) => ({ x: dot.right + GAP, y: dot.cy - h / 2 }),
    (dot, w, h) => ({ x: dot.left - GAP - w, y: dot.cy - h / 2 }),
    (dot, _w, h) => ({ x: dot.right + 2, y: dot.top - h - 2 }),
    (dot) => ({ x: dot.right + 2, y: dot.bottom + 2 }),
    (dot, w, h) => ({ x: dot.left - 2 - w, y: dot.top - h - 2 }),
    (dot, w) => ({ x: dot.left - 2 - w, y: dot.bottom + 2 }),
];

function overlap(a, b) {
    const w = Math.min(a.x + a.w, b.x + b.w) - Math.max(a.x, b.x);
    const h = Math.min(a.y + a.h, b.y + b.h) - Math.max(a.y, b.y);

    return w > 0 && h > 0 ? w * h : 0;
}

export function placeLabels(map) {
    const box = map.getBoundingClientRect();
    const pins = [...map.querySelectorAll('[data-pin]')];
    const taken = pins.map((pin) => {
        const r = pin.querySelector('[data-pin-dot]').getBoundingClientRect();

        return { x: r.left - box.left - 4, y: r.top - box.top - 4, w: r.width + 8, h: r.height + 8 };
    });

    // The placeholder note in the corner counts as taken too, once all dots are in.
    const note = map.querySelector('[data-map-note]');
    const noteRect = note && note.offsetWidth > 0 ? note.getBoundingClientRect() : null;
    const blocked = noteRect ? [{ x: noteRect.left - box.left, y: noteRect.top - box.top, w: noteRect.width, h: noteRect.height }] : [];

    pins.forEach((pin, index) => {
        const label = pin.querySelector('[data-pin-label]');
        const own = taken[index];
        const dot = { left: own.x + 4, right: own.x + own.w - 4, top: own.y + 4, bottom: own.y + own.h - 4, cy: own.y + own.h / 2 };
        const w = label.offsetWidth;
        const h = label.offsetHeight;
        let best = null;

        for (const place of PLACES) {
            const { x, y } = place(dot, w, h);
            const rect = { x, y, w, h };
            const outside = Math.max(0, -x) * h + Math.max(0, x + w - box.width) * h + Math.max(0, -y) * w + Math.max(0, y + h - box.height) * w;
            const cost = taken.reduce((sum, other, i) => sum + (i === index ? 0 : overlap(rect, other)), 0) + blocked.reduce((sum, other) => sum + overlap(rect, other), 0) + outside * 4;

            if (best === null || cost < best.cost) best = { rect, cost };
            if (cost === 0) break;
        }

        label.style.left = `${best.rect.x - dot.left}px`;
        label.style.top = `${best.rect.y - dot.top}px`;
        label.dataset.placed = '1';
        taken.push(best.rect);
    });
}

export default function meetupMap() {
    return {
        observer: null,

        init() {
            const place = () => placeLabels(this.$el);
            this.$nextTick(place);
            this.observer = new ResizeObserver(place);
            this.observer.observe(this.$el);
        },

        destroy() {
            this.observer?.disconnect();
        },
    };
}
