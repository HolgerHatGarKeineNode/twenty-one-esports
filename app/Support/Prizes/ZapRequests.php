<?php

namespace App\Support\Prizes;

use App\Models\IncomingPayment;
use App\Support\Nostr\SignedEvent;
use App\Support\SeasonChain\LeagueKey;

/**
 * Which pot a zap request (NIP-57 `9734`) to the league's LNURL endpoint
 * pays into, checked as the endpoint must check it (NIP-57 appendix D, NIP
 * "Pots and zap targets"):
 *
 * - kind 9734 with a valid signature and tags;
 * - exactly one `p`, the pool key;
 * - no `a` and no `e`: the league reserve, the only pot the endpoint takes.
 *   A tournament (`a`) is refused: every tournament pot is its tournament's
 *   own wallet (user, 2026-09-27), never the league's. An `e` names a pot
 *   the league does not run yet (the reserve's zap goal, match fees):
 *   refused rather than counted in the wrong pot;
 * - an `amount` tag, when present, equal to the amount paid.
 */
final class ZapRequests
{
    /**
     * The pot the request pays into (always the reserve).
     *
     * @throws PoolRefusal
     */
    public function potOf(SignedEvent $request, int $amountMsats): string
    {
        $pool = LeagueKey::poolPubkey();

        if ($pool === null) {
            throw new PoolRefusal(__('The league reserve is not set up yet.'));
        }

        if ($request->kind !== 9734 || $request->tags === [] || ! $request->hasValidSignature()) {
            throw new PoolRefusal(__('Not a valid zap request.'));
        }

        $recipients = $request->tagsNamed('p');

        if (count($recipients) !== 1 || ($recipients[0][0] ?? null) !== $pool) {
            throw new PoolRefusal(__('A zap request names the pool key as its only recipient.'));
        }

        $amount = $request->tag('amount');

        if ($amount !== null && $amount !== (string) $amountMsats) {
            throw new PoolRefusal(__('The zap request is for a different amount.'));
        }

        if ($request->tagsNamed('a') !== []) {
            throw new PoolRefusal(__('Tournament pots take no zaps through the league: add sats on the tournament page.'));
        }

        if ($request->tagsNamed('e') !== []) {
            throw new PoolRefusal(__('This pot does not take zaps yet.'));
        }

        return IncomingPayment::RESERVE;
    }
}
