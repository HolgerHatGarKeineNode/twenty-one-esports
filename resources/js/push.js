/*
 * Browser push subscription for this browser (ChessSettings "Reminders by
 * browser push · allowed in this browser"): asks for permission, registers
 * the service worker (/sw.js), subscribes with the server's VAPID public key
 * and hands the subscription to Livewire. Turning it off unsubscribes this
 * browser only; other devices keep theirs.
 */

import { registerAlpine } from './registerAlpine.js';

function keyBytes(base64url) {
    const padded = base64url.replace(/-/g, '+').replace(/_/g, '/') + '='.repeat((4 - (base64url.length % 4)) % 4);

    return Uint8Array.from(atob(padded), (c) => c.charCodeAt(0));
}

export function pushSupported() {
    return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
}

export function pushToggle(config) {
    return {
        on: config.on,
        supported: pushSupported() && !!config.vapidKey,
        permission: 'Notification' in window ? Notification.permission : 'denied',
        here: false,
        busy: false,
        error: '',
        t: config.labels,

        async init() {
            if (!this.supported) return;
            const registration = await navigator.serviceWorker.getRegistration('/');
            this.here = !!(await registration?.pushManager.getSubscription());
        },

        get hint() {
            if (!config.vapidKey) return this.t.notConfigured;
            if (!pushSupported()) return this.t.unsupported;
            if (this.permission === 'denied') return this.t.denied;

            return this.here ? this.t.allowed : this.t.notHere;
        },

        async toggle() {
            if (this.busy) return;
            this.busy = true;
            this.error = '';
            try {
                if (this.on) {
                    await this.disable();
                } else {
                    await this.enable();
                }
            } catch {
                this.error = this.t.failed;
            } finally {
                this.busy = false;
            }
        },

        async enable() {
            if (!this.supported) return;
            this.permission = await Notification.requestPermission();
            if (this.permission !== 'granted') return;

            const registration = await navigator.serviceWorker.register('/sw.js', { scope: '/' });
            await navigator.serviceWorker.ready;
            const subscription = (await registration.pushManager.getSubscription())
                ?? (await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(config.vapidKey) }));

            // Null when saved; otherwise the reason the server refused it (an endpoint not on a public host).
            const refused = await this.$wire.savePushSubscription(JSON.stringify(subscription.toJSON()));
            if (refused) {
                await subscription.unsubscribe();
                this.here = false;
                this.error = refused;
                return;
            }
            this.here = true;
            this.on = true;
        },

        async disable() {
            const registration = this.supported ? await navigator.serviceWorker.getRegistration('/') : null;
            const subscription = await registration?.pushManager.getSubscription();
            if (subscription) {
                await this.$wire.removePushSubscription(subscription.endpoint);
                await subscription.unsubscribe();
            }
            await this.$wire.setPush(false);
            this.here = false;
            this.on = false;
        },
    };
}

registerAlpine(() => {
    window.Alpine.data('pushToggle', pushToggle);
});
