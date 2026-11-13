<?php

namespace App\Games;

/**
 * Several games shown as one wherever games are listed (Contracts\Grouped): the slug the group is known by (its chat
 * channel, its place in the display order), its name and its cover. Its members are the registered games that name it,
 * in the registry's order, and its home is the first of them (GameRegistry::members(), ::home()).
 */
final readonly class GameGroup
{
    public function __construct(
        public string $slug,
        public string $name,
        public ?GameCover $cover = null,
    ) {}
}
