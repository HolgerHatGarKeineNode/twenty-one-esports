<?php

namespace App\Support\SeasonChain;

use App\Models\ClanDeparture;
use App\Models\ClanMember;
use App\Models\NostrEvent;
use App\Models\TrustDecision;
use App\Models\TrustExclusion;
use App\Models\TrustReportDismissal;
use App\Models\User;
use App\Support\Board;
use App\Support\Nostr\NostrKeys;
use Illuminate\Support\Facades\DB;

/**
 * The admin decisions of NIP "Reports", applied by the next trust run
 * ({@see TrustJob}): dismiss a report (it stops counting) or exclude a
 * pubkey from the graph (raw 0, its list vouches for nobody), and undo
 * either.
 *
 * Security re-check round 3:
 * - Every decision and every undo takes a reason and goes into the
 *   append-only log ({@see TrustDecision}), with the actor.
 * - Nobody decides about himself or his clan: a report whose author or
 *   target is the acting admin or in the admin's clan, or such a key, is
 *   refused (as SeriesService::assertCanDecide does for matches).
 * - Dismissing a single report is for every admin; excluding a key (and
 *   lifting an exclusion) takes a key out of the whole graph and is for the
 *   board ({@see Board}).
 */
final class TrustAdmin
{
    public const REASON_MAX = 280;

    /** @throws TrustAdminRefused */
    public function dismiss(User $admin, string $eventId, string $reason): TrustReportDismissal
    {
        $this->assertAdmin($admin);
        $reason = $this->reason($reason);
        $this->assertNotOwn($admin, $this->reportParties($eventId));

        return DB::transaction(function () use ($admin, $eventId, $reason): TrustReportDismissal {
            $this->log($admin, 'dismiss', $eventId, $reason);

            return TrustReportDismissal::query()->updateOrCreate(['event_id' => $eventId], ['dismissed_by_id' => $admin->id, 'reason' => $reason]);
        });
    }

    /** @throws TrustAdminRefused */
    public function restore(User $admin, string $eventId, string $reason): void
    {
        $this->assertAdmin($admin);
        $reason = $this->reason($reason);
        $this->assertNotOwn($admin, $this->reportParties($eventId));

        DB::transaction(function () use ($admin, $eventId, $reason): void {
            $this->log($admin, 'restore', $eventId, $reason);
            TrustReportDismissal::query()->where('event_id', $eventId)->delete();
        });
    }

    /** @throws TrustAdminRefused */
    public function exclude(User $admin, string $key, string $reason): TrustExclusion
    {
        $this->assertBoard($admin);
        $reason = $this->reason($reason);
        $pubkey = NostrKeys::toHex($key) ?? throw new TrustAdminRefused(__('Enter an npub or a 64-character hex public key.'));
        $this->assertNotOwn($admin, [$pubkey]);

        return DB::transaction(function () use ($admin, $pubkey, $reason): TrustExclusion {
            $this->log($admin, 'exclude', $pubkey, $reason);

            return TrustExclusion::query()->updateOrCreate(['pubkey' => $pubkey], ['excluded_by_id' => $admin->id, 'reason' => $reason]);
        });
    }

    /** @throws TrustAdminRefused */
    public function lift(User $admin, string $pubkey, string $reason): void
    {
        $this->assertBoard($admin);
        $reason = $this->reason($reason);
        $this->assertNotOwn($admin, [$pubkey]);

        DB::transaction(function () use ($admin, $pubkey, $reason): void {
            $this->log($admin, 'lift', $pubkey, $reason);
            TrustExclusion::query()->where('pubkey', $pubkey)->delete();
        });
    }

    /** Whether this admin may exclude keys (the board). */
    public static function canExclude(?User $admin): bool
    {
        return $admin !== null && Board::contains($admin->pubkey);
    }

    /** @param 'dismiss'|'restore'|'exclude'|'lift' $action */
    private function log(User $admin, string $action, string $target, string $reason): void
    {
        TrustDecision::query()->create(['actor_id' => $admin->id, 'actor_pubkey' => $admin->pubkey, 'action' => $action, 'target' => $target, 'reason' => $reason]);
    }

    /**
     * The author and the target of an archived league report.
     *
     * @return list<string>
     *
     * @throws TrustAdminRefused
     */
    private function reportParties(string $eventId): array
    {
        $report = NostrEvent::query()->where('kind', TrustJob::REPORT)->where('event_id', $eventId)->first()
            ?? throw new TrustAdminRefused(__('This is not a report id.'));
        $parties = [$report->pubkey];

        foreach ((array) ($report->payload()['tags'] ?? []) as $tag) {
            if (is_array($tag) && ($tag[0] ?? null) === 'p' && is_string($tag[1] ?? null)) {
                $parties[] = $tag[1];
            }
        }

        return $parties;
    }

    /**
     * The admin himself, and everybody in a clan he belonged to during the
     * live season (round 4: leaving the clan does not lift the guard): its
     * members now and those who left it this season. Without a live season
     * every clan he ever left counts. Departures are read by pubkey, so
     * neither an ended clan (P7d gate, Low A) nor a deleted account (P7e)
     * takes anybody out of the guard.
     *
     * @param  list<string>  $pubkeys
     *
     * @throws TrustAdminRefused
     */
    private function assertNotOwn(User $admin, array $pubkeys): void
    {
        $since = Seasons::live()?->genesis_at;
        $departures = fn () => ClanDeparture::query()->when($since !== null, fn ($query) => $query->where('left_at', '>=', $since));
        $clanIds = [
            ...ClanMember::query()->where('user_id', $admin->id)->pluck('clan_id')->all(),
            ...$departures()->where(fn ($query) => $query->where('pubkey', $admin->pubkey)->orWhere('user_id', $admin->id))->pluck('clan_id')->all(),
        ];
        $left = $departures()->whereIn('clan_id', $clanIds)->get(['user_id', 'pubkey']);
        $userIds = [...ClanMember::query()->whereIn('clan_id', $clanIds)->pluck('user_id')->all(), ...$left->pluck('user_id')->filter()->all()];
        $own = [
            $admin->pubkey,
            ...User::query()->whereKey(array_unique($userIds))->pluck('pubkey')->all(),
            ...$left->pluck('pubkey')->filter()->all(),
        ];

        if (array_intersect($pubkeys, $own) !== []) {
            throw new TrustAdminRefused(__('You cannot decide about yourself or your own clan. Another admin has to.'));
        }
    }

    /** @throws TrustAdminRefused */
    private function assertAdmin(User $admin): void
    {
        if (! $admin->isAdmin()) {
            throw new TrustAdminRefused(__('Only admins can decide on reports.'));
        }
    }

    /** @throws TrustAdminRefused */
    private function assertBoard(User $admin): void
    {
        if (! self::canExclude($admin)) {
            throw new TrustAdminRefused(__('Only a board member can exclude a key or lift an exclusion.'));
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
