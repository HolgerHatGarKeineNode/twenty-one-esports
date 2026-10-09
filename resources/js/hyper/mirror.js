/**
 * The table as the page shows it between two snapshots (plan "Hyperbitcoinization", P2): the server's
 * snapshot (HyperMatches::snapshot()) turned into one object, and the server's events written into it one by
 * one while they are animated, so the pins count down during a battle and the map turns as it is conquered.
 *
 * Nothing here decides anything. Every number comes from an event: dice, losses, conquests, placements,
 * card effects. Where an event does not carry a number (an undo's refund, whether a fortify used up the
 * turn's moves) the mirror leaves it, and the next snapshot sets it right; the page loads one after every
 * batch of events (game.js). defenseBonus() and odds() only draw the battle screen's table and chance bar;
 * the server rolls and judges.
 *
 * No DOM, so tests/js/hyperMirror.test.mjs runs it in Node.
 */
import { ADJ, BANK, ZONE_OF, ZT } from './data.js';

const r1 = (n) => Math.round(n * 10) / 10;
const r2 = (n) => Math.round(n * 100) / 100;

/**
 * The page's table from a snapshot: the state as the viewer may see it, merged with the seats' public info.
 */
export function fromSnapshot(snapshot) {
    const state = snapshot.state ?? {};
    const terr = {};

    for (const [id, row] of Object.entries(state.territories ?? {})) {
        terr[id] = { owner: row.owner ?? null, pleb: row.pleb ?? 0, maxi: row.maxi ?? 0, asic: row.asic ?? 0, shield: row.shield ?? null };
    }

    const seats = (state.seats ?? []).map((seat, index) => {
        const info = (snapshot.seats ?? []).find((s) => s.seat === index) ?? {};

        return {
            seat: index,
            faction: seat.faction ?? info.faction,
            // A team match (P4): the seat's side, 0 or 1; null without teams.
            team: info.team ?? seat.team ?? null,
            bot: info.bot ?? seat.bot ?? false,
            takeover: info.takeover ?? null,
            left: info.left ?? false,
            userId: info.user_id ?? null,
            name: info.name ?? null,
            pubkey: info.pubkey ?? null,
            avatar: info.avatar ?? null,
            place: info.place ?? null,
            fiat: seat.fiat ?? 0,
            sats: seat.sats ?? 0,
            loot: info.loot ?? seat.loot ?? 0,
            hand: Array.isArray(seat.hand) ? [...seat.hand] : null,
            handCount: seat.hand_count ?? (Array.isArray(seat.hand) ? seat.hand.length : 0),
            out: !!seat.out,
            freePlebs: seat.free_plebs ?? 0,
            dip: !!seat.dip,
            conquered: seat.conquered ?? 0,
            moves: seat.moves ?? 0,
        };
    });

    return {
        id: snapshot.id,
        ply: snapshot.ply ?? 0,
        status: snapshot.status,
        round: state.round ?? snapshot.round ?? 1,
        cur: snapshot.seat ?? state.seat ?? 0,
        phase: state.phase ?? snapshot.phase ?? 'buy',
        inflation: !!state.inflation,
        inflationNext: !!state.inflation_next,
        fortified: !!state.fortified,
        // Finished, or voided by the league (P5c, `aborted`): either way the match is over.
        over: (snapshot.status !== undefined && snapshot.status !== 'active') || !!state.over,
        voided: snapshot.end_reason === 'voided',
        endedAt: Number.isSafeInteger(snapshot.ended_at) ? snapshot.ended_at : null,
        winner: snapshot.winner ?? state.winner ?? null,
        byLimit: !!state.by_limit,
        limit: snapshot.limit ?? state.limit ?? 0,
        endReason: snapshot.end_reason ?? null,
        pending: state.pending_move ?? null,
        terr,
        seats,
    };
}

export function units(G, id) {
    const t = G.terr[id];

    return t ? t.pleb + t.maxi + t.asic : 0;
}

export function territoriesOf(G, seat) {
    return Object.keys(G.terr).filter((id) => G.terr[id].owner === seat);
}

export function banksOf(G, seat) {
    return territoriesOf(G, seat).filter((id) => BANK[id]).length;
}

