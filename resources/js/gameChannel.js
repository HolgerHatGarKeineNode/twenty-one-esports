/**
 * Entry for the game overview pages (P21): the global chat of the game, a
 * NIP-28 public channel with NIP-88 polls (rules: resources/js/channelChat.js),
 * read and written straight from the browser on the chat relays; the league
 * server never sees a message. Loaded by /chess and /games/{slug} (layout
 * `scripts`); the markup is components/⚡game-channel.
 *
 * - One subscription for the channel's messages and polls (`#e` the channel)
 *   and the creator's hides and mutes; one for the votes of the polls on
 *   screen, opened again when that set changes.
 * - Names and avatars come from the league (`$wire.players`): a league
 *   player shows with the name the league knows, anybody else with a short
 *   npub and a generated avatar, marked "not in the league". Polls are
 *   shown from league players only, and only their votes count; how many
 *   other votes arrived is said, not added.
 * - Message text renders through the P24 stream chat's bounded tokenizer
 *   (streamChat.js) and its token partial, never as HTML.
 * - Mutes are the viewer's own (localStorage and the account, ChatMute);
 *   the creator's kind 43/44 hide for everyone on this app.
 * - Guests read only.
 */
import { SimplePool } from 'nostr-tools/pool';
import { npubEncode } from 'nostr-tools/nip19';
import { KIND_HIDE, KIND_MESSAGE, KIND_MUTE, KIND_POLL, KIND_VOTE, addVote, isChannelMessage, isClosed, messageTemplate, moderation, parsePoll, parseVote, pollBlocker, pollTemplate, tally, timeLeft, voteTemplate } from './channelChat.js';
import { knownCustomEmojis, loadUserCustomEmojis, pushRecentEmoji } from './emoji.js';
import { emojiPicker, emojiPopover } from './emojiPicker.js';
import { ensureSigner } from './nostrSign.js';
import { signerMessage, signTemplate } from './signing.js';
import { botMark, displayRows, insertSorted, length, sendBlocker, tokenize } from './streamChat.js';

const MUTES_KEY = 'esports.chat.mutes';
const KEEP = 200;
const BOTTOM_SLACK = 48;
/** Polls whose votes are read (the newest). */
const POLLS_WATCHED = 30;
/** Pubkeys asked about in one lookup, and in one page's life. */
const LOOKUP_BATCH = 100;
const LOOKUP_LIMIT = 2000;
const LOOKUP_TRIES = 3;

function readJson(key, fallback) {
    try {
        return JSON.parse(localStorage.getItem(key) ?? 'null') ?? fallback;
    } catch {
        return fallback;
    }
}

function writeJson(key, value) {
    try {
        localStorage.setItem(key, JSON.stringify(value));
    } catch {
        // private mode or full storage: it only forgets
    }
}

