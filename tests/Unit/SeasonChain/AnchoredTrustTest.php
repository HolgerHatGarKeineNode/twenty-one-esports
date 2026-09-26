<?php

/*
 * `anchored-trust-v1` against the worked examples of docs/nips/esports.md:
 * the table under "Algorithm `anchored-trust-v1`" (measured on constructed
 * graphs), the test bed of "Trust gate and league-wide season 3" (trust runs
 * 1 and 2) and the anchor subtrees of revision 5 (trust run 4).
 */

use App\Support\SeasonChain\AnchoredTrust;

/**
 * @param  array<string, array{raw: float, rank: int, layer: int, anchor: array{0: string, 1: int}|null}>  $result
 */
function trustRank(array $result, string $pubkey): ?int
{
    return $result[$pubkey]['rank'] ?? null;
}

/** @return list<string> */
function fillers(string $prefix, int $count): array
{
    return array_map(fn (int $i): string => $prefix.$i, $count > 0 ? range(1, $count) : []);
}

test('a newcomer listed by one anchor: 1-4 / 8 / 12 / 16 / 17 / 32 entries give rank 100 / 75 / 60 / 50 / 48 / 25', function (int $entries, int $rank) {
    $result = AnchoredTrust::compute(['anchor'], ['anchor' => ['newcomer', ...fillers('f', $entries - 1)]]);

    expect(trustRank($result, 'newcomer'))->toBe($rank);
})->with([
    [1, 100], [2, 100], [3, 100], [4, 100], [8, 75], [12, 60], [16, 50], [17, 48], [32, 25],
]);

test('a newcomer listed by 1 / 2 / 3 / 4 players, each listed by an anchor and with 5 entries, gets 17 / 42 / 57 / 67', function (int $vouchers, int $rank) {
    // "each listed by an anchor (with a list of four)" (NIP "New players"): every voucher has raw 1/8.
    $lists = ['anchor' => ['v1', 'v2', 'v3', 'v4']];

    foreach (range(1, 4) as $i) {
        $lists['v'.$i] = $i <= $vouchers ? ['newcomer', ...fillers("v{$i}-f", 4)] : fillers("v{$i}-f", 5);
    }

    expect(trustRank(AnchoredTrust::compute(['anchor'], $lists), 'newcomer'))->toBe($rank);
})->with([[1, 17], [2, 42], [3, 57], [4, 67]]);

test('a ring of 100 accounts listing each other and five anchors is not reached', function () {
    $anchors = fillers('anchor', 5);
    $ring = fillers('ring', 100);
    $lists = [];

    foreach ($ring as $account) {
        $lists[$account] = [...array_values(array_diff($ring, [$account])), ...$anchors];
    }

    $result = AnchoredTrust::compute($anchors, $lists);

    expect(array_intersect_key($result, array_flip($ring)))->toBe([]);
});

test('the ring with one anchor fooled into listing one account (list of 10): that account 67, the other 99 rank 0', function () {
    $anchors = fillers('anchor', 5);
    $ring = fillers('ring', 100);
    $lists = ['anchor1' => ['ring1', ...fillers('f', 9)]];

    foreach ($ring as $account) {
        $lists[$account] = [...array_values(array_diff($ring, [$account])), ...$anchors];
    }

    $result = AnchoredTrust::compute($anchors, $lists);
    $others = array_map(fn (string $account): ?int => trustRank($result, $account), array_slice($ring, 1));

    expect(trustRank($result, 'ring1'))->toBe(67)
        ->and(array_unique(array_map(fn (?int $rank): int => $rank ?? 0, $others)))->toBe([0]);
});

test('the ring with one player at rank 100 listing one account (list of 5): the highest ring rank is 17', function () {
    $ring = fillers('ring', 100);
    $lists = ['anchor' => ['player', ...fillers('a', 3)], 'player' => ['ring1', ...fillers('p', 4)]];

    foreach ($ring as $account) {
        $lists[$account] = array_values(array_diff($ring, [$account]));
    }

    $result = AnchoredTrust::compute(['anchor'], $lists);

    expect(trustRank($result, 'player'))->toBe(100)
        ->and(max(array_map(fn (string $account): int => trustRank($result, $account) ?? 0, $ring)))->toBe(17);
});

test('a chain of single entries reaches three accounts, never a fourth', function () {
    $result = AnchoredTrust::compute(['anchor'], ['anchor' => ['c1'], 'c1' => ['c2'], 'c2' => ['c3'], 'c3' => ['c4']]);

    expect([trustRank($result, 'c1'), trustRank($result, 'c2'), trustRank($result, 'c3')])->toBe([100, 100, 100])
        ->and($result)->not->toHaveKey('c4');
});

