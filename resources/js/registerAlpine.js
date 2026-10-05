/**
 * Registers a page entry's Alpine components (the layout's `scripts`: push.js,
 * chess.js, matchRoom.js, ...) whether Alpine has started or not.
 *
 * On a full load the entry runs before Alpine starts, and `alpine:init` is still
 * ahead. Brought in by wire:navigate, the entry is injected into a window where
 * Alpine started long ago: `alpine:init` never fires again, and its components
 * stayed undefined (2026-10-05: the notification settings' push toggle,
 * "pushToggle is not defined"). Livewire starts the swapped-in page only after
 * the injected scripts have loaded, so registering at once is in time.
 */
export function registerAlpine(register) {
    if (typeof window === 'undefined') return;
    if (window.Alpine) {
        register(window.Alpine);

        return;
    }
    document.addEventListener('alpine:init', () => register(window.Alpine));
}
