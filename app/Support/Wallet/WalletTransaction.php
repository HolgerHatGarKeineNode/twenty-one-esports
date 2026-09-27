<?php

namespace App\Support\Wallet;

/**
 * A NIP-47 `lookup_invoice` answer, reduced to what the league acts on.
 * `state` is the wallet's own when it sends one (`pending`, `settled`,
 * `expired`, `failed`); a wallet without the field counts as `settled` when
 * it names a settle time or a preimage, else `pending`.
 */
final readonly class WalletTransaction
{
    public function __construct(
        public string $state,
        public ?string $preimage,
        public ?int $settledAt,
        public ?int $feesMsats,
    ) {}

    /**
     * @param  array<string, mixed>  $result
     */
    public static function fromResult(array $result): self
    {
        $preimage = is_string($result['preimage'] ?? null) && preg_match('/^[0-9a-f]{64}$/', strtolower($result['preimage'])) === 1 ? strtolower($result['preimage']) : null;
        $settledAt = is_int($result['settled_at'] ?? null) && $result['settled_at'] > 0 ? $result['settled_at'] : null;
        $state = is_string($result['state'] ?? null) ? strtolower($result['state']) : ($settledAt !== null || $preimage !== null ? 'settled' : 'pending');

        return new self(
            in_array($state, ['pending', 'settled', 'expired', 'failed'], true) ? $state : 'pending',
            $preimage,
            $settledAt,
            is_int($result['fees_paid'] ?? null) ? $result['fees_paid'] : null,
        );
    }

    public function isSettled(): bool
    {
        return $this->state === 'settled';
    }
}
