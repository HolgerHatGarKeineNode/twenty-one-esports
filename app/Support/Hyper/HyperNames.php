<?php

namespace App\Support\Hyper;

use App\Models\HyperMatch;
use App\Models\HyperSeat;

/**
 * How the league's pages outside the match name a Hyperbitcoinization seat and its faction (plan
 * "Hyperbitcoinization", P6): the match list, the mempool strip, the link previews and the share posts. A player
 * by their display name, a bot by its faction ("Fed (bot)"), a deleted account as such.
 */
final class HyperNames
{
    /** The factions by name; only the ECB is translated (EZB), the others are the same in every language. */
    public const FACTIONS = ['bitcoiner' => 'Bitcoiner', 'fed' => 'Fed', 'ezb' => 'ECB', 'goldbug' => 'Goldbug', 'shitcoiner' => 'Shitcoiner', 'nocoiner' => 'Nocoiner'];

    public static function faction(string $faction): string
    {
        return __(self::FACTIONS[$faction] ?? $faction);
    }

    public static function seat(HyperSeat $seat): string
    {
        return match (true) {
            // A player's seat stays theirs when a bot took it over (left, timeouts, forfeit).
            $seat->user !== null => $seat->user->displayName(),
            $seat->bot => __(':name (bot)', ['name' => self::faction($seat->faction)]),
            default => __('Deleted player'),
        };
    }

    /**
     * The winner of a finished match: the winning team's name in a team match, else the player on place 1;
     * null while it runs or for a voided match.
     */
    public static function winner(HyperMatch $match): ?string
    {
        $match->loadMissing('seats.user');
        $first = $match->seats->firstWhere('place', 1);

        if ($first === null) {
            return null;
        }

        if ($match->isTeamMatch()) {
            return HyperTeams::sides($match->team_clans, HyperTeams::preloaded($match))[(int) $first->team]['name'] ?? self::seat($first);
        }

        return self::seat($first);
    }

    /**
     * The seats in the order a list shows them: by place once over (the winner first), else by seat.
     *
     * @return list<HyperSeat>
     */
    public static function ordered(HyperMatch $match): array
    {
        $match->loadMissing('seats.user');

        return array_values($match->seats->sortBy([fn (HyperSeat $a, HyperSeat $b): int => ($a->place ?? PHP_INT_MAX) <=> ($b->place ?? PHP_INT_MAX), ['seat', 'asc']])->all());
    }
}
