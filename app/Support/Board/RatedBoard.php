<?php

namespace App\Support\Board;

use App\Games\GameRegistry;
use App\Models\User;
use App\Support\Chess\RatedChess;
use App\Support\FairPlay\FairPlay;
use App\Support\GameNames;
use App\Support\SeasonChain\GatePin;
use App\Support\SeasonChain\RatedTrustGate;
use App\Support\SeasonChain\Seasons;
use App\Support\Series\Ladders;

/**
 * Rated board games (plan "Mühle und Dame", P6) through the same trust gate
 * and season chain as rated chess (App\Support\Chess\RatedChess, P7d), built
 * next to it: a rated game is a league pairing of the board game's queue,
 * so the pairing is its accept, and the gate is checked and pinned then
 * ({@see GatePin}), with each player's clan.
 *
 * Open only while the season is live (the board game's ladder is open),
 * the rated queue is offered (`esports.board_games.rated_queue`) and trust
 * ranks exist. The queue pairs two rated players only if both are Trusted
 * AND list each other, as rated chess: consensus rule 1 gives no block
 * without it. Casual play is untouched.
 */
final class RatedBoard
{
    public function __construct(private RatedTrustGate $gate) {}

    /**
     * Why this player cannot search a rated game of this board game and
     * mode now, or null.
     */
    public function refusal(User $user, string $game, string $mode): ?string
    {
        if (! app(GameRegistry::class)->isBoard($game)) {
            return __('This game has no rated play.');
        }

        if (! Ladders::isOpen($game, $mode)) {
            return Seasons::restMessage($user);
        }

        if (! self::offered()) {
            return __('Rated :game is not open yet. Games are casual for now.', ['game' => GameNames::game($game)]);
        }

        if (! $this->gate->isAvailable()) {
            return RatedTrustGate::message(RatedTrustGate::NOT_COMPUTED);
        }

        // Fair play (P41): a linked second account or a lock after false reports; the message says until when.
        if (($barred = FairPlay::message($user->pubkey, $user)) !== null) {
            return $barred;
        }

        if (! $this->gate->pin([$user->pubkey], [$user->pubkey, $user->pubkey])->isEligible($user->pubkey)) {
            return __('Rated :game needs a Trusted account (trust rank :minimum or more). Casual games with members, and players who add you back, raise your trust.', ['game' => GameNames::game($game), 'minimum' => RatedTrustGate::minimum()]);
        }

        return null;
    }

    /**
     * Whether the rated queue of the board games is offered at all
     * (`esports.board_games.rated_queue`, off by default). While off no
     * board game win can mine, and the mining views say so
     * (ChainOverview::mines()).
     */
    public static function offered(): bool
    {
        return (bool) config('esports.board_games.rated_queue');
    }

    /**
     * The gate of a rated pairing, pinned with the game; null when the two
     * cannot play rated against each other now (not Trusted, not listing
     * each other, no trust ranks, the rated queue off).
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
     * consensus rule 3 and the `2154` `clan` rows: the same reading as a
     * rated chess game's.
     *
     * @return array<string, string>
     */
    public static function clans(User $white, User $black): array
    {
        return RatedChess::clans($white, $black);
    }
}
