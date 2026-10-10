/**
 * A meme event's takeover of the field (show.js announce()), as numbers and texts that need no page: shared by the
 * game against a bot, the live match and tests/js/pongPhysics.test.mjs.
 *
 * The takeover lasts the announcement (ANNOUNCE_TICKS in physics.js, the referee's PongPhysics::ANNOUNCE_TICKS): it
 * comes in for TAKEOVER_INTRO_MS, holds still, and goes out for TAKEOVER_OUTRO_MS (plus TAKEOVER_SLACK_MS before the end).
 * The hold in between is at least the
 * reading time of the longest name and line in German and English (readingMs(): 1.5 s plus 0.3 s a word, never under
 * 4 s), checked by the Node test against lang/de.json. After it, the event stays pinned at the field's edge for its
 * rally (show.js pin()).
 */
export const TAKEOVER_INTRO_MS = 600;
export const TAKEOVER_OUTRO_MS = 400;
/** The outro starts this much early: the game's tick clock may end the announcement up to a frame before the timer. */
export const TAKEOVER_SLACK_MS = 100;

/** The words of a text: runs between spaces that hold a letter or digit (a lone dash is no word). */
export const wordsOf = (text) => text.split(/\s+/).filter((word) => /[\p{L}\p{N}]/u.test(word)).length;

/** How long a text must hold still to be read: 1.5 s plus 0.3 s a word, at least 4 s. */
export const readingMs = (text) => Math.max(4000, 1500 + 300 * wordsOf(text));

/** A meme event's name and line in the page's language (literal keys, so the page's text test finds them). */
export const eventText = (t, event) => ({
    halving: [t('Halving'), t('The ball is half the size, its point counts double.')],
    brrr: [t('Brrr'), t('The ball flies faster.')],
    pizza: [t('Pizza Day'), t('Two balls at once.')],
    difficulty: [t('Difficulty Adjustment'), t('Both paddles are shorter.')],
    tax: [t('Taxation is Theft'), t('A tax office patrols the centre line; the ball bounces off it.')],
    controls: [t('Capital Controls'), t('A border wall with a moving gap; hit the wall and the ball comes back.')],
    few: [t('Few understand'), t('The ball is invisible in the middle of the field.')],
    pow: [t('Proof of Work'), t('Each of your hits makes your paddle longer.')],
    arbeitsamt: [t('Job Centre – Please wait'), t('At the centre line the ball draws a number and waits a second.')],
})[event];
