<?php

namespace App\Support\Prizes;

use App\Models\Tournament;
use App\Models\User;
use App\Support\Lightning\WinnerZaps;
use App\Support\Lightning\ZapRefused;
use App\Support\QrCode;
use App\Support\SeasonChain\LeagueKey;
use Illuminate\Support\Facades\RateLimiter;

/**
 * "Zap with Nostr" in a tournament's "Fill the pot" card (user, 2026-10-02:
 * „auf der Turnierseite selbst können durch Zaps auf das Turnier selbst
 * Sponsoren dazukommen"): a NIP-57 zap to the tournament's calendar event,
 * paid into its pot in the league wallet through the league's own LNURL
 * endpoint. Beside it the card's "Pay without Nostr" is a plain invoice
 * ({@see PotTopUps}), part of the pot as announced, without a receipt.
 *
 * - A signed-in player picks an amount, signs the kind 9734 zap request
 *   {@see template()} built (`p` the pool key, `a` the tournament's event,
 *   `amount`, `lnurl`, `relays` = the league relays its receipt goes to) in
 *   the browser, and gets the invoice as a QR code ({@see invoice()}); once
 *   paid, the league signs the receipt and the zap shows on the wall, on top
 *   of the pot ({@see ZapSponsors}).
 *
 * Only while the pot takes zaps ({@see self::open()}). A zap is voluntary
 * support of the prizes, never a fee: nobody has to pay to play.
 */
final class PotZaps
{
    /** The amounts offered, in sats. */
    public const AMOUNTS = [210, 2_100, 21_000, 210_000];

    public const MAX_COMMENT = 140;

    public function __construct(private PoolInvoices $invoices) {}

    /** Whether the tournament's pot takes zaps now: open in the league wallet, with the LNURL key and the pool key set up. */
    public static function open(Tournament $tournament): bool
    {
        return PotTopUps::enabled($tournament) && PoolInvoices::receives() && $tournament->address() !== null;
    }

    /**
     * The kind 9734 zap request the viewer signs.
     *
     * @return array{kind: int, tags: list<list<string>>, content: string, created_at: int}
     *
     * @throws ZapRefused
     */
    public function template(User $zapper, Tournament $tournament, int $sats, string $comment): array
    {
        if (! self::open($tournament)) {
            throw new ZapRefused(__('This tournament takes no zaps into its pot right now.'));
        }

        try {
            PoolInvoices::checkAmount($sats);
        } catch (PoolRefusal $refusal) {
            throw new ZapRefused($refusal->getMessage());
        }

        $comment = trim($comment);

        if (mb_strlen($comment) > self::MAX_COMMENT) {
            throw new ZapRefused(__('The comment can be at most :max characters.', ['max' => self::MAX_COMMENT]));
        }

        $relays = array_values(array_filter((array) config('esports.relays'), fn (mixed $url): bool => is_string($url) && (str_starts_with($url, 'wss://') || str_starts_with($url, 'ws://'))));

        return [
            'kind' => 9734,
            'tags' => [
                ['relays', ...array_slice($relays, 0, 5)],
                ['amount', (string) ($sats * 1000)],
                ['lnurl', PoolInvoices::lnurl($tournament)],
                ['p', (string) LeagueKey::poolPubkey()],
                ['a', (string) $tournament->address()],
                ['k', (string) Tournament::CALENDAR_EVENT],
            ],
            'content' => $comment,
            'created_at' => now()->getTimestamp(),
        ];
    }

    /**
     * The signed zap request, checked against the template, turned into an
     * invoice of the league wallet for the pot. Returns its id, the invoice
     * and its QR code.
     *
     * @return array{id: int, invoice: string, qr: string}
     *
     * @throws ZapRefused
     */
    public function invoice(User $zapper, Tournament $tournament, int $sats, string $comment, mixed $signed): array
    {
        if (RateLimiter::hit('pot-zaps:'.$zapper->id, 3600) > max(1, (int) config('esports.zaps.invoices_per_hour', 20))) {
            throw new ZapRefused(__('You asked for many invoices this hour. Try again later.'));
        }

        $template = $this->template($zapper, $tournament, $sats, $comment);
        $event = WinnerZaps::matching($signed, $template, $zapper) ?? throw new ZapRefused(__('The signed zap request did not match. Please try again.'));

        try {
            $payment = $this->invoices->forZapRequest($event, $event->toJson(), $sats, $tournament);
        } catch (PoolRefusal $refusal) {
            throw new ZapRefused($refusal->getMessage());
        }

        return [
            'id' => $payment->id,
            'invoice' => $payment->bolt11,
            'qr' => QrCode::svg('lightning:'.strtoupper($payment->bolt11), label: __('QR code of the zap invoice')),
        ];
    }
}
