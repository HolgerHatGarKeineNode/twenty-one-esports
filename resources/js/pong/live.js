/**
 * A live Proof of Pong match between two players (plan "Proof of Pong", P2; resources/views/pong/live.blade.php),
 * netcode "defender decides, server checks" (App\Support\Pong\PongReferee):
 *
 * - Both pages run the rally on the shared core (physics.js) from the server's snapshot: the rally's seed and event,
 *   each ball's last checked state and the moment of tick 0 on the server's clock (`servedAt`, `speed` ticks per 1/60 s).
 * - Each page moves only its own paddle. When a ball reaches this player's face, the page decides hit or miss with its
 *   paddle in that tick and reports it (`hit` with the paddle's centre, or `goal`); the referee checks it and pushes
 *   the decision to both pages. A ball reaching the OPPONENT's face waits there until the referee's decision arrives,
 *   so no page ever plays on a guess; then it re-syncs from the server's state and catches up with the clock.
 * - The paddles stream to the other page as client events on the presence channel `pong.{ulid}` (~20 Hz), smoothed;
 *   they are only drawn, the referee never reads them. Presence shows when the opponent is gone: the server pauses the
 *   match once it has not heard from a page for a few seconds and ends it after 30 s (forfeit).
 * - The page asks the server every two seconds (its heartbeat), at once when the opponent leaves or comes back, and
 *   when it becomes visible again; without a websocket it asks more often for the snapshots it cannot hear.
 *
 * Deploy skew (P8): every snapshot names the rules and code the referee plays with (`rules`, PongRules::version()).
 * A page whose first snapshot named another one runs older code than the server: it reloads itself once, between two
 * rallies (not while a rally is in play), instead of computing rallies the referee no longer agrees with; if the reload
 * still brings the old code (a cache), it says so instead of reloading again.
 *
 * The rally is stepped tick by tick as PongRally steps it (paddles first, then the balls), and the score is always the
 * server's. With `pong-settings` autoplay (the browser test's handle) a bot plays this page's paddle exactly as in
 * PongGame::bots(), so two autoplaying pages end with the score PongGame::bots() gives for the seed and both levels.
 */
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { createNet } from '../hyper/net.js';
import { botSpeed, createBot } from './bot.js';
import { createStage, readConfig, readSettings } from './page.js';
import { HEIGHT, PADDLE_HALF, PLAYER_SPEED, RALLY_TICK_CAP, SERVE_TICKS, TICKS_PER_SECOND, WIDTH, approaches, bounce, createRally, crossed, halfOf, meets, speedAfter, step } from './physics.js';
import { storedFigure } from './picker.js';
import { rallySeed } from './rules.js';
import { createShow } from './show.js';

const HEARTBEAT_MS = 2000;
const POLL_MS = 700;
const WHISPER_MS = 50;

const { config, t } = readConfig();
const settings = readSettings();
const $ = (id) => document.getElementById(id);
const http = createNet(config.csrf);
const me = config.me;
const opp = me === null ? 1 : 1 - me;
const { arena, settings: stageSettings, portrait: isPortrait, input } = createStage();

let snap = null;
let applied = 0;
let skew = 0;
let bestRtt = Infinity;
let rally = null;
let previous = null;
let oppShown = HEIGHT >> 1;
let oppTarget = HEIGHT >> 1;
let opponentHere = null;
let lastScore = null;
let channel = null;
const reported = new Set();

/* ---------- The show (P3): figures, effects, sound ----------------------------------------------------------- */

/** The figures as this page shows them: the viewer's side first (they always play side 0 on screen). */
const shownFigures = (figures) => (me === 1 ? [figures[1], figures[0]] : [figures[0], figures[1]]);
const theShow = createShow({ arena, settings: stageSettings, t, castTexts: config.castTexts, figures: shownFigures(config.snapshot.figures ?? [null, null]), arenaName: config.arena });
let figuresShown = JSON.stringify(config.snapshot.figures ?? null);
let announced = 0;
let served = 0;
let ended = false;
let figureSent = null;

