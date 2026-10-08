<?php

namespace App\Support\TwentyOne\Stream;

/**
 * When the supervisor republishes the `live` 30311.
 *
 * The first `live` goes out at once. After that: every `republishSeconds`
 * (clients treat an old `live` as ended), and on a change of title/summary,
 * cover (StreamCover) or viewer count, but such a change at most once per
 * `textChangeSeconds`, so players renaming themselves (or viewers coming and
 * going) cannot make the platform key publish at will. The send itself runs
 * in a child process; this schedule only decides when one is started.
 * `ended` is not scheduled here: it always goes out at once.
 */
final class PublishSchedule
{
    private ?int $publishedAt = null;

    /** @var array{title: string, summary: string, image?: string, viewers?: int|null}|null */
    private ?array $publishedTexts = null;

    public function __construct(
        private int $republishSeconds,
        private int $textChangeSeconds,
    ) {}

    /**
     * @param  array{title: string, summary: string, image?: string, viewers?: int|null}  $texts
     */
    public function due(array $texts, int $now): bool
    {
        if ($this->publishedAt === null) {
            return true;
        }

        if ($now - $this->publishedAt >= $this->republishSeconds) {
            return true;
        }

        return $texts !== $this->publishedTexts && $now - $this->publishedAt >= $this->textChangeSeconds;
    }

    /**
     * @param  array{title: string, summary: string, image?: string, viewers?: int|null}  $texts
     */
    public function published(array $texts, int $now): void
    {
        $this->publishedAt = $now;
        $this->publishedTexts = $texts;
    }
}
