<?php

namespace App\Support\Hyper;

use App\Models\HyperMatch;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * The end-of-match statistics of a finished Hyperbitcoinization match (plan "Hyperbitcoinization", P3, approach
 * point 8, "Nachspiel-Statistik"): computed from the replay (seed and action log through HyperReplay), never from
 * stored aggregates, so every number can be checked against the action list.
 *
 * - `series`: per value (territories, units, banks, fiat, loot) one list per seat over x = 0..rounds. x = 0 is
 *   the table after the setup; x = r the table when round r ended (sampled after the ply that starts round r + 1,
 *   so the first seat's income of that round is in it), the last x the table at the end.
 * - `leaders`: most battles (attack actions that rolled dice), biggest conquest run (territories taken in one
 *   turn, a 51%-Attacke included), longest defence (attacks a territory repelled for the same holder), most loot
 *   (sats collected), unluckiest roller (own pips minus 3.5 per die, attacking and defending). A board without an
 *   entry has `seat` null.
 * - `turning_points`: a central bank taken from a player, a currency space completed, a player knocked out.
 * - `moments`: the scenes after the charts, each once with its first occurrence. Pride: `finale` (the winner's
 *   faction finale), `first_bank`, `zone`, `comeback` (the winner held land in one currency space only, after
 *   round 1), `perfect_dice` (three sixes on attack), `knockout`. Meme, from the event cards: `lost_keys` (Lost
 *   Keys hits the richest), `salvador_lost` (the El Salvador coin flip lost), `pizza` (Pizza Day), `attack51`,
 *   and `bitcoin_dead` when the Nocoiner wins.
 *
 * A finished match never changes, so the result is cached per match (not stored); VERSION is in the key, a
 * change to the computation bumps it.
 */
final class HyperStats
{
    public const int VERSION = 1;

    private const int CACHE_DAYS = 30;

    /** @var list<string> */
    public const array SERIES = ['territories', 'units', 'banks', 'fiat', 'loot'];

    /** @var list<string> the moment keys, pride first, in the order the scenes play */
    public const array MOMENTS = ['finale', 'first_bank', 'zone', 'comeback', 'perfect_dice', 'knockout', 'lost_keys', 'salvador_lost', 'pizza', 'attack51', 'bitcoin_dead'];

    /**
     * The statistics of a finished match, cached.
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException when the match is not finished or its log does not replay
     */
    public static function of(HyperMatch $match): array
    {
        if ($match->isActive()) {
            throw new RuntimeException('A running match has no statistics yet.');
        }

        return Cache::remember('hyper:stats:v'.self::VERSION.':'.$match->ulid, now()->addDays(self::CACHE_DAYS), fn (): array => self::compute($match));
    }

    /**
     * @return array<string, mixed>
     *
     * @throws RuntimeException when the log does not replay
     */
    public static function compute(HyperMatch $match): array
    {
        $collector = new self;
        $replay = new HyperReplay($match, null, $collector->observe(...));

        return $collector->result($replay->game());
    }

    /** @var array<string, array<int, array<int, float|int>>> value => seat => x => value */
    private array $series = [];

    private int $round = 1;

    /** @var array<int, int> attack actions per seat */
    private array $battles = [];

    /** @var array<int, int> conquests per seat in its current turn */
    private array $run = [];

    /** @var array{seat: int|null, value: int, round: int} */
    private array $bestRun = ['seat' => null, 'value' => 0, 'round' => 0];

    /** @var array<string, int> repelled attacks by "territory|holder" */
    private array $repelled = [];

    /** @var array<int, float> pips minus expectation per seat */
    private array $luck = [];

    /** @var array<int, int> dice rolled per seat */
    private array $dice = [];

    /** @var list<array<string, mixed>> */
    private array $turningPoints = [];

    /** @var array<string, array<string, mixed>> */
    private array $moments = [];

    /** @var array<int, array{round: int, zone: string}> the first time a seat held land in one currency space only */
    private array $lastRegion = [];

    /**
     * One replayed ply (HyperReplay's observer): the game before it (null for the setup), after it, and its events.
     * Fed in order and closed with result(); compute() does both for a stored match.
     *
     * @param  array{ply: int, seat: int, source: string, events: list<array<string, mixed>>}  $ply
     */
    public function observe(?HyperGame $before, HyperGame $after, array $ply): void
    {
        if ($before === null) {
            $this->round = $after->round();
            $this->sample($after, 0);

            return;
        }

        $events = $ply['events'];
        $previous = [];
        $attack = null;

        foreach ($events as $i => $event) {
            $seat = isset($event['seat']) && is_int($event['seat']) ? $event['seat'] : null;

            switch ($event['type']) {
                case 'round_started':
                    $this->sample($after, $this->round);
                    $this->round = (int) $event['round'];
                    break;
                case 'turn_started':
                    $this->run[(int) $seat] = 0;
                    break;
                case 'dice_rolled':
                    $to = HyperGame::territoryIndex($event['to']);
                    $attack ??= $to;
                    $this->roll((int) $seat, (array) $event['attacker']);
                    $defender = $before->ownerOf($to);

                    if ($defender !== null) {
                        $this->roll($defender, (array) $event['defender']);
                    }

                    if ($event['attacker'] === [6, 6, 6]) {
                        $this->moment('perfect_dice', ['seat' => $seat, 'territory' => $event['to']]);
                    }
                    break;
                case 'territory_conquered':
                    $previous[$event['territory']] = $event['previous_owner'];
                    $this->conquered((int) $seat);
                    break;
                case 'bank_fallen':
                    $loser = $previous[$event['territory']] ?? null;
                    $this->moment('first_bank', ['seat' => $seat, 'territory' => $event['territory'], 'loser' => $loser]);

                    if ($loser !== null) {
                        $this->turningPoints[] = ['round' => $this->round, 'type' => 'bank_fallen', 'seat' => $seat, 'loser' => $loser, 'territory' => $event['territory']];
                    }
                    break;
                case 'zone_completed':
                    $this->moment('zone', ['seat' => $seat, 'zone' => $event['zone']]);
                    $this->turningPoints[] = ['round' => $this->round, 'type' => 'zone_completed', 'seat' => $seat, 'zone' => $event['zone']];
                    break;
                case 'player_eliminated':
                    $this->moment('knockout', ['seat' => $event['by'], 'victim' => $seat]);
                    $this->turningPoints[] = ['round' => $this->round, 'type' => 'player_eliminated', 'seat' => $event['by'], 'loser' => $seat];
                    break;
                case 'card_played':
                    $this->card($event, $events[$i + 1] ?? null);
                    break;
            }
        }

        if ($attack !== null) {
            $this->battles[$ply['seat']] = ($this->battles[$ply['seat']] ?? 0) + 1;
            $holder = $before->ownerOf($attack);

            if ($holder !== null && $after->ownerOf($attack) === $holder) {
                $key = HyperMap::IDS[$attack].'|'.$holder;
                $this->repelled[$key] = ($this->repelled[$key] ?? 0) + 1;
            }
        }

        if ($this->round > 1) {
            $this->regions($after);
        }
    }

    /**
     * @param  array<string, mixed>  $event
     * @param  array<string, mixed>|null  $next
     */
    private function card(array $event, ?array $next): void
    {
        $seat = $event['seat'];
        $effect = (array) ($event['effect'] ?? []);

        match ($event['card']) {
            'keys' => isset($effect['victim']) ? $this->moment('lost_keys', ['seat' => $seat, 'victim' => $effect['victim'], 'sats' => $effect['sats_lost'] ?? 0]) : null,
            'salvador' => ($effect['up'] ?? null) === false ? $this->moment('salvador_lost', ['seat' => $seat]) : null,
            'pizza' => $this->moment('pizza', ['seat' => $seat]),
            'attack51' => $this->moment('attack51', ['seat' => $seat, 'territory' => $event['target']]),
            default => null,
        };

        // A 51%-Attacke from a lone unit takes the territory without a conquest event.
        if ($event['card'] === 'attack51' && ! (($next['type'] ?? null) === 'territory_conquered' && $next['territory'] === $event['target'])) {
            $this->conquered((int) $seat);
        }
    }

    private function conquered(int $seat): void
    {
        $this->run[$seat] = ($this->run[$seat] ?? 0) + 1;

        if ($this->run[$seat] > $this->bestRun['value']) {
            $this->bestRun = ['seat' => $seat, 'value' => $this->run[$seat], 'round' => $this->round];
        }
    }

    /**
     * @param  array<mixed>  $pips
     */
    private function roll(int $seat, array $pips): void
    {
        $this->luck[$seat] = ($this->luck[$seat] ?? 0.0) + array_sum($pips) - 3.5 * count($pips);
        $this->dice[$seat] = ($this->dice[$seat] ?? 0) + count($pips);
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private function moment(string $key, array $detail): void
    {
        $this->moments[$key] ??= ['key' => $key, 'round' => $this->round, ...$detail];
    }

    private function regions(HyperGame $game): void
    {
        for ($seat = 0; $seat < $game->seatCount(); $seat++) {
            if (isset($this->lastRegion[$seat])) {
                continue;
            }

            $zones = array_unique(array_map(fn (int $t): int => HyperMap::ZONE[$t], $game->territoriesOf($seat)));

            if (count($zones) === 1) {
                $this->lastRegion[$seat] = ['round' => $this->round, 'zone' => HyperMap::ZONE_KEYS[array_values($zones)[0]]];
            }
        }
    }

    private function sample(HyperGame $game, int $x): void
    {
        for ($seat = 0; $seat < $game->seatCount(); $seat++) {
            $purse = $game->seatAt($seat);
            $this->series['territories'][$seat][$x] = count($game->territoriesOf($seat));
            $this->series['units'][$seat][$x] = $game->unitsOf($seat);
            $this->series['banks'][$seat][$x] = $game->banksOf($seat);
            $this->series['fiat'][$seat][$x] = $purse['fiat'];
            $this->series['loot'][$seat][$x] = $purse['loot'];
        }
    }

    /**
     * The statistics after the last ply; `game` is the table at the end.
     *
     * @return array<string, mixed>
     */
    public function result(HyperGame $game): array
    {
        $this->sample($game, $this->round);
        $winner = $game->winner();
        $seats = range(0, $game->seatCount() - 1);

        if ($winner !== null) {
            $this->moments['finale'] = ['key' => 'finale', 'round' => $this->round, 'seat' => $winner, 'faction' => $game->seatAt($winner)['faction'], 'by_limit' => $game->wonByLimit()];

            if (isset($this->lastRegion[$winner])) {
                $this->moments['comeback'] = ['key' => 'comeback', 'seat' => $winner, ...$this->lastRegion[$winner]];
            }

            if ($game->seatAt($winner)['faction'] === 'nocoiner') {
                $this->moments['bitcoin_dead'] = ['key' => 'bitcoin_dead', 'round' => $this->round, 'seat' => $winner];
            }
        }

        $defence = ['seat' => null, 'value' => 0, 'territory' => null];

        foreach ($this->repelled as $key => $count) {
            if ($count > $defence['value']) {
                [$territory, $holder] = explode('|', $key);
                $defence = ['seat' => (int) $holder, 'value' => $count, 'territory' => $territory];
            }
        }

        $loot = $game->loot();
        $unlucky = ['seat' => null, 'value' => 0.0, 'dice' => 0];

        foreach ($this->luck as $seat => $balance) {
            if ($unlucky['seat'] === null || $balance < $unlucky['value']) {
                $unlucky = ['seat' => $seat, 'value' => round($balance, 1), 'dice' => $this->dice[$seat]];
            }
        }

        $series = [];

        foreach (self::SERIES as $name) {
            $series[$name] = array_map(fn (int $seat): array => array_values($this->series[$name][$seat]), $seats);
        }

        return [
            'version' => self::VERSION,
            'rounds' => $this->round,
            'winner' => $winner,
            'by_limit' => $game->wonByLimit(),
            'factions' => array_map(fn (int $seat): string => $game->seatAt($seat)['faction'], $seats),
            'series' => $series,
            'leaders' => [
                'battles' => $this->top(array_map(fn (int $seat): int => $this->battles[$seat] ?? 0, $seats)),
                'conquest_run' => $this->bestRun,
                'defence' => $defence,
                'loot' => $this->top($loot),
                'unlucky' => $unlucky,
            ],
            'turning_points' => $this->turningPoints,
            'moments' => array_values(array_filter(array_map(fn (string $key): ?array => $this->moments[$key] ?? null, self::MOMENTS))),
        ];
    }

    /**
     * The seat with the highest value (the first of equals), null when nobody has any.
     *
     * @param  list<int|float>  $values
     * @return array{seat: int|null, value: int|float, all: list<int|float>}
     */
    private function top(array $values): array
    {
        $best = null;

        foreach ($values as $seat => $value) {
            if ($value > 0 && ($best === null || $value > $values[$best])) {
                $best = $seat;
            }
        }

        return ['seat' => $best, 'value' => $best === null ? 0 : $values[$best], 'all' => $values];
    }
}
