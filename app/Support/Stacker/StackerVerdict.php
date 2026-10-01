<?php

namespace App\Support\Stacker;

/**
 * What the verifier said about one Blockfill run: verified (the replay
 * finished at exactly the claimed ticks and hash), rejected (with a reason),
 * or unavailable (the verifier could not be asked; the run stays pending).
 */
final readonly class StackerVerdict
{
    public const VERIFIED = 'verified';

    public const REJECTED = 'rejected';

    public const UNAVAILABLE = 'unavailable';

    /**
     * @param  array{das: int, arr: int, sdf: int}|null  $settings
     * @param  string|null  $replay  the replay as the verifier re-encoded it from its inputs (verified only)
     * @param  array{flags: list<string>, pps?: float|int, maxPressesPerTick?: int, sameTickBursts?: int, timingCv?: float|int|null, finesse?: array{perfect: int, of: int}}|null  $hints  cheat hints of a verified run (P5): flags and the numbers behind them
     */
    private function __construct(
        public string $outcome,
        public ?string $reason = null,
        public ?array $settings = null,
        public ?string $replay = null,
        public ?array $hints = null,
    ) {}

    /**
     * @param  array{das: int, arr: int, sdf: int}  $settings
     * @param  array{flags: list<string>, pps?: float|int, maxPressesPerTick?: int, sameTickBursts?: int, timingCv?: float|int|null, finesse?: array{perfect: int, of: int}}|null  $hints
     */
    public static function verified(array $settings, string $replay, ?array $hints = null): self
    {
        return new self(self::VERIFIED, null, $settings, $replay, $hints ?? ['flags' => []]);
    }

    public static function rejected(string $reason): self
    {
        return new self(self::REJECTED, $reason);
    }

    public static function unavailable(string $reason): self
    {
        return new self(self::UNAVAILABLE, $reason);
    }
}
