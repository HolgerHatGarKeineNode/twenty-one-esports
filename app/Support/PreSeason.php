<?php

namespace App\Support;

use App\Models\User;
use App\Support\SeasonChain\ChainDraft;
use Carbon\CarbonImmutable;

/**
 * The Pre-Season as the board planned it before Block 0: the saved chain
 * draft of the admin season page (ChainDraft, P43), the one source of the
 * home page, the rules and the countdown. Until the board saved one, every
 * value reads as "not configured", so the home page never shows a made-up
 * date, supply or message.
 */
class PreSeason
{
    /** Planned Block 0; the board still releases it by hand. */
    public static function block0At(): ?CarbonImmutable
    {
        $at = ChainDraft::stored()['block0_at'] ?? null;

        return $at === null ? null : CarbonImmutable::createFromTimestamp($at);
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

    /**
     * The Pre-Season supply in sats, null = not announced: the most the
     * season pays out after it ends, not money held now.
     */
    public static function potSats(): ?int
    {
        return ChainDraft::stored()['supply'] ?? null;
    }

    public static function genesisMessage(): ?string
    {
        return ChainDraft::stored()['message'] ?? null;
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
