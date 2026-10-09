/**
 * Proof of Pong's game page against a bot (plan "Proof of Pong", P1; resources/views/pong/match.blade.php): runs the
 * game on the deterministic core (rules.js, physics.js, bot.js; the server's App\Support\Pong) at a fixed 60 ticks a
 * second and hands each frame to the arena (arena.js) to draw. The player is side 0 (left, or at the bottom on a
 * phone held upright), the bot side 1.
 *
 * A rally goes: an announced meme event (every 21st rally), a short countdown, play, then a pause on the point.
 * Goals count as they fall, so a Pizza Day rally can end the game with its first ball.
 *
 * The field is fitted to the window once and again only when the window's WIDTH changes: a phone shows and hides its
 * address bar (and keyboard) as height-only resizes, and a field that followed them jumped (Blockli, 2026-10-09).
 *
 * Settings (localStorage `pong-settings`, the browser test's handle): `speed` runs the clock faster (1 = real time),
 * `autoplay` lets a bot of that level play the player's paddle. With both, a game is the one PongGame::bots() plays
 * for the same seed and levels, tick for tick.
 */
import { createArena, fieldYAt } from './arena.js';
import { botSpeed, createBot } from './bot.js';
import { HEIGHT, PADDLE_HALF, PLAYER_SPEED, TICKS_PER_SECOND, stepRally } from './physics.js';
import { createGame, rallySeed } from './rules.js';

const ANNOUNCE_TICKS = 110;
const SERVE_TICKS = 50;
const POINT_TICKS = 70;
const TICK_MS = 1000 / TICKS_PER_SECOND;
const KEY_STEP = 1800;


function readSettings() {
    try {
        const stored = JSON.parse(localStorage.getItem('pong-settings') || '{}');
        const speed = Number(stored.speed);
        const autoplay = Number(stored.autoplay);

        return { speed: Number.isFinite(speed) && speed > 0 ? Math.min(speed, 200) : 1, autoplay: [1, 2, 3, 4].includes(autoplay) ? autoplay : null };
    } catch {
        return { speed: 1, autoplay: null };
    }
}

