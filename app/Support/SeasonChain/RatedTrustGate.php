<?php

namespace App\Support\SeasonChain;

use App\Models\Lineup;
use App\Models\SeriesMatch;
use App\Models\User;

/**
 * The trust gate of rated play (NIP "Trust gate"; plan: rated only for
 * Trusted players who list each other): every rated player and both
 * gatekeepers at or above `season.trust_minimum`, and the two gatekeepers
 * (the captains, or the two players of a chess game) list each other.
 * Checked when a rated series is challenged and when it is accepted (a
 * rated chess game: when the league pairs it); at the accept the facts are
 * pinned ({@see GatePin}) and nothing after it re-checks them: "Nothing
 * after the accept undoes the gate".
 *
 * Fail closed: without trust ranks for the live season (no trust run,
 * {@see NoTrustFacts}) every rated action is refused with
 * `trust_not_computed`, a missing rank counts as below the minimum, a
 * missing connection as not connected.
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

        return $this->pin($players, $gatekeepers)->refusal();
    }

    /**
     * The live facts for these players and gatekeepers, as they would be
     * pinned now. Call {@see refusal()} (or check available()) first.
     *
     * @param  list<string>  $players
     * @param  array{0: string, 1: string}  $gatekeepers
     */
    public function pin(array $players, array $gatekeepers): GatePin
    {
        return GatePin::fromFacts($players, $gatekeepers, $this->facts->at($players, $gatekeepers), (int) config('season.trust_minimum'));
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

    /**
     * The pin stored with the accept: the same players and gatekeepers as
     * {@see forAccept()}, which has to have passed.
     */
    public function pinForAccept(SeriesMatch $match, User $answering): GatePin
    {
        $players = $match->challengerLineup === null || $match->challengedLineup === null
            ? []
            : self::players($match->challengerLineup, $match->challengedLineup);

        return $this->pin($players, [(string) $match->createdBy?->pubkey, $answering->pubkey]);
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
