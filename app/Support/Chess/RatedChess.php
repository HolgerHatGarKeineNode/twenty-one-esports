<?php

namespace App\Support\Chess;

use App\Models\ClanMember;
use App\Models\User;
use App\Support\SeasonChain\GatePin;
use App\Support\SeasonChain\RatedTrustGate;
use App\Support\SeasonChain\Seasons;
use App\Support\Series\Ladders;

/**
 * Rated chess through the same trust gate and season chain as a rated
 * series (P7d). A rated blitz game is a league pairing (the queue): the
 * pairing is its accept, so the gate is checked and pinned then
 * ({@see GatePin}), with each player's clan.
 *
 * Open only while the season is live (a chess ladder is open) and trust
 * ranks exist; casual play is untouched. The queue pairs two rated players
 * only if both are Trusted AND list each other: the NIP lets league
 * pairings skip the mutual listing, but the plan's decision ("Gewertete
 * Partien nur bei gegenseitigem Folgen") and consensus rule 1 (no block
 * without it) ask for it, and a queue pairing can be steered by two players
 * who join at the same moment.
 */
final class RatedChess
{
    public function __construct(private RatedTrustGate $gate) {}

    /**
     * Why this player cannot search a rated game in this mode now, or null.
     */
    public function refusal(User $user, string $mode): ?string
    {
        if (! Ladders::isOpen('chess', $mode)) {
            return Seasons::restMessage($user);
        }

        if (! self::offered()) {
            return __('Rated chess is not open yet. Blitz games are casual for now.');
        }

        if (! $this->gate->isAvailable()) {
            return RatedTrustGate::message(RatedTrustGate::NOT_COMPUTED);
        }

        if (! $this->gate->pin([$user->pubkey], [$user->pubkey, $user->pubkey])->isEligible($user->pubkey)) {
            return __('Rated chess needs a Trusted account (trust rank :minimum or more). Casual games with members, and players who add you back, raise your trust.', ['minimum' => RatedTrustGate::minimum()]);
        }

        return null;
    }

    /**
     * Whether rated blitz is offered at all (`esports.chess.rated_queue`):
     * off until the lobby has a Rated choice. While off no chess win can
     * mine, and the mining views say so instead of showing chess rewards as
     * achievable (ChainOverview::mines()).
     */
    public static function offered(): bool
    {
        return (bool) config('esports.chess.rated_queue');
    }

    /**
     * The gate of a rated pairing, pinned with the game; null when the two
     * cannot play rated against each other now.
     */
    public function pin(User $white, User $black): ?GatePin
    {
        if (! self::offered() || ! $this->gate->isAvailable()) {
            return null;
        }

        $pin = $this->gate->pin([$white->pubkey, $black->pubkey], [$white->pubkey, $black->pubkey]);

        return $pin->refusal() === null ? $pin : null;
    }

    /**
     * Each player's clan at the pairing (pubkey => clan address), for
     * consensus rule 3 and the 2154 `clan` rows.
     *
     * @return array<string, string>
     */
    public static function clans(User $white, User $black): array
    {
        $clans = [];

        foreach (ClanMember::query()->with(['clan', 'user'])->whereIn('user_id', [$white->id, $black->id])->get() as $member) {
            $clans[$member->user->pubkey] = $member->clan->address();
        }

        return $clans;
    }
}
