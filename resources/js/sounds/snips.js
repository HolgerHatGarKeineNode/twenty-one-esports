/**
 * The snippet library (plan "Proof of Pong", P7; P8 brings it to Hyperbitcoinization): the soundboard clips cut at
 * their pauses into short snippets by tools/sound-snips/cut.py, listed in public/sounds/snips/manifest.json with their
 * source clip, person, duration and tags (the Hyperbitcoinization POOLS categories of the source clip).
 *
 * A game names its occasions as tag lists (`{ goal: ['conquer', 'hit'], … }`); draw(occasion, persons) takes a snippet
 * from every snippet carrying one of those tags, without repetition: each occasion is a shuffled deck that is dealt to
 * the end before it is shuffled again, and the snippet that just played never comes twice in a row. With `persons`
 * (the figure's speakers, see personsOf()) it draws from that person's snippets of the occasion with the weight
 * `ownWeight`, from the whole occasion otherwise; occasions in `ownAnyTag` fall back to the person's snippets of any
 * tag when none of theirs carries the occasion's tags (a figure's own voice on a goal or a win).
 *
 * Pure apart from the random source, so the deal is testable (tests/js/snips.test.mjs).
 */

/** A speaker of a soundboard clip name: its prefix before "_", else the name itself (cut.py names known speakers). */
export const speakerOf = (name) => (name.includes('_') ? name.slice(0, name.indexOf('_')) : name);

/**
 * The persons a figure speaks as: the speakers of its own clips (cast.json `goal` and `win`), plus the manifest's
 * person of those clips (an unprefixed clip whose speaker cut.py knows).
 */
export function personsOf(clips, snips) {
    const persons = new Set(clips.map(speakerOf));
    snips.forEach((snip) => { if (clips.includes(snip.source) && snip.person) persons.add(snip.person); });

    return [...persons];
}

/** A deck of items dealt in a shuffled order, reshuffled when empty; `avoid` is skipped when anything else is left. */
export function createDeck(items, random = Math.random) {
    let rest = [];

    const shuffle = () => {
        rest = [...items];
        for (let i = rest.length - 1; i > 0; i--) {
            const j = Math.floor(random() * (i + 1));
            [rest[i], rest[j]] = [rest[j], rest[i]];
        }
    };

    return {
        size: items.length,
        draw(avoid = null) {
            if (!items.length) return null;
            if (!rest.length) shuffle();
            let at = rest.length - 1;
            if (rest[at] === avoid) {
                if (rest.length > 1) at = rest.length - 2;
                else if (items.length > 1) {
                    // The last card of a deck is the one to avoid: it goes under the next shuffle's first.
                    const held = rest.pop();
                    shuffle();
                    const first = rest.length - 1;
                    const swap = rest[first] === held ? (first > 0 ? first - 1 : first) : first;
                    [rest[first], rest[swap]] = [rest[swap], rest[first]];
                    at = rest.length - 1;
                }
            }

            return rest.splice(at, 1)[0];
        },
    };
}

/**
 * A draw over the manifest's snippets for a game's occasions.
 *
 * @param {{ base: string, snips: Array<{ id: string, file: string, source: string, person: string, dur: number, tags: string[] }> }} manifest
 * @param {{ occasions: Record<string, string[]>, exclude?: string[], ownWeight?: number, ownAnyTag?: string[], random?: () => number }} options
 */
export function createSnips(manifest, { occasions, exclude = [], ownWeight = 0.4, ownAnyTag = [], random = Math.random }) {
    const snips = (manifest?.snips ?? []).filter((snip) => !exclude.includes(snip.person) && !exclude.includes(speakerOf(snip.source)));
    const decks = new Map();
    let last = null;

    const pool = (occasion) => {
        const tags = occasions[occasion] ?? [];

        return snips.filter((snip) => snip.tags.some((tag) => tags.includes(tag)));
    };

    const deck = (key, items) => {
        if (!decks.has(key)) decks.set(key, createDeck(items, random));

        return decks.get(key);
    };

    const mine = (items, persons) => items.filter((snip) => persons.includes(snip.person) || persons.includes(speakerOf(snip.source)));

    return {
        snips,
        pool,
        url: (snip) => `${manifest.base}${snip.file}`,
        /** A snippet for `occasion`, the figure's own (`persons`) with the weight `ownWeight`; null when none is tagged. */
        draw(occasion, persons = []) {
            const all = pool(occasion);
            let own = persons.length ? mine(all, persons) : [];
            let ownKey = `${occasion}|${persons.join(',')}`;
            if (!own.length && persons.length && ownAnyTag.includes(occasion)) {
                own = mine(snips, persons);
                ownKey = `*|${persons.join(',')}`;
            }
            const useOwn = own.length > 0 && (all.length === 0 || random() < ownWeight);
            const snip = useOwn ? deck(ownKey, own).draw(last) : deck(occasion, all).draw(last);
            if (snip) last = snip;

            return snip;
        },
    };
}
