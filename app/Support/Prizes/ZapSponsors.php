<?php

namespace App\Support\Prizes;

use App\Enums\IncomingPaymentStatus;
use App\Models\IncomingPayment;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Nostr\NostrKeys;
use Illuminate\Container\Attributes\Scoped;

/**
 * The zap sponsors of a tournament (user, 2026-10-02: „auf der Turnierseite
 * selbst können durch Zaps auf das Turnier selbst Sponsoren dazukommen. Die
 * werden dann mit ihren Nostr Avataren stolz präsentiert. Diese zahlen oben
 * drauf auf den Topf"): everyone whose zap to the tournament's calendar
 * event has a receipt that {@see ZapReceipts} verified, one entry per
 * zapper with their zapped sum, the biggest first. Their sats add on top of
 * the pot as announced and are split like it ({@see PrizePool}).
 *
 * Each receipt is verified once, when the league signs it
 * (App\Support\Prizes\IncomingPayments, `zap_verified` on its payment row);
 * the wall is one query over those rows, remembered for the request
 * (security gate on 8a171405, F2: never re-verified per page view). One row
 * is one payment and one receipt, so each receipt counts once. A zapper
 * chose to zap in public, so their Nostr key, name and picture show; never
 * anything else of a player.
 */
#[Scoped]
final class ZapSponsors
{
    /** @var array<int, list<array{pubkey: string, npub: string, sats: int, zaps: int, first: int, user: User|null}>> */
    private array $walls = [];

    /**
     * Verified zaps out of raw receipts (JSON), each receipt id once: the
     * same check as at settle, for a recount from relay data.
     *
     * @param  list<string>  $receipts
     * @return list<array{id: string, payer: string, sats: int, at: int, comment: string}>
     */
    public static function tally(array $receipts, Tournament $tournament, string $lnurlPubkey, string $poolPubkey): array
    {
        $seen = [];

        foreach ($receipts as $raw) {
            $zap = ZapReceipts::verify(json_decode($raw, true), $tournament, $lnurlPubkey, $poolPubkey);

            if ($zap !== null && ! isset($seen[$zap['id']])) {
                $seen[$zap['id']] = $zap;
            }
        }

        return array_values($seen);
    }

    /** The sats zapped on top of the pot. */
    public function zapSats(Tournament $tournament): int
    {
        return array_sum(array_column($this->wall($tournament), 'sats'));
    }

    /**
     * The sponsors' wall: one entry per zapper, the biggest sum first (then
     * the earliest zap): their key, npub, sum, number of zaps, and their
     * account when they play here (for the picture the league already has).
     *
     * @return list<array{pubkey: string, npub: string, sats: int, zaps: int, first: int, user: User|null}>
     */
    public function wall(Tournament $tournament): array
    {
        if (! $tournament->hasLeaguePot()) {
            return [];
        }

        return $this->walls[$tournament->id] ??= $this->read($tournament);
    }

    /** Forget the remembered wall (a zap was verified in this request). */
    public function forget(int $tournamentId): void
    {
        unset($this->walls[$tournamentId]);
    }

    /**
     * @return list<array{pubkey: string, npub: string, sats: int, zaps: int, first: int, user: User|null}>
     */
    private function read(Tournament $tournament): array
    {
        $rows = IncomingPayment::query()->where('tournament_id', $tournament->id)->where('zap_verified', true)
            ->where('pot', $tournament->potAccount())->where('source', 'zap')->where('status', IncomingPaymentStatus::Settled)
            ->where('late', false)->whereNotNull('payer_pubkey')
            ->groupBy('payer_pubkey')
            ->selectRaw('payer_pubkey, sum(amount_sats) as sats, count(*) as zaps, min(settled_at) as first_at')
            ->get();

        $users = User::query()->whereIn('pubkey', $rows->pluck('payer_pubkey'))->get()->keyBy('pubkey');
        $wall = [];

        foreach ($rows as $row) {
            $pubkey = (string) $row->getAttribute('payer_pubkey');
            $wall[] = [
                'pubkey' => $pubkey,
                'npub' => NostrKeys::hexToNpub($pubkey),
                'sats' => (int) $row->getAttribute('sats'),
                'zaps' => (int) $row->getAttribute('zaps'),
                'first' => (int) strtotime((string) $row->getAttribute('first_at')),
                'user' => $users->get($pubkey),
            ];
        }

        usort($wall, fn (array $a, array $b): int => [$b['sats'], $a['first']] <=> [$a['sats'], $b['first']]);

        return $wall;
    }
}
