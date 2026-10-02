<?php

namespace App\Http\Controllers;

use App\Models\Tournament;
use App\Support\Nostr\SignedEvent;
use App\Support\Prizes\PoolInvoices;
use App\Support\Prizes\PoolRefusal;
use App\Support\Prizes\PotTopUps;
use App\Support\SeasonChain\LeagueKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The league's own LNURL-pay endpoint (P9, NIP "Prize pool funding"), the
 * Lightning address `pool@<host>` of the pool key's profile: LUD-06 and
 * LUD-16 with NIP-57 zaps (`allowsNostr`, `nostrPubkey` = the LNURL server
 * key), for the league reserve and every tournament pot (all booked in the
 * league wallet since 2026-10-02). Invoices come from the league's
 * receiving wallet connection; a zap request decides the pot
 * (App\Support\Prizes\ZapRequests), a payment without one goes to the
 * reserve. `?pot=<tournament id>` is the same endpoint for one tournament's
 * pot (the QR code on its page): a plain payment goes to that pot, a zap
 * request must name that tournament.
 *
 * The zap request is read from the raw query string: the framework's input
 * cleaning (TrimStrings, ConvertEmptyStringsToNull) would change a signed
 * event. Errors follow LUD-06 (`{"status":"ERROR","reason":…}`); fail
 * closed while the wallet, the LNURL server key or the pool key is missing.
 */
class LnurlPayController extends Controller
{
    public function metadata(string $username): JsonResponse
    {
        if ($username !== config('esports.wallet.lnurl_username')) {
            return self::error(__('Unknown Lightning address.'), 404);
        }

        $key = LeagueKey::lnurl();

        if ($key === null || ! PoolInvoices::receives()) {
            return self::error(__('The league wallet cannot take payments yet.'));
        }

        [$tournament, $refused] = self::tournament();

        if ($refused) {
            return self::error(__('This tournament takes no zaps into its pot right now.'));
        }

        return self::json([
            'tag' => 'payRequest',
            'callback' => route('lnurl.callback', array_filter(['username' => $username, 'pot' => $tournament?->id])),
            'minSendable' => (int) config('esports.wallet.min_sats') * 1000,
            'maxSendable' => (int) config('esports.wallet.max_sats') * 1000,
            'metadata' => PoolInvoices::metadata($tournament),
            'commentAllowed' => 140,
            'allowsNostr' => true,
            'nostrPubkey' => $key->pubkey(),
        ]);
    }

    public function callback(Request $request, string $username, PoolInvoices $invoices): JsonResponse
    {
        if ($username !== config('esports.wallet.lnurl_username')) {
            return self::error(__('Unknown Lightning address.'), 404);
        }

        $raw = $request->server('QUERY_STRING');
        parse_str(is_string($raw) ? $raw : '', $query);
        $amount = $query['amount'] ?? null;

        if (! is_string($amount) || ! ctype_digit($amount) || strlen($amount) > 15 || (int) $amount % 1000 !== 0) {
            return self::error(__('The amount must be whole sats, in millisatoshis.'));
        }

        $sats = intdiv((int) $amount, 1000);
        $nostr = $query['nostr'] ?? null;
        [$tournament, $refused] = self::tournament($query);

        if ($refused) {
            return self::error(__('This tournament takes no zaps into its pot right now.'));
        }

        try {
            if (is_string($nostr) && $nostr !== '') {
                $zapRequest = strlen($nostr) <= 8192 ? SignedEvent::fromInput(json_decode($nostr, true)) : null;

                if ($zapRequest === null) {
                    return self::error(__('Not a valid zap request.'));
                }

                $payment = $invoices->forZapRequest($zapRequest, $nostr, $sats, $tournament);
            } else {
                $payment = $invoices->forPlainPayment($sats, is_string($query['comment'] ?? null) ? $query['comment'] : '', $tournament);
            }
        } catch (PoolRefusal $refusal) {
            return self::error($refusal->getMessage());
        }

        return self::json(['pr' => $payment->bolt11, 'routes' => []]);
    }

    /**
     * The tournament named by `?pot=<id>`: [null, false] without one, [the
     * tournament, false] when its pot takes payments now, [null, true] when
     * it is named but takes none (fail closed: never the reserve instead).
     *
     * @param  array<mixed>|null  $query  the parsed raw query string; null = this request's
     * @return array{0: Tournament|null, 1: bool}
     */
    private static function tournament(?array $query = null): array
    {
        if ($query === null) {
            $raw = request()->server('QUERY_STRING');
            parse_str(is_string($raw) ? $raw : '', $query);
        }

        $pot = $query['pot'] ?? null;

        if ($pot === null) {
            return [null, false];
        }

        $tournament = is_string($pot) && ctype_digit($pot) && strlen($pot) <= 12 ? Tournament::query()->find((int) $pot) : null;

        return $tournament !== null && PotTopUps::enabled($tournament) ? [$tournament, false] : [null, true];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private static function json(array $body, int $status = 200): JsonResponse
    {
        return response()->json($body, $status, ['Access-Control-Allow-Origin' => '*'], JSON_UNESCAPED_SLASHES);
    }

    private static function error(string $reason, int $status = 200): JsonResponse
    {
        return self::json(['status' => 'ERROR', 'reason' => $reason], $status);
    }
}
