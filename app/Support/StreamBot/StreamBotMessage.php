<?php

namespace App\Support\StreamBot;

/**
 * One chat message a builder offers: which builder, which fact (the
 * dedup key: the same fact is not posted again within
 * `esports.stream_bot.repeat_hours`), the text and the `p` tags of the
 * players it names (none for most messages).
 */
final readonly class StreamBotMessage
{
    public function __construct(
        public string $builder,
        public string $factKey,
        public string $content,
        /** @var list<list<string>> */
        public array $tags = [],
    ) {}
}
