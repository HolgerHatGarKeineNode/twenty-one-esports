<?php

namespace App\Support;

use App\Models\User;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * The Pre-Season as configured before Block 0 (config/esports.php,
 * `preseason`). Every value is optional; anything missing or malformed
 * reads as "not configured", so the home page never shows a made-up date,
 * pot or message.
 */
class PreSeason
{
    /** Planned Block 0; the board still releases it by hand. */
    public static function block0At(): ?CarbonImmutable
    {
        $value = trim((string) config('esports.preseason.block0_at'));

        if ($value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * `countdown` before the planned Block 0, `due` once it has passed and
     * the board has not released a season yet, `undated` without a date.
     *
     * @return 'countdown'|'due'|'undated'
     */
    public static function state(): string
    {
        $block0At = self::block0At();

        return match (true) {
            $block0At === null => 'undated',
            $block0At->isFuture() => 'countdown',
            default => 'due',
        };
    }

    public static function secondsLeft(): int
    {
        $block0At = self::block0At();

        return $block0At === null ? 0 : max(0, (int) ceil(now()->diffInSeconds($block0At, false)));
    }

    /** The Pre-Season supply in sats, null = not announced. */
    public static function potSats(): ?int
    {
        $value = filter_var(config('esports.preseason.pot_sats'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $value === false ? null : $value;
    }

    public static function genesisMessage(): ?string
    {
        $value = trim((string) config('esports.preseason.genesis_message'));

        return $value === '' ? null : $value;
    }

    /** The player's own zone if valid, the league's display zone otherwise. */
    public static function timezoneFor(?User $user): string
    {
        $zone = $user?->timezone;

        return is_string($zone) && in_array($zone, timezone_identifiers_list(), true)
            ? $zone
            : (string) config('esports.preseason.display_timezone', 'UTC');
    }

    /**
     * Sats with a no-break space as the thousands separator, as in the designs
     * ("2 100 000").
     */
    public static function formatSats(int $sats): string
    {
        return number_format($sats, 0, '', "\u{00A0}");
    }
}
