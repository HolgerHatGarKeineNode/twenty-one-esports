/**
 * Toast stack (Overlays.dc.html, "Live notifications").
 *
 * Any code can raise a toast with a window event:
 *   window.dispatchEvent(new CustomEvent('toast', { detail: { tone: 'challenge', title: '…' } }))
 * or from Livewire: $this->dispatch('toast', tone: 'confirmed', title: '…').
 *
 * detail: { tone: 'challenge'|'confirmed'|'success', title, text?, time?, action?: { label, href } }
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
            };
            this.toasts.push(toast);
            this.arm(toast);
        },

        arm(toast) {
            clearTimeout(toast.timer);
            toast.timer = setTimeout(() => this.remove(toast.id), this.lifetime);
        },

        hold(toast) {
            clearTimeout(toast.timer);
        },

        remove(id) {
            this.toasts = this.toasts.filter((toast) => toast.id !== id);
        },
    }));
});
