/**
 * Moves in the reader's language: German uses K D T L S for king, queen, rook,
 * bishop and knight (`Lg2` for `Bg2`). Stored moves, PGN and Nostr events stay
 * in English SAN. App\Support\Chess\SanNotation is the server twin.
 */
const LETTERS = { de: { K: 'K', Q: 'D', R: 'T', B: 'L', N: 'S' } };
// German letters back to English. D, T, L and S are no English piece letters,
// so typing either language works on any page.
const TO_ENGLISH = { D: 'Q', T: 'R', L: 'B', S: 'N' };

function pageLocale() {
    return typeof document !== 'undefined' ? (document.documentElement.lang || 'en') : 'en';
}

/**
 * @param {string} san English SAN, e.g. `Bg2`, `exd8=Q+`
 * @param {string} [locale] defaults to the page's `<html lang>`
 */
export function displaySan(san, locale = pageLocale()) {
    const letters = LETTERS[(locale || '').slice(0, 2)];
    if (!san || !letters) return san;

    return san.replace(/^[KQRBN]|=[QRBN]/, (piece) => piece.replace(/[KQRBN]/, (ch) => letters[ch]));
}

/**
 * Typed move to English SAN: accepts `Lg2` as well as `Bg2`, `e8=D` as `e8=Q`.
 *
 * @param {string} text
 */
export function inputSan(text) {
    return (text || '').trim().replace(/^[DTLS]|=[DTLS]/, (piece) => piece.replace(/[DTLS]/, (ch) => TO_ENGLISH[ch]));
}

if (typeof window !== 'undefined') {
    window.displaySan = displaySan;
}
