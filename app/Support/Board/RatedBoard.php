<?php

namespace App\Support\Board;

use App\Games\GameRegistry;
use App\Models\User;
use App\Support\Chess\RatedChess;
use App\Support\FairPlay\FairPlay;
use App\Support\GameNames;
use App\Support\SeasonChain\ChainOverview;
use App\Support\SeasonChain\GatePin;
use App\Support\SeasonChain\RatedTrustGate;
use App\Support\SeasonChain\Seasons;
use App\Support\Series\Ladders;
use Illuminate\Support\Facades\Cache;
use Throwable;

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
        return $this->reason($user, $game, $mode)['message'] ?? null;
    }

    /**
     * The refusal with its class, as RatedChess::reason() gives it for
     * chess (plan "Season vs. Casual", P3/P4), so a view can name the kind
     * of reason in a word: `none` (not a board game), `season` (no season
     * live), `later` (a running season without this game yet), `closed`
     * (the rated queue off, or trust ranks not computed yet), `barred` (fair
     * play), `standard` (below the profile standard: the player can fix it),
     * `held` (an impersonation check waits for an admin) or `trust` (below
     * the trust minimum). Null while rated play is open for this player.
     *
     * @return array{kind: 'none'|'season'|'later'|'closed'|'barred'|'standard'|'held'|'trust', message: string}|null
     */
    public function reason(User $user, string $game, string $mode): ?array
    {
        if (! app(GameRegistry::class)->isBoard($game)) {
            return ['kind' => 'none', 'message' => __('This game has no rated play.')];
        }

        if (! Ladders::isOpen($game, $mode)) {
            // A season live since before the board games joined: their ladder opens with the board's next rule change.
            return Seasons::live() !== null
                ? ['kind' => 'later', 'message' => __('Rated :game starts when the board adds it to the running season.', ['game' => GameNames::game($game)])]
                : ['kind' => 'season', 'message' => Seasons::restMessage($user)];
        }

        if (! self::offered()) {
            return ['kind' => 'closed', 'message' => __('Rated :game is not open yet. Games are casual for now.', ['game' => GameNames::game($game)])];
        }

        if (! $this->gate->isAvailable()) {
            return ['kind' => 'closed', 'message' => RatedTrustGate::message(RatedTrustGate::NOT_COMPUTED)];
        }

        // Fair play (P41): a linked second account or a lock after false reports; the message says until when.
        if (($barred = FairPlay::message($user->pubkey, $user)) !== null) {
            $kind = FairPlay::barred([$user->pubkey])[$user->pubkey]['kind'] ?? null;

            return ['kind' => in_array($kind, ['standard', 'held'], true) ? $kind : 'barred', 'message' => $barred];
        }

        if (! $this->gate->pin([$user->pubkey], [$user->pubkey, $user->pubkey])->isEligible($user->pubkey)) {
            return ['kind' => 'trust', 'message' => __('Rated :game needs a Trusted account (trust rank :minimum or more). Casual games with members, and players who add you back, raise your trust.', ['game' => GameNames::game($game), 'minimum' => RatedTrustGate::minimum()])];
        }

        return null;
    }

    /**
     * What a rated win of this board game mines right now per mode (sats
     * per winner), the figures /mining shows (ChainOverview::live()), as
     * RatedChess::rewardsNow() does for chess. Empty without a live season,
     * while the rated queue is off or the game does not mine. Cached for a
     * minute, scalars only (the cache unserializes no objects).
     *
     * @param  list<string>  $modes
     * @return array<string, int>
     */
    public static function rewardsNow(string $game, array $modes): array
    {
        $season = Seasons::live();

        if ($season === null || ! self::offered() || $modes === []) {
            return [];
        }

        $read = function () use ($season, $game, $modes): array {
            $now = app(ChainOverview::class)->live($season)['rewards_now'];
            $rewards = [];

            foreach ($modes as $mode) {
                if (Ladders::isOpen($game, $mode) && ($sats = (int) ($now[$game.'/'.$mode] ?? 0)) > 0) {
                    $rewards[$mode] = $sats;
                }
            }

            return $rewards;
        };

        try {
            /** @var array<string, int> $rewards */
            $rewards = Cache::remember('board:rewards-now:'.$season->id.':'.$game.':'.implode(',', $modes), 60, $read);
        } catch (Throwable $e) {
            report($e);
            $rewards = $read();
        }

        return $rewards;
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
     * Why these two cannot play a rated game against each other now, as a
     * RatedTrustGate code (NOT_CONNECTED: they do not list each other, the
     * P57 notice), or null. For a correspondence challenge (P8), which asks
     * before the accept that pin() then reads again.
     *
     * @return RatedTrustGate::NOT_COMPUTED|RatedTrustGate::NOT_TRUSTED|RatedTrustGate::NOT_CONNECTED|RatedTrustGate::FAIR_PLAY|null
     */
    public function pairRefusal(User $white, User $black): ?string
    {
        if (! self::offered()) {
            return RatedTrustGate::NOT_COMPUTED;
        }

        return $this->gate->refusal([$white->pubkey, $black->pubkey], [$white->pubkey, $black->pubkey]);
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
