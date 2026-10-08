//! Headless balance simulator for Hyperbitcoinization.
//! A faithful port of the browser game's rules and bot logic (index.html: newGame, dealFair,
//! beginTurn, botTurn, battleRound, conquest, playCard, endTurn, checkWin), without rendering.
//! The PHP rules core (app/Support/Hyper) plays the same games for the same seed; `parity` proves it.
//!
//! Usage (release build: `cargo build --release`, at most 8 threads on the workstation):
//!   hbsim <games> <threads>                      the experiment plan below (fair deal, limit 20)
//!   hbsim <games> <threads> gate [limit]          balance gate: value-balanced deal + SEAT_COMP, 2-6 players
//!   hbsim <games> <threads> tune [limit]          grid search for the seat compensation per player count
//!   hbsim <games> <threads> luck|terr [limit]     start luck per side / start advantage per territory
//!   hbsim parity <first-seed> <count> <players> [limit]
//!       one line per game, seeds first-seed .. first-seed+count-1, as the game page sets a game up:
//!       seed players limit winner(-1 = cut at round 200) rounds by_limit board(fnv32) loot(fnv32)
//!   hbsim rng <seed> <count>                      raw generator outputs (test vectors for the PHP port)
//! Map and balance values come from the game page: node export.mjs <index.html> map.json && node maprs.mjs map.json
mod map;
mod values;
use map::*;
use values::{SEAT_COMP_LIMIT, SEAT_COMP_OPEN, T_VALUE};
use std::sync::atomic::{AtomicU64, Ordering};
use std::sync::Arc;
use std::time::Instant;

/* ---------- RNG: xoshiro128++ (32-bit state, so PHP can replay it with masked integer arithmetic) ---------- */
struct Rng([u32; 4]);
impl Rng {
    /// Seeded from a u32 by splitmix32.
    fn new(seed: u32) -> Self {
        let mut z = seed;
        let mut s = [0u32; 4];
        for x in s.iter_mut() {
            z = z.wrapping_add(0x9E3779B9);
            let mut v = z;
            v = (v ^ (v >> 16)).wrapping_mul(0x85EBCA6B);
            v = (v ^ (v >> 13)).wrapping_mul(0xC2B2AE35);
            *x = v ^ (v >> 16);
        }
        Rng(s)
    }
    #[inline]
    fn next(&mut self) -> u32 {
        let s = &mut self.0;
        let r = s[0].wrapping_add(s[3]).rotate_left(7).wrapping_add(s[0]);
        let t = s[1] << 9;
        s[2] ^= s[0];
        s[3] ^= s[1];
        s[1] ^= s[2];
        s[0] ^= s[3];
        s[2] ^= t;
        s[3] = s[3].rotate_left(11);
        r
    }
    /// A float in [0, 1) from the top 24 bits: exact in every double arithmetic.
    #[inline]
    fn f(&mut self) -> f64 { (self.next() >> 8) as f64 / 16_777_216.0 }
    /// 0 .. n-1 by multiply-shift (the product stays below 2^38 for every n the game uses).
    #[inline]
    fn below(&mut self, n: usize) -> usize { ((self.next() as u64 * n as u64) >> 32) as usize }
    #[inline]
    fn die(&mut self) -> i32 { 1 + self.below(6) as i32 }
    fn shuffle<T>(&mut self, a: &mut [T]) {
        for i in (1..a.len()).rev() { let j = self.below(i + 1); a.swap(i, j); }
    }
}

#[inline] fn r1(x: f64) -> f64 { (x * 10.0).round() / 10.0 }
#[inline] fn r2(x: f64) -> f64 { (x * 100.0).round() / 100.0 }

/* ---------- Cards ---------- */
#[derive(Clone, Copy, PartialEq)]
enum Card { Brrrr, Attack51, Keys, Diamond, Salvador, Scam, Pizza, Lagarde, Dip, NoKeys }
const CARDS: [Card; 10] = [Card::Brrrr, Card::Attack51, Card::Keys, Card::Diamond, Card::Salvador, Card::Scam, Card::Pizza, Card::Lagarde, Card::Dip, Card::NoKeys];
const ZONE_DOLLAR: usize = 0; const ZONE_SA: usize = 1; const ZONE_POUND: usize = 2; const ZONE_EURO: usize = 3; const ZONE_SWISS: usize = 4;
const ZONE_RUBEL: usize = 5; const ZONE_YUAN: usize = 6; const ZONE_YEN: usize = 7; const ZONE_AFRO: usize = 8; const ZONE_OZEAN: usize = 9;

#[derive(Clone, Copy, Default)]
struct Terr { owner: i8, pleb: i32, maxi: i32, asic: i32, shield: i8 }

#[derive(Clone, Default)]
struct Player { fiat: f64, sats: f64, loot: f64, hand: Vec<Card>, out: bool, conquered: i32, free: i32, dip: bool, yuan: u32 }

#[derive(Clone, Copy)]
pub struct Cfg { balanced: bool, players: usize, fair: bool, seatcomp: f64, limit: u32, neutral_seats: usize, max_round: u32 }

struct Game { t: [Terr; NT], p: Vec<Player>, cur: usize, round: u32, deck: Vec<Card>, inflation: bool, inflation_next: bool, over: bool, winner: i8, by_limit: bool, cfg: Cfg }

