/**
 * Comments, likes and RSVPs on Nostr (P48, NIP "Comments, likes and RSVPs"):
 *
 * nostrComments: the comment section of a tournament, a rated game or a
 *   rated series (components/⚡nostr-comments). Reads the comments (a page of
 *   `page`, "Load more" for the next, at most `maxShown`), the likes and the
 *   league's moderation from the relays (resources/js/commentsRead.js);
 *   names and pictures come from the league ($wire.authors). Writing is the
 *   share-post flow (badgeShare.js): $wire.prepare…() -> the exact event as a
 *   preview -> only "Sign and post" / "Sign and like" signs, after a fresh
 *   template that must equal the preview -> the player's write relays ->
 *   $wire.submit…() (the league relays it).
 *
 * rsvpSummary: who said on Nostr they go to a tournament (components/
 *   ⚡rsvp-summary), next to the real sign-up count, never mixed with it.
 *
 * rsvpOffer: "going" / "not going" as a NIP-52 RSVP after a sign-up or a
 *   withdrawal (components/⚡rsvp-offer), preview first, signed on click.
 *
 * Nothing here signs without a click, and relay content is rendered with
 * x-text only.
 */
import { ensureSigner } from './nostrSign.js';
import { sameDraft, signerMessage, signTemplate } from './signing.js';
import { publishToRelays, writeRelaysFor } from './relayRead.js';
import {
    clipText, commentFilter, isReply, mayHaveMore, mergeComments, moderation, moderationFilter, oldest,
    reactionFilter, readAll, readRelayList, rsvpFilter, tallyLikes, tallyRsvps,
} from './commentsRead.js';

const AUTHOR_BATCH = 100;

/**
 * The shared signing step: fresh template equal to the preview, sign, send to
 * the write relays, hand to the league. Returns the signed event, or null
 * with `error`/`warning` set on the component.
 */
async function signAndSend(component, { prepare, submit, preview, messages, pubkey, relays }) {
    if (! (await ensureSigner())) {
        component.error = messages.noSigner;

        return null;
    }

    const fresh = await prepare();

    if (fresh?.error) {
        component.error = fresh.error;

        return null;
    }

    if (! sameDraft(fresh?.template, preview)) {
        component.warning = messages.changed;

        return { changed: fresh?.template ?? null };
    }

    let signed;
    try {
        signed = await signTemplate(fresh.template, { pubkey });
    } catch (error) {
        component.error = signerMessage(messages, error);

        return null;
    }

    const published = await publishToRelays(await writeRelaysFor(pubkey, relays), signed);

    if (published === 0) {
        component.warning = messages.notPosted;

        return null;
    }

    const answer = await submit(JSON.stringify(signed));

    if (answer !== true) {
        component.error = answer?.error ?? messages.failed;

        return null;
    }

    return { signed };
}

