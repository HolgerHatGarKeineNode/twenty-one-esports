/**
 * wire:navigate only between the shell's navigable pages (performance plan P6b,
 * App\Support\Navigation\Navigate). Such a page carries `data-navigate-page` on
 * <html>; every other one (a game, a board, a match room, a lobby, a chat) loads
 * in full, in both directions:
 *
 * - out of it: a navigate that starts on a page without the mark (a link, the
 *   back button) becomes a plain page load of its target, so the page's live
 *   parts (presence on `game.<id>.players`, game channels, intervals) end with
 *   the document instead of staying behind in the kept window;
 * - into it: when the swapped-in page has no mark (a redirect, a link the server
 *   should not have marked), Alpine and Livewire never start on it and the
 *   browser loads it again in full. This runs at the swap, before the new page's
 *   components start, so nothing joins a channel for a moment.
 *
 * The server only puts wire:navigate on links between marked pages, so both
 * branches are the safety net, not the normal path.
 */

const MARK = 'data-navigate-page';

function navigable() {
    return document.documentElement.hasAttribute(MARK);
}

document.addEventListener('livewire:navigate', (event) => {
    if (navigable()) return;
    event.preventDefault();
    // The back and forward buttons already changed the address: load it.
    if (event.detail?.history) {
        window.location.reload();
    } else {
        window.location.assign(String(event.detail?.url ?? window.location.href));
    }
});

document.addEventListener('livewire:navigating', (event) => {
    event.detail?.onSwap?.(() => {
        if (navigable()) return;
        // Alpine's initTree skips a tree under an ignored element, and Livewire starts its components from there.
        document.body._x_ignore = true;
        window.location.reload();
    });
});
