/**
 * The table chat of a Hyperbitcoinization match (plan "Hyperbitcoinization", P2, Ansatz 6): the match's own
 * NIP-28 channel (GameChannels::matchChannelId(), kind 42), players and spectators in one channel (user,
 * 2026-10-08), with NIP-25 reactions (reactions.js) under each message. Read and written straight from the
 * browser on the chat relays, signed by the viewer's own signer, exactly as the game channels
 * (resources/js/gameChannel.js): the same channel rules (channelChat.js), the same text tokenizer and send
 * limits (streamChat.js), the same signer path (nostrSign.js, signing.js). The league server never sees a
 * message.
 *
 * Names come from the league, as in the game channels: the seats' league names, and for anybody else the
 * league's answer for the pubkey (HyperMatchController::people(), GameChannels::players()); a pubkey the
 * league does not know shows as a short npub. Never a gamer tag. The channel creator's kind 43/44 and the
 * keys an admin muted site-wide hide messages and reactions for everyone. Guests read only. Without a
 * channel id or relays the drawer says the chat is off.
 */
import { SimplePool } from 'nostr-tools/pool';
import { npubEncode } from 'nostr-tools/nip19';
import { KIND_HIDE, KIND_MESSAGE, KIND_MUTE, isChannelMessage, messageTemplate, moderation } from '../channelChat.js';
import { ensureSigner } from '../nostrSign.js';
import { signerMessage, signTemplate } from '../signing.js';
import { sendBlocker, tokenize } from '../streamChat.js';
import { KIND_REACTION, REACTIONS, addReaction, countsFor, reactionTemplate } from './reactions.js';
import { t } from './i18n.js';

const KEEP = 200;
const LOOKUP_BATCH = 50;

const $ = (s) => document.querySelector(s);

/** One REQ per relay carrying every filter (as gameChannel.js: SimplePool.subscribe() takes one filter). */
function subscribeFilters(pool, relays, filters, params) {
    return pool.subscribeMap([...new Set(relays)].flatMap((url) => filters.map((filter) => ({ url, filter }))), params);
}

function shortNpub(pubkey) {
    try {
        const npub = npubEncode(pubkey);

        return npub.slice(0, 10) + '…' + npub.slice(-4);
    } catch {
        return t('Someone');
    }
}

