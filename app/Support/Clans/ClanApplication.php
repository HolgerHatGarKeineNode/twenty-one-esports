<?php

namespace App\Support\Clans;

/**
 * The short form of a clan application (plan "Clan-Bewerbungen", P2): the
 * games the player wants to play with the clan (GameRegistry slugs), the
 * platforms they play on (App\Enums\Platform values), their time zone (an
 * IANA name) and an optional message of at most MESSAGE_MAX characters.
 *
 * The component that collects it validates the values
 * (resources/views/components/⚡clan-apply.blade.php); this only carries them.
 */
final readonly class ClanApplication
{
    public const MESSAGE_MAX = 280;

    /**
     * @param  list<string>  $games
     * @param  list<string>  $platforms
     */
    public function __construct(
        public array $games,
        public array $platforms,
        public string $timezone,
        public ?string $message = null,
    ) {}
}
