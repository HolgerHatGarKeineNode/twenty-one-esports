<?php

namespace App\Support\Tournaments;

use App\Enums\TournamentFormat;
use App\Models\Tournament;
use App\Support\GameNames;
use App\Support\LobbyWords;
use App\Support\Pages\RulesPage;
use Carbon\CarbonImmutable;
use Illuminate\Container\Container;

/**
 * Lobby tournaments (plan "AoE2 und Trackmania", P10, user 2026-10-01: "Es
 * soll nur ein einziges Match sein, weil man AoE2 in einem Match mit vielen
 * Spielern gegeneinander spielen kann"): a game with a `lobby` block in
 * `esports.series.lobby_rules.<game>` plays its tournaments only as Free for
 * All in one round. Every entry plays one match in a lobby of at most
 * `max_players`; the draw splits the entries evenly over the fewest lobbies
 * (9 = 5 + 4, 17 = 6 + 6 + 5) and nobody advances (BracketBuilder, with
 * FormatOptions::$lobbyMinutes set).
 *
 * The lobby's settings follow its player count (the map size) and are fixed
 * with the lobby at the draw ({@see settings()}, stored on the match with a
 * league-made name and password, TournamentBrackets). Every sentence about
 * the format lives here, so the chooser, /rules, the game page, the
 * tournament page and the calendar event say the same thing.
 *
 * @phpstan-type Settings array{players: int, map: string, map_size: string, civilizations: string, population: int, lock_teams: bool, allied_victory: bool, victory: string, time_limit_minutes: int, spectator_delay_minutes: int, restarts: int, restart_minutes: int}
 */
final class Lobbies
{
    /**
     * The game's lobby block, or null for a game whose tournaments are not lobbies.
     *
     * @return array<string, mixed>|null
     */
    public static function config(string $game): ?array
    {
        // The estimator's unit tests run without the framework: no config, no lobby game.
        if (! Container::getInstance()->bound('config')) {
            return null;
        }

        $lobby = config("esports.series.lobby_rules.{$game}.lobby");

        return is_array($lobby) ? $lobby : null;
    }

    public static function isLobbyGame(string $game): bool
    {
        return self::config($game) !== null;
    }

    /**
     * A tournament played as lobbies: a lobby game in Free for All. A lobby
     * game's tournament in another format (one created before P10 and not
     * switched yet) runs as it was drawn.
     */
    public static function isLobby(Tournament $tournament): bool
    {
        return $tournament->format === TournamentFormat::FreeForAll && self::isLobbyGame($tournament->game);
    }

    /**
     * The format a tournament's chips and lines name: "Swiss"; a lobby
     * tournament "One lobby match", as the stream slides say it.
     */
    public static function formatLabel(Tournament $tournament): string
    {
        return self::isLobby($tournament) ? __('One lobby match') : $tournament->format->label();
    }

    /**
     * Game and mode, "Chess Blitz 5+3"; a lobby tournament names its game
     * alone: up to 8 players share one match, so "1v1" would promise a duel.
     */
    public static function gameLine(Tournament $tournament): string
    {
        return self::isLobby($tournament) ? GameNames::game($tournament->game) : GameNames::full($tournament->game, $tournament->mode);
    }

    /** The mode alone ("Blitz 5+3"), null for a lobby tournament (see {@see gameLine()}). */
    public static function modeLabel(Tournament $tournament): ?string
    {
        return self::isLobby($tournament) ? null : GameNames::mode($tournament->game, $tournament->mode);
    }

    /** Most players in one lobby (Age of Empires II: 8). */
    public static function maxPlayers(string $game): int
    {
        return max(2, (int) (self::config($game)['max_players'] ?? 8));
    }

    /** Fewest entries a lobby tournament plays with; fewer at the close call it off. */
    public static function minEntries(string $game): int
    {
        return max(2, (int) (self::config($game)['min_entries'] ?? 3));
    }

    /**
     * Entries a tournament needs at the close: a lobby tournament's minimum,
     * else the two of any other.
     */
    public static function minEntriesOf(Tournament $tournament): int
    {
        return self::isLobby($tournament) ? self::minEntries($tournament->game) : 2;
    }

    public static function timeLimit(string $game): int
    {
        return max(1, (int) (self::config($game)['time_limit_minutes'] ?? 120));
    }

    /** How long one lobby is planned: filling the lobby, then the game to its time limit. */
    public static function plannedMinutes(string $game): int
    {
        return max(0, (int) (self::config($game)['setup_minutes'] ?? 15)) + self::timeLimit($game);
    }

    /** The most places a casual cup of the game grows to (5 lobbies of 8 by default). */
    public static function cupCapacity(string $game): int
    {
        return max(self::minEntries($game), (int) (self::config($game)['cup_capacity'] ?? 40));
    }

