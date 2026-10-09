/**
 * Proof of Pong's cast in the browser (plan "Proof of Pong", P3): resources/js/pong/cast.json, the one source the
 * server reads as well (App\Support\Pong\PongCast). A figure's name and tagline are English keys; the page's config
 * carries their translations (`castTexts`), so `label(figure, t)` shows them in the viewer's language.
 */
import CAST from './cast.json';

export const ART = '/pong/art/';
export const PLAYERS = CAST.players;
export const BOTS = CAST.bots;
export const TICKER = CAST.ticker;
export const COMMENTATOR = CAST.commentator;
export const ARENAS = CAST.arenas;

const BY_ID = Object.fromEntries([...PLAYERS, ...BOTS].map((figure) => [figure.id, figure]));

/** The fallback figures when a side has picked none: the player's and the opponent's. */
export const DEFAULTS = ['turm', 'saylor'];

/** A figure by id; an unknown id falls back to the side's default. */
export function figure(id, side = 0) {
    return BY_ID[id] ?? BY_ID[DEFAULTS[side] ?? DEFAULTS[0]];
}

export const portrait = (f) => `${ART}por-${f.id}.webp`;
export const pose = (f) => `${ART}win-${f.id}.webp`;
export const arenaPlate = (name) => `${ART}arena-${ARENAS.includes(name) ? name : 'studio'}.webp`;
export const eventIcon = (event) => `${ART}ev-${event}.webp`;

/** One of a list, the same for the same seed (a clip or a ticker line that does not jump between renders). */
export const oneOf = (list, seed) => (list.length ? list[Math.abs(Math.floor(seed)) % list.length] : null);

/** A figure's neon: its first colour, or its second when the first is too grey or dark to glow (HSL saturation, lightness). */
export function neon(f) {
    const [r, g, b] = [1, 3, 5].map((i) => parseInt(f.skin[0].slice(i, i + 2), 16) / 255);
    const max = Math.max(r, g, b);
    const min = Math.min(r, g, b);
    const l = (max + min) / 2;
    const s = max === min ? 0 : (max - min) / (1 - Math.abs(2 * l - 1));

    return s < 0.35 || l < 0.18 ? f.skin[1] : f.skin[0];
}
