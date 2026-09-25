<?php

namespace App\Support\Nostr;

use App\Models\NostrEvent;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * The door every player-signed event passes before the league acts on it.
 *
 * The server builds the unsigned template, the browser signs it, and here the
 * signed event must equal that template in kind, tags and content, be signed
 * by the logged-in player, pass the NIP rules and carry a valid signature.
 * Cheap checks run first; Schnorr runs last (see SignedEvent).
 *
 * NIP rules applied here: 1 (id, sig), 4 (clock window at submission),
 * 5 (id not seen before), 9 and "Replay protection" (a replaceable or
 * addressable version must be newer than the stored one; regular events such
 * as game notes have no "version"); 2, 3, 7-9 and 15 via
 * {@see EsportsEventRules}.
 */
final class SignedEventGate
{
    /** NIP rule 4: at most 10 minutes in the past ... */
    public const MAX_AGE_SECONDS = 600;

    /** ... and 5 minutes in the future. */
    public const MAX_AHEAD_SECONDS = 300;

    public function __construct(private EsportsEventRules $rules) {}

    /**
     * @param  mixed  $input  the signed event as the browser sent it (array or JSON string)
     * @param  array{kind: int, tags: list<list<string>>, content: string}  $template
     *
     * @throws RejectedEvent
     */
    public function check(mixed $input, array $template, User $author): SignedEvent
    {
        if (is_string($input)) {
            $input = json_decode($input, true);
        }

        $event = SignedEvent::fromInput($input);

        if ($event === null) {
            $this->reject('malformed');
        }

        if ($event->kind !== $template['kind']) {
            $this->reject('wrong_kind', $event);
        }

        if ($event->pubkey !== $author->pubkey) {
            $this->reject('foreign_author', $event);
        }

        if ($event->tags !== $template['tags'] || $event->content !== $template['content']) {
            $this->reject('not_the_prepared_event', $event);
        }

        $now = now()->getTimestamp();

        if ($event->createdAt < $now - self::MAX_AGE_SECONDS || $event->createdAt > $now + self::MAX_AHEAD_SECONDS) {
            $this->reject('created_at_outside_window', $event);
        }

        if (NostrEvent::query()->where('event_id', $event->id)->exists()) {
            $this->reject('already_seen', $event);
        }

        if (self::isReplaceable($event->kind)) {
            $stored = NostrEvent::query()
                ->where('kind', $event->kind)
                ->where('pubkey', $event->pubkey)
                ->where('d', $event->tag('d'))
                ->max('signed_at');

            if ($stored !== null && $event->createdAt <= (int) $stored) {
                $this->reject('not_newer_than_stored', $event);
            }
        }

        $violation = $this->rules->check($event);

        if ($violation !== null) {
            $this->reject($violation, $event);
        }

        if (! $event->hasValidSignature()) {
            $this->reject('invalid_signature', $event);
        }

        return $event;
    }

    /**
     * NIP-01: 0, 3 and 10000-19999 are replaceable, 30000-39999 addressable.
     */
    public static function isReplaceable(int $kind): bool
    {
        return $kind === 0 || $kind === 3 || ($kind >= 10000 && $kind < 20000) || ($kind >= 30000 && $kind < 40000);
    }

    private function reject(string $reason, ?SignedEvent $event = null): never
    {
        Log::info('Signed event refused: '.$reason, ['kind' => $event?->kind, 'pubkey' => $event?->pubkey]);

        throw new RejectedEvent($reason);
    }
}
