<?php

namespace App\Support\FairPlay;

use App\Models\User;
use App\Support\LeagueTime;

/**
 * What a member reads before accepting an opponent request (plan "Profile
 * standard and impersonation", P4): why rated play with the requester stays
 * closed for now, so the accept is a decision and not a reflex.
 *
 * - an open impersonation hold ({@see Impersonation::held()}): only that the
 *   account is being checked, never whom it resembles or why;
 * - the hard marks the requester lacks ({@see ProfileStandard::below()}), in
 *   the player's own step order and in plain words.
 *
 * Each part follows its switch and the exemption exactly as rated play does:
 * while a switch is off, its part warns about nothing, so a member never
 * reads about a rule that does not apply yet, and nothing is queried. Read
 * on the request cards of /settings/opponents and by the opponent button
 * (player page, match room, lobby notices; D17). The accept stays possible;
 * {@see FairPlay::barred()} keeps the requester out of rated play until the
 * standard is met or the hold ends.
 */
final class AcceptWarnings
{
    /**
     * The warnings per requester, only for those with at least one. At most
     * one query per switch that is on, plus the exemption, for any number of
     * requesters.
     *
     * @param  iterable<User>  $players
     * @return array<string, array{held: bool, missing: list<array{key: 'known'|'nip05'|'profile', text: string}>}>
     */
    public static function for(iterable $players): array
    {
        $byPubkey = [];

        foreach ($players as $player) {
            $byPubkey[$player->pubkey] = $player;
        }

        $pubkeys = array_keys($byPubkey);
        $held = array_flip(Impersonation::held($pubkeys));
        $below = ProfileStandard::below($pubkeys);
        $warnings = [];

        foreach ($byPubkey as $pubkey => $player) {
            $missing = array_map(fn (string $mark): array => ['key' => $mark, 'text' => self::text($player, $mark)], $below[$pubkey]['missing'] ?? []);

            if (isset($held[$pubkey]) || $missing !== []) {
                $warnings[$pubkey] = ['held' => isset($held[$pubkey]), 'missing' => $missing];
            }
        }

        return $warnings;
    }

    /** The missing mark as the accepting member reads it: the player's own step, said about someone else. */
    private static function text(User $player, string $mark): string
    {
        return match ($mark) {
            ProfileStandard::KNOWN => __('New here: an account plays rated from :days days after its first login, so from :time.', [
                'days' => ProfileStandard::knownDays(), 'time' => LeagueTime::stamp(ProfileStandard::knownAt($player)),
            ]),
            ProfileStandard::NIP05 => __('No NIP-05 address set.'),
            // The same three texts as the admin check page (P3), so the line says exactly what is missing.
            default => match (true) {
                trim((string) $player->name) === '' && trim((string) $player->picture) === '' => __('No name and no profile picture'),
                trim((string) $player->name) === '' => __('No name in the profile'),
                default => __('No profile picture'),
            },
        };
    }
}
