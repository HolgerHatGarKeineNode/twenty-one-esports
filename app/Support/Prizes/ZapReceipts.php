<?php

namespace App\Support\Prizes;

use App\Models\Tournament;
use App\Support\Lightning\Bolt11;
use App\Support\Nostr\SignedEvent;

/**
 * Whether a zap receipt (NIP-57 `9735`) proves a zap into a tournament's pot
 * (NIP-57 appendix F, NIP rev. 9.12). Pure: it reads nothing but the
 * receipt, the tournament and the two keys. A receipt counts only when
 *
 * - it is a kind 9735 with a valid signature by the league's LNURL server
 *   key (`nostrPubkey` of the endpoint that made the invoice);
 * - its `description` is a valid, signed kind 9734 whose `p` is the pool key
 *   and whose `a` is the tournament's calendar event, and the receipt
 *   carries the same `p` and `a` (it is tagged to the tournament's event);
 * - its `bolt11` decodes, its amount equals the request's `amount`, and its
 *   description hash is SHA-256 of that `description`;
 * - it was made before the pot closed (the payout check): later zaps count
 *   for the league reserve, never for the prizes.
 *
 * Anything else is ignored, never repaired. Duplicates are dropped by the
 * caller, by receipt id ({@see ZapSponsors}).
 */
final class ZapReceipts
{
    /**
     * The zap the receipt proves, or null.
     *
     * @return array{id: string, payer: string, sats: int, at: int, comment: string}|null
     */
    public static function verify(mixed $receipt, Tournament $tournament, string $lnurlPubkey, string $poolPubkey): ?array
    {
        $event = $receipt instanceof SignedEvent ? $receipt : SignedEvent::fromInput($receipt);
        $address = $tournament->address();

        if ($event === null || $address === null || $event->kind !== 9735 || $event->pubkey !== $lnurlPubkey || ! $event->hasValidSignature()) {
            return null;
        }

        if ($tournament->pool_closed_at !== null && $event->createdAt >= $tournament->pool_closed_at->getTimestamp()) {
            return null;
        }

        $description = $event->tag('description');
        $request = $description === null ? null : SignedEvent::fromInput(json_decode($description, true));

        if ($request === null || $request->kind !== 9734 || ! $request->hasValidSignature()) {
            return null;
        }

        if (self::only($request, 'p') !== $poolPubkey || self::only($event, 'p') !== $poolPubkey
            || self::only($request, 'a') !== $address || self::only($event, 'a') !== $address) {
            return null;
        }

        $invoice = Bolt11::decode((string) $event->tag('bolt11'));
        $amount = $request->tag('amount');

        if ($invoice === null || $invoice->amountMsats === null || $invoice->amountMsats <= 0 || $amount === null
            || $amount !== (string) $invoice->amountMsats || $invoice->descriptionHash !== hash('sha256', (string) $description)) {
            return null;
        }

        return ['id' => $event->id, 'payer' => $request->pubkey, 'sats' => intdiv($invoice->amountMsats, 1000), 'at' => $event->createdAt, 'comment' => $request->content];
    }

    /** The value of the one tag named `$name`, or null when there is none or more than one. */
    private static function only(SignedEvent $event, string $name): ?string
    {
        $tags = $event->tagsNamed($name);

        return count($tags) === 1 && is_string($tags[0][0] ?? null) ? $tags[0][0] : null;
    }
}
