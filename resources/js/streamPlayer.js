/**
 * Plays the league's HLS stream into one <video> (P20): natively where the
 * browser says it can (Safari), otherwise with hls.js (its light build: one
 * audio track, no subtitles, no DRM, as the stream is), which is loaded only
 * when something is played, so no page pays for it up front.
 *
 * It recovers on its own: a media error is handed back to hls.js
 * (recoverMediaError, then once more after swapping the audio codec), a
 * network error or a stall restarts the stream after a growing pause (5, 10,
 * 20, 40 s). After that the stream counts as ended: nothing loads any more
 * until `start()` is called again. `stop()` detaches everything, so a closed
 * player costs no bandwidth.
 *
 * Autoplay refused even muted (NotAllowedError: Firefox and LibreWolf with
 * "block audio and video", Safari's "never auto-play") is no error: the
 * state turns `blocked`, the stream stays loaded, nothing is retried, and
 * `resume()` from a click (a user gesture, always allowed) plays it. The
 * page cannot ask the browser for autoplay permission.
 *
 * States reported through `onState`: loading, playing, retrying, ended,
 * unsupported, blocked.
 */

const RETRY_SECONDS = [5, 10, 20, 40];

/** No new frame for this long while it should play: the stream stalled (or ended without saying so). */
const STALL_SECONDS = 24;

export function playsHlsNatively(video) {
    return typeof video.canPlayType === 'function' && video.canPlayType('application/vnd.apple.mpegurl') !== '';
}

export class StreamPlayer {
    constructor(video, url, onState = () => {}) {
        this.video = video;
        this.url = url;
        this.onState = onState;
        this.hls = null;
        this.attempt = 0;
        this.mediaRecoveries = 0;
        this.retryTimer = null;
        this.stallTimer = null;
        this.lastTime = -1;
        this.lastProgressAt = 0;
        this.running = false;
        this.blocked = false;
        this.engine = null;

        this.onPlaying = () => {
            this.attempt = 0;
            this.onState('playing');
        };
        this.onNativeError = () => this.fail();
        video.addEventListener('playing', this.onPlaying);
    }

    async start() {
        this.stop(false);
        this.running = true;
        this.onState('loading');

        if (playsHlsNatively(this.video)) {
            this.engine = 'native';
            this.video.dataset.engine = 'native';
            this.video.addEventListener('error', this.onNativeError);
            this.video.src = this.url;
        } else {
            let Hls;

            try {
                ({ default: Hls } = await import('hls.js/light'));
            } catch {
                this.fail();

                return;
            }

            if (!this.running) return;

            if (!Hls.isSupported()) {
                this.running = false;
                this.onState('unsupported');

                return;
            }

            this.engine = 'hls.js';
            this.video.dataset.engine = 'hls.js';
            const hls = new Hls({
                // Four segments behind the edge: 24 s of room for one late piece, still inside the 36 s playlist window.
                liveSyncDurationCount: 4,
                maxBufferLength: 30,
                backBufferLength: 12,
                manifestLoadingMaxRetry: 1,
                levelLoadingMaxRetry: 2,
                fragLoadingMaxRetry: 2,
            });
            this.hls = hls;

            hls.on(Hls.Events.ERROR, (_event, data) => {
                if (!data.fatal || this.hls !== hls) return;

                if (data.type === Hls.ErrorTypes.MEDIA_ERROR && this.mediaRecoveries < 2) {
                    this.mediaRecoveries += 1;
                    if (this.mediaRecoveries === 2) hls.swapAudioCodec();
                    hls.recoverMediaError();

                    return;
                }

                this.fail();
            });
            hls.loadSource(this.url);
            hls.attachMedia(this.video);
        }

        this.watchStall();
        this.play();
    }

    play() {
        const refused = (error) => error?.name === 'NotAllowedError';
        const attempt = () => Promise.resolve(this.video.play());

        attempt().catch((error) => {
            if (!this.running || !refused(error)) return;

            // Refused with sound (a sound-on start without a gesture): once more muted.
            if (!this.video.muted) {
                this.video.muted = true;
                attempt().catch((again) => {
                    if (this.running && refused(again)) this.block();
                });

                return;
            }

            this.block();
        });
    }

    /** Autoplay refused even muted: wait for a click instead of "Tuning in" forever. */
    block() {
        this.blocked = true;
        this.onState('blocked');
    }

    /**
     * Play after a click on the page's play button. Call it from the click
     * handler itself: video.play() must run inside the gesture.
     */
    resume() {
        if (!this.running) {
            this.start();

            return;
        }

        this.blocked = false;
        this.onState('loading');
        this.play();
    }

    /**
     * Something broke: try again after a pause that grows, then give up.
     */
    fail() {
        if (!this.running) return;

        const wait = RETRY_SECONDS[this.attempt];
        this.stop(false);

        if (wait === undefined) {
            this.onState('ended');

            return;
        }

        this.attempt += 1;
        this.running = true;
        this.onState('retrying');
        this.retryTimer = setTimeout(() => {
            if (this.running) this.start();
        }, wait * 1000);
    }

    watchStall() {
        this.lastTime = -1;
        this.lastProgressAt = Date.now();
        clearInterval(this.stallTimer);
        this.stallTimer = setInterval(() => {
            // Paused on purpose, or waiting for a click after autoplay was refused: nothing to watch for.
            if (this.blocked || (this.video.paused && this.video.readyState >= 2)) {
                this.lastProgressAt = Date.now();

                return;
            }

            const time = this.video.currentTime;
            if (time !== this.lastTime) {
                this.lastTime = time;
                this.lastProgressAt = Date.now();

                return;
            }

            if (Date.now() - this.lastProgressAt > STALL_SECONDS * 1000) this.fail();
        }, 2000);
    }

    /**
     * Stop loading and let go of the stream. `final` also forgets the retries.
     */
    stop(final = true) {
        clearTimeout(this.retryTimer);
        clearInterval(this.stallTimer);
        this.blocked = false;
        this.retryTimer = null;
        this.stallTimer = null;
        this.mediaRecoveries = 0;

        if (final) {
            this.running = false;
            this.attempt = 0;
        }

        if (this.hls) {
            this.hls.destroy();
            this.hls = null;
        }

        this.video.removeEventListener('error', this.onNativeError);

        if (this.video.getAttribute('src') !== null || this.video.srcObject || this.video.currentSrc) {
            this.video.pause();
            this.video.removeAttribute('src');
            this.video.load();
        }

        this.engine = null;
        if (!final) this.running = false;
    }

    destroy() {
        this.stop();
        this.video.removeEventListener('playing', this.onPlaying);
    }
}
