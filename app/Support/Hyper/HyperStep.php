<?php

namespace App\Support\Hyper;

/**
 * The result of one action: the game after it and what happened, in order, for the page to animate.
 * Every event is an array with a `type` key; HyperGame's class comment lists them.
 *
 * A bot turn (HyperBot::playTurn) also names the actions it took, each with its own events, so the server
 * stores a bot's turn as the actions a player would have sent (the replay is seed + actions).
 */
final readonly class HyperStep
{
    /**
     * @param  list<array<string, mixed>>  $events
     * @param  list<array{action: array<string, mixed>, events: list<array<string, mixed>>}>  $actions
     */
    public function __construct(public HyperGame $game, public array $events, public array $actions = []) {}
}