pub struct Outcome { start: [i8; NT], winner: i8, by_limit: bool, rounds: u32, zone_first: [i8; NZ], zone_first_round: [u32; NZ], loot: Vec<f64> }

impl Game {
    #[inline] fn u(&self, i: usize) -> i32 { let t = &self.t[i]; t.pleb + t.maxi + t.asic }
    #[inline] fn own(&self, i: usize) -> i8 { self.t[i].owner }
    fn mine_of(&self, pid: usize) -> impl Iterator<Item = usize> + '_ { (0..NT).filter(move |&i| self.t[i].owner == pid as i8) }
    fn zone_owner(&self, z: usize) -> i8 {
        let mut o: i8 = -2;
        for i in 0..NT { if T_ZONE[i] == z { let w = self.t[i].owner; if o == -2 { o = w; } else if w != o { return -1; } } }
        if o < 0 { -1 } else { o }
    }
    #[inline] fn has_zone(&self, pid: usize, z: usize) -> bool { self.zone_owner(z) == pid as i8 }
    fn banks_of(&self, pid: usize) -> usize { self.mine_of(pid).filter(|&i| T_BANK[i]).count() }
    fn units_of(&self, pid: usize) -> i32 { self.mine_of(pid).map(|i| self.u(i)).sum() }

    fn new_deck(rng: &mut Rng) -> Vec<Card> { let mut d: Vec<Card> = CARDS.iter().flat_map(|&c| [c, c]).collect(); rng.shuffle(&mut d); d }

    fn new(cfg: Cfg, rng: &mut Rng) -> Game {
        let n = cfg.players;
        let terr = if cfg.balanced { Self::deal_balanced(n, cfg.neutral_seats, rng) } else if cfg.fair { Self::deal_fair(n, cfg.neutral_seats, rng) } else { Self::deal_random(n, cfg.neutral_seats, rng) };
        // The deck is shuffled after the start plebs are placed, in the game page's order of draws.
        let mut g = Game { t: terr, p: vec![Player::default(); n], cur: 0, round: 1, deck: Vec::new(), inflation: false, inflation_next: false, over: false, winner: -1, by_limit: false, cfg };
        let seats = n + cfg.neutral_seats;
        for seat in 0..seats {
            // Neutral seats of the duel rule are stored as owner = -(seat+10) during the deal, then turned neutral.
            let tag: i8 = if seat < n { seat as i8 } else { -(seat as i8) - 10 };
            let mut mine: Vec<usize> = (0..NT).filter(|&i| g.t[i].owner == tag).collect();
            if mine.is_empty() { continue; }
            rng.shuffle(&mut mine);
            let extra = 14 + if seat < n { (cfg.seatcomp * seat as f64).round() as usize } else { 0 };
            for k in 0..extra { let id = mine[k % mine.len()]; g.t[id].pleb += 1; }
        }
        for t in g.t.iter_mut() { if t.owner < -5 { t.owner = -1; } }
        g.deck = Self::new_deck(rng);
        g
    }
    fn tag_of(i: usize, n: usize) -> i8 { if i < n { i as i8 } else { -(i as i8) - 10 } }
    fn deal_random(n: usize, neutral: usize, rng: &mut Rng) -> [Terr; NT] {
        let seats = n + neutral;
        let mut ids: Vec<usize> = (0..NT).collect(); rng.shuffle(&mut ids);
        let mut t = [Terr::default(); NT];
        for (k, &id) in ids.iter().enumerate() { t[id] = Terr { owner: Self::tag_of(k % seats, n), pleb: 1, maxi: 0, asic: 0, shield: -1 }; }
        t
    }
    fn deal_fair(n: usize, neutral: usize, rng: &mut Rng) -> [Terr; NT] {
        let seats = n + neutral;
        for _ in 0..200 {
            let mut t = [Terr::default(); NT];
            let mut deal = |filter: &dyn Fn(usize) -> bool, rng: &mut Rng| {
                let mut list: Vec<usize> = (0..NT).filter(|&i| filter(i)).collect(); rng.shuffle(&mut list);
                let even = list.len() - list.len() % seats;
                for (k, &id) in list.iter().enumerate() {
                    t[id] = if k < even { Terr { owner: Self::tag_of(k % seats, n), pleb: 1, maxi: 0, asic: 0, shield: -1 } } else { Terr { owner: -1, pleb: 2, maxi: 0, asic: 0, shield: -1 } };
                }
            };
            deal(&|i| T_BANK[i], rng);
            deal(&|i| T_MINE[i], rng);
            deal(&|i| !T_BANK[i] && !T_MINE[i], rng);
            let complete = (0..NZ).any(|z| {
                let mut o: i8 = -100; let mut ok = true;
                for i in 0..NT { if T_ZONE[i] == z { if o == -100 { o = t[i].owner; } else if t[i].owner != o { ok = false; break; } } }
                ok && o >= 0
            });
            if !complete { return t; }
        }
        Self::deal_random(n, neutral, rng)
    }