/** The player's pick goes to the referee while the match has not had its first point. */
async function sendFigure(id) {
    if (me === null || !id || figureSent === id) return;
    const s = snap;
    if (!s || !(s.status === 'waiting' || (s.status === 'active' && s.score[0] === 0 && s.score[1] === 0))) return;
    if ((s.figures ?? [])[me] === id) return;
    figureSent = id;
    const response = await http.post(config.urls.figure, { figure: id });
    if (response.ok && response.data) apply(response.data);
}
document.addEventListener('pong-figure', (event) => sendFigure(event.detail.id));

const serverNow = () => Date.now() + skew;
const msOf = (ticks, speed) => (ticks * 1000) / (TICKS_PER_SECOND * speed);
const out = (ball) => ball[0] <= 0 || ball[0] >= WIDTH;

/** The rally tick the server's clock stands at (held while the match is paused). */
function clockTick(r) {
    const at = r.ref.pausedAt ?? serverNow();

    return Math.min(RALLY_TICK_CAP, Math.floor(((at - r.ref.servedAt) * TICKS_PER_SECOND * snap.speed) / 1000));
}

/** A rally from the server's state: its parameters from the serve, each ball as the referee last checked it. */
function startRally(ref) {
    const serve = createRally(rallySeed(snap.seed, ref.rally), ref.event, [PLAYER_SPEED, PLAYER_SPEED]);
    const balls = ref.balls.map((ball) => ({ b: [...ball.b], t: ball.t, hits: ball.hits, state: ball.state === 'live' ? 'live' : 'out' }));
    const g = Math.min(...balls.filter((ball) => ball.state === 'live').map((ball) => ball.t), Infinity);
    const r = {
        number: ref.rally,
        ref,
        serve,
        balls,
        g: Number.isFinite(g) ? g : 0,
        paddle: me === null ? HEIGHT >> 1 : ref.paddles[me][0],
        paddleTick: 0,
        // This player's hits in the rally (Proof of Work grows the paddle with them), from the referee on a re-sync.
        myHits: me === null ? 0 : (ref.sideHits?.[me] ?? 0),
        history: new Map(),
        pending: {},
        bot: settings.autoplay && me !== null ? createBot(settings.autoplay, me, rallySeed(snap.seed, ref.rally)) : null,
        speed: settings.autoplay ? botSpeed(settings.autoplay) : PLAYER_SPEED,
    };
    r.paddleTick = r.g;
    r.history.set(r.g, r.paddle);

    return r;
}

/** What a bot or PongRally sees of the rally at tick g. */
function view(r) {
    const paddles = [HEIGHT >> 1, HEIGHT >> 1];
    paddles[me ?? 0] = r.paddle;

    return {
        tick: r.g,
        balls: r.balls.map((ball) => ball.b),
        alive: r.balls.map((ball) => ball.state === 'live' || ball.state === 'flying'),
        hits: r.balls.map((ball) => ball.hits),
        sideHits: me === 1 ? [0, r.myHits] : [r.myHits, 0],
        paddles,
        half: myHalf(r),
        event: r.serve.event,
        seed: r.serve.seed,
    };
}

/** This player's paddle half length now (Proof of Work grows it with each own hit). */
const myHalf = (r) => halfOf(r.serve.event, r.myHits);

/** A ball one tick on, arriving at `tick`, in this rally's field (obstacles, queue). */
const stepIn = (r, b, tick) => step(b, tick, r.serve.event, r.serve.seed);

/** This player's paddle in tick n: moved once per tick towards the wanted position, at most its speed. */
function paddleAt(r, n) {
    while (r.paddleTick < n) {
        const want = r.bot ? r.bot.target(view(r)) : input.target(r.paddle);
        const delta = Math.max(-r.speed, Math.min(r.speed, want - r.paddle));
        r.paddle = Math.max(myHalf(r), Math.min(HEIGHT - myHalf(r), r.paddle + delta));
        r.paddleTick++;
        r.history.set(r.paddleTick, r.paddle);
    }

    return r.history.get(n) ?? r.paddle;
}

