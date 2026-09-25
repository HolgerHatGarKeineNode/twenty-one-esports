<?php

namespace App\Support\Series;

use App\Models\NostrEvent;
use App\Models\SeriesMatch;

/**
 * The unsigned match-flow events of a RATED series (docs/nips/esports.md,
 * kinds 2150-2153), built by the league and signed by the captain.
 *
 * A casual series has none: "Casual (unrated) games never produce
 * match-flow events" (NIP "Game registry"). Callers ask
 * {@see SeriesMatch::$rated} first; nothing here is called for casual play.
 *
 * Lobby data never appears in any of these (NIP rule 6): the templates are
 * built from the match's public fields only, and `content` is the public
 * challenge message or the public dispute reason.
 */
final class SeriesEvents
{
    public const CHALLENGE = 2150;

    public const ANSWER = 2151;

    public const REPORT = 2152;

    public const RESPONSE = 2153;

    /**
     * @param  list<string>  $notify  pubkeys of the challenged lineup's captain(s)
     * @return array{kind: int, tags: list<list<string>>, content: string, created_at: int}
     */
    public static function challenge(SeriesMatch $match, array $notify): array
    {
        $tags = [
            ['a', $match->challenger_lineup_address, '', 'challenger'],
            ['a', $match->challenged_lineup_address, '', 'challenged'],
            ['a', (string) $match->ladder_address, ''],
        ];

        foreach ($notify as $pubkey) {
            $tags[] = ['p', $pubkey];
        }

        $tags[] = ['bo', (string) $match->best_of];

        foreach ($match->proposals as $start) {
            $tags[] = ['start', (string) $start];
        }

        $tags[] = ['respond_by', (string) $match->respond_by->getTimestamp()];
        $tags[] = ['match', (string) $match->number];
        $tags[] = ['alt', "Esports challenge: match #{$match->number}, best of {$match->best_of}, {$match->game} {$match->mode}"];

        return self::template(self::CHALLENGE, $tags, (string) $match->message);
    }

    /**
     * @param  'accepted'|'declined'|'withdrawn'  $status
     * @return array{kind: int, tags: list<list<string>>, content: string, created_at: int}
     */
    public static function answer(SeriesMatch $match, NostrEvent $challenge, string $status, ?int $start, string $notify): array
    {
        $tags = [
            ['e', $challenge->event_id, '', $challenge->pubkey],
            ...self::references($match),
            ['p', $notify],
            ['status', $status],
        ];

        if ($status === 'accepted') {
            $tags[] = ['start', (string) $start];
        }

        $tags[] = ['alt', "Esports challenge answer: {$status}, match #{$match->number}"];

        return self::template(self::ANSWER, $tags, '');
    }

    /**
     * @param  list<array{winner: string, challenger: int|null, challenged: int|null}>  $games
     * @param  list<array{pubkey: string, side: string, role: string}>  $roster
     * @return array{kind: int, tags: list<list<string>>, content: string, created_at: int}
     */
    public static function report(SeriesMatch $match, NostrEvent $challenge, array $games, array $roster, ?string $notify): array
    {
        $tags = [['e', $challenge->event_id, '', $challenge->pubkey], ...self::references($match)];

        foreach ($roster as $entry) {
            $tags[] = ['p', $entry['pubkey'], '', $entry['side'], $entry['role']];
        }

        // NIP "Result Report": a `p` without side for the other captain when not on the roster.
        if ($notify !== null && ! in_array($notify, array_column($roster, 'pubkey'), true)) {
            $tags[] = ['p', $notify];
        }

        foreach ($games as $index => $game) {
            $score = ['score', (string) ($index + 1), $game['winner']];

            if ($game['challenger'] !== null && $game['challenged'] !== null) {
                $score[] = (string) $game['challenger'];
                $score[] = (string) $game['challenged'];
            }

            $tags[] = $score;
        }

        $wins = SeriesMatch::seriesScore($games);
        $tags[] = ['alt', "Esports result report: match #{$match->number}, {$wins['challenger']}-{$wins['challenged']} in games"];

        return self::template(self::REPORT, $tags, '');
    }

    /**
     * @param  'confirmed'|'disputed'  $status
     * @return array{kind: int, tags: list<list<string>>, content: string, created_at: int}
     */
    public static function response(SeriesMatch $match, NostrEvent $report, NostrEvent $challenge, string $status, string $reason): array
    {
        $tags = [
            ['e', $report->event_id, '', $report->pubkey],
            ['e', $challenge->event_id, '', $challenge->pubkey],
            ...self::references($match),
            ['p', $report->pubkey],
            ['status', $status],
            ['alt', "Esports result response: {$status}, match #{$match->number}"],
        ];

        return self::template(self::RESPONSE, $tags, $status === 'disputed' ? $reason : '');
    }

    /**
     * The three `a` references every event after the challenge copies
     * (both lineups and the ladder, without roles).
     *
     * @return list<list<string>>
     */
    private static function references(SeriesMatch $match): array
    {
        return [
            ['a', $match->challenger_lineup_address, ''],
            ['a', $match->challenged_lineup_address, ''],
            ['a', (string) $match->ladder_address, ''],
        ];
    }

    /**
     * @param  list<list<string>>  $tags
     * @return array{kind: int, tags: list<list<string>>, content: string, created_at: int}
     */
    private static function template(int $kind, array $tags, string $content): array
    {
        return ['kind' => $kind, 'tags' => $tags, 'content' => $content, 'created_at' => now()->getTimestamp()];
    }
}
