<?php

namespace App\Support\SeasonChain;

use App\Models\TrustExclusion;
use App\Models\TrustReportDismissal;
use App\Models\User;
use App\Support\Nostr\NostrKeys;

/**
 * The admin decisions of NIP "Reports", applied by the next trust run
 * ({@see TrustJob}): dismiss a report (it stops counting) or exclude a
 * pubkey from the graph (raw 0, its list vouches for nobody), and undo
 * either. Only admins decide; every decision keeps its reason.
 */
final class TrustAdmin
{
    public const REASON_MAX = 280;

    /** @throws TrustAdminRefused */
    public function dismiss(User $admin, string $eventId, string $reason): TrustReportDismissal
    {
        $this->assertAdmin($admin);

        if (preg_match('/^[0-9a-f]{64}$/', $eventId) !== 1) {
            throw new TrustAdminRefused(__('This is not a report id.'));
        }

        return TrustReportDismissal::query()->updateOrCreate(['event_id' => $eventId], ['dismissed_by_id' => $admin->id, 'reason' => $this->reason($reason)]);
    }

    /** @throws TrustAdminRefused */
    public function restore(User $admin, string $eventId): void
    {
        $this->assertAdmin($admin);

        TrustReportDismissal::query()->where('event_id', $eventId)->delete();
    }

    /** @throws TrustAdminRefused */
    public function exclude(User $admin, string $key, string $reason): TrustExclusion
    {
        $this->assertAdmin($admin);

        $pubkey = NostrKeys::toHex($key) ?? throw new TrustAdminRefused(__('Enter an npub or a 64-character hex public key.'));

        return TrustExclusion::query()->updateOrCreate(['pubkey' => $pubkey], ['excluded_by_id' => $admin->id, 'reason' => $this->reason($reason)]);
    }

    /** @throws TrustAdminRefused */
    public function lift(User $admin, string $pubkey): void
    {
        $this->assertAdmin($admin);

        TrustExclusion::query()->where('pubkey', $pubkey)->delete();
    }

    /** @throws TrustAdminRefused */
    private function assertAdmin(User $admin): void
    {
        if (! $admin->isAdmin()) {
            throw new TrustAdminRefused(__('Only admins can decide on reports.'));
        }
    }

    /** @throws TrustAdminRefused */
    private function reason(string $reason): string
    {
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > self::REASON_MAX) {
            throw new TrustAdminRefused(__('Give a reason, up to :max characters.', ['max' => self::REASON_MAX]));
        }

        return $reason;
    }
}