    /// Value-balanced deal: per class (banks, mines, plain land) the territories go out in rounds of one
    /// per seat; in each round the strongest remaining goes to the seat with the lowest total so far.
    /// The weakest remainder stays neutral. Ties in value are shuffled.
    fn deal_balanced(n: usize, neutral: usize, rng: &mut Rng) -> [Terr; NT] {
        let seats = n + neutral;
        for _ in 0..200 {
            let mut t = [Terr::default(); NT];
            let mut totals = vec![0.0f64; seats];
            for class in 0..3 {
                // A one-territory currency space (Zuerich) would start complete: it stays neutral.
                let solo = |i: usize| (0..NT).filter(|&j| T_ZONE[j] == T_ZONE[i]).count() == 1;
                if class == 0 { for i in 0..NT { if solo(i) { t[i] = Terr { owner: -1, pleb: 2, maxi: 0, asic: 0, shield: -1 }; } } }
                let mut list: Vec<usize> = (0..NT).filter(|&i| !solo(i) && match class { 0 => T_BANK[i], 1 => T_MINE[i], _ => !T_BANK[i] && !T_MINE[i] }).collect();
                rng.shuffle(&mut list);
                list.sort_by(|&a, &b| T_VALUE[b].partial_cmp(&T_VALUE[a]).unwrap().then(std::cmp::Ordering::Equal));
                let even = list.len() - list.len() % seats;
                for &id in &list[even..] { t[id] = Terr { owner: -1, pleb: 2, maxi: 0, asic: 0, shield: -1 }; }
                for chunk in list[..even].chunks(seats) {
                    let mut order: Vec<usize> = (0..seats).collect(); rng.shuffle(&mut order);
                    order.sort_by(|&a, &b| totals[a].partial_cmp(&totals[b]).unwrap());
                    for (k, &id) in chunk.iter().enumerate() { let seat = order[k]; totals[seat] += T_VALUE[id]; t[id] = Terr { owner: Self::tag_of(seat, n), pleb: 1, maxi: 0, asic: 0, shield: -1 }; }
                }
            }
            let complete = (0..NZ).any(|z| { let mut o: i8 = -100; let mut ok = true; for i in 0..NT { if T_ZONE[i] == z { if o == -100 { o = t[i].owner; } else if t[i].owner != o { ok = false; break; } } } ok && o >= 0 });
            if !complete { return t; }
        }
        // As the game page: after 200 tries every territory goes round-robin to the players, no neutral side.
        Self::deal_random(n, 0, rng)
    }
    fn pleb_cost(&self, pid: usize) -> f64 {
        let mut c = 1.0; if self.inflation { c *= 1.5; } if self.has_zone(pid, ZONE_EURO) { c *= 0.8; } if self.p[pid].dip { c *= 0.5; } r2(c)
    }
    fn def_bonus(&self, id: usize, att_asic: bool) -> i32 {
        let t = &self.t[id]; let mut b = 0;
        if T_BANK[id] && !att_asic { b += 1; }
        if t.maxi > 0 { b += 1; }
        if T_ZONE[id] == ZONE_SWISS { b += 1; }
        if t.owner >= 0 && (T_ZONE[id] == ZONE_RUBEL || T_ZONE[id] == ZONE_OZEAN) && self.has_zone(t.owner as usize, T_ZONE[id]) { b += 1; }
        if t.shield != -1 { b += 1; }
        b
    }
    fn lose(t: &mut Terr, n: i32) { for _ in 0..n { if t.pleb > 0 { t.pleb -= 1 } else if t.asic > 0 { t.asic -= 1 } else if t.maxi > 0 { t.maxi -= 1 } } }

    /// One dice round; returns (attacker dice, won).
    fn battle_round(&mut self, from: usize, to: usize, rng: &mut Rng) -> (i32, bool) {
        let an = (self.u(from) - 1).min(3); let dn = self.u(to).min(2);
        let mut ar = [0i32; 3]; let mut dr = [0i32; 2];
        for k in 0..an as usize { ar[k] = rng.die(); }
        for k in 0..dn as usize { dr[k] = rng.die(); }
        ar[..an as usize].sort_unstable_by(|a, b| b.cmp(a)); dr[..dn as usize].sort_unstable_by(|a, b| b.cmp(a));
        let asic = self.t[from].asic > 0; let bonus = self.def_bonus(to, asic);
        if asic { ar[0] += 1; } dr[0] += bonus;
        let (mut al, mut dl) = (0, 0);
        for k in 0..an.min(dn) as usize { if ar[k] > dr[k] { dl += 1 } else { al += 1 } }
        Self::lose(&mut self.t[from], al); Self::lose(&mut self.t[to], dl);
        (an, self.u(to) == 0)
    }
    fn move_units(&mut self, from: usize, to: usize, n: i32) {
        let mut left = n.min(self.u(from) - 1);
        let m = self.t[from].pleb.min(left); self.t[from].pleb -= m; self.t[to].pleb += m; left -= m;
        let m = self.t[from].asic.min(left); self.t[from].asic -= m; self.t[to].asic += m; left -= m;
        let m = self.t[from].maxi.min(left); self.t[from].maxi -= m; self.t[to].maxi += m;
    }
    fn conquest(&mut self, from: usize, to: usize, prev: i8, mv: i32) {
        let pid = self.cur;
        self.t[to] = Terr { owner: pid as i8, pleb: 0, maxi: 0, asic: 0, shield: -1 };
        self.move_units(from, to, mv.max(1));
        if self.u(to) == 0 { self.t[to].pleb = 1; Self::lose(&mut self.t[from], 1); }
        self.p[pid].conquered += 1;
        if T_BANK[to] { self.p[pid].sats = r1(self.p[pid].sats + 1.0); self.p[pid].loot = r1(self.p[pid].loot + 1.0); }
        if prev >= 0 {
            let v = prev as usize;
            if self.mine_of(v).next().is_none() {
                self.p[v].out = true;
                let h = std::mem::take(&mut self.p[v].hand); self.p[pid].hand.extend(h); self.p[pid].hand.truncate(7);
            }
        }
        self.check_win(false);
    }
    fn standings_winner(&self) -> usize {
        let mut alive: Vec<usize> = (0..self.p.len()).filter(|&i| !self.p[i].out).collect();
        alive.sort_by(|&a, &b| self.banks_of(b).cmp(&self.banks_of(a)).then(self.mine_of(b).count().cmp(&self.mine_of(a).count())).then(self.units_of(b).cmp(&self.units_of(a))));
        alive[0]
    }
    fn check_win(&mut self, fin: bool) -> bool {
        let alive: Vec<usize> = (0..self.p.len()).filter(|&i| !self.p[i].out).collect();
        if alive.len() == 1 { self.over = true; self.winner = alive[0] as i8; return true; }
        if fin && alive.len() > 1 { self.over = true; self.winner = self.standings_winner() as i8; self.by_limit = true; return true; }
        false
    }

