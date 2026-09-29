/*
 * The live board of a board game next to chess (plan "Mühle und Dame", P2):
 * nine men's morris, checkers and the test fixture all use it. It knows no
 * rules. It draws what the server describes (the layout: lines, cells and
 * clickable points; the state: pieces and legal moves) and builds a move from
 * clicks along the paths of the legal moves the server sent. It proposes that
 * move and shows whatever the server answers: the server alone decides.
 *
 * Realtime as on the chess page (resources/js/chess.js, not imported so chess
 * stays untouched): every change reaches both players on the private
 * `board.{id}` channel and spectators on the public `board.{id}.watch`
 * channel; a heartbeat asks the server anyway, and without a websocket the
 * page polls.
 */

const SVG = 'http://www.w3.org/2000/svg';

/** How long the page trusts the websocket alone before it asks the server. */
const HEARTBEAT_MS = 4000;

// Black men are dark discs with a light rim: on the dark board a dark rim would hide them.
const PIECE_FILL = { w: '#F4F4F5', b: '#09090B' };
const PIECE_STROKE = { w: '#A1A1AA', b: '#D4D4D8' };

export function formatClock(ms) {
    const total = Math.max(0, Math.ceil(ms / 1000));

    return Math.floor(total / 60) + ':' + String(total % 60).padStart(2, '0');
}

/**
 * The legal moves whose path begins with these clicks.
 */
export function candidates(legal, clicks) {
    return legal.filter((option) => clicks.every((point, index) => option.path[index] === point));
}

/**
 * The radius a piece gets: a little over a third of the closest distance
 * between two points, so neighbours never touch.
 */
function pieceRadius(points) {
    let closest = Infinity;
    points.forEach((a, i) => points.slice(i + 1).forEach((b) => (closest = Math.min(closest, Math.hypot(a.x - b.x, a.y - b.y)))));

    return Number.isFinite(closest) ? closest * 0.36 : 20;
}

function watchConnection(onChange) {
    const pusher = window.Echo?.connector?.pusher;
    if (!pusher) {
        onChange('none');

        return;
    }

    onChange(pusher.connection.state === 'connected' ? 'connected' : 'connecting');
    pusher.connection.bind('state_change', ({ current }) => onChange(current));
}