export function zoneOwner(G, zone) {
    const ids = ZT[zone] ?? [];
    const owner = G.terr[ids[0]]?.owner ?? null;

    return owner !== null && ids.every((id) => G.terr[id]?.owner === owner) ? owner : null;
}

/** Losses take plebs first, then ASICs, then maxis (as the server's HyperGame::lose()). */
export function lose(t, n) {
    for (let i = 0; i < n; i++) {
        if (t.pleb > 0) t.pleb--;
        else if (t.asic > 0) t.asic--;
        else if (t.maxi > 0) t.maxi--;
    }
}

/** Moves up to n units, plebs first, then ASICs, then maxis; one unit stays (HyperGame::moveUnits()). */
export function moveUnits(G, from, to, n) {
    const A = G.terr[from];
    const B = G.terr[to];
    if (!A || !B) return;
    let left = Math.min(n, A.pleb + A.maxi + A.asic - 1);

    for (const kind of ['pleb', 'asic', 'maxi']) {
        const m = Math.max(0, Math.min(A[kind], left));
        A[kind] -= m;
        B[kind] += m;
        left -= m;
    }
}

const seatOf = (G, index) => G.seats[index] ?? null;

/**
 * Writes one server event into the table. Unknown events change nothing.
 */
export function applyEvent(G, e) {
    const s = seatOf(G, e.seat);

    switch (e.type) {
        case 'turn_started':
            G.cur = e.seat;
            G.round = e.round ?? G.round;
            G.phase = 'buy';
            G.fortified = false;
            G.pending = null;
            if (s) {
                s.fiat = r2(s.fiat + (e.fiat ?? 0));
                s.sats = r1(s.sats + (e.sats ?? 0));
                s.loot = r1(s.loot + (e.sats ?? 0));
                s.freePlebs += e.free_plebs ?? 0;
                s.conquered = 0;
                s.dip = false;
                s.moves = 0;
            }
            Object.values(G.terr).forEach((t) => { if (t.shield === e.seat) t.shield = null; });
            break;
        case 'loot_gained':
            // Income is in turn_started already; a fallen bank adds its sat here.
            if (s && e.reason === 'bank') {
                s.sats = r1(s.sats + e.amount);
                s.loot = r1(s.loot + e.amount);
            }
            break;
        case 'placed': {
            const t = G.terr[e.territory];
            if (t) t[e.unit] = (t[e.unit] ?? 0) + e.count + (e.bonus ?? 0);
            if (s && e.source === 'free') s.freePlebs = Math.max(0, s.freePlebs - e.count);
            if (s && e.source === 'fiat') s.fiat = r2(s.fiat - e.cost);
            if (s && e.source === 'sats') s.sats = r1(s.sats - e.cost);
            break;
        }
        case 'undone': {
            const t = G.terr[e.territory];
            if (t) t[e.unit] = Math.max(0, (t[e.unit] ?? 0) - e.count);
            break;
        }
        case 'phase_changed':
            G.phase = e.phase;
            break;
        case 'dice_rolled':
            if (G.terr[e.from]) lose(G.terr[e.from], e.attacker_losses ?? 0);
            if (G.terr[e.to]) lose(G.terr[e.to], e.defender_losses ?? 0);
            break;
        case 'territory_conquered': {
            const to = G.terr[e.territory];
            if (!to) break;
            Object.assign(to, { owner: e.seat, pleb: 0, maxi: 0, asic: 0, shield: null });
            moveUnits(G, e.from, e.territory, e.units ?? 1);
            const short = (e.units ?? 1) - units(G, e.territory);
            if (short > 0) {
                to.pleb += short;
                if (G.terr[e.from]) lose(G.terr[e.from], short);
            }
            if (s) s.conquered++;
            break;
        }
        case 'moved':
            moveUnits(G, e.from, e.to, e.count);
            if (s && e.kind === 'fortify') s.moves++;
            break;
        case 'card_played':
            playCard(G, e, s);
            break;
        case 'card_drawn':
            if (s) {
                s.handCount++;
                if (Array.isArray(s.hand) && e.card) s.hand.push(e.card);
            }
            break;
        case 'fiat_interest':
            if (s) s.fiat = r2(s.fiat + e.amount);
            break;
        case 'fiat_decayed':
            if (s) s.fiat = r2(s.fiat - e.amount);
            break;
        case 'player_eliminated': {
            if (s) {
                s.out = true;
                s.handCount = 0;
                if (Array.isArray(s.hand)) s.hand = [];
            }
            const by = seatOf(G, e.by);
            if (by) by.handCount = Math.min(7, by.handCount + (e.cards ?? 0));
            break;
        }
        case 'round_started':
            G.round = e.round;
            G.inflation = !!e.inflation;
            G.inflationNext = false;
            break;
        case 'game_won':
            G.over = true;
            G.winner = e.seat;
            G.byLimit = !!e.by_limit;
            G.pending = null;
            (e.loot ?? []).forEach((amount, index) => { if (G.seats[index]) G.seats[index].loot = amount; });
            break;
        default:
            break;
    }
}

