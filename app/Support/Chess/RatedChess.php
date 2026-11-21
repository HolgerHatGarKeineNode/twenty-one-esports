<?php

namespace App\Support\Chess;

use App\Models\ClanMember;
use App\Models\User;
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
        return $this->reason($user, $mode)['message'] ?? null;
    }

    /**
     * The refusal with its class, so a view can name the kind of reason in
     * a word: `season` (no season live), `later` (a running season without
     * this mode yet), `closed` (rated chess off, or trust ranks not computed
     * yet), `barred` (fair play), `standard` (below the profile standard, no
     * bar: the player can fix it) or `trust` (below the trust minimum). Null
     * while rated chess is open for this player in this mode.
     *
     * @return array{kind: 'season'|'later'|'closed'|'barred'|'standard'|'trust', message: string}|null
     */
    public function reason(User $user, string $mode): ?array
    {
        if (! Ladders::isOpen('chess', $mode)) {
            // A season live since before this mode joined (chess rapid, NIP rev. 9.22): its ladder opens with the board's next rule change.
            return Seasons::live() !== null
                ? ['kind' => 'later', 'message' => __('Rated :game starts when the board adds it to the running season.', ['game' => GameNames::full('chess', $mode)])]
                : ['kind' => 'season', 'message' => Seasons::restMessage($user)];
        }

        if (! self::offered()) {
            return ['kind' => 'closed', 'message' => __('Rated chess is not open yet. Rapid and blitz games are casual for now.')];
        }

        if (! $this->gate->isAvailable()) {
            return ['kind' => 'closed', 'message' => RatedTrustGate::message(RatedTrustGate::NOT_COMPUTED)];
        }

        // Fair play (P41): a linked second account or a lock after false reports; the message says until when.
        if (($barred = FairPlay::message($user->pubkey, $user)) !== null) {
            $standard = (FairPlay::barred([$user->pubkey])[$user->pubkey]['kind'] ?? null) === 'standard';

            return ['kind' => $standard ? 'standard' : 'barred', 'message' => $barred];
        }

        if (! $this->gate->pin([$user->pubkey], [$user->pubkey, $user->pubkey])->isEligible($user->pubkey)) {
            return ['kind' => 'trust', 'message' => __('Rated chess needs a Trusted account (trust rank :minimum or more). Casual games with members, and players who add you back, raise your trust.', ['minimum' => RatedTrustGate::minimum()])];
        }

        return null;
    }

    /**
     * What a rated win mines right now per live mode (sats per winner), for
     * the lobby's chip: the reward in the era chess itself stands in (it
     * halves by what chess mined, whatever the other games did), the same
     * figure /mining shows (ChainOverview::live()). Empty without a live
     * season or while chess does not mine. Cached for a minute, scalars
     * only (the cache unserializes no objects).
     *
     * @return array<string, int>
     */
    public static function rewardsNow(): array
    {
        $season = Seasons::live();

        if ($season === null || ! self::offered()) {
            return [];
        }

        $read = function () use ($season): array {
            $now = app(ChainOverview::class)->live($season)['rewards_now'];
            $rewards = [];

            foreach (ChessModes::live() as $mode) {
                if (Ladders::isOpen('chess', $mode) && ($sats = (int) ($now['chess/'.$mode] ?? 0)) > 0) {
                    $rewards[$mode] = $sats;
                }
            }

            return $rewards;
        };

        try {
            /** @var array<string, int> $rewards */
            $rewards = Cache::remember('chess:rewards-now:'.$season->id, 60, $read);
        } catch (Throwable $e) {
            report($e);
            $rewards = $read();
        }

        return $rewards;
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
