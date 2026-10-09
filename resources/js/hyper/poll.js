/**
 * The spectators' "Who wins?" of a rated or tournament Hyperbitcoinization match (plan "Hyperbitcoinization", P5,
 * App\Support\Hyper\HyperPoll): a NIP-88 poll in the match's table chat channel whose id the league fixed from the
 * match alone, so the page counts votes whether or not the league has published the poll itself.
 *
 * Votes (kind 1018) are signed by the viewer's own signer and sent to the chat relays, as the game channels' polls
 * are (channelChat.js: parseVote, addVote, tally, voteTemplate). Counted are league accounts that count in the
 * game channels (members, players with a result: the people lookup's `counts`), never a seat of this match. The
 * poll closes when the match ends: a vote signed after the server's end time is not counted (also on a page that was
 * open when it ended: the book is counted again, P5c), and nobody votes any more.
 *
 * The panel opens on a click and stays until it is closed (no auto-advance, user 2026-10-09).
 */
import { SimplePool } from 'nostr-tools/pool';
import { KIND_VOTE, addVote, parseVote, tally, voteTemplate } from '../channelChat.js';
import { ensureSigner } from '../nostrSign.js';
import { signerMessage, signTemplate } from '../signing.js';
import { t } from './i18n.js';

const $ = (s) => document.querySelector(s);
const LOOKUP_BATCH = 50;

export function startPoll(config, chat) {
    const panel = $('#poll');
    const list = $('#poll-options');
    const note = $('#poll-note');
    const total = $('#poll-total');
    const players = new Set(config.players ?? []);
    const people = new Map();
    const asked = new Set();
    const queue = new Set();
    const book = new Map();
    // Every valid vote received, so the book can be counted again once the server's end time is known.
    const seen = [];
    const me = config.me ?? null;
    const relays = chat?.relays ?? [];
    let closedAt = Number.isSafeInteger(config.closedAt) ? config.closedAt : null;
    let pool = null;
    let lookupTimer = 0;
    let sending = false;

    const poll = () => ({
        id: config.id,
        created_at: config.created_at,
        endsAt: closedAt === null ? config.endsAt : Math.min(config.endsAt, closedAt),
        options: config.options.map((option) => ({ id: option.id, label: option.label })),
    });
    const closed = () => closedAt !== null || Math.floor(Date.now() / 1000) >= config.endsAt;
    const counts = (pubkey) => !players.has(pubkey) && people.get(pubkey)?.counts === true;

    function render() {
        const current = poll();
        const outcome = tally(current, book, { counts, me });
        const mine = players.has(me) ? null : outcome.mine;
        list.textContent = '';

        for (const option of current.options) {
            const votes = outcome.counts[option.id] ?? 0;
            const share = outcome.total === 0 ? 0 : Math.round((votes / outcome.total) * 100);
            const li = document.createElement('li');
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'poll-option' + (mine === option.id ? ' mine' : '');
            button.dataset.test = 'hyper-poll-option';
            button.dataset.option = option.id;
            button.disabled = closed() || !me || sending;
            button.setAttribute('aria-pressed', String(mine === option.id));
            button.innerHTML = '<span class="po-label"></span><span class="po-count"></span><span class="po-bar" aria-hidden="true"><i></i></span>';
            button.querySelector('.po-label').textContent = option.label;
            button.querySelector('.po-count').textContent = `${share} % · ${votes}`;
            button.querySelector('.po-bar i').style.width = `${share}%`;
            button.onclick = () => vote(option.id);
            li.appendChild(button);
            list.appendChild(li);
        }

        total.textContent = t(':count votes', { count: outcome.total });
        panel.dataset.state = closed() ? 'closed' : 'open';
        if (closed()) note.textContent = t('Closed: the match is over.');
        else if (!me) note.textContent = t('Log in to vote.');
        else if (!note.dataset.error) note.textContent = t('One vote per account, public on Nostr. Spectators only.');
    }

    function wantPerson(pubkey) {
        if (asked.has(pubkey) || !chat?.peopleUrl) return;
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
            const response = await fetch(`${chat.peopleUrl}?keys=${batch.join(',')}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            const answer = response.ok ? await response.json() : {};
            batch.forEach((key) => { const found = answer?.[key]; if (found && typeof found === 'object') people.set(key, { counts: found.counts === true }); });
            render();
        } catch (error) {
            console.warn('[hyper poll] lookup failed', error);
        }
    }

    function receive(event) {
        const parsed = parseVote(event);
        if (!parsed || parsed.poll !== config.id || players.has(parsed.pubkey)) return;
        if (seen.length < 4000) seen.push(parsed);
        const outcome = addVote(book, parsed, new Map([[config.id, poll()]]));
        if (outcome === 'added' || outcome === 'replaced') { wantPerson(parsed.pubkey); render(); }
    }

    async function vote(option) {
        if (!me || closed() || sending || !pool) return;
        delete note.dataset.error;
        if (!(await ensureSigner())) { note.dataset.error = '1'; note.textContent = t('No Nostr signer found. Install a Nostr browser extension or use a remote signer.'); return; }
        sending = true;
        render();
        try {
            const signed = await signTemplate(voteTemplate(poll(), option, { relayHint: chat?.relayHint ?? '' }), { pubkey: me });
            try {
                await Promise.any(pool.publish(relays, signed));
            } catch {
                note.dataset.error = '1';
                note.textContent = t('The vote did not reach any relay. Please try again.');

                return;
            }
            receive(signed);
        } catch (error) {
            note.dataset.error = '1';
            note.textContent = signerMessage(config.signer ?? {}, error);
        } finally {
            sending = false;
            render();
        }
    }

    if (relays.length > 0) {
        pool = new SimplePool();
        pool.subscribeMap([...new Set(relays)].map((url) => ({ url, filter: { kinds: [KIND_VOTE], '#e': [config.id], limit: 2000 } })), { onevent: receive, maxWait: 6000 });
    }
    if (me) wantPerson(me);
    render();

    return {
        open() { panel.hidden = false; render(); },
        close() { panel.hidden = true; },
        toggle() { if (panel.hidden) this.open(); else this.close(); },
        /**
         * The match ended on this page: no more votes. Without `at` it closes now (the end screen); with the
         * server's end time (`ended_at`, P5c) that time decides, and the book is counted again from every vote
         * received, so a vote signed after the end no longer counts and the vote it replaced counts again.
         */
        end(at) {
            if (Number.isSafeInteger(at)) {
                closedAt = at;
                book.clear();
                const polls = new Map([[config.id, poll()]]);
                seen.forEach((vote) => addVote(book, vote, polls));
            } else {
                closedAt ??= Math.floor(Date.now() / 1000);
            }
            render();
        },
    };
}