/** The side a live ball runs towards, and whether its next tick crosses that side's face. */
function nextContact(r, ball) {
    const side = ball.b[2] < 0 ? 0 : 1;

    return { side, crosses: approaches(ball.b, side) && crossed(stepIn(r, ball.b, ball.t + 1), side) };
}

function report(r, index, tick, kind, y) {
    const key = `${r.number}:${index}:${tick}`;
    if (reported.has(key)) return;
    reported.add(key);

    const send = async (attempt) => {
        const response = await http.post(config.urls.report, { rally: r.number, ball: index, tick, kind, y });
        if (response.ok && response.data?.snapshot) {
            apply(response.data.snapshot);
        } else if (response.status === 0 || response.status === 429 || response.status >= 500) {
            if (attempt < 3) setTimeout(() => send(attempt + 1), 250 * (attempt + 1));
        }
    };
    send(0);
}

/**
 * One ball up to tick `to`: this player's contacts decided with the paddle of that tick and reported; a contact of
 * the opponent stops it (the referee decides it). A missed ball flies on to the goal line and goes.
 */
function advance(r, index, to) {
    const ball = r.balls[index];

    while (ball.t < to) {
        if (ball.state === 'live') {
            const { side, crosses } = nextContact(r, ball);
            const tick = ball.t + 1;
            const moved = stepIn(r, ball.b, tick);

            if (crosses) {
                if (side !== me) return;

                const y = paddleAt(r, tick);
                const half = myHalf(r);
                if (meets(moved, y, half)) {
                    ball.hits++;
                    r.myHits++;
                    ball.b = bounce(moved, side, y, half, speedAfter(ball.hits, r.serve.base, r.serve.speedup, r.serve.max));
                    report(r, index, tick, 'hit', y);
                } else {
                    ball.b = moved;
                    ball.state = 'flying';
                    report(r, index, tick, 'goal', null);
                }
                ball.t = tick;
                continue;
            }

            ball.b = moved;
            ball.t = tick;
            continue;
        }

        if (ball.state === 'flying') {
            ball.b = stepIn(r, ball.b, ball.t + 1);
            ball.t++;
            if (out(ball.b)) ball.state = 'out';
            continue;
        }

        ball.t = to;
    }
}

/** A decision of the referee for ball `index` (its state at the contact's tick) put in place of the local ball. */
function place(r, index, server) {
    const ball = r.balls[index];
    ball.b = [...server.b];
    ball.t = server.t;
    ball.hits = server.hits;
    ball.state = server.state === 'live' ? 'live' : 'flying';
}

/**
 * The rally up to the server's clock, tick by tick as PongRally steps: the paddle first (from the rally as it stood
 * a tick earlier), then the balls. A referee's decision takes effect in the tick of its contact, never earlier, so a
 * bot sees the rally exactly as PongRally shows it.
 */
function run(r) {
    const target = clockTick(r);

    for (let guard = 0; guard < 20000 && r.g < target; guard++) {
        const n = r.g + 1;
        paddleAt(r, n);
        let held = false;

        // This player's contacts of tick n first, so two balls at both faces in one tick cannot hold both pages.
        r.balls.forEach((ball, index) => {
            if (ball.state !== 'live' || ball.t !== r.g || r.pending[index]?.t === n) return;
            const { side, crosses } = nextContact(r, ball);
            if (!crosses) return;
            if (side === me) advance(r, index, n);
            else held = true;
        });
        // A ball at the opponent's face waits for the referee.
        if (held) return;

        r.g = n;
        r.balls.forEach((_, index) => {
            if (r.pending[index]?.t === n) {
                place(r, index, r.pending[index]);
                delete r.pending[index];
            } else {
                advance(r, index, n);
            }
        });
    }
}

/* The rules and code this page was loaded with, and whether the server has moved on since (deploy skew). */
const loadedRules = config.snapshot?.rules ?? null;
let staleRules = null;
const RELOAD_KEY = `pong-rules-reload:${config.id}`;

