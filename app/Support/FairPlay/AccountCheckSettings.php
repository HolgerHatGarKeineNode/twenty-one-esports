<?php

namespace App\Support\FairPlay;

use App\Support\Settings\LeagueSettingRefused;
use App\Support\Settings\LeagueSettings;

/**
 * The settings of the account checks (plan "Profile standard and
 * impersonation", P3): the soft marks' thresholds and weights and the AI
 * scan's model and prompt. Listed in {@see LeagueSettings} (group
 * `account_checks`, /admin/settings). The repository is public, so it holds
 * none of the values the admins work with (user 2026-11-21: „können wir die
 * Prompts und Eigenschaften nicht in die DB unsichtbar seeden?"): the
 * defaults here are neutral (every weight 0, so no mark shows; every
 * threshold 0, which turns it off; no prompt, so no scan runs), and the
 * values in force live in the database only, saved by an admin on
 * /admin/settings or seeded from a file outside the repository with
 * `fair-play:seed-account-checks` ({@see seed()}).
 *
 * A weight orders the chips on the check view and 0 hides a mark; the
 * weights are never summed into a score (scope: no computed trust score
 * shown to anyone).
 *
 * @phpstan-import-type Definition from LeagueSettings
 */
final class AccountCheckSettings
{
    public const PREFIX = 'esports.account_checks.';

    /** The model of a new scan: the most capable Claude model when this shipped (claude-api skill, 2026-11-21). */
    public const DEFAULT_MODEL = 'claude-opus-5-5';

    /** The longest prompt an admin can save. */
    public const PROMPT_MAX = 8000;

    /**
     * @return array<string, Definition>
     */
    public static function definitions(): array
    {
        $group = 'account_checks';
        $weight = __('Orders the marks on an account’s check view, highest first; 0 hides the mark. Never added up into a score.');

        return [
            self::PREFIX.'activity_weight' => LeagueSettings::owned($group, 'int', __('Weight: activity'), 0, 10, 0, $weight),
            self::PREFIX.'activity_days' => LeagueSettings::owned($group, 'int', __('Activity: days with a game'), 0, 365, 0,
                __('An account that played rated or casual games with us on at least this many different days shows the mark “steady”; 0 turns it off. Applies at once.')),
            self::PREFIX.'activity_burst_percent' => LeagueSettings::owned($group, 'int', __('Activity: share of games within one day (%)'), 0, 100, 0,
                __('When at least this share of an account’s games fell within its busiest 24 hours, it shows the mark “burst”; 0 turns it off. Applies at once.')),
            self::PREFIX.'activity_burst_games' => LeagueSettings::owned($group, 'int', __('Activity: games before a burst counts'), 0, 1000, 0,
                __('Fewer games than this never show “burst”; 0 turns it off. Applies at once.')),
            self::PREFIX.'zaps_weight' => LeagueSettings::owned($group, 'int', __('Weight: zaps from known accounts'), 0, 10, 0, $weight),
            self::PREFIX.'zaps_accounts' => LeagueSettings::owned($group, 'int', __('Zaps: known accounts needed'), 0, 100, 0,
                __('That many different known accounts that zapped the account show the mark; 0 turns it off. Applies at once.')),
            self::PREFIX.'zaps_rank' => LeagueSettings::owned($group, 'int', __('Zaps: trust rank of a known account'), 0, 100, 0,
                __('A Verein member counts as known, and so does an account ranked at least this high now; 0: members only. Applies at once.')),
            self::PREFIX.'casual_weight' => LeagueSettings::owned($group, 'int', __('Weight: casual games before Block 0'), 0, 10, 0, $weight),
            self::PREFIX.'casual_games' => LeagueSettings::owned($group, 'int', __('Casual games before Block 0'), 0, 1000, 0,
                __('That many casual games with us before the live season’s Block 0 show the mark; 0 turns it off. Applies at once.')),
            self::PREFIX.'scan_model' => LeagueSettings::owned($group, 'string', __('AI scan: model'), 0, 0, self::DEFAULT_MODEL,
                __('The Claude model a new scan runs on. Scans run only when an admin starts one, and each costs money. Applies to the next scan.')),
            self::PREFIX.'scan_prompt' => LeagueSettings::owned($group, 'text', __('AI scan: instructions'), 0, self::PROMPT_MAX, '',
                __('What the model is told before it reads an account. Without instructions no scan runs. The account’s own texts reach it as untrusted data either way. Applies to the next scan.')),
        ];
    }

    public static function int(string $name): int
    {
        return (int) LeagueSettings::get(self::PREFIX.$name);
    }

    public static function model(): string
    {
        return (string) LeagueSettings::get(self::PREFIX.'scan_model');
    }

    public static function prompt(): string
    {
        return (string) LeagueSettings::get(self::PREFIX.'scan_prompt');
    }

    /**
     * Write these values as the ones in force, as the league itself (no
     * admin, {@see LeagueSettings::save()}): `$values` by short name
     * (`activity_weight` => 3). All or nothing: an unknown name or a value
     * its definition refuses writes none. A value already in force writes no
     * row, so a second run changes nothing. Returns the short names written.
     *
     * @param  array<array-key, mixed>  $values
     * @return list<string>
     *
     * @throws LeagueSettingRefused with a message per refused name
     */
    public static function seed(array $values): array
    {
        $definitions = self::definitions();
        $errors = [];
        $keyed = [];

        foreach ($values as $name => $value) {
            $key = self::PREFIX.$name;

            if (! is_string($name) || ! isset($definitions[$key])) {
                $errors[(string) $name] = __('Not an account check setting.');

                continue;
            }

            if ($value === null) {
                $errors[$name] = LeagueSettings::rule($definitions[$key]);

                continue;
            }

            $keyed[$key] = $value;
        }

        if ($errors !== []) {
            throw new LeagueSettingRefused($errors);
        }

        try {
            $written = LeagueSettings::save(null, $keyed);
        } catch (LeagueSettingRefused $refused) {
            throw new LeagueSettingRefused(collect($refused->errors)->mapWithKeys(fn (string $message, string $key): array => [substr($key, strlen(self::PREFIX)) => $message])->all());
        }

        LeagueSettings::forget();

        return array_map(fn ($change): string => substr((string) $change->key, strlen(self::PREFIX)), $written);
    }
}
