<?php

namespace App\Support\Prizes;

use App\Enums\IncomingPaymentStatus;
use App\Models\IncomingPayment;
use App\Models\Tournament;
use App\Support\Lightning\Bolt11;
use App\Support\Nostr\SignedEvent;
use App\Support\PreSeason;
use App\Support\SeasonChain\LeagueKey;
use App\Support\Wallet\NwcError;
use App\Support\Wallet\ReceivingWallet;
use App\Support\Wallet\WalletSetup;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;

use function BitWasp\Bech32\convertBits;
use function BitWasp\Bech32\encode;

/**
 * Invoices of the league's LNURL endpoint `pool@<host>`, all made by the
 * league's receiving wallet connection and each recorded with its payment
 * hash and pot before anyone sees it: a zap request from any Nostr client
 * (NIP-57) into the reserve or, naming a tournament's calendar event by
 * `a`, into that tournament's pot (user, 2026-10-02: „nutze doch einfach
 * Zaps auf Nostr Events"; {@see ZapRequests}); or a plain LNURL payment,
 * into the reserve, or into a tournament's pot through that tournament's
 * LNURL (`?pot=<id>`, the QR code on its page).
 *
 * A zap's invoice has as description hash SHA-256 of the zap request exactly
 * as received, so the receipt can carry it as `description`; a plain
 * payment's is SHA-256 of the metadata served (LUD-06). Fail closed: without
 * the receiving wallet or the LNURL server key nothing is made.
 */
final class PoolInvoices
{
    public function __construct(private ZapRequests $zapRequests) {}

    /**
     * An invoice for a signed zap request. `$json` is the request exactly as
     * received: its SHA-256 is the invoice's description hash.
     *
     * @throws PoolRefusal
     */
    public function forZapRequest(SignedEvent $request, string $json, int $amountSats, ?Tournament $only = null): IncomingPayment
    {
        $this->checkAmount($amountSats);
        $pot = $this->zapRequests->potOf($request, $amountSats * 1000, $only);

        return $this->make($amountSats, hash('sha256', $json), [
            'pot' => $pot,
            'tournament_id' => $pot === IncomingPayment::RESERVE ? null : (int) substr($pot, strlen('tournament:')),
            'source' => 'zap',
            'zap_request' => $json,
            'payer_pubkey' => $request->pubkey,
            'comment' => mb_substr($request->content, 0, 280) ?: null,
        ]);
    }

    /**
     * A payment through the LNURL endpoint without a zap request: the
     * reserve, or through a tournament's LNURL that tournament's pot (part
     * of the pot as announced; without a receipt it is no zap on top).
     *
     * @throws PoolRefusal
     */
    public function forPlainPayment(int $amountSats, string $comment, ?Tournament $tournament = null): IncomingPayment
    {
        $this->checkAmount($amountSats);

        if ($tournament !== null && ! PotTopUps::enabled($tournament)) {
            throw new PoolRefusal(__('This tournament takes no zaps into its pot right now.'));
        }

        return $this->make($amountSats, hash('sha256', self::metadata($tournament)), [
            'pot' => $tournament === null ? IncomingPayment::RESERVE : $tournament->potAccount(),
            'tournament_id' => $tournament?->id,
            'source' => 'lnurl',
            'comment' => self::comment($comment) ?: null,
        ]);
    }

    /**
     * The LUD-06 metadata of the league's pool address, or of a tournament's
     * LNURL on it (named by its stable slug).
     */
    public static function metadata(?Tournament $tournament = null): string
    {
        return (string) json_encode([
            ['text/plain', $tournament === null ? 'TWENTY ONE Esports league reserve' : 'TWENTY ONE Esports prize pot of '.$tournament->slug],
            ['text/identifier', self::address()],
        ], JSON_UNESCAPED_SLASHES);
    }

    /**
     * Whether the league's pay endpoint takes payments: the LNURL server key,
     * the receiving wallet and the pool key are all set up. Without them the
     * endpoint answers with an error (LnurlPayController, fail closed).
     */
    public static function receives(): bool
    {
        return LeagueKey::lnurl() !== null && WalletSetup::canReceive() && LeagueKey::poolPubkey() !== null;
    }

    /** `pool@<host>`: the Lightning address of the pool key's profile. */
    public static function address(): string
    {
        return config('esports.wallet.lnurl_username', 'pool').'@'.parse_url((string) config('app.url'), PHP_URL_HOST);
    }

    /**
     * The LNURL of the league's pay endpoint, bech32 `lnurl` (LUD-01), for
     * the zap request's `lnurl` tag; with a tournament, the same endpoint
     * for that tournament's pot (`?pot=<id>`, the QR code on its page).
     */
    public static function lnurl(?Tournament $tournament = null): string
    {
        $url = route('lnurl.pay', array_filter(['username' => config('esports.wallet.lnurl_username', 'pool'), 'pot' => $tournament?->id]));
        $bytes = array_values(unpack('C*', $url) ?: []);

        return strtoupper(encode('lnurl', convertBits($bytes, count($bytes), 8, 5, true)));
    }

    /**
     * @throws PoolRefusal
     */
    public static function checkAmount(int $amountSats): void
    {
        $min = (int) config('esports.wallet.min_sats', 1);
        $max = (int) config('esports.wallet.max_sats', 10_000_000);

        if ($amountSats < $min || $amountSats > $max) {
            throw new PoolRefusal(__('Pick an amount between :min and :max sats.', ['min' => PreSeason::formatSats($min), 'max' => PreSeason::formatSats($max)]));
        }
    }

    public static function comment(string $comment): string
    {
        return mb_substr(trim(preg_replace('/\s+/u', ' ', $comment) ?? ''), 0, 140);
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws PoolRefusal
     */
    private function make(int $amountSats, string $descriptionHash, array $attributes): IncomingPayment
    {
        $wallet = ReceivingWallet::fromConfig();

        if ($wallet === null || LeagueKey::lnurl() === null) {
            throw new PoolRefusal(__('The league wallet cannot take payments yet.'));
        }

        $requester = InvoiceCaps::requester();
        InvoiceCaps::check($requester);
        $expiry = (int) config('esports.wallet.invoice_expiry_seconds', 900);

        try {
            $invoice = $wallet->makeInvoice($amountSats, $descriptionHash, $expiry);
        } catch (NwcError $error) {
            Log::warning('Reserve invoice: the wallet made no invoice', ['code' => $error->errorCode]);

            throw new PoolRefusal(__('The league wallet did not answer. Please try again in a moment.'));
        }

        return IncomingPayment::query()->create([
            ...$attributes,
            ...$requester,
            'payment_hash' => $invoice->paymentHash,
            'bolt11' => $invoice->invoice,
            'amount_sats' => $amountSats,
            'status' => IncomingPaymentStatus::Pending,
            'expires_at' => self::expiry($invoice),
        ]);
    }

    /**
     * When the league stops counting an invoice as open: the invoice's own
     * expiry, but never later than the league asked for (security gate
     * F-B), so a wallet that writes a long expiry cannot hold the caps.
     */
    public static function expiry(Bolt11 $invoice): CarbonInterface
    {
        return now()->setTimestamp(min($invoice->expiresAt(), now()->getTimestamp() + (int) config('esports.wallet.invoice_expiry_seconds', 900)));
    }
}
