<?php

namespace App\Support\Hyper;

use InvalidArgumentException;

/**
 * Hyperbitcoinization's rules (plan "Hyperbitcoinization", P1): Risk with currency spaces, server-
 * authoritative and deterministic. The game page's prototype (resources/js/hyper/prototype/index.html)
 * is the specification; tools/hbsim plays the same rules and the same bots, draw for draw (ParityTest).
 * Plain PHP, no framework, no database: the state serializes to an array (toArray/fromArray).
 *
 * A turn has three phases as on the page: `buy` (place free plebs, buy plebs with fiat, maxis and ASICs
 * with sats; undo), `attack` (roll once or blitz; after a conquest the attacker moves troops in), and
 * `fortify` (one move between neighbours, two when holding South America). Cards are played any time
 * in one's own turn. Income, interest/decay, the card draw and the round limit run as on the page.
 *
 * Actions, sent by the seat to move (`apply`):
 *   {"type":"deploy","territory":"ny","unit":"pleb|maxi|asic","qty":1|"max"}   free plebs go first
 *   {"type":"undo"}                                   the last placement of this buy phase
 *   {"type":"attack","from":"ny","to":"irland","mode":"roll|blitz"}
 *   {"type":"move_in","count":2}                      after a conquest, 0 .. units at `from` - 1
 *   {"type":"fortify","from":"ny","to":"texas","count":3}
 *   {"type":"play_card","card":"attack51","target":"irland"}   target null for cards without one
 *   {"type":"end_phase"}                              buy -> attack -> fortify -> next turn
 *   {"type":"end_turn"}                               ends the turn from any phase (turn timer, bots)
 *
 * Events (`type` plus fields; territories by id, seats by index, neutral = null):
 *   game_started {seats, limit, seed} · turn_started {seat, round, fiat, sats, free_plebs}
 *   placed {seat, territory, unit, count, source: free|fiat|sats, cost, bonus} · undone {seat, territory, unit, count}
 *   phase_changed {seat, phase} · dice_rolled {seat, from, to, attacker, defender, attacker_total,
 *   defender_total, attacker_losses, defender_losses, attacker_units, defender_units}
 *   territory_conquered {seat, territory, from, previous_owner, units} · bank_fallen {seat, territory, zone}
 *   zone_completed {seat, zone} · loot_gained {seat, amount, reason: income|bank}
 *   moved {seat, from, to, count, kind: conquest|fortify} · card_played {seat, card, target, effect}
 *   card_drawn {seat, card} · fiat_interest {seat, amount} · fiat_decayed {seat, amount}
 *   player_eliminated {seat, by, cards} · round_started {round, inflation} · turn_ended {seat}
 *   game_won {seat, by_limit, round, standings, loot}
 *
 * @phpstan-type Seat array{faction: string, bot: bool, fiat: float, sats: float, loot: float, hand: list<string>, out: bool, conquered: int, free: int, dip: bool, yuan: int, moves: int}
 * @phpstan-type Placement array{territory: int, kind: string, count: int, cost: float}
 */
final class HyperGame
{
    public const int VERSION = 1;

    /** @var list<int> round limits the page offers; 0 = none (the default) */
    public const array LIMITS = [0, 12, 20, 30];

    /** @var array<string, string> faction => portrait key of the page's art */
    public const array FACTIONS = ['bitcoiner' => 'you', 'fed' => 'fed', 'ezb' => 'ezb', 'goldbug' => 'goldbug', 'shitcoiner' => 'shit', 'nocoiner' => 'no'];

    /** @var array<string, string|null> card => target kind (null = no target), in the page's deck order */
    public const array CARDS = ['brrrr' => null, 'attack51' => 'enemyAdj', 'keys' => null, 'diamond' => 'own', 'salvador' => null, 'scam' => 'enemyBank', 'pizza' => null, 'lagarde' => null, 'dip' => null, 'nokeys' => 'enemyOne'];

    /** @var array<string, int> sats per maxi and ASIC */
    public const array UNIT_SATS = ['maxi' => 2, 'asic' => 3];

    public const int START_PLEBS = 14;

    public const int HAND_DRAW_BELOW = 5;

    public const int HAND_MAX = 7;

    public const int ZONE_DOLLAR = 0;

    public const int ZONE_SA = 1;

    public const int ZONE_POUND = 2;

    public const int ZONE_EURO = 3;

    public const int ZONE_SWISS = 4;

    public const int ZONE_RUBEL = 5;

    public const int ZONE_YUAN = 6;

    public const int ZONE_YEN = 7;

    public const int ZONE_AFRO = 8;

    public const int ZONE_OZEAN = 9;

    private const int NEUTRAL = -1;

    /** @var array<string, int>|null */
    private static ?array $index = null;

    private int $round = 1;

    private int $cur = 0;

    private string $phase = 'buy';

    private bool $inflation = false;

    private bool $inflationNext = false;

    private bool $fortified = false;

    private bool $over = false;

    private bool $byLimit = false;

    private ?int $winner = null;

    /** @var list<string> */
    private array $deck = [];

    /** @var list<HyperSeat> */
    private array $seats = [];

    /** @var array<int, int> seat index, or -1 for neutral, by territory */
    private array $owner = [];

    /** @var array<int, int> */
    private array $pleb = [];

    /** @var array<int, int> */
    private array $maxi = [];

    /** @var array<int, int> */
    private array $asic = [];

    /** @var array<int, int> the seat whose Diamond Hands guard it, or -1 */
    private array $shield = [];

    /** @var list<Placement> this buy phase's placements, for undo */
    private array $placed = [];

    /** @var array{from: int, to: int}|null a conquest waiting for the move-in */
    private ?array $pending = null;

    /** @var list<array<string, mixed>> */
    private array $events = [];

    private function __construct(private HyperRng $rng, private int $seed, private int $limit) {}

    public function __clone()
    {
        $this->rng = clone $this->rng;
        $this->seats = array_map(fn (HyperSeat $seat): HyperSeat => clone $seat, $this->seats);
    }

