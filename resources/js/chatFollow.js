/**
 * Keeps a chat's message list at its newest line (the game chat, pages/games/partials/chat-body): when the list
 * first shows (the mobile sheet opens), when it changes size and when a message comes in, unless the reader
 * scrolled up to read older ones. Scrolling back down to the end follows again; the reader's own message always
 * brings the list to the end.
 *
 * The list used to sit at the bottom by `justify-content: flex-end` on the scroll container. That pushed every
 * line that did not fit above the top edge, where no scrolling reaches (2026-11-27, Pixel 6a: the chat history
 * did not scroll up). The list now starts at the top like any scroll box, a `margin-top: auto` before the first
 * line keeps a short chat at the bottom, and this scrolls a long one to its end.
 *
 * @param {HTMLElement} list the scroll container
 * @param {{ Observer?: typeof ResizeObserver }} [options] a seam for tests/js/chatFollow.test.mjs
 * @returns {{ update: (force?: boolean) => void, disconnect: () => void }}
 */
export function followEnd(list, { Observer = globalThis.ResizeObserver } = {}) {
    /** Within this many pixels of the end the list counts as at the end (a fraction of a line, rounding on phones). */
    const SLACK = 32;
    let pinned = true;
    let height = list.clientHeight;

    const toEnd = () => {
        list.scrollTop = list.scrollHeight;
    };
    const onScroll = () => {
        pinned = list.scrollHeight - list.scrollTop - list.clientHeight <= SLACK;
    };
    list.addEventListener('scroll', onScroll, { passive: true });

    // A list that was hidden (the closed sheet: display none, height 0) opens at the newest line, wherever it was left.
    const observer = Observer ? new Observer(() => {
        const shown = height === 0 && list.clientHeight > 0;
        height = list.clientHeight;
        if (shown) pinned = true;
        if (pinned) toEnd();
    }) : null;
    observer?.observe(list);

    return {
        update(force = false) {
            if (force) pinned = true;
            if (pinned) toEnd();
        },
        disconnect() {
            list.removeEventListener('scroll', onScroll);
            observer?.disconnect();
        },
    };
}
