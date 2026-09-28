<?php

use App\Models\SeasonSettingChange;
use App\Support\Rating\RatingSettings;
use App\Support\SeasonChain\ChainDraft;
use Carbon\CarbonImmutable;

/*
| The one-time move of the Pre-Season env settings into the chain draft
| (P43): ESPORTS_BLOCK0_AT, ESPORTS_PRESEASON_POT_SATS and
| ESPORTS_GENESIS_MESSAGE, from the process or the .env file, become the
| first draft; nothing is written without them, and a saved draft is never
| overwritten.
*/

const PRESEASON_ENV_KEYS = ['ESPORTS_BLOCK0_AT', 'ESPORTS_PRESEASON_POT_SATS', 'ESPORTS_GENESIS_MESSAGE'];

/** The migration, reading $envFile (a scratch file) instead of the project's .env. */
function preSeasonEnvMigration(string $envFile): object
{
    $migration = require database_path('migrations/2026_09_28_163927_move_preseason_env_into_the_chain_draft.php');
    $migration->envFile = $envFile;
    $migration->runInTests = true;

    return $migration;
}

beforeEach(function () {
    $this->envFile = tempnam(sys_get_temp_dir(), 'p43-env-');
    file_put_contents($this->envFile, "APP_NAME=Test\n");
});

afterEach(function () {
    foreach (PRESEASON_ENV_KEYS as $key) {
        putenv($key);
    }

    @unlink($this->envFile);
});

test('values in the process environment become the first chain draft', function () {
    putenv('ESPORTS_BLOCK0_AT=2026-10-10T19:00:00+02:00');
    putenv('ESPORTS_PRESEASON_POT_SATS=1500000');
    putenv('ESPORTS_GENESIS_MESSAGE=From the process');

    preSeasonEnvMigration($this->envFile)->up();
    RatingSettings::forget();

    $row = SeasonSettingChange::query()->sole();

    expect(ChainDraft::stored())->toMatchArray(['block0_at' => CarbonImmutable::parse('2026-10-10T17:00:00Z')->getTimestamp(), 'supply' => 1_500_000, 'message' => 'From the process'])
        ->and($row->changed_by_pubkey)->toBe('env')
        ->and($row->changes)->toHaveKeys(['chain.block0_at', 'chain.supply', 'chain.message'])
        ->and(RatingSettings::draft())->toBe(RatingSettings::defaults());
});

test('values only in the .env file are read, a malformed one is skipped', function () {
    file_put_contents($this->envFile, "ESPORTS_BLOCK0_AT=\"not a date\"\nESPORTS_PRESEASON_POT_SATS=1700000\nESPORTS_GENESIS_MESSAGE=\"From the env file\"\n");

    preSeasonEnvMigration($this->envFile)->up();
    RatingSettings::forget();

    expect(ChainDraft::stored())->toMatchArray(['block0_at' => null, 'supply' => 1_700_000, 'message' => 'From the env file']);
});

test('an .env file that does not parse is skipped, not a failed deploy', function () {
    file_put_contents($this->envFile, "ESPORTS_PRESEASON_POT_SATS=1700000\nBROKEN=a b c\n");

    preSeasonEnvMigration($this->envFile)->up();

    expect(SeasonSettingChange::query()->count())->toBe(0);
});

test('without any of the values nothing is written', function () {
    file_put_contents($this->envFile, "ESPORTS_PRESEASON_POT_SATS=\nESPORTS_GENESIS_MESSAGE=\n");

    preSeasonEnvMigration($this->envFile)->up();

    expect(SeasonSettingChange::query()->count())->toBe(0);
});

test('a chain draft the board saved is never overwritten', function () {
    saveChainDraft(['supply' => 900_000]);
    putenv('ESPORTS_PRESEASON_POT_SATS=1500000');

    preSeasonEnvMigration($this->envFile)->up();
    RatingSettings::forget();

    expect(SeasonSettingChange::query()->count())->toBe(1)
        ->and(ChainDraft::stored()['supply'])->toBe(900_000);
});