/** Between two rallies: reload once for a server running other rules; a second time only says so. */
function reloadForRules(current) {
    if (staleRules === null || current === 'play') return;
    let tried = null;
    try { tried = sessionStorage.getItem(RELOAD_KEY); } catch { /* no storage: reload anyway, once per page */ }
    if (tried === staleRules) {
        flash(t('The game was updated: please reload the page.'));
        staleRules = null;

        return;
    }
    try { sessionStorage.setItem(RELOAD_KEY, staleRules); } catch { /* the guard holds for this page only */ }
    staleRules = null;
    document.body.dataset.reloading = '1';
    location.reload();
}

/** A newer snapshot of the server: the truth for score, state and the rally. */
function apply(next) {
    if (!next || next.version < applied) return;
    applied = next.version;
    snap = next;
    if (loadedRules !== null && next.rules && next.rules !== loadedRules && next.status !== 'finished') staleRules = next.rules;

    const figures = JSON.stringify(next.figures ?? null);
    if (figures !== figuresShown) {
        figuresShown = figures;
        theShow.setFigures(shownFigures(next.figures ?? [null, null]));
    }

    const ref = next.ref;
    if (ref) {
        if (!rally || ref.rally > rally.number) {
            if (rally && rally.balls.some((ball) => ball.state === 'flying')) previous = rally;
            rally = startRally(ref);
            if (ref.rally > served) {
                served = ref.rally;
                theShow.serve(ref.rally);
            }
        } else if (ref.rally === rally.number) {
            rally.ref = ref;
            ref.balls.forEach((server, index) => {
                const local = rally.balls[index];
                const decided = server.state !== 'live' ? local.state === 'live' : (server.hits !== local.hits || server.t > local.t || local.state !== 'live');
                if (!decided) return;
                if (server.t > rally.g) {
                    // Ahead of this page: it takes effect in its own tick (run()).
                    rally.pending[index] = server;
                } else {
                    // Behind this page (its own decision refused, or a page that ran ahead): from there to now.
                    delete rally.pending[index];
                    place(rally, index, server);
                    advance(rally, index, rally.g);
                }
            });
            if (me !== null) oppTarget = ref.paddles[opp][0];
        }
    }

    show();
}

/* ---------- The page ------------------------------------------------------------------------------------------- */

const banner = $('banner');
const toast = $('toast');
let toastUntil = 0;

function flash(text) {
    toast.textContent = text;
    toast.hidden = false;
    toastUntil = performance.now() + 1500;
}

function show() {
    const s = snap;
    const mine = me ?? 0;
    const score = [s.score[mine], s.score[1 - mine]];
    document.querySelector('[data-test=pong-score-me]').textContent = String(score[0]);
    document.querySelector('[data-test=pong-score-opponent]').textContent = String(score[1]);
    document.body.dataset.score = `${s.score[0]}:${s.score[1]}`;
    document.body.dataset.status = s.status;
    $('rally').textContent = s.ref ? t('Rally :n', { n: s.ref.rally }) : '';

    if (lastScore && me !== null && s.status === 'active') {
        const won = s.score[me] - lastScore[me];
        const lost = s.score[opp] - lastScore[opp];
        if (won > 0) {
            flash(t('Point for you'));
            theShow.goal(0, won);
        } else if (lost > 0) {
            flash(t('Point for :name', { name: config.names[opp] }));
            theShow.goal(1, lost);
        }
    }
    lastScore = [...s.score];

    $('waiting').hidden = s.status !== 'waiting';
    const paused = s.status === 'active' && (s.away !== null || (opponentHere === false && me !== null));
    $('away').hidden = !paused;
    $('away-title').textContent = s.away === me && me !== null ? t('Reconnecting …') : t(':name is gone', { name: config.names[opp] });

    const over = s.status === 'finished' || s.status === 'aborted';
    $('end').hidden = !over;
    $('resign').hidden = over || me === null;
    if (over) end(s);
}

