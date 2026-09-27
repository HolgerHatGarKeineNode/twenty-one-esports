/**
 * nostr-mill 1.7.0 wrapper, ported from einundzwanzig-portal.
 *
 * Mill is loaded on demand so window.nostr stays untouched until a successful
 * connect; otherwise a NIP-07 extension would be hidden. Failed NIP-46 state
 * is dropped so a hanging bunker cannot block the next attempt.
 *
 * Only two ways in: Google (pomegranate, a FROST-sharded NIP-46 bunker) and a
 * Nostr signer (NIP-07 extension, else NIP-46). No Lightning, no own accounts.
 *
 * A NIP-46 pairing is kept for the next pages (restoreSigner): the client key
 * the bunker authorised, the bunker's pubkey and relays, and the player's
 * pubkey, in localStorage under SESSION_KEY. A later page reopens the relay
 * subscription with the SAME client key and sends no second `connect`: a
 * bunker:// secret is single-use (NIP-46), so re-pairing with it fails, and
 * Amber would ask for a new bunker every time. The client key is a transport
 * key, not the player's nsec; it never leaves the browser (never sent to the
 * server, never logged). The session is bound to the logged-in pubkey
 * (<meta name="nostr-session">) and removed on logout, on a new pairing, when
 * another player is logged in, and when the bunker says this client is no
 * longer authorised.
 */

import { REVOKED, SignerError } from './signing.js';

const BUNKER_STORAGE_KEY = 'mill:nip46:state';
const SESSION_KEY = 'esports:nip46:session';
const CONNECT_TIMEOUT_MS = 90_000;
const HEX64 = /^[0-9a-f]{64}$/;
// What a bunker answers a client key it does not (or no longer) know. Amber's
// own wording is not documented; a bunker that stays silent instead ends in a
// timeout, which reads as "did not answer" and keeps the session.
const REVOKED_PATTERN = /unauthori[sz]ed|not authori[sz]ed|revoked|unknown client/i;

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
 * Forget a remote signer that mill installed, and its stored session. Also the
 * right call on logout, so the next person on this browser does not inherit
 * the bunker session.
 */
export function dropFailedBunker() {
    try {
        sessionStorage.removeItem(BUNKER_STORAGE_KEY);
    } catch {
        // private mode / disabled storage
    }
    try {
        localStorage.removeItem(SESSION_KEY);
    } catch {
        // private mode / disabled storage
    }

    activeClient?.stop();
    activeClient = null;
    restoring = null;

    if (window.__millWindowNostr) {
        try {
            delete window.nostr;
        } catch {
            window.nostr = undefined;
        }
        window.__millWindowNostr = false;
    }
}

/* ---------- The NIP-46 session across pages ---------------------------------------------------------------- */

let activeClient = null;
let restoring = null;

/**
 * The logged-in player's pubkey from <meta name="nostr-session">: a pubkey, ''
 * for a guest, or null where the page does not say (no session on it, such as
 * an error page), in which case nothing is decided.
 */
function sessionPubkey() {
    const content = document.querySelector('meta[name="nostr-session"]')?.getAttribute('content');

    return typeof content === 'string' ? content.toLowerCase() : null;
}

