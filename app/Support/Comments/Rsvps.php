<?php

namespace App\Support\Comments;

use App\Enums\TournamentStatus;
use App\Models\NostrEvent;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Nostr\RejectedEvent;
use App\Support\Nostr\SignedEventGate;
use App\Support\Tournaments\TournamentSignups;

/**
 * A player's NIP-52 RSVP (kind 31925) to a tournament's calendar event (P48,
 * NIP "Comments, likes and RSVPs", rev. 9.9): "accepted" offered once the
 * player is in, "declined" once a player who said "accepted" through the
 * league is no longer in.
 *
 * The RSVP is not the sign-up. The sign-up is the league's own record (the
 * consent `22150`, never published); an RSVP is what calendar apps show, and
 * anybody can publish one. `d` is the tournament's address, so a later RSVP
 * of the same player replaces the earlier one on every relay (NIP-01
 * addressable). There is no `e`: a tournament gets new versions (a new time,
 * more places), and NIP-52 lets clients treat an RSVP with `e` as an answer
 * to that version only. There is no `fb`.
 */
final class Rsvps
{
    public const KIND = 31925;

    public const ACCEPTED = 'accepted';

    public const DECLINED = 'declined';

    public function __construct(private SignedEventGate $gate, private TournamentSignups $signups) {}

    /**
     * Which RSVP the league offers this player now, null for none.
     */
    public function offer(User $user, Tournament $tournament): ?string
    {
        if (CommentTarget::tournament($tournament) === null
            || in_array($tournament->status, [TournamentStatus::Finished, TournamentStatus::Cancelled], true)) {
            return null;
        }

        $said = $this->said($user, $tournament);

        if ($this->signups->entryOf($tournament, $user) !== null) {
            return $said === self::ACCEPTED ? null : self::ACCEPTED;
        }

        return $said === self::ACCEPTED ? self::DECLINED : null;
    }

    /**
     * The status of the newest RSVP of this player to this tournament that
     * went through the league, null for none.
     */
    public function said(User $user, Tournament $tournament): ?string
    {
        $address = $tournament->address();

        if ($address === null) {
            return null;
        }

        $newest = NostrEvent::query()->where('kind', self::KIND)->where('pubkey', $user->pubkey)->where('d', $address)
            ->orderByDesc('signed_at')->orderByDesc('id')->first();

        foreach ($newest?->payload()['tags'] ?? [] as $tag) {
            if (is_array($tag) && ($tag[0] ?? null) === 'status') {
                return is_string($tag[1] ?? null) ? $tag[1] : null;
            }
        }

        return null;
    }

    /**
     * @return array{kind: int, tags: list<list<string>>, content: string, created_at: int}
     *
     * @throws CommentRefused
     */
    public function template(User $user, Tournament $tournament, string $status): array
    {
        $target = CommentTarget::tournament($tournament);

        if ($target === null || $target->address === null || $this->offer($user, $tournament) !== $status) {
            throw new CommentRefused(__('There is nothing to answer here right now.'));
        }

        return [
            'kind' => self::KIND,
            'tags' => [
                ['d', $target->address],
                ['a', $target->address, $target->relay],
                ['status', $status],
                ['p', $target->pubkey, $target->relay],
                ['alt', 'RSVP to a tournament in TWENTY ONE Esports: '.$status],
            ],
            'content' => '',
            'created_at' => now()->getTimestamp(),
        ];
    }

    /**
     * @throws CommentRefused|RejectedEvent
     */
    public function submit(User $user, Tournament $tournament, string $status, mixed $signed): NostrEvent
    {
        NostrComments::count($user, 'rsvps');

        return NostrComments::keep($this->gate->check($signed, $this->template($user, $tournament, $status), $user));
    }
}
