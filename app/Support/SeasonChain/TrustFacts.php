<?php

namespace App\Support\SeasonChain;

/**
 * The live trust facts the gate pins at the accept ({@see GatePin}): each
 * player's trust rank (`30382`) with the id of the assertion it comes from,
 * whether the two gatekeepers list each other (`30000` opponent lists) with
 * the ids of those lists, each player's anchor share, and the trust key.
 *
 * Read only when a rated match is challenged, accepted or paired; everything
 * after that reads the pin. Without a trust run for the live season the
 * bound implementation answers `available() === false` ({@see NoTrustFacts}
 * before any run, {@see AnchoredTrustFacts} otherwise), and the rated trust
 * gate ({@see RatedTrustGate}) keeps rated play closed.
 */
interface TrustFacts
{
    /** Whether trust ranks exist for the live season; false until the trust job ran. */
    public function available(): bool;

    /**
     * @param  list<string>  $players  pubkeys of every rated player
     * @param  array{0: string, 1: string}  $gatekeepers
     * @return array{trust: array<string, int>, anchors: array<string, array{0: string, 1: int}|null>, connected: bool, assertions?: array<string, string>, lists?: array<string, string>, key?: string}
     */
    public function at(array $players, array $gatekeepers): array;
}