function boot() {
    const config = JSON.parse(document.getElementById('pong-config').textContent);
    const t = (key, replace = {}) => Object.entries(replace).reduce((text, [k, v]) => text.replace(`:${k}`, v), config.texts[key] ?? key);
    const settings = readSettings();
    // Each meme event's name and line (literal keys, so the page's text test finds them).
    const eventText = (event) => ({
        halving: [t('Halving'), t('The ball is half the size, its point counts double.')],
        brrr: [t('Brrr'), t('The ball flies faster.')],
        pizza: [t('Pizza Day'), t('Two balls at once.')],
        difficulty: [t('Difficulty Adjustment'), t('Both paddles are shorter.')],
    })[event];
    const $ = (id) => document.getElementById(id);
    const field = $('field');
    const stage = $('stage');
    const arena = createArena($('arena'));

    const game = createGame(config.seed, config.rules);
    const speeds = [settings.autoplay ? botSpeed(settings.autoplay) : PLAYER_SPEED, botSpeed(config.level)];
    let phase = 'ready';
    let wait = 0;
    let rally = null;
    let bots = [];
    let counted = 0;
    let lastScorer = null;
    let target = HEIGHT >> 1;
    const keys = { up: false, down: false };
    let portrait = false;
    let box = { w: 0, h: 0 };

    // Fit the field into the stage: 16:9 lying, 9:16 upright. Called at the start and when the width changes.
    let fittedWidth = -1;
    const fit = () => {
        fittedWidth = innerWidth;
        const top = 56;
        const availW = innerWidth - 16;
        const availH = innerHeight - top - 12;
        portrait = innerWidth < innerHeight;
        const ratio = portrait ? 9 / 16 : 16 / 9;
        let w = Math.min(availW, availH * ratio);
        let h = w / ratio;
        if (h > availH) {
            h = availH;
            w = h * ratio;
        }
        w = Math.floor(w);
        h = Math.floor(h);
        stage.style.height = `${innerHeight - top}px`;
        field.style.width = `${w}px`;
        field.style.height = `${h}px`;
        box = { w, h };
        arena.resize(w, h, portrait);
        document.body.dataset.portrait = portrait ? '1' : '0';
    };
    fit();
    addEventListener('resize', () => {
        if (innerWidth !== fittedWidth) fit();
    });

    // The player's paddle follows the pointer (mouse, or a thumb dragged anywhere on the page) ...
    const follow = (event) => {
        const r = field.getBoundingClientRect();
        target = fieldYAt(event.clientX - r.left, event.clientY - r.top, box.w, box.h, portrait);
    };
    addEventListener('pointermove', follow);
    addEventListener('pointerdown', follow);
    // ... or the keys: W/S and the arrows (left/right as well on an upright field).
    const keyOf = (event) => ({ KeyW: 'up', ArrowUp: 'up', ArrowLeft: 'up', KeyS: 'down', ArrowDown: 'down', ArrowRight: 'down' })[event.code];
    addEventListener('keydown', (event) => {
        const key = keyOf(event);
        if (!key) return;
        keys[key] = true;
        event.preventDefault();
    });
    addEventListener('keyup', (event) => {
        const key = keyOf(event);
        if (key) keys[key] = false;
    });

    const scoreMe = document.querySelector('[data-test=pong-score-me]');
    const scoreBot = document.querySelector('[data-test=pong-score-bot]');
    const rallyLabel = $('rally');
    const banner = $('banner');
    const toast = $('toast');

    const showScore = () => {
        scoreMe.textContent = String(game.score[0]);
        scoreBot.textContent = String(game.score[1]);
        document.body.dataset.score = `${game.score[0]}:${game.score[1]}`;
        rallyLabel.textContent = t('Rally :n', { n: game.rally });
    };

    const serve = () => {
        rally = game.serve(speeds);
        const seed = rallySeed(config.seed, game.rally);
        bots = [settings.autoplay ? createBot(settings.autoplay, 0, seed) : null, createBot(config.level, 1, seed)];
        counted = 0;
        showScore();
        const event = rally.event;
        if (event) {
            const [name, text] = eventText(event);
            banner.querySelector('small').textContent = t('Meme event');
            banner.querySelector('b').textContent = name;
            banner.querySelector('span').textContent = text;
            banner.hidden = false;
            banner.dataset.event = event;
            phase = 'announce';
            wait = ANNOUNCE_TICKS;
        } else {
            phase = 'serve';
            wait = SERVE_TICKS;
        }
        toast.hidden = true;
    };

    const finish = () => {
        phase = 'over';
        const won = game.winner === 0;
        banner.hidden = true;
        toast.hidden = true;
        $('end-title').textContent = won ? t('You win!') : t(':name wins', { name: config.bot });
        $('end-score').textContent = `${game.score[0]} : ${game.score[1]}`;
        $('end').hidden = false;
        document.body.dataset.result = won ? 'win' : 'loss';
    };

    const playerTarget = () => {
        if (bots[0]) return bots[0].target(rally);
        if (keys.up || keys.down) {
            target = Math.max(0, Math.min(HEIGHT, rally.paddles[0] + (keys.down ? KEY_STEP : -KEY_STEP)));
        }

        return target;
    };

    const tick = () => {
        if (phase === 'announce' || phase === 'serve') {
            if (--wait > 0) return;
            if (phase === 'announce') {
                phase = 'serve';
                wait = SERVE_TICKS;

                return;
            }
            banner.hidden = true;
            phase = 'play';

            return;
        }

        if (phase === 'point') {
            if (--wait > 0) return;
            if (game.winner !== null) {
                finish();

                return;
            }
            game.next();
            serve();

            return;
        }

        if (phase !== 'play') return;

        stepRally(rally, [playerTarget(), bots[1].target(rally)]);

        for (; counted < rally.goals.length; counted++) {
            const [, scorer] = rally.goals[counted];
            lastScorer = scorer;
            const won = game.goal(scorer, rally.points);
            showScore();
            toast.textContent = scorer === 0 ? t('Point for you') : t('Point for :name', { name: config.bot });
            toast.hidden = false;
            if (won) {
                counted++;
                break;
            }
        }

        if (rally.over || game.winner !== null) {
            phase = 'point';
            wait = POINT_TICKS;
        }
    };

    const view = () => (rally ? { balls: rally.balls, alive: rally.alive, paddles: rally.paddles, half: rally.half } : null);

    let last = performance.now();
    let acc = 0;
    let ticks = 0;
    const frame = (now) => {
        const dt = Math.min(now - last, 100);
        last = now;
        if (phase !== 'ready' && phase !== 'over') {
            acc += dt * settings.speed;
            while (acc >= TICK_MS) {
                acc -= TICK_MS;
                ticks++;
                tick();
                if (phase === 'over') break;
            }
        }
        const v = view();
        if (v) arena.render(v);
        document.body.dataset.phase = phase;
        requestAnimationFrame(frame);
    };

    const start = () => {
        if (phase !== 'ready') return;
        $('start').hidden = true;
        serve();
    };
    $('start-btn').addEventListener('click', start);

    // A frame before the start: the field with both paddles in the middle and the first ball waiting.
    arena.render({ balls: [], alive: [], paddles: [HEIGHT >> 1, HEIGHT >> 1], half: PADDLE_HALF });
    showScore();
    requestAnimationFrame(frame);

    // The browser test's handle: where the game stands.
    window.pongGame = {
        state: () => ({ phase, score: [...game.score], rally: game.rally, winner: game.winner, ticks, lastScorer, renderer: arena.kind, portrait }),
    };
    document.body.dataset.renderer = arena.kind;
    document.body.dataset.ready = '1';
}

boot();
