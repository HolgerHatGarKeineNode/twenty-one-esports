/**
 * The private team chat of a Hyperbitcoinization team match (plan "Hyperbitcoinization", P4, Ansatz 6): a tab
 * beside the table chat, for the players of one team only. NIP-17 exactly as the league's match rooms write it
 * (resources/js/nostrChat.js): one kind-14 rumor with `p` = every teammate and `match` = `hyper:<ulid>`, sealed
 * (kind 13) and gift-wrapped (kind 1059) with NIP-44 separately for every teammate and once for oneself, signed
 * by the viewer's own signer (window.nostr: extension, or the NIP-46 / Google signer of the login). Each wrap
 * goes to the chat relays and its recipient's DM relays (10050), and the chat reads on both (dmInbox.js).
 *
 * Who the team is comes from the league (HyperMatchController::team(), HyperTeamChat::members()), which answers
 * only a player of the team: an opponent's or a spectator's page never has the team's keys and never opens
 * its wraps. Messages show the league name of the member, never a gamer tag, and only messages of this match
 * whose author and recipients are all members of the team (teamMessages()).
 *
 * The chat connects when the tab is first opened: opening a wrap asks the signer to decrypt.
 */
import { SimplePool } from 'nostr-tools/pool';
import { extraInboxRelays, lookupInboxes, relaysFor } from '../dmInbox.js';
import { canEncrypt, chatSince, isRumor, unwrapMessage, wrapGroupMessage } from '../nostrChat.js';
import { ensureSigner } from '../nostrSign.js';
import { sendBlocker } from '../streamChat.js';
import { t } from './i18n.js';

const $ = (s) => document.querySelector(s);

/**
 * The team's messages: this match's `match` tag, written by a member, every recipient a member, addressed to me
 * or written by me; each once, oldest first. As nostrChat.js roomMessages(), except that a message without
 * recipients counts too: a player whose teammates are all bots writes to nobody but the copy to self.
 */
export function teamMessages(rumors, { me, members, match }) {
    const team = new Set(members);
    const seen = new Set();

    return rumors.filter((rumor) => {
        if (!isRumor(rumor) || seen.has(rumor.id)) return false;
        const recipients = rumor.tags.filter((tag) => tag[0] === 'p').map((tag) => tag[1]);
        const ours = rumor.tags.some((tag) => tag[0] === 'match' && tag[1] === String(match)) && team.has(rumor.pubkey) && recipients.every((p) => team.has(p));
        const mine = rumor.pubkey === me || recipients.includes(me);
        if (!ours || !mine) return false;
        seen.add(rumor.id);

        return true;
    }).sort((a, b) => a.created_at - b.created_at);
}