function end(s) {
    const won = s.winner !== null && s.winner === me;
    const title = s.status === 'aborted'
        ? t('Match aborted')
        : (me === null ? t(':name wins', { name: config.names[s.winner] }) : (won ? t('You win!') : t(':name wins', { name: config.names[opp] })));
    $('end-title').textContent = title;
    const mine = me ?? 0;
    $('end-score').textContent = `${s.score[mine]} : ${s.score[1 - mine]}`;
    $('end-reason').textContent = ({ resign: t('by resignation'), forfeit: t('by forfeit') })[s.endReason] ?? '';
    const rating = s.ratings && me !== null ? s.ratings[me] : null;
    $('end-rating').hidden = !rating;
    if (rating) {
        const delta = rating[1] - rating[0];
        $('end-rating').textContent = t('Elo :before → :after (:delta)', { before: rating[0], after: rating[1], delta: delta >= 0 ? `+${delta}` : String(delta) });
    }
    document.body.dataset.result = s.status === 'aborted' ? 'aborted' : (won ? 'win' : 'loss');
    if (!ended) {
        ended = true;
        const shownWinner = s.winner === null ? null : (me === 1 ? 1 - s.winner : s.winner);
        theShow.end(s.status === 'aborted' ? null : shownWinner, me === null ? null : won);
    }

    const rematch = $('rematch');
    rematch.hidden = me === null || s.status !== 'finished';
    if (me !== null) {
        rematch.disabled = s.rematch[me];
        rematch.textContent = s.rematch[me] ? t('Waiting for :name …', { name: config.names[opp] }) : (s.rematch[opp] ? t('Accept rematch') : t('Rematch'));
    }
    if (s.next && !document.body.dataset.leaving) {
        document.body.dataset.leaving = '1';
        location.assign(s.next);
    }
}

/** The banner of a meme event and the serve's countdown, before tick 0 of a rally. */
function phase(now) {
    if (!snap || snap.status !== 'active' || !rally) {
        theShow.unpin();

        return snap?.status ?? 'loading';
    }
    const until = rally.ref.servedAt - (rally.ref.pausedAt ?? now);
    if (until > 0) {
        const serveMs = msOf(SERVE_TICKS, snap.speed);
        if (rally.ref.event && until > serveMs) {
            if (announced !== rally.number) {
                announced = rally.number;
                theShow.announce(rally.ref.event, until - serveMs, rally.number);
            }

            return 'announce';
        }
        pinFor(rally);
        banner.hidden = true;

        return previous ? 'point' : 'serve';
    }
    pinFor(rally);
    banner.hidden = true;
    previous = null;

    return 'play';
}

/** The event of the rally in play pinned at the field's edge once its takeover is out; gone with the next rally. */
function pinFor(r) {
    if (r.ref.event) theShow.pin(r.ref.event, r.number);
    else theShow.unpin();
}

/** The field from this player's side: they are always side 0 (left, or at the bottom upright). */
function draw(r) {
    const flip = me === 1;
    const balls = r.balls.map((ball) => (flip ? [WIDTH - ball.b[0], ball.b[1], -ball.b[2], ball.b[3], ...ball.b.slice(4)] : ball.b));
    const alive = r.balls.map((ball) => ball.state !== 'out');
    const mine = me === null ? r.ref.paddles[0][0] : r.paddle;
    const theirs = me === null ? r.ref.paddles[1][0] : oppShown;
    const halves = r.ref.halves ?? [r.serve.half, r.serve.half];
    const myShownHalf = me === null ? halves[0] : myHalf(r);
    const theirHalf = me === null ? halves[1] : halves[opp];
    theShow.frame({ balls, alive, paddles: [mine, theirs], half: r.serve.half, halves: [myShownHalf, theirHalf], event: r.ref.event, seed: r.serve.seed, tick: r.g, flip });
}

function frame() {
    const now = serverNow();
    if (rally && snap?.status === 'active' && me !== null) run(rally);
    if (previous) {
        previous.balls.forEach((ball, index) => {
            if (ball.state === 'flying') advance(previous, index, ball.t + Math.max(1, Math.round(snap.speed)));
        });
        if (!previous.balls.some((ball) => ball.state === 'flying')) previous = null;
    }
    oppShown += (oppTarget - oppShown) * 0.35;
    const current = phase(now);
    if (previous) draw(previous);
    else if (rally) draw(rally);
    // Before the first serve: the field with both paddles in the middle.
    else theShow.frame({ balls: [], alive: [], paddles: [HEIGHT >> 1, HEIGHT >> 1], half: PADDLE_HALF, event: null });
    if (!toast.hidden && performance.now() > toastUntil) toast.hidden = true;
    document.body.dataset.phase = current;
    reloadForRules(current);
    requestAnimationFrame(frame);
}

