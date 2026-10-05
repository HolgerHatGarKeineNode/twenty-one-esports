/**
 * The Alpine side of "Your follows here" (P47,
 * resources/views/components/⚡follows-here.blade.php):
 *
 * 1. When the section comes into view, read the viewer's kind 3 from their
 *    relays (followsHere.js) and hand the pubkeys to the league
 *    ($wire.match(): one query), which renders who plays here.
 * 2. "Invite follows who are not here yet": names from their kind 0, pick up
 *    to ten, "Preview the invite" asks the league for the personal link and
 *    text ($wire.inviteText(); the link is made on this click, not before),
 *    "Sign and send" sends one DM per person (inviteDm.js, loaded on demand),
 *    and a person who could only get NIP-04 waits for a yes for them.
 *
 * Nothing is signed or sent before "Sign and send"; the league never sees
 * the follow list beyond the pubkeys it matches, nor the message.
 *
 * On a lobby (`lookingKey` set: `chess/blitz`, `<board game>/blitz`) each row
 * reads the page-wide presence that "Online now" reads
 * (window.esportsPresence, resources/js/echo.js): online, or looking for
 * this lobby's blitz, and for the latter the lobby's own invite
 * ($wire.$parent.invite, the lobby page's component). The lobby keeps the
 * invite's state (invitedUserId, invitedUntilMs, error); the row reads it.
 */
import { npubEncode } from 'nostr-tools/nip19';
import { readFollows, readNames, splitFollows } from './followsHere.js';
import { ensureSigner } from './nostrSign.js';
import { signerMessage } from './signing.js';

const PICK_MAX = 10;
const LISTED = 200;

