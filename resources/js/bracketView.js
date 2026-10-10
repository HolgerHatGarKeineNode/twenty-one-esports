/**
 * bracketView(config) — the "2D / 3D" switch of a tournament's bracket (plan "OBS-Broadcast-Overlays", P6), on the
 * bracket section of pages::tournaments.show. 2D (the page's own bracket) is the default and costs nothing extra; 3D
 * loads three.js and the 3D view (resources/js/broadcast/bracket/page.js) only once a viewer picks it.
 *
 * The choice is remembered per viewer (localStorage `bracket-view`, in try/catch: a blocked storage just forgets).
 * A remembered 3D is applied before the first paint by the section's inline script (data-bracket-view on the
 * section, so the page does not jump from 2D to 3D after load); here it only starts the view. Without WebGL the 3D
 * button turns disabled with a hint once picked, and a remembered 3D falls back to 2D.
 *
 * config: { url, id, words, art, three }.
 */

const KEY = 'bracket-view';

export function webglWorks() {
    try {
        const c = document.createElement('canvas');

        return !!(window.WebGLRenderingContext && (c.getContext('webgl2') || c.getContext('webgl')));
    } catch {
        return false;
    }
}

function loadThree(src) {
    if (window.THREE) return Promise.resolve();
    if (!window.__threeLoading) {
        window.__threeLoading = new Promise((resolve, reject) => {
            const s = document.createElement('script');
            s.src = src;
            s.onload = () => resolve();
            s.onerror = () => reject(new Error('three.js did not load'));
            document.head.append(s);
        });
    }

    return window.__threeLoading;
}

export default function bracketView(config) {
    return {
        view: '2d',
        webgl: true,
        state: 'idle',
        page: null,
        api: null,

        init() {
            const section = this.$root;
            let remembered = '2d';
            try {
                remembered = localStorage.getItem(KEY) === '3d' ? '3d' : '2d';
            } catch {
                remembered = '2d';
            }
            // WebGL is asked for only when 3D is wanted: a 2D viewer's page opens no GL context.
            this.webgl = remembered === '3d' ? webglWorks() : true;
            this.view = this.webgl ? remembered : '2d';
            section.dataset.bracketView = this.view;
            if (this.view === '3d') this.start();
        },

        choose(view) {
            if (view === '3d') this.webgl = this.webgl && webglWorks();
            if (view === '3d' && !this.webgl) return;
            this.view = view;
            this.$root.dataset.bracketView = view;
            try {
                localStorage.setItem(KEY, view);
            } catch {
                // Storage blocked: the choice lasts for this page.
            }
            if (view === '3d') this.start();
            else if (this.api) this.api.pause();
        },

        async start() {
            if (this.api) {
                this.api.resume();

                return;
            }
            if (this.state === 'loading') return;
            this.state = 'loading';
            try {
                await loadThree(config.three);
                const { mountBracket3d } = await import('./broadcast/bracket/page.js');
                const host = this.$root.querySelector('[data-bracket-3d]');
                this.api = await mountBracket3d(host, { url: config.url, tournamentId: config.id, words: config.words, art: config.art, onPage: (p) => { this.page = p; } });
                this.state = 'ready';
            } catch (e) {
                // No 3D after all (a lost context, a failed load): back to the bracket that always works.
                this.state = 'failed';
                this.view = '2d';
                this.$root.dataset.bracketView = '2d';
                console.warn('3D bracket unavailable', e);
            }
        },

        prev() { this.api?.prev(); },
        next() { this.api?.next(); },
    };
}

if (typeof document !== 'undefined') {
    document.addEventListener('alpine:init', () => {
        window.Alpine.data('bracketView', bracketView);
    });
}
