<?php

namespace App\Support\SeasonChain;

/**
 * The trust facts consensus rules 1 and 7 read, pinned at the attestation:
 * each player's trust rank (`30382`), whether the two gatekeepers list each
 * other (`30000` opponent lists), and each player's anchor share.
 *
 * The league has no trust job yet (NIP "Trust", `anchored-trust-v1`), so the
 * bound implementation is {@see NoTrustFacts}: no rank, not connected. Rule 1
 * then rejects every win (`not-trusted`), which is the fail-closed reading of
 * "a player without a pinned trust rank is below the minimum".
 */
interface TrustFacts
{
    /**
     * @param  list<string>  $players  pubkeys of every rated player
     * @param  array{0: string, 1: string}  $gatekeepers
     * @return array{trust: array<string, int>, anchors: array<string, array{0: string, 1: int}|null>, connected: bool}
     */
    public function at(array $players, array $gatekeepers): array;
}