    /**
     * The sizes a casual cup of the game grows through, smallest first
     * (user 2026-10-01: "Ich dachte wir grenzen immer anfangs ein und
     * stocken dann immer nach Anmeldungen auf"): `cup_sizes` from the lobby
     * minimum up, ending at {@see cupCapacity()}. Above 8 each step is one
     * full lobby: 4, 8, 16, 24, 32, 40.
     *
     * @return non-empty-list<int>
     */
    public static function cupSizes(string $game): array
    {
        $capacity = self::cupCapacity($game);
        $sizes = array_filter(array_map(intval(...), (array) (self::config($game)['cup_sizes'] ?? [4, 8, 16, 24, 32, 40])),
            fn (int $size): bool => $size >= self::minEntries($game) && $size < $capacity);
        $sizes = array_values(array_unique($sizes));
        sort($sizes);

        return [...$sizes, $capacity];
    }

    /**
     * The places a casual cup of the game needs with `$signedUp` in: the
     * first size above them (0 or 1 in: 4, 9 in: 16), at most
     * {@see cupCapacity()}, never fewer than are in.
     */
    public static function cupSizeFor(string $game, int $signedUp): int
    {
        foreach (self::cupSizes($game) as $size) {
            if ($size > $signedUp) {
                return $size;
            }
        }

        return max(self::cupCapacity($game), $signedUp);
    }

    /**
     * The format options a lobby tournament of the game runs with: one round
     * of lobbies of `max_players`, planned at {@see plannedMinutes()}.
     *
     * @return array{heatSize: int, heatAdvance: int, lobbyMinutes: int, bestOf: int, finalBestOf: int}
     */
    public static function options(string $game): array
    {
        return ['heatSize' => self::maxPlayers($game), 'heatAdvance' => 1, 'lobbyMinutes' => self::plannedMinutes($game), 'bestOf' => 1, 'finalBestOf' => 1];
    }

    /**
     * The lobby sizes the draw makes of `$entries`: the fewest lobbies of at
     * most `max_players`, as even as they come (the engine's snake order
     * fills them).
     *
     * @return list<int>
     */
    public static function split(string $game, int $entries): array
    {
        if ($entries <= 0) {
            return [];
        }

        $lobbies = (int) ceil($entries / self::maxPlayers($game));
        $sizes = array_fill(0, $lobbies, intdiv($entries, $lobbies));

        for ($i = 0; $i < $entries % $lobbies; $i++) {
            $sizes[$i]++;
        }

        return array_values($sizes);
    }

    /** The map size for a lobby of `$players` (Age of Empires II: 2 Tiny … 7–8 Large). */
    public static function mapSize(string $game, int $players): string
    {
        $sizes = (array) (self::config($game)['map_sizes'] ?? []);
        ksort($sizes);
        $size = '';

        // The size of the largest count the lobby reaches: a count without its own entry takes the one below.
        foreach ($sizes as $count => $name) {
            if ((int) $count <= $players) {
                $size = (string) $name;
            }
        }

        return $size !== '' ? $size : (string) (reset($sizes) ?: '');
    }

    /**
     * The settings of one lobby of `$players`, as fixed at the draw.
     *
     * @return Settings
     */
    public static function settings(string $game, int $players): array
    {
        $lobby = self::config($game) ?? [];

        return [
            'players' => $players,
            'map' => (string) ($lobby['map'] ?? ''),
            'map_size' => self::mapSize($game, $players),
            'civilizations' => (string) ($lobby['civilizations'] ?? 'free'),
            'population' => (int) ($lobby['population'] ?? 200),
            'lock_teams' => (bool) ($lobby['lock_teams'] ?? false),
            'allied_victory' => (bool) ($lobby['allied_victory'] ?? true),
            'victory' => (string) ($lobby['victory'] ?? 'time-limit'),
            'time_limit_minutes' => self::timeLimit($game),
            'spectator_delay_minutes' => (int) ($lobby['spectator_delay_minutes'] ?? 0),
            'restarts' => (int) ($lobby['restarts'] ?? 1),
            'restart_minutes' => (int) ($lobby['restart_minutes'] ?? 0),
        ];
    }

