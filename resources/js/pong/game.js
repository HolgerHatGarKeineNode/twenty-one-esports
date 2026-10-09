/**
 * Proof of Pong's game page against a bot (plan "Proof of Pong", P1; resources/views/pong/match.blade.php): runs the
 * game on the deterministic core (rules.js, physics.js, bot.js; the server's App\Support\Pong) at a fixed 60 ticks a
 * second and hands each frame to the arena (arena.js) to draw. The player is side 0 (left, or at the bottom on a
 * phone held upright), the bot side 1.
 *
 * A rally goes: an announced meme event (all nine in every block of 21 rallies), a short countdown, play, then a pause on the point.
 * Goals count as they fall, so a Pizza Day rally can end the game with its first ball.
 *
 * The field and the player's input are page.js's (shared with the live match, live.js).
 *
 * Settings (localStorage `pong-settings`, the browser test's handle): `speed` runs the clock faster (1 = real time),
 * `autoplay` lets a bot of that level play the player's paddle, `eventEvery` sets the block of rallies that holds the events (1: every rally an event). With both, a game is the one PongGame::bots() plays
 * for the same seed and levels, tick for tick.
 */
import { botSpeed, createBot } from './bot.js';
import './picker.js';
import { createStage, readConfig, readSettings } from './page.js';
import { HEIGHT, PADDLE_HALF, PLAYER_SPEED, TICKS_PER_SECOND, rallyHalf, stepRally } from './physics.js';
import { createGame, rallySeed } from './rules.js';
import { createShow } from './show.js';
import { musicState } from './sound.js';

const ANNOUNCE_TICKS = 110;
const SERVE_TICKS = 50;
const POINT_TICKS = 70;
const TICK_MS = 1000 / TICKS_PER_SECOND;

function boot() {
    const { config, t } = readConfig();
    const settings = readSettings();
    const $ = (id) => document.getElementById(id);
    const { arena, portrait: isPortrait, input } = createStage();
    const show = createShow({ arena, settings, t, castTexts: config.castTexts, figures: [config.figure, config.botFigure], arenaName: config.arena });

    const game = createGame(config.seed, settings.eventEvery ? { ...config.rules, event_block_rallies: settings.eventEvery } : config.rules);
    const speeds = [settings.autoplay ? botSpeed(settings.autoplay) : PLAYER_SPEED, botSpeed(config.level)];
    let phase = 'ready';
    let wait = 0;
    let rally = null;
    let bots = [];
    let counted = 0;
    let lastScorer = null;
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
            show.announce(event, (ANNOUNCE_TICKS * TICK_MS) / settings.speed, game.rally);
            phase = 'announce';
            wait = ANNOUNCE_TICKS;
        } else {
            phase = 'serve';
            wait = SERVE_TICKS;
        }
        show.serve(game.rally);
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
        show.end(game.winner, won);
    };

    const playerTarget = () => (bots[0] ? bots[0].target(rally) : input.target(rally.paddles[0]));

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
            show.goal(scorer, rally.points);
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

    const idle = { balls: [], alive: [], paddles: [HEIGHT >> 1, HEIGHT >> 1], half: PADDLE_HALF, event: null };
    const view = () => (rally ? { balls: rally.balls, alive: rally.alive, paddles: rally.paddles, half: rally.half, halves: [rallyHalf(rally, 0), rallyHalf(rally, 1)], event: phase === 'point' ? null : rally.event, seed: rally.seed, tick: rally.tick } : idle);

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
        show.frame(view());
        document.body.dataset.phase = phase;
        requestAnimationFrame(frame);
    };

    const start = () => {
        if (phase !== 'ready') return;
        $('start').hidden = true;
        serve();
    };
    $('start-btn').addEventListener('click', start);

    // The figure picked on the start card plays the player's paddle (resources/js/pong/picker.js keeps the pick).
    document.addEventListener('pong-figure', (event) => {
        if (phase !== 'ready') return;
        show.setFigures([event.detail.id, config.botFigure]);
        const again = document.querySelector('[data-test=pong-again]');
        if (again) {
            const url = new URL(again.href, location.href);
            url.searchParams.set('figure', event.detail.id);
            again.href = url.pathname + url.search;
        }
    });

    const picked = document.querySelector('#start [data-pong-picker]')?.dataset.picked;
    if (picked) show.setFigures([picked, config.botFigure]);
    showScore();
    requestAnimationFrame(frame);

    // The browser test's handles: where the game stands, and the show (to stage a moment for a picture).
    window.pongShow = show;
    window.pongGame = {
        state: () => ({ phase, score: [...game.score], rally: game.rally, winner: game.winner, ticks, event: rally?.event ?? null, halves: rally ? [rallyHalf(rally, 0), rallyHalf(rally, 1)] : null, waiting: rally ? rally.balls.some((ball) => ball.length > 5) : false, sideHits: rally ? [...rally.sideHits] : null, lastScorer, renderer: arena.kind, quality: arena.quality, gpu: arena.gpu ?? null, draws: arena.stats ? arena.stats() : null, drawn: arena.drawn ? arena.drawn() : null, figures: show.figures().map((f) => f.id), portrait: isPortrait(), music: musicState() }),
    };
    document.body.dataset.renderer = arena.kind;
    document.body.dataset.ready = '1';
}

boot();
