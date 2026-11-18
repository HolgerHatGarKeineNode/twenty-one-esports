/**
 * The Block 0 stage (resources/views/pages/admin/⚡stage.blade.php):
 * <div x-data="seasonStage({ pubkey, messages, labels })">.
 *
 * Act 1, arm:    $wire.arm(supply, rehearsal) -> the unsigned release label, signed here and kept in this
 *                module's memory only (never in storage, never sent before the hold is complete).
 * Act 2, stage:  a modal <dialog> over the admin page, fullscreen when the browser allows it.
 * Act 3, hold:   the big button only opens the confirm. The confirm's control ignores input for COLD_MS, then
 *                needs a press held for HOLD_MS (pointer, or Space / Enter held down). Only the completed hold
 *                calls $wire.launch(), once: `sending` is set before the first await.
 *
 * A signed label is good for ten minutes on the server (SignedEventGate::MAX_AGE_SECONDS, counted from the
 * label's created_at, which is the server's clock at arming or later). The page sends one only for
 * SIGNATURE_MS after the label arrived; an older one is dropped and the signer is asked again after the hold.
 *
 * Every time here is performance.now(): a wall clock that jumps does not shorten a hold or lengthen a signature.
 */
import { ensureSigner } from './nostrSign.js';
import { signerMessage, signTemplate } from './signing.js';

export const HOLD_MS = 2000;
export const COLD_MS = 1000;
// An outside click this soon after the confirm opened is the second click of a double click on the big button.
export const BACKDROP_MS = 500;
export const SIGNATURE_MS = 540_000;

