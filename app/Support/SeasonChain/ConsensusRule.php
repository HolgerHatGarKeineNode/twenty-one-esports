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
}