export function followsHere({ me = null, relays = [], labels = {}, lookingKey = null } = {}) {
    return {
        // idle -> reading -> done | none | failed
        state: 'idle',
        total: 0,
        complete: true,
        answered: 0,
        asked: 0,
        here: [],
        notHere: [],
        names: {},
        namesLoading: false,
        error: '',
        // invite
        pickerOpen: false,
        search: '',
        picked: [],
        // pick -> preview -> sending -> done
        step: 'pick',
        busy: false,
        link: '',
        text: '',
        results: {},
        // presence on a lobby: user id -> member of `online`
        online: {},
        inviting: null,
        invitedLast: null,
        stopPresence: null,
        observer: null,

        init() {
            if (!me) return;
            if (lookingKey) {
                this.stopPresence = window.esportsPresence?.subscribe((members) => {
                    this.online = Object.fromEntries(members.map((member) => [member.id, member]));
                }) ?? null;
            }
            if (typeof IntersectionObserver !== 'function') {
                this.read();

                return;
            }
            this.observer = new IntersectionObserver((entries) => {
                if (entries.some((entry) => entry.isIntersecting)) {
                    this.observer.disconnect();
                    this.read();
                }
            }, { rootMargin: '200px' });
            this.observer.observe(this.$el);
        },

        destroy() {
            this.stopPresence?.();
            this.observer?.disconnect();
        },

        /** Whether this follow is online and looks for this lobby's blitz. */
        looks(id) {
            return lookingKey !== null && this.online[id]?.looking === lookingKey;
        },

        /** The lobby's own answer (its canInvite, as "Online now" gets it): no invite while the viewer is in a live game. */
        canInvite() {
            return this.$wire.$parent?.canInvite === true;
        },

        /** The lobby's open blitz invite goes to this follow (until it runs out; the lobby clears it on its next render). */
        invitedBlitz(id) {
            const lobby = this.$wire.$parent;

            return Boolean(lobby) && lobby.invitedUserId === id && Date.now() < lobby.invitedUntilMs;
        },

        /** The lobby's own invite: a searching follow starts the game at once, the page then moves to the board. */
        async inviteBlitz(id) {
            const lobby = this.$wire.$parent;
            if (!lobby || this.inviting !== null || !this.canInvite()) return;
            this.inviting = id;
            this.invitedLast = null;
            try {
                await lobby.invite(id);
            } catch (error) {
                console.warn('[follows here] the blitz invite failed:', error);
            } finally {
                this.invitedLast = id;
                this.inviting = null;
            }
        },

        /** Why the lobby refused this row's invite, where it was clicked (the lobby also says it at its top). */
        blitzError(id) {
            return this.invitedLast === id && !this.invitedBlitz(id) ? (this.$wire.$parent?.error ?? '') : '';
        },

        label(key, replace = {}) {
            let text = labels[key] ?? key;
            for (const [name, value] of Object.entries(replace)) text = text.replace(':' + name, String(value));

            return text;
        },

        async read() {
            if (this.state === 'reading') return;
            const wire = this.$wire;
            void wire.$id;
            this.state = 'reading';
            this.error = '';
            try {
                const read = await readFollows(me, relays);
                Object.assign(this, { total: read.pubkeys.length, complete: read.complete, answered: read.answered, asked: read.asked });
                if (!read.found) {
                    this.state = read.answered === 0 ? 'failed' : 'none';
                    if (this.state === 'failed') this.error = this.label('notRead', { answered: read.answered, asked: read.asked });

                    return;
                }
                const answer = await wire.match(read.pubkeys);
                const split = splitFollows(read.pubkeys, answer?.here ?? []);
                this.here = split.here;
                this.notHere = split.notHere;
                this.state = 'done';
            } catch (error) {
                console.warn('[follows here] reading the follow list failed:', error);
                this.error = this.label('failed');
                this.state = 'failed';
            }
        },

        async openPicker() {
            this.pickerOpen = true;
            this.step = 'pick';
            if (Object.keys(this.names).length > 0 || this.namesLoading) return;
            this.namesLoading = true;
            try {
                const names = await readNames(this.notHere, relays);
                this.names = Object.fromEntries(names);
            } catch (error) {
                console.warn('[follows here] reading names failed:', error);
            } finally {
                this.namesLoading = false;
            }
        },

        closePicker() {
            if (this.busy) return;
            this.pickerOpen = false;
            this.step = 'pick';
            this.picked = [];
            this.results = {};
            this.error = '';
        },

        shortNpub(pubkey) {
            try {
                const npub = npubEncode(pubkey);

                return npub.slice(0, 12) + '…' + npub.slice(-4);
            } catch {
                return pubkey.slice(0, 8);
            }
        },

        nameOf(pubkey) {
            return this.names[pubkey] ?? this.shortNpub(pubkey);
        },

        get matching() {
            const term = this.search.trim().toLowerCase();
            if (term === '') return this.notHere;

            return this.notHere.filter((pubkey) => (this.names[pubkey] ?? '').toLowerCase().includes(term) || this.shortNpub(pubkey).toLowerCase().includes(term) || npubEncode(pubkey).includes(term));
        },

        get candidates() {
            // Named accounts first, in follow order; the picked ones always stay visible.
            const named = this.matching.filter((pubkey) => this.names[pubkey]);
            const unnamed = this.matching.filter((pubkey) => !this.names[pubkey]);
            const listed = [...named, ...unnamed].slice(0, LISTED);

            return [...this.picked.filter((pubkey) => !listed.includes(pubkey)), ...listed];
        },

        get moreCandidates() {
            return Math.max(0, this.matching.length - LISTED);
        },

        pick(pubkey) {
            if (this.picked.includes(pubkey)) {
                this.picked = this.picked.filter((key) => key !== pubkey);
            } else if (this.picked.length < PICK_MAX) {
                this.picked = [...this.picked, pubkey];
            }
        },

        async preview() {
            if (this.picked.length === 0 || this.busy) return;
            const wire = this.$wire;
            void wire.$id;
            this.busy = true;
            this.error = '';
            try {
                const answer = await wire.inviteText();
                if (!answer || answer.error) {
                    this.error = answer?.error ?? this.label('failed');

                    return;
                }
                this.link = answer.link;
                this.text = answer.text;
                this.results = {};
                this.step = 'preview';
            } catch (error) {
                console.warn('[follows here] preparing the invite failed:', error);
                this.error = this.label('failed');
            } finally {
                this.busy = false;
            }
        },

        skip(pubkey) {
            this.results = { ...this.results, [pubkey]: { recipient: pubkey, status: 'skipped' } };
            this.settle();
        },

        /** Who still gets the invite on "Sign and send": not sent, skipped, refused or waiting for a NIP-04 yes. */
        get unsent() {
            return this.picked.filter((pubkey) => !['sent', 'skipped', 'refused', 'confirm'].includes(this.results[pubkey]?.status));
        },

        /** Done once nobody is left to send to and nobody waits for a yes. */
        settle() {
            const waiting = this.picked.some((pubkey) => this.results[pubkey]?.status === 'confirm');
            this.step = this.unsent.length === 0 && !waiting ? 'done' : 'preview';
        },

        resultText(pubkey) {
            const result = this.results[pubkey];
            if (!result) return '';
            if (result.status === 'unsent') return labels.unsent ?? 'No relay took it.';
            if (result.status === 'error') return signerMessage(labels.signer ?? {}, result.error);

            return labels['dm_' + result.code] ?? this.label('failed');
        },

        /**
         * The "Sign and send" click; `nip04For`: the person the player just said
         * yes to NIP-04 for (P45 audit F3: never a silent downgrade).
         */
        async send(nip04For = null) {
            if (this.busy || (this.step !== 'preview' && nip04For === null)) return;
            const recipients = nip04For === null ? this.unsent : [nip04For];
            if (recipients.length === 0) return;
            this.busy = true;
            this.error = '';
            try {
                if (!(await ensureSigner())) {
                    this.error = labels.signer?.noSigner ?? this.label('failed');

                    return;
                }
                const { InviteRefused, sendInvites } = await import('./inviteDm.js');
                this.step = 'sending';
                try {
                    await sendInvites({
                        sender: me,
                        recipients,
                        text: this.text,
                        link: this.link,
                        signer: window.nostr,
                        relays,
                        allowNip04: nip04For === null ? [] : [nip04For],
                        onResult: (result) => {
                            this.results = { ...this.results, [result.recipient]: result };
                        },
                    });
                } catch (error) {
                    if (error instanceof InviteRefused) {
                        this.error = this.label(error.code === 'no_link' ? 'noLink' : (error.code === 'too_many' ? 'tooMany' : 'failed'), { max: PICK_MAX });
                        this.step = 'preview';

                        return;
                    }
                    throw error;
                }
                this.settle();
            } catch (error) {
                console.warn('[follows here] sending the invites failed:', error);
                this.error = signerMessage(labels.signer ?? {}, error);
                this.step = 'preview';
            } finally {
                this.busy = false;
            }
        },
    };
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('followsHere', followsHere);
});
