"""Export the Pre-Season sample ledger as the golden fixture of the PHP season chain engine.

The ledger generator (docs/plans/2026-09-25T1212-esports-v1-ladder/ledger-src, not in git) is the
oracle: it implements the NIP rev. 5 rules in Python and writes SAMPLE-LEDGER.md. This script imports
it and dumps the inputs of every block candidate plus the oracle's verdicts, blocks, per-player sums,
settlement and estimator figures, and the rating examples.

Usage, from the repository root:

    python3 tests/Fixtures/SeasonChain/export_ledger.py \
        docs/plans/2026-09-25T1212-esports-v1-ladder/ledger-src \
        > tests/Fixtures/SeasonChain/pre-season-ledger.json

Times are exported in UTC (the ledger keeps CEST/CET wall-clock times). Game and weight keys use the
NIP vocabulary: `chess`, `rocket-league`; `chess/blitz` (team-match boards are rated in the blitz
ladder), `chess/correspondence`, `rocket-league/<mode>`.
"""
import json, math, os, sys
from collections import Counter

sys.path.insert(0, os.path.abspath(sys.argv[1]))
os.chdir(sys.argv[1])
import chain as c  # noqa: E402  (runs build.py and model.py)

GAME = {'chess': 'chess', 'Rocket League': 'rocket-league'}
TOKEN = {'Bronze': 'bronze', 'Silver': 'silver', 'Gold': 'gold', 'Platinum': 'platinum', 'Diamond': 'diamond',
         'Champion': 'champion', 'Grand Champion': 'grand-champion'}
ROMAN = {'I': 1, 'II': 2, 'III': 3}


def iso(local):
    return c.utc(local).strftime('%Y-%m-%dT%H:%M:%SZ')


def weight_key(cand):
    return {'blitz': 'chess/blitz', 'board': 'chess/blitz', 'daily': 'chess/correspondence'}.get(cand.kind) or f"rocket-league/{cand.mode}"


def pairing(cand):
    if cand.kind == 'rl':
        return sorted([f"{cand.wside}/{cand.mode}", f"{cand.lside}/{cand.mode}"])
    return sorted(cand.winners + cand.losers)


def anchor(p):
    h = c.home_anchor(p)
    return None if h is None else [h[0], math.floor(h[1] * 100)]   # NIP: floor(100 * share)


def token(name):
    if name == 'Provisional':
        return 'provisional'
    rank, level = name.rsplit(' ', 1)
    return f"{TOKEN[rank]}-{ROMAN[level]}"


ordered = sorted(c.CANDS, key=lambda x: (x.attested, x.num, x.label))
candidates = []
for x in ordered:
    people = sorted(set(x.winners + x.losers + list(x.gate)))
    resolution = 'forfeit' if x.ending == 'forfeit' else ('admin' if x.state == 'admin' else 'confirmed')
    era = c.era_of(x.attested) + 1
    candidates.append({
        'label': x.label,
        'match': x.match_key,
        'listed': x.listed,
        'game': GAME[x.game],
        'weight_key': weight_key(x),
        'attested_at': iso(x.attested),
        'resolution': resolution,
        'moves': x.moves if x.kind != 'rl' else None,
        'winners': x.winners,
        'losers': x.losers,
        'winning_side': x.wside,
        'pairing': pairing(x),
        'gatekeepers': list(x.gate),
        'gatekeepers_connected': c.mutual(*x.gate),
        'trust': {p: c.TRUST[p] for p in x.winners + x.losers},
        'clans': {p: c.CAST[p][3] for p in x.winners + x.losers},
        'anchors': {p: anchor(p) for p in people},
        'expected': {
            'rule': x.rule,
            'height': x.height,
            'era': era,
            'reward_per_player': x.per if x.valid else None,
            'reward': x.reward if x.valid else None,
            'daily_limit_in_force': c.daily_limit(x.game, x.attested),
            'fees_per_player': x.fee_per if x.valid else None,
        },
    })

changes = [{
    'created_at': iso(e['logged']),
    'effective_at': iso(e['effective']),
    'by': e['by'],
    'reason': e['why'],
    'daily': {GAME[e['daily'][0]]: e['daily'][1]},
} for e in c.PARAM_LOG if 'daily' in e]

