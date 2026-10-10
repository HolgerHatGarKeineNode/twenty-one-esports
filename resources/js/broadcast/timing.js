/**
 * The broadcast's tempo (plan "OBS-Broadcast-Overlays", Qualitätslatte "Tempo"): every number an overlay waits, fades or
 * scrolls by lives here, and nowhere else. The user's warning was that generated motion runs too fast to read, so the
 * rules are written as bounds first and the values the elements use second; tests/Browser/BroadcastStyleguideTest.php
 * reads the live timeline (window.broadcast.timeline()) and holds every element to the bounds, not to these values.
 *
 * Units: milliseconds, and logical pixels of the 1920x1080 design frame (a 4K source scales them by 2).
 */

/** Bounds of the plan, verbatim: a text holds still for max(4 s, 1.5 s + 0.3 s per word) after its build-in ends. */
export const RULES = Object.freeze({
    holdMinMs: 4000,
    holdBaseMs: 1500,
    holdPerWordMs: 300,
    introMinMs: 600,
    introMaxMs: 1200,
    outroMinMs: 400,
    outroMaxMs: 800,
    prideMinMs: 8000,
    prideMaxMs: 12000,
    tickerMaxPxPerS: 80,
    rotationMinMs: 8000,
});

/** What the elements actually use, inside the bounds. */
export const TIMING = Object.freeze({
    // Lower third: the plate swings in (block being set), the text reveals behind it.
    lowerThirdIntroMs: 1000,
    lowerThirdOutroMs: 600,
    // A pride moment in a top corner: a longer, heavier build, then the same out.
    prideIntroMs: 1200,
    prideOutroMs: 700,
    // Pride moment total is clamped to RULES.prideMinMs..prideMaxMs whatever its words.
    prideTargetMs: 10000,
    // Text inside one element follows its plate by this much (the accent leads, the plate follows, the text last).
    staggerMs: 120,
    // The crawl: 64 px/s at 1080p, well under the 80 px/s cap, so a word stays on screen for ~25 s.
    tickerPxPerS: 64,
    tickerIntroMs: 900,
    // Elements that take turns in one slot (lower third texts, corner moments) swap at most this often.
    rotationMs: 12000,
    // The stinger carries no text (nothing to read): the shutter covers the cut by the peak, the mark punches through,
    // the shutter starts to clear at stingerClearMs and is gone by stingerMs.
    stingerMs: 1600,
    stingerPeakMs: 700,
    stingerClearMs: 900,
    // A pride moment's trophy lands this far into its build-in (shock ring, burst, the shimmer sound).
    prideLandMs: 760,
    // The gap after one element leaves before the next in the same slot comes in.
    slotGapMs: 1400,

    // Overlays (P3, P4). A corner moment is decided this far ahead of its start (the riser plays 1200 ms before it).
    prideLeadMs: 1400,
    // Between two corner moments the stream breathes: the next starts this long after the last has left.
    prideGapMs: 6000,
    // A corner left empty this long replays the league's pride list, so a quiet league still shows its people.
    prideIdleMs: 40000,
    // A champion takes the longest moment the rules allow.
    championTargetMs: 12000,
    // League live: an information card (stats, next cup, join, a game) in the lower third at most this often.
    infoEveryMs: 20000,
    // The join card with its QR code holds long enough to take out a phone and scan.
    qrHoldMs: 8000,
    // Tournament board: a page holds at least this long (more when its words need it); one page alone holds a minute.
    boardPageMs: 12000,
    boardAloneMs: 60000,
    boardIntroMs: 900,
    boardOutroMs: 600,
    // A champion of a finished tournament comes back this often.
    championRepeatMs: 120000,

    // Break scene (P5). The hero (what the viewer waits for) builds in with the mark and stays; a state change takes
    // it out behind a full stinger. The right column's sections take turns behind a panel wipe, each held at least
    // breakSectionMs (more when its words need it), long enough to read a list of names twice.
    heroIntroMs: 1200,
    heroOutroMs: 700,
    panelIntroMs: 1000,
    panelOutroMs: 600,
    breakSectionMs: 16000,
    // The wipe covers the panel by wipeCoverMs and has cleared it by wipeMs (no text: nothing to read).
    wipeMs: 1500,
    wipeCoverMs: 650,
    // The mark turns slowly about its upright axis (one swing in this time) and a light crosses it every heroSweepMs.
    markSwingMs: 18000,
    heroSweepMs: 6000,

    // Bracket (P6). A page is a camera position: the flight away from a page is its build-out, the flight in the next
    // page's build-in; while a page holds, the camera stands still, so no name moves while it is read. A page holds
    // at least bracketPageMs (the plan: >= 8 s), more when its names need it.
    bracketFlightInMs: 1200,
    bracketFlightOutMs: 700,
    bracketPageMs: 10000,
    bracketOverviewMs: 9000,
    bracketChampionMs: 12000,
    // A live result: the winner's row lights (0-500), the light runs the connector (400-1300), the name lands in the
    // next match (1200-1900); it plays inside a page's hold, after the page's own build-in.
    advanceMs: 2000,
});

/** Words in a text, the way a reader counts them: runs of letters or digits. */
export function wordsIn(text) {
    return (String(text).match(/[\p{L}\p{N}]+(?:['’.,:-][\p{L}\p{N}]+)*/gu) || []).length;
}

/** The least a text with this many words holds still, per the plan. */
export function holdFor(words) {
    return Math.max(RULES.holdMinMs, RULES.holdBaseMs + RULES.holdPerWordMs * words);
}

/** Hold of an element showing several texts at once: the reader reads all of them, so the words add up. */
export function holdForTexts(texts) {
    return holdFor(texts.reduce((n, t) => n + wordsIn(t), 0));
}
