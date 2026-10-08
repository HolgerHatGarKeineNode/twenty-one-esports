<?php

namespace App\Support\Hyper;

/**
 * The result of one action: the game after it and what happened, in order, for the page to animate.
 * Every event is an array with a `type` key; HyperGame's class comment lists them.
 */
final readonly class HyperStep
{
    /**
     * @param  list<array<string, mixed>>  $events
     */
    public function __construct(public HyperGame $game, public array $events) {}
}