    /**
     * A new game as the page sets it up: a value-balanced deal (a neutral third side in the duel), 14
     * start plebs per side plus the seat compensation for later seats, a shuffled deck, and the first
     * turn begun. Seats play in the given order.
     *
     * @param  list<array{faction: string, bot?: bool}>  $seats
     */
    public static function start(array $seats, int $limit, int $seed): HyperStep
    {
        $n = count($seats);

        if ($n < 2 || $n > 6) {
            throw new InvalidArgumentException('A game has 2 to 6 seats.');
        }

        if (! in_array($limit, self::LIMITS, true)) {
            throw new InvalidArgumentException('The round limit is one of 0, 12, 20, 30.');
        }

        $factions = array_map(fn (array $seat): string => $seat['faction'], $seats);

        if (array_diff($factions, array_keys(self::FACTIONS)) !== [] || count(array_unique($factions)) !== $n) {
            throw new InvalidArgumentException('Every seat plays its own faction of '.implode(', ', array_keys(self::FACTIONS)).'.');
        }

        $game = new self(HyperRng::seeded($seed), $seed, $limit);

        foreach ($seats as $seat) {
            $game->seats[] = new HyperSeat($seat['faction'], $seat['bot'] ?? false);
        }

        $neutralSides = $n === 2 ? 1 : 0;
        $game->dealFair($n, $neutralSides);
        $comp = HyperMap::SEAT_COMP[$limit > 0 ? 'limit' : 'open'][$n];

        for ($side = 0; $side < $n + $neutralSides; $side++) {
            $tag = self::sideTag($side, $n);
            $mine = $game->rng->shuffle(array_keys($game->owner, $tag, true));
            $extra = self::START_PLEBS + ($side < $n ? (int) self::roundHalf($comp * $side) : 0);

            for ($k = 0; $k < $extra && $mine !== []; $k++) {
                $game->pleb[$mine[$k % count($mine)]]++;
            }
        }

        foreach ($game->owner as $t => $owner) {
            if ($owner < 0) {
                $game->owner[$t] = self::NEUTRAL;
            }
        }

        $game->deck = $game->newDeck();
        $game->emit('game_started', ['seats' => array_map(fn (HyperSeat $s): array => ['faction' => $s->faction, 'bot' => $s->bot], $game->seats), 'limit' => $limit, 'seed' => $seed]);
        $game->beginTurn();

        return new HyperStep($game, $game->flush());
    }

    /**
     * Applies one action of `seat` to a copy of this game; this game stays as it was.
     *
     * @param  array<string, mixed>  $action
     *
     * @throws HyperRuleViolation
     */
    public function apply(int $seat, array $action): HyperStep
    {
        $next = clone $this;
        $next->events = [];
        $next->perform($seat, $action);

        return new HyperStep($next, $next->flush());
    }

    /* ---------------------------------------------------------------- queries */

    public function seed(): int
    {
        return $this->seed;
    }

    public function limit(): int
    {
        return $this->limit;
    }

    public function round(): int
    {
        return $this->round;
    }

    public function currentSeat(): int
    {
        return $this->cur;
    }

    public function phase(): string
    {
        return $this->phase;
    }

    public function isOver(): bool
    {
        return $this->over;
    }

    public function winner(): ?int
    {
        return $this->winner;
    }

    public function wonByLimit(): bool
    {
        return $this->byLimit;
    }

    public function seatCount(): int
    {
        return count($this->seats);
    }

    public function isBot(int $seat): bool
    {
        return $this->seatAt($seat)['bot'];
    }

    /**
     * A bot takes over a seat (or hands it back).
     */
    public function withBot(int $seat, bool $bot = true): self
    {
        $this->seatAt($seat);
        $next = clone $this;
        $next->seats[$seat]->bot = $bot;

        return $next;
    }

    /**
     * @return Seat
     */
    public function seatAt(int $seat): array
    {
        return ($this->seats[$seat] ?? throw new HyperRuleViolation('bad_seat', "No seat {$seat}."))->toArray();
    }

    /**
     * @return array{from: string, to: string, max: int}|null
     */
    public function pendingMove(): ?array
    {
        if ($this->pending === null) {
            return null;
        }

        return ['from' => HyperMap::IDS[$this->pending['from']], 'to' => HyperMap::IDS[$this->pending['to']], 'max' => $this->units($this->pending['from']) - 1];
    }

    /**
     * The owner of a territory by index: a seat, or null for neutral.
     */
    public function ownerOf(int $t): ?int
    {
        return $this->owner[$t] >= 0 ? $this->owner[$t] : null;
    }

    public function units(int $t): int
    {
        return $this->pleb[$t] + $this->maxi[$t] + $this->asic[$t];
    }

    public function asicsOn(int $t): int
    {
        return $this->asic[$t];
    }

    /**
     * @return list<int> the seat's territories by index, in map order
     */
    public function territoriesOf(int $seat): array
    {
        return array_keys($this->owner, $seat, true);
    }

    public function banksOf(int $seat): int
    {
        return count(array_filter($this->territoriesOf($seat), fn (int $t): bool => HyperMap::BANK[$t]));
    }

    public function unitsOf(int $seat): int
    {
        return array_sum(array_map(fn (int $t): int => $this->units($t), $this->territoriesOf($seat)));
    }

    /**
     * The seat holding every territory of a currency space, or null.
     */
    public function zoneOwner(int $zone): ?int
    {
        $territories = HyperMap::ZONE_TERRITORIES[$zone];
        $owner = $this->owner[$territories[0]];

        if ($owner < 0) {
            return null;
        }

        foreach ($territories as $t) {
            if ($this->owner[$t] !== $owner) {
                return null;
            }
        }

        return $owner;
    }

    public function hasZone(int $seat, int $zone): bool
    {
        return $this->zoneOwner($zone) === $seat;
    }

    /**
     * Fiat per pleb for a seat: inflation +50 %, the Euro block -20 %, Buy the Dip -50 %.
     */
    public function plebCost(int $seat): float
    {
        $cost = 1.0;

        if ($this->inflation) {
            $cost *= 1.5;
        }

        if ($this->hasZone($seat, self::ZONE_EURO)) {
            $cost *= 0.8;
        }

        if ($this->seats[$seat]->dip) {
            $cost *= 0.5;
        }

        return self::r2($cost);
    }

    /**
     * What the defender adds to its highest die: bank (not against an ASIC), maxi, the Swiss vault, a
     * complete Rubel or Oceania space, Diamond Hands.
     */
    public function defenseBonus(int $t, bool $attackerHasAsic): int
    {
        $bonus = 0;

        if (HyperMap::BANK[$t] && ! $attackerHasAsic) {
            $bonus++;
        }

        if ($this->maxi[$t] > 0) {
            $bonus++;
        }

        if (HyperMap::ZONE[$t] === self::ZONE_SWISS) {
            $bonus++;
        }

        if ($this->owner[$t] >= 0 && in_array(HyperMap::ZONE[$t], [self::ZONE_RUBEL, self::ZONE_OZEAN], true) && $this->hasZone($this->owner[$t], HyperMap::ZONE[$t])) {
            $bonus++;
        }

        if ($this->shield[$t] !== -1) {
            $bonus++;
        }

        return $bonus;
    }

    /**
     * Whether the seat to move may play a card on a territory (the page's cardTargetOk).
     */
    public function cardTargetOk(string $card, int $t): bool
    {
        $seat = $this->cur;
        $owner = $this->owner[$t];

        return match (self::CARDS[$card] ?? null) {
            'enemyAdj' => $owner !== $seat && $this->units($t) <= 3 && $this->bordersSeat($t, $seat),
            'own' => $owner === $seat,
            'enemyBank' => $owner !== $seat && $owner >= 0 && HyperMap::BANK[$t],
            'enemyOne' => $owner !== $seat && $owner >= 0 && $this->units($t) === 1,
            default => false,
        };
    }

