<?php

namespace App\Games;

/**
 * The cover art of a game: one 16:9 image under `public/images/games/`, as
 * `<name>-<width>.webp` plus a `<name>-<width>.jpg` fallback for each width.
 * A source narrower than 1280 px is never scaled up, so its largest file
 * has the source's own width.
 */
final readonly class GameCover
{
    public const DIRECTORY = 'images/games';

    /**
     * @param  list<int>  $widths  the widths on disk, smallest first
     */
    public function __construct(
        public string $name,
        public array $widths = [480, 1280],
    ) {}

    /** Public path of one file, relative to `public/`. */
    public function path(int $width, string $format): string
    {
        return self::DIRECTORY."/{$this->name}-{$width}.{$format}";
    }

    public function smallest(): int
    {
        return $this->widths[0];
    }

    public function largest(): int
    {
        return $this->widths[count($this->widths) - 1];
    }
}
