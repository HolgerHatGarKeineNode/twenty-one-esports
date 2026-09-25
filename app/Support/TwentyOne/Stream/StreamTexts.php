<?php

namespace App\Support\TwentyOne\Stream;

use App\Models\ChessGame;

/**
 * Title and summary of the stream's 30311 event: the configured loop texts,
 * or the game the scene shows. Player names go through PublicName, so no
 * control or bidi character and no line break reaches the event.
 */
final class StreamTexts
{
    /** Code points per player name in the texts. */
    public const NAME_LIMIT = 40;

    /**
     * @return array{title: string, summary: string}
     */
    public static function for(?ChessGame $game): array
    {
        /** @var array{title: string, summary: string} $event */
        $event = config('twentyone.stream.event');

        if ($game === null) {
            return ['title' => $event['title'], 'summary' => $event['summary']];
        }

        $white = PublicName::limit($game->white->displayName(), self::NAME_LIMIT) ?: 'White player';
        $black = PublicName::limit($game->black->displayName(), self::NAME_LIMIT) ?: 'Black player';
        $players = $white.' vs '.$black;

        return [
            'title' => 'Live now: '.$players.' · Chess Blitz',
            'summary' => $players.': live blitz chess on TWENTY ONE Esports, the esports arm of EINUNDZWANZIG. Play the next game at '.config('twentyone.stream.scene.url').'. Login via Nostr.',
        ];
    }
}
