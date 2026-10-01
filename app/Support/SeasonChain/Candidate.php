<?php

namespace App\Support\SeasonChain;

use Carbon\CarbonImmutable;

/**
 * A block candidate: an attested rated result with a winner (a solo game, one
 * board of a chess team match, or a series), with the facts the consensus
 * rules read. Trust ranks, connections and anchors are the values pinned by
 * the attestation's `gate` rows at the accept, supplied by the caller.
 *
 * A **solo** candidate (NIP rev. 9.18, plan "AoE2 und Trackmania", P7) is the
 * winner of a score window the league opened itself: no opponent, so
 * `losers` is empty and `pairing` and `gatekeepers` name the winner twice.
 * Its `solo` facts are the window, how the winning value was read, and the
 * other entrants with a verified value in the window (the field the solo
 * rules 1, 3 and 7 count); trust, clans and anchors cover them too, pinned
 * at the attestation.
 *
 * @phpstan-type Solo array{start: string, end: string, achieved_at: string, source: string, verified: bool, entrants: list<string>}
 */
final class Candidate
{
    /**
     * @param  string  $label  the result, e.g. "#212" or "#404/2" for a board
     * @param  string  $match  the challenge the result belongs to (fees are paid per challenge)
     * @param  string  $game  `chess`, `rocket-league`: share cap and daily limit are per share key (the game, or its group)
     * @param  string  $weightKey  `<game>/<mode>` of the ladder, e.g. `chess/blitz`
     * @param  ?int  $moves  full moves of the counted chess game record; null for a series
     * @param  list<string>  $winners  the winning players (a series: the winning roster)
     * @param  list<string>  $losers  the losing players
     * @param  ?string  $winningSide  the winning lineup (team results) or the winner's clan
     * @param  array{0: string, 1: string}  $pairing  the two rated entities: two players, or two lineups
     * @param  array{0: string, 1: string}  $gatekeepers  the two players (solo, board) or the two gatekeepers of a series
     * @param  bool  $gatekeepersConnected  the gatekeepers list each other (opponent lists pinned in `gate`)
     * @param  array<string, int>  $trust  player => pinned trust rank
     * @param  array<string, ?string>  $clans  player => clan at the attestation
     * @param  array<string, array{0: string, 1: int}|null>  $anchors  player => [anchor, floor(100 * share)] from the pinned `30382`
     * @param  Solo|null  $solo  the facts of a score window (times in ISO 8601 UTC); null for a versus result
     */
    public function __construct(
        public readonly string $label,
        public readonly string $match,
        public readonly string $game,
        public readonly string $weightKey,
        public readonly CarbonImmutable $attestedAt,
        public readonly Resolution $resolution,
        public readonly ?int $moves,
        public readonly array $winners,
        public readonly array $losers,
        public readonly ?string $winningSide,
        public readonly array $pairing,
        public readonly array $gatekeepers,
        public readonly bool $gatekeepersConnected,
        public readonly array $trust,
        public readonly array $clans,
        public readonly array $anchors,
        public readonly ?array $solo = null,
    ) {}

    /**
     * The stored form (season_attestations.candidate), keyed as the ledger
     * fixture (tests/Fixtures/SeasonChain/pre-season-ledger.json). `solo`
     * only for a score window, so a versus candidate keeps the fixture's keys.
     *
     * @return array{label: string, match: string, game: string, weight_key: string, attested_at: string, resolution: string, moves: ?int, winners: list<string>, losers: list<string>, winning_side: ?string, pairing: array{0: string, 1: string}, gatekeepers: array{0: string, 1: string}, gatekeepers_connected: bool, trust: array<string, int>, clans: array<string, ?string>, anchors: array<string, array{0: string, 1: int}|null>, solo?: Solo}
     */
    public function toArray(): array
    {
        $row = [
            'label' => $this->label,
            'match' => $this->match,
            'game' => $this->game,
            'weight_key' => $this->weightKey,
            'attested_at' => $this->attestedAt->utc()->toIso8601ZuluString(),
            'resolution' => $this->resolution->value,
            'moves' => $this->moves,
            'winners' => $this->winners,
            'losers' => $this->losers,
            'winning_side' => $this->winningSide,
            'pairing' => $this->pairing,
            'gatekeepers' => $this->gatekeepers,
            'gatekeepers_connected' => $this->gatekeepersConnected,
            'trust' => $this->trust,
            'clans' => $this->clans,
            'anchors' => $this->anchors,
        ];

        return $this->solo === null ? $row : [...$row, 'solo' => $this->solo];
    }

    /**
     * @param  array<string, mixed>  $row  as written by toArray()
     */
    public static function fromArray(array $row): self
    {
        /** @var array{label: string, match: string, game: string, weight_key: string, attested_at: string, resolution: string, moves: ?int, winners: list<string>, losers: list<string>, winning_side: ?string, pairing: array{0: string, 1: string}, gatekeepers: array{0: string, 1: string}, gatekeepers_connected: bool, trust: array<string, int>, clans: array<string, ?string>, anchors: array<string, array{0: string, 1: int}|null>, solo?: Solo|null} $row */
        return new self(
            $row['label'], $row['match'], $row['game'], $row['weight_key'],
            CarbonImmutable::parse($row['attested_at']),
            Resolution::from($row['resolution']),
            $row['moves'], $row['winners'], $row['losers'], $row['winning_side'],
            $row['pairing'], $row['gatekeepers'], $row['gatekeepers_connected'],
            $row['trust'], $row['clans'], $row['anchors'],
            $row['solo'] ?? null,
        );
    }

    /** @return list<string> */
    public function players(): array
    {
        return [...$this->winners, ...$this->losers];
    }

    /** The winner of a score window, without an opponent (NIP rev. 9.18). */
    public function isSolo(): bool
    {
        return $this->solo !== null;
    }

    /**
     * The pairing of rules 4 and 8: the two rated entities, per game. For a
     * score window the window itself, per game: solo rule 4 allows one block
     * per window and game, whoever wins it.
     */
    public function pairingKey(): string
    {
        if ($this->solo !== null) {
            return $this->game.'|window|'.$this->windowStart()->getTimestamp().'|'.$this->windowEnd()->getTimestamp();
        }

        $entities = $this->pairing;
        sort($entities);

        return $this->game.'|'.implode('|', $entities);
    }

    /** The UTC day of the attestation (rules 4 and 5). */
    public function utcDay(): string
    {
        return $this->attestedAt->utc()->format('Y-m-d');
    }

    /** Start of the score window (included); only for a solo candidate. */
    public function windowStart(): CarbonImmutable
    {
        return CarbonImmutable::parse((string) ($this->solo['start'] ?? ''));
    }

    /** End of the score window (excluded); only for a solo candidate. */
    public function windowEnd(): CarbonImmutable
    {
        return CarbonImmutable::parse((string) ($this->solo['end'] ?? ''));
    }
}
