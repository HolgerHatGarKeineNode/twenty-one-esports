<?php

namespace App\Support\Notifications;

use App\Models\NostrEvent;
use App\Models\User;
use App\Support\Nostr\NostrKeys;
use App\Support\Nostr\RelayPublisher;
use App\Support\Nostr\SignedEvent;
use RuntimeException;
use swentel\nostr\Encryption\Nip44;
use swentel\nostr\Event\Event;
use swentel\nostr\Key\Key;
use swentel\nostr\Sign\Sign;

/**
 * A notification as a NIP-17 direct message from the league's notification
 * key (NIP "Notifications"): a kind-14 rumor, sealed (kind 13) by the
 * notification key with NIP-44 to the player, gift-wrapped (kind 1059) by a
 * fresh random key. Seal and wrap carry a `created_at` up to two days in the
 * past (NIP-59); there is no copy to the sender, and the key has no DM relay
 * list, so NIP-17 clients do not reply.
 *
 * The cryptography is swentel/nostr-php's (NIP-44 v2, BIP-340); this class
 * only puts the three layers together. The player's client does the reverse
 * in resources/js/nostrChat.js, including the sender check (tests/Nostr).
 */
final class NotificationDm
{
    /** NIP-59: timestamps of seal and wrap up to two days in the past. */
    public const MAX_BACKDATE_SECONDS = 172800;

    private ?string $secret;

    public function __construct(?string $secret)
    {
        $this->secret = NostrKeys::secretToHex($secret);
    }

    public static function fromConfig(): self
    {
        return new self(config('esports.notifications.nsec'));
    }

    public function isConfigured(): bool
    {
        return $this->secret !== null;
    }

    public function pubkey(): ?string
    {
        return $this->secret === null ? null : (new Key)->getPublicKey($this->secret);
    }

    /**
     * The three layers for one message to one recipient.
     *
     * @return array{rumor: array<string, mixed>, seal: array<string, mixed>, wrap: array<string, mixed>}
     */
    public function build(string $recipient, string $text, ?int $match = null, ?int $now = null): array
    {
        if ($this->secret === null || ! NostrKeys::isHexPubkey($recipient)) {
            throw new RuntimeException('No notification key configured, or not a pubkey.');
        }

        $now ??= now()->getTimestamp();
        $sender = (string) $this->pubkey();

        $tags = [['p', $recipient]];

        if ($match !== null) {
            $tags[] = ['match', (string) $match];
        }

        $rumorEvent = (new Event)->setKind(14)->setTags($tags)->setContent($text)->setCreatedAt($now);
        $rumorEvent->setPublicKey($sender);
        $rumor = [
            'id' => hash('sha256', (string) Sign::serializeEvent($rumorEvent)),
            'pubkey' => $sender,
            'created_at' => $now,
            'kind' => 14,
            'tags' => $tags,
            'content' => $text,
        ];

        $seal = $this->signed(13, [], Nip44::encrypt($this->json($rumor), Nip44::getConversationKey($this->secret, $recipient)), $this->backdated($now), $this->secret);

        $wrapSecret = bin2hex(random_bytes(32));
        $wrap = $this->signed(1059, [['p', $recipient]], Nip44::encrypt($this->json($seal), Nip44::getConversationKey($wrapSecret, $recipient)), $this->backdated($now), $wrapSecret);

        return ['rumor' => $rumor, 'seal' => $seal, 'wrap' => $wrap];
    }

    /**
     * Wrap, store and publish to the chat relays. Returns the stored wrap, or
     * null when there is no notification key.
     */
    public function send(User $user, string $text, ?int $match = null): ?NostrEvent
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $signed = SignedEvent::fromInput($this->build($user->pubkey, $text, $match)['wrap']);

        if ($signed === null) {
            return null;
        }

        $event = NostrEvent::fromSigned($signed);
        app(RelayPublisher::class)->publish($event, config('esports.chat.relays', []));

        return $event;
    }

    /**
     * @param  list<list<string>>  $tags
     * @return array<string, mixed>
     */
    private function signed(int $kind, array $tags, string $content, int $createdAt, string $secret): array
    {
        $event = (new Event)->setKind($kind)->setTags($tags)->setContent($content)->setCreatedAt($createdAt);
        (new Sign)->signEvent($event, $secret);

        return $event->toArray();
    }

    private function backdated(int $now): int
    {
        return $now - random_int(0, self::MAX_BACKDATE_SECONDS);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function json(array $event): string
    {
        return json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