export function startChat(config, { seats = [], onUnread = () => {} } = {}) {
    const drawer = $('#chat');
    const list = $('#chat-list');
    const form = $('#chat-form');
    const input = $('#chat-input');
    const note = $('#chat-note');
    const people = new Map();
    const asked = new Set();
    const queue = new Set();
    const messages = new Map();
    const book = new Map();
    const modEvents = [];
    let mod = { hidden: new Set(), muted: new Set() };
    const siteHidden = new Set(config.hidden ?? []);
    let unread = 0;
    let lastSentAt = null;
    let sending = false;
    let pool = null;
    let lookupTimer = 0;
    let picker = null;
    const me = config.me ?? null;
    const off = !config.channel || (config.relays ?? []).length === 0;

    seats.forEach((s) => { if (s.pubkey) { people.set(s.pubkey, { name: s.name, avatar: s.avatar }); asked.add(s.pubkey); } });
    if (me && config.meName) { people.set(me, { name: config.meName, avatar: config.meAvatar ?? null }); asked.add(me); }

    drawer.dataset.status = off ? 'off' : 'connecting';
    $('#chat-off').hidden = !off;
    form.hidden = off || !me;
    note.textContent = off ? '' : me ? t('Public on Nostr, signed with your key.') : t('Log in to write in the table chat.');
    input.maxLength = (config.maxLength ?? 280) * 2;

    const leftOut = (pubkey, id = null) => siteHidden.has(pubkey) || mod.muted.has(pubkey) || (id !== null && mod.hidden.has(id));
    const nameOf = (pubkey) => people.get(pubkey)?.name || shortNpub(pubkey);
    const avatarOf = (pubkey) => people.get(pubkey)?.avatar || (config.avatarUrl ?? '').replace(config.avatarPlaceholder ?? '0'.repeat(64), pubkey);
    const hiddenSet = () => new Set([...siteHidden, ...mod.muted]);

    function wantPerson(pubkey) {
        if (asked.has(pubkey) || !config.peopleUrl) return;
        asked.add(pubkey);
        queue.add(pubkey);
        clearTimeout(lookupTimer);
        lookupTimer = setTimeout(lookup, 250);
    }

    async function lookup() {
        const batch = [...queue].slice(0, LOOKUP_BATCH);
        batch.forEach((key) => queue.delete(key));
        if (queue.size) lookupTimer = setTimeout(lookup, 500);
        if (!batch.length) return;
        try {
            const response = await fetch(`${config.peopleUrl}?keys=${batch.join(',')}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            const answer = response.ok ? await response.json() : {};
            batch.forEach((key) => { const found = answer?.[key]; if (found && typeof found === 'object') people.set(key, { name: String(found.name ?? ''), avatar: String(found.avatar ?? '') }); });
            renderAll();
        } catch (error) {
            console.warn('[hyper chat] name lookup failed', error);
        }
    }

    function tokensHtml(tokens, li) {
        const p = li.querySelector('.cm-text');
        p.textContent = '';
        for (const token of tokens) {
            if (token.type === 'emoji' && token.url) {
                const img = document.createElement('img'); img.src = token.url; img.alt = ':' + token.value + ':'; img.className = 'cm-emoji'; img.referrerPolicy = 'no-referrer'; p.appendChild(img);
            } else if (token.type === 'mention') {
                p.append('@' + nameOf(token.pubkey));
            } else if (token.type === 'ref') {
                p.append(t('Quoted message'));
            } else {
                p.append(token.value);
            }
        }
    }

    function reactionsHtml(li, id) {
        const box = li.querySelector('.cm-reactions');
        box.textContent = '';
        for (const entry of countsFor(book, id, { me, hidden: hiddenSet() })) {
            const chip = document.createElement('button');
            chip.type = 'button'; chip.className = 'cm-chip' + (entry.mine ? ' mine' : '');
            chip.dataset.test = 'hyper-chat-reaction'; chip.dataset.emoji = entry.emoji;
            chip.textContent = `${entry.emoji} ${entry.count}`;
            chip.setAttribute('aria-label', t(':emoji, :count', { emoji: entry.emoji, count: entry.count }));
            chip.disabled = !me || entry.mine;
            chip.onclick = () => react(id, entry.emoji);
            box.appendChild(chip);
        }
        if (me) {
            const add = document.createElement('button');
            add.type = 'button'; add.className = 'cm-add'; add.dataset.test = 'hyper-chat-react'; add.textContent = '+';
            add.setAttribute('aria-label', t('React'));
            add.onclick = (e) => { e.stopPropagation(); openPicker(id, add); };
            box.appendChild(add);
        }
    }

    function openPicker(id, anchor) {
        closePicker();
        picker = document.createElement('div');
        picker.className = 'cm-picker frame'; picker.setAttribute('role', 'menu');
        REACTIONS.forEach((emoji) => {
            const btn = document.createElement('button');
            btn.type = 'button'; btn.textContent = emoji; btn.dataset.emoji = emoji; btn.setAttribute('role', 'menuitem');
            btn.onclick = () => { closePicker(); react(id, emoji); };
            picker.appendChild(btn);
        });
        anchor.after(picker);
    }
    function closePicker() { picker?.remove(); picker = null; }
    document.addEventListener('pointerdown', (e) => { if (picker && !picker.contains(e.target)) closePicker(); });

    function row(item) {
        const li = document.createElement('li');
        li.className = 'cm' + (item.pubkey === me ? ' own' : '');
        li.dataset.test = 'hyper-chat-message'; li.dataset.id = item.id; li.dataset.pubkey = item.pubkey;
        li.innerHTML = '<img class="cm-av" alt="" width="28" height="28" referrerpolicy="no-referrer" loading="lazy"><div class="cm-body"><div class="cm-head"><b class="cm-name"></b><time class="cm-time"></time></div><p class="cm-text" data-test="hyper-chat-text"></p><div class="cm-reactions"></div></div>';
        const img = li.querySelector('.cm-av'); img.src = avatarOf(item.pubkey); img.onerror = () => { img.onerror = null; img.src = (config.avatarUrl ?? '').replace(config.avatarPlaceholder ?? '0'.repeat(64), item.pubkey); };
        li.querySelector('.cm-name').textContent = nameOf(item.pubkey);
        const time = li.querySelector('.cm-time'); time.dateTime = new Date(item.created_at * 1000).toISOString();
        time.textContent = new Date(item.created_at * 1000).toLocaleTimeString(config.locale ?? undefined, { hour: '2-digit', minute: '2-digit' });
        tokensHtml(item.tokens, li);
        reactionsHtml(li, item.id);

        return li;
    }

    function renderAll() {
        const atBottom = list.scrollHeight - list.scrollTop - list.clientHeight < 48;
        const items = [...messages.values()].filter((m) => !leftOut(m.pubkey, m.id)).sort((a, c) => a.created_at - c.created_at || (a.id < c.id ? -1 : 1));
        list.textContent = '';
        if (!items.length) {
            const li = document.createElement('li'); li.className = 'cm-empty';
            li.textContent = off ? '' : drawer.dataset.status === 'live' ? t('No messages yet. Say hello to the table.') : t('Connecting to the chat …');
            list.appendChild(li);
        }
        items.slice(-KEEP).forEach((item) => list.appendChild(row(item)));
        if (atBottom || list.dataset.follow !== '0') list.scrollTop = list.scrollHeight;
    }

    function receive(event) {
        if (event?.kind === KIND_HIDE || event?.kind === KIND_MUTE) {
            if (event.pubkey !== config.creator || modEvents.length >= 1000) return;
            modEvents.push(event);
            const m = moderation(modEvents, config.creator);
            mod = { hidden: new Set(m.hidden), muted: new Set(m.muted) };
            renderAll();

            return;
        }
        if (event?.kind === KIND_REACTION) {
            const target = addReaction(book, event, { channel: config.channel });
            if (target === null) return;
            wantPerson(event.pubkey);
            const li = list.querySelector(`[data-id="${CSS.escape(target)}"]`);
            if (li) reactionsHtml(li, target);

            return;
        }
        if (!isChannelMessage(event, config.channel) || messages.has(event.id)) return;
        messages.set(event.id, { id: event.id, pubkey: event.pubkey, created_at: event.created_at, tokens: tokenize(event.content, event.tags) });
        if (messages.size > KEEP * 2) {
            const oldest = [...messages.values()].sort((a, c) => a.created_at - c.created_at).slice(0, messages.size - KEEP);
            oldest.forEach((m) => messages.delete(m.id));
        }
        wantPerson(event.pubkey);
        if (drawer.hidden && event.pubkey !== me && drawer.dataset.status === 'live' && !leftOut(event.pubkey, event.id)) { unread++; onUnread(unread); }
        renderAll();
    }

    async function publish(template) {
        if (!(await ensureSigner())) { note.textContent = t('No Nostr signer found. Install a Nostr browser extension or use a remote signer.'); return null; }
        let signed;
        try {
            signed = await signTemplate(template, { pubkey: me });
        } catch (error) {
            note.textContent = signerMessage(config.signer ?? {}, error);

            return null;
        }
        try {
            await Promise.any(pool.publish(config.relays, signed));
        } catch {
            note.textContent = t('The message did not reach any relay. Please try again.');

            return null;
        }

        return signed;
    }

    async function react(id, emoji) {
        const message = messages.get(id);
        if (!me || !message || !pool) return;
        const signed = await publish(reactionTemplate(message, emoji, { channel: config.channel, relayHint: config.relayHint ?? '' }));
        if (signed) receive(signed);
    }

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        if (!me || sending || drawer.dataset.status !== 'live') return;
        const blocker = sendBlocker(input.value, { maxLength: config.maxLength ?? 280, cooldownMs: config.cooldownMs ?? 2000, lastSentAt });
        if (blocker === 'empty') return;
        if (blocker !== null) { note.textContent = blocker === 'tooLong' ? t('Keep it to :max characters.', { max: config.maxLength ?? 280 }) : t('One message every 2 seconds. Try again in a moment.'); return; }
        const content = input.value;
        sending = true; note.textContent = '';
        try {
            lastSentAt = Date.now();
            input.value = '';
            const signed = await publish(messageTemplate(content, { channel: config.channel, relayHint: config.relayHint ?? '' }));
            if (signed === null) { input.value ||= content; return; }
            list.dataset.follow = '1';
            receive(signed);
        } finally {
            sending = false;
        }
    });
    list.addEventListener('scroll', () => { list.dataset.follow = list.scrollHeight - list.scrollTop - list.clientHeight < 48 ? '1' : '0'; }, { passive: true });

    function caughtUp() {
        clearTimeout(caughtUp.timer);
        if (drawer.dataset.status !== 'connecting') return;
        drawer.dataset.status = 'live';
        renderAll();
    }

    if (!off) {
        pool = new SimplePool();
        subscribeFilters(pool, config.relays, [
            { kinds: [KIND_MESSAGE], '#e': [config.channel], limit: config.history ?? 120 },
            { kinds: [KIND_REACTION], '#e': [config.channel], limit: 1000 },
            ...(config.creator ? [{ kinds: [KIND_HIDE, KIND_MUTE], authors: [config.creator], limit: 500 }] : []),
        ], { onevent: receive, oneose: caughtUp, maxWait: 6000 });
        caughtUp.timer = setTimeout(caughtUp, 6500);
    }
    renderAll();

    return {
        open() { drawer.hidden = false; unread = 0; onUnread(0); list.dataset.follow = '1'; renderAll(); if (me && !off) input.focus({ preventScroll: true }); },
        close() { drawer.hidden = true; closePicker(); },
        toggle() { if (drawer.hidden) this.open(); else this.close(); },
        status: () => drawer.dataset.status,
    };
}
