<?php

namespace App\Support\FairPlay;

use App\Models\ImpersonationHold;
use App\Models\ReceivedZap;
use App\Models\TrustRank;
use App\Models\User;
use App\Models\ZapRead;
use App\Support\Lightning\Bolt11;
use App\Support\Nostr\RelayReader;
use App\Support\Nostr\SignedEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Zaps a player received, read from the relays for the admin's soft mark
 * "zaps from known accounts" (plan "Profile standard and impersonation", P3).
 * The league stores no zaps players receive elsewhere, so the receipts
 * (NIP-57, kind 9735, `#p` = the player) are read here: on demand from the
 * account's check view, and by a scheduled job for the accounts the admins
 * look at (open holds, the players /admin/checks lists), each once a day.
 *
 * A receipt counts when its `description` is a validly signed kind-9734
 * request naming the player as `p` and its invoice carries a positive
 * amount; the zapper is the request's signer, never the player. The
 * receipt's own signer (the player's LNURL server) is not checked against
 * the player's Lightning address: a known account's own signed request to
 * zap the player is what the mark reads, a hint for an admin, never a
 * decision. A read no relay answered stores nothing and keeps the last read
 * time, so "none" and "never read" stay apart.
 */
final class ReceivedZaps
{
    /** Receipts read per player and read at most. */
    public const LIMIT = 500;

    /** Players the scheduled job reads per run at most. */
    public const PER_RUN = 100;

    /** A player's zaps are read again by the job after this many hours. */
    public const REFRESH_HOURS = 24;

    public function __construct(private RelayReader $relays) {}

    /**
     * Read and store this player's received zaps. Returns how many relays
     * answered and how many receipts were new.
     *
     * @return array{answered: int, stored: int}
     */
    public function read(string $pubkey): array
    {
        $known = array_fill_keys(array_map(strval(...), ReceivedZap::query()->where('recipient_pubkey', $pubkey)->pluck('receipt_id')->all()), true);
        $read = $this->relays->fetchCounted([['kinds' => [9735], '#p' => [$pubkey], 'limit' => self::LIMIT]], ListedProfiles::relays(), $known, self::LIMIT);

        if ($read['answered'] === 0) {
            Log::warning('Received zaps: no relay answered', ['pubkey' => $pubkey]);

            return ['answered' => 0, 'stored' => 0];
        }

        $stored = 0;

        foreach ($read['events'] as $receipt) {
            $zap = self::parse($receipt, $pubkey);

            if ($zap !== null && ReceivedZap::query()->insertOrIgnore([...$zap, 'created_at' => now(), 'updated_at' => now()]) > 0) {
                $stored++;
            }
        }

        ZapRead::query()->updateOrCreate(['pubkey' => $pubkey], ['read_at' => now(), 'relays_answered' => $read['answered']]);

        return ['answered' => $read['answered'], 'stored' => $stored];
    }

    /**
     * The scheduled read: the accounts admins look at whose zaps were not
     * read within a day.
     *
     * @param  list<string>  $listed  the pubkeys /admin/checks lists now
     * @return array{players: int, failed: int}
     */
    public function refresh(array $listed): array
    {
        $pubkeys = array_values(array_unique([
            ...array_map(strval(...), ImpersonationHold::query()->open()->pluck('pubkey')->all()),
            ...$listed,
        ]));
        $fresh = array_fill_keys(array_map(strval(...), ZapRead::query()->whereIn('pubkey', $pubkeys)->where('read_at', '>=', now()->subHours(self::REFRESH_HOURS))->pluck('pubkey')->all()), true);
        $due = array_slice(array_values(array_filter($pubkeys, fn (string $pubkey): bool => ! isset($fresh[$pubkey]))), 0, self::PER_RUN);
        $failed = 0;

        foreach ($due as $pubkey) {
            if ($this->read($pubkey)['answered'] === 0) {
                $failed++;
            }
        }

        return ['players' => count($due), 'failed' => $failed];
    }

    /**
     * The distinct known accounts that zapped each of these players: Verein
     * members, or accounts ranked `$rank` or more now (0: members only).
     *
     * @param  list<string>  $pubkeys
     * @return array<string, int>
     */
    public static function knownZappers(array $pubkeys, int $rank): array
    {
        if ($pubkeys === []) {
            return [];
        }

        $rows = ReceivedZap::query()->whereIn('recipient_pubkey', $pubkeys)
            ->where(fn ($query) => $query->whereIn('zapper_pubkey', User::query()->where('is_member', true)->select('pubkey'))
                ->when($rank > 0, fn ($query) => $query->orWhereIn('zapper_pubkey', TrustRank::query()->where('rank', '>=', $rank)->select('pubkey'))))
            ->distinct()->get(['recipient_pubkey', 'zapper_pubkey']);

        return $rows->countBy('recipient_pubkey')->map(fn (int $count): int => $count)->all();
    }

    /**
     * When each of these players' zaps were read last, as a timestamp.
     *
     * @param  list<string>  $pubkeys
     * @return array<string, int>
     */
    public static function readAt(array $pubkeys): array
    {
        return ZapRead::query()->whereIn('pubkey', $pubkeys)->get(['pubkey', 'read_at'])
            ->mapWithKeys(fn (ZapRead $read): array => [$read->pubkey => $read->read_at->getTimestamp()])->all();
    }

    /**
     * A receipt of a zap to `$recipient`, or null.
     *
     * @return array{receipt_id: string, recipient_pubkey: string, zapper_pubkey: string, sats: int, zapped_at: CarbonImmutable}|null
     */
    public static function parse(SignedEvent $receipt, string $recipient): ?array
    {
        if ($receipt->kind !== 9735 || ! self::names($receipt, $recipient)) {
            return null;
        }

        $description = $receipt->tag('description');
        $request = $description === null ? null : SignedEvent::fromInput(json_decode($description, true));

        if ($request === null || $request->kind !== 9734 || ! self::names($request, $recipient) || $request->pubkey === $recipient || ! $request->hasValidSignature()) {
            return null;
        }

        $invoice = Bolt11::decode((string) $receipt->tag('bolt11'));

        if ($invoice === null || $invoice->amountMsats === null || $invoice->amountMsats < 1000) {
            return null;
        }

        return [
            'receipt_id' => $receipt->id,
            'recipient_pubkey' => $recipient,
            'zapper_pubkey' => $request->pubkey,
            'sats' => intdiv($invoice->amountMsats, 1000),
            'zapped_at' => CarbonImmutable::createFromTimestamp(min($receipt->createdAt, now()->getTimestamp())),
        ];
    }

    /** Whether the event's `p` tags name the recipient. */
    private static function names(SignedEvent $event, string $recipient): bool
    {
        foreach ($event->tagsNamed('p') as $tag) {
            if (($tag[0] ?? null) === $recipient) {
                return true;
            }
        }

        return false;
    }
}
