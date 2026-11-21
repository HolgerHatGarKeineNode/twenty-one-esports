<?php

namespace App\Console\Commands;

use App\Support\FairPlay\AccountCheckSettings;
use App\Support\Settings\LeagueSettingRefused;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The account checks' values in force (AccountCheckSettings, plan "Profile standard and impersonation", P3), seeded
 * into the league settings from a JSON file kept outside the public repository (user 2026-11-21: „können wir die
 * Prompts und Eigenschaften nicht in die DB unsichtbar seeden?"). The file is one object by short name
 * (`{"activity_weight": 3, "scan_prompt": "…"}`); every name must be an account check setting and every value must
 * pass it, or nothing is written. A value already in force writes nothing, so running it again changes nothing.
 * Prints the names it set, never a value: the prompt stays out of the terminal and the logs.
 */
#[Signature('fair-play:seed-account-checks {file : A JSON file outside the repository}')]
#[Description('Seed the account checks\' weights, thresholds, model and prompt from a JSON file outside the repository')]
class SeedAccountChecksCommand extends Command
{
    public function handle(): int
    {
        $file = is_string($this->argument('file')) ? $this->argument('file') : '';
        $contents = is_file($file) && is_readable($file) ? file_get_contents($file) : false;

        if ($contents === false) {
            $this->error("Cannot read {$file}.");

            return self::FAILURE;
        }

        $values = json_decode($contents, true);

        if (! is_array($values) || array_is_list($values)) {
            $this->error('The file must hold one JSON object of setting names and values.');

            return self::FAILURE;
        }

        try {
            $set = AccountCheckSettings::seed($values);
        } catch (LeagueSettingRefused $refused) {
            $this->error('Nothing was written. Refused:');

            foreach ($refused->errors as $name => $message) {
                $this->line("  {$name}: {$message}");
            }

            return self::FAILURE;
        }

        $unchanged = array_values(array_diff(array_map(strval(...), array_keys($values)), $set));

        $this->info($set === [] ? 'Nothing changed: every value was in force already.' : 'Set: '.implode(', ', $set).'.');

        if ($set !== [] && $unchanged !== []) {
            $this->line('Unchanged: '.implode(', ', $unchanged).'.');
        }

        return self::SUCCESS;
    }
}