function playCard(G, e, s) {
    if (s) {
        s.handCount = Math.max(0, s.handCount - 1);
        if (Array.isArray(s.hand)) {
            const at = s.hand.indexOf(e.card);
            if (at >= 0) s.hand.splice(at, 1);
        }
    }

    const target = e.target ? G.terr[e.target] : null;
    const effect = e.effect ?? {};

    switch (e.card) {
        case 'brrrr':
            if (s) s.freePlebs += effect.free_plebs ?? 8;
            G.inflationNext = true;
            break;
        case 'attack51':
            // A lone unit takes it without leaving home; with more at hand a territory_conquered follows and overwrites this.
            if (target) Object.assign(target, { owner: e.seat, pleb: 1, maxi: 0, asic: 0, shield: null });
            break;
        case 'keys': {
            const victim = seatOf(G, effect.victim);
            if (victim) victim.sats = r1(victim.sats - (effect.sats_lost ?? 0));
            break;
        }
        case 'diamond':
            if (target) target.shield = e.seat;
            break;
        case 'salvador':
            if (s) s.sats = r1(s.sats * (effect.up ? 1.25 : 0.75));
            break;
        case 'scam':
            if (target) lose(target, effect.units_lost ?? 0);
            break;
        case 'pizza':
            if (s) {
                s.sats = r1(s.sats * 0.9);
                s.freePlebs += effect.free_plebs ?? 3;
            }
            break;
        case 'lagarde':
            if (s) s.fiat = r2(s.fiat + (effect.fiat ?? 3));
            break;
        case 'dip':
            if (s) s.dip = true;
            break;
        case 'nokeys':
            if (target) target.owner = null;
            break;
        default:
            break;
    }
}

/**
 * What the defender adds to its highest die, for the battle screen's table only: bank (not against an
 * ASIC), maxi, the Swiss vault, a complete Rouble or Oceania space, Diamond Hands.
 */
export function defenseBonus(G, id, attackerAsic) {
    const t = G.terr[id];
    if (!t) return 0;
    let bonus = 0;
    if (BANK[id] && !attackerAsic) bonus++;
    if (t.maxi > 0) bonus++;
    if (ZONE_OF[id] === 'swiss') bonus++;
    if (t.owner !== null && ['rubel', 'ozean'].includes(ZONE_OF[id]) && zoneOwner(G, ZONE_OF[id]) === t.owner) bonus++;
    if (t.shield !== null && t.shield !== undefined) bonus++;

    return bonus;
}

/** A Monte Carlo estimate of the attacker's chance to take `to` from `from`, for the chance bar only. */
export function odds(G, from, to, sims = 500, random = Math.random) {
    const asic = (G.terr[from]?.asic ?? 0) > 0;
    const bonus = defenseBonus(G, to, asic);
    const roll = (n) => Array.from({ length: n }, () => 1 + Math.floor(random() * 6)).sort((a, b) => b - a);
    let wins = 0;

    for (let k = 0; k < sims; k++) {
        let au = units(G, from);
        let du = units(G, to);
        while (au > 1 && du > 0) {
            const an = Math.min(3, au - 1);
            const dn = Math.min(2, du);
            const ar = roll(an);
            const dr = roll(dn);
            if (asic) ar[0]++;
            dr[0] += bonus;
            for (let i = 0; i < Math.min(an, dn); i++) {
                if (ar[i] > dr[i]) du--;
                else au--;
            }
        }
        if (du === 0) wins++;
    }

    return wins / sims;
}

export function isNeighbour(a, b) {
    return ADJ[a]?.has(b) ?? false;
}
