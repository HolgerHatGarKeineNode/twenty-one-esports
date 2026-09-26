<?php

namespace App\Support\SeasonChain;

use App\Models\ChessGame;
use App\Models\Lineup;
use App\Models\SeriesMatch;
use App\Models\SeriesReport;
use App\Models\User;

/**
 * The trust gate of rated play (NIP "Trust gate"; plan: rated only for
 * Trusted players who list each other): every rated player at or above
 * `season.trust_minimum`, and the two gatekeepers (the captains) list each
 * other. Checked when a rated series is challenged, when it is accepted and
 * again at the result, so a trust rank that dropped in between moves no
 * rated Elo.
 *
 * Fail closed: without trust ranks (no trust job, {@see NoTrustFacts}) every
 * rated action is refused with `trust_not_computed`, a missing rank counts
 * as below the minimum, a missing connection as not connected.
 */
final class RatedTrustGate
{
    public const NOT_COMPUTED = 'trust_not_computed';

    public const NOT_TRUSTED = 'not_trusted';

    public const NOT_CONNECTED = 'not_connected';

    public function __construct(private TrustFacts $facts) {}

    /** Whether rated play can be offered at all (trust ranks exist). */
    public function isAvailable(): bool
    {
        return $this->facts->available();
    }

    /**
     * @param  list<string>  $players  pubkeys of every rated player
     * @param  array{0: string, 1: string}  $gatekeepers
     * @return self::NOT_COMPUTED|self::NOT_TRUSTED|self::NOT_CONNECTED|null
     */
    public function refusal(array $players, array $gatekeepers): ?string
    {
        if (! $this->facts->available()) {
            return self::NOT_COMPUTED;
        }

        $facts = $this->facts->at($players, $gatekeepers);
        $minimum = (int) config('season.trust_minimum');

        foreach ($players as $player) {
            if (($facts['trust'][$player] ?? 0) < $minimum) {
                return self::NOT_TRUSTED;
            }
        }

        return $facts['connected'] ? null : self::NOT_CONNECTED;
    }

    /**
     * Before the accept: the active players of both lineups, and the author
     * with any acting captain of the challenged lineup (who will answer).
     *
     * @param  list<string>  $challengedCaptains  pubkeys
     */
    public function forChallenge(Lineup $challenger, Lineup $challenged, User $author, array $challengedCaptains): ?string
    {
        $players = self::players($challenger, $challenged);
        $refusal = self::NOT_CONNECTED;

        foreach ($challengedCaptains as $captain) {
            $refusal = $this->refusal($players, [$author->pubkey, $captain]);

            if ($refusal === null) {
                return null;
            }
        }

        return $refusal;
    }

    /** At the accept: the active players of both lineups, the author and the answering captain. */
    public function forAccept(SeriesMatch $match, User $answering): ?string
    {
        if ($match->challengerLineup === null || $match->challengedLineup === null) {
            return self::NOT_TRUSTED;
        }

        return $this->refusal(self::players($match->challengerLineup, $match->challengedLineup), [(string) $match->createdBy?->pubkey, $answering->pubkey]);
    }

    /** At the result: the roster of the counted report, the author and the captain who accepted. */
    public function forResult(SeriesMatch $match): ?string
    {
        $report = $match->latestReport;
        $players = $report instanceof SeriesReport ? array_map(fn (array $entry): string => $entry['pubkey'], $report->roster) : [];

        if ($players === []) {
            return self::NOT_TRUSTED;
        }

        return $this->refusal($players, [(string) $match->createdBy?->pubkey, (string) $match->answeredBy?->pubkey]);
    }

    /** A rated chess game: both players, who are also the gatekeepers. */
    public function forChessGame(ChessGame $game): ?string
    {
        $white = $game->white->pubkey;
        $black = $game->black->pubkey;

        return $this->refusal([$white, $black], [$white, $black]);
    }

    /** The refusal in plain words. */
    public static function message(string $refusal): string
    {
        return match ($refusal) {
            self::NOT_COMPUTED => __('Rated play opens once trust ranks are computed. Until then every match is casual.'),
            self::NOT_TRUSTED => __('Rated play needs every player of both lineups to be Trusted (trust rank :minimum or more).', ['minimum' => (int) config('season.trust_minimum')]),
            default => __('Rated play needs both captains to have added each other as opponents.'),
        };
    }

    /**
     * @return list<string>
     */
    private static function players(Lineup $a, Lineup $b): array
    {
        $players = [];

        foreach ([$a, $b] as $lineup) {
            foreach ($lineup->activeSeats() as $seat) {
                $players[] = $seat->user->pubkey;
            }
        }

        return array_keys(array_flip($players));
    }
}
