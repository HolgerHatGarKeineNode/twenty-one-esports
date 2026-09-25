<?php

namespace App\Support\SeasonChain;

/**
 * `season-chain-v1` (docs/nips/esports.md, "Consensus rules"): whether a
 * candidate mines, checked against the chain before it and with the
 * parameters in force at its attestation (SeasonParameters::inForceAt()).
 * The reason a win does not mine is the first rule it fails, in the order of
 * ConsensusRule::cases(): 0, 1, 2, 3, 4, 5, 7, 8, 9.
 *
 * Every missing input fails closed: a player without a pinned trust rank is
 * below the minimum, a chess result without a move count is not a real game,
 * a game and mode without a weight does not mine.
 */
final class ConsensusRules
{
    private const string Chess = 'chess';

    public function __construct(public readonly int $minimumTrust = 50) {}

    public static function fromConfig(): self
    {
        return new self((int) config('season.trust_minimum'));
    }

    /**
     * @param  ConsensusParameters  $parameters  the parameters in force at the candidate's attestation
     */
    public function check(Candidate $candidate, SeasonParameters $season, ConsensusParameters $parameters, ChainState $chain): Verdict
    {
        if (! $season->contains($candidate->attestedAt)) {
            return new Verdict(ConsensusRule::Season, 'outside-season', 0, 0, 0);
        }

        $era = $season->eraAt($candidate->attestedAt);
        $perPlayer = $season->rewardPerPlayer($parameters->weightFor($candidate->weightKey), $era);
        $reward = $perPlayer * count($candidate->winners);

        foreach (ConsensusRule::cases() as $rule) {
            $failure = match ($rule) {
                ConsensusRule::Season => $this->season($candidate, $season, $parameters, $chain, $reward),
                ConsensusRule::TrustedAndConnected => $this->trustedAndConnected($candidate),
                ConsensusRule::RealGame => $this->realGame($candidate, $parameters),
                ConsensusRule::SameClan => $this->sameClan($candidate),
                ConsensusRule::PairingPerDay => $chain->pairingBlocksOn($candidate) >= $parameters->pairLimitPerDay ? ['pairing-daily-limit', null] : null,
                ConsensusRule::DailyLimit => $this->dailyLimit($candidate, $parameters, $chain),
                ConsensusRule::SameSubtree => $this->sameSubtree($candidate, $parameters->subtree),
                ConsensusRule::PairingPerSeason => $chain->pairingBlocks($candidate) >= $parameters->pairLimitPerSeason ? ['pairing-season-limit', null] : null,
                ConsensusRule::ShareCap => $chain->minedIn($candidate->game, $era) + $reward > $season->shareCap($parameters->shareFor($candidate->game), $era)
                    ? ['share-cap', $candidate->game] : null,
            };

            if ($failure !== null) {
                return new Verdict($rule, $failure[0], $era, $perPlayer, $reward, $failure[1]);
            }
        }

        return Verdict::block($era, $perPlayer, $reward);
    }

    /**
     * 0. The ladder's game and mode mine, and the block fits the supply minus
     * everything mined before.
     *
     * @return array{0: string, 1: ?string}|null
     */
    private function season(Candidate $candidate, SeasonParameters $season, ConsensusParameters $parameters, ChainState $chain, int $reward): ?array
    {
        if ($parameters->weightFor($candidate->weightKey) <= 0) {
            return ['game-does-not-mine', $candidate->weightKey];
        }

        return $chain->mined() + $reward > $season->supply ? ['supply-exhausted', null] : null;
    }

    /**
     * 1. Every rated player at or above the trust minimum; the two players or
     * gatekeepers list each other.
     *
     * @return array{0: string, 1: ?string}|null
     */
    private function trustedAndConnected(Candidate $candidate): ?array
    {
        foreach ($candidate->players() as $player) {
            if (($candidate->trust[$player] ?? 0) < $this->minimumTrust) {
                return ['not-trusted', $player];
            }
        }

        return $candidate->gatekeepersConnected ? null : ['not-connected', implode(' – ', $candidate->gatekeepers)];
    }

    /**
     * 2. A forfeit never mines; a chess game needs at least `moves` full
     * moves (which also excludes a resignation before move 10).
     *
     * @return array{0: string, 1: ?string}|null
     */
    private function realGame(Candidate $candidate, ConsensusParameters $parameters): ?array
    {
        if ($candidate->resolution === Resolution::Forfeit) {
            return ['forfeit', null];
        }

        if ($candidate->game === self::Chess && ($candidate->moves ?? 0) < $parameters->moves) {
            return ['too-few-moves', (string) ($candidate->moves ?? 0)];
        }

        return null;
    }

    /**
     * 3. No winning and losing player share a clan.
     *
     * @return array{0: string, 1: ?string}|null
     */
    private function sameClan(Candidate $candidate): ?array
    {
        $winningClans = array_filter(array_map(fn (string $player): ?string => $candidate->clans[$player] ?? null, $candidate->winners));

        foreach ($candidate->losers as $player) {
            $clan = $candidate->clans[$player] ?? null;
            if ($clan !== null && in_array($clan, $winningClans, true)) {
                return ['same-clan', $clan];
            }
        }

        return null;
    }

    /**
     * 5. No winning player has `daily` blocks of this game on that UTC day.
     *
     * @return array{0: string, 1: ?string}|null
     */
    private function dailyLimit(Candidate $candidate, ConsensusParameters $parameters, ChainState $chain): ?array
    {
        $daily = $parameters->dailyLimitFor($candidate->game);

        foreach ($daily === null ? [] : $candidate->winners as $player) {
            if ($chain->playerBlocksOn($player, $candidate->game, $candidate->utcDay()) >= $daily) {
                return ['player-daily-limit', $player];
            }
        }

        return null;
    }

    /**
     * 7. Not the same anchor subtree. Compared are the two gatekeepers (the
     * players of a solo game or board, the gatekeepers of a series), as in
     * rule 1 and the sample ledger; NIP rev. 6 words the rule the same way
     * (rev. 5 said every winning and losing player). A player without an
     * anchor share has no subtree.
     *
     * @return array{0: string, 1: ?string}|null
     */
    private function sameSubtree(Candidate $candidate, int $subtree): ?array
    {
        [$first, $second] = $candidate->gatekeepers;
        $a = $candidate->anchors[$first] ?? null;
        $b = $candidate->anchors[$second] ?? null;

        if ($a === null || $b === null || $a[0] !== $b[0]) {
            return null;
        }

        return $a[1] >= $subtree && $b[1] >= $subtree ? ['same-subtree', $a[0]] : null;
    }
}