test('one / two counted reports against a player at rank 100 (anchor list of 4) give 75 / 50, and a third does not count', function (int $reports, int $rank) {
    $result = AnchoredTrust::compute(['anchor'], ['anchor' => ['player', ...fillers('f', 3)]], ['player' => $reports]);

    expect(trustRank($result, 'player'))->toBe($rank);
})->with([[0, 100], [1, 75], [2, 50], [3, 50]]);

test('raw trust is capped at 1, so three anchors behind one player do not inflate what it passes on', function () {
    $result = AnchoredTrust::compute(['a1', 'a2', 'a3'], ['a1' => ['x'], 'a2' => ['x'], 'a3' => ['x'], 'x' => ['y', ...fillers('f', 7)]]);

    expect($result['x']['raw'])->toBe(1.0)
        ->and(trustRank($result, 'y'))->toBe(75);
});

test('an excluded pubkey gets no rank and its list vouches for nobody', function () {
    $result = AnchoredTrust::compute(['anchor'], ['anchor' => ['bad', 'good'], 'bad' => ['friend']], excluded: ['bad']);

    expect($result)->not->toHaveKey('bad')
        ->and($result)->not->toHaveKey('friend')
        ->and(trustRank($result, 'good'))->toBe(100);
});

/*
 * The test bed of NIP "Trust gate and league-wide season 3": anchors alice and carol.
 * 11:05 alice lists bob, erin, carol; bob lists alice, dave, frank; carol lists alice, erin, dave;
 * dave, erin and frank list their counterparts; sybil1-3 list each other, sybil1 also alice.
 */
function seasonThreeLists(bool $aliceAddedFrank = false): array
{
    return [
        'alice' => $aliceAddedFrank ? ['bob', 'erin', 'carol', 'frank'] : ['bob', 'erin', 'carol'],
        'bob' => ['alice', 'dave', 'frank'],
        'carol' => ['alice', 'erin', 'dave'],
        'dave' => ['bob', 'carol'],
        'erin' => ['alice', 'carol'],
        'frank' => ['alice', 'bob'],
        'sybil1' => ['sybil2', 'sybil3', 'alice'],
        'sybil2' => ['sybil1', 'sybil3'],
        'sybil3' => ['sybil1', 'sybil2'],
    ];
}

test('season-3 trust run 1: anchors and their direct entries 100, frank 46 one layer further out, the sybils unreached', function () {
    $result = AnchoredTrust::compute(['alice', 'carol'], seasonThreeLists(), ['sybil1' => 1]);

    expect(array_map(fn (string $name): ?int => trustRank($result, $name), ['alice', 'carol', 'bob', 'dave', 'erin', 'frank']))->toBe([100, 100, 100, 100, 100, 46])
        // "raw = 0.5 * (0.5 / 3) / 3 = 0.028"
        ->and(round($result['frank']['raw'], 3))->toBe(0.028)
        ->and($result)->not->toHaveKeys(['sybil1', 'sybil2', 'sybil3']);
});

test('season-3 trust run 2: after alice adds frank he is in layer 1 at rank 100, and only his rank changed', function () {
    $before = AnchoredTrust::compute(['alice', 'carol'], seasonThreeLists());
    $after = AnchoredTrust::compute(['alice', 'carol'], seasonThreeLists(aliceAddedFrank: true));
    $changed = array_keys(array_filter($after, fn (array $row, string $name): bool => $row['rank'] !== ($before[$name]['rank'] ?? null), ARRAY_FILTER_USE_BOTH));

    expect($after['frank']['layer'])->toBe(1)
        ->and(trustRank($after, 'frank'))->toBe(100)
        ->and($changed)->toBe(['frank']);
});

test('trust run 4 anchor subtrees: alice, bob, frank in alice\'s (100); carol, dave in carol\'s (100); erin in carol\'s with 57', function () {
    $result = AnchoredTrust::compute(['alice', 'carol'], seasonThreeLists(aliceAddedFrank: true));

    expect(array_map(fn (string $name): ?array => $result[$name]['anchor'], ['alice', 'bob', 'frank', 'carol', 'dave', 'erin']))->toBe([
        ['alice', 100], ['alice', 100], ['alice', 100], ['carol', 100], ['carol', 100], ['carol', 57],
    ]);
});

test('equal anchor shares go to the lower pubkey', function () {
    $result = AnchoredTrust::compute(['bb', 'aa'], ['aa' => ['player'], 'bb' => ['player']]);

    expect($result['player']['anchor'])->toBe(['aa', 50]);
});

test('the anchor share is floored, not rounded: two thirds is 66', function () {
    $result = AnchoredTrust::compute(['aa', 'bb'], ['aa' => ['player'], 'bb' => ['player', 'other']]);

    expect($result['player']['anchor'])->toBe(['aa', 66]);
});
