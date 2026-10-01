<?php

namespace App\Support\Scores;

use App\Models\TournamentParticipant;
use Carbon\CarbonImmutable;

/**
 * One entry of a score leaderboard: its place (null while it has no valid
 * value inside the window), its best value and when it was set, and where it
 * came from. `proofUrl` is for directors and admins only: a player's link may
 * name their game account, so public pages never show it.
 */
final readonly class ScoreStanding
{
    public function __construct(
        public TournamentParticipant $participant,
        public ?int $place,
        public ?int $value,
        public ?CarbonImmutable $achievedAt,
        public ?string $source,
        public ?string $proofUrl,
        public ?int $runId,
    ) {}
}
