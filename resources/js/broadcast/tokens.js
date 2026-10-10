/**
 * Broadcast design tokens: the 1920x1080 logical frame, its grid and slots, the type scale and the palette.
 * Every overlay lays out in these units; a 1440p or 4K source scales the whole frame (resources/js/broadcast/stage.js).
 *
 * Hierarchy, decided before any colour: 1. who (the name, Unbounded 800, white; 64 px in a pride moment, 44 in a lower
 * third), 2. what they did (Unbounded 500, white at a smaller size), 3. where/when (JetBrains Mono, grey).
 * Orange is light and material only: the hot edge of a block, the live chip, glow, the league mark; it never marks a
 * word. Depth comes from layers (light behind, glass, metal lip, figure in front, flare on top), not from colour.
 */

export const FRAME = Object.freeze({ w: 1920, h: 1080 });

/** Title-safe margins (5 % of the frame), the 8 px base unit, 12 columns of 122 px with 24 px gutters. */
export const GRID = Object.freeze({
    unit: 8,
    marginX: 96,
    marginY: 54,
    columns: 12,
    column: 122,
    gutter: 24,
});

/**
 * Slots. `centre` is the 16:9 picture that stays free for the stream (1120x630, centred): no overlay may draw into it
 * outside full-screen scenes and the stinger (resources/js/broadcast/guard.js cuts every light there). The lower third
 * starts below it, the ticker under that.
 *
 * A pride moment is an L that hugs one top corner of the stream: its name plate runs along the top band (`corner*`,
 * ending at y 222, above the centre) and its trophy column stands in the free side strip (`pillar*`, x 1560..1824 or
 * 96..360, 40 px clear of the centre), reaching down to y 524 where the stream's picture leaves room. The two
 * corners share the top band's middle, so only one pride moment is on air at a time (P3 schedules them in turn).
 */
export const SLOTS = Object.freeze({
    centre: { x: 400, y: 225, w: 1120, h: 630 },
    cornerLeft: { x: 96, y: 54, w: 864, h: 168 },
    cornerRight: { x: 960, y: 54, w: 864, h: 168 },
    pillarLeft: { x: 96, y: 54, w: 264, h: 470 },
    pillarRight: { x: 1560, y: 54, w: 264, h: 470 },
    lowerThird: { x: 96, y: 858, w: 1120, h: 104 },
    ticker: { x: 96, y: 978, w: 1728, h: 48 },
});

/**
 * Type scale in logical px (steps of ~1.18, 22 at the floor: the smallest size that still reads on a phone watching
 * a 1080p stream). `leading` is the line height factor, `tracking` em.
 */
export const TYPE = Object.freeze({
    hero: { family: 'display', weight: 800, size: 80, leading: 1.05, tracking: -0.01 },
    // The name in a pride moment: the largest word an overlay ever puts beside the live picture.
    name: { family: 'display', weight: 800, size: 64, leading: 1.0, tracking: -0.015 },
    headline: { family: 'display', weight: 800, size: 52, leading: 1.1, tracking: -0.005 },
    title: { family: 'display', weight: 700, size: 44, leading: 1.15, tracking: 0 },
    line: { family: 'display', weight: 500, size: 30, leading: 1.25, tracking: 0 },
    data: { family: 'mono', weight: 500, size: 26, leading: 1.3, tracking: 0 },
    crawl: { family: 'mono', weight: 500, size: 24, leading: 1.3, tracking: 0 },
    tag: { family: 'display', weight: 700, size: 22, leading: 1.2, tracking: 0.02 },
    // A ticker segment's head (what kind of news follows), inside its metal chip.
    head: { family: 'display', weight: 600, size: 20, leading: 1.2, tracking: 0.01 },
});

/** Palette: the site's tokens (resources/css/app.css) plus the two materials the broadcast adds. */
export const COLOR = Object.freeze({
    ground: '#0A0A0B',
    glass: '#17171B',
    metal: '#2A2A30',
    ink: '#FFFFFF',
    ink2: '#ADADB0',
    ink3: '#8B8B90',
    btc: '#F7931A',
    btcHi: '#F9B25F',
    btcDeep: '#B9640A',
    onBtc: '#17120A',
});