export function startTeamChat(config, { http, onUnread = () => {} } = {}) {
    const pane = $('#team-pane');
    const list = $('#team-list');
    const form = $('#team-form');
    const input = $('#team-input');
    const note = $('#team-note');
    const rumors = new Map();
    let team = null;
    let pool = null;
    let inboxes = Promise.resolve(new Map());
    let started = false;
    let visible = false;
    let unread = 0;
    let sending = false;
    let lastSentAt = null;
    const me = document.querySelector('meta[name="nostr-session"]')?.content || null;

    const status = (value, text = '') => { pane.dataset.status = value; note.textContent = text; form.hidden = value !== 'live'; render(); };
    const member = (pubkey) => team?.members.find((m) => m.pubkey === pubkey) ?? null;

    function messages() {
        if (!team) return [];

        return teamMessages([...rumors.values()], { me, members: team.members.map((m) => m.pubkey), match: team.match });
    }

    function render() {
        const items = messages();
        list.textContent = '';
        if (!items.length) {
            const li = document.createElement('li');
            li.className = 'cm-empty';
            li.textContent = pane.dataset.status === 'live' ? t('Only your team reads this. Plan your next move.') : pane.dataset.status === 'starting' ? t('Connecting to the chat …') : '';
            list.appendChild(li);
        }
        for (const rumor of items.slice(-200)) {
            const who = member(rumor.pubkey);
            const li = document.createElement('li');
            li.className = 'cm' + (rumor.pubkey === me ? ' own' : '');
            li.dataset.test = 'hyper-team-message';
            li.innerHTML = '<img class="cm-av" alt="" width="28" height="28" referrerpolicy="no-referrer" loading="lazy"><div class="cm-body"><div class="cm-head"><b class="cm-name"></b><time class="cm-time"></time></div><p class="cm-text" data-test="hyper-team-text"></p></div>';
            const img = li.querySelector('.cm-av');
            if (who?.avatar) img.src = who.avatar; else img.remove();
            li.querySelector('.cm-name').textContent = who?.name ?? t('Someone');
            const time = li.querySelector('.cm-time');
            time.dateTime = new Date(rumor.created_at * 1000).toISOString();
            time.textContent = new Date(rumor.created_at * 1000).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
            li.querySelector('.cm-text').textContent = rumor.content;
            list.appendChild(li);
        }
        list.scrollTop = list.scrollHeight;
    }

    function add(rumor) {
        if (rumors.has(rumor.id)) return;
        rumors.set(rumor.id, rumor);
        const counted = messages().some((m) => m.id === rumor.id);
        if (counted && !visible && rumor.pubkey !== me) { unread++; onUnread(unread); }
        render();
    }

    async function receive(wrap) {
        try {
            add(await unwrapMessage(window.nostr, wrap, me));
        } catch {
            // Not a message of ours: someone else's wrap, a forgery, or another chat's.
        }
    }

    async function start() {
        if (started) return;
        started = true;
        status('starting');
        const answer = await http.get(config.url);
        if (!answer.ok || !Array.isArray(answer.data?.members)) { started = false; status('off', t('Only your team reads this chat.')); return; }
        team = answer.data;
        if (!me || (team.relays ?? []).length === 0) { status('off', t('The team chat is off here: no chat relay is set up.')); return; }
        if (!(await ensureSigner())) { started = false; status('signer', t('No Nostr signer found. Install a Nostr browser extension or use a remote signer.')); return; }
        if (!canEncrypt(window.nostr)) { status('signer', t('Your signer cannot encrypt (NIP-44), so the team chat stays closed.')); return; }

        pool = new SimplePool();
        const filter = { kinds: [1059], '#p': [me], since: chatSince(team.since ?? null) };
        const handlers = { onevent: receive, onauth: (template) => window.nostr.signEvent(template) };
        pool.subscribe(team.relays, filter, handlers);
        const lookup = [...(team.lookupRelays ?? []), ...team.relays];
        inboxes = lookupInboxes(team.members.map((m) => m.pubkey), lookup, { trusted: lookup }).catch(() => new Map());
        inboxes.then((found) => {
            const extra = extraInboxRelays(me, team.relays, found);
            if (extra.length > 0) pool.subscribe(extra, filter, handlers);
        });
        status('live', team.members.length > 1 ? t('Private: sealed for :names.', { names: team.members.filter((m) => m.pubkey !== me).map((m) => m.name).join(', ') }) : t('Your teammates are bots: nobody else reads this.'));
    }

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        if (sending || pane.dataset.status !== 'live') return;
        const blocker = sendBlocker(input.value, { maxLength: 280, cooldownMs: 2000, lastSentAt });
        if (blocker === 'empty') return;
        if (blocker !== null) { note.textContent = blocker === 'tooLong' ? t('Keep it to :max characters.', { max: 280 }) : t('One message every 2 seconds. Try again in a moment.'); return; }
        const content = input.value.trim();
        sending = true;
        input.value = '';
        lastSentAt = Date.now();
        try {
            // Every member of the team as the server named them, and nobody else: the wraps go to exactly these keys.
            const { rumor, wraps, targets } = await wrapGroupMessage(window.nostr, { sender: me, recipients: team.members.map((m) => m.pubkey), content, match: team.match });
            const found = await inboxes;
            const acks = wraps.flatMap((wrap, i) => pool.publish(relaysFor(targets[i], team.relays, found), wrap, { onauth: (template) => window.nostr.signEvent(template) }));
            await Promise.any(acks);
            add(rumor);
        } catch (error) {
            console.warn('[hyper team chat] not sent', error);
            input.value ||= content;
            note.textContent = t('The message did not reach any relay. Please try again.');
        } finally {
            sending = false;
        }
    });

    pane.dataset.status = 'idle';
    form.hidden = true;
    render();

    return {
        show() { visible = true; pane.hidden = false; unread = 0; onUnread(0); start(); if (pane.dataset.status === 'live') input.focus({ preventScroll: true }); },
        hide() { visible = false; pane.hidden = true; },
        status: () => pane.dataset.status,
        /** For the browser test: the keys the wraps go to. */
        members: () => (team?.members ?? []).map((m) => m.pubkey),
    };
}