    /**
     * Alive seats, best first: most central banks, then territories, then units (the limit's ranking).
     *
     * @return list<int>
     */
    public function standings(): array
    {
        $alive = array_values(array_filter(array_keys($this->seats), fn (int $s): bool => ! $this->seats[$s]->out));
        usort($alive, fn (int $a, int $b): int => ($this->banksOf($b) <=> $this->banksOf($a))
            ?: (count($this->territoriesOf($b)) <=> count($this->territoriesOf($a)))
            ?: ($this->unitsOf($b) <=> $this->unitsOf($a)));

        return $alive;
    }

    /**
     * Sats collected per seat (the loot credited to the profile after the game).
     *
     * @return list<float>
     */
    public function loot(): array
    {
        return array_map(fn (HyperSeat $seat): float => $seat->loot, $this->seats);
    }

    /**
     * Everything the page needs to offer the seat to move its options.
     *
     * @return array{seat: int, phase: string, over: bool, pending_move: array{from: string, to: string, max: int}|null, free_plebs: int, costs: array{pleb: float, maxi: int, asic: int}, affordable: array{pleb: bool, maxi: bool, asic: bool}, deploy: list<string>, attack: array<string, list<string>>, fortify: array<string, list<string>>, cards: array<string, list<string>|null>, can_undo: bool, can_end_phase: bool}
     */
    public function legal(): array
    {
        $seat = $this->cur;
        $p = $this->seats[$seat]->toArray();
        $mine = $this->territoriesOf($seat);
        $active = ! $this->over && $this->pending === null;
        $ids = fn (array $territories): array => array_values(array_map(fn (int $t): string => HyperMap::IDS[$t], $territories));
        $attack = [];
        $fortify = [];

        if ($active && $this->phase === 'attack') {
            foreach ($mine as $from) {
                $targets = $this->attackTargets($from);

                if ($this->units($from) >= 2 && $targets !== []) {
                    $attack[HyperMap::IDS[$from]] = $ids($targets);
                }
            }
        }

        if ($active && $this->phase === 'fortify' && ! $this->fortified) {
            foreach ($mine as $from) {
                $targets = $this->fortifyTargets($from);

                if ($this->units($from) >= 2 && $targets !== []) {
                    $fortify[HyperMap::IDS[$from]] = $ids($targets);
                }
            }
        }

        $cards = [];

        if ($active) {
            foreach (array_unique($p['hand']) as $card) {
                $cards[$card] = self::CARDS[$card] === null ? null : $ids($this->cardTargets($card));
            }
        }

        return [
            'seat' => $seat,
            'phase' => $this->phase,
            'over' => $this->over,
            'pending_move' => $this->pendingMove(),
            'free_plebs' => $p['free'],
            'costs' => ['pleb' => $this->plebCost($seat), 'maxi' => self::UNIT_SATS['maxi'], 'asic' => self::UNIT_SATS['asic']],
            'affordable' => [
                'pleb' => $this->plebCost($seat) <= $p['fiat'] + 1e-9,
                'maxi' => self::UNIT_SATS['maxi'] <= $p['sats'] + 1e-9,
                'asic' => self::UNIT_SATS['asic'] <= $p['sats'] + 1e-9,
            ],
            'deploy' => $active && $this->phase === 'buy' ? $ids($mine) : [],
            'attack' => $attack,
            'fortify' => $fortify,
            'cards' => $cards,
            'can_undo' => $active && $this->phase === 'buy' && $this->placed !== [],
            'can_end_phase' => $active && ! ($this->phase === 'buy' && $p['free'] > 0),
        ];
    }

    /**
     * @return list<int> neighbours of `from` the seat to move may attack
     */
    public function attackTargets(int $from): array
    {
        return array_values(array_filter(HyperMap::ADJ[$from], fn (int $to): bool => $this->owner[$to] !== $this->owner[$from]));
    }

    /**
     * @return list<int> own neighbours of `from`
     */
    public function fortifyTargets(int $from): array
    {
        return array_values(array_filter(HyperMap::ADJ[$from], fn (int $to): bool => $this->owner[$to] === $this->owner[$from]));
    }

    /**
     * @return list<int>
     */
    public function cardTargets(string $card): array
    {
        return array_values(array_filter(range(0, HyperMap::COUNT - 1), fn (int $t): bool => $this->cardTargetOk($card, $t)));
    }

    /**
     * The territory index of an id like "ny".
     */
    public static function territoryIndex(mixed $id): int
    {
        self::$index ??= array_flip(HyperMap::IDS);

        if (! is_string($id) || ! isset(self::$index[$id])) {
            throw new HyperRuleViolation('unknown_territory', 'Unknown territory '.(is_scalar($id) ? (string) $id : gettype($id)).'.');
        }

        return self::$index[$id];
    }

    /* ---------------------------------------------------------------- serialization */

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $territories = [];

        foreach (HyperMap::IDS as $t => $id) {
            $territories[$id] = ['owner' => $this->ownerOf($t), 'pleb' => $this->pleb[$t], 'maxi' => $this->maxi[$t], 'asic' => $this->asic[$t], 'shield' => $this->shield[$t] >= 0 ? $this->shield[$t] : null];
        }

