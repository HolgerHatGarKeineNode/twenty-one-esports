/**
 * Who a moment waits for (user 2026-10-09). A seat counts as a human while a player plays it themselves: a seat a
 * bot took over (left, timeouts) plays as a bot. A table with two or more humans is multiplayer: there no big moment
 * waits for a click (one player would hold everyone's page), it moves on by itself once it was readable. A battle no
 * human fights in, on either side, runs without animation.
 */

/** @param {{userId?: number|null, bot?: boolean}|null|undefined} seat */
export const isHumanSeat = (seat) => !!seat && seat.userId !== null && seat.userId !== undefined && !seat.bot;

/** @param {Array<object>} seats */
export const isMultiplayer = (seats) => (seats ?? []).filter(isHumanSeat).length > 1;

/** The wait after a big moment was readable: short at a multiplayer table, longer elsewhere (a click ends it sooner). */
export const TAP_GRACE_MS = 6000;
export const MULTIPLAYER_GRACE_MS = 1500;
export const tapGraceMs = (seats) => (isMultiplayer(seats) ? MULTIPLAYER_GRACE_MS : TAP_GRACE_MS);

/**
 * Whether a moment moves on without a click: always at a multiplayer table; else for a spectator, and for a player
 * whose own turn clock already runs on the server.
 */
export const movesOnByItself = ({ seats, playing, myTurnRuns }) => isMultiplayer(seats) || !playing || myTurnRuns;

/** A battle between two non-human sides (a neutral territory has no seat): no animation. */
export const quietBattle = (seats, attacker, defender) => !isHumanSeat(seats?.[attacker]) && !isHumanSeat(defender === null || defender === undefined ? null : seats?.[defender]);

/**
 * How far a page may fall behind the server (user 2026-10-09: every browser at the table in sync). The table's
 * events reach every page at once over Reverb; each page shows them at its own pace, so a page that is more than
 * MAX_LAG_MS behind lands the rest at once. A replay plays at its own pace and never lags.
 */
export const MAX_LAG_MS = 5000;
export const lagging = (arrivedAt, now, replay = false) => !replay && arrivedAt !== undefined && now - arrivedAt > MAX_LAG_MS;
