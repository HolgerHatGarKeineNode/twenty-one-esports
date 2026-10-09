/**
 * The order of the background music: every track once per cycle, a fresh shuffle for each cycle, and the
 * last track of a cycle never the first of the next, so no track plays twice in a row (a list of one
 * track is the one exception: it repeats). A new page load is a new random start.
 *
 * The tracks come from public/music/midi/manifest.json (see readManifest()); adding a track is a file and
 * a manifest line, no code. Pure: `random` is handed in, so tests/js/midiPlaylist.test.mjs runs it seeded.
 */

const text = (value) => (typeof value === 'string' ? value.trim() : '');

/** Where the games read the list of tracks from. */
export const MANIFEST_URL = '/music/midi/manifest.json';

/** Fisher-Yates over a copy. */
export function shuffle(items, random = Math.random) {
    const list = [...items];
    for (let i = list.length - 1; i > 0; i--) {
        const j = Math.floor(random() * (i + 1));
        [list[i], list[j]] = [list[j], list[i]];
    }

    return list;
}

/**
 * @param {Array<T>} tracks
 * @param {() => number} [random]
 * @returns {{next: () => T|null, size: number}}
 * @template T
 */
export function createPlaylist(tracks, random = Math.random) {
    const all = [...tracks];
    let cycle = [];
    let previous = null;

    function refill() {
        cycle = shuffle(all, random);
        if (cycle.length > 1 && previous !== null && cycle[0] === previous) {
            // the new cycle would open with the track that just ended: swap it with any other place
            const j = 1 + Math.floor(random() * (cycle.length - 1));
            [cycle[0], cycle[j]] = [cycle[j], cycle[0]];
        }
    }

    return {
        size: all.length,
        next() {
            if (all.length === 0) {
                return null;
            }
            if (cycle.length === 0) {
                refill();
            }
            previous = cycle.shift();

            return previous;
        },
    };
}

/**
 * The tracks of a manifest as absolute URLs, in file order; an unreadable manifest or one without tracks is
 * an empty list (the game then keeps its own music). `{"tracks": [{"file": "x.mid", "title", "author",
 * "license", "source", "genre"}]}`, files relative to the manifest; a name that is not a plain
 * `*.mid`/`*.midi` file name is skipped. The credits (title, author, license, source) travel with the track
 * for the "now playing" line; a source that is not an https URL is left out.
 *
 * @returns {Array<{url: string, title: string, author: string, license: string, source: string|null, genre: string}>}
 */
export function readManifest(data, manifestUrl = MANIFEST_URL) {
    const list = data && Array.isArray(data.tracks) ? data.tracks : [];
    const dir = manifestUrl.slice(0, manifestUrl.lastIndexOf('/') + 1);

    const seen = new Set();

    return list
        .filter((track) => track && typeof track.file === 'string' && /^[\w.-]+\.midi?$/i.test(track.file))
        // a file listed twice is one track: two entries could play it back to back
        .filter((track) => !seen.has(track.file) && seen.add(track.file))
        .map((track) => ({
            url: dir + track.file,
            title: text(track.title) || track.file,
            author: text(track.author),
            license: text(track.license),
            source: /^https:\/\//.test(text(track.source)) ? track.source : null,
            genre: text(track.genre),
        }));
}