        return [
            'version' => self::VERSION,
            'seed' => $this->seed,
            'limit' => $this->limit,
            'round' => $this->round,
            'seat' => $this->cur,
            'phase' => $this->phase,
            'inflation' => $this->inflation,
            'inflation_next' => $this->inflationNext,
            'fortified' => $this->fortified,
            'over' => $this->over,
            'winner' => $this->winner,
            'by_limit' => $this->byLimit,
            'rng' => $this->rng->state(),
            'deck' => $this->deck,
            'seats' => array_map(fn (HyperSeat $s): array => ['faction' => $s->faction, 'bot' => $s->bot, 'fiat' => $s->fiat, 'sats' => $s->sats, 'loot' => $s->loot, 'hand' => $s->hand, 'out' => $s->out, 'conquered' => $s->conquered, 'free_plebs' => $s->free, 'dip' => $s->dip, 'yuan' => $s->yuan, 'moves' => $s->moves], $this->seats),
            'territories' => $territories,
            'placed' => array_map(fn (array $p): array => ['territory' => HyperMap::IDS[$p['territory']], 'kind' => $p['kind'], 'count' => $p['count'], 'cost' => $p['cost']], $this->placed),
            'pending_move' => $this->pending === null ? null : ['from' => HyperMap::IDS[$this->pending['from']], 'to' => HyperMap::IDS[$this->pending['to']]],
        ];
    }

    /**
     * @param  array<string, mixed>  $data  from toArray(), possibly through JSON
     *
     * @throws InvalidArgumentException for data that is no game state
     */
    public static function fromArray(array $data): self
    {
        if (($data['version'] ?? null) !== self::VERSION) {
            throw new InvalidArgumentException('Unknown Hyperbitcoinization state version.');
        }

        $int = function (mixed $v, string $key): int {
            if (! is_int($v)) {
                throw new InvalidArgumentException("State field {$key} is no integer.");
            }

            return $v;
        };
        $float = function (mixed $v, string $key): float {
            if (! is_int($v) && ! is_float($v)) {
                throw new InvalidArgumentException("State field {$key} is no number.");
            }

            return (float) $v;
        };
        $bool = function (mixed $v, string $key): bool {
            if (! is_bool($v)) {
                throw new InvalidArgumentException("State field {$key} is no boolean.");
            }

            return $v;
        };
        $cards = function (mixed $v, string $key): array {
            if (! is_array($v) || ! array_is_list($v) || array_diff($v, array_keys(self::CARDS)) !== []) {
                throw new InvalidArgumentException("State field {$key} is no list of cards.");
            }

            /** @var list<string> $v */
            return $v;
        };
        $rng = $data['rng'] ?? null;

        if (! is_array($rng) || ! array_is_list($rng)) {
            throw new InvalidArgumentException('State field rng is no list.');
        }

        $game = new self(HyperRng::fromState($rng), $int($data['seed'] ?? null, 'seed'), $int($data['limit'] ?? null, 'limit'));
        $game->round = $int($data['round'] ?? null, 'round');
        $game->cur = $int($data['seat'] ?? null, 'seat');
        $phase = $data['phase'] ?? null;

        if (! in_array($phase, ['buy', 'attack', 'fortify'], true)) {
            throw new InvalidArgumentException('State field phase is unknown.');
        }

        $game->phase = $phase;
        $game->inflation = $bool($data['inflation'] ?? null, 'inflation');
        $game->inflationNext = $bool($data['inflation_next'] ?? null, 'inflation_next');
        $game->fortified = $bool($data['fortified'] ?? null, 'fortified');
        $game->over = $bool($data['over'] ?? null, 'over');
        $game->byLimit = $bool($data['by_limit'] ?? null, 'by_limit');
        $game->winner = ($data['winner'] ?? null) === null ? null : $int($data['winner'], 'winner');
        $game->deck = $cards($data['deck'] ?? null, 'deck');
        $seats = $data['seats'] ?? null;

        if (! is_array($seats) || ! array_is_list($seats) || count($seats) < 2 || count($seats) > 6) {
            throw new InvalidArgumentException('State field seats is no list of 2 to 6 seats.');
        }

        foreach ($seats as $s) {
            if (! is_array($s) || ! isset(self::FACTIONS[$s['faction'] ?? ''])) {
                throw new InvalidArgumentException('A seat has no known faction.');
            }

            $game->seats[] = new HyperSeat((string) $s['faction'], $bool($s['bot'] ?? null, 'bot'), $float($s['fiat'] ?? null, 'fiat'), $float($s['sats'] ?? null, 'sats'), $float($s['loot'] ?? null, 'loot'), $cards($s['hand'] ?? null, 'hand'), $bool($s['out'] ?? null, 'out'), $int($s['conquered'] ?? null, 'conquered'), $int($s['free_plebs'] ?? null, 'free_plebs'), $bool($s['dip'] ?? null, 'dip'), $int($s['yuan'] ?? null, 'yuan'), $int($s['moves'] ?? null, 'moves'));
        }

        $territories = $data['territories'] ?? null;

        if (! is_array($territories) || array_keys($territories) !== HyperMap::IDS) {
            throw new InvalidArgumentException('State field territories does not name the 53 territories in map order.');
        }

        foreach (array_values($territories) as $t => $row) {
            if (! is_array($row)) {
                throw new InvalidArgumentException('A territory is no object.');
            }

            $owner = $row['owner'] ?? null;
            $shield = $row['shield'] ?? null;
            $game->owner[$t] = $owner === null ? self::NEUTRAL : $int($owner, 'owner');
            $game->pleb[$t] = $int($row['pleb'] ?? null, 'pleb');
            $game->maxi[$t] = $int($row['maxi'] ?? null, 'maxi');
            $game->asic[$t] = $int($row['asic'] ?? null, 'asic');
            $game->shield[$t] = $shield === null ? -1 : $int($shield, 'shield');
        }

        $placed = $data['placed'] ?? [];

        foreach (is_array($placed) ? $placed : [] as $p) {
            if (! is_array($p) || ! is_string($p['kind'] ?? null)) {
                throw new InvalidArgumentException('A placement is malformed.');
            }

            $game->placed[] = ['territory' => self::territoryIndex($p['territory'] ?? null), 'kind' => $p['kind'], 'count' => $int($p['count'] ?? null, 'count'), 'cost' => $float($p['cost'] ?? null, 'cost')];
        }

        $pending = $data['pending_move'] ?? null;
        $game->pending = is_array($pending) ? ['from' => self::territoryIndex($pending['from'] ?? null), 'to' => self::territoryIndex($pending['to'] ?? null)] : null;

        if ($game->cur < 0 || $game->cur >= count($game->seats)) {
            throw new InvalidArgumentException('State field seat is out of range.');
        }

        return $game;
    }

    /* ---------------------------------------------------------------- actions */

    /**
     * @param  array<string, mixed>  $action
     */
    private function perform(int $seat, array $action): void
    {
        if ($this->over) {
            throw new HyperRuleViolation('game_over', 'The game is over.');
        }

        $this->seatAt($seat);

        if ($seat !== $this->cur) {
            throw new HyperRuleViolation('not_your_turn', "Seat {$seat} acted in seat {$this->cur}'s turn.");
        }

        $type = $action['type'] ?? null;

        if ($this->pending !== null && ! in_array($type, ['move_in', 'end_turn'], true)) {
            throw new HyperRuleViolation('move_in_pending', 'Move troops into the conquered territory first.');
        }

        match ($type) {
            'deploy' => $this->deploy($action),
            'undo' => $this->undo(),
            'attack' => $this->attack($action),
            'move_in' => $this->moveIn($action),
            'fortify' => $this->fortify($action),
            'play_card' => $this->playCard($action),
            'end_phase' => $this->endPhase(),
            'end_turn' => $this->endTurnAction(),
            default => throw new HyperRuleViolation('unknown_action', 'Unknown action '.(is_scalar($type) ? (string) $type : gettype($type)).'.'),
        };
    }

    /**
     * @param  array<string, mixed>  $action
     */
    private function deploy(array $action): void
    {
        $this->requirePhase('buy');
        $t = $this->ownTerritory($action['territory'] ?? null);
        $qty = $action['qty'] ?? 1;
        $want = $qty === 'max' ? 999 : $qty;
        $unit = $action['unit'] ?? 'pleb';

        if (! is_int($want) || $want < 1 || $want > 999) {
            throw new HyperRuleViolation('bad_quantity', 'The quantity is 1 to 999 or "max".');
        }

        if (! in_array($unit, ['pleb', 'maxi', 'asic'], true)) {
            throw new HyperRuleViolation('unknown_unit', 'The unit is pleb, maxi or asic.');
        }

        $seat = $this->cur;

        // As on the page: free plebs are placed first, whatever unit is selected.
        if ($this->seats[$seat]->free > 0) {
            $k = min($want, $this->seats[$seat]->free);
            $this->seats[$seat]->free -= $k;
            $this->pleb[$t] += $k;
            $this->placed[] = ['territory' => $t, 'kind' => 'free', 'count' => $k, 'cost' => 0.0];
            $this->emit('placed', ['seat' => $seat, 'territory' => HyperMap::IDS[$t], 'unit' => 'pleb', 'count' => $k, 'source' => 'free', 'cost' => 0.0, 'bonus' => 0]);

            return;
        }

        $n = 0;
        $bonus = 0;
        $spent = 0.0;

        if ($unit === 'pleb') {
            for ($i = 0; $i < $want; $i++) {
                $cost = $this->plebCost($seat);

                if ($cost > $this->seats[$seat]->fiat + 1e-9) {
                    break;
                }

                $this->seats[$seat]->fiat = self::r2($this->seats[$seat]->fiat - $cost);
                $this->pleb[$t]++;
                $n++;
                $spent += $cost;
                $this->placed[] = ['territory' => $t, 'kind' => 'pleb', 'count' => 1, 'cost' => $cost];

                // Yuan sphere: every third pleb bought is free.
                if ($this->hasZone($seat, self::ZONE_YUAN)) {
                    $this->seats[$seat]->yuan++;

                    if ($this->seats[$seat]->yuan % 3 === 0) {
                        $this->pleb[$t]++;
                        $bonus++;
                        $this->placed[] = ['territory' => $t, 'kind' => 'yuanfree', 'count' => 1, 'cost' => 0.0];
                    }
                }
            }

            if ($n === 0) {
                throw new HyperRuleViolation('not_enough_fiat', 'Not enough fiat for a pleb.');
            }

            $this->emit('placed', ['seat' => $seat, 'territory' => HyperMap::IDS[$t], 'unit' => 'pleb', 'count' => $n, 'source' => 'fiat', 'cost' => self::r2($spent), 'bonus' => $bonus]);

            return;
        }

        $cost = self::UNIT_SATS[$unit];

        for ($i = 0; $i < min($want, 5); $i++) {
            if ($cost > $this->seats[$seat]->sats + 1e-9) {
                break;
            }

            $this->seats[$seat]->sats = self::r1($this->seats[$seat]->sats - $cost);

            if ($unit === 'maxi') {
                $this->maxi[$t]++;
            } else {
                $this->asic[$t]++;
            }

            $n++;
            $this->placed[] = ['territory' => $t, 'kind' => $unit, 'count' => 1, 'cost' => (float) $cost];
        }

        if ($n === 0) {
            throw new HyperRuleViolation('not_enough_sats', "Not enough sats for a {$unit}.");
        }

        $this->emit('placed', ['seat' => $seat, 'territory' => HyperMap::IDS[$t], 'unit' => $unit, 'count' => $n, 'source' => 'sats', 'cost' => (float) ($n * $cost), 'bonus' => 0]);
    }

    private function undo(): void
    {
        $this->requirePhase('buy');

        if ($this->placed === []) {
            throw new HyperRuleViolation('nothing_to_undo', 'Nothing placed this phase.');
        }

        $this->undoLast();
    }

    private function undoLast(): void
    {
        $last = array_pop($this->placed);

        if ($last === null) {
            return;
        }

        $t = $last['territory'];
        $seat = $this->seats[$this->cur];

        if ($last['kind'] === 'free') {
            $this->pleb[$t] -= $last['count'];
            $seat->free += $last['count'];
        } elseif ($last['kind'] === 'pleb') {
            $this->pleb[$t]--;
            $seat->fiat = self::r2($seat->fiat + $last['cost']);
        } elseif ($last['kind'] === 'yuanfree') {
            $this->pleb[$t]--;
            $seat->yuan--;
        } elseif ($last['kind'] === 'maxi') {
            $this->maxi[$t]--;
            $seat->sats = self::r1($seat->sats + $last['cost']);
        } else {
            $this->asic[$t]--;
            $seat->sats = self::r1($seat->sats + $last['cost']);
        }

        // The free bonus pleb of the Yuan sphere goes back together with the pleb that earned it.
        if ($last['kind'] === 'yuanfree') {
            $this->undoLast();

            return;
        }

        $this->emit('undone', ['seat' => $this->cur, 'territory' => HyperMap::IDS[$t], 'unit' => in_array($last['kind'], ['maxi', 'asic'], true) ? $last['kind'] : 'pleb', 'count' => $last['count']]);
    }

    /**
     * @param  array<string, mixed>  $action
     */
    private function attack(array $action): void
    {
        $this->requirePhase('attack');
        $from = $this->ownTerritory($action['from'] ?? null);
        $to = self::territoryIndex($action['to'] ?? null);
        $mode = $action['mode'] ?? 'roll';

        if (! in_array($mode, ['roll', 'blitz'], true)) {
            throw new HyperRuleViolation('unknown_mode', 'The attack mode is roll or blitz.');
        }

        if ($this->units($from) < 2) {
            throw new HyperRuleViolation('too_few_units', 'An attack needs at least 2 units.');
        }

        if (! in_array($to, HyperMap::ADJ[$from], true)) {
            throw new HyperRuleViolation('not_adjacent', 'Only neighbours can be attacked.');
        }

        if ($this->owner[$to] === $this->cur) {
            throw new HyperRuleViolation('own_territory', 'That territory is already yours.');
        }

        do {
            $previous = $this->owner[$to];
            [$an, $won] = $this->battleRound($from, $to);

            if ($won) {
                $this->conquest($from, $to, $previous, $an);

                if (! $this->over && $this->units($from) > 1) {
                    $this->pending = ['from' => $from, 'to' => $to];
                }

                return;
            }
        } while ($mode === 'blitz' && $this->units($from) > 1);
    }

    /**
     * @param  array<string, mixed>  $action
     */
    private function moveIn(array $action): void
    {
        if ($this->pending === null) {
            throw new HyperRuleViolation('no_move_pending', 'There is no conquest to move into.');
        }

        ['from' => $from, 'to' => $to] = $this->pending;
        $count = $action['count'] ?? null;

        if (! is_int($count) || $count < 0 || $count > $this->units($from) - 1) {
            throw new HyperRuleViolation('bad_count', 'Move 0 to '.($this->units($from) - 1).' units.');
        }

        $this->pending = null;

        if ($count > 0) {
            $this->moveUnits($from, $to, $count);
            $this->emit('moved', ['seat' => $this->cur, 'from' => HyperMap::IDS[$from], 'to' => HyperMap::IDS[$to], 'count' => $count, 'kind' => 'conquest']);
        }
    }

    /**
     * @param  array<string, mixed>  $action
     */
    private function fortify(array $action): void
    {
        $this->requirePhase('fortify');

        if ($this->fortified) {
            throw new HyperRuleViolation('already_fortified', 'This turn has moved its troops already.');
        }

        $from = $this->ownTerritory($action['from'] ?? null);
        $to = $this->ownTerritory($action['to'] ?? null);
        $count = $action['count'] ?? null;

        if (! in_array($to, HyperMap::ADJ[$from], true)) {
            throw new HyperRuleViolation('not_adjacent', 'Troops move only into a neighbouring own territory.');
        }

        if (! is_int($count) || $count < 1 || $count > $this->units($from) - 1) {
            throw new HyperRuleViolation('bad_count', 'Move 1 to '.($this->units($from) - 1).' units; one stays behind.');
        }

        $seat = $this->cur;
        $this->moveUnits($from, $to, $count);
        $this->seats[$seat]->moves++;

        if ($this->seats[$seat]->moves >= ($this->hasZone($seat, self::ZONE_SA) ? 2 : 1)) {
            $this->fortified = true;
        }

        $this->emit('moved', ['seat' => $seat, 'from' => HyperMap::IDS[$from], 'to' => HyperMap::IDS[$to], 'count' => $count, 'kind' => 'fortify']);
    }

    /**
     * @param  array<string, mixed>  $action
     */
    private function playCard(array $action): void
    {
        $card = $action['card'] ?? null;

        if (! is_string($card) || ! array_key_exists($card, self::CARDS)) {
            throw new HyperRuleViolation('unknown_card', 'Unknown card.');
        }

        $seat = $this->cur;
        $index = array_search($card, $this->seats[$seat]->hand, true);

        if ($index === false) {
            throw new HyperRuleViolation('card_not_in_hand', "No {$card} in hand.");
        }

        $target = null;

        if (self::CARDS[$card] !== null) {
            $target = self::territoryIndex($action['target'] ?? null);

            if (! $this->cardTargetOk($card, $target)) {
                throw new HyperRuleViolation('bad_target', "That target does not fit {$card}.");
            }
        } elseif (($action['target'] ?? null) !== null) {
            throw new HyperRuleViolation('bad_target', "{$card} takes no target.");
        }

        array_splice($this->seats[$seat]->hand, $index, 1);
        // Drawn for every card, as on the page (only El Salvador reads it).
        $up = $this->rng->float() < 0.5;
        $effect = [];
        $conquest = null;

        switch ($card) {
            case 'brrrr':
                $this->seats[$seat]->free += 8;
                $this->inflationNext = true;
                $effect = ['free_plebs' => 8, 'inflation_next_round' => true];
                break;
            case 'attack51':
                assert($target !== null);
                $previous = $this->owner[$target];
                $source = null;

                foreach (HyperMap::ADJ[$target] as $n) {
                    if ($this->owner[$n] === $seat && ($source === null || $this->units($n) > $this->units($source))) {
                        $source = $n;
                    }
                }

                assert($source !== null);
                $this->pleb[$target] = 0;
                $this->maxi[$target] = 0;
                $this->asic[$target] = 0;
                $effect = ['from' => HyperMap::IDS[$source]];

                if ($this->units($source) > 1) {
                    $conquest = [$source, $target, $previous];
                } else {
                    // A lone unit takes it without leaving home; a loser without land is out as after a conquest.
                    $this->owner[$target] = $seat;
                    $this->pleb[$target] = 1;
                    $this->shield[$target] = -1;
                    $this->seats[$seat]->conquered++;
                    $this->knockOutIfLandless($previous, $seat);
                }
                break;
            case 'keys':
                $rich = null;

                foreach ($this->seats as $i => $other) {
                    if ($i !== $seat && ! $other->out && ($rich === null || $other->sats > $this->seats[$rich]->sats)) {
                        $rich = $i;
                    }
                }

                if ($rich !== null) {
                    $lost = self::r1($this->seats[$rich]->sats * 0.3);
                    $this->seats[$rich]->sats = self::r1($this->seats[$rich]->sats - $lost);
                    $effect = ['victim' => $rich, 'sats_lost' => $lost];
                }
                break;
            case 'diamond':
                assert($target !== null);
                $this->shield[$target] = $seat;
                break;
            case 'salvador':
                $this->seats[$seat]->sats = self::r1($this->seats[$seat]->sats * ($up ? 1.25 : 0.75));
                $effect = ['up' => $up];
                break;
            case 'scam':
                assert($target !== null);
                $lost = min(2, $this->units($target) - 1);
                $this->lose($target, $lost);
                $effect = ['units_lost' => $lost];
                break;
            case 'pizza':
                $this->seats[$seat]->sats = self::r1($this->seats[$seat]->sats * 0.9);
                $this->seats[$seat]->free += 3;
                $effect = ['free_plebs' => 3];
                break;
            case 'lagarde':
                $this->seats[$seat]->fiat += 3;

                if ($this->deck === []) {
                    $this->deck = $this->newDeck();
                }

                $effect = ['fiat' => 3, 'next_card' => $this->deck[0]];
                break;
            case 'dip':
                $this->seats[$seat]->dip = true;
                break;
            case 'nokeys':
                assert($target !== null);
                $previous = $this->owner[$target];
                $this->owner[$target] = self::NEUTRAL;

                if ($this->territoriesOf($previous) === []) {
                    $this->seats[$previous]->out = true;
                    $this->emit('player_eliminated', ['seat' => $previous, 'by' => $seat, 'cards' => 0]);
                }
                break;
        }

        $this->emit('card_played', ['seat' => $seat, 'card' => $card, 'target' => $target === null ? null : HyperMap::IDS[$target], 'effect' => $effect]);

        if ($conquest !== null) {
            $this->conquest($conquest[0], $conquest[1], $conquest[2], 1);
        } elseif (! $this->over) {
            $this->checkWin(false);
        }
    }

    private function endPhase(): void
    {
        $seat = $this->cur;

        if ($this->phase === 'buy') {
            if ($this->seats[$seat]->free > 0) {
                throw new HyperRuleViolation('free_plebs_first', 'Place the free plebs first.');
            }

            $this->phase = 'attack';
            $this->placed = [];
            $this->emit('phase_changed', ['seat' => $seat, 'phase' => 'attack']);

            return;
        }

        if ($this->phase === 'attack') {
            $this->phase = 'fortify';
            $this->emit('phase_changed', ['seat' => $seat, 'phase' => 'fortify']);

            return;
        }

        $this->endTurn();
    }

    private function endTurnAction(): void
    {
        $this->pending = null;
        $this->endTurn();
    }

    /* ---------------------------------------------------------------- the page's rules */

    private function beginTurn(): void
    {
        $seat = $this->cur;
        $this->seats[$seat]->conquered = 0;
        $this->seats[$seat]->dip = false;
        $this->seats[$seat]->moves = 0;
        $this->fortified = false;
        $this->phase = 'buy';
        $this->placed = [];
        $this->pending = null;

        foreach ($this->shield as $t => $guard) {
            if ($guard === $seat) {
                $this->shield[$t] = -1;
            }
        }

        $mine = $this->territoriesOf($seat);
        $banks = count(array_filter($mine, fn (int $t): bool => HyperMap::BANK[$t]));
        $fiat = (float) (max(3, intdiv(count($mine), 3)) + 2 * $banks);

        if ($this->hasZone($seat, self::ZONE_DOLLAR)) {
            $fiat = self::roundHalf($fiat * 1.25);
        }

        if ($this->hasZone($seat, self::ZONE_SA)) {
            $fiat += 2;
        }

        $sats = 0.5 * count(array_filter($mine, fn (int $t): bool => HyperMap::MINE[$t]));

        foreach (HyperMap::ZONE_SATS as $zone => $zoneSats) {
            if ($this->hasZone($seat, $zone)) {
                $sats += $zoneSats;
            }
        }

        $growth = $this->seats[$seat]->sats * 0.03;
        $loot = $this->seats[$seat]->loot;
        $this->seats[$seat]->fiat = self::r2($this->seats[$seat]->fiat + $fiat);
        $this->seats[$seat]->sats = self::r1($this->seats[$seat]->sats + $sats + $growth);
        $this->seats[$seat]->loot = self::r1($this->seats[$seat]->loot + $sats + $growth);
        $free = $this->hasZone($seat, self::ZONE_AFRO) ? 2 : 0;
        $this->seats[$seat]->free += $free;
        $gained = self::r1($this->seats[$seat]->loot - $loot);

        $this->emit('turn_started', ['seat' => $seat, 'round' => $this->round, 'fiat' => $fiat, 'sats' => $gained, 'free_plebs' => $free]);

        if ($gained > 0) {
            $this->emit('loot_gained', ['seat' => $seat, 'amount' => $gained, 'reason' => 'income']);
        }

        $this->checkWin(false);
    }

    private function endTurn(): void
    {
        $seat = $this->cur;

        if ($this->seats[$seat]->fiat > 0) {
            if ($this->hasZone($seat, self::ZONE_POUND)) {
                $before = $this->seats[$seat]->fiat;
                $this->seats[$seat]->fiat = self::r2($this->seats[$seat]->fiat * 1.1);
                $this->emit('fiat_interest', ['seat' => $seat, 'amount' => self::r2($this->seats[$seat]->fiat - $before)]);
            } elseif (! $this->hasZone($seat, self::ZONE_YEN)) {
                $lost = self::r2($this->seats[$seat]->fiat * 0.15);
                $this->seats[$seat]->fiat = self::r2($this->seats[$seat]->fiat - $lost);
                $this->emit('fiat_decayed', ['seat' => $seat, 'amount' => $lost]);
            }
        }

        if ($this->seats[$seat]->conquered > 0 && count($this->seats[$seat]->hand) < self::HAND_DRAW_BELOW) {
            if ($this->deck === []) {
                $this->deck = $this->newDeck();
            }

            $card = array_shift($this->deck);
            $this->seats[$seat]->hand[] = $card;
            $this->emit('card_drawn', ['seat' => $seat, 'card' => $card]);
        }

        $this->pending = null;
        $this->emit('turn_ended', ['seat' => $seat]);
        $next = $this->cur;

        do {
            $next = ($next + 1) % count($this->seats);
        } while ($this->seats[$next]->out);

        if ($next <= $this->cur && $this->limit > 0 && $this->round >= $this->limit && $this->checkWin(true)) {
            return;
        }

        if ($next <= $this->cur) {
            $this->round++;
            $this->inflation = $this->inflationNext;
            $this->inflationNext = false;
            $this->emit('round_started', ['round' => $this->round, 'inflation' => $this->inflation]);
        }

        $this->cur = $next;
        $this->beginTurn();
    }

    /**
     * Won on the map, as in Risk: the last side standing; at the round limit the best of standings().
     */
    private function checkWin(bool $final): bool
    {
        $alive = array_values(array_filter(array_keys($this->seats), fn (int $s): bool => ! $this->seats[$s]->out));

        if (count($alive) === 1) {
            $winner = $alive[0];
        } elseif ($final && count($alive) > 1) {
            $winner = $this->standings()[0];
            $this->byLimit = true;
        } else {
            return false;
        }

        $this->over = true;
        $this->winner = $winner;
        $this->pending = null;
        $this->emit('game_won', ['seat' => $winner, 'by_limit' => $this->byLimit, 'round' => $this->round, 'standings' => $this->standings(), 'loot' => $this->loot()]);

        return true;
    }

    /**
     * One dice round: up to 3 attacking dice against up to 2; pairs compared highest first, the
     * defender wins ties. Returns the attacker's dice count and whether the defender is wiped out.
     *
     * @return array{int, bool}
     *
     * @phpstan-impure
     */
    private function battleRound(int $from, int $to): array
    {
        $an = min(3, $this->units($from) - 1);
        $dn = min(2, $this->units($to));
        $attacker = [];
        $defender = [];

        for ($k = 0; $k < $an; $k++) {
            $attacker[] = $this->rng->die();
        }

        for ($k = 0; $k < $dn; $k++) {
            $defender[] = $this->rng->die();
        }

        rsort($attacker);
        rsort($defender);
        $asic = $this->asic[$from] > 0;
        $attackerTotal = $attacker;
        $defenderTotal = $defender;

        if ($asic) {
            $attackerTotal[0]++;
        }

        $defenderTotal[0] += $this->defenseBonus($to, $asic);
        $attackerLosses = 0;
        $defenderLosses = 0;

        for ($k = 0; $k < min($an, $dn); $k++) {
            if ($attackerTotal[$k] > $defenderTotal[$k]) {
                $defenderLosses++;
            } else {
                $attackerLosses++;
            }
        }

        $this->lose($from, $attackerLosses);
        $this->lose($to, $defenderLosses);
        $this->emit('dice_rolled', ['seat' => $this->cur, 'from' => HyperMap::IDS[$from], 'to' => HyperMap::IDS[$to], 'attacker' => $attacker, 'defender' => $defender, 'attacker_total' => $attackerTotal, 'defender_total' => $defenderTotal, 'attacker_losses' => $attackerLosses, 'defender_losses' => $defenderLosses, 'attacker_units' => $this->units($from), 'defender_units' => $this->units($to)]);

        return [$an, $this->units($to) === 0];
    }

    private function conquest(int $from, int $to, int $previous, int $move): void
    {
        $seat = $this->cur;
        $this->owner[$to] = $seat;
        $this->shield[$to] = -1;
        $this->pleb[$to] = 0;
        $this->maxi[$to] = 0;
        $this->asic[$to] = 0;
        $this->moveUnits($from, $to, max(1, $move));

        if ($this->units($to) === 0) {
            $this->pleb[$to] = 1;
            $this->lose($from, 1);
        }

        $this->seats[$seat]->conquered++;
        $this->emit('territory_conquered', ['seat' => $seat, 'territory' => HyperMap::IDS[$to], 'from' => HyperMap::IDS[$from], 'previous_owner' => $previous >= 0 ? $previous : null, 'units' => $this->units($to)]);

        if (HyperMap::BANK[$to]) {
            $this->seats[$seat]->sats = self::r1($this->seats[$seat]->sats + 1);
            $this->seats[$seat]->loot = self::r1($this->seats[$seat]->loot + 1);
            $this->emit('bank_fallen', ['seat' => $seat, 'territory' => HyperMap::IDS[$to], 'zone' => HyperMap::ZONE_KEYS[HyperMap::ZONE[$to]]]);
            $this->emit('loot_gained', ['seat' => $seat, 'amount' => 1.0, 'reason' => 'bank']);
        }

        if ($this->hasZone($seat, HyperMap::ZONE[$to])) {
            $this->emit('zone_completed', ['seat' => $seat, 'zone' => HyperMap::ZONE_KEYS[HyperMap::ZONE[$to]]]);
        }

        $this->knockOutIfLandless($previous, $seat);
        $this->checkWin(false);
    }

    /**
     * A seat that lost its last territory to `by` is out; its hand goes to the conqueror (up to 7 cards).
     */
    private function knockOutIfLandless(int $previous, int $by): void
    {
        if ($previous < 0 || $this->territoriesOf($previous) !== []) {
            return;
        }

        $this->seats[$previous]->out = true;
        $cards = count($this->seats[$previous]->hand);
        $hand = array_slice([...$this->seats[$by]->hand, ...$this->seats[$previous]->hand], 0, self::HAND_MAX);
        $this->seats[$previous]->hand = [];
        $this->seats[$by]->hand = $hand;
        $this->emit('player_eliminated', ['seat' => $previous, 'by' => $by, 'cards' => $cards]);
    }

    /**
     * Moves up to n units, plebs first, then ASICs, then maxis; one unit always stays.
     */
    private function moveUnits(int $from, int $to, int $n): void
    {
        $left = min($n, $this->units($from) - 1);
        $m = min($this->pleb[$from], $left);
        $this->pleb[$from] -= $m;
        $this->pleb[$to] += $m;
        $left -= $m;
        $m = min($this->asic[$from], $left);
        $this->asic[$from] -= $m;
        $this->asic[$to] += $m;
        $left -= $m;
        $m = min($this->maxi[$from], $left);
        $this->maxi[$from] -= $m;
        $this->maxi[$to] += $m;
    }

    /**
     * Losses take plebs first, then ASICs, then maxis.
     */
    private function lose(int $t, int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            if ($this->pleb[$t] > 0) {
                $this->pleb[$t]--;
            } elseif ($this->asic[$t] > 0) {
                $this->asic[$t]--;
            } elseif ($this->maxi[$t] > 0) {
                $this->maxi[$t]--;
            }
        }
    }

    /**
     * The page's dealFair: banks, mines and plain land dealt separately in rounds of one per side, the
     * strongest remaining territory (START_VALUE) to the side with the lowest total so far; what does not
     * divide stays neutral, and so does a one-territory currency space. No space may start complete;
     * after 200 tries every territory goes round-robin to the players.
     */
    private function dealFair(int $n, int $neutralSides): void
    {
        $sides = $n + $neutralSides;
        $solo = fn (int $t): bool => count(HyperMap::ZONE_TERRITORIES[HyperMap::ZONE[$t]]) === 1;
        $classes = [
            fn (int $t): bool => HyperMap::BANK[$t],
            fn (int $t): bool => HyperMap::MINE[$t],
            fn (int $t): bool => ! HyperMap::BANK[$t] && ! HyperMap::MINE[$t],
        ];

        for ($attempt = 0; $attempt < 200; $attempt++) {
            $this->resetBoard();
            $totals = array_fill(0, $sides, 0.0);

            foreach (range(0, HyperMap::COUNT - 1) as $t) {
                if ($solo($t)) {
                    $this->pleb[$t] = 2;
                }
            }

            foreach ($classes as $class) {
                $list = $this->rng->shuffle(array_values(array_filter(range(0, HyperMap::COUNT - 1), fn (int $t): bool => ! $solo($t) && $class($t))));
                usort($list, fn (int $a, int $b): int => HyperMap::START_VALUE[$b] <=> HyperMap::START_VALUE[$a]);
                $even = count($list) - count($list) % $sides;

                foreach (array_slice($list, $even) as $t) {
                    $this->owner[$t] = self::NEUTRAL;
                    $this->pleb[$t] = 2;
                }

                for ($k = 0; $k < $even; $k += $sides) {
                    $order = $this->rng->shuffle(range(0, $sides - 1));
                    usort($order, fn (int $a, int $b): int => $totals[$a] <=> $totals[$b]);

                    foreach (array_slice($list, $k, $sides) as $j => $t) {
                        $side = $order[$j];
                        $totals[$side] += HyperMap::START_VALUE[$t];
                        $this->owner[$t] = self::sideTag($side, $n);
                        $this->pleb[$t] = 1;
                    }
                }
            }

            if (! $this->anyZoneComplete()) {
                return;
            }
        }

        $this->resetBoard();

        foreach ($this->rng->shuffle(range(0, HyperMap::COUNT - 1)) as $i => $t) {
            $this->owner[$t] = $i % $n;
            $this->pleb[$t] = 1;
        }
    }

    private function resetBoard(): void
    {
        $this->owner = array_fill(0, HyperMap::COUNT, self::NEUTRAL);
        $this->pleb = array_fill(0, HyperMap::COUNT, 0);
        $this->maxi = array_fill(0, HyperMap::COUNT, 0);
        $this->asic = array_fill(0, HyperMap::COUNT, 0);
        $this->shield = array_fill(0, HyperMap::COUNT, -1);
    }

    private function anyZoneComplete(): bool
    {
        foreach (array_keys(HyperMap::ZONE_TERRITORIES) as $zone) {
            if ($this->zoneOwner($zone) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Seats keep their index; the duel's neutral side is tagged below -1 until the deal is done.
     */
    private static function sideTag(int $side, int $n): int
    {
        return $side < $n ? $side : -1 - $side;
    }

    /**
     * @return list<string> every card twice, shuffled
     */
    private function newDeck(): array
    {
        $deck = [];

        foreach (array_keys(self::CARDS) as $card) {
            $deck[] = $card;
            $deck[] = $card;
        }

        return $this->rng->shuffle($deck);
    }

    private function bordersSeat(int $t, int $seat): bool
    {
        foreach (HyperMap::ADJ[$t] as $n) {
            if ($this->owner[$n] === $seat) {
                return true;
            }
        }

        return false;
    }

    private function requirePhase(string $phase): void
    {
        if ($this->phase !== $phase) {
            throw new HyperRuleViolation('wrong_phase', "This needs the {$phase} phase, the turn is in {$this->phase}.");
        }
    }

    private function ownTerritory(mixed $id): int
    {
        $t = self::territoryIndex($id);

        if ($this->owner[$t] !== $this->cur) {
            throw new HyperRuleViolation('not_your_territory', HyperMap::IDS[$t].' is not yours.');
        }

        return $t;
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function emit(string $type, array $fields): void
    {
        $this->events[] = ['type' => $type, ...$fields];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function flush(): array
    {
        $events = $this->events;
        $this->events = [];

        return $events;
    }

    /**
     * Rounds half away from zero like Rust's f64::round, without PHP round()'s pre-rounding.
     */
    private static function roundHalf(float $x): float
    {
        $a = abs($x);
        $r = floor($a);

        if ($a - $r >= 0.5) {
            $r += 1.0;
        }

        return $x < 0 ? -$r : $r;
    }

    private static function r1(float $x): float
    {
        return self::roundHalf($x * 10.0) / 10.0;
    }

    private static function r2(float $x): float
    {
        return self::roundHalf($x * 100.0) / 100.0;
    }
}
