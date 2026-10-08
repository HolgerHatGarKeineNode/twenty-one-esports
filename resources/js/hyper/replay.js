/**
 * The replay of a finished Hyperbitcoinization match (plan "Hyperbitcoinization", P3): the match page's table
 * (game.js), read-only, fed ply by ply from the replayed log (`config.replay.data`, HyperReplay on the server)
 * instead of the server's push. Play, pause, one step, the pace (the table's own: 1×, 3×, instant) and a
 * scrubber that jumps to any ply (the table at that ply comes from `config.replay.state?ply=`).
 *
 * game.js asks for snapshots and missed plies on its own (settle, catchUp): replayNet() answers those from
 * here, the snapshot at the ply the table shows and the plies up to the one fed last. While the replay plays
 * it asks for no snapshot (each one replays the match on the server): the events alone move the table, and
 * when it stops (pause, the end) the table is set to the replayed state at that ply once.
 */
import { t } from './i18n.js';

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

/** The page's requests, with the replay's two answered here. */
export function replayNet(net, config, cursor) {
    return {
        post: net.post,
        get(url) {
            if (url === config.urls.snapshot) {
                return cursor.playing ? Promise.resolve({ ok: false, status: 0, data: null }) : net.get(`${config.replay.state}?ply=${cursor.shown()}`);
            }
            if (url.startsWith(`${config.urls.events}?after=`)) {
                const after = Number(new URL(url, location.href).searchParams.get('after') || 0);

                return Promise.resolve({ ok: true, status: 200, data: { ply: cursor.fed, actions: cursor.plies.filter((p) => p.ply > after && p.ply <= cursor.fed) } });
            }

            return net.get(url);
        },
    };
}

export function startReplay(config, net, game, cursor) {
    const $ = (s) => document.querySelector(s);
    const last = config.replay.last;
    const play = $('#rp-play');
    const scrub = $('#rp-scrub');
    let playing = false;
    let speed = 1;
    let loop = null;
    let busy = false;

    const idle = () => { const s = game.state(); return s.idle !== undefined && !s.running && s.queued === 0; };
    const show = () => {
        scrub.value = String(cursor.fed);
        $('#rp-ply').textContent = `${cursor.fed} / ${last}`;
        document.body.dataset.replayPly = String(cursor.fed);
        $('#rp-play-t').textContent = playing ? t('Pause') : cursor.fed >= last ? t('Again') : t('Play');
        play.setAttribute('aria-label', $('#rp-play-t').textContent);
    };

    /** The next ply onto the table, as a broadcast of it would come. */
    function step() {
        const next = cursor.plies.find((p) => p.ply === cursor.fed + 1);
        if (!next) return false;
        game.onUpdated({ from_ply: cursor.fed, ply: next.ply, seat: next.seat, deadline_ms: null, events: next.events, truncated: false });
        cursor.fed = next.ply;
        show();

        return true;
    }

    async function run() {
        cursor.playing = true;
        while (playing) {
            if (!idle()) { await sleep(60); continue; }
            if (!step()) { playing = false; break; }
            await sleep(speed >= 20 ? 0 : 420 / speed);
        }
        while (!idle()) await sleep(60);
        cursor.playing = false;
        loop = null;
        if (!busy) await jump(cursor.fed);
        show();
    }

    async function jump(ply) {
        if (busy) return;
        busy = true;
        playing = false;
        cursor.playing = false;
        try {
            const r = await net.get(`${config.replay.state}?ply=${ply}`);
            if (r.ok && r.data) { cursor.fed = r.data.ply; game.jump(r.data); }
        } finally {
            busy = false;
            show();
        }
    }

    play.addEventListener('click', async () => {
        if (playing) { playing = false; show(); return; }
        if (loop) return;
        if (cursor.fed >= last) await jump(0);
        playing = true;
        show();
        loop = run();
    });
    $('#rp-step').addEventListener('click', () => { playing = false; if (idle()) step(); show(); });
    scrub.addEventListener('change', () => jump(Number(scrub.value)));
    document.querySelectorAll('#rp-speed button').forEach((b) => b.addEventListener('click', () => {
        speed = Number(b.dataset.s);
        game.setSpeed(speed);
        document.querySelectorAll('#rp-speed button').forEach((x) => x.classList.toggle('on', x === b));
    }));
    game.setSpeed(speed);
    show();

    return { state: () => ({ fed: cursor.fed, last, playing, busy, loop: loop !== null }) };
}
