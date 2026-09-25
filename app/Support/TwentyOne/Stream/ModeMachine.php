<?php

namespace App\Support\TwentyOne\Stream;

/**
 * Which picture the stream shows: the promo loop, or the live-game scene.
 *
 * Scene as soon as a live game runs; back to the loop only after no game has
 * run for `hysteresis` seconds, so the result stays on screen and a rematch
 * within that time does not flip the stream back and forth.
 */
final class ModeMachine
{
    public const LOOP = 'loop';

    public const SCENE = 'scene';

    /** @var self::LOOP|self::SCENE */
    private string $mode = self::LOOP;

    private ?int $lastLiveAt = null;

    /** Until then no scene, whatever the database says (a scene that failed). */
    private int $sceneBlockedUntil = 0;

    public function __construct(private int $hysteresisSeconds = 60) {}

    /**
     * @return self::LOOP|self::SCENE
     */
    public function tick(bool $gameLive, int $now): string
    {
        if ($now < $this->sceneBlockedUntil) {
            $this->mode = self::LOOP;
        } elseif ($gameLive) {
            $this->lastLiveAt = $now;
            $this->mode = self::SCENE;
        } elseif ($this->mode === self::SCENE && $now - (int) $this->lastLiveAt >= $this->hysteresisSeconds) {
            $this->mode = self::LOOP;
        }

        return $this->mode;
    }

    /**
     * Back to the loop now, and no scene before `$until` (unix seconds):
     * for a scene that cannot be rendered or encoded.
     */
    public function forceLoop(int $until): void
    {
        $this->mode = self::LOOP;
        $this->sceneBlockedUntil = $until;
    }

    /**
     * @return self::LOOP|self::SCENE
     */
    public function mode(): string
    {
        return $this->mode;
    }
}
