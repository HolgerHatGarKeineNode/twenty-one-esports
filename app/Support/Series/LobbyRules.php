<?php

namespace App\Support\Series;

use App\Support\Pages\RulesPage;

/**
 * The league's defaults for the lobby a host creates in the game
 * (`esports.series.lobby_rules`, plan "AoE2 und Trackmania", P9): for now
 * Age of Empires II only, the same for a casual 1v1 and a clan series.
 * Every sentence of them lives here, so /rules, the game page, the match
 * room and the lobby card say the same thing. A game without an entry has
 * none (empty lists, empty line).
 *
 * @phpstan-type Rules array{map: string, civilizations: string, spectator_delay_minutes: int, restart_minutes: int}
 */
final class LobbyRules
{
    /**
     * @return Rules|null
     */
    public static function for(string $game): ?array
    {
        $rules = config('esports.series.lobby_rules.'.$game);

        if (! is_array($rules)) {
            return null;
        }

        return [
            'map' => (string) ($rules['map'] ?? ''),
            'civilizations' => (string) ($rules['civilizations'] ?? 'free'),
            'spectator_delay_minutes' => (int) ($rules['spectator_delay_minutes'] ?? 0),
            'restart_minutes' => (int) ($rules['restart_minutes'] ?? 0),
        ];
    }

    /**
     * The numbers at a glance: label and value.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function facts(string $game): array
    {
        $rules = self::for($game);

        if ($rules === null) {
            return [];
        }

        return [
            [__('Map'), $rules['map']],
            [__('Civilisations'), self::civilizations($rules)],
            [__('Spectator delay'), RulesPage::minutes($rules['spectator_delay_minutes'])],
            [__('Restart after a disconnect'), __('in the first :time', ['time' => RulesPage::minutes($rules['restart_minutes'])])],
        ];
    }

    /**
     * The rules as short sentences.
     *
     * @return list<string>
     */
    public static function items(string $game): array
    {
        $rules = self::for($game);

        if ($rules === null) {
            return [];
        }

        return [
            $rules['civilizations'] === 'free'
                ? __('Map :map. Each player picks any civilisation.', ['map' => $rules['map']])
                : __('Map :map. Civilisations: :civilizations.', ['map' => $rules['map'], 'civilizations' => $rules['civilizations']]),
            __('Spectators are allowed, with a delay of :time. Watching your own match from a second account is a dispute.', ['time' => RulesPage::minutes($rules['spectator_delay_minutes'])]),
            __('A player disconnects in the first :time of a game: restart it once, with the same civilisations and colours.', ['time' => RulesPage::minutes($rules['restart_minutes'])]),
            __('A later disconnect loses the game, unless both agree to restart.'),
        ];
    }

    /**
     * One short line, sent with the host's lobby card (in English: the
     * card's text is English for every client, like the rest of it).
     */
    public static function line(string $game, ?string $locale = null): string
    {
        $rules = self::for($game);

        if ($rules === null) {
            return '';
        }

        return __('League rules: map :map, :civilizations, spectators delayed by :delay, one restart after a disconnect in the first :restart, a later disconnect loses.', [
            'map' => $rules['map'],
            'civilizations' => $rules['civilizations'] === 'free' ? __('any civilisation', [], $locale) : $rules['civilizations'],
            'delay' => RulesPage::minutes($rules['spectator_delay_minutes'], $locale),
            'restart' => RulesPage::minutes($rules['restart_minutes'], $locale),
        ], $locale);
    }

    /**
     * @param  Rules  $rules
     */
    private static function civilizations(array $rules): string
    {
        return $rules['civilizations'] === 'free' ? __('free pick') : $rules['civilizations'];
    }
}