    fn begin_turn(&mut self) {
        let pid = self.cur;
        { let p = &mut self.p[pid]; p.conquered = 0; p.dip = false; }
        for t in self.t.iter_mut() { if t.shield == pid as i8 { t.shield = -1; } }
        let mine: Vec<usize> = self.mine_of(pid).collect();
        let mut fiat = ((mine.len() / 3).max(3) + 2 * mine.iter().filter(|&&i| T_BANK[i]).count()) as f64;
        if self.has_zone(pid, ZONE_DOLLAR) { fiat = (fiat * 1.25).round(); }
        if self.has_zone(pid, ZONE_SA) { fiat += 2.0; }
        let mut sats = 0.5 * mine.iter().filter(|&&i| T_MINE[i]).count() as f64;
        for z in 0..NZ { if self.has_zone(pid, z) { sats += ZONE_SATS[z]; } }
        let afro = self.has_zone(pid, ZONE_AFRO);
        let p = &mut self.p[pid];
        let growth = p.sats * 0.03;
        p.fiat = r2(p.fiat + fiat); p.sats = r1(p.sats + sats + growth); p.loot = r1(p.loot + sats + growth);
        if afro { p.free += 2; }
        self.check_win(false);
    }

    fn card_target_ok(&self, c: Card, id: usize) -> bool {
        let pid = self.cur as i8; let o = self.own(id);
        match c {
            Card::Attack51 => o != pid && self.u(id) <= 3 && ADJ[id].iter().any(|&n| self.own(n) == pid),
            Card::Diamond => o == pid,
            Card::Scam => o != pid && o >= 0 && T_BANK[id],
            Card::NoKeys => o != pid && o >= 0 && self.u(id) == 1,
            _ => false,
        }
    }
    fn play_card(&mut self, c: Card, target: Option<usize>, rng: &mut Rng) {
        let pid = self.cur;
        if let Some(i) = self.p[pid].hand.iter().position(|&x| x == c) { self.p[pid].hand.remove(i); } else { return; }
        let fate = rng.f() < 0.5;
        match c {
            Card::Brrrr => { self.p[pid].free += 8; self.inflation_next = true; }
            Card::Attack51 => {
                let tg = target.unwrap(); let prev = self.own(tg);
                let mut src: Option<usize> = None;
                for &n in ADJ[tg] { if self.own(n) == pid as i8 && src.map_or(true, |s| self.u(n) > self.u(s)) { src = Some(n); } }
                self.t[tg].pleb = 0; self.t[tg].maxi = 0; self.t[tg].asic = 0;
                let s = src.unwrap();
                if self.u(s) > 1 { self.conquest(s, tg, prev, 1); } else { self.t[tg] = Terr { owner: pid as i8, pleb: 1, maxi: 0, asic: 0, shield: -1 }; self.p[pid].conquered += 1; }
            }
            Card::Keys => {
                let mut rich: Option<usize> = None;
                for i in 0..self.p.len() { if i != pid && !self.p[i].out && rich.map_or(true, |r| self.p[i].sats > self.p[r].sats) { rich = Some(i); } }
                if let Some(r) = rich { let l = r1(self.p[r].sats * 0.3); self.p[r].sats = r1(self.p[r].sats - l); }
            }
            Card::Diamond => { self.t[target.unwrap()].shield = pid as i8; }
            Card::Salvador => { let p = &mut self.p[pid]; p.sats = r1(p.sats * if fate { 1.25 } else { 0.75 }); }
            Card::Scam => { let tg = target.unwrap(); let k = 2.min(self.u(tg) - 1); Self::lose(&mut self.t[tg], k); }
            Card::Pizza => { let p = &mut self.p[pid]; p.sats = r1(p.sats * 0.9); p.free += 3; }
            Card::Lagarde => { self.p[pid].fiat += 3.0; if self.deck.is_empty() { self.deck = Self::new_deck(rng); } }
            Card::Dip => { self.p[pid].dip = true; }
            Card::NoKeys => {
                let tg = target.unwrap(); let prev = self.own(tg) as usize; self.t[tg].owner = -1;
                if self.mine_of(prev).next().is_none() { self.p[prev].out = true; }
            }
        }
        self.check_win(false);
    }

