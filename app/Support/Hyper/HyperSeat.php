<?php

namespace App\Support\Hyper;

/**
 * One seat's purse and hand inside HyperGame (mutable, copied with the game). Read it from outside
 * through HyperGame::seatAt(), which hands out an array.
 *
 * @internal
 */
final class HyperSeat
{
    /**
     * @param  list<string>  $hand
     */
    public function __construct(
        public string $faction,
        public bool $bot,
        public float $fiat = 0.0,
        public float $sats = 0.0,
        public float $loot = 0.0,
        public array $hand = [],
        public bool $out = false,
        public int $conquered = 0,
        public int $free = 0,
        public bool $dip = false,
        public int $yuan = 0,
        public int $moves = 0,
    ) {}

    /**
     * @return array{faction: string, bot: bool, fiat: float, sats: float, loot: float, hand: list<string>, out: bool, conquered: int, free: int, dip: bool, yuan: int, moves: int}
     */
    public function toArray(): array
    {
        return ['faction' => $this->faction, 'bot' => $this->bot, 'fiat' => $this->fiat, 'sats' => $this->sats, 'loot' => $this->loot, 'hand' => $this->hand, 'out' => $this->out, 'conquered' => $this->conquered, 'free' => $this->free, 'dip' => $this->dip, 'yuan' => $this->yuan, 'moves' => $this->moves];
    }
}
