<?php

namespace App\Support\Prizes;

use App\Enums\IncomingPaymentStatus;
use App\Models\IncomingPayment;
use App\Models\Tournament;
use App\Models\TournamentSponsor;
use App\Models\User;
use App\Support\Nostr\SignedEvent;
use App\Support\PreSeason;
use App\Support\SeasonChain\LeagueKey;
use App\Support\Wallet\NwcError;
use App\Support\Wallet\ReceivingWallet;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

use function BitWasp\Bech32\convertBits;
use function BitWasp\Bech32\encode;

/**
 * Invoices into the pots (P9, NIP "Prize pool funding"), all made by the
 * league's receiving wallet connection and each recorded with its payment
 * hash and its pot before anyone sees it:
 *
 * - a zap request from any Nostr client through the LNURL endpoint, or from
 *   the tournament page signed by the player's own signer;
 * - an anonymous zap from the page: the league signs the request with a
 *   throwaway key;
 * - a sponsor invoice: the sponsor desk key signs the request, its content
 *   names the sponsor;
 * - a plain LNURL payment without a zap request: the reserve.
 *
 * The invoice's description hash is SHA-256 of the zap request exactly as
 * stored (NIP-57), so the receipt can carry it as `description`. Fail closed:
 * without the receiving wallet, the LNURL server key or the pool key nothing
 * is made.
 */
final class PoolInvoices
{
    public function __construct(private ZapRequests $zapRequests) {}

    /**
     * An unsigned zap request for the player's own signer (the tournament page).
     *
     * @return array{kind: int, created_at: int, tags: list<list<string>>, content: string}
     *
     * @throws PoolRefusal
     */
    public function zapTemplate(Tournament $tournament, int $amountSats, string $comment): array
    {
        $this->checkAmount($amountSats);

        return ['kind' => 9734, 'created_at' => now()->getTimestamp(), 'tags' => $this->zapTags($tournament, $amountSats), 'content' => self::comment($comment)];
    }

    /**
     * @throws PoolRefusal
     */
    public function anonymousZap(Tournament $tournament, int $amountSats, string $comment): IncomingPayment
    {
        $this->checkAmount($amountSats);
        $request = LeagueKey::throwaway()->sign(9734, $this->zapTags($tournament, $amountSats), self::comment($comment), now()->getTimestamp());

        return $this->forZapRequest($request, $request->toJson(), $amountSats, 'anonymous');
    }

    /**
     * @throws PoolRefusal
     */
    public function sponsorInvoice(TournamentSponsor $sponsor, User $user): IncomingPayment
    {
        if (! Gate::forUser($user)->allows('manage-tournament', $sponsor->tournament)) {
            throw new PoolRefusal(__('Only the organizer of this tournament or an admin can change its prize pool.'));
        }

        $desk = LeagueKey::sponsorDesk() ?? throw new PoolRefusal(__('The sponsor desk key is not set up (ESPORTS_SPONSOR_NSEC).'));
        $request = $desk->sign(9734, $this->zapTags($sponsor->tournament, $sponsor->pledged_sats), 'Sponsor: '.$sponsor->name, now()->getTimestamp());

        return $this->forZapRequest($request, $request->toJson(), $sponsor->pledged_sats, 'sponsor', $sponsor);
    }

    /**
     * An invoice for a signed zap request. `$json` is the request exactly as
     * received: its SHA-256 is the invoice's description hash.
     *
     * @throws PoolRefusal
     */
    public function forZapRequest(SignedEvent $request, string $json, int $amountSats, string $source = 'zap', ?TournamentSponsor $sponsor = null): IncomingPayment
    {
        $this->checkAmount($amountSats);
        ['pot' => $pot, 'tournament' => $tournament] = $this->zapRequests->potOf($request, $amountSats * 1000);

        return $this->make($amountSats, hash('sha256', $json), [
            'pot' => $pot,
            'tournament_id' => $tournament?->id,
            'sponsor_id' => $sponsor?->id,
            'source' => $source,
            'zap_request' => $json,
            'payer_pubkey' => $request->pubkey,
            'comment' => mb_substr($request->content, 0, 280) ?: null,
        ]);
    }

    /**
     * A payment through the LNURL endpoint without a zap request: the reserve.
     *
     * @throws PoolRefusal
     */
    public function forPlainPayment(int $amountSats, string $comment): IncomingPayment
    {
        $this->checkAmount($amountSats);

        return $this->make($amountSats, hash('sha256', self::metadata()), [
            'pot' => IncomingPayment::RESERVE,
            'source' => 'lnurl',
            'comment' => self::comment($comment) ?: null,
        ]);
    }

    /**
     * The LUD-06 metadata of the league's pool address.
     */
    public static function metadata(): string
    {
        return (string) json_encode([
            ['text/plain', 'TWENTY ONE Esports prize pools'],
            ['text/identifier', self::address()],
        ], JSON_UNESCAPED_SLASHES);
    }