    fn bot_turn(&mut self, rng: &mut Rng) {
        let pid = self.cur;
        let hand = self.p[pid].hand.clone();
        for c in hand {
            if self.over { return; }
            let target = match c {
                Card::Attack51 | Card::NoKeys => (0..NT).find(|&i| self.card_target_ok(c, i)),
                Card::Scam => (0..NT).find(|&i| self.card_target_ok(c, i) && self.u(i) > 2),
                Card::Diamond => self.mine_of(pid).find(|&i| T_BANK[i]),
                _ => None,
            };
            let needs = matches!(c, Card::Attack51 | Card::NoKeys | Card::Scam | Card::Diamond);
            if needs && target.is_none() { continue; }
            if (c == Card::Salvador && self.p[pid].sats > 8.0) || (c == Card::Pizza && self.p[pid].sats > 12.0) { continue; }
            self.play_card(c, target, rng);
        }
        if self.over { return; }
        // Goal: the currency space with the largest own share (then the richest).
        let share = |g: &Game, z: usize| { let (mut a, mut b) = (0, 0); for i in 0..NT { if T_ZONE[i] == z { b += 1; if g.own(i) == pid as i8 { a += 1; } } } a as f64 / b as f64 };
        let mut goal: Option<usize> = None;
        for z in 0..NZ { if self.has_zone(pid, z) { continue; } goal = match goal { None => Some(z), Some(gz) => { let (sa, sb) = (share(self, z), share(self, gz)); if sa > sb || (sa == sb && ZONE_SATS[z] > ZONE_SATS[gz]) { Some(z) } else { Some(gz) } } }; }
        let gz = goal.unwrap_or(usize::MAX);
        let mut front: Option<usize> = None;
        for i in self.mine_of(pid) {
            if !ADJ[i].iter().any(|&n| self.own(n) != pid as i8) { continue; }
            front = match front { None => Some(i), Some(f) => { let (ga, gb) = ((T_ZONE[i] == gz) as i32, (T_ZONE[f] == gz) as i32); if ga > gb || (ga == gb && self.u(i) > self.u(f)) { Some(i) } else { Some(f) } } };
        }
        let front = match front.or_else(|| self.mine_of(pid).next()) { Some(f) => f, None => { self.end_turn(rng); return; } };
        if self.p[pid].sats >= 3.0 { self.p[pid].sats = r1(self.p[pid].sats - 3.0); self.t[front].asic += 1; }
        let free = self.p[pid].free; self.t[front].pleb += free; self.p[pid].free = 0;
        let mut guard = 80;
        // Plebs one at a time through the same purchase a human makes, so the Yuan bonus (every 3rd pleb free)
        // counts for bots too. The game page's bot bought around placeOn() and never got it.
        let yuan = self.has_zone(pid, ZONE_YUAN);
        loop {
            let c = self.pleb_cost(pid); if !(self.p[pid].fiat + 1e-9 >= c) || guard == 0 { break; } guard -= 1;
            self.p[pid].fiat = r2(self.p[pid].fiat - c); self.t[front].pleb += 1;
            if yuan { self.p[pid].yuan += 1; if self.p[pid].yuan % 3 == 0 { self.t[front].pleb += 1; } }
        }
        // Attacks: best scored option, fight until won or the odds turn.
        for _ in 0..14 {
            if self.over { return; }
            let mut best: Option<(f64, usize, usize)> = None;
            for from in 0..NT {
                if self.own(from) != pid as i8 || self.u(from) < 3 { continue; }
                let asic = self.t[from].asic > 0;
                for &to in ADJ[from] {
                    if self.own(to) == pid as i8 { continue; }
                    let score = self.u(from) as f64 - self.u(to) as f64 * 1.4 - self.def_bonus(to, asic) as f64 + if T_ZONE[to] == gz { 2.0 } else { 0.0 } + if T_BANK[to] { 1.5 } else { 0.0 };
                    if score > 1.0 && best.map_or(true, |b| score > b.0) { best = Some((score, from, to)); }
                }
            }
            let Some((_, from, to)) = best else { break };
            let prev = self.own(to);
            let (mut an, mut won);
            loop { let r = self.battle_round(from, to, rng); an = r.0; won = r.1; if won || !(self.u(from) > 2) || !(self.u(from) > self.u(to)) { break; } }
            if won {
                // As a human conquers: the dice move in, then the rest of the bot's 80 % if the game goes on
                // (the page's bot moved all at once; only the last board of a finished game differs).
                let mv = an.max(((self.u(from) - 1) as f64 * 0.8).floor() as i32);
                self.conquest(from, to, prev, an);
                if !self.over && mv > an { self.move_units(from, to, mv - an); }
            }
        }
        if self.over { return; }
        // Fortify: the biggest inner stack moves to the front.
        let mut inner: Option<usize> = None;
        for i in self.mine_of(pid) { if self.u(i) > 1 && ADJ[i].iter().all(|&n| self.own(n) == pid as i8) && inner.map_or(true, |b| self.u(i) > self.u(b)) { inner = Some(i); } }
        if let Some(i) = inner {
            let to = ADJ[i].iter().copied().find(|&n| ADJ[n].iter().any(|&m| self.own(m) != pid as i8)).unwrap_or(ADJ[i][0]);
            let k = self.u(i) - 1; self.move_units(i, to, k);
        }
        self.end_turn(rng);
    }

