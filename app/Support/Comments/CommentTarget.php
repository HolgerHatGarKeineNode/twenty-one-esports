<?php

namespace App\Support\Comments;

use App\Enums\TournamentStatus;
use App\Models\ChessGame;
use App\Models\NostrEvent;
use App\Models\SeriesMatch;
use App\Models\Tournament;
use App\Support\Nostr\NostrKeys;

/**
 * What a comment or like on a page points at (P48, NIP "Comments, likes and
 * RSVPs"): an event the league has on Nostr, never a page of its own.
 *
 * - tournament: its NIP-52 calendar event (`31923`), addressable, so the
 *   root is its address (`A`), the current version's id rides along as `e`;
 * - game: the league's record of a rated chess game (`64`), by id (`E`);
 * - series: the challenge of a rated series (`2150`), the root of its whole
 *   match chain, by id (`E`).
 *
 * A casual game or series has no league event and so no target: its page
 * shows no comments.
 */
final readonly class CommentTarget
{
    public const TYPES = ['tournament', 'game', 'series'];

    public function __construct(
        public string $type,
        public string $id,
        public int $kind,
        public string $pubkey,
        public string $eventId,
        public ?string $address,
        public string $relay,
    ) {}

    public static function resolve(string $type, string $id): ?self
    {
        if (! ctype_digit($id) || strlen($id) > 18) {
            return null;
        }

        return match ($type) {
            'tournament' => self::tournament(Tournament::query()->with('event')->find((int) $id)),
            'game' => self::event('game', $id, ChessGame::query()->with('recordEvent')->find((int) $id)?->recordEvent),
            'series' => self::event('series', $id, SeriesMatch::query()->with('challengeEvent')->where('number', (int) $id)->first()?->challengeEvent),
            default => null,
        };
    }

    public static function tournament(?Tournament $tournament): ?self
    {
        $address = $tournament?->address();

        if ($tournament === null || $address === null || $tournament->event === null || $tournament->published_at === null
            || $tournament->status === TournamentStatus::Draft) {
            return null;
        }

        return new self('tournament', (string) $tournament->id, Tournament::CALENDAR_EVENT, $tournament->event->pubkey, $tournament->event->event_id, $address, self::relayHint());
    }

    private static function event(string $type, string $id, ?NostrEvent $event): ?self
    {
        if ($event === null || ! NostrKeys::isHexPubkey($event->pubkey)) {
            return null;
        }

        return new self($type, $id, $event->kind, $event->pubkey, $event->event_id, null, self::relayHint());
    }

    public function addressable(): bool
    {
        return $this->address !== null;
    }

    /**
     * The NIP-22 scope of a top-level comment: root (`A`/`E`, `K`, `P`) and
     * the same item as parent (`a` with the version's `e`, or `e`; `k`, `p`).
     *
     * @return list<list<string>>
     */
    public function commentTags(): array
    {
        $kind = (string) $this->kind;

        if ($this->address !== null) {
            return [
                ['A', $this->address, $this->relay],
                ['K', $kind],
                ['P', $this->pubkey, $this->relay],
                ['a', $this->address, $this->relay],
                ['e', $this->eventId, $this->relay],
                ['k', $kind],
                ['p', $this->pubkey, $this->relay],
            ];
        }

        return [
            ['E', $this->eventId, $this->relay, $this->pubkey],
            ['K', $kind],
            ['P', $this->pubkey, $this->relay],
            ['e', $this->eventId, $this->relay, $this->pubkey],
            ['k', $kind],
            ['p', $this->pubkey, $this->relay],
        ];
    }

    /**
     * The NIP-25 tags of a like: `e` the event (the current version of an
     * addressable one), its address as `a`, the author `p`, the kind `k`.
     *
     * @return list<list<string>>
     */
    public function reactionTags(): array
    {
        return [
            ['e', $this->eventId, $this->relay, $this->pubkey],
            ...($this->address !== null ? [['a', $this->address, $this->relay, $this->pubkey]] : []),
            ['p', $this->pubkey, $this->relay],
            ['k', (string) $this->kind],
        ];
    }

    /**
     * What the browser needs to read and check comments and likes of this
     * target (resources/js/nostrComments.js).
     *
     * @return array{kind: int, pubkey: string, eventId: string, address: string|null}
     */
    public function forBrowser(): array
    {
        return ['kind' => $this->kind, 'pubkey' => $this->pubkey, 'eventId' => $this->eventId, 'address' => $this->address];
    }

    /** The first league relay: the hint in every reference. */
    public static function relayHint(): string
    {
        return (string) (config('esports.relays')[0] ?? '');
    }
}
