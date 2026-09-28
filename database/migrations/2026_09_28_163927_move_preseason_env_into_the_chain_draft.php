<?php

use App\Models\SeasonSettingChange;
use App\Support\Rating\RatingSettings;
use App\Support\SeasonChain\ChainDraft;
use App\Support\SeasonChain\SeasonRelease;
use Carbon\CarbonImmutable;
use Dotenv\Dotenv;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /** Who wrote the row, in place of a board member's pubkey. */
    private const AUTHOR = 'env';

    /** The .env file read; null = base_path('.env'). Set by its regression test only. */
    public ?string $envFile = null;

    /**
     * Migrations run in every test's fresh database, where a developer's
     * .env must not leak into a draft; its regression test switches this on.
     */
    public bool $runInTests = false;

    /**
     * One-time move of the Pre-Season settings of the server into the chain
     * draft (P43): ESPORTS_BLOCK0_AT, ESPORTS_PRESEASON_POT_SATS and
     * ESPORTS_GENESIS_MESSAGE were read by config/esports.php until P43; the
     * draft of the admin season page is now their only source. A server
     * that had them set keeps its countdown, supply and message as the first
     * chain draft; nothing is written without them, or once the board has
     * saved a chain draft.
     *
     * Read from the process and the .env file directly: a cached config of
     * the previous deploy would hide the .env from env(), and the keys are
     * gone from the config. A malformed value is skipped, never guessed.
     */
    public function up(): void
    {
        if ((app()->runningUnitTests() && ! $this->runInTests) || ChainDraft::stored() !== null) {
            return;
        }

        $file = $this->envFile ?? base_path('.env');
        $env = [];

        try {
            $env = is_file($file) ? Dotenv::parse((string) file_get_contents($file)) : [];
        } catch (Throwable) {
            // An unreadable .env must not stop the deploy: its values are then not moved.
        }

        $read = function (string $key) use ($env): string {
            $value = getenv($key);

            return trim(is_string($value) && $value !== '' ? $value : (string) ($env[$key] ?? ''));
        };

        $chain = ChainDraft::defaults();
        $block0 = $read('ESPORTS_BLOCK0_AT');
        $supply = filter_var($read('ESPORTS_PRESEASON_POT_SATS'), FILTER_VALIDATE_INT, ['options' => ['min_range' => ChainDraft::LIMITS['supply'][0], 'max_range' => ChainDraft::LIMITS['supply'][1]]]);
        $message = $read('ESPORTS_GENESIS_MESSAGE');
        $found = false;

        if ($block0 !== '') {
            try {
                $chain['block0_at'] = CarbonImmutable::parse($block0)->getTimestamp();
                $found = true;
            } catch (Throwable) {
                // not a date: the board sets it on the page
            }
        }

        if ($supply !== false) {
            $chain['supply'] = $supply;
            $found = true;
        }

        if ($message !== '' && mb_strlen($message) <= SeasonRelease::MESSAGE_MAX) {
            $chain['message'] = $message;
            $found = true;
        }

        if (! $found) {
            return;
        }

        SeasonSettingChange::query()->create([
            'changed_by_id' => null,
            'changed_by_pubkey' => self::AUTHOR,
            'values' => [...RatingSettings::draft(), 'chain' => $chain],
            'changes' => ChainDraft::diff(ChainDraft::defaults(), $chain),
        ]);
    }

    public function down(): void
    {
        SeasonSettingChange::query()->where('changed_by_pubkey', self::AUTHOR)->delete();
    }
};
