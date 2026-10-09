/**
 * Proof of Pong's show (plan "Proof of Pong", P3), shared by the game against a bot (game.js) and the live match
 * (live.js): everything a player sees and hears besides the game itself. The pages tell it what happened (a serve, a
 * meme event, a goal, the end); hits and wall bounces it reads from the frames it draws.
 *
 * - The figures (resources/js/pong/cast.json) in the HUD, on the paddles and in the goal celebration: the scorer's
 *   victory pose slides in from their side for at most 2.5 s, never over the middle of the field, gone on a click.
 * - A meme event takes the field over while it is announced: its icon, its name and line, its staging in the arena.
 *   The Arbeitsamt stamps a waiting ball with its number ("Ihre Wartenummer: 21"), with a line for Markus Turm when
 *   he plays; a ball bouncing off the tax block or the border wall sparks there instead of at a paddle.
 * - Sound (sound.js): blips, the goal, the event's sound, the figures' clips; the settings panel (gear in the HUD)
 *   for volume, effects, voices, music, the arena's quality and motion.
 * - Frame times: kept for the browser test (`window.pongFrames`) and, with quality `auto`, a slow device steps down
 *   a tier (high, medium, low) instead of stuttering.
 */
import { autoQuality } from './arena.js';
import { TICKER, eventIcon, figure, neon, oneOf, portrait as portraitOf, pose } from './cast.js';
import { eventText, writeSetting } from './page.js';
import { ARBEITSAMT, CONTROLS, HEIGHT, TAX, WIDTH } from './physics.js';
import { EVENTS } from './rules.js';
import { play, say, set as setSound, settings as sound } from './sound.js';

const CHEER_MS = 2200;
const FRAME_KEEP = 600;

