<?php

namespace App\Support\TwentyOne\Stream;

use Closure;
use Illuminate\Support\Facades\File;

/**
 * The picture of the stream's 30311 (`image`): every `twentyone.stream.cover.minutes`
 * the next slide of the rotation, rendered like a scene (1280x720, the stream's
 * own 16:9 frame) into one file, `twentyone.stream.cover.path`, which
 * StreamCoverController serves.
 *
 * The slides take turns: the hero of every tournament open for sign-up, then
 * the join and ladder teasers. One file is overwritten, nothing piles up; the
 * URL carries the picture's hash (`?v=`), so a changed picture is a new URL
 * for every client cache, and an unchanged one republishes nothing. The live
 * countdown would freeze on a still, so a cover's hero says "Open now".
 *
 * Until the first cover rendered (or when it cannot), image() is null and the
 * event keeps the configured `twentyone.stream.event.image`.
 */
final class StreamCover
{
    /** Teasers after the tournament heroes: join (a4), the ladders (a3). */
    public const TEASERS = ['a4', 'a3'];

    /** A render that failed is tried again after this long, not at the next slot. */
    public const RETRY_SECONDS = 60;

    private int $turn = 0;

    private ?string $image = null;

    private float $nextAt = 0.0;

    public function __construct(
        private SceneRenderer $renderer,
        private string $path,
        private string $url,
        private int $seconds,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            SceneRenderer::fromConfig(),
            (string) config('twentyone.stream.cover.path'),
            route('stream.cover'),
            60 * max(1, (int) config('twentyone.stream.cover.minutes', 15)),
        );
    }

    /**
     * The cover slides in turn: [scene id, tournament id or null].
     *
     * @param  list<int>  $tournamentIds  upcoming tournaments, soonest sign-up close first
     * @return list<array{scene: string, tournamentId: int|null}>
     */
    public static function slides(array $tournamentIds): array
    {
        return [
            ...array_map(fn (int $id): array => ['scene' => 'ta1', 'tournamentId' => $id], $tournamentIds),
            ...array_map(fn (string $scene): array => ['scene' => $scene, 'tournamentId' => null], self::TEASERS),
        ];
    }

    /**
     * The `image` URL of the current cover, null before the first one.
     */
    public function image(): ?string
    {
        return $this->image;
    }

    public function due(float $now): bool
    {
        return $now >= $this->nextAt;
    }

    /**
     * Render the next slide and make it the cover; the slide's label.
     * On a failure the cover stays and the next attempt is RETRY_SECONDS away.
     *
     * @param  list<int>  $tournamentIds
     * @param  Closure(string, int|null): array<string, mixed>  $data  the scene data of a slide
     *
     * @throws \Throwable when the data or the render fails
     */
    public function advance(float $now, array $tournamentIds, Closure $data): string
    {
        $this->nextAt = $now + self::RETRY_SECONDS;
        $slides = self::slides($tournamentIds);
        $slide = $slides[$this->turn % count($slides)];

        $scene = $data($slide['scene'], $slide['tournamentId']);

        if ($slide['tournamentId'] !== null && is_array($scene['tournament'] ?? null)) {
            $scene['tournament'] = [...$scene['tournament'], 'countdown' => 'Open now', 'countdownLabel' => 'Sign-up'];
        }

        $png = $this->renderer->png($scene, RotationPlanner::VIEWS[$slide['scene']]);

        File::ensureDirectoryExists(dirname($this->path));
        PlaylistWriter::writeAtomically($this->path, $png);

        $this->image = $this->url.'?v='.substr(hash('sha256', $png), 0, 16);
        $this->nextAt = $now + $this->seconds;
        $this->turn++;

        return $slide['scene'].($slide['tournamentId'] !== null ? ' tournament '.$slide['tournamentId'] : '');
    }
}