/* ---------- The server ------------------------------------------------------------------------------------------ */

async function sync() {
    const sent = Date.now();
    const response = await http.post(config.urls.sync, {});
    const received = Date.now();
    if (!response.ok || !response.data) return;
    // The server's clock: from the answer with the shortest round trip.
    const rtt = received - sent;
    if (rtt <= bestRtt) {
        bestRtt = rtt;
        skew = response.data.now - (sent + rtt / 2);
    }
    apply(response.data);
}

$('rematch').addEventListener('click', async () => {
    const response = await http.post(config.urls.rematch, {});
    if (response.ok) apply(response.data);
});

const resign = $('resign');
let confirming = 0;
resign.addEventListener('click', async () => {
    if (performance.now() > confirming) {
        confirming = performance.now() + 3000;
        resign.dataset.confirm = '1';
        resign.setAttribute('aria-label', t('Click again to resign'));
        setTimeout(() => { if (performance.now() > confirming) delete resign.dataset.confirm; }, 3100);

        return;
    }
    const response = await http.post(config.urls.resign, {});
    if (response.ok) apply(response.data);
});

const meta = document.querySelector('meta[name="reverb"]');
if (meta && me !== null) {
    window.Pusher = Pusher;
    const reverb = JSON.parse(meta.content);
    const echo = new Echo({
        broadcaster: 'reverb',
        key: reverb.key,
        wsHost: reverb.host,
        wsPort: reverb.port,
        wssPort: reverb.port,
        forceTLS: reverb.scheme === 'https',
        enabledTransports: ['ws', 'wss'],
    });
    window.Echo = echo;

    const present = (members) => {
        opponentHere = members.some((member) => member.side === opp);
        if (snap) show();
        sync();
    };
    let members = [];
    channel = echo.join(`pong.${config.id}`)
        .here((list) => { members = list; present(members); })
        .joining((member) => { members = [...members.filter((m) => m.id !== member.id), member]; present(members); })
        .leaving((member) => { members = members.filter((m) => m.id !== member.id); present(members); })
        .listen('.pong.updated', (payload) => apply(payload))
        .listenForWhisper('paddle', ({ y }) => { if (Number.isFinite(y)) oppTarget = y; });

    setInterval(() => {
        if (rally && snap?.status === 'active') channel.whisper('paddle', { y: rally.paddle, r: rally.number });
    }, WHISPER_MS);

    echo.connector.pusher.connection.bind('state_change', ({ current }) => {
        document.body.dataset.live = current === 'connected' ? '1' : '0';
        if (current === 'connected') sync();
    });
    setInterval(sync, HEARTBEAT_MS);
} else if (me !== null) {
    // No websocket here: the snapshots come by asking.
    setInterval(sync, POLL_MS);
}

document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible' && me !== null) sync(); });

apply(config.snapshot);
skew = config.snapshot.now - Date.now();
if (me !== null) sync().then(() => sendFigure(document.querySelector('#waiting [data-pong-picker]')?.dataset.picked ?? storedFigure()));
requestAnimationFrame(frame);

// The browser test's handle: where the match stands, in the server's orientation (side 0 first).
window.pongLive = {
    state: () => ({
        status: snap?.status, score: snap ? [...snap.score] : null, me, version: applied, winner: snap?.winner ?? null,
        rally: rally?.number ?? null, tick: rally?.g ?? null, away: snap?.away ?? null, opponentHere, portrait: isPortrait(), renderer: arena.kind,
        figures: snap?.figures ?? null, shown: theShow.figures().map((f) => f.id), rules: loadedRules,
    }),
};
window.pongShow = theShow;
document.body.dataset.renderer = arena.kind;
document.body.dataset.ready = '1';