export function createShow({ arena, settings, t, castTexts, figures: ids, arenaName }) {
    const $ = (id) => document.getElementById(id);
    const label = (key) => castTexts?.[key] ?? key;
    let figures = [figure(ids[0], 0), figure(ids[1], 1)];
    let motion = settings.motion;
    const previous = [];
    let cheerTimer = 0;
    let bannerTimer = 0;
    let goals = 0;
    // The score as the goals came (display sides), the side on a run and its length, the deepest deficit per side.
    const tally = [0, 0];
    const deficit = [0, 0];
    let run = 0;
    let runner = null;

    /* ---------- Figures ---------- */

    function showFigures() {
        arena.setFigures(figures);
        figures.forEach((f, side) => {
            const which = side === 0 ? 'me' : 'opponent';
            const face = document.querySelector(`[data-test=pong-face-${which}]`);
            if (face) {
                face.src = portraitOf(f);
                face.alt = label(f.name);
                face.style.setProperty('--ring', neon(f));
            }
            const name = document.querySelector(`[data-test=pong-figure-${which}]`);
            if (name) name.textContent = label(f.name);
        });
        document.body.style.setProperty('--side-0', neon(figures[0]));
        document.body.style.setProperty('--side-1', neon(figures[1]));
        document.body.dataset.figures = figures.map((f) => f.id).join(',');
        // The poses are in the cache before the first goal, so a celebration never waits for its picture.
        figures.forEach((f) => { new Image().src = pose(f); });
    }
    showFigures();
    arena.setArena(arenaName);
    EVENTS.forEach((event) => { new Image().src = eventIcon(event); });

    /* ---------- Frames: hits, walls, frame times ---------- */

    const frames = [];
    window.pongFrames = frames;
    let lastFrame = performance.now();
    let checkedAt = performance.now();

    function frame(view) {
        const now = performance.now();
        frames.push(now - lastFrame);
        if (frames.length > FRAME_KEEP) frames.shift();
        lastFrame = now;
        if (view) {
            view.balls.forEach((ball, index) => {
                const last = previous[index];
                if (last && view.alive[index] && Math.abs(last[0] - ball[0]) < 12000) {
                    if (Math.sign(last[2]) !== Math.sign(ball[2]) && ball[2] !== 0 && (view.event === TAX || view.event === CONTROLS) && Math.abs(ball[0] - WIDTH / 2) < WIDTH / 8) {
                        // Off the tax block or the border wall, not a paddle.
                        arena.wall(ball[0], ball[1]);
                        play.block();
                    } else if (Math.sign(last[2]) !== Math.sign(ball[2]) && ball[2] !== 0) {
                        const side = ball[2] > 0 ? 0 : 1;
                        arena.hit(side, ball[0], ball[1]);
                        play.hit(Math.round(Math.hypot(ball[2], ball[3]) / 120), side);
                        say('hit', figures[side]);
                    } else if (Math.sign(last[3]) !== Math.sign(ball[3]) && ball[3] !== 0) {
                        arena.wall(ball[0], ball[1]);
                        play.wall();
                    }
                }
                previous[index] = view.alive[index] ? [...ball] : null;
            });
            const waiting = view.event === ARBEITSAMT ? view.balls.find((ball, index) => view.alive[index] && ball.length > 5) : undefined;
            queue(!!waiting, waiting?.[1]);
        }
        arena.render(view);
        adapt(now);
    }

    /** Quality `auto`: two seconds of slow frames (p75 over 24 ms) step down one tier. */
    function adapt(now) {
        if (settings.quality !== 'auto' || arena.kind !== 'webgl' || now - checkedAt < 2000 || frames.length < 90) return;
        checkedAt = now;
        const recent = frames.slice(-90).sort((a, b) => a - b);
        if (recent[Math.floor(recent.length * 0.75)] > 24) {
            const next = { high: 'medium', medium: 'low' }[arena.quality];
            if (next) arena.setQuality(next);
        }
        document.body.dataset.quality = arena.quality;
    }

    /* ---------- The Arbeitsamt's queue ---------- */

    let queued = false;

    /**
     * The stamp and the waiting number while a ball waits; Markus Turm's line when he plays. The box never covers the
     * waiting ball (P8, review): lying, the ball waits on the vertical centre line, so the box goes to the other half
     * of that line; upright, the centre line is horizontal and the box sits above it.
     */
    function queue(waiting, ballY) {
        const box = $('queue');
        if (!box || waiting === queued) return;
        queued = waiting;
        if (!waiting) {
            box.hidden = true;

            return;
        }
        const upright = document.body.dataset.portrait === '1';
        box.dataset.at = upright || ballY > HEIGHT / 2 ? 'top' : 'bottom';
        box.querySelector('b').textContent = t('Stamped');
        box.querySelector('span').textContent = t('Your waiting number: :n', { n: 21 });
        const turm = figures.some((f) => f.id === 'turm');
        const line = box.querySelector('small');
        line.textContent = turm ? t('Markus Turm knows his way around here.') : '';
        line.hidden = !turm;
        box.hidden = false;
        box.classList.remove('in');
        void box.offsetWidth;
        box.classList.add('in');
        play.stamp();
        if (turm) say('turm', figures.find((f) => f.id === 'turm'));
    }

    /* ---------- Moments ---------- */

    /** The takeover of a meme event, for `ms` milliseconds (its announcement). */
    function announce(event, ms, seed = 0) {
        const banner = $('banner');
        const [name, line] = eventText(t, event);
        banner.querySelector('small').textContent = t('Meme event');
        banner.querySelector('b').textContent = name;
        banner.querySelector('span').textContent = line;
        banner.hidden = false;
        const icon = banner.querySelector('img');
        if (icon && icon.getAttribute('src') !== eventIcon(event)) {
            // Never the last event's picture: the icon shows once its own has loaded.
            icon.style.visibility = 'hidden';
            icon.onload = () => { icon.style.visibility = ''; };
            icon.src = eventIcon(event);
            if (icon.complete && icon.naturalWidth > 0) icon.style.visibility = '';
        }
        banner.dataset.event = event;
        banner.classList.remove('in');
        void banner.offsetWidth;
        banner.classList.add('in');
        arena.event(event, ms);
        // The page hides it when the rally starts; a moment staged on its own goes after its time as well.
        clearTimeout(bannerTimer);
        bannerTimer = setTimeout(() => { if (banner.dataset.event === event) banner.hidden = true; }, ms);
        play[({ difficulty: 'ratchet' })[event] ?? event]?.();
        say(`event:${event}`);
    }

    function serve(rally) {
        play.serve();
        // Every seventh rally the announcer says a line of the ticker.
        if (rally > 1 && rally % 7 === 0) {
            const line = oneOf(TICKER, rally / 7);
            const ticker = $('ticker');
            if (ticker && line) {
                ticker.textContent = label(line);
                ticker.hidden = false;
                ticker.classList.remove('in');
                void ticker.offsetWidth;
                ticker.classList.add('in');
                setTimeout(() => { ticker.hidden = true; }, 2600);
            }
        }
    }

    /**
     * A goal for display side `scorer` (0 the viewer's side), worth `points`: the explosion, the score's pop, the
     * scorer's celebration and maybe their clip.
     */
    function goal(scorer, points = 1) {
        goals++;
        tally[scorer] += points;
        run = runner === scorer ? run + 1 : 1;
        runner = scorer;
        const conceding = scorer === 0 ? WIDTH : 0;
        const ball = previous.filter(Boolean).sort((a, b) => Math.abs(a[0] - conceding) - Math.abs(b[0] - conceding))[0];
        arena.goal(scorer, ball ? ball[1] : HEIGHT / 2);
        play.goal(scorer === 0);
        pop(scorer === 0 ? 'me' : 'opponent');
        cheer(scorer, points);
        // Three in a row, or a goal back to within one after trailing by three or more: the streak's voice.
        const comeback = deficit[scorer] >= 3 && tally[1 - scorer] - tally[scorer] <= 1;
        if (comeback) deficit[scorer] = 0;
        deficit[1 - scorer] = Math.max(deficit[1 - scorer], tally[scorer] - tally[1 - scorer]);
        if (run === 3 || comeback) say('streak', figures[scorer]);
        else if (scorer === 0) say('goal', figures[0]);
        else say('conceded', figures[0]);
    }

    function pop(which) {
        const score = document.querySelector(`.hud .side.${which === 'me' ? 'me' : 'bot'} .score`);
        if (!score || !motion) return;
        score.classList.remove('pop');
        void score.offsetWidth;
        score.classList.add('pop');
    }

    function cheer(scorer, points) {
        const box = $('cheer');
        if (!box) return;
        const f = figures[scorer];
        box.querySelector('img').src = pose(f);
        box.querySelector('img').alt = label(f.name);
        box.querySelector('.cheer-text b').textContent = label(f.name);
        box.querySelector('.cheer-text > span').textContent = `+${points}`;
        box.dataset.side = String(scorer);
        box.style.setProperty('--tone', neon(f));
        box.hidden = false;
        box.classList.remove('in');
        void box.offsetWidth;
        box.classList.add('in');
        clearTimeout(cheerTimer);
        cheerTimer = setTimeout(() => { box.hidden = true; }, CHEER_MS);
    }

    $('cheer')?.addEventListener('pointerdown', () => {
        clearTimeout(cheerTimer);
        $('cheer').hidden = true;
    });
    addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            if ($('cheer')) $('cheer').hidden = true;
            closeSettings();
        }
    });

    /** The end: the winner's pose on the end card, the fanfare, their clip, the commentator for the loser. */
    function end(winner, mine) {
        clearTimeout(cheerTimer);
        if ($('cheer')) $('cheer').hidden = true;
        const img = $('end-pose');
        if (img && winner !== null) {
            const f = figures[winner];
            img.src = pose(f);
            img.alt = label(f.name);
            img.hidden = false;
            img.style.setProperty('--tone', neon(f));
        }
        const line = $('end-ticker');
        if (line) line.textContent = label(oneOf(TICKER, goals));
        if (winner === null) return;
        play.fanfare();
        if (mine === false) say('lose', figures[0]);
        else say('win', figures[winner]);
    }

    /* ---------- Settings panel ---------- */

    const panel = $('settings');
    const gear = $('settings-btn');

    function closeSettings() {
        if (!panel || panel.hidden) return;
        panel.hidden = true;
        gear?.setAttribute('aria-expanded', 'false');
    }

    if (panel && gear) {
        const field = (name) => panel.querySelector(`[name=${name}]`);
        field('vol').value = String(Math.round(sound.vol * 100));
        ['fx', 'board', 'music'].forEach((key) => { field(key).checked = sound[key]; });
        field('musicVol').value = String(Math.round(sound.musicVol * 100));
        field('quality').value = settings.quality;
        field('motion').checked = motion;

        gear.addEventListener('click', () => {
            panel.hidden = !panel.hidden;
            gear.setAttribute('aria-expanded', panel.hidden ? 'false' : 'true');
        });
        panel.querySelector('[data-close]').addEventListener('click', closeSettings);
        field('vol').addEventListener('input', (e) => setSound('vol', Number(e.target.value) / 100));
        field('musicVol').addEventListener('input', (e) => setSound('musicVol', Number(e.target.value) / 100));
        ['fx', 'board', 'music'].forEach((key) => field(key).addEventListener('change', (e) => setSound(key, e.target.checked)));
        field('quality').addEventListener('change', (e) => {
            settings.quality = e.target.value;
            writeSetting('quality', e.target.value);
            arena.setQuality(e.target.value === 'auto' ? autoQuality() : e.target.value);
            document.body.dataset.quality = arena.quality;
        });
        field('motion').addEventListener('change', (e) => {
            motion = e.target.checked;
            settings.motion = motion;
            writeSetting('motion', motion);
            arena.setMotion(motion);
            document.body.classList.toggle('still', !motion);
        });
    }
    document.body.classList.toggle('still', !motion);
    document.body.dataset.quality = arena.quality;

    // The credit of the MIDI track that plays (CC-BY and OGA-BY require it), gone when the music stops (P8).
    addEventListener('midi-track', (e) => {
        const track = e.detail;
        const line = $('now-playing');
        if (!line) return;
        line.hidden = !track;
        if (!track) return;
        const link = $('now-playing-title');
        link.textContent = track.title;
        if (track.source) link.href = track.source;
        else link.removeAttribute('href');
        $('now-playing-by').textContent = `– ${track.author} (${track.license})`;
    });

    return {
        frame,
        announce,
        serve,
        goal,
        end,
        figures: () => figures,
        setFigures(next) {
            figures = [figure(next[0], 0), figure(next[1], 1)];
            showFigures();
        },
        label,
        t,
    };
}
