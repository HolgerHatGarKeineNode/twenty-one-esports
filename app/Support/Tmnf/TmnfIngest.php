<?php

namespace App\Support\Tmnf;

use App\Games\ScoreGame;
use App\Support\Scores\FinishEvent;
use App\Support\Scores\JsonServerIngest;
use App\Support\Scores\ServerIngest;

/**
 * The finishes of the league's TMNF server (plan "Trackmania und Restposten"):
 * `tmnf:listen` hands them over read already (ingestEvents()); a bridge may
 * post the league's JSON finish format instead (parse() reads it as
 * JsonServerIngest does). A mapped finish far below the author time is
 * flagged, and held while it would enter the week's top places (TmnfOutliers).
 */
final class TmnfIngest extends ServerIngest
{
    public function parse(array $payload): array
    {
        return (new JsonServerIngest)->parse($payload);
    }

    protected function review(ScoreGame $game, FinishEvent $event, int $userId): ?array
    {
        $author = $event->raw['author_ms'] ?? null;

        return app(TmnfOutliers::class)->assess($event->course, $event->value, $event->achievedAt, $userId, is_int($author) ? $author : null);
    }
}
