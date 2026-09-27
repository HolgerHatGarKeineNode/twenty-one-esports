<?php

namespace App\Support\Wallet;

use RuntimeException;

/**
 * A NIP-47 error answer (`error.code`: PAYMENT_FAILED, INSUFFICIENT_BALANCE,
 * QUOTA_EXCEEDED, NOT_FOUND, …), or `TIMEOUT` when no answer came: then
 * nobody knows whether the wallet acted. The message is the wallet's own
 * text, cut short; it never carries a secret of ours.
 */
final class NwcError extends RuntimeException
{
    public const TIMEOUT = 'TIMEOUT';

    public function __construct(public readonly string $errorCode, string $message = '')
    {
        parent::__construct(mb_substr($errorCode.($message === '' ? '' : ': '.$message), 0, 200));
    }

    public function isTimeout(): bool
    {
        return $this->errorCode === self::TIMEOUT;
    }
}
