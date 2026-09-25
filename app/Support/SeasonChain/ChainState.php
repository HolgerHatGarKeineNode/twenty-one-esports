<?php

namespace App\Support\SeasonChain;

/**
 * The counters of the chain that rules 0, 4, 5, 8 and 9 read: everything
 * mined so far, per game and era, per pairing (day and season) and per
 * winning player, game and UTC day. Voided blocks keep their place in every
 * counter.
 */
final class ChainState
{
    private int $mined = 0;

    /** @var array<string, array<int, int>> game => era => sats */
    private array $minedByGameAndEra = [];

    /** @var array<string, int> pairing|day => blocks */
    private array $pairingDay = [];

    /** @var array<string, int> pairing => blocks */
    private array $pairingSeason = [];

    /** @var array<string, int> player|game|day => blocks */
    private array $playerDay = [];

    public function mined(): int
    {
        return $this->mined;
    }

    /** @return array<string, array<int, int>> */
    public function minedByGameAndEra(): array
    {
        return $this->minedByGameAndEra;
    }

    public function minedIn(string $game, int $era): int
    {
        return $this->minedByGameAndEra[$game][$era] ?? 0;
    }

    public function pairingBlocksOn(Candidate $candidate): int
    {
        return $this->pairingDay[$candidate->pairingKey().'|'.$candidate->utcDay()] ?? 0;
    }

    public function pairingBlocks(Candidate $candidate): int
    {
        return $this->pairingSeason[$candidate->pairingKey()] ?? 0;
    }

    public function playerBlocksOn(string $player, string $game, string $utcDay): int
    {
        return $this->playerDay[$player.'|'.$game.'|'.$utcDay] ?? 0;
    }

    public function record(Candidate $candidate, Verdict $verdict): void
    {
        $this->mined += $verdict->reward;
        $this->minedByGameAndEra[$candidate->game][$verdict->era] = $this->minedIn($candidate->game, $verdict->era) + $verdict->reward;

        $day = $candidate->utcDay();
        $pairing = $candidate->pairingKey();
        $this->pairingDay[$pairing.'|'.$day] = ($this->pairingDay[$pairing.'|'.$day] ?? 0) + 1;
        $this->pairingSeason[$pairing] = ($this->pairingSeason[$pairing] ?? 0) + 1;

        foreach ($candidate->winners as $player) {
            $key = $player.'|'.$candidate->game.'|'.$day;
            $this->playerDay[$key] = ($this->playerDay[$key] ?? 0) + 1;
        }
    }
}
