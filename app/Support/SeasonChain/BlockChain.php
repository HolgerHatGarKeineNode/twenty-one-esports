<?php

namespace App\Support\SeasonChain;

use Carbon\CarbonImmutable;

/**
 * The chain of one season (docs/nips/esports.md, "Blocks" and "Parameter
 * changes"). Candidates are attested one by one in league attestation order;
 * a valid one gets the next height, and its era and reward are fixed then.
 * The chain refuses anything that would rewrite the past: an attestation
 * older than the one before (a halving would reverse), a parameter change
 * whose `effective` does not lie after the latest attestation. A void keeps
 * the block, its height and its place in every counter.
 */
final class BlockChain
{
    private readonly ChainState $state;

    /** @var list<Block> */
    private array $blocks = [];

    /** @var list<array{candidate: Candidate, verdict: Verdict, height: ?int, parameters: ConsensusParameters}> */
    private array $attestations = [];

    /** @var array<int, string> height => public reason of the void */
    private array $voids = [];

    private ?CarbonImmutable $latestAttestation = null;

    public function __construct(
        public readonly SeasonParameters $season,
        public readonly ConsensusRules $rules,
    ) {
        $this->state = new ChainState;
    }

    /** Attest the next result; returns its verdict, a block when it mines. */
    public function attest(Candidate $candidate): Verdict
    {
        if ($this->latestAttestation !== null && $candidate->attestedAt->lt($this->latestAttestation)) {
            throw new ChainViolation("{$candidate->label} is attested before the previous attestation; the chain only grows forward.");
        }

        $parameters = $this->season->inForceAt($candidate->attestedAt);
        $verdict = $this->rules->check($candidate, $this->season, $parameters, $this->state);
        $height = null;

        if ($verdict->mines()) {
            $height = count($this->blocks) + 1;
            $this->blocks[] = new Block($height, $candidate, $verdict->era, $verdict->rewardPerPlayer, $verdict->reward);
            $this->state->record($candidate, $verdict);
        }

        $this->attestations[] = ['candidate' => $candidate, 'verdict' => $verdict, 'height' => $height, 'parameters' => $parameters];
        $this->latestAttestation = $candidate->attestedAt;

        return $verdict;
    }

    /** Never retroactive: the change applies only to attestations after the latest one. */
    public function changeParameters(ParameterChange $change): void
    {
        if ($this->latestAttestation !== null && $change->effectiveAt->lte($this->latestAttestation)) {
            throw new ChainViolation('A parameter change is never retroactive: results up to '.$this->latestAttestation->toIso8601ZuluString().' are attested.');
        }

        $this->season->addChange($change);
    }

    /** A `void-block` label of the season review. */
    public function void(int $height, string $reason): void
    {
        if (! isset($this->blocks[$height - 1])) {
            throw new ChainViolation("There is no block {$height}.");
        }

        $this->voids[$height] = $reason;
    }

    public function isVoided(int $height): bool
    {
        return isset($this->voids[$height]);
    }

    /** @return array<int, string> */
    public function voids(): array
    {
        return $this->voids;
    }

    /** @return list<Block> */
    public function blocks(): array
    {
        return $this->blocks;
    }

    /** @return list<array{candidate: Candidate, verdict: Verdict, height: ?int, parameters: ConsensusParameters}> */
    public function attestations(): array
    {
        return $this->attestations;
    }

    public function mined(): int
    {
        return $this->state->mined();
    }

    public function remaining(): int
    {
        return $this->season->supply - $this->state->mined();
    }

    /** @return array<string, array<int, int>> game => era => sats */
    public function minedByGameAndEra(): array
    {
        return $this->state->minedByGameAndEra();
    }
}
