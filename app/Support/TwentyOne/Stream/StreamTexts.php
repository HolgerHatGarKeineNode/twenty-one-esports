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

    /** Pairings the summary names when several games run. */
    public const PAIRINGS = 3;

    /**
     * The texts for what the scene shows: one live game as {@see for()},
     * several as "Live now: N chess games" with the first pairings named.
     * Ended cards are not counted; with no live game left the first card
     * (the one that just ended) speaks, as with a single game.
     *
     * @param  list<ChessGame>  $games  in display order
     * @param  int  $more  live games beyond $games
     * @return array{title: string, summary: string}
     */
    public static function forGames(array $games, int $more = 0): array
    {
        $live = array_values(array_filter($games, fn (ChessGame $game): bool => $game->isActive()));
        $count = count($live) + $more;

        if ($count <= 1) {
            return self::for($live[0] ?? $games[0] ?? null);
        }

        $pairings = array_map(fn (ChessGame $game): string => self::players($game), array_slice($live, 0, self::PAIRINGS));
        $unnamed = $count - count($pairings);

        return [
            'title' => 'Live now: '.$count.' chess games',
            'summary' => implode(', ', $pairings).($unnamed > 0 ? ' and '.$unnamed.' more' : '').': live chess on TWENTY ONE Esports, the esports arm of EINUNDZWANZIG. Play the next game at '.config('twentyone.stream.scene.url').'. Login via Nostr.',
        ];
    }

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

        $players = self::players($game);
        [$kind, $described] = $game->isCorrespondence() ? ['Chess Correspondence', 'correspondence chess, one move a day,'] : ['Chess Blitz', 'live blitz chess'];

        return [
            'title' => 'Live now: '.$players.' · '.$kind,
            'summary' => $players.': '.$described.' on TWENTY ONE Esports, the esports arm of EINUNDZWANZIG. Play the next game at '.config('twentyone.stream.scene.url').'. Login via Nostr.',
        ];
    }

    /**
     * "White vs Black" with the public names, sanitized (PublicName).
     */
    private static function players(ChessGame $game): string
    {
        $white = PublicName::limit($game->white->displayName(), self::NAME_LIMIT) ?: 'White player';
        $black = PublicName::limit($game->black->displayName(), self::NAME_LIMIT) ?: 'Black player';

        return $white.' vs '.$black;
    }
}
