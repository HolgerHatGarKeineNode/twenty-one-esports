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
     * @param  string|null  $replay  the canonical replay the verifier re-encoded (verified only)
     */
    private function __construct(
        public string $outcome,
        public ?string $reason = null,
        public ?array $settings = null,
        public ?string $replay = null,
    ) {}

    /**
     * @param  array{das: int, arr: int, sdf: int}  $settings
     */
    public static function verified(array $settings, string $replay): self
    {
        return new self(self::VERIFIED, null, $settings, $replay);
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