    fn end_turn(&mut self, rng: &mut Rng) {
        let pid = self.cur;
        if self.p[pid].fiat > 0.0 {
            if self.has_zone(pid, ZONE_POUND) { self.p[pid].fiat = r2(self.p[pid].fiat * 1.1); }
            else if !self.has_zone(pid, ZONE_YEN) { let l = r2(self.p[pid].fiat * 0.15); self.p[pid].fiat = r2(self.p[pid].fiat - l); }
        }
        if self.p[pid].conquered > 0 && self.p[pid].hand.len() < 5 { if self.deck.is_empty() { self.deck = Self::new_deck(rng); } let c = self.deck.remove(0); self.p[pid].hand.push(c); }
        let mut next = self.cur;
        loop { next = (next + 1) % self.p.len(); if !self.p[next].out { break; } }
        if next <= self.cur && self.cfg.limit > 0 && self.round >= self.cfg.limit && self.check_win(true) { return; }
        if next <= self.cur { self.round += 1; self.inflation = self.inflation_next; self.inflation_next = false; }
        self.cur = next;
    }

    fn play(mut self, rng: &mut Rng) -> Outcome {
        let mut zf = [-1i8; NZ]; let mut zr = [0u32; NZ];
        let mut start = [-1i8; NT]; for i in 0..NT { start[i] = self.t[i].owner; }
        self.begin_turn();
        while !self.over && self.round <= self.cfg.max_round {
            self.bot_turn(rng);
            for z in 0..NZ { if zf[z] < 0 { let o = self.zone_owner(z); if o >= 0 { zf[z] = o; zr[z] = self.round; } } }
            if self.over { break; }
            self.begin_turn();
        }
        Outcome { start, winner: if self.over { self.winner } else { -1 }, by_limit: self.by_limit, rounds: self.round, zone_first: zf, zone_first_round: zr, loot: self.p.iter().map(|p| p.loot).collect() }
    }
}

/* ---------- Experiments ---------- */
struct Stats { luck: [[u64; 2]; 4], t_own: [u64; NT], t_win: [u64; NT], n: u64, seat_wins: Vec<u64>, timeouts: u64, by_limit: u64, rounds: u64, zone_done: [u64; NZ], zone_round: [u64; NZ], zone_conv: [u64; NZ], loot: Vec<f64> }
impl Stats {
    fn new(players: usize) -> Self { Stats { luck: [[0; 2]; 4], t_own: [0; NT], t_win: [0; NT], n: 0, seat_wins: vec![0; players], timeouts: 0, by_limit: 0, rounds: 0, zone_done: [0; NZ], zone_round: [0; NZ], zone_conv: [0; NZ], loot: vec![0.0; players] } }
    fn add(&mut self, o: &Outcome) {
        self.n += 1; self.rounds += o.rounds as u64;
        if o.winner < 0 { self.timeouts += 1; } else { self.seat_wins[o.winner as usize] += 1; }
        if o.by_limit { self.by_limit += 1; }
        for z in 0..NZ { if o.zone_first[z] >= 0 { self.zone_done[z] += 1; self.zone_round[z] += o.zone_first_round[z] as u64; if o.zone_first[z] == o.winner { self.zone_conv[z] += 1; } } }
        for (i, l) in o.loot.iter().enumerate() { self.loot[i] += l; }
        // Start luck: each side's summed start value, bucketed into quartiles (−∞,−3), [−3,0), [0,3), [3,∞).
        let np = o.loot.len();
        for pid in 0..np { let v: f64 = (0..NT).filter(|&i| o.start[i] == pid as i8).map(|i| T_VALUE[i]).sum(); let b = if v < -3.0 { 0 } else if v < 0.0 { 1 } else if v < 3.0 { 2 } else { 3 }; self.luck[b][0] += 1; if o.winner == pid as i8 { self.luck[b][1] += 1; } }
        for i in 0..NT { if o.start[i] >= 0 { self.t_own[i] += 1; if o.start[i] == o.winner { self.t_win[i] += 1; } } }
    }
    fn merge(&mut self, b: &Stats) {
        for q in 0..4 { self.luck[q][0] += b.luck[q][0]; self.luck[q][1] += b.luck[q][1]; }
        for i in 0..NT { self.t_own[i] += b.t_own[i]; self.t_win[i] += b.t_win[i]; }
        self.n += b.n; self.timeouts += b.timeouts; self.by_limit += b.by_limit; self.rounds += b.rounds;
        for i in 0..self.seat_wins.len() { self.seat_wins[i] += b.seat_wins[i]; self.loot[i] += b.loot[i]; }
        for z in 0..NZ { self.zone_done[z] += b.zone_done[z]; self.zone_round[z] += b.zone_round[z]; self.zone_conv[z] += b.zone_conv[z]; }
    }
}

fn run(cfg: Cfg, games: u64, threads: usize, seed: u64) -> (Stats, f64) {
    let t0 = Instant::now();
    let counter = Arc::new(AtomicU64::new(0));
    let handles: Vec<_> = (0..threads).map(|k| {
        let counter = counter.clone();
        std::thread::spawn(move || {
            let mut rng = Rng::new((seed ^ (k as u64 + 1).wrapping_mul(0xA24BAED4963EE407) >> 16) as u32);
            let mut st = Stats::new(cfg.players);
            while counter.fetch_add(1, Ordering::Relaxed) < games { let g = Game::new(cfg, &mut rng); st.add(&g.play(&mut rng)); }
            st
        })
    }).collect();
    let mut total = Stats::new(cfg.players);
    for h in handles { total.merge(&h.join().unwrap()); }
    (total, t0.elapsed().as_secs_f64())
}

