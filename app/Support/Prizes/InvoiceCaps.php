<?php

namespace App\Support\Prizes;

use App\Enums\IncomingPaymentStatus;
use App\Models\IncomingPayment;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Who asks for an invoice, and how many unpaid ones they may hold (security
 * gate F2, 2026-09-27): every invoice costs a wallet a `make_invoice` and a
 * row that is looked up until it expires. At most
 * `esports.wallet.open_invoices_per_user` unpaid, unexpired invoices per
 * logged-in user and `open_invoices_per_ip` per network, whichever wallet
 * made them (the league reserve's or a tournament pot's). The network is
 * stored as an HMAC of the IP with the app key, never the IP itself.
 */
final class InvoiceCaps
{
    /**
     * The requester of an invoice made now, as stored with it.
     *
     * @return array{requester_user_id: int|null, requester_ip_hash: string}
     */
    public static function requester(): array
    {
        $me = Auth::user();

        return ['requester_user_id' => $me instanceof User ? $me->id : null, 'requester_ip_hash' => self::ipHash((string) request()->ip())];
    }

    public static function ipHash(string $ip): string
    {
        return hash_hmac('sha256', $ip, (string) config('app.key'));
    }

    /**
     * @param  array{requester_user_id: int|null, requester_ip_hash: string}  $requester
     *
     * @throws PoolRefusal
     */
    public static function check(array $requester): void
    {
        // Sponsor invoices have caps of their own (PotTopUps::sponsorInvoice) and never use up the organizer's.
        $open = fn () => IncomingPayment::query()->where('status', IncomingPaymentStatus::Pending)->where('expires_at', '>', now())->where('source', '!=', 'sponsor');

        if ($requester['requester_user_id'] !== null && $open()->where('requester_user_id', $requester['requester_user_id'])->count() >= (int) config('esports.wallet.open_invoices_per_user', 5)) {
            throw new PoolRefusal(__('You have too many unpaid invoices open. Pay one or let it expire first.'));
        }

        if ($open()->where('requester_ip_hash', $requester['requester_ip_hash'])->count() >= (int) config('esports.wallet.open_invoices_per_ip', 20)) {
            throw new PoolRefusal(__('Too many unpaid invoices from this network. Please wait until one is paid or expires.'));
        }
    }
}