    /** `pool@<host>`: the Lightning address of the pool key's profile. */
    public static function address(): string
    {
        return config('esports.wallet.lnurl_username', 'pool').'@'.parse_url((string) config('app.url'), PHP_URL_HOST);
    }

    /** The LNURL of the league's pay endpoint, bech32 `lnurl` (LUD-01), for the zap request's `lnurl` tag. */
    public static function lnurl(): string
    {
        $url = route('lnurl.pay', ['username' => config('esports.wallet.lnurl_username', 'pool')]);
        $bytes = array_values(unpack('C*', $url) ?: []);

        return strtoupper(encode('lnurl', convertBits($bytes, count($bytes), 8, 5, true)));
    }

    /**
     * @throws PoolRefusal
     */
    private function checkAmount(int $amountSats): void
    {
        $min = (int) config('esports.wallet.min_sats', 1);
        $max = (int) config('esports.wallet.max_sats', 10_000_000);

        if ($amountSats < $min || $amountSats > $max) {
            throw new PoolRefusal(__('Pick an amount between :min and :max sats.', ['min' => PreSeason::formatSats($min), 'max' => PreSeason::formatSats($max)]));
        }
    }

    /**
     * @return list<list<string>>
     *
     * @throws PoolRefusal
     */
    private function zapTags(Tournament $tournament, int $amountSats): array
    {
        $pool = PrizePool::poolPubkey();

        if ($pool === null || ! $tournament->isPoolOpen() || $tournament->hasOwnWallet() || $tournament->address() === null) {
            throw new PoolRefusal(__('This tournament takes no zaps: it has no open prize pool.'));
        }

        return [
            ['relays', ...array_values(array_map(strval(...), (array) config('esports.relays', [])))],
            ['amount', (string) ($amountSats * 1000)],
            ['lnurl', self::lnurl()],
            ['p', $pool],
            ['a', (string) $tournament->address()],
            ['k', (string) Tournament::CALENDAR_EVENT],
        ];
    }

    private static function comment(string $comment): string
    {
        return mb_substr(trim(preg_replace('/\s+/u', ' ', $comment) ?? ''), 0, 140);
    }

    /**
     * The requester's IP as stored: keyed with the app key, so the table
     * holds no address and the hash is useless elsewhere.
     */
    public static function ipHash(string $ip): string
    {
        return hash_hmac('sha256', $ip, (string) config('app.key'));
    }

    /**
     * At most `open_invoices_per_user` unpaid, unexpired invoices per
     * logged-in user and `open_invoices_per_ip` per network (security gate
     * F2): an invoice costs the wallet a `make_invoice` and a row that is
     * looked up every minute until it expires.
     *
     * @throws PoolRefusal
     */
    private function checkOpenInvoices(?int $userId, string $ipHash): void
    {
        $open = fn () => IncomingPayment::query()->where('status', IncomingPaymentStatus::Pending)->where('expires_at', '>', now());

        if ($userId !== null && $open()->where('requester_user_id', $userId)->count() >= (int) config('esports.wallet.open_invoices_per_user', 5)) {
            throw new PoolRefusal(__('You have too many unpaid invoices open. Pay one or let it expire first.'));
        }

        if ($open()->where('requester_ip_hash', $ipHash)->count() >= (int) config('esports.wallet.open_invoices_per_ip', 20)) {
            throw new PoolRefusal(__('Too many unpaid invoices from this network. Please wait until one is paid or expires.'));
        }
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

        $me = Auth::user();
        $requester = ['requester_user_id' => $me instanceof User ? $me->id : null, 'requester_ip_hash' => self::ipHash((string) request()->ip())];

        // Sponsor invoices come from the organizers' own page; everyone else holds a few unpaid invoices at most (F2).
        if (($attributes['source'] ?? null) !== 'sponsor') {
            $this->checkOpenInvoices($requester['requester_user_id'], $requester['requester_ip_hash']);
        }

        $expiry = (int) config('esports.wallet.invoice_expiry_seconds', 900);

        try {
            $invoice = $wallet->makeInvoice($amountSats, $descriptionHash, $expiry);
        } catch (NwcError $error) {
            Log::warning('Pool invoice: the wallet made no invoice', ['code' => $error->errorCode]);

            throw new PoolRefusal(__('The league wallet did not answer. Please try again in a moment.'));
        }

        return IncomingPayment::query()->create([
            ...$attributes,
            ...$requester,
            'payment_hash' => $invoice->paymentHash,
            'bolt11' => $invoice->invoice,
            'amount_sats' => $amountSats,
            'status' => IncomingPaymentStatus::Pending,
            'expires_at' => now()->setTimestamp($invoice->expiresAt()),
        ]);
    }
}