    /**
     * The settings as label and value, in the viewer's language; the values
     * are the in-game names (Arabia, Normal), translated where the game
     * translates them.
     *
     * @param  array<string, mixed>  $settings
     * @return list<array{0: string, 1: string}>
     */
    public static function facts(array $settings): array
    {
        $onOff = fn (bool $on): string => $on ? __('On') : __('Off');

        return [
            [__('Map'), (string) ($settings['map'] ?? '')],
            [__('Map size'), __((string) ($settings['map_size'] ?? ''))],
            [__('Civilisations'), ($settings['civilizations'] ?? 'free') === 'free' ? __('free pick') : (string) $settings['civilizations']],
            [__('Population'), (string) (int) ($settings['population'] ?? 200)],
            [__('Lock Teams'), $onOff((bool) ($settings['lock_teams'] ?? false))],
            [__('Allied Victory'), $onOff((bool) ($settings['allied_victory'] ?? true))],
            [__('Victory'), __('Time Limit, :time', ['time' => RulesPage::minutes((int) ($settings['time_limit_minutes'] ?? 120))])],
            [__('Spectator delay'), RulesPage::minutes((int) ($settings['spectator_delay_minutes'] ?? 0))],
            [__('Restart'), trans_choice('once in the first :time|up to :count times in the first :time', (int) ($settings['restarts'] ?? 1), ['time' => RulesPage::minutes((int) ($settings['restart_minutes'] ?? 0))])],
        ];
    }

    /**
     * "2 Tiny, 3 Small, 4 Medium, 5–6 Normal, 7–8 Large": the map sizes by
     * player count, neighbouring counts of one size joined.
     */
    public static function mapSizesLine(string $game): string
    {
        $sizes = (array) (self::config($game)['map_sizes'] ?? []);
        ksort($sizes);
        $runs = [];

        foreach ($sizes as $count => $name) {
            $last = array_key_last($runs);

            if ($last !== null && $runs[$last]['name'] === $name && $runs[$last]['to'] === (int) $count - 1) {
                $runs[$last]['to'] = (int) $count;
            } else {
                $runs[] = ['from' => (int) $count, 'to' => (int) $count, 'name' => (string) $name];
            }
        }

        return implode(', ', array_map(fn (array $run): string => ($run['from'] === $run['to'] ? $run['from'] : $run['from'].'–'.$run['to']).' '.__($run['name']), $runs));
    }

    /**
     * How a lobby tournament is played, as short sentences for /rules, the
     * game page and the tournament page.
     *
     * @return list<string>
     */
    public static function rules(string $game): array
    {
        $lobby = self::config($game);

        if ($lobby === null) {
            return [];
        }

        $max = self::maxPlayers($game);

        return [
            __('Tournaments are one lobby match: every player is in one lobby of :min to :max, split evenly at the draw (9 players: 5 and 4). Each lobby plays one game; nobody moves on.', ['min' => min(self::minEntries($game), $max), 'max' => $max]),
            __('A diplomacy game: everyone starts alone, Lock Teams off, Allied Victory on. Alliances can be made and broken during the game.'),
            __('Victory: Time Limit, :time. Allies still standing when it ends, or when everyone else is defeated, share place 1; everyone else ranks by the order they were defeated.', ['time' => RulesPage::minutes(self::timeLimit($game))]),
            __('Map :map, any civilisation, population :population. Map size by players: :sizes.', ['map' => (string) ($lobby['map'] ?? ''), 'population' => (int) ($lobby['population'] ?? 200), 'sizes' => self::mapSizesLine($game)]),
            __('The league names each lobby and sets its password at the draw; both show on the tournament page to the lobby\'s players only.'),
            __('One player reports the places with a screenshot of the end screen, within :time after the time limit; a tournament director confirms. A shared place 1 splits its prize equally.', ['time' => RulesPage::minutes((int) ($lobby['report_minutes'] ?? 60))]),
        ];
    }

    /**
     * The format in a few English words for the stream (P10): "one 2 h
     * diplomacy lobby, 3 to 8, wins shared".
     */
    public static function pitch(string $game): string
    {
        $limit = self::timeLimit($game);

        return 'one '.($limit % 60 === 0 ? intdiv($limit, 60).' h' : $limit.' min').' diplomacy lobby, '
            .min(self::minEntries($game), self::maxPlayers($game)).' to '.self::maxPlayers($game).', wins shared';
    }

    /** The league's name of a lobby: "21-hodl-42-2" (tournament 42, lobby 2), see {@see LobbyWords}. */
    public static function name(Tournament $tournament, int $position): string
    {
        return LobbyWords::lobbyName($tournament->id, $position);
    }

    /** A fresh password: "mempool-halving-42", two Bitcoin words and two digits. */
    public static function password(): string
    {
        return LobbyWords::password();
    }

    /**
     * Until when the players report a lobby: the later of the start and the
     * draw, plus the lobby's set-up, the time limit and `report_minutes`.
     * After it only a director decides the lobby.
     */
    public static function reportBy(Tournament $tournament, CarbonImmutable $drawnAt): CarbonImmutable
    {
        $lobby = self::config($tournament->game) ?? [];
        $start = $drawnAt->max($tournament->starts_at->toImmutable());

        return $start->addMinutes(self::plannedMinutes($tournament->game) + max(0, (int) ($lobby['report_minutes'] ?? 60)));
    }
}
