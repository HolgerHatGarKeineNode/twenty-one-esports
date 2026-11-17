<?php

namespace App\Support\SeasonChain;

/**
 * The counters of the chain that rules 0, 4, 5, 8 and 9 read: everything
 * mined so far, per share key (the game, or its share group) and era, per
 * pairing (day and season) and per winning player, share key and UTC day,
 * and the blocks of score windows per winning player and share key (solo
 * rule 8; a score window's pairing is the window, solo rule 4). A table of 2
 * to 6 seats counts in the pairing of its winner with each loser, and reads
 * the fullest of them (Candidate::pairingKeys()). Voided blocks keep their
 * place in every counter.
 */
final class ChainState
{
    private int $mined = 0;

    /** @var array<string, array<int, int>> share key => era => sats */
    private array $minedByGameAndEra = [];

    /** @var array<string, int> pairing|day => blocks */
    private array $pairingDay = [];

    /** @var array<string, int> pairing => blocks */
    private array $pairingSeason = [];

    /** @var array<string, int> player|share key|day => blocks */
    private array $playerDay = [];

    /** @var array<string, int> player|share key => blocks of score windows in the season */
    private array $windowWins = [];

    public function mined(): int
    {
        return $this->mined;
    }

    /** @return array<string, array<int, int>> */
    public function minedByGameAndEra(): array
    {
        return $this->minedByGameAndEra;
    }

    public function minedIn(string $shareKey, int $era): int
    {
        return $this->minedByGameAndEra[$shareKey][$era] ?? 0;
    }

    public function pairingBlocksOn(Candidate $candidate): int
    {
        return max([0, ...array_map(fn (string $pairing): int => $this->pairingDay[$pairing.'|'.$candidate->utcDay()] ?? 0, $candidate->pairingKeys())]);
    }

    public function pairingBlocks(Candidate $candidate): int
    {
        return max([0, ...array_map(fn (string $pairing): int => $this->pairingSeason[$pairing] ?? 0, $candidate->pairingKeys())]);
    }

    public function playerBlocksOn(string $player, string $shareKey, string $utcDay): int
    {
        return $this->playerDay[$player.'|'.$shareKey.'|'.$utcDay] ?? 0;
    }

    /** Solo rule 8: the score windows this player won with a block, for this share key. */
    public function windowWins(string $player, string $shareKey): int
    {
        return $this->windowWins[$player.'|'.$shareKey] ?? 0;
    }

    /** $shareKey: the candidate's game, or its share group (ConsensusParameters::shareKey()). */
    public function record(Candidate $candidate, Verdict $verdict, string $shareKey): void
    {
        $this->mined += $verdict->reward;
        $this->minedByGameAndEra[$shareKey][$verdict->era] = $this->minedIn($shareKey, $verdict->era) + $verdict->reward;

        $day = $candidate->utcDay();

        foreach ($candidate->pairingKeys() as $pairing) {
            $this->pairingDay[$pairing.'|'.$day] = ($this->pairingDay[$pairing.'|'.$day] ?? 0) + 1;
            $this->pairingSeason[$pairing] = ($this->pairingSeason[$pairing] ?? 0) + 1;
        }

        foreach ($candidate->winners as $player) {
            $key = $player.'|'.$shareKey.'|'.$day;
            $this->playerDay[$key] = ($this->playerDay[$key] ?? 0) + 1;

            if ($candidate->isSolo()) {
                $this->windowWins[$player.'|'.$shareKey] = $this->windowWins($player, $shareKey) + 1;
            }
        }
    }
}