fn report(label: &str, cfg: Cfg, st: &Stats, secs: f64, zones: bool) {
    let n = st.n as f64;
    let fair = 1.0 / cfg.players as f64;
    let seats: Vec<String> = st.seat_wins.iter().map(|&w| format!("{:.1}", w as f64 / n * 100.0)).collect();
    let ci = 1.96 * (fair * (1.0 - fair) / n).sqrt() * 100.0;
    let worst = st.seat_wins.iter().map(|&w| ((w as f64 / n) - fair).abs() * 100.0).fold(0.0, f64::max);
    println!("{label:<34} n={:>8} {:>6.0}k games/s  seats% [{}]  fair {:.1}±{:.2}  worstΔ {:.1}pp  rounds {:.1}  limit {:.0}%  timeout {:.1}%",
        st.n, n / secs / 1000.0, seats.join(" "), fair * 100.0, ci, worst, st.rounds as f64 / n, st.by_limit as f64 / n * 100.0, st.timeouts as f64 / n * 100.0);
    if zones {
        for z in 0..NZ {
            let d = st.zone_done[z] as f64;
            println!("    zone {:<7} sats {:>3.1}  completed {:>5.1}%  first in round {:>5.1}  first holder wins {:>5.1}%", ZONE_KEYS[z], ZONE_SATS[z], d / n * 100.0, if d > 0.0 { st.zone_round[z] as f64 / d } else { 0.0 }, if d > 0.0 { st.zone_conv[z] as f64 / d * 100.0 } else { 0.0 });
        }
        let loot: Vec<String> = st.loot.iter().map(|l| format!("{:.1}", l / n)).collect();
        println!("    mean loot per seat (Mio. Sats): [{}]", loot.join(" "));
    }
}

/// A game as the game page sets it up: value-balanced deal, a neutral side in the duel, SEAT_COMP by limit.
fn page_cfg(players: usize, limit: u32) -> Cfg {
    let table = if limit > 0 { SEAT_COMP_LIMIT } else { SEAT_COMP_OPEN };
    Cfg { balanced: true, players, fair: true, seatcomp: table[players - 2], limit, neutral_seats: if players == 2 { 1 } else { 0 }, max_round: 200 }
}

/// FNV-1a over the bytes of a string: a short fingerprint both cores compute the same way.
fn fnv32(s: &str) -> u32 {
    let mut h: u32 = 0x811C9DC5;
    for b in s.bytes() { h ^= b as u32; h = h.wrapping_mul(0x0100_0193); }
    h
}

/// One line per seed, compared line by line with the PHP core (tests/Unit/Hyper/ParityTest.php).
fn parity(first: u32, count: u32, players: usize, limit: u32) {
    for k in 0..count {
        let seed = first.wrapping_add(k);
        let mut rng = Rng::new(seed);
        let mut g = Game::new(page_cfg(players, limit), &mut rng);
        g.begin_turn();
        while !g.over && g.round <= g.cfg.max_round { g.bot_turn(&mut rng); if g.over { break; } g.begin_turn(); }
        let board: String = g.t.iter().map(|t| format!("{},{},{},{};", t.owner, t.pleb, t.maxi, t.asic)).collect();
        let loot: String = g.p.iter().map(|p| format!("{};", (p.loot * 10.0).round() as i64)).collect();
        println!("{} {} {} {} {} {} {:08x} {:08x}", seed, players, limit, if g.over { g.winner } else { -1 }, g.round, g.by_limit as u8, fnv32(&board), fnv32(&loot));
    }
}

