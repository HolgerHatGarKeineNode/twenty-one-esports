<?php

use App\Support\Hyper\HyperBot;
use App\Support\Hyper\HyperGame;
use App\Support\Hyper\HyperMap;
use App\Support\Hyper\HyperRuleViolation;

/**
 * A hand-made Hyperbitcoinization position: three human seats (bitcoiner, fed, ezb), every territory
 * neutral with one pleb, seat 0 to move in the buy phase with no fiat, sats or cards. `$edit` changes
 * it: `territories` => [id => [owner|null, pleb, maxi = 0, asic = 0]], `seats` => [index => fields of
 * HyperGame::toArray()'s seats], and any top-level field (phase, seat, round, limit, inflation, ...).
 *
 * @param  array<string, mixed>  $edit
 */
function hyperPosition(array $edit = []): HyperGame
{
    $state = HyperGame::start([['faction' => 'bitcoiner'], ['faction' => 'fed'], ['faction' => 'ezb']], 0, 1)->game->toArray();

    foreach (HyperMap::IDS as $id) {
        $state['territories'][$id] = ['owner' => null, 'pleb' => 1, 'maxi' => 0, 'asic' => 0, 'shield' => null];
    }

    foreach ($state['seats'] as $i => $seat) {
        $state['seats'][$i] = [...$seat, 'fiat' => 0.0, 'sats' => 0.0, 'loot' => 0.0, 'hand' => [], 'free_plebs' => 0, 'yuan' => 0];
    }

    foreach ($edit['territories'] ?? [] as $id => $row) {
        $state['territories'][$id] = ['owner' => $row[0], 'pleb' => $row[1], 'maxi' => $row[2] ?? 0, 'asic' => $row[3] ?? 0, 'shield' => $row[4] ?? null];
    }

    foreach ($edit['seats'] ?? [] as $i => $fields) {
        $state['seats'][$i] = [...$state['seats'][$i], ...$fields];
    }

    unset($edit['territories'], $edit['seats']);

    return HyperGame::fromArray([...$state, 'placed' => [], 'pending_move' => null, ...$edit]);
}

/**
 * Every territory of a currency space for one owner.
 *
 * @return array<string, array{int|null, int}>
 */
function hyperZone(string $zone, ?int $owner, int $pleb = 1): array
{
    $index = array_search($zone, HyperMap::ZONE_KEYS, true);

    return array_fill_keys(array_map(fn (int $t): string => HyperMap::IDS[$t], HyperMap::ZONE_TERRITORIES[$index]), [$owner, $pleb]);
}

/**
 * The reason code an action is refused with, or null when it goes through.
 */
function hyperRefusal(callable $action): ?string
{
    try {
        $action();
    } catch (HyperRuleViolation $violation) {
        return $violation->reason;
    }

    return null;
}

/**
 * @param  list<array<string, mixed>>  $events
 * @return list<array<string, mixed>>
 */
function hyperEvents(array $events, string $type): array
{
    return array_values(array_filter($events, fn (array $event): bool => $event['type'] === $type));
}

/**
 * @return array{owner: int|null, pleb: int, maxi: int, asic: int, shield: int|null}
 */
function hyperTerritory(HyperGame $game, string $id): array
{
    return $game->toArray()['territories'][$id];
}

/**
 * One line of tools/hbsim's `parity` mode for the same game: a whole bot game from the page's setup,
 * fingerprinted by FNV-1a over the last board and the loot per seat.
 */
function hyperParityLine(int $seed, int $players, int $limit): string
{
    $seats = array_map(fn (string $faction): array => ['faction' => $faction, 'bot' => true], array_slice(array_keys(HyperGame::FACTIONS), 0, $players));
    $game = HyperBot::playGame(HyperGame::start($seats, $limit, $seed)->game)->game;
    $fnv = function (string $text): int {
        $hash = 0x811C9DC5;

        foreach (str_split($text) as $char) {
            $hash = (($hash ^ ord($char)) * 0x01000193) & 0xFFFFFFFF;
        }

        return $hash;
    };
    $board = implode('', array_map(fn (array $t): string => ($t['owner'] ?? -1).",{$t['pleb']},{$t['maxi']},{$t['asic']};", $game->toArray()['territories']));
    $loot = implode('', array_map(fn (float $loot): string => (int) round($loot * 10).';', $game->loot()));

    return sprintf('%d %d %d %d %d %d %08x %08x', $seed, $players, $limit, $game->isOver() ? $game->winner() : -1, $game->round(), $game->wonByLimit() ? 1 : 0, $fnv($board), $fnv($loot));
}
