<?php

namespace App\Support\SeasonChain;

use Carbon\CarbonImmutable;

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
 *
 * A solo candidate (the winner of a score window, NIP rev. 9.18) is checked
 * in the same order with rules 0 to 4, 7 and 8 restated for a field instead
 * of an opponent (soloRule()); rules 5 and 9 are the same for both, rule 6
 * never fails. The field is the other entrants with a verified value in the
 * window: rule 1 counts the trusted ones, rule 3 those of them outside the
 * winner's clan, rule 7 those of them also outside the winner's anchor
 * subtree, and each needs `soloEntrants` with the winner.
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
            $failure = $candidate->isSolo() ? $this->soloRule($rule, $candidate, $season, $parameters, $chain) : null;

            $failure ??= match ($rule) {
                ConsensusRule::Season => $this->season($candidate, $season, $parameters, $chain, $reward),
                // A solo candidate's rules 1 to 4, 7 and 8 were decided by soloRule(): it passed them.
                ConsensusRule::TrustedAndConnected => $candidate->isSolo() ? null : $this->trustedAndConnected($candidate),
                ConsensusRule::RealGame => $candidate->isSolo() ? null : $this->realGame($candidate, $parameters),
                ConsensusRule::SameClan => $candidate->isSolo() ? null : $this->sameClan($candidate),
                ConsensusRule::PairingPerDay => ! $candidate->isSolo() && $chain->pairingBlocksOn($candidate) >= $parameters->pairLimitPerDay ? ['pairing-daily-limit', null] : null,
                ConsensusRule::DailyLimit => $this->dailyLimit($candidate, $parameters, $chain),
                ConsensusRule::SameSubtree => $candidate->isSolo() ? null : $this->sameSubtree($candidate, $parameters->subtree),
                ConsensusRule::PairingPerSeason => ! $candidate->isSolo() && $chain->pairingBlocks($candidate) >= $parameters->pairLimitPerSeason ? ['pairing-season-limit', null] : null,
                ConsensusRule::ShareCap => $chain->minedIn($parameters->shareKey($candidate->game), $era) + $reward > $season->shareCap($parameters->shareFor($candidate->game), $era)
                    ? ['share-cap', $parameters->shareKey($candidate->game)] : null,
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
     * The solo restatement of a rule for the winner of a score window (NIP
     * rev. 9.18, "Solo blocks"); null when it passes or the rule is the same
     * for both (0's weight and supply, 5, 9), which check() then applies.
     *
     *   0. The window lies inside the season: it starts at or after Block 0.
     *   1. The winner at or above the trust minimum, and at least
     *      `soloEntrants` trusted players with a verified value in the
     *      window, the winner included.
     *   2. The winning value is verified by a source (never a director's
     *      entry), set inside [start, end), and the review time `soloReview`
     *      after the end is over at the attestation.
     *   3. Without the winner's clan mates the field still reaches `soloEntrants`.
     *   4. No earlier block of this window and game (the pairing is the window).
     *   7. Without clan mates and the players of the winner's anchor subtree
     *      (both shares at least `subtree`) the field still reaches it.
     *   8. Fewer earlier window blocks of the winner for this share key in the
     *      season than `soloWins`.
     *
     * @return array{0: string, 1: ?string}|null
     */
    private function soloRule(ConsensusRule $rule, Candidate $candidate, SeasonParameters $season, ConsensusParameters $parameters, ChainState $chain): ?array
    {
        $winner = $candidate->winners[0] ?? '';
        $solo = $candidate->solo ?? [];

        return match ($rule) {
            ConsensusRule::Season => $candidate->windowStart()->lt($season->genesisAt) ? ['window-outside-season', null] : null,
            ConsensusRule::TrustedAndConnected => match (true) {
                count($candidate->winners) !== 1 || ($candidate->trust[$winner] ?? 0) < $this->minimumTrust => ['not-trusted', $winner],
                count($this->field($candidate, 1, $parameters->subtree)) + 1 < $parameters->soloEntrants => ['too-few-entrants', (string) (count($this->field($candidate, 1, $parameters->subtree)) + 1)],
                default => null,
            },
            ConsensusRule::RealGame => $this->soloRealGame($candidate, $solo, $parameters),
            ConsensusRule::SameClan => count($this->field($candidate, 3, $parameters->subtree)) + 1 < $parameters->soloEntrants ? ['same-clan', $this->soloClans($candidate, $winner)[0] ?? null] : null,
            ConsensusRule::PairingPerDay => $chain->pairingBlocks($candidate) >= 1 ? ['window-block', null] : null,
            ConsensusRule::SameSubtree => count($this->field($candidate, 7, $parameters->subtree)) + 1 < $parameters->soloEntrants ? ['same-subtree', $candidate->anchors[$winner][0] ?? null] : null,
            ConsensusRule::PairingPerSeason => $chain->windowWins($winner, $parameters->shareKey($candidate->game)) >= $parameters->soloWins ? ['window-wins-limit', $winner] : null,
            default => null,
        };
    }

    /**
     * Solo rule 2: a verified value of a source inside the window, and the
     * review time over. A forfeit never mines, as for every candidate.
     *
     * @param  array<string, mixed>  $solo
     * @return array{0: string, 1: ?string}|null
     */
    private function soloRealGame(Candidate $candidate, array $solo, ConsensusParameters $parameters): ?array
    {
        if ($candidate->resolution === Resolution::Forfeit) {
            return ['forfeit', null];
        }

        if (($solo['verified'] ?? false) !== true || ($solo['source'] ?? 'director') === 'director') {
            return ['unverified', null];
        }

        $achieved = CarbonImmutable::parse((string) ($solo['achieved_at'] ?? '1970-01-01T00:00:00Z'));

        if ($achieved->lt($candidate->windowStart()) || ! $achieved->lt($candidate->windowEnd())) {
            return ['outside-window', null];
        }

        return $candidate->attestedAt->lt($candidate->windowEnd()->addSeconds($parameters->soloReview)) ? ['not-reviewed', null] : null;
    }

    /**
     * The entrants of a score window that count for a solo rule, the winner
     * not included: from rule 1 on those at or above the trust minimum, from
     * rule 3 on without the winner's clan mates, from rule 7 on also without
     * the players of the winner's anchor subtree. A clan mate shares at least
     * one clan with the winner (soloClans(): every clan held from the
     * window's start to the attestation, audit F2).
     *
     * @return list<string>
     */
    private function field(Candidate $candidate, int $upTo, int $subtree): array
    {
        $winner = $candidate->winners[0] ?? '';
        $clans = $this->soloClans($candidate, $winner);
        $anchor = $candidate->anchors[$winner] ?? null;
        $field = [];

        foreach (array_unique((array) ($candidate->solo['entrants'] ?? [])) as $entrant) {
            $entrant = (string) $entrant;
            $theirs = $candidate->anchors[$entrant] ?? null;

            $out = $entrant === $winner
                || ($candidate->trust[$entrant] ?? 0) < $this->minimumTrust
                || ($upTo >= 3 && array_intersect($clans, $this->soloClans($candidate, $entrant)) !== [])
                || ($upTo >= 7 && $anchor !== null && $theirs !== null && $anchor[0] === $theirs[0] && $anchor[1] >= $subtree && $theirs[1] >= $subtree);

            if (! $out) {
                $field[] = $entrant;
            }
        }

        return $field;
    }

    /**
     * The clans a player of a score window held from the window's start to
     * the attestation (the solo fact `clans`, NIP rev. 9.18: one `clan` row
     * each); a candidate without that fact has the one clan at the
     * attestation.
     *
     * @return list<string>
     */
    private function soloClans(Candidate $candidate, string $player): array
    {
        $held = $candidate->solo['clans'][$player] ?? null;

        if (is_array($held)) {
            return array_values(array_map(strval(...), $held));
        }

        $clan = $candidate->clans[$player] ?? null;

        return $clan === null ? [] : [$clan];
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
     * moves (which also excludes a resignation before move 10), and so does
     * a board game played on the league server (NIP rev. 9.13): every
     * candidate that counts its moves.
     *
     * @return array{0: string, 1: ?string}|null
     */
    private function realGame(Candidate $candidate, ConsensusParameters $parameters): ?array
    {
        if ($candidate->resolution === Resolution::Forfeit) {
            return ['forfeit', null];
        }

        if (($candidate->game === self::Chess || $candidate->moves !== null) && ($candidate->moves ?? 0) < $parameters->moves) {
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
     * 5. No winning player has `daily` blocks of this game (of its share
     * group, when it has one) on that UTC day.
     *
     * @return array{0: string, 1: ?string}|null
     */
    private function dailyLimit(Candidate $candidate, ConsensusParameters $parameters, ChainState $chain): ?array
    {
        $daily = $parameters->dailyLimitFor($candidate->game);

        foreach ($daily === null ? [] : $candidate->winners as $player) {
            if ($chain->playerBlocksOn($player, $parameters->shareKey($candidate->game), $candidate->utcDay()) >= $daily) {
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
