<?php

namespace App\Support\Prizes;

use App\Models\IncomingPayment;
use App\Models\Tournament;
use App\Support\Nostr\SignedEvent;
use App\Support\SeasonChain\LeagueKey;

/**
 * Which pot a zap request (NIP-57 `9734`) pays into, checked as the league's
 * LNURL endpoint must check it (NIP-57 appendix D, NIP "Pots and zap
 * targets"):
 *
 * - kind 9734 with a valid signature and tags;
 * - exactly one `p`, the pool key;
 * - at most one `a`: a tournament of the league (`31923:<league>:<slug>`)
 *   whose pool is open; the tournament's pot. Without `a` and `e`: the
 *   reserve. An `e` names a pot the league does not run yet (the reserve's
 *   zap goal, match fees): refused rather than counted in the wrong pot;
 * - an `amount` tag, when present, equal to the amount paid; a `k` tag,
 *   when present, the kind the `a` names.
 *
 * Every request names exactly one pot; a request that names two is refused.
 */
final class ZapRequests
{
    /**
     * @return array{pot: string, tournament: Tournament|null}
     *
     * @throws PoolRefusal
     */
    public function potOf(SignedEvent $request, int $amountMsats): array
    {
        $pool = PrizePool::poolPubkey();

        if ($pool === null) {
            throw new PoolRefusal(__('The prize pools are not set up yet.'));
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

        $addresses = $request->tagsNamed('a');
        $events = $request->tagsNamed('e');

        if ($events !== []) {
            throw new PoolRefusal(__('This pot does not take zaps yet.'));
        }

        if (count($addresses) > 1) {
            throw new PoolRefusal(__('A zap request names exactly one pot.'));
        }

        if ($addresses === []) {
            return ['pot' => IncomingPayment::RESERVE, 'tournament' => null];
        }

        $tournament = $this->tournament((string) ($addresses[0][0] ?? ''));

        if ($tournament === null || ! $tournament->isPoolOpen() || $tournament->hasOwnWallet()) {
            throw new PoolRefusal(__('This tournament takes no zaps: it has no open prize pool.'));
        }

        $kind = $request->tag('k');

        if ($kind !== null && $kind !== (string) Tournament::CALENDAR_EVENT) {
            throw new PoolRefusal(__('Not a valid zap request.'));
        }

        return ['pot' => IncomingPayment::tournamentPot($tournament->id), 'tournament' => $tournament];
    }

    private function tournament(string $address): ?Tournament
    {
        $league = LeagueKey::fromConfig()?->pubkey();
        $parts = explode(':', $address, 3);

        if ($league === null || count($parts) !== 3 || $parts[0] !== (string) Tournament::CALENDAR_EVENT || $parts[1] !== $league || $parts[2] === '') {
            return null;
        }

        return Tournament::query()->where('slug', $parts[2])->whereNotNull('event_id')->first();
    }
}
