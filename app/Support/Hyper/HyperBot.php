<?php

namespace App\Support\Hyper;

/**
 * The page's bot (botTurn), playing through the same actions a human sends: cards first, then
 * everything onto one front territory (free plebs, an ASIC when it has 3 sats, plebs while the fiat
 * lasts), up to 14 attacks on the best-scored neighbour until won or the odds turn, and one fortify
 * move of the biggest inner stack towards the front. It fills empty seats and takes over for a player
 * who left. tools/hbsim plays the same bot (ParityTest).
 *
 * In a team game (P4) a teammate's territory counts as friendly everywhere the bot asks "foreign?": the
 * front borders an opponent or neutral land, attacks skip teammates, and the inner stack may fortify into a
 * teammate's territory. Without teams that is the seat itself, so the bot plays as before.
 */
final class HyperBot
{
    /**
     * Plays the whole turn of the seat to move.
     */
    public static function playTurn(HyperGame $game): HyperStep
    {
        $turn = new self($game);
        $turn->play();

        return new HyperStep($turn->game, $turn->events, $turn->actions);
    }

    /**
     * Plays turns until the game is over, or until a round beyond `$maxRound` would start (the
     * simulator cuts a game at round 200). Every seat plays as a bot, whatever its flag says.
     */
    public static function playGame(HyperGame $game, int $maxRound = 200): HyperStep
    {
        $events = [];

        while (! $game->isOver() && $game->round() <= $maxRound) {
            $step = self::playTurn($game);
            $game = $step->game;
            array_push($events, ...$step->events);
        }

        return new HyperStep($game, $events);
    }

    /** @var list<array<string, mixed>> */
    private array $events = [];

    /** @var list<array{action: array<string, mixed>, events: list<array<string, mixed>>}> */
    private array $actions = [];

    private readonly int $seat;

    private function __construct(private HyperGame $game)
    {
        $this->seat = $game->currentSeat();
    }

    /**
     * @param  array<string, mixed>  $action
     * @return list<array<string, mixed>>
     *
     * @phpstan-impure
     */
    private function act(array $action): array
    {
        $step = $this->game->apply($this->seat, $action);
        $this->game = $step->game;
        array_push($this->events, ...$step->events);
        $this->actions[] = ['action' => $action, 'events' => $step->events];

        return $step->events;
    }

    private function play(): void
    {
        $seat = $this->seat;

        foreach ($this->game->seatAt($seat)['hand'] as $card) {
            if ($this->game->isOver()) {
                return;
            }

            $target = match ($card) {
                'attack51', 'nokeys' => $this->firstTerritory(fn (int $t): bool => $this->game->cardTargetOk($card, $t)),
                'scam' => $this->firstTerritory(fn (int $t): bool => $this->game->cardTargetOk($card, $t) && $this->game->units($t) > 2),
                'diamond' => $this->firstTerritory(fn (int $t): bool => $this->game->ownerOf($t) === $seat && HyperMap::BANK[$t]),
                default => null,
            };

            if (HyperGame::CARDS[$card] !== null && $target === null) {
                continue;
            }

            $sats = $this->game->seatAt($seat)['sats'];

            if (($card === 'salvador' && $sats > 8) || ($card === 'pizza' && $sats > 12)) {
                continue;
            }

            $this->act(['type' => 'play_card', 'card' => $card, 'target' => $target === null ? null : HyperMap::IDS[$target]]);
        }

        if ($this->game->isOver()) {
            return;
        }

        $goal = $this->goal();
        $front = $this->front($goal);

        if ($front === null) {
            $this->act(['type' => 'end_turn']);

            return;
        }

        $this->deploy($front);
        $this->act(['type' => 'end_phase']);
        $this->attack($goal);

        if ($this->game->isOver()) {
            return;
        }

        $this->act(['type' => 'end_phase']);
        $this->fortify();
        $this->act(['type' => 'end_phase']);
    }

    /**
     * The currency space with the largest own share, then the most sats; null when it holds them all.
     */
    private function goal(): ?int
    {
        $best = null;
        $bestShare = 0.0;

        foreach (HyperMap::ZONE_TERRITORIES as $zone => $territories) {
            if ($this->game->hasZone($this->seat, $zone)) {
                continue;
            }

            // A float even when the division is exact, so equal shares compare equal.
            $share = (float) (count(array_filter($territories, fn (int $t): bool => $this->game->ownerOf($t) === $this->seat)) / count($territories));

            if ($best === null || $share > $bestShare || ($share === $bestShare && HyperMap::ZONE_SATS[$zone] > HyperMap::ZONE_SATS[$best])) {
                $best = $zone;
                $bestShare = $share;
            }
        }

        return $best;
    }

