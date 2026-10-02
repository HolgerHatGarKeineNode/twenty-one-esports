<?php

namespace App\Support\Prizes;

use App\Enums\IncomingPaymentStatus;
use App\Models\IncomingPayment;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Nostr\NostrKeys;
use App\Support\SeasonChain\LeagueKey;
use Illuminate\Support\Facades\Cache;

/**
 * The zap sponsors of a tournament (user, 2026-10-02: „auf der Turnierseite
 * selbst können durch Zaps auf das Turnier selbst Sponsoren dazukommen. Die
 * werden dann mit ihren Nostr Avataren stolz präsentiert. Diese zahlen oben
 * drauf auf den Topf"): everyone whose zap to the tournament's calendar
 * event has a receipt that {@see ZapReceipts} verifies, one entry per
 * zapper with their zapped sum, the biggest first. Their sats add on top of
 * the pot as announced and are split like it ({@see PrizePool}).
 *
 * The receipts are the league's own, published when the league wallet saw
 * the zap's invoice paid (App\Support\Prizes\IncomingPayments); each is
 * still verified here, and counted once by its id. A zapper chose to zap in
 * public, so their Nostr key, name and picture show; never anything else
 * of a player.
 */
final class ZapSponsors
{
    /** How long a receipt's verification is remembered (it is a pure function of signed data). */
    private const VERIFIED_TTL = 86_400;

    /**
     * The verified zaps into the pot, each receipt once.
     *
     * @return list<array{id: string, payer: string, sats: int, at: int, comment: string}>
     */
    public function zaps(Tournament $tournament): array
    {
        $lnurl = LeagueKey::lnurl()?->pubkey();
        $pool = LeagueKey::poolPubkey();
        $address = $tournament->address();

        if ($lnurl === null || $pool === null || $address === null || ! $tournament->hasLeaguePot()) {
            return [];
        }

        $payments = IncomingPayment::query()->where('tournament_id', $tournament->id)->where('pot', $tournament->potAccount())
            ->where('source', 'zap')->where('status', IncomingPaymentStatus::Settled)->where('late', false)
            ->whereNotNull('receipt_event_id')->with('receipt')->orderBy('id')->get();

        $receipts = [];

        foreach ($payments as $payment) {
            if ($payment->receipt !== null) {
                $receipts[] = $payment->receipt->raw;
            }
        }

        return self::tally($receipts, $tournament, $lnurl, $pool);
    }

    /**
     * Verified zaps out of raw receipts (JSON), each receipt id once.
     *
     * @param  list<string>  $receipts
     * @return list<array{id: string, payer: string, sats: int, at: int, comment: string}>
     */
    public static function tally(array $receipts, Tournament $tournament, string $lnurlPubkey, string $poolPubkey): array
    {
        $seen = [];
        $context = hash('sha256', implode('|', [$tournament->address(), $tournament->pool_closed_at?->getTimestamp(), $lnurlPubkey, $poolPubkey]));

        foreach ($receipts as $raw) {
            $input = json_decode($raw, true);
            $id = is_array($input) && is_string($input['id'] ?? null) ? $input['id'] : null;

            if ($id === null || isset($seen[$id])) {
                continue;
            }

            // Keyed by the whole signed text, not the id alone: the id does not cover the signature.
            $zap = Cache::remember('zap-receipt:'.hash('sha256', $raw).':'.$context, self::VERIFIED_TTL,
                fn () => ZapReceipts::verify($input, $tournament, $lnurlPubkey, $poolPubkey) ?? false);

            if (is_array($zap) && $zap['id'] === $id) {
                $seen[$id] = $zap;
            }
        }

        return array_values($seen);
    }

    /** The sats zapped on top of the pot. */
    public function zapSats(Tournament $tournament): int
    {
        return array_sum(array_column($this->zaps($tournament), 'sats'));
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
        $byPayer = [];

        foreach ($this->zaps($tournament) as $zap) {
            $entry = $byPayer[$zap['payer']] ?? ['pubkey' => $zap['payer'], 'npub' => NostrKeys::hexToNpub($zap['payer']), 'sats' => 0, 'zaps' => 0, 'first' => $zap['at'], 'user' => null];
            $entry['sats'] += $zap['sats'];
            $entry['zaps']++;
            $entry['first'] = min($entry['first'], $zap['at']);
            $byPayer[$zap['payer']] = $entry;
        }

        $users = User::query()->whereIn('pubkey', array_keys($byPayer))->get()->keyBy('pubkey');

        foreach ($byPayer as $pubkey => $entry) {
            $byPayer[$pubkey]['user'] = $users->get($pubkey);
        }

        $wall = array_values($byPayer);
        usort($wall, fn (array $a, array $b): int => [$b['sats'], $a['first']] <=> [$a['sats'], $b['first']]);

        return $wall;
    }
}