function svgElement(name, attributes) {
    const element = document.createElementNS(SVG, name);
    Object.entries(attributes).forEach(([key, value]) => element.setAttribute(key, String(value)));

    return element;
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('boardGame', (config) => ({
        state: config.state,
        layout: config.layout,
        color: config.color,
        t: config.labels,
        radius: pieceRadius(config.layout.points),
        receivedAt: performance.now(),
        now: performance.now(),
        clicks: [],
        pending: false,
        error: '',
        confirmResign: false,
        connection: 'connecting',
        wasConnected: false,
        ticker: null,
        poller: null,
        lastSyncAt: 0,
        syncing: false,
        clockCheckSent: 0,

        init() {
            this.ticker = setInterval(() => this.tick(), 200);
            this.lastSyncAt = performance.now();

            document.addEventListener('visibilitychange', () => {
                if (document.visibilityState === 'visible') this.resync();
            });

            watchConnection((current) => {
                const before = this.connection;
                this.connection = current;
                // Back after a drop: pushes may have been missed meanwhile.
                if (current === 'connected' && before !== 'connected' && this.wasConnected) this.resync();
                if (current === 'connected') this.wasConnected = true;
                this.syncPolling();
            });

            if (window.Echo) {
                const channel = this.color ? window.Echo.private('board.' + this.state.id) : window.Echo.channel('board.' + this.state.id + '.watch');
                channel.listen('.board.updated', (update) => this.receive(update));
                // Anything that happened between rendering the page and subscribing.
                channel.subscribed?.(() => this.resync());
            }

            this.render();
        },

        destroy() {
            clearInterval(this.ticker);
            clearInterval(this.poller);
        },

        /* ---- the board ------------------------------------------------------------------------------ */

        get myTurn() {
            return this.color !== null && this.state.status === 'active' && this.state.turn === this.color;
        },

        get canMove() {
            return this.myTurn && !this.pending;
        },

        /** The points a click may go to next: the start of a move, or the next step of one begun. */
        get targets() {
            if (!this.canMove) return [];
            const next = new Set(candidates(this.state.legal, this.clicks).map((option) => option.path[this.clicks.length]));
            next.delete(undefined);

            return [...next];
        },

        render() {
            const svg = this.$refs.board;
            if (!svg) return;
            svg.replaceChildren();
            const r = this.radius;
            const targets = new Set(this.targets);
            const last = new Set(this.state.lastMove?.path ?? []);

            this.layout.cells.forEach((cell) => svg.append(svgElement('rect', { x: cell.x, y: cell.y, width: cell.size, height: cell.size, fill: '#3F3F46' })));
            this.layout.lines.forEach(([x1, y1, x2, y2]) => svg.append(svgElement('line', { x1, y1, x2, y2, stroke: '#52525B', 'stroke-width': Math.max(2, r * 0.12), 'stroke-linecap': 'round' })));

            this.layout.points.forEach((point) => {
                const piece = this.state.pieces[point.id] ?? null;
                const clicked = this.clicks.includes(point.id);
                const group = svgElement('g', {
                    'data-point': point.id,
                    'data-piece': piece ? piece.side + ':' + piece.kind : '',
                    'data-target': targets.has(point.id) ? '1' : '0',
                    role: 'button',
                    tabindex: targets.has(point.id) || clicked ? 0 : -1,
                    'aria-label': this.pointLabel(point.id, piece),
                    'aria-pressed': clicked ? 'true' : 'false',
                    class: 'outline-none',
                });
                group.style.cursor = targets.has(point.id) || clicked ? 'pointer' : 'default';
                // The whole disc around the point takes the click, not only the piece.
                group.append(svgElement('circle', { cx: point.x, cy: point.y, r, fill: 'transparent' }));
                if (last.has(point.id)) group.append(svgElement('circle', { cx: point.x, cy: point.y, r: r * 1.12, fill: 'rgba(247, 147, 26, 0.18)' }));
                group.append(svgElement('circle', { cx: point.x, cy: point.y, r: Math.max(3, r * 0.14), fill: '#71717A' }));
                if (piece) {
                    group.append(svgElement('circle', { cx: point.x, cy: point.y, r: r * 0.92, fill: PIECE_FILL[piece.side], stroke: PIECE_STROKE[piece.side], 'stroke-width': Math.max(2, r * 0.08) }));
                    if (piece.kind !== 'man') group.append(svgElement('circle', { cx: point.x, cy: point.y, r: r * 0.45, fill: 'none', stroke: '#F7931A', 'stroke-width': Math.max(2, r * 0.1) }));
                }
                if (clicked) group.append(svgElement('circle', { cx: point.x, cy: point.y, r: r * 1.02, fill: 'none', stroke: '#F7931A', 'stroke-width': Math.max(3, r * 0.12) }));
                if (targets.has(point.id) && !clicked) group.append(svgElement('circle', { cx: point.x, cy: point.y, r: Math.max(5, r * 0.26), fill: 'rgba(247, 147, 26, 0.75)' }));
                svg.append(group);
            });
        },

        pointLabel(id, piece) {
            return piece ? id + ', ' + (piece.side === 'w' ? this.t.white : this.t.black) : id;
        },

        pick(event) {
            const point = event.target.closest?.('[data-point]')?.dataset.point;
            if (point) this.click(point);
        },

        pickKey(event) {
            if (event.key !== 'Enter' && event.key !== ' ') return;
            const point = event.target.closest?.('[data-point]')?.dataset.point;
            if (!point) return;
            event.preventDefault();
            this.click(point);
        },

        click(point) {
            if (!this.canMove) return;
            this.error = '';

            // A second click on the last point takes that click back.
            if (this.clicks.length > 0 && this.clicks[this.clicks.length - 1] === point) {
                this.clicks = this.clicks.slice(0, -1);
                this.render();

                return;
            }

            const next = [...this.clicks, point];
            const options = candidates(this.state.legal, next);

            if (options.length === 0) {
                // Not part of the move begun: start over from this point, if a move starts there.
                this.clicks = candidates(this.state.legal, [point]).length > 0 ? [point] : [];
                const complete = this.clicks.length === 1 ? candidates(this.state.legal, this.clicks).find((option) => option.path.length === 1) : null;
                if (complete) return this.send(complete.move);
                this.render();

                return;
            }

            const complete = options.find((option) => option.path.length === next.length);
            if (complete) return this.send(complete.move);

            this.clicks = next;
            this.render();
        },

        async send(move) {
            this.clicks = [];
            this.pending = true;
            this.render();
            try {
                await this.call('move', move, this.state.ply + 1);
            } finally {
                this.pending = false;
                this.render();
            }
        },

        /* ---- clocks and status ---------------------------------------------------------------------- */

        tick() {
            this.now = performance.now();
            if (this.state.status !== 'active') return;
            if (this.connection === 'connected' && this.now - this.lastSyncAt > HEARTBEAT_MS) this.resync();
            const running = this.state.clock.running;
            const due = running ? this.remaining(running) <= 0 : this.state.firstMoveDeadline && this.serverNow() >= this.state.firstMoveDeadline;
            // The display reached zero: ask the server, which alone decides.
            if (due && this.now - this.clockCheckSent > 2000) {
                this.clockCheckSent = this.now;
                this.call('checkClock');
            }
        },

        serverNow() {
            return this.state.clock.serverNow + (this.now - this.receivedAt);
        },

        remaining(side) {
            const base = this.state.clock[side];
            if (this.state.status === 'active' && this.state.clock.running === side) {
                return Math.max(0, base - (this.now - this.receivedAt));
            }

            return base;
        },

        clock(side) {
            return formatClock(this.remaining(side));
        },

        get topSide() {
            return this.color === 'b' ? 'w' : 'b';
        },

        get bottomSide() {
            return this.color === 'b' ? 'b' : 'w';
        },

        sideName(side) {
            return this.t.names[side] + ' · ' + (side === 'w' ? this.t.white : this.t.black);
        },

        get statusLine() {
            if (this.state.status === 'aborted') return this.t.status.aborted;
            if (this.state.status !== 'active') return this.t.status.over;

            return this.t.status.live.replace(':move', this.state.ply + 1).replace(':side', this.state.turn === 'w' ? this.t.white : this.t.black);
        },

        get firstMoveLine() {
            if (this.state.status !== 'active' || !this.state.firstMoveDeadline) return '';
            const seconds = Math.max(0, Math.ceil((this.state.firstMoveDeadline - this.serverNow()) / 1000));

            return this.t.firstMove.replace(':side', this.state.turn === 'w' ? this.t.white : this.t.black).replace(':s', seconds);
        },

        get outcome() {
            if (this.state.status === 'active') return '';
            if (this.state.status === 'aborted') return this.t.outcome.aborted;
            const reason = this.t.reasons[this.state.reason] ?? this.state.reason ?? '';
            if (this.state.result === '1/2-1/2') return this.t.outcome.draw + ' · ' + reason;
            const winner = this.state.result === '1-0' ? 'w' : 'b';

            return this.t.outcome.wins.replace(':name', this.t.names[winner]) + ' · ' + reason;
        },

        get connectionLabel() {
            if (this.connection === 'connected') return this.t.connection.connected;
            if (this.connection === 'none') return this.t.connection.polling;

            return this.t.connection.connecting;
        },

        /* ---- state from the server ------------------------------------------------------------------ */

        syncPolling() {
            const needed = this.connection !== 'connected' && this.state.status === 'active';
            if (needed && !this.poller) {
                this.poller = setInterval(() => this.resync(), 3000);
            } else if (!needed && this.poller) {
                clearInterval(this.poller);
                this.poller = null;
            }
        },

        apply(state) {
            if (!state || state.version < this.state.version) return;
            const changed = state.version !== this.state.version;
            this.state = { ...state, moves: state.moves ?? this.state.moves };
            this.receivedAt = performance.now();
            this.lastSyncAt = this.receivedAt;
            this.now = this.receivedAt;
            if (changed) this.clicks = [];
            if (this.state.status !== 'active') this.confirmResign = false;
            this.syncPolling();
            this.render();
        },

        receive(update) {
            if (update.version <= this.state.version) return;
            if (update.ply === this.state.ply + 1 && update.lastMove) {
                this.apply({ ...update, moves: [...this.state.moves, update.lastMove] });
            } else if (update.ply === this.state.ply) {
                this.apply({ ...update, moves: this.state.moves });
            } else {
                this.resync();
            }
        },

        async resync() {
            if (this.syncing) return;
            this.syncing = true;
            this.lastSyncAt = performance.now();
            try {
                const state = await this.$wire.fetchState();
                if (state) this.apply(state);
            } finally {
                this.syncing = false;
            }
        },

        async call(method, ...args) {
            const response = await this.$wire[method](...args);
            if (!response) return null;
            if (response.state) this.apply(response.state);
            this.error = response.ok === false ? (this.t.errors[response.error] ?? this.t.errors.default) : '';

            return response;
        },

        resign() {
            if (!this.confirmResign) {
                this.confirmResign = true;

                return;
            }
            this.confirmResign = false;
            this.call('resign');
        },
    }));
});
