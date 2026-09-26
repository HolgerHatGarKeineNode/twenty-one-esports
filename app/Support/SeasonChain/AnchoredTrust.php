<?php

namespace App\Support\SeasonChain;

/**
 * The trust algorithm `anchored-trust-v1` (docs/nips/esports.md, "Algorithm
 * `anchored-trust-v1`" and "Anchor subtree"), as a pure function of its
 * inputs, so anyone with the anchor list, the opponent lists and the counted
 * reports gets the same ranks:
 *
 * 1. Excluded pubkeys are removed: raw 0, and their lists vouch for nobody.
 * 2. Layers: anchors are layer 0; a pubkey listed by a pubkey of layer h and
 *    in no earlier layer is in layer h+1; layers stop at 3.
 * 3. Raw trust: an anchor has `penalty`; layers 1 to 3 in order
 *    `raw(v) = penalty(v) * min(1, sum over u in layer(v)-1 listing v of
 *    0.5 * raw(u) / |L(u)|)`, |L(u)| the distinct pubkeys in u's list other
 *    than u. `penalty` is 1, halved per counted report (at most two count).
 * 4. Rank: 0 for raw 0, else clamp(round(100 + 25 * log2(8 * raw)), 0, 100),
 *    rounded half away from zero (PHP's round(), NIP "Rating").
 *
 * Anchor shares: an anchor has share 1 of itself; every voucher passes its
 * contribution on split by its own shares; a player's shares are the sums,
 * normalised to 1. The output is the anchor with the largest share (ties:
 * the lower pubkey) and floor(100 * share).
 *
 * Reading of step 1 (the NIP does not say it outright): |L(u)| counts u's
 * list as signed, so excluding a listed pubkey does not strengthen the other
 * entries of the lists that name it.
 */
final class AnchoredTrust
{
    public const LAYERS = 3;

    public const ALPHA = 0.5;

    /** At most this many counted reports halve a player's trust before an admin decides. */
    public const REPORTS_COUNTED = 2;

    /**
     * @param  list<string>  $anchors  pubkeys
     * @param  array<string, list<string>>  $lists  pubkey => the pubkeys of its newest opponent list
     * @param  array<string, int>  $reports  pubkey => counted reports against it
     * @param  list<string>  $excluded  pubkeys an admin excluded
     * @return array<string, array{raw: float, rank: int, layer: int, anchor: array{0: string, 1: int}|null}> every reached pubkey
     */
    public static function compute(array $anchors, array $lists, array $reports = [], array $excluded = []): array
    {
        $excluded = array_fill_keys($excluded, true);
        $listed = [];

        foreach ($lists as $pubkey => $entries) {
            $listed[$pubkey] = array_values(array_diff(array_unique($entries), [$pubkey]));
        }

        $layer = [];
        $raw = [];
        $shares = [];
        $frontier = [];

        foreach (array_unique($anchors) as $anchor) {
            if (isset($excluded[$anchor])) {
                continue;
            }

            $layer[$anchor] = 0;
            $raw[$anchor] = self::penalty($anchor, $reports);
            $shares[$anchor] = [$anchor => 1.0];
            $frontier[] = $anchor;
        }

        for ($h = 1; $h <= self::LAYERS; $h++) {
            /** @var array<string, float> $sums */
            $sums = [];
            /** @var array<string, array<string, float>> $weighted */
            $weighted = [];

            foreach ($frontier as $voucher) {
                $size = count($listed[$voucher] ?? []);

                if ($size === 0 || $raw[$voucher] <= 0.0) {
                    continue;
                }

                $contribution = self::ALPHA * $raw[$voucher] / $size;

                foreach ($listed[$voucher] as $target) {
                    if (isset($layer[$target]) || isset($excluded[$target])) {
                        continue;
                    }

                    $sums[$target] = ($sums[$target] ?? 0.0) + $contribution;

                    foreach ($shares[$voucher] as $anchor => $share) {
                        $weighted[$target][$anchor] = ($weighted[$target][$anchor] ?? 0.0) + $contribution * $share;
                    }
                }
            }

            $frontier = [];

            foreach ($sums as $target => $sum) {
                $layer[$target] = $h;
                $raw[$target] = self::penalty($target, $reports) * min(1.0, $sum);
                $shares[$target] = array_map(fn (float $value): float => $value / $sum, $weighted[$target]);
                $frontier[] = $target;
            }
        }

        $result = [];

        foreach ($raw as $pubkey => $value) {
            $result[$pubkey] = [
                'raw' => $value,
                'rank' => self::rank($value),
                'layer' => $layer[$pubkey],
                'anchor' => $value > 0.0 ? self::largestShare($shares[$pubkey]) : null,
            ];
        }

        return $result;
    }

    /** Step 4: 100 means raw >= 1/8, every 25 points a factor of 2, 0 for raw <= 1/128. */
    public static function rank(float $raw): int
    {
        if ($raw <= 0.0) {
            return 0;
        }

        return (int) max(0, min(100, round(100 + 25 * log(8 * $raw, 2))));
    }

    /**
     * @param  array<string, int>  $reports
     */
    private static function penalty(string $pubkey, array $reports): float
    {
        return 0.5 ** min(self::REPORTS_COUNTED, max(0, $reports[$pubkey] ?? 0));
    }

    /**
     * @param  array<string, float>  $shares
     * @return array{0: string, 1: int}
     */
    private static function largestShare(array $shares): array
    {
        $best = null;

        foreach ($shares as $anchor => $share) {
            $anchor = (string) $anchor;

            if ($best === null || $share > $best[1] || ($share === $best[1] && strcmp($anchor, $best[0]) < 0)) {
                $best = [$anchor, $share];
            }
        }

        return [(string) $best[0], (int) floor(100 * $best[1])];
    }
}
