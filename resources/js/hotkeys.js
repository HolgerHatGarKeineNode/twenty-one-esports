/**
 * Board shortcuts promised on the chess settings page (Keyboard card):
 * F flips the board, Esc clears the selection or the promotion picker,
 * ← / → step through the moves, Home / End jump to the start and back to the
 * current position (every board, P55), Q R B N pick the promotion piece.
 *
 * Returns the normalised key, or null when the key belongs to something else:
 * typing in a field (the SAN input, the chat), or a chord with a modifier.
 */
export function boardKey(event) {
    if (event.defaultPrevented || event.ctrlKey || event.metaKey || event.altKey) return null;
    const target = event.target;
    if (target instanceof HTMLElement && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName))) return null;

    if (['Escape', 'ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return event.key;

    const key = event.key.toLowerCase();

    return ['f', 'q', 'r', 'b', 'n'].includes(key) ? key : null;
}
