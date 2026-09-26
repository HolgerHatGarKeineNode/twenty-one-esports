<?php

namespace App\Support;

use App\Models\User;
use App\Support\Nostr\NostrKeys;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Asks the Verein whether a pubkey paid its membership fee.
 *
 * Membership is a badge and perk flag; it never decides whether someone may
 * play. The public `GET /api/members/{year}` list is fetched once per year and
 * cached, so checking many users costs one request per year and cache window.
 *
 * When the Verein cannot be reached, the last known state is kept: a failed
 * fetch never turns a member into a non-member, and `member_checked_at` stays
 * where it was so the next attempt retries.
 */
final class Membership
{
    public function __construct(private Http $http, private Cache $cache) {}

    /**
     * Re-check the user and store the answer. Returns false when the Verein
     * could not answer and the previous state was kept.
     */
    public function refresh(User $user): bool
    {
        $isMember = $this->isMember($user->pubkey);

        if ($isMember === null) {
            return false;
        }

        $user->forceFill([
            'is_member' => $isMember,
            'member_checked_at' => now(),
        ])->save();

        return true;
    }

    /**
     * Refresh when the stored answer is older than the configured window.
     * While the Verein is down, a user is retried at most every few minutes
     * instead of on every request.
     */
    public function refreshIfStale(User $user): void
    {
        if (! $this->isStale($user)) {
            return;
        }

        $retryGuard = 'membership:attempt:'.$user->pubkey;
        $retryAfter = (int) config('esports.membership.retry_after_minutes') * 60;

        if ($this->cache->add($retryGuard, true, $retryAfter)) {
            $this->refresh($user);
        }
    }

    public function isStale(User $user): bool
    {
        $hours = (int) config('esports.membership.stale_after_hours');

        return $user->member_checked_at === null
            || $user->member_checked_at->lt(now()->subHours($hours));
    }

    /**
     * True if the pubkey paid in the current year or within the grace years,
     * false if every list was read and it is in none, null if unknown.
     */
    public function isMember(string $pubkey): ?bool
    {
        $currentYear = now()->year;
        $graceYears = max(0, (int) config('esports.membership.grace_years'));
        $unknown = false;

        for ($year = $currentYear; $year >= $currentYear - $graceYears; $year--) {
            $paid = $this->paidPubkeys($year);

            if ($paid === null) {
                $unknown = true;

                continue;
            }

            if (isset($paid[$pubkey])) {
                return true;
            }
        }

        return $unknown ? null : false;
    }

    /**
     * The hex pubkeys that paid in the given year, as a set, or null when the
     * list could not be read. Also the anchors of the trust job
     * (App\Support\SeasonChain\TrustJob).
     *
     * @return array<string, true>|null
     */
    public function paidPubkeys(int $year): ?array
    {
        $cacheKey = 'membership:paid:'.$year;
        $cached = $this->cache->get($cacheKey);

        if (is_array($cached)) {
            /** @var array<string, true> $cached */
            return $cached;
        }

        try {
            $response = $this->http
                ->acceptJson()
                ->connectTimeout(2)
                ->timeout((int) config('esports.membership.timeout_seconds'))
                ->get(rtrim((string) config('esports.membership.api_url'), '/').'/'.$year);
        } catch (Throwable $exception) {
            Log::warning('Verein membership API unreachable', ['year' => $year, 'error' => $exception->getMessage()]);

            return null;
        }

        $members = $response->successful() ? $response->json() : null;

        if (! is_array($members) || ! array_is_list($members)) {
            Log::warning('Verein membership API gave no member list', ['year' => $year, 'status' => $response->status()]);

            return null;
        }

        $paid = [];

        foreach ($members as $member) {
            $pubkey = is_array($member) && is_string($member['pubkey'] ?? null)
                ? strtolower($member['pubkey'])
                : null;

            if (! NostrKeys::isHexPubkey($pubkey) && is_array($member) && is_string($member['npub'] ?? null)) {
                $pubkey = NostrKeys::npubToHex($member['npub']);
            }

            if (is_string($pubkey) && NostrKeys::isHexPubkey($pubkey)) {
                $paid[$pubkey] = true;
            }
        }

        $this->cache->put($cacheKey, $paid, now()->addMinutes((int) config('esports.membership.list_cache_minutes')));

        return $paid;
    }
}
