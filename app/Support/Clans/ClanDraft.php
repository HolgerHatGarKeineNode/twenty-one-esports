<?php

namespace App\Support\Clans;

/**
 * The fields of a clan, validated by the create form or the edit card on the
 * manage page. The meetup fields come from the portal import and are all
 * optional.
 */
final readonly class ClanDraft
{
    public function __construct(
        public string $name,
        public string $clantag,
        public ?string $description = null,
        public ?string $picture = null,
        public ?string $meetupName = null,
        public ?string $meetupCity = null,
        public ?string $meetupUrl = null,
        public ?float $meetupLatitude = null,
        public ?float $meetupLongitude = null,
    ) {}
}
