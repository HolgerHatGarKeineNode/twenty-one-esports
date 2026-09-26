<?php

namespace App\Support\SeasonChain;

/**
 * The trust gate of one rated match as it stood at the accept (NIP "Trust
 * gate"; for a league pairing, at the pairing): the trust key and minimum in
 * force, the two gatekeepers, whether they listed each other, and per player
 * the rank, the assertion id and the opponent-list id the gate used.
 *
 * "Nothing after the accept undoes the gate": the rating, consensus rule 1
 * and the attestation's `trust` and `gate` rows read only this pin, never
 * live trust facts, so a player who unfollows or loses rank after the accept
 * cannot unrate a match he is losing. The one check left at the result is
 * the NIP's condition 3: a roster that lists a player who was not eligible
 * at the accept ({@see isEligible()}) is invalid.
 */
final class GatePin
{
    /**
     * @param  array{0: string, 1: string}  $gatekeepers
     * @param  array<string, array{rank: int, assertion: ?string, list: ?string, anchor: array{0: string, 1: int}|null}>  $players  pubkey => facts, gatekeepers included
     */
    public function __construct(
        public readonly string $trustKey,
        public readonly int $minimum,
        public readonly array $gatekeepers,
        public readonly bool $connected,
        public readonly array $players,
    ) {}

    /**
     * Pin the facts the trust job answers for these players and gatekeepers.
     *
     * @param  list<string>  $players
     * @param  array{0: string, 1: string}  $gatekeepers
     * @param  array{trust: array<string, int>, anchors: array<string, array{0: string, 1: int}|null>, connected: bool, assertions?: array<string, string>, lists?: array<string, string>, key?: string}  $facts
     */
    public static function fromFacts(array $players, array $gatekeepers, array $facts, int $minimum): self
    {
        $pinned = [];

        foreach (array_values(array_unique([...$gatekeepers, ...$players])) as $pubkey) {
            if ($pubkey === '') {
                continue;
            }

            $pinned[$pubkey] = [
                'rank' => (int) ($facts['trust'][$pubkey] ?? 0),
                'assertion' => $facts['assertions'][$pubkey] ?? null,
                'list' => $facts['lists'][$pubkey] ?? null,
                'anchor' => $facts['anchors'][$pubkey] ?? null,
            ];
        }

        return new self($facts['key'] ?? '', $minimum, $gatekeepers, $facts['connected'], $pinned);
    }

    /**
     * @param  array<string, mixed>|null  $stored  as written by toArray(); null = never pinned
     */
    public static function fromArray(?array $stored): ?self
    {
        if ($stored === null || ! isset($stored['gatekeepers'], $stored['players']) || ! is_array($stored['players'])) {
            return null;
        }

        /** @var array{trust_key: string, minimum: int, gatekeepers: array{0: string, 1: string}, connected: bool, players: array<string, array{rank: int, assertion: ?string, list: ?string, anchor: array{0: string, 1: int}|null}>} $stored */
        return new self((string) $stored['trust_key'], (int) $stored['minimum'], $stored['gatekeepers'], (bool) $stored['connected'], $stored['players']);
    }

    /**
     * @return array{trust_key: string, minimum: int, gatekeepers: array{0: string, 1: string}, connected: bool, players: array<string, array{rank: int, assertion: ?string, list: ?string, anchor: array{0: string, 1: int}|null}>}
     */
    public function toArray(): array
    {
        return [
            'trust_key' => $this->trustKey,
            'minimum' => $this->minimum,
            'gatekeepers' => $this->gatekeepers,
            'connected' => $this->connected,
            'players' => $this->players,
        ];
    }

    /** NIP condition 3: pinned at the accept with a rank at or above the minimum then. */
    public function isEligible(string $pubkey): bool
    {
        return isset($this->players[$pubkey]) && $this->players[$pubkey]['rank'] >= $this->minimum;
    }

    /**
     * NIP conditions 2 and 1 on the pinned values: each gatekeeper at or
     * above the minimum, and the two list each other; null when they pass.
     * Other players below the minimum do not refuse the accept (condition
     * 3): they are ineligible for this match ({@see isEligible()}).
     *
     * @return RatedTrustGate::NOT_TRUSTED|RatedTrustGate::NOT_CONNECTED|null
     */
    public function refusal(): ?string
    {
        foreach ($this->gatekeepers as $gatekeeper) {
            if (! $this->isEligible($gatekeeper)) {
                return RatedTrustGate::NOT_TRUSTED;
            }
        }

        return $this->connected ? null : RatedTrustGate::NOT_CONNECTED;
    }

    /**
     * The pinned rank of each of these players; a player the accept did not
     * see has none (rule 1 then reads rank 0: fail closed).
     *
     * @param  list<string>  $pubkeys
     * @return array<string, int>
     */
    public function ranks(array $pubkeys): array
    {
        $ranks = [];

        foreach ($pubkeys as $pubkey) {
            if (isset($this->players[$pubkey])) {
                $ranks[$pubkey] = $this->players[$pubkey]['rank'];
            }
        }

        return $ranks;
    }

    /**
     * @param  list<string>  $pubkeys
     * @return array<string, array{0: string, 1: int}|null>
     */
    public function anchors(array $pubkeys): array
    {
        $anchors = [];

        foreach (array_unique([...$this->gatekeepers, ...$pubkeys]) as $pubkey) {
            $anchors[$pubkey] = $this->players[$pubkey]['anchor'] ?? null;
        }

        return $anchors;
    }

    /**
     * The attestation's evidence (NIP "Trust gate", "Evidence"): the `trust`
     * tag in force at the accept (when the trust key is known) and one `gate`
     * row per gatekeeper and rated player.
     *
     * @param  list<string>  $rated  pubkeys of the rated players
     * @return list<list<string>>
     */
    public function tags(array $rated): array
    {
        $tags = $this->trustKey === '' ? [] : [['trust', $this->trustKey, (string) $this->minimum]];

        foreach (array_values(array_unique([...$this->gatekeepers, ...$rated])) as $pubkey) {
            $facts = $this->players[$pubkey] ?? null;

            if ($facts !== null) {
                $tags[] = ['gate', $pubkey, (string) $facts['rank'], (string) $facts['assertion'], (string) $facts['list']];
            }
        }

        return $tags;
    }
}
