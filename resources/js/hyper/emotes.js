/**
 * Quick emotes at the table (plan "Hyperbitcoinization", Ansatz 6): meme stickers and soundboard clips, sent
 * by a seated player through `POST …/emote` and shown to everyone by `hyper.emote` over Reverb, never on
 * Nostr and never stored. The server throttles (3 stickers a minute, 1 clip per turn); a 429 says when to try
 * again, and the panel counts down. An incoming emote rises over the sender's portrait; a clip plays unless the
 * soundboard is muted (audio.js).
 */
import { STICKERS, clipLabel } from './data.js';
import { emoteClip, sfx } from './audio.js';
import { t } from './i18n.js';

const $ = (s) => document.querySelector(s);

export function startEmotes(config, net, game, { live = () => true } = {}) {
    const panel = $('#emotes');
    const note = $('#emote-note');
    let blockedUntil = { sticker: 0, clip: 0 };
    let countdown = 0;

    function show({ seat, kind, emote }) {
        const rect = game.seatRect(seat);
        const layer = $('#emote-layer');
        if (!layer) return;
        const el = document.createElement('div');
        el.className = 'emote-bubble ' + kind;
        el.dataset.test = 'hyper-emote'; el.dataset.emote = emote; el.dataset.seat = String(seat);
        el.style.setProperty('--pc', game.seatColor(seat));
        el.textContent = kind === 'sticker' ? (STICKERS[emote] ?? emote) : '🔊 ' + clipLabel(emote);
        el.setAttribute('role', 'status');
        el.setAttribute('aria-label', t(':name: :emote', { name: game.seatName(seat), emote: el.textContent }));
        const x = rect ? rect.left + rect.width / 2 : innerWidth / 2;
        const y = rect ? rect.top + rect.height / 2 : 120;
        el.style.left = Math.max(8, Math.min(innerWidth - 8, x)) + 'px';
        el.style.top = Math.max(8, y) + 'px';
        layer.appendChild(el);
        if (kind === 'clip') emoteClip(emote); else sfx.coin();
        const gsap = window.gsap;
        if (gsap && !matchMedia('(prefers-reduced-motion: reduce)').matches) {
            gsap.fromTo(el, { scale: 0.4, opacity: 0, y: 10 }, { scale: 1, opacity: 1, y: -28, duration: 0.45, ease: 'back.out(2.5)' });
            gsap.to(el, { opacity: 0, y: -60, duration: 0.6, delay: 3.2, ease: 'power2.in', onComplete: () => el.remove() });
        } else {
            setTimeout(() => el.remove(), 3500);
        }
    }

    function tick() {
        clearInterval(countdown);
        const step = () => {
            const now = Date.now();
            const sticker = Math.ceil(Math.max(0, blockedUntil.sticker - now) / 1000);
            const clip = Math.ceil(Math.max(0, blockedUntil.clip - now) / 1000);
            panel.querySelectorAll('[data-kind="sticker"]').forEach((b) => { b.disabled = sticker > 0; });
            panel.querySelectorAll('[data-kind="clip"]').forEach((b) => { b.disabled = clip > 0; });
            if (sticker <= 0 && clip <= 0) { clearInterval(countdown); note.textContent = ''; return; }
            note.textContent = sticker > 0 ? t('Not so fast. Stickers again in :seconds s.', { seconds: sticker }) : t('One clip per turn. The next in :seconds s or next turn.', { seconds: clip });
        };
        step();
        countdown = setInterval(step, 500);
    }

    async function send(kind, emote) {
        const r = await net.post(config.urls.emote, { emote });
        if (r.ok && r.data) {
            panel.hidden = true;
            if (!live()) show(r.data);

            return;
        }
        sfx.error();
        if (r.status === 429) {
            const seconds = Math.max(1, Number(r.data?.retry_after ?? 5));
            blockedUntil = { ...blockedUntil, [kind]: Date.now() + seconds * 1000 };
            note.dataset.test = 'hyper-emote-throttled';
            tick();
            note.textContent = r.data?.message ?? note.textContent;

            return;
        }
        note.textContent = r.data?.message ?? t('The server did not answer. Please try again.');
    }

    const stickers = $('#emote-stickers');
    Object.entries(STICKERS).forEach(([id, label]) => {
        const b = document.createElement('button');
        b.type = 'button'; b.className = 'sticker'; b.dataset.kind = 'sticker'; b.dataset.emote = id; b.textContent = label;
        b.onclick = () => send('sticker', id);
        stickers.appendChild(b);
    });
    const clips = $('#emote-clips');
    (config.clips ?? []).forEach((id) => {
        const b = document.createElement('button');
        b.type = 'button'; b.className = 'clip'; b.dataset.kind = 'clip'; b.dataset.emote = id; b.textContent = clipLabel(id);
        b.onclick = () => send('clip', id);
        clips.appendChild(b);
    });
    $('#emote-filter')?.addEventListener('input', (e) => {
        const q = e.target.value.trim().toLowerCase();
        clips.querySelectorAll('button').forEach((b) => { b.hidden = q !== '' && !b.textContent.toLowerCase().includes(q); });
    });
    $('#emote-btn')?.addEventListener('click', () => { panel.hidden = !panel.hidden; if (!panel.hidden) $('#chat').hidden = true; });
    $('#emote-close')?.addEventListener('click', () => { panel.hidden = true; });

    return { show };
}