    /**
     * The own territory with a foreign neighbour, in the goal first, then the biggest stack.
     */
    private function front(?int $goal): ?int
    {
        $mine = $this->game->territoriesOf($this->seat);
        $front = null;

        foreach ($mine as $t) {
            if (! $this->bordersForeign($t)) {
                continue;
            }

            if ($front === null) {
                $front = $t;

                continue;
            }

            $inGoal = (int) (HyperMap::ZONE[$t] === $goal);
            $frontInGoal = (int) (HyperMap::ZONE[$front] === $goal);

            if ($inGoal > $frontInGoal || ($inGoal === $frontInGoal && $this->game->units($t) > $this->game->units($front))) {
                $front = $t;
            }
        }

        return $front ?? ($mine[0] ?? null);
    }

    private function deploy(int $front): void
    {
        $id = HyperMap::IDS[$front];
        $seat = $this->game->seatAt($this->seat);
        $asic = $seat['sats'] >= 3;

        if ($seat['free'] > 0) {
            $this->act(['type' => 'deploy', 'territory' => $id, 'unit' => 'pleb', 'qty' => $seat['free']]);
        }

        if ($asic) {
            $this->act(['type' => 'deploy', 'territory' => $id, 'unit' => 'asic', 'qty' => 1]);
        }

        for ($guard = 80; $guard > 0 && $this->game->seatAt($this->seat)['fiat'] + 1e-9 >= $this->game->plebCost($this->seat); $guard--) {
            $this->act(['type' => 'deploy', 'territory' => $id, 'unit' => 'pleb', 'qty' => 1]);
        }
    }

    private function attack(?int $goal): void
    {
        for ($step = 0; $step < 14 && ! $this->game->isOver(); $step++) {
            $best = null;
            $bestScore = 0.0;

            foreach ($this->game->territoriesOf($this->seat) as $from) {
                if ($this->game->units($from) < 3) {
                    continue;
                }

                $asic = $this->game->asicsOn($from) > 0;

                foreach (HyperMap::ADJ[$from] as $to) {
                    if ($this->game->allied($this->game->ownerOf($to), $this->seat)) {
                        continue;
                    }

                    $score = $this->game->units($from) - $this->game->units($to) * 1.4 - $this->game->defenseBonus($to, $asic)
                        + (HyperMap::ZONE[$to] === $goal ? 2.0 : 0.0) + (HyperMap::BANK[$to] ? 1.5 : 0.0);

                    if ($score > 1 && ($best === null || $score > $bestScore)) {
                        $best = [$from, $to];
                        $bestScore = $score;
                    }
                }
            }

            if ($best === null) {
                return;
            }

            [$from, $to] = $best;
            $fromId = HyperMap::IDS[$from];

            do {
                $events = $this->act(['type' => 'attack', 'from' => $fromId, 'to' => HyperMap::IDS[$to], 'mode' => 'roll']);
                $dice = $events[0];
                $won = $this->game->ownerOf($to) === $this->seat;
            } while (! $won && $this->game->units($from) > 2 && $this->game->units($from) > $this->game->units($to));

            if ($this->game->isOver()) {
                return;
            }

            if ($won && $this->game->pendingMove() !== null) {
                // The page's bot moves max(dice, 80 % of what can go) at once; the conquest moved the dice.
                $an = count((array) $dice['attacker']);
                $before = (int) $dice['attacker_units'];
                $this->act(['type' => 'move_in', 'count' => max($an, (int) floor(($before - 1) * 0.8)) - $an]);
            }
        }
    }

    private function fortify(): void
    {
        $inner = null;

        foreach ($this->game->territoriesOf($this->seat) as $t) {
            if ($this->game->units($t) > 1 && ! $this->bordersForeign($t) && ($inner === null || $this->game->units($t) > $this->game->units($inner))) {
                $inner = $t;
            }
        }

        if ($inner === null) {
            return;
        }

        $to = HyperMap::ADJ[$inner][0];

        foreach (HyperMap::ADJ[$inner] as $n) {
            if ($this->bordersForeign($n)) {
                $to = $n;
                break;
            }
        }

        $this->act(['type' => 'fortify', 'from' => HyperMap::IDS[$inner], 'to' => HyperMap::IDS[$to], 'count' => $this->game->units($inner) - 1]);
    }

    private function bordersForeign(int $t): bool
    {
        foreach (HyperMap::ADJ[$t] as $n) {
            if (! $this->game->allied($this->game->ownerOf($n), $this->seat)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  callable(int): bool  $accept
     */
    private function firstTerritory(callable $accept): ?int
    {
        for ($t = 0; $t < HyperMap::COUNT; $t++) {
            if ($accept($t)) {
                return $t;
            }
        }

        return null;
    }
}