function nostrComments(config) {
    const t = config.labels ?? {};
    const relays = readRelayList(config.relays ?? [], config.readRelays ?? 5);
    const format = new Intl.DateTimeFormat(config.locale ?? 'en', { dateStyle: 'medium', timeStyle: 'short', timeZone: config.timeZone ?? undefined });

    return {
        // loading -> ready | unreached (no relay answered: nothing is known, which is not "no comments")
        status: 'loading',
        comments: [],
        authors: {},
        hidden: [],
        muted: [...(config.muted ?? [])],
        // Reactions as read (and the player's own, once signed); the count follows the mutes, which may arrive later.
        reactions: [],
        likesKnown: false,
        more: false,
        loadingMore: false,
        // The composer and the like: idle -> opening -> preview -> posting -> idle (a comment adds itself to the list)
        text: '',
        step: 'idle',
        template: null,
        likeStep: 'idle',
        likeTemplate: null,
        error: null,
        warning: null,
        posted: false,

        async init() {
            await Promise.all([this.readModeration(), this.readFirst(), this.readLikes()]);
            await this.lookUp(this.comments.map((comment) => comment.pubkey));
        },

        async readModeration() {
            const creator = config.moderation?.creator;
            const moderationRelays = readRelayList(config.moderation?.relays ?? [], config.readRelays ?? 5);

            if (! creator || moderationRelays.length === 0) {
                return;
            }

            const { events } = await readAll(moderationRelays, [moderationFilter(creator)]);
            const found = moderation(events, creator);
            this.hidden = found.hidden;
            this.muted = [...new Set([...this.muted, ...found.muted])];
        },

        async readFirst() {
            this.status = 'loading';
            const { results, reached, events } = await readAll(relays, [commentFilter(config.target, { limit: config.page })]);

            if (! reached) {
                this.status = 'unreached';

                return;
            }

            this.comments = mergeComments(this.comments, events, config.target, config.maxShown).list;
            this.more = mayHaveMore(results, config.page) && this.comments.length < config.maxShown;
            this.status = 'ready';
        },

        async retry() {
            await this.readFirst();
            await this.readLikes();
            await this.lookUp(this.comments.map((comment) => comment.pubkey));
        },

        async loadMore() {
            if (this.loadingMore || ! this.more) {
                return;
            }

            this.loadingMore = true;

            try {
                const { results, reached, events } = await readAll(relays, [commentFilter(config.target, { limit: config.page, until: oldest(this.comments) })]);
                const merged = mergeComments(this.comments, events, config.target, config.maxShown);
                this.comments = merged.list;
                this.more = reached && merged.added > 0 && mayHaveMore(results, config.page) && this.comments.length < config.maxShown;
                await this.lookUp(this.comments.map((comment) => comment.pubkey));
            } finally {
                this.loadingMore = false;
            }
        },

        async readLikes() {
            const { reached, events } = await readAll(relays, [reactionFilter(config.target, config.readLimit)]);
            const own = this.reactions.filter((event) => event.pubkey === config.me);
            this.reactions = [...events, ...own];
            this.likesKnown = this.likesKnown || reached;
        },

        get likes() {
            return { ...tallyLikes(this.reactions, config.target, { me: config.me, muted: this.muted }), known: this.likesKnown };
        },

        async lookUp(pubkeys) {
            const unknown = [...new Set(pubkeys)].filter((pubkey) => ! this.authors[pubkey]);

            for (let i = 0; i < unknown.length; i += AUTHOR_BATCH) {
                try {
                    const found = await this.$wire.authors(unknown.slice(i, i + AUTHOR_BATCH));
                    this.authors = { ...this.authors, ...(found ?? {}) };
                } catch (error) {
                    console.warn('[comments] looking up the authors failed:', error);

                    return;
                }
            }
        },

        /** The rows: the league's hidden events and muted people left out, the text clipped. */
        get rows() {
            return this.comments
                .filter((comment) => ! this.hidden.includes(comment.id) && ! this.muted.includes(comment.pubkey))
                .map((comment) => {
                    const author = this.authors[comment.pubkey] ?? null;

                    return {
                        id: comment.id,
                        text: clipText(comment.content),
                        when: format.format(new Date(comment.created_at * 1000)),
                        iso: new Date(comment.created_at * 1000).toISOString(),
                        reply: isReply(comment),
                        name: author?.name ?? t.someone,
                        avatar: author?.avatar ?? null,
                        href: author?.href ?? null,
                        npub: author?.npub ?? null,
                        player: author?.player === true,
                        mine: comment.pubkey === config.me,
                    };
                });
        },

        get left() {
            return (config.maxLength ?? 1000) - [...this.text].length;
        },

        /* ---------- The comment ---------- */

        async preview() {
            if (this.step !== 'idle') {
                return;
            }

            const wire = this.$wire;
            void wire.$id;
            this.error = null;
            this.warning = null;
            this.posted = false;
            this.step = 'opening';

            try {
                const prepared = await wire.prepareComment(this.text);

                if (prepared?.error) {
                    this.error = prepared.error;
                    this.step = 'idle';

                    return;
                }

                this.template = prepared.template;
                this.step = 'preview';
            } catch (error) {
                console.warn('[comments] preparing the comment failed:', error);
                this.error = t.failed;
                this.step = 'idle';
            }
        },

        edit() {
            if (this.step === 'posting') {
                return;
            }

            this.step = 'idle';
            this.template = null;
            this.warning = null;
        },

        async post() {
            if (this.step !== 'preview') {
                return;
            }

            const wire = this.$wire;
            void wire.$id;
            const text = this.text;
            this.step = 'posting';
            this.error = null;
            this.warning = null;

            try {
                const result = await signAndSend(this, {
                    prepare: () => wire.prepareComment(text),
                    submit: (signed) => wire.submitComment(text, signed),
                    preview: this.template,
                    messages: t,
                    pubkey: config.me,
                    relays: config.writeRelays ?? [],
                });

                if (result?.changed) {
                    this.template = result.changed;
                    this.step = 'preview';

                    return;
                }

                if (! result?.signed) {
                    this.step = 'preview';

                    return;
                }

                this.comments = mergeComments(this.comments, [result.signed], config.target, config.maxShown).list;
                this.status = this.status === 'unreached' ? 'ready' : this.status;
                await this.lookUp([result.signed.pubkey]);
                this.text = '';
                this.template = null;
                this.posted = true;
                this.step = 'idle';
            } catch (error) {
                console.warn('[comments] posting failed:', error);
                this.error = t.failed;
                this.step = 'preview';
            }
        },

        /* ---------- The like ---------- */

        async previewLike() {
            if (this.likeStep !== 'idle' || this.likes.mine) {
                return;
            }

            const wire = this.$wire;
            void wire.$id;
            this.error = null;
            this.warning = null;
            this.likeStep = 'opening';

            try {
                const prepared = await wire.prepareReaction();

                if (prepared?.error) {
                    this.error = prepared.error;
                    this.likeStep = 'idle';

                    return;
                }

                this.likeTemplate = prepared.template;
                this.likeStep = 'preview';
            } catch (error) {
                console.warn('[comments] preparing the like failed:', error);
                this.error = t.failed;
                this.likeStep = 'idle';
            }
        },

        cancelLike() {
            if (this.likeStep === 'posting') {
                return;
            }

            this.likeStep = 'idle';
            this.likeTemplate = null;
        },

        async like() {
            if (this.likeStep !== 'preview') {
                return;
            }

            const wire = this.$wire;
            void wire.$id;
            this.likeStep = 'posting';
            this.error = null;
            this.warning = null;

            try {
                const result = await signAndSend(this, {
                    prepare: () => wire.prepareReaction(),
                    submit: (signed) => wire.submitReaction(signed),
                    preview: this.likeTemplate,
                    messages: t,
                    pubkey: config.me,
                    relays: config.writeRelays ?? [],
                });

                if (result?.changed) {
                    this.likeTemplate = result.changed;
                    this.likeStep = 'preview';

                    return;
                }

                if (! result?.signed) {
                    this.likeStep = 'preview';

                    return;
                }

                this.reactions = [...this.reactions, result.signed];
                this.likeTemplate = null;
                this.likeStep = 'idle';
            } catch (error) {
                console.warn('[comments] liking failed:', error);
                this.error = t.failed;
                this.likeStep = 'preview';
            }
        },
    };
}

