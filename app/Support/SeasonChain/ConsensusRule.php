<?php

namespace App\Support\SeasonChain;

/**
 * The consensus rules of `season-chain-v1` (docs/nips/esports.md, "Consensus
 * rules"). Rule 6 (team wins: one block, the reward times the winners) never
 * fails, so it has no case. The case order is the check order.
 */
enum ConsensusRule: int
{
    case Season = 0;
    case TrustedAndConnected = 1;
    case RealGame = 2;
    case SameClan = 3;
    case PairingPerDay = 4;
    case DailyLimit = 5;
    case SameSubtree = 7;
    case PairingPerSeason = 8;
    case ShareCap = 9;

    /** Why a win failing this rule mines nothing, in a few words (the end-of-game panel, P7e). */
    public function reason(): string
    {
        return match ($this) {
            self::Season => __('outside the season or its supply'),
            self::TrustedAndConnected => __('the players do not list each other, or one is below the trust minimum'),
            self::RealGame => __('too short to count as a real game'),
            self::SameClan => __('both players are in the same clan'),
            self::PairingPerDay => __('this pairing already mined a block today'),
            self::DailyLimit => __('the winner reached the daily block limit'),
            self::SameSubtree => __('both players get their trust from the same person'),
            self::PairingPerSeason => __('this pairing reached its blocks for the season'),
            self::ShareCap => __('chess reached its share of this era\'s blocks'),
        };
    }
}