fn main() {
    let args: Vec<String> = std::env::args().collect();
    if args.get(1).map(|s| s.as_str()) == Some("parity") {
        let num = |i: usize, d: u32| args.get(i).and_then(|s| s.parse().ok()).unwrap_or(d);
        let players = num(4, 4) as usize;
        if !(2..=6).contains(&players) { eprintln!("players must be 2..6"); std::process::exit(2); }
        parity(num(2, 1), num(3, 25), players, num(5, 0));
        return;
    }
    if args.get(1).map(|s| s.as_str()) == Some("rng") {
        // Raw generator outputs for a seed: the PHP generator's test vectors.
        let seed: u32 = args.get(2).and_then(|s| s.parse().ok()).unwrap_or(1);
        let count: u32 = args.get(3).and_then(|s| s.parse().ok()).unwrap_or(8);
        let mut rng = Rng::new(seed);
        let out: Vec<String> = (0..count).map(|_| rng.next().to_string()).collect();
        println!("{}", out.join(" "));
        return;
    }
    let games: u64 = args.get(1).and_then(|s| s.parse().ok()).unwrap_or(100_000);
    let threads: usize = args.get(2).and_then(|s| s.parse().ok()).unwrap_or(8);
    let limit: u32 = args.get(4).and_then(|s| s.parse().ok()).unwrap_or(20);
    let base = Cfg { balanced: false, players: 4, fair: true, seatcomp: 0.0, limit, neutral_seats: 0, max_round: 200 };
    if args.get(3).map(|s| s.as_str()) == Some("gate") {
        // The balance gate: every seat within 2 pp of the fair share, without a limit (the default) and with 20.
        let limits: Vec<u32> = match args.get(4).and_then(|s| s.parse().ok()) { Some(l) => vec![l], None => vec![0, 20] };
        for l in limits {
            for players in 2..=6usize {
                let cfg = page_cfg(players, l);
                let (st, secs) = run(cfg, games, threads, 0x6A7E + players as u64 * 31 + l as u64);
                report(&format!("{players}P limit {l} comp +{:.1}/seat{}", cfg.seatcomp, if players == 2 { " +neutral" } else { "" }), cfg, &st, secs, false);
            }
        }
        return;
    }
    if args.get(3).map(|s| s.as_str()) == Some("luck") {
        for (players, comp) in [(4usize, 4.0), (6, 1.0)] {
            for balanced in [false, true] {
                let cfg = Cfg { players, seatcomp: comp, balanced, ..base };
                let (st, secs) = run(cfg, games, threads, 0x1C4 + players as u64 + balanced as u64);
                report(&format!("{players}P {} deal", if balanced { "value-balanced" } else { "fair" }), cfg, &st, secs, false);
                let names = ["start value < -3", "-3 .. 0", "0 .. +3", "> +3"];
                for q in 0..4 { let (g, w) = (st.luck[q][0], st.luck[q][1]); if g > 0 { println!("    {:<17} share {:>5.1}% of sides, win rate {:>5.1}%", names[q], g as f64 / (st.n as f64 * players as f64) * 100.0, w as f64 / g as f64 * 100.0); } }
            }
        }
        return;
    }
    if args.get(3).map(|s| s.as_str()) == Some("terr") {
        // Start advantage per territory: how much more often the side that starts with it wins.
        for (players, comp) in [(4usize, 4.0), (6, 1.0)] {
            let cfg = Cfg { players, seatcomp: comp, ..base };
            let (st, secs) = run(cfg, games, threads, 0x7E44 + players as u64);
            report(&format!("{players}P territory start advantage"), cfg, &st, secs, false);
            let fair = 1.0 / players as f64;
            let mut rows: Vec<(f64, usize)> = (0..NT).filter(|&i| st.t_own[i] > 0).map(|i| ((st.t_win[i] as f64 / st.t_own[i] as f64 - fair) * 100.0, i)).collect();
            rows.sort_by(|a, b| b.0.partial_cmp(&a.0).unwrap());
            let ci = |i: usize| 1.96 * (fair * (1.0 - fair) / st.t_own[i] as f64).sqrt() * 100.0;
            for (d, i) in rows.iter() { println!("    {:<13} zone {:<7} nb {:>2} bank {:<5} mine {:<5} start-holder wins {:+5.2} pp (±{:.2}, n={})", T_ID[*i], ZONE_KEYS[T_ZONE[*i]], ADJ[*i].len(), T_BANK[*i], T_MINE[*i], d, ci(*i), st.t_own[*i]); }
        }
        return;
    }
    if args.get(3).map(|s| s.as_str()) == Some("tune") {
        // Grid search: the seat compensation per player count that minimises the worst seat's deviation.
        for (players, neutral) in [(2usize, 1usize), (3, 0), (4, 0), (5, 0), (6, 0)] {
            let mut best = (f64::MAX, 0.0);
            for step in 0..=24 {
                let comp = step as f64 * 0.5;
                let cfg = Cfg { players, neutral_seats: neutral, seatcomp: comp, balanced: true, ..base };
                let (st, _) = run(cfg, games, threads, 0xC0FFEE + step as u64 * 7 + players as u64);
                let fair = 1.0 / players as f64;
                let worst = st.seat_wins.iter().map(|&w| ((w as f64 / st.n as f64) - fair).abs() * 100.0).fold(0.0, f64::max);
                if worst < best.0 { best = (worst, comp); }
            }
            let cfg = Cfg { players, neutral_seats: neutral, seatcomp: best.1, balanced: true, ..base };
            let (st, secs) = run(cfg, games * 4, threads, 0xBEEF + players as u64);
            report(&format!("{players}P best +{:.1}/seat{}", best.1, if neutral > 0 { " +neutral" } else { "" }), cfg, &st, secs, false);
        }
        return;
    }
    let plan: Vec<(&str, Cfg, bool)> = vec![
        ("4P random deal", Cfg { fair: false, ..base }, false),
        ("4P fair deal", base, true),
        ("4P fair +1 pleb/seat", Cfg { seatcomp: 1.0, ..base }, false),
        ("4P fair +2 plebs/seat", Cfg { seatcomp: 2.0, ..base }, false),
        ("4P fair +3 plebs/seat", Cfg { seatcomp: 3.0, ..base }, false),
        ("3P fair", Cfg { players: 3, ..base }, false),
        ("5P fair", Cfg { players: 5, ..base }, false),
        ("6P fair", Cfg { players: 6, ..base }, false),
        ("2P fair", Cfg { players: 2, ..base }, false),
        ("2P fair + 1 neutral army", Cfg { players: 2, neutral_seats: 1, ..base }, false),
        ("2P fair + 1 neutral +3/seat", Cfg { players: 2, neutral_seats: 1, seatcomp: 3.0, ..base }, false),
        ("4P fair, no round limit", Cfg { limit: 0, ..base }, false),
    ];
    for (label, cfg, zones) in plan {
        let (st, secs) = run(cfg, games, threads, 0x5EED ^ label.len() as u64);
        report(label, cfg, &st, secs, zones);
    }
}
