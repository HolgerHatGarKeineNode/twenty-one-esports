/**
 * The zap sponsors' wall of a tournament's prize pot
 * (resources/views/pages/tournaments/partials/prize-pool.blade.php, user
 * 2026-10-02: zappers are shown "mit ihren Nostr Avataren").
 *
 * A zapper who plays here comes with the picture the league already has. For
 * everyone else the server only knows the key: this reads their kind 0 from
 * the profile relays (`data-relays`) in one REQ and fills in the name and an
 * https picture. Nothing is sent back to the server and nothing is stored;
 * a relay that does not answer, or a profile without a picture, leaves the
 * generated picture and the shortened npub. Text goes in as text, never as
 * HTML.
 */
const WAIT_MS = 2500;
const MAX_NAME = 48;

function profileOf(event) {
    try {
        const content = JSON.parse(event.content);
        const name = [content.display_name, content.name].find((value) => typeof value === 'string' && value.trim() !== '');
        const picture = typeof content.picture === 'string' && /^https:\/\/[^\s"'<>]{1,2040}$/.test(content.picture) ? content.picture : null;

        return { name: name ? name.trim().slice(0, MAX_NAME) : null, picture };
    } catch {
        return { name: null, picture: null };
    }
}

export function potZappers() {
    return {
        async init() {
            const pubkeys = [...this.$el.querySelectorAll('[data-zapper-avatar]')]
                .map((img) => img.dataset.zapperAvatar)
                .filter((pubkey) => /^[0-9a-f]{64}$/.test(pubkey || ''));
            let relays = [];
            try {
                relays = JSON.parse(this.$el.dataset.relays || '[]');
            } catch {
                relays = [];
            }

            if (pubkeys.length === 0 || relays.length === 0) {
                return;
            }

            const { SimplePool } = await import('nostr-tools/pool');
            const pool = new SimplePool();
            try {
                const events = await Promise.race([
                    pool.querySync(relays, { kinds: [0], authors: pubkeys }, { maxWait: WAIT_MS }),
                    new Promise((resolve) => setTimeout(() => resolve([]), WAIT_MS + 500)),
                ]);
                const newest = new Map();
                for (const event of events) {
                    if (event.kind === 0 && pubkeys.includes(event.pubkey) && (! newest.has(event.pubkey) || event.created_at > newest.get(event.pubkey).created_at)) {
                        newest.set(event.pubkey, event);
                    }
                }
                for (const [pubkey, event] of newest) {
                    const { name, picture } = profileOf(event);
                    const img = this.$el.querySelector(`img[data-zapper-avatar="${pubkey}"]`);
                    const label = this.$el.querySelector(`[data-zapper-name="${pubkey}"]`);
                    if (name && label) {
                        label.textContent = name;
                    }
                    if (picture && img) {
                        const fallback = img.getAttribute('src');
                        img.onerror = () => {
                            img.onerror = null;
                            img.src = fallback;
                        };
                        img.src = picture;
                        img.alt = name ?? img.alt.replace(/, generated$/, '');
                    }
                }
            } catch (error) {
                console.warn('[pot] reading the zappers’ profiles failed:', error);
            } finally {
                try {
                    pool.close(relays);
                } catch {
                    // already closed
                }
            }
        },
    };
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('potZappers', potZappers);
});