function rsvpSummary(config) {
    const relays = readRelayList(config.relays ?? [], config.readRelays ?? 5);

    return {
        status: 'loading',
        going: [],
        notGoing: 0,
        maybe: 0,
        authors: {},

        async init() {
            const reads = [readAll(relays, [rsvpFilter(config.address, config.readLimit)])];
            const creator = config.moderation?.creator;
            const moderationRelays = readRelayList(config.moderation?.relays ?? [], config.readRelays ?? 5);

            if (creator && moderationRelays.length > 0) {
                reads.push(readAll(moderationRelays, [moderationFilter(creator)]));
            }

            const [rsvps, mod] = await Promise.all(reads);

            if (! rsvps.reached) {
                this.status = 'unreached';

                return;
            }

            const muted = [...(config.muted ?? []), ...(mod ? moderation(mod.events, creator).muted : [])];
            const tally = tallyRsvps(rsvps.events, config.address, { muted });
            this.going = tally.accepted;
            this.notGoing = tally.declined.length;
            this.maybe = tally.tentative.length;
            this.status = 'ready';

            const faces = this.going.slice(0, config.faces ?? 6);

            if (faces.length > 0) {
                try {
                    this.authors = (await this.$wire.authors(faces)) ?? {};
                } catch (error) {
                    console.warn('[rsvp] looking up the faces failed:', error);
                }
            }
        },

        get faces() {
            return this.going.slice(0, config.faces ?? 6).map((pubkey) => ({ pubkey, ...(this.authors[pubkey] ?? { name: '', avatar: null }) }));
        },
    };
}

function rsvpOffer(config) {
    const t = config.labels ?? {};

    return {
        step: 'idle',
        template: null,
        error: null,
        warning: null,

        async open() {
            if (this.step !== 'idle') {
                return;
            }

            const wire = this.$wire;
            void wire.$id;
            this.error = null;
            this.warning = null;
            this.step = 'opening';

            try {
                const prepared = await wire.prepareRsvp();

                if (prepared?.error) {
                    this.error = prepared.error;
                    this.step = 'idle';

                    return;
                }

                this.template = prepared.template;
                this.step = 'preview';
            } catch (error) {
                console.warn('[rsvp] preparing failed:', error);
                this.error = t.failed;
                this.step = 'idle';
            }
        },

        cancel() {
            if (this.step === 'posting') {
                return;
            }

            this.step = 'idle';
            this.template = null;
        },

        async send() {
            if (this.step !== 'preview') {
                return;
            }

            const wire = this.$wire;
            void wire.$id;
            this.step = 'posting';
            this.error = null;
            this.warning = null;

            try {
                const result = await signAndSend(this, {
                    prepare: () => wire.prepareRsvp(),
                    submit: (signed) => wire.submitRsvp(signed),
                    preview: this.template,
                    messages: t,
                    pubkey: config.me,
                    relays: config.writeRelays ?? [],
                });

                if (result?.changed) {
                    this.template = result.changed;
                    this.step = 'preview';

                    return;
                }

                this.step = result?.signed ? 'done' : 'preview';
            } catch (error) {
                console.warn('[rsvp] sending failed:', error);
                this.error = t.failed;
                this.step = 'preview';
            }
        },
    };
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('nostrComments', nostrComments);
    window.Alpine.data('rsvpSummary', rsvpSummary);
    window.Alpine.data('rsvpOffer', rsvpOffer);
});
