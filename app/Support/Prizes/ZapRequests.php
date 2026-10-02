<?php

namespace App\Support\Prizes;

use App\Models\IncomingPayment;
use App\Models\NostrEvent;
use App\Models\Tournament;
use App\Support\Nostr\SignedEvent;
use App\Support\SeasonChain\LeagueKey;

/**
 * Which pot a zap request (NIP-57 `9734`) to the league's LNURL endpoint
 * pays into, checked as the endpoint must check it (NIP-57 appendix D, NIP
 * "Pots and zap targets", rev. 9.12):
 *
 * - kind 9734 with a valid signature and tags;
 * - exactly one `p`, the pool key;
 * - an `amount` tag, when present, equal to the amount paid;
 * - one `a` naming a tournament's calendar event (`31923:<league key>:<d>`)
 *   whose pot is open in the league wallet: that tournament's pot (user,
 *   2026-10-02: „nutze doch einfach Zaps auf Nostr Events"). An `e` beside
 *   it must be one of that tournament's own versions, a `k` must say
 *   `31923`. Two `a`, or an `a` the league does not run a pot for, are
 *   refused rather than counted in the wrong pot;
 * - no `a` and no `e`: the league reserve. An `e` alone names a pot the
 *   league does not run (the reserve's zap goal, match fees): refused.
 *
 * Through a tournament's own LNURL (`?pot=<id>`, the QR code on its page)
 * the request must name exactly that tournament.
 */
final class ZapRequests
{
    /**
     * The pot the request pays into: the reserve or `tournament:<id>`.
     *
     * @throws PoolRefusal
     */
    public function potOf(SignedEvent $request, int $amountMsats, ?Tournament $only = null): string
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

        $addresses = $request->tagsNamed('a');

        if ($addresses === []) {
            if ($only !== null) {
                throw new PoolRefusal(__('This zap request names no tournament.'));
            }

            if ($request->tagsNamed('e') !== []) {
                throw new PoolRefusal(__('This pot does not take zaps yet.'));
            }

            return IncomingPayment::RESERVE;
        }

        $tournament = count($addresses) === 1 ? self::tournamentAt((string) ($addresses[0][0] ?? '')) : null;

        if ($tournament === null || ! PotTopUps::enabled($tournament) || ($only !== null && $only->id !== $tournament->id)) {
            throw new PoolRefusal(__('This tournament takes no zaps into its pot right now.'));
        }

        foreach ($request->tagsNamed('e') as [$eventId]) {
            if (! NostrEvent::query()->where('kind', Tournament::CALENDAR_EVENT)->where('d', $tournament->slug)
                ->where('pubkey', LeagueKey::fromConfig()?->pubkey())->where('event_id', $eventId)->exists()) {
                throw new PoolRefusal(__('The zap request names another event than the tournament.'));
            }
        }

        if (($kind = $request->tag('k')) !== null && $kind !== (string) Tournament::CALENDAR_EVENT) {
            throw new PoolRefusal(__('The zap request names another event than the tournament.'));
        }

        return IncomingPayment::tournamentPot($tournament->id);
    }

    /**
     * The tournament whose current calendar event has this address
     * (`31923:<league key>:<slug>`), or null.
     */
    public static function tournamentAt(string $address): ?Tournament
    {
        $parts = explode(':', $address, 3);
        $league = LeagueKey::fromConfig()?->pubkey();

        if (count($parts) !== 3 || $parts[0] !== (string) Tournament::CALENDAR_EVENT || $league === null || $parts[1] !== $league || $parts[2] === '') {
            return null;
        }

        $tournament = Tournament::query()->where('slug', $parts[2])->first();

        return $tournament !== null && $tournament->address() === $address ? $tournament : null;
    }
}
