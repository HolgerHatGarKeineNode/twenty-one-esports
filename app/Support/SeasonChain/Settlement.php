<?php

namespace App\Support\SeasonChain;

/**
 * What each player is owed at season end (docs/nips/esports.md, "Fees",
 * "Review and corrections", "Payout"): the rewards of their blocks, their
 * share of the fees, minus what the review voided. Fees of a challenge go to
 * the blocks of that challenge won by the side that won the match, in equal
 * parts per block and within a block per winning player, rounded down; a
 * challenge without such a block, the remainders and voided blocks' rewards
 * and fees go to the league reserve.
 */
final class Settlement
{
    /**
     * @param  list<array{match: string, sats: int, winning_side: ?string}>  $fees  zap receipts per challenge that count for the match; winning_side = the side that won a team match, null for a solo game or series
     * @return array{players: array<string, array{blocks: int, subsidy: int, fees: int, voided: int, payout: int}>, fees_per_block: array<int, int>, reserve: array{fees_without_block: int, fee_remainders: int, voided: int}}
     */
    public static function compute(BlockChain $chain, array $fees): array
    {
        $players = [];
        $feePerPlayer = [];
        $reserve = ['fees_without_block' => 0, 'fee_remainders' => 0, 'voided' => 0];

        foreach ($fees as $receipt) {
            $blocks = array_values(array_filter($chain->blocks(), fn (Block $block): bool => $block->candidate->match === $receipt['match']
                && ($receipt['winning_side'] === null || $block->candidate->winningSide === $receipt['winning_side'])));

            if ($blocks === []) {
                $reserve['fees_without_block'] += $receipt['sats'];

                continue;
            }

            $perBlock = intdiv($receipt['sats'], count($blocks));
            $reserve['fee_remainders'] += $receipt['sats'] - $perBlock * count($blocks);

            foreach ($blocks as $block) {
                $each = intdiv($perBlock, max(1, count($block->candidate->winners)));
                $feePerPlayer[$block->height] = ($feePerPlayer[$block->height] ?? 0) + $each;
                $reserve['fee_remainders'] += $perBlock - $each * count($block->candidate->winners);
            }
        }

        foreach ($chain->blocks() as $block) {
            $fee = $feePerPlayer[$block->height] ?? 0;
            $voided = $chain->isVoided($block->height);

            foreach ($block->candidate->winners as $player) {
                $row = $players[$player] ?? ['blocks' => 0, 'subsidy' => 0, 'fees' => 0, 'voided' => 0, 'payout' => 0];
                $row['blocks']++;
                $row['subsidy'] += $block->rewardPerPlayer;
                $row['fees'] += $fee;
                $row['voided'] += $voided ? $block->rewardPerPlayer + $fee : 0;
                $row['payout'] = $row['subsidy'] + $row['fees'] - $row['voided'];
                $players[$player] = $row;
            }

            if ($voided) {
                $reserve['voided'] += ($block->rewardPerPlayer + $fee) * count($block->candidate->winners);
            }
        }

        ksort($players);

        return ['players' => $players, 'fees_per_block' => $feePerPlayer, 'reserve' => $reserve];
    }
}
