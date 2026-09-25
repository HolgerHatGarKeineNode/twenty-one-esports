<?php

namespace App\Support\TwentyOne;

/**
 * What one relay answered to one published event.
 */
final readonly class PublishResult
{
    public function __construct(
        public string $relay,
        public bool $accepted,
        public string $message = '',
    ) {}
}
