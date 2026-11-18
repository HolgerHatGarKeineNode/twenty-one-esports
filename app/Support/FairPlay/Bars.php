<?php

namespace App\Support\FairPlay;

use App\Models\FairPlayBar;
use App\Models\User;
use App\Support\LeagueTime;
use App\Support\Nostr\NostrKeys;
use App\Support\SeasonChain\Seasons;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * An admin bars a player from rated play by hand and lifts the bar again
 * (fair play). The bar has a reason (required, internal: the player reads
 * only that the league locked rated play and until when,
 * {@see FairPlay::message()}), an end (a time, or the end of the live
 * season) and who set it; it stays in the record when it ends or is lifted.
 *
 * A barred player is refused new rated play like a locked one
 * ({@see FairPlay::barred()}); a match accepted before keeps its pinned
 * gate. Nothing else changes: blocks mined and prizes won before stay as
 * they are (only a linked account wins no prize, {@see AccountLinks}), and
 * the season review alone voids results.
 */
final class Bars
{
    /** The longest reason an admin can give. */
    public const REASON_MAX = 280;

    /**
     * Bar the account `$playerKey` (npub or hex) until `$until`, or until
     * the live season ends when it is null.
     *
     * @throws FairPlayRefused
     */
    public function set(User $admin, string $playerKey, string $reason, ?CarbonImmutable $until = null): FairPlayBar
    {
        $this->assertAdmin($admin);
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > self::REASON_MAX) {
            throw new FairPlayRefused(__('Give a reason, up to :max characters.', ['max' => self::REASON_MAX]));
        }

        $pubkey = NostrKeys::toHex(trim($playerKey)) ?? throw new FairPlayRefused(__('Enter an npub or a 64-character hex public key.'));
        $player = User::query()->where('pubkey', $pubkey)->first() ?? throw new FairPlayRefused(__('This key has no account in the league.'));

        if ($player->pubkey === $admin->pubkey) {
            throw new FairPlayRefused(__('You cannot decide about your own accounts. Another admin has to.'));
        }

        if ($until === null) {
            $season = Seasons::live() ?? throw new FairPlayRefused(__('No season is live: give the bar an end.'));
            $until = CarbonImmutable::instance($season->ends_at);
        }

        if (! $until->isFuture()) {
            throw new FairPlayRefused(__('The end of the bar has to lie in the future.'));
        }

        return DB::transaction(function () use ($admin, $player, $reason, $until): FairPlayBar {
            // One decision at a time for this account: the check below reads under the lock.
            User::query()->whereKey($player->id)->lockForUpdate()->first();
            $running = FairPlayBar::query()->running()->where('pubkey', $player->pubkey)->orderByDesc('until')->first();

            if ($running !== null) {
                throw new FairPlayRefused(__(':name is barred already, until :time. Lift that bar first to set another.', ['name' => $player->displayName(), 'time' => LeagueTime::stamp($running->until)]));
            }

            return FairPlayBar::query()->create([
                'user_id' => $player->id,
                'pubkey' => $player->pubkey,
                'reason' => $reason,
                'until' => $until,
                'barred_by_id' => $admin->id,
                'barred_by_pubkey' => $admin->pubkey,
            ]);
        });
    }

    /**
     * Lift a running bar before its end: rated play is open again at once.
     *
     * @throws FairPlayRefused
     */
    public function lift(User $admin, FairPlayBar $bar): void
    {
        $this->assertAdmin($admin);

        if ($bar->pubkey === $admin->pubkey) {
            throw new FairPlayRefused(__('You cannot decide about your own accounts. Another admin has to.'));
        }

        $lifted = FairPlayBar::query()->running()->whereKey($bar->id)->update([
            'lifted_at' => now(),
            'lifted_by_id' => $admin->id,
            'lifted_by_pubkey' => $admin->pubkey,
        ]);

        if ($lifted === 0) {
            throw new FairPlayRefused(__('This bar ended or was lifted already.'));
        }
    }

    /** @throws FairPlayRefused */
    private function assertAdmin(User $admin): void
    {
        if (! Gate::forUser($admin)->allows('admin')) {
            throw new FairPlayRefused(__('Only admins can bar players from rated play.'));
        }
    }
}
