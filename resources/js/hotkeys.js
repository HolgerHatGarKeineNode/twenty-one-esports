/**
 * Board shortcuts promised on the chess settings page (Keyboard card):
 * F flips the board, Esc clears the selection or the promotion picker,
 * ← / → step through a replay, Q R B N pick the promotion piece.
 *
 * Returns the normalised key, or null when the key belongs to something else:
 * typing in a field (the SAN input, the chat), or a chord with a modifier.
 */
export function boardKey(event) {
    if (event.defaultPrevented || event.ctrlKey || event.metaKey || event.altKey) return null;
    const target = event.target;
    if (target instanceof HTMLElement && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName))) return null;

    if (event.key === 'Escape' || event.key === 'ArrowLeft' || event.key === 'ArrowRight') return event.key;

    const key = event.key.toLowerCase();

    return ['f', 'q', 'r', 'b', 'n'].includes(key) ? key : null;
}
