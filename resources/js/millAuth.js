/**
 * nostr-mill 1.7.0 wrapper, ported from einundzwanzig-portal.
 *
 * Mill is loaded on demand so window.nostr stays untouched until a successful
 * connect; otherwise a NIP-07 extension would be hidden. Failed NIP-46 state
 * is dropped so a hanging bunker cannot block the next attempt.
 *
 * Only two ways in: Google (pomegranate, a FROST-sharded NIP-46 bunker) and a
 * Nostr signer (NIP-07 extension, else NIP-46). No Lightning, no own accounts.
 */

const BUNKER_STORAGE_KEY = 'mill:nip46:state';
const CONNECT_TIMEOUT_MS = 90_000;

const MILL_CHROME = {
    appName: 'TWENTY ONE Esports',
    theme: {
        '--mill-bg': '#09090b',
        '--mill-surface': '#18181b',
        '--mill-card': '#27272a',
        '--mill-card-hover': '#3f3f46',
        '--mill-inset': 'rgba(0,0,0,0.25)',
        '--mill-inset-strong': 'rgba(0,0,0,0.4)',
        '--mill-overlay': 'rgba(9,9,11,0.72)',
        '--mill-border': '#3f3f46',
        '--mill-border-light': '#52525b',
        '--mill-accent': '#f7931a',
        '--mill-accent-hover': '#fbbf24',
        '--mill-accent-dim': 'rgba(247,147,26,0.13)',
        '--mill-teal': '#a1a1aa',
        '--mill-teal-dim': 'rgba(161,161,170,0.13)',
        '--mill-text': '#fafafa',
        '--mill-text-secondary': '#a1a1aa',
        '--mill-muted': '#71717a',
        '--mill-danger': '#f87171',
        '--mill-danger-dim': 'rgba(248,113,113,0.13)',
        '--mill-warning': '#fbbf24',
        '--mill-warning-dim': 'rgba(251,191,36,0.13)',
        '--mill-success': '#4ade80',
        '--mill-success-dim': 'rgba(74,222,128,0.13)',
        '--mill-radius': '8px',
        '--mill-border-width': '1px',
        '--mill-border-style': 'solid',
        '--mill-shadow': '0 25px 50px -12px rgba(0,0,0,0.7)',
        '--mill-font': 'ui-sans-serif, system-ui, sans-serif',
        '--mill-font-mono': 'ui-monospace, monospace',
    },
    header: {
        title: 'TWENTY ONE Esports',
        message: '',
        align: 'center',
        label: '',
    },
    tip: false,
    footer: { attribution: false },
};

let millModulePromise = null;

function loadMill() {
    if (! millModulePromise) {
        millModulePromise = import('./vendor/nostr-mill/mill.umd.js').then(() => {
            if (! window.MILL) {
                throw new Error('MILL failed to load');
            }

            return window.MILL;
        }).catch((error) => {
            millModulePromise = null;
            throw error;
        });
    }

    return millModulePromise;
}

export function hasNostrExtension() {
    if (window.__millWindowNostr) {
        return false;
    }

    return typeof window.nostr?.signEvent === 'function';
}

/**
 * Forget a remote signer that mill installed. Also the right call on logout,
 * so the next person on this browser does not inherit the bunker session.
 */
export function dropFailedBunker() {
    try {
        sessionStorage.removeItem(BUNKER_STORAGE_KEY);
    } catch {
        // private mode / disabled storage
    }

    if (window.__millWindowNostr) {
        try {
            delete window.nostr;
        } catch {
            window.nostr = undefined;
        }
        window.__millWindowNostr = false;
    }
}

/**
 * Open mill for the given methods and resolve once a signer is connected and
 * installed as window.nostr. Rejects with 'cancelled' or 'timeout'.
 *
 * @param {{ methods: string[], pomegranate?: boolean }} options
 */
export async function connectMill(options) {
    const methods = options.methods;
    const pomegranate = options.pomegranate === true;

    if (methods.includes('nip46') || pomegranate) {
        dropFailedBunker();
    }

    const MILL = await loadMill();

    return new Promise((resolve, reject) => {
        let settled = false;
        let timer;

        const finish = (fn, value) => {
            if (settled) {
                return false;
            }
            settled = true;
            clearTimeout(timer);
            fn(value);

            return true;
        };

        timer = setTimeout(() => {
            if (settled) {
                return;
            }
            settled = true;
            if (typeof MILL.close === 'function') {
                MILL.close();
            }
            dropFailedBunker();
            reject(new Error('timeout'));
        }, CONNECT_TIMEOUT_MS);

        const openOptions = {
            ...MILL_CHROME,
            methods,
            onConnected: (result) => {
                if (! finish(resolve, result)) {
                    return;
                }
                if (result?.signer && typeof MILL.installAsWindowNostr === 'function') {
                    MILL.installAsWindowNostr(result.signer);
                    window.__millWindowNostr = true;
                }
            },
            onClose: () => {
                finish(reject, new Error('cancelled'));
            },
        };

        if (pomegranate) {
            openOptions.pomegranate = true;
        }

        const el = MILL.open(openOptions);
        // mill 1.7.0 open() always starts with _state.method = null and has no
        // startScreen option; skip the one-card picker.
        if (methods.length === 1 && el?._state) {
            el._state.method = methods[0];
            el._render?.();
        }
    });
}