export default function seasonStage({ pubkey = null, messages = {}, labels = {} } = {}) {
    // The signed label: a plain object outside Alpine's reactive state, so nothing renders or stores it.
    let signed = null;
    let holdTimer = null;
    let holdStart = 0;
    let coldTimer = null;
    let clockTimer = null;
    let confirmOpenedAt = 0;
    let pressedOutside = false;

    return {
        mode: 'rehearsal',
        supply: '',
        busy: false,
        error: '',
        notice: '',

        armed: false,
        rehearsal: true,
        armedEnd: '',
        expiresAt: 0,
        signedUntil: 0,
        signing: false,
        signerError: '',
        now: 0,

        scene: 'idle',
        holdReady: false,
        holding: false,
        sending: false,
        wait: 'release',
        failure: '',
        live: false,
        unknown: false,
        revealed: false,
        fsRefused: false,

        get signatureFresh() {
            return this.signedUntil > this.now;
        },

        /** The time left until `until` as m:ss; reads `now`, so it follows the tick. */
        clock(until) {
            const seconds = Math.max(0, Math.ceil((until - this.now) / 1000));

            return Math.floor(seconds / 60) + ':' + String(seconds % 60).padStart(2, '0');
        },

        tick() {
            this.now = performance.now();

            if (this.armed && this.now >= this.expiresAt) {
                this.expire();
            }
        },

        async arm() {
            if (this.busy || this.armed) {
                return;
            }

            this.busy = true;
            this.error = '';
            this.notice = '';

            try {
                const answer = await this.$wire.arm(this.supply, this.mode === 'rehearsal');
                const arrived = performance.now();

                if (! answer?.ok) {
                    this.error = answer?.reason ?? messages.failed ?? '';

                    return;
                }

                this.armed = true;
                this.rehearsal = !! answer.rehearsal;
                this.armedEnd = answer.end ?? '';
                this.expiresAt = arrived + answer.seconds * 1000;
                this.scene = 'idle';
                this.revealed = false;
                this.tick();
                clearInterval(clockTimer);
                clockTimer = setInterval(() => this.tick(), 1000);

                await this.sign(answer.template, arrived);
            } catch (error) {
                console.warn('[stage] arming failed:', error);
                this.error = messages.failed ?? '';
            } finally {
                this.busy = false;
            }
        },

        /** Sign the release label and keep it; false (with `signerError`) when the signer did not sign. */
        async sign(template, arrived) {
            signed = null;
            this.signedUntil = 0;
            this.signerError = '';
            this.signing = true;

            try {
                if (! (await ensureSigner())) {
                    this.signerError = messages.noSigner ?? '';

                    return false;
                }

                signed = await signTemplate(template, { pubkey });
                this.signedUntil = arrived + SIGNATURE_MS;
                this.tick();

                return true;
            } catch (error) {
                this.signerError = signerMessage(messages, error);

                return false;
            } finally {
                this.signing = false;
            }
        },

        async signAgain() {
            if (this.busy || this.signing || ! this.armed) {
                return;
            }

            this.busy = true;
            this.error = '';

            try {
                const answer = await this.$wire.label();
                const arrived = performance.now();

                if (! answer?.ok) {
                    this.error = answer?.reason ?? messages.failed ?? '';

                    return;
                }

                await this.sign(answer.template, arrived);
            } catch (error) {
                console.warn('[stage] signing again failed:', error);
                this.error = messages.failed ?? '';
            } finally {
                this.busy = false;
            }
        },

        async disarm() {
            if (this.sending) {
                return;
            }

            this.forget();

            try {
                await this.$wire.disarm();
            } catch (error) {
                console.warn('[stage] disarming failed:', error);
            }
        },

        /** Drop everything an arming holds in this page. */
        forget() {
            signed = null;
            this.armed = false;
            this.signedUntil = 0;
            this.signerError = '';
            this.supply = '';
            clearInterval(clockTimer);
        },

        expire() {
            if (this.sending) {
                return;
            }

            const onStage = this.$refs.stage.open;
            this.closeConfirm();
            this.disarm();
            this.notice = labels.expired ?? '';

            if (onStage) {
                this.failure = labels.expired ?? '';
                this.live = false;
                this.unknown = true;
                this.scene = 'expired';
            }
        },

        async openStage() {
            if (! this.armed) {
                return;
            }

            this.scene = 'idle';
            this.revealed = false;

            if (! this.$refs.stage.open) {
                this.$refs.stage.showModal();
            }

            // Nothing on the stage is focused when it opens: a stray Enter or Space meets no button.
            this.$refs.stage.focus();
            await this.enterFullscreen();
        },

        async enterFullscreen() {
            this.fsRefused = false;

            try {
                if (typeof document.documentElement.requestFullscreen !== 'function') {
                    throw new Error('The Fullscreen API is not available');
                }

                if (! document.fullscreenElement) {
                    await document.documentElement.requestFullscreen({ navigationUI: 'hide' });
                }
            } catch (error) {
                console.info('[stage] fullscreen refused:', error?.message ?? error);
                this.fsRefused = true;
            }
        },

        leaveStage() {
            if (this.sending || this.$refs.confirm.open) {
                return;
            }

            if (document.fullscreenElement) {
                document.exitFullscreen().catch(() => {});
            }

            // After an outcome the page under the stage is stale (the season may be live, the arming is spent):
            // onStageClosed() loads it again.
            this.$refs.stage.close();

            if (this.scene !== 'done' && this.scene !== 'failed') {
                this.scene = 'idle';
            }
        },

        /**
         * The stage closed without leaveStage(): a browser lets a second Escape through. While the release is on its
         * way the stage comes back; after an outcome the page under it is stale and loads again.
         */
        onStageClosed() {
            if (this.sending) {
                this.$refs.stage.showModal();

                return;
            }

            if (this.scene === 'done' || this.scene === 'failed') {
                window.location.reload();
            }
        },

        openConfirm() {
            if (! this.armed || this.scene !== 'idle' || this.sending) {
                return;
            }

            this.scene = 'confirm';
            this.holdReady = false;
            this.holding = false;
            this.$refs.confirm.showModal();
            confirmOpenedAt = performance.now();
            // The safe choice holds the focus (R18); the hold control is never focused by the page.
            this.$refs.cancel.focus();
            clearTimeout(coldTimer);
            coldTimer = setTimeout(() => {
                this.holdReady = this.$refs.confirm.open;
            }, COLD_MS);
        },

        closeConfirm() {
            if (this.sending || ! this.$refs.confirm.open) {
                return;
            }

            this.$refs.confirm.close();
        },

        /** Escape: the browser closes the confirm, except while the release is on its way. */
        onConfirmCancel(event) {
            if (this.sending) {
                event.preventDefault();
            }
        },

        onConfirmClosed() {
            // A browser lets a second Escape through without asking: while the release is on its way, the confirm
            // comes back and keeps saying so.
            if (this.sending) {
                this.$refs.confirm.showModal();

                return;
            }

            clearTimeout(coldTimer);
            this.cancelHold();
            this.holdReady = false;

            if (this.scene === 'confirm') {
                this.scene = 'idle';
            }

            // The browser hands the focus back to the big button; a key held down would open the confirm again.
            if (this.$refs.stage.open) {
                this.$refs.stage.focus();
            }
        },

        backdropPressed(event) {
            pressedOutside = event.target === this.$refs.confirm;
        },

        backdrop() {
            // A press that began on the hold and slipped off it is not a click outside.
            if (! pressedOutside || performance.now() - confirmOpenedAt < BACKDROP_MS) {
                return;
            }

            this.closeConfirm();
        },

        startHold() {
            if (! this.holdReady || this.holding || this.sending || ! this.armed) {
                return;
            }

            this.holding = true;
            holdStart = performance.now();
            clearTimeout(holdTimer);
            holdTimer = setTimeout(() => this.completeHold(), HOLD_MS);
        },

        cancelHold() {
            clearTimeout(holdTimer);
            this.holding = false;
        },

        completeHold() {
            if (! this.holding) {
                return;
            }

            const left = HOLD_MS - (performance.now() - holdStart);

            // A timer may fire a moment early; the hold is complete only after the full time.
            if (left > 0) {
                holdTimer = setTimeout(() => this.completeHold(), left);

                return;
            }

            this.holding = false;
            this.launch();
        },

        async launch() {
            if (this.sending || ! this.armed) {
                return;
            }

            this.sending = true;
            this.wait = 'release';

            try {
                if (signed === null || performance.now() >= this.signedUntil) {
                    this.wait = 'signer';
                    const label = await this.$wire.label();
                    const arrived = performance.now();

                    if (! label?.ok) {
                        this.fail(label?.reason, { live: label?.live });

                        return;
                    }

                    if (! (await this.sign(label.template, arrived))) {
                        this.fail([this.signerError, labels.notSigned].filter(Boolean).join(' '), { unknown: true });

                        return;
                    }

                    this.wait = 'release';
                }

                // A rehearsal sends no signature at all.
                const payload = this.rehearsal ? '' : JSON.stringify([signed]);
                signed = null;
                const answer = await this.$wire.launch(payload);

                if (! answer?.ok) {
                    this.fail(answer?.reason, { live: answer?.live });

                    return;
                }

                this.finish('done');
                this.$nextTick(() => this.$refs.doneTitle.focus());
            } catch (error) {
                console.warn('[stage] the release request failed:', error);
                this.fail(labels.unknown, { unknown: true });
            }
        },

        fail(reason, { live = false, unknown = false } = {}) {
            this.failure = reason || messages.failed || '';
            this.live = !! live;
            this.unknown = unknown;
            this.finish('failed');
        },

        /** The arming is spent: close the confirm and show the outcome. */
        finish(scene) {
            this.forget();
            this.sending = false;
            this.holding = false;
            this.revealed = false;
            this.scene = scene;

            if (this.$refs.confirm.open) {
                this.$refs.confirm.close();
            }
        },
    };
}
