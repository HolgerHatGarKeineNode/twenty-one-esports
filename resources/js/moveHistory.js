/*
 * Browsing the moves of a chess game (P55): every board steps back through its
 * own history like Lichess. One implementation for the finished-game replay,
 * the live blitz board (players and spectators) and the daily game.
 *
 *   positionsFrom(startFen)  — a replayer: moves → the position before the
 *                              first move and after every ply, extended as
 *                              moves arrive instead of replayed each time.
 *   withHistory(host)        — adds the history state and actions to an
 *                              Alpine data object. The host provides
 *                              `historyMoves` (the plies, {uci, san}) and
 *                              `historyFen(index)`, and may define
 *                              `onBrowse()` (called before every step).
 *   revealIn(scroller, el)   — scrolls a move list to a move without moving
 *                              the page (scrollIntoView would scroll the
 *                              document too).
 *
 * `viewIndex` null means "follow the game": the board shows the newest
 * position and moves along with it. A number pins the board to the position
 * after that many plies; new moves then do not move it, they are counted in
 * `newMoves`. Stepping forward onto the newest position follows the game again.
 */
import { Chess } from 'chess.js';
import { displaySan } from './sanNotation.js';

export function positionsFrom(startFen) {
    let ucis = [];
    let fens = [startFen];

    return (moves) => {
        let same = 0;
        while (same < ucis.length && same < moves.length && ucis[same] === moves[same].uci) same++;
        if (same < ucis.length) {
            // A move taken back (an own move the server refused): replay from there.
            ucis = ucis.slice(0, same);
            fens = fens.slice(0, same + 1);
        }
        if (ucis.length < moves.length) {
            const chess = new Chess(fens[fens.length - 1]);
            for (let i = ucis.length; i < moves.length; i++) {
                const uci = moves[i].uci;
                try {
                    chess.move({ from: uci.slice(0, 2), to: uci.slice(2, 4), promotion: uci[4] });
                } catch {
                    break;
                }
                ucis.push(uci);
                fens.push(chess.fen());
            }
        }

        return fens;
    };
}

/** "12. Nf3" after White's ply, "12… Nf6" after Black's; `index` counts plies from 1. */
export function plyLabel(index, san) {
    return Math.ceil(index / 2) + (index % 2 === 1 ? '. ' : '… ') + displaySan(san);
}

export function withHistory(host) {
    const history = {
        viewIndex: null,
        // How many plies there were when browsing began: everything past it is new.
        seenPlies: 0,

        get historyLength() {
            return this.historyMoves.length;
        },

        get shownIndex() {
            return this.viewIndex === null ? this.historyLength : Math.min(this.viewIndex, this.historyLength);
        },

        get browsing() {
            return this.viewIndex !== null && this.viewIndex < this.historyLength;
        },

        get newMoves() {
            return this.browsing ? Math.max(0, this.historyLength - this.seenPlies) : 0;
        },

        get shownFen() {
            return this.historyFen(this.shownIndex);
        },

        get shownSquares() {
            const move = this.historyMoves[this.shownIndex - 1];

            return move ? [move.uci.slice(0, 2), move.uci.slice(2, 4)] : [];
        },

        /** The shown position as "12… Nf6", or '' for the start position. */
        get shownLabel() {
            const move = this.historyMoves[this.shownIndex - 1];

            return move ? plyLabel(this.shownIndex, move.san) : '';
        },

        /** The newest move as "31. Qh5", or ''. */
        get newestLabel() {
            const n = this.historyLength;

            return n > 0 ? plyLabel(n, this.historyMoves[n - 1].san) : '';
        },

        go(index) {
            const n = this.historyLength;
            const target = Math.max(0, Math.min(n, index));
            this.onBrowse?.(target);
            if (target >= n) {
                this.viewIndex = null;

                return;
            }
            if (this.viewIndex === null) this.seenPlies = n;
            this.viewIndex = target;
        },

        toLive() {
            this.go(this.historyLength);
        },

        /** The history keys (see hotkeys.js boardKey); true when the key was one of them. */
        historyKey(key, event = null) {
            const steps = { ArrowLeft: () => this.go(this.shownIndex - 1), ArrowRight: () => this.go(this.shownIndex + 1), Home: () => this.go(0), End: () => this.toLive() };
            if (!steps[key]) return false;
            event?.preventDefault();
            steps[key]();

            return true;
        },

        /**
         * Keeps a move list on the shown move, and at its newest end while following the game.
         * The list says where that end is: data-newest="reverse" (a column- or row-reverse
         * list rests there at scroll offset 0) or "right" (a strip that grows to the right).
         */
        revealShown(scroller) {
            const following = !this.browsing;
            requestAnimationFrame(() => {
                if (following && scroller.dataset.newest === 'reverse') {
                    scroller.scrollTop = 0;
                    scroller.scrollLeft = 0;
                } else if (following && scroller.dataset.newest === 'right') {
                    scroller.scrollLeft = scroller.scrollWidth;
                } else {
                    revealIn(scroller, scroller.querySelector('[aria-current=step]'));
                }
            });
        },
    };

    return Object.defineProperties(host, Object.getOwnPropertyDescriptors(history));
}

/** A move out of view is brought to the middle of its list, so the moves around it show too. */
export function revealIn(scroller, el) {
    if (!scroller || !el) return;
    const port = scroller.getBoundingClientRect();
    const box = el.getBoundingClientRect();
    if (box.top < port.top || box.bottom > port.bottom) scroller.scrollTop += box.top + box.height / 2 - (port.top + port.height / 2);
    if (box.left < port.left || box.right > port.right) scroller.scrollLeft += box.left + box.width / 2 - (port.left + port.width / 2);
}