teams = {t.key: t for t in c.TEAMS}
fees = [{'match': k, 'sats': sum(z for _, z in zs), 'winning_side': teams[k].winner if k in teams else None}
        for k, zs in c.FEES.items() if k not in {m[0] for m in c.MEMPOOL}]

voided = {rc.height: why for rc, why, *_ in c.REVIEW}
settlement = {}
for p, m in c.MINER.items():
    lost = sum(b.per + b.fee_per for b in c.CHAIN if b.height in voided and p in b.winners)
    settlement[p] = m['sats'] + m['fees'] - lost

# estimator: blitz and team boards share the weight chess/blitz and the reward, so they form one stream
fw = c.FORECAST_WEEK
assert c.EST_S1['wins'][0]['blitz'] == c.EST_S1['wins'][0]['board']
STREAMS = {'chess/blitz': ['blitz', 'board'], 'chess/correspondence': ['daily'], 'rocket-league/3v3': ['rl 3v3'], 'rocket-league/2v2': ['rl 2v2']}


def est(e):
    return {
        'milestone_weeks': {str(m): w for m, w in sorted(e['hits'].items())},
        'end_mined': e['end_mined'],
        'per_era': [{'era': k + 1, 'budget': b, 'chess': ch, 'rocket-league': rl} for k, b, ch, rl in e['per_era']],
        'payout_per_week': [e['pay_week'][k] for k in sorted(e['pay_week'])],
        'wins_per_era': [{key: e['wins'][k][kinds[0]] for key, kinds in STREAMS.items()} for k in sorted(e['wins'])],
        'warnings': e['warn'],
    }


def genesis(params, supply_key='supply'):
    return {
        'supply': params['supply'],
        'subsidy': params['base'],
        'weights': {'chess/blitz': params['weight']['blitz'] * 1000, 'chess/correspondence': params['weight']['daily'] * 1000,
                    'rocket-league/3v3': params['weight']['rl'] * 1000, 'rocket-league/2v2': params['weight']['rl'] * 1000,
                    'rocket-league/1v1': params['weight']['rl'] * 1000},
        'shares': {'chess': round(params['cap']['chess'] * 100), 'rocket-league': round(params['cap']['Rocket League'] * 100)},
        'eras': params['eras'],
    }


assert c.WEIGHT['board'] == c.WEIGHT['blitz']
g1 = genesis(c.S1_PARAMS)
g1.update({
    'season': c.SEASON_SLUG,
    'genesis_at': iso(c.BLOCK0),
    'ends_at': c.SEASON_END_UTC.strftime('%Y-%m-%dT%H:%M:%SZ'),
    'halving_seconds': int(c.ERA_LEN.total_seconds()),
    'claim_seconds': int(c.CLAIM_WINDOW.total_seconds()),
    'daily': {'chess': c.DAILY_LIMIT, 'rocket-league': c.DAILY_LIMIT},
    'pairlimit': [1, c.PAIR_SEASON_LIMIT],
    'subtree': c.SUBTREE,
    'moves': c.MOVES,
    'minimum_trust': 50,
})
assert c.SEASON_END_UTC == c.BLOCK0_UTC + g1['eras'] * c.ERA_LEN

# ------------------------------------------------------------------ rating examples
import build as b  # noqa: E402

daily = []
for gid, w, bl, r, fin in sorted(c.DAILY_GAMES, key=lambda g: g[4]):
    o = b.D_OUT[gid]
    daily.append({'id': gid, 'challenger': w, 'challenged': bl, 'score': {'W': 1.0, 'D': 0.5, 'L': 0.0}[r],
                  'challenger_elo': list(o[w]), 'challenged_elo': list(o[bl])})
series = []
for s in sorted([x for x in b.RATED_RL], key=lambda x: x.when):
    series.append({'challenger': f"{s.a}/{s.mode}", 'challenged': f"{s.b}/{s.mode}", 'score': 1.0 if s.winner == s.a else 0.0,
                   'challenger_elo': list(s.elo[s.a]), 'challenged_elo': list(s.elo[s.b])})
