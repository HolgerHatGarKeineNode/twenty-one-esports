/**
 * Toast stack (Overlays.dc.html, "Live notifications").
 *
 * Any code can raise a toast with a window event:
 *   window.dispatchEvent(new CustomEvent('toast', { detail: { tone: 'challenge', title: '…' } }))
 * or from Livewire: $this->dispatch('toast', tone: 'confirmed', title: '…').
 *
 * detail: { tone: 'challenge'|'confirmed'|'success', title, text?, time?, action?: { label, href },
 *           countdown?: seconds, labels?: { opening, stay } }
 *
 * `countdown` (P5c, "Opponent found"): the toast counts down and then opens
 * `action.href` on its own; "Stay here" (or closing the toast) cancels it.
 * A counting toast does not time out on its own.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('toastStack', () => ({
        toasts: [],
        nextId: 1,
        lifetime: 8000,

        add(detail) {
            const toast = {
                id: this.nextId++,
                tone: ['challenge', 'confirmed', 'success'].includes(detail?.tone) ? detail.tone : 'success',
                title: detail?.title ?? '',
                text: detail?.text ?? '',
                time: detail?.time ?? '',
                action: detail?.action ?? null,
                timer: null,
                countdown: Number.isInteger(detail?.countdown) && detail?.action?.href ? detail.countdown : null,
                labels: detail?.labels ?? {},
                ticker: null,
            };
            this.toasts.push(toast);
            if (toast.countdown !== null) {
                this.count(toast.id);
            } else {
                this.arm(toast);
            }
        },

        count(id) {
            const toast = () => this.toasts.find((t) => t.id === id);
            const ticker = setInterval(() => {
                const current = toast();
                if (!current || current.countdown === null) {
                    clearInterval(ticker);

                    return;
                }
                current.countdown -= 1;
                if (current.countdown <= 0) {
                    clearInterval(ticker);
                    window.esportsAlerts?.leavingTo?.(current.action.href);
                    window.location.assign(current.action.href);
                }
            }, 1000);
            toast().ticker = ticker;
        },

        stay(toast) {
            clearInterval(toast.ticker);
            toast.countdown = null;
            this.arm(toast);
        },

        opening(toast) {
            return (toast.labels.opening ?? ':s').replace(':s', toast.countdown);
        },

        arm(toast) {
            if (toast.countdown !== null) return;
            clearTimeout(toast.timer);
            toast.timer = setTimeout(() => this.remove(toast.id), this.lifetime);
        },

        hold(toast) {
            clearTimeout(toast.timer);
        },

        remove(id) {
            this.toasts.filter((toast) => toast.id === id).forEach((toast) => clearInterval(toast.ticker));
            this.toasts = this.toasts.filter((toast) => toast.id !== id);
        },
    }));
});
