<?php

namespace App\Support\TwentyOne\Stream;

use App\Games\Blockfill;
use App\Games\GameRegistry;
use App\Games\TrackmaniaNationsForever;
use App\Models\ChessGame;
use App\Support\Chess\ChessModes;
use Illuminate\Support\Facades\Cache;
use Throwable;

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

    /** How long the games-played count behind a rotating title is kept. */
    private const PLAYED_CACHE_SECONDS = 300;

    /**
     * The 30311 texts by turns: what the scene shows (null in the loop), then
     * the games on offer, the games played and each weekly highscore chase
     * that is switched on, one every `twentyone.stream.texts.rotate_minutes`.
     * The turn comes from the clock alone, so a restarted daemon keeps it,
     * and two neighbouring turns never carry the same title.
     *
     * @param  array{title: string, summary: string}|null  $scene
     * @return array{title: string, summary: string}
     */
    public static function rotate(?array $scene, int $now): array
    {
        $minutes = (int) config('twentyone.stream.texts.rotate_minutes', 10);

        if ($minutes <= 0) {
            return $scene ?? self::for(null);
        }

        $turns = array_values(array_filter([$scene, ...self::general()]));
        $titles = [];

        foreach ($turns as $turn) {
            $titles[$turn['title']] ??= $turn;
        }

        $turns = array_values($titles);

        return $turns[intdiv($now, $minutes * 60) % count($turns)];
    }

    /**
     * The texts that need no live game: the games on offer, the games
     * played, and the weekly highscore chases switched on.
     *
     * @return list<array{title: string, summary: string}>
     */
    private static function general(): array
    {
        $registry = app(GameRegistry::class);
        $names = array_values(array_map(fn ($game): string => GameTitle::short($game->name()), $registry->all()));
        $url = (string) config('twentyone.stream.scene.url');
        $offer = self::listed($names);
        $summary = 'TWENTY ONE Esports, the esports arm of EINUNDZWANZIG: '.$offer.'. Ladders, weekly highscores and tournaments for Bitcoiners. Play at '.$url.'. Login via Nostr.';
        $played = self::played();

        $turns = [
            ['title' => 'Bitcoiner esports 24/7: '.self::listed(array_slice($names, 0, 3)).(count($names) > 3 ? ' and more' : ''), 'summary' => $summary],
        ];

        if ($played > 0) {
            $turns[] = ['title' => number_format($played).' games played on TWENTY ONE Esports', 'summary' => $summary];
        }

        if ($registry->find(TrackmaniaNationsForever::SLUG) !== null) {
            $turns[] = ['title' => 'TrackMania Nations Forever: a weekly time attack on our own server', 'summary' => 'Free on Steam, one track a week, your best finish counts. '.$summary];
        }

        if ($registry->find(Blockfill::SLUG) !== null) {
            $turns[] = ['title' => 'Blockfill: the weekly highscore chase in the browser', 'summary' => 'Play in the browser, your best run of the week counts. '.$summary];
        }

        $turns[] = ['title' => 'Tournaments, ladders and highscores · Login via Nostr', 'summary' => $summary];

        return $turns;
    }

    /**
     * "A, B and C".
     *
     * @param  list<string>  $names
     */
    private static function listed(array $names): string
    {
        $last = array_pop($names);

        return $names === [] ? (string) $last : implode(', ', $names).' and '.$last;
    }

    /** Games played, counted at most every few minutes: the daemon asks four times a second. */
    private static function played(): int
    {
        try {
            return (int) Cache::remember('twentyone.stream.texts.played', self::PLAYED_CACHE_SECONDS, fn (): int => StreamStats::played());
        } catch (Throwable $e) {
            report($e);

            return 0;
        }
    }

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
     * The texts while a running tournament holds the stream: its name and game
     * from its live frame (TournamentLiveSlides; the name already through PublicName).
     *
     * @param  array<string, mixed>  $frame
     * @return array{title: string, summary: string}
     */
    public static function forTournament(array $frame): array
    {
        $name = PublicName::limit((string) ($frame['name'] ?? ''), 2 * self::NAME_LIMIT) ?: 'Tournament';
        $game = (string) ($frame['game'] ?? '');
        $now = is_string($frame['now'] ?? null) ? ' '.$frame['now'].'.' : '';

        return [
            'title' => 'Live now: '.$name.($game === '' ? '' : ' · '.$game.' tournament'),
            'summary' => $name.' is running:'.$now.' The bracket and the matches live on TWENTY ONE Esports, the esports arm of EINUNDZWANZIG. Follow it at '.($frame['url'] ?? config('twentyone.stream.scene.url')).'. Login via Nostr.',
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
        // The live mode by its registry word (plan "Schach Rapid und Clan", P3): "Chess Rapid", "live rapid chess".
        [$kind, $described] = $game->isCorrespondence() ? ['Chess Correspondence', 'correspondence chess, one move a day,'] : ['Chess '.ucfirst(ChessModes::word($game->mode)), 'live '.ChessModes::word($game->mode).' chess'];

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