export function gameChannel(config) {
    const t = config.labels;
    const nowSeconds = () => Math.floor(Date.now() / 1000);
    // Outside Alpine's reactivity (a vote book can hold thousands of entries): a render reads them through `version`.
    const state = { polls: new Map(), book: new Map(), moderationEvents: [], mod: { hidden: new Set(), muted: new Set() }, asked: new Set(), pending: new Set(), tries: new Map() };

    return {
        t,
        items: [],
        status: (config.relays ?? []).length === 0 ? 'off' : 'connecting',
        input: '',
        sending: false,
        error: '',
        lastSentAt: null,
        atBottom: true,
        unseen: 0,
        muted: [],
        revealed: [],
        menuFor: null,
        me: config.me ?? null,
        maxLength: config.maxLength ?? 280,
        pointerFine: typeof window.matchMedia === 'function' && window.matchMedia('(hover: hover) and (pointer: fine)').matches,
        // name/avatar per league player; `false` for a pubkey the league does not know
        people: {},
        // tick for "closes in", once a minute
        clock: nowSeconds(),
        version: 0,

        // The poll form
        composing: false,
        question: '',
        answers: ['', ''],
        duration: config.poll?.durations?.[1] ?? config.poll?.durations?.[0] ?? 86400,
        voting: null,

        init() {
            // The component's own $wire, bound to its root, for calls made later from any element.
            state.wire = this.$wire;
            const local = readJson(MUTES_KEY, []);
            this.muted = [...new Set(this.me ? (config.muted ?? []) : (Array.isArray(local) ? local : []))];
            if (this.me) writeJson(MUTES_KEY, this.muted);

            this.clockTimer = setInterval(() => { this.clock = nowSeconds(); }, 30_000);

            if (this.status === 'off') return;

            this.pool = new SimplePool();
            this.sub = this.pool.subscribe(
                config.relays,
                [
                    { kinds: [KIND_MESSAGE, KIND_POLL], '#e': [config.channel], limit: config.history ?? 120 },
                    { kinds: [KIND_HIDE, KIND_MUTE], authors: [config.creator], limit: 500 },
                ],
                { onevent: (event) => this.receive(event), oneose: () => this.caughtUp(), maxWait: 6000 },
            );
            this.eoseTimer = setTimeout(() => this.caughtUp(), 6500);

            if (this.me && this.pointerFine) {
                loadUserCustomEmojis(this.me, config.emojiRelays ?? []).catch(() => {});
            }
        },

        destroy() {
            clearTimeout(this.eoseTimer);
            clearTimeout(this.voteTimer);
            clearTimeout(this.lookupTimer);
            clearInterval(this.clockTimer);
            this.sub?.close();
            this.votes?.close();
            this.pool?.destroy();
        },

        caughtUp() {
            clearTimeout(this.eoseTimer);
            if (this.status !== 'connecting') return;
            this.status = 'live';
            this.$nextTick(() => this.scrollToBottom());
        },

        receive(event) {
            if (event?.kind === KIND_HIDE || event?.kind === KIND_MUTE) {
                if (event.pubkey !== config.creator || state.moderationEvents.length >= 1000) return;
                state.moderationEvents.push(event);
                state.mod = moderation(state.moderationEvents, config.creator);
                this.version += 1;

                return;
            }

            if (event?.kind === KIND_VOTE) {
                const vote = parseVote(event);
                if (vote && addVote(state.book, vote, state.polls) !== 'ignored') {
                    this.wantPerson(vote.pubkey);
                    this.version += 1;
                }

                return;
            }

            let item = null;
            if (isChannelMessage(event, config.channel)) {
                item = { type: 'message', id: event.id, pubkey: event.pubkey, created_at: event.created_at, tokens: tokenize(event.content, event.tags) };
            } else if (event?.kind === KIND_POLL) {
                const poll = parsePoll(event, config.channel);
                if (!poll) return;
                state.polls.set(poll.id, poll);
                item = { type: 'poll', id: poll.id, pubkey: poll.pubkey, created_at: poll.created_at, poll };
            }
            if (!item) return;

            const follow = this.atBottom;
            if (!insertSorted(this.items, item, KEEP)) return;
            this.wantPerson(item.pubkey);
            if (item.type === 'poll') this.watchVotes();

            if (this.status !== 'live') return;
            if (item.pubkey === this.me || follow) {
                this.$nextTick(() => this.scrollToBottom());
            } else if (!this.muted.includes(item.pubkey)) {
                this.unseen += 1;
            }
        },

        /** Read the votes of the newest polls; again (debounced) whenever a poll arrives. */
        watchVotes() {
            clearTimeout(this.voteTimer);
            this.voteTimer = setTimeout(() => {
                const ids = this.items.filter((item) => item.type === 'poll').slice(-POLLS_WATCHED).map((item) => item.id);
                if (ids.length === 0 || !this.pool) return;
                this.votes?.close();
                this.votes = this.pool.subscribe(config.relays, { kinds: [KIND_VOTE], '#e': ids }, { onevent: (event) => this.receive(event) });
            }, 250);
        },

        /* ---------- People ------------------------------------------------------------------- */

        wantPerson(pubkey) {
            if (state.asked.has(pubkey) || state.asked.size >= LOOKUP_LIMIT) return;
            state.asked.add(pubkey);
            state.pending.add(pubkey);
            clearTimeout(this.lookupTimer);
            this.lookupTimer = setTimeout(() => this.lookup(), 200);
        },

        /**
         * Ask the league which of the pending pubkeys are players. Only an
         * answer says "not a player": a lookup that got none (a failed
         * request) leaves them unknown and asks again, up to LOOKUP_TRIES
         * times.
         *
         * Through the `$wire` taken in init(), never `this.$wire` here: a
         * lookup can be started by a click on a poll answer, and `this` then
         * resolves `$wire` from that button, which the next render replaces.
         * Measured in the browser test: the call on the detached button
         * resolved undefined and never reached the server, and the voter was
         * marked "not in the league".
         */
        async lookup() {
            const batch = [...state.pending].slice(0, LOOKUP_BATCH);
            batch.forEach((pubkey) => state.pending.delete(pubkey));
            if (state.pending.size > 0) this.lookupTimer = setTimeout(() => this.lookup(), 200);
            if (batch.length === 0) return;

            let answer;
            try {
                answer = await state.wire.players(batch);
            } catch (error) {
                console.warn('[game chat] player lookup failed', error);
            }

            if (answer === null || typeof answer !== 'object') {
                const again = batch.filter((pubkey) => (state.tries.get(pubkey) ?? 0) < LOOKUP_TRIES);
                again.forEach((pubkey) => {
                    state.tries.set(pubkey, (state.tries.get(pubkey) ?? 0) + 1);
                    state.pending.add(pubkey);
                });
                clearTimeout(this.lookupTimer);
                if (state.pending.size > 0) this.lookupTimer = setTimeout(() => this.lookup(), 1000);

                return;
            }

            const next = { ...this.people };
            for (const pubkey of batch) next[pubkey] = answer[pubkey] ?? false;
            this.people = next;
        },

        isPlayer(pubkey) {
            return !!this.people[pubkey];
        },

        nameOf(pubkey) {
            if (pubkey === this.me && config.meName) return config.meName;
            if (this.people[pubkey]) return this.people[pubkey].name;
            try {
                return npubEncode(pubkey).slice(0, 12) + '…';
            } catch {
                return t.someone;
            }
        },

        avatarOf(pubkey) {
            return this.people[pubkey]?.avatar ?? this.generatedAvatar(pubkey);
        },

        generatedAvatar(pubkey) {
            return (config.avatarUrl ?? '').replace(config.avatarPlaceholder ?? '0'.repeat(64), pubkey);
        },

        markOf(pubkey) {
            if (pubkey === config.creator) return 'league';
            if (botMark(pubkey, { bot: config.bot }) === 'bot') return 'bot';

            return this.people[pubkey] === false ? 'outside' : null;
        },

        /* ---------- The list ----------------------------------------------------------------- */

        /**
         * The rows: the creator's hidden messages and muted people left out; a
         * poll only from a league player (none while the league has not
         * answered yet); runs of the viewer's own mutes folded.
         */
        get rows() {
            this.version;
            const shown = this.items.filter((item) => !state.mod.hidden.has(item.id) && !state.mod.muted.has(item.pubkey) && (item.type !== 'poll' || this.isPlayer(item.pubkey)));
            const rows = displayRows(shown, { muted: this.muted, revealed: this.revealed });
            rows.forEach((row, index) => {
                const previous = rows[index - 1];
                row.cont = row.type === 'message' && previous?.type === 'message' && previous.item.pubkey === row.item.pubkey && row.item.created_at - previous.item.created_at < 120;
            });

            return rows;
        },

        /** Open polls of league players, newest first: the side column from lg. */
        get openPolls() {
            this.version;
            return this.items.filter((item) => item.type === 'poll' && this.isPlayer(item.pubkey) && !isClosed(item.poll, this.clock) && !state.mod.hidden.has(item.id)).reverse().slice(0, 3).map((item) => item.poll);
        },

        get hasItems() {
            return this.rows.length > 0;
        },

        /** A poll's result as the card draws it: per answer the count, the share and whether it is mine or leading. */
        result(poll) {
            this.version;
            const outcome = tally(poll, state.book, { counts: (pubkey) => this.isPlayer(pubkey), me: this.me });
            const top = Math.max(0, ...Object.values(outcome.counts));
            const closed = isClosed(poll, this.clock);

            return {
                closed,
                total: outcome.total,
                uncounted: outcome.uncounted,
                mine: outcome.mine,
                totalLabel: outcome.total === 1 ? t.vote : t.votes.replace(':count', outcome.total),
                when: closed ? t.closed : t.closesIn.replace(':time', timeLeft(poll.endsAt, { now: this.clock, locale: config.locale ?? 'en' })),
                options: poll.options.map((option) => ({
                    ...option,
                    count: outcome.counts[option.id],
                    share: outcome.total === 0 ? 0 : Math.round((outcome.counts[option.id] / outcome.total) * 100),
                    mine: outcome.mine === option.id,
                    leading: closed && top > 0 && outcome.counts[option.id] === top,
                })),
            };
        },

        /* ---------- Scrolling ---------------------------------------------------------------- */

        onScroll() {
            const list = this.$refs.list;
            if (!list) return;
            this.atBottom = list.scrollHeight - list.scrollTop - list.clientHeight < BOTTOM_SLACK;
            if (this.atBottom) this.unseen = 0;
        },

        scrollToBottom(smooth = false) {
            const list = this.$refs.list;
            if (!list) return;
            const reduce = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
            list.scrollTo({ top: list.scrollHeight, behavior: smooth && !reduce ? 'smooth' : 'auto' });
            this.atBottom = true;
            this.unseen = 0;
        },

        get unseenLabel() {
            return this.unseen === 1 ? t.newMessage : t.newMessages.replace(':count', this.unseen);
        },

        time(seconds) {
            return new Date(seconds * 1000).toLocaleTimeString(config.locale ?? undefined, { hour: '2-digit', minute: '2-digit' });
        },

        shortNpub(pubkey) {
            try {
                const npub = npubEncode(pubkey);

                return npub.slice(0, 10) + '…' + npub.slice(-4);
            } catch {
                return '';
            }
        },

        /* ---------- Mutes -------------------------------------------------------------------- */

        isMuted(pubkey) {
            return this.muted.includes(pubkey);
        },

        toggleMenu(id) {
            this.menuFor = this.menuFor === id ? null : id;
        },

        async setMuted(pubkey, mute) {
            if (!pubkey || pubkey === this.me) return;
            this.muted = mute ? [...new Set([...this.muted, pubkey])] : this.muted.filter((known) => known !== pubkey);
            this.menuFor = null;
            writeJson(MUTES_KEY, this.muted);
            if (this.me) {
                try {
                    await state.wire.setMuted(pubkey, mute);
                } catch (error) {
                    console.warn('[game chat] saving the mute failed', error);
                }
            }
        },

        muteLabel(pubkey) {
            return (this.isMuted(pubkey) ? t.unmute : t.mute).replace(':name', this.nameOf(pubkey));
        },

        mutedLabel(row) {
            return row.ids.length === 1 ? t.mutedOne : t.mutedMany.replace(':count', row.ids.length);
        },

        toggleReveal(row) {
            const first = row.ids[0];
            this.revealed = this.revealed.includes(first) ? this.revealed.filter((id) => id !== first) : [...this.revealed, first];
        },

        isRevealed(row) {
            return this.revealed.includes(row.ids[0]);
        },

        /* ---------- Writing ------------------------------------------------------------------ */

        get remaining() {
            return this.maxLength - length(this.input.trim());
        },

        insertEmoji(text, emojiTag, label) {
            const field = this.$refs.composer;
            const start = field?.selectionStart ?? this.input.length;
            const end = field?.selectionEnd ?? this.input.length;
            this.input = this.input.slice(0, start) + text + this.input.slice(end);
            pushRecentEmoji(emojiTag ? { custom: true, shortcode: String(emojiTag[1]), url: String(emojiTag[2]) } : { u: text, label: label ?? text });
            // The caret goes behind the emoji now, not on the next tick: a key pressed before that tick
            // landed in front and the tick then moved the caret into the middle of what was typed
            // (P21: LiveChatTest sent "gm st:sareamtoshi:" once the picker moved to its own chunk).
            if (field) {
                const position = start + text.length;
                field.value = this.input;
                field.focus();
                field.setSelectionRange(position, position);
            }
        },

        /** Sign with the viewer's signer and publish; resolves to the signed event, or null after setting `error`. */
        async publish(template, notSent = t.notSent) {
            if (!(await ensureSigner())) {
                this.error = t.noSigner;

                return null;
            }

            let signed;
            try {
                signed = await signTemplate(template, { pubkey: this.me });
            } catch (error) {
                this.error = signerMessage(t, error);

                return null;
            }

            try {
                await Promise.any(this.pool.publish(config.relays, signed));
            } catch {
                this.error = notSent;

                return null;
            }

            return signed;
        },

        async send() {
            if (!this.me || this.sending || this.status !== 'live') return;

            const blocker = sendBlocker(this.input, { maxLength: this.maxLength, cooldownMs: config.cooldownMs ?? 2000, lastSentAt: this.lastSentAt });
            if (blocker === 'empty') return;
            if (blocker !== null) {
                this.error = blocker === 'tooLong' ? t.tooLong.replace(':max', this.maxLength) : t.wait;

                return;
            }

            const content = this.input;
            this.sending = true;
            this.error = '';

            try {
                const template = messageTemplate(content, { channel: config.channel, relayHint: config.relayHint, custom: knownCustomEmojis(this.me) });
                this.lastSentAt = Date.now();
                this.input = this.input === content ? '' : this.input;
                const signed = await this.publish(template);
                if (signed === null) {
                    this.input ||= content;

                    return;
                }
                this.receive(signed);
            } catch (error) {
                console.warn('[game chat] sending failed', error);
                this.error = t.failed;
                this.input ||= content;
            } finally {
                this.sending = false;
            }
        },

        /* ---------- Polls -------------------------------------------------------------------- */

        openPollForm() {
            this.composing = true;
            this.error = '';
            this.$nextTick(() => this.$refs.question?.focus());
        },

        addAnswer() {
            if (this.answers.length < (config.poll?.maxOptions ?? 4)) this.answers = [...this.answers, ''];
        },

        removeAnswer(index) {
            if (this.answers.length > 2) this.answers = this.answers.filter((_, i) => i !== index);
        },

        get pollProblem() {
            return pollBlocker({ question: this.question, options: this.answers }, config.poll ?? {});
        },

        async sendPoll() {
            if (!this.me || this.sending || this.status !== 'live') return;
            if (this.pollProblem !== null) {
                this.error = t.pollInvalid;

                return;
            }

            this.sending = true;
            this.error = '';

            try {
                const template = pollTemplate({
                    question: this.question,
                    options: this.answers,
                    duration: Number(this.duration),
                    channel: config.channel,
                    relayHint: config.relayHint,
                    relays: config.relays,
                    limits: config.poll ?? {},
                });
                const signed = await this.publish(template);
                if (signed === null) return;
                this.composing = false;
                this.question = '';
                this.answers = ['', ''];
                this.receive(signed);
            } catch (error) {
                console.warn('[game chat] the poll failed', error);
                this.error = t.failed;
            } finally {
                this.sending = false;
            }
        },

        async vote(poll, option) {
            if (!this.me || this.voting !== null || isClosed(poll) || this.result(poll).mine === option) return;
            this.voting = poll.id;
            this.error = '';

            try {
                const signed = await this.publish(voteTemplate(poll, option, { relayHint: config.relayHint }), t.voteNotSent);
                if (signed !== null) this.receive(signed);
            } catch (error) {
                console.warn('[game chat] the vote failed', error);
                this.error = t.failed;
            } finally {
                this.voting = null;
            }
        },
    };
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('gameChannel', gameChannel);
    window.Alpine.data('emojiPicker', emojiPicker);
    window.Alpine.data('emojiPopover', emojiPopover);
});