function validSession(value) {
    return value !== null
        && typeof value === 'object'
        && HEX64.test(value.clientSecretKey ?? '')
        && HEX64.test(value.remotePubkey ?? '')
        && HEX64.test(value.userPubkey ?? '')
        && Array.isArray(value.relays)
        && value.relays.length > 0
        && value.relays.every((url) => typeof url === 'string' && /^wss?:\/\//.test(url));
}

function readSession() {
    try {
        const value = JSON.parse(localStorage.getItem(SESSION_KEY) ?? 'null');

        return validSession(value) ? value : null;
    } catch {
        return null;
    }
}

/**
 * Copy the pairing mill just stored for this tab (sessionStorage) into the
 * lasting session. Returns it, or null when there is none.
 */
function keepPairing() {
    try {
        const state = JSON.parse(sessionStorage.getItem(BUNKER_STORAGE_KEY) ?? 'null');
        const session = state && {
            clientSecretKey: String(state.clientSecretKey ?? '').toLowerCase(),
            remotePubkey: String(state.remotePubkey ?? '').toLowerCase(),
            relays: state.relays,
            userPubkey: String(state.userPubkey ?? '').toLowerCase(),
        };
        if (!validSession(session)) {
            return null;
        }
        localStorage.setItem(SESSION_KEY, JSON.stringify(session));

        return session;
    } catch {
        return null;
    }
}

function hexToBytes(hex) {
    const bytes = new Uint8Array(hex.length / 2);
    for (let i = 0; i < bytes.length; i++) {
        bytes[i] = parseInt(hex.slice(i * 2, i * 2 + 2), 16);
    }

    return bytes;
}

/**
 * mill's NIP-46 client opens one relay subscription and never reopens it: a
 * relay that drops (a phone that locks, a tab in the background, a relay
 * restart) leaves every later answer unheard until the request times out.
 * This reopens the subscription before the next request, with the same client
 * key, when the old one was closed, when a request timed out, or when the tab
 * comes back to the foreground with nothing in flight.
 */
function supervise(client) {
    let dead = false;
    let ignoreClose = false;

    client.onLog = (entry) => {
        if (!ignoreClose && entry?.level === 'err' && String(entry.msg).startsWith('Subscription closed')) {
            dead = true;
        }
    };

    const reopen = () => {
        const pool = client.pool;
        ignoreClose = true;
        try {
            pool?.destroy?.();
        } catch {
            // already gone
        } finally {
            ignoreClose = false;
        }
        client.pool = null;
        client.sub = null;
        dead = false;
        client._openPool();
    };

    const request = client._request.bind(client);
    client._request = async (method, params, options) => {
        if (dead && !client._closed) {
            reopen();
        }
        try {
            return await request(method, params, options);
        } catch (error) {
            if (/timed out|failed to publish/i.test(String(error?.message ?? ''))) {
                dead = true;
            }
            throw error;
        }
    };

    const wake = () => {
        if (document.visibilityState === 'visible' && client.pending.size === 0) {
            dead = true;
        }
    };
    document.addEventListener('visibilitychange', wake);
    window.addEventListener('online', wake);

    return {
        client,
        stop() {
            document.removeEventListener('visibilitychange', wake);
            window.removeEventListener('online', wake);
            try {
                client.disconnect();
            } catch {
                // already closed
            }
        },
    };
}

/**
 * window.nostr over a restored client. A bunker that no longer knows this
 * client key ends the session: forgotten here, and a SignerError(REVOKED) so
 * the page says so and the next attempt pairs anew instead of failing again.
 */
function installSigner(client, userPubkey) {
    const call = (fn) => async (...args) => {
        try {
            return await fn(...args);
        } catch (error) {
            if (REVOKED_PATTERN.test(String(error?.message ?? error))) {
                console.warn('[signer] the remote signer no longer accepts this browser; the stored session is removed.');
                dropFailedBunker();
                throw new SignerError(REVOKED, error);
            }
            throw error;
        }
    };

    window.nostr = {
        getPublicKey: async () => userPubkey,
        signEvent: call((event) => client.signEvent(event)),
        nip04: {
            encrypt: call((pubkey, text) => client.nip04Encrypt(pubkey, text)),
            decrypt: call((pubkey, text) => client.nip04Decrypt(pubkey, text)),
        },
        nip44: {
            encrypt: call((pubkey, text) => client.nip44Encrypt(pubkey, text)),
            decrypt: call((pubkey, text) => client.nip44Decrypt(pubkey, text)),
        },
    };
    window.__millWindowNostr = true;
}

async function startSession(session) {
    const MILL = await loadMill();
    const client = new MILL.NIP46Client({ relays: session.relays, clientSecretKey: hexToBytes(session.clientSecretKey) });
    const supervised = supervise(client);
    // No `connect`: the bunker authorised this client key when it was paired.
    await client.restore({ remotePubkey: session.remotePubkey, relays: session.relays, userPubkey: session.userPubkey });
    activeClient?.stop();
    activeClient = supervised;
    installSigner(client, session.userPubkey);
}

/**
 * Put the stored NIP-46 session back as window.nostr, without any dialog.
 * Resolves true when a signer is there afterwards. Never pairs anew.
 */
export function restoreSigner() {
    if (typeof window.nostr?.signEvent === 'function') {
        return Promise.resolve(true);
    }

    restoring ??= (async () => {
        const session = readSession();
        const pubkey = sessionPubkey();
        if (session === null || !pubkey || session.userPubkey !== pubkey) {
            return false;
        }
        try {
            await startSession(session);

            return true;
        } catch (error) {
            console.warn('[signer] restoring the remote signer failed:', error?.message ?? error);

            return false;
        }
    })().finally(() => {
        restoring = null;
    });

    return restoring;
}

/**
 * On every page: a stored session that is not the logged-in player's goes
 * (logged out elsewhere, the session expired, another player logged in).
 */
export function forgetForeignSession() {
    const pubkey = sessionPubkey();
    if (pubkey === null) {
        return;
    }
    let stored = null;
    try {
        stored = localStorage.getItem(SESSION_KEY);
    } catch {
        return;
    }
    if (stored === null) {
        return;
    }
    const session = readSession();
    if (session === null || session.userPubkey !== pubkey) {
        dropFailedBunker();
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
        let connected = false;
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
            onConnected: async (result) => {
                if (settled || connected) {
                    return;
                }
                connected = true;
                clearTimeout(timer);

                // A NIP-46 pairing (a bunker, or Google's pomegranate bunker) is kept
                // for later pages and served by the supervised client from here on,
                // the same one a later page restores.
                const session = ['nip46', 'pomegranate'].includes(result?.method) ? keepPairing() : null;
                if (session !== null) {
                    try {
                        await startSession(session);
                        result.signer?.disconnect?.();
                        finish(resolve, result);

                        return;
                    } catch (error) {
                        console.warn('[signer] keeping the remote signer session failed:', error?.message ?? error);
                    }
                }

                if (result?.signer && typeof MILL.installAsWindowNostr === 'function') {
                    MILL.installAsWindowNostr(result.signer);
                    window.__millWindowNostr = true;
                }
                finish(resolve, result);
            },
            onClose: () => {
                if (! connected) {
                    finish(reject, new Error('cancelled'));
                }
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
