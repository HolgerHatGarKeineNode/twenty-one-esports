<?php

namespace App\Support\TwentyOne\Stream;

/**
 * Which rotation scene the stream shows now: a pure state machine over time
 * and the games on show, deterministic and without ffmpeg.
 *
 * With games (active, daily included, or ended within the hysteresis the
 * caller applies) a round is: MATCH in this round's look (A, B, C in turn;
 * the next game in turn, blitz first as the caller orders them), GALLERY in
 * the same look when two or more games run, then TEASERS from the pool of
 * nine, continuing where the last round stopped. Without games a round is
 * the teasers alone, and every `loopEvery`-th such round (the first one
 * included, so the daemon starts on the loop) is one pass of the promo loop.
 *
 * Games have priority: a game that appears during a round without games
 * takes over at the end of the current teaser, and ends the loop at once.
 * A match whose game is gone, or a gallery with fewer than two games, ends
 * early too.
 */
final class RotationPlanner
{
    public const MATCH = 'match';

    public const GALLERY = 'gallery';

    public const TEASER = 'teaser';

    public const LOOP = 'loop';

    public const LOOKS = ['a', 'b', 'c'];

    public const TEASERS = ['a3', 'a4', 'a5', 'b3', 'b4', 'b5', 'c3', 'c4', 'c5'];

    /** Scene id => its view (resources/views/stream/rotation). */
    public const VIEWS = [
        'a1' => 'stream.rotation.a1-match', 'a2' => 'stream.rotation.a2-gallery', 'a3' => 'stream.rotation.a3-ladders', 'a4' => 'stream.rotation.a4-join', 'a5' => 'stream.rotation.a5-zap',
        'b1' => 'stream.rotation.b1-match', 'b2' => 'stream.rotation.b2-gallery', 'b3' => 'stream.rotation.b3-daily', 'b4' => 'stream.rotation.b4-clans', 'b5' => 'stream.rotation.b5-boards',
        'c1' => 'stream.rotation.c1-match', 'c2' => 'stream.rotation.c2-gallery', 'c3' => 'stream.rotation.c3-ladders', 'c4' => 'stream.rotation.c4-scan', 'c5' => 'stream.rotation.c5-zap',
    ];

    /** @var array{kind: string, scene: string|null, gameId: int|null, until: float}|null */
    private ?array $slot = null;

    /** @var list<array{kind: string, look?: string, gameId?: int}> the rest of the current round */
    private array $queue = [];

    private bool $roundWithGames = false;

    private int $liveRounds = 0;

    private int $idleRounds = 0;

    private int $teaser = 0;

    private int $matchTurn = 0;

    public function __construct(
        private float $matchSeconds = 45,
        private float $blitzMatchSeconds = 60,
        private float $gallerySeconds = 20,
        private float $teaserSeconds = 12,
        private int $teasersPerRound = 3,
        private int $loopEvery = 3,
        private float $loopSeconds = 60,
    ) {}

    public static function fromConfig(float $loopSeconds): self
    {
        return new self(
            (float) config('twentyone.stream.rotation.match_seconds', 45),
            (float) config('twentyone.stream.rotation.blitz_match_seconds', 60),
            (float) config('twentyone.stream.rotation.gallery_seconds', 20),
            (float) config('twentyone.stream.rotation.teaser_seconds', 12),
            (int) config('twentyone.stream.rotation.teasers_per_round', 3),
            (int) config('twentyone.stream.rotation.loop_every_rounds', 3),
            $loopSeconds,
        );
    }

    /**
     * The slot on show at `$now`.
     *
     * @param  list<array{id: int, blitz: bool}>  $games  the games on show, in display order
     * @return array{kind: string, scene: string|null, gameId: int|null, until: float}
     */
    public function at(float $now, array $games): array
    {
        $ids = array_column($games, 'id');

        if ($this->slot === null || $now >= $this->slot['until'] || $this->endsEarly($ids)) {
            $this->advance($now, $games);
        }

        assert($this->slot !== null);

        return $this->slot;
    }

    /**
     * @param  list<int>  $ids
     */
    private function endsEarly(array $ids): bool
    {
        assert($this->slot !== null);

        return match ($this->slot['kind']) {
            // Live priority: a game ends the loop at once.
            self::LOOP => $ids !== [],
            self::MATCH => ! in_array($this->slot['gameId'], $ids, true),
            self::GALLERY => count($ids) < 2,
            default => false,
        };
    }

    /**
     * @param  list<array{id: int, blitz: bool}>  $games
     */
    private function advance(float $now, array $games): void
    {
        // Back to back on schedule; after a stall (or early end) from now.
        $start = $this->slot !== null && $now >= $this->slot['until'] && $now - $this->slot['until'] < 1 ? $this->slot['until'] : $now;

        // A round without games gives way as soon as a game is there.
        if (! $this->roundWithGames && $games !== []) {
            $this->queue = [];
        }

        while (true) {
            if ($this->queue === []) {
                $this->plan($games);
            }

            $next = array_shift($this->queue);
            $slot = $this->slotFor($next, $games, $start);

            if ($slot !== null) {
                $this->slot = $slot;

                return;
            }
        }
    }

    /**
     * @param  list<array{id: int, blitz: bool}>  $games
     */
    private function plan(array $games): void
    {
        $teasers = array_fill(0, max(1, $this->teasersPerRound), ['kind' => self::TEASER]);

        if ($games === []) {
            $this->roundWithGames = false;
            $this->queue = $this->idleRounds++ % max(1, $this->loopEvery) === 0 ? [['kind' => self::LOOP]] : $teasers;

            return;
        }

        $this->roundWithGames = true;
        $look = self::LOOKS[$this->liveRounds++ % count(self::LOOKS)];
        $game = $games[$this->matchTurn++ % count($games)];
        $this->queue = [
            ['kind' => self::MATCH, 'look' => $look, 'gameId' => $game['id']],
            // Skipped when fewer than two games are on show by then (slotFor()).
            ['kind' => self::GALLERY, 'look' => $look],
            ...$teasers,
        ];
    }

    /**
     * @param  array{kind: string, look?: string, gameId?: int}  $entry
     * @param  list<array{id: int, blitz: bool}>  $games
     * @return array{kind: string, scene: string|null, gameId: int|null, until: float}|null null when it no longer applies
     */
    private function slotFor(array $entry, array $games, float $start): ?array
    {
        switch ($entry['kind']) {
            case self::MATCH:
                $game = collect($games)->firstWhere('id', $entry['gameId'] ?? null);

                return $game === null ? null : $this->slot(self::MATCH, ($entry['look'] ?? 'a').'1', $game['id'], $start + ($game['blitz'] ? $this->blitzMatchSeconds : $this->matchSeconds));
            case self::GALLERY:
                return count($games) < 2 ? null : $this->slot(self::GALLERY, ($entry['look'] ?? 'a').'2', null, $start + $this->gallerySeconds);
            case self::LOOP:
                return $this->slot(self::LOOP, null, null, $start + $this->loopSeconds);
            default:
                $scene = self::TEASERS[$this->teaser++ % count(self::TEASERS)];

                return $this->slot(self::TEASER, $scene, null, $start + $this->teaserSeconds);
        }
    }

    /**
     * @return array{kind: string, scene: string|null, gameId: int|null, until: float}
     */
    private function slot(string $kind, ?string $scene, ?int $gameId, float $until): array
    {
        return ['kind' => $kind, 'scene' => $scene, 'gameId' => $gameId, 'until' => $until];
    }
}