tiers = [{'player': p, 'elo': v[0], 'games': v[1] + v[2] + v[3], 'tier': token(b.tier(v[0], v[1] + v[2] + v[3]))} for p, v in b.BLITZ.items()]
ladders = {'chess blitz': b.BLITZ_VALS, 'chess daily': b.DAILY_VALS, 'RL 3v3': b.RL3_VALS, 'RL 2v2': b.RL2_VALS}
global_rating = []
for n, (gr, pbar, parts) in b.GR.items():
    if not parts:
        continue
    global_rating.append({'player': n, 'expected': gr,
                          'entries': [{'ladder': lbl.split(' (')[0], 'weight': w, 'rating': rating} for lbl, w, _, rating, _ in parts]})
clan_rating = []   # emit.CLAN_RATING: rnd(sum / 3) of the top three blitz ratings, none below three players
for t in b.CLANS:
    el = sorted([b.BLITZ[p][0] for p in b.members_of(t) if p in b.BLITZ], reverse=True)
    clan_rating.append({'clan': t, 'elos': el, 'expected': b.rnd(sum(el[:3]) / 3) if len(el) >= 3 else None})

counts = {p: Counter() for p in b.CAST}
for p, v in b.BLITZ.items():
    counts[p].update({'W': v[1], 'D': v[2], 'L': v[3]})
for gid, w, bl, r, fin in b.DAILY_GAMES:
    counts[w][r] += 1
    counts[bl][{'W': 'L', 'L': 'W', 'D': 'D'}[r]] += 1
for s in b.RATED_RL:
    for t, roster in s.rosters.items():
        for p in roster:
            counts[p]['W' if t == s.winner else 'L'] += 1
for p in b.CAST:
    assert 3 * counts[p]['W'] + 2 * counts[p]['D'] + counts[p]['L'] == b.PL[p]['hr'], p
hashrate = []
for t in b.CLANS:
    mem = b.members_of(t)
    hashrate.append({'clan': t, 'wins': sum(counts[p]['W'] for p in mem), 'draws': sum(counts[p]['D'] for p in mem),
                     'losses': sum(counts[p]['L'] for p in mem), 'team_wins': b.CLAN_HR[t]['team_wins'] + b.CLAN_HR[t]['rl_wins'],
                     'expected': b.CLAN_HR[t]['season']})

out = {
    'source': 'docs/plans/2026-09-25T1212-esports-v1-ladder/ledger-src (chain.py, build.py, model.py); SAMPLE-LEDGER.md section 10',
    'genesis': g1,
    'changes': changes,
    'candidates': candidates,
    'totals': {'mined': c.MINED, 'blocks': len(c.CHAIN), 'remaining': c.SUPPLY - c.MINED,
               'verdicts': dict(sorted(Counter('valid' if x.rule is None else str(x.rule) for x in c.CANDS).items()))},
    'miners': {p: {'blocks': m['blocks'], 'sats': m['sats'], 'fees': m['fees']} for p, m in sorted(c.MINER.items())},
    'fees': fees,
    'fees_to_reserve': sum(a for _, a, _ in c.FEE_TO_RESERVE),
    'review': [{'height': h, 'reason': why} for h, why in voided.items()],
    'settlement': {'per_player': dict(sorted(settlement.items())), 'voided': c.REVIEW_VOID,
                   'payouts': sum(settlement.values())},
    'estimator': {
        'now': iso(c.NOW),
        'window_days': 28,
        'forecast_per_week': {key: sum(fw[k] for k in kinds) for key, kinds in STREAMS.items()},
        'pre_season': est(c.EST_S1),
        'example': {'genesis': genesis(c.S2_PARAMS), 'expected': est(c.EST_S2)},
    },
    'rating': {'k': c.K, 'provisional_k': c.KP, 'provisional': c.PROV, 'daily': daily, 'series': series, 'tiers': tiers,
               'ladders': ladders, 'global_rating': global_rating, 'clan_rating': clan_rating,
               'hashrate': {'points': [b.PTS['W'], b.PTS['D'], b.PTS['L'], b.BONUS], 'clans': hashrate}},
}
json.dump(out, sys.stdout, ensure_ascii=False, indent=1)
sys.stdout.write('\n')
