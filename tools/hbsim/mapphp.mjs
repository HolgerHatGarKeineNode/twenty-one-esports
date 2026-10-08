// Writes the PHP rules core's map class from map.json (see export.mjs):
// node mapphp.mjs map.json ../../app/Support/Hyper/HyperMap.php
import fs from 'node:fs';
const d = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
const str = (s) => `'${String(s).replace(/\\/g, '\\\\').replace(/'/g, "\\'")}'`;
const float = (v) => (Number.isInteger(v) ? v.toFixed(1) : String(v));
const list = (a, f = String) => `[${a.map(f).join(', ')}]`;
const zoneTerr = d.zones.map((_, z) => d.terr.map((t, i) => (t.zone === z ? i : -1)).filter((i) => i >= 0));
const comp = (a, seats = [2, 3, 4, 5, 6]) => `[${a.map((v, k) => `${seats[k]} => ${float(v)}`).join(', ')}]`;
const php = `<?php

namespace App\\Support\\Hyper;

/**
 * The board of Hyperbitcoinization: 53 territories in 10 currency spaces, generated from the game page
 * (resources/js/hyper/prototype/index.html) with tools/hbsim/balance.json, so the PHP core and the Rust
 * simulator play the same map. Territories are indexed 0..52 in the page's order; neighbours keep the
 * page's order too, because the bots pick the first of equals. Do not edit; regenerate:
 *
 *   cd tools/hbsim && node export.mjs ../../resources/js/hyper/prototype/index.html map.json \\
 *     && node maprs.mjs map.json && node mapphp.mjs map.json ../../app/Support/Hyper/HyperMap.php
 */
final class HyperMap
{
    public const int COUNT = ${d.terr.length};

    /** @var list<string> */
    public const array IDS = ${list(d.terr.map((t) => t.id), str)};

    /** @var list<string> */
    public const array NAMES = ${list(d.terr.map((t) => t.name), str)};

    /** @var list<int> zone index per territory */
    public const array ZONE = ${list(d.terr.map((t) => t.zone))};

    /** @var list<bool> */
    public const array BANK = ${list(d.terr.map((t) => t.bank))};

    /** @var list<bool> */
    public const array MINE = ${list(d.terr.map((t) => t.mine))};

    /** @var list<list<int>> */
    public const array ADJ = [${d.terr.map((t) => list(t.adj)).join(', ')}];

    /** @var list<float> start value per territory (pp of win rate, 4 players), sorts the value-balanced deal */
    public const array START_VALUE = ${list(d.terr.map((t) => t.value), float)};

    /** @var list<string> */
    public const array ZONE_KEYS = ${list(d.zones.map((z) => z.key), str)};

    /** @var list<string> */
    public const array ZONE_NAMES = ${list(d.zones.map((z) => z.name), str)};

    /** @var list<string> */
    public const array ZONE_BANKS = ${list(d.zones.map((z) => z.bank), str)};

    /** @var list<float> sats per turn for holding the whole currency space */
    public const array ZONE_SATS = ${list(d.zones.map((z) => z.sats), float)};

    /** @var list<list<int>> territories per zone */
    public const array ZONE_TERRITORIES = [${zoneTerr.map((a) => list(a)).join(', ')}];

    /** @var array{open: array<int, float>, limit: array<int, float>} extra start plebs per later seat, by player count */
    public const array SEAT_COMP = ['open' => ${comp(d.seatComp.open)}, 'limit' => ${comp(d.seatComp.limit)}];

    /** @var array{open: array<int, float>, limit: array<int, float>} the same for two teams seated alternately: 4 seats (2v2), 6 seats (3v3) */
    public const array TEAM_SEAT_COMP = ['open' => ${comp(d.teamComp.open, [4, 6])}, 'limit' => ${comp(d.teamComp.limit, [4, 6])}];
}
`;
fs.writeFileSync(process.argv[3], php);
console.log('HyperMap.php ok');
