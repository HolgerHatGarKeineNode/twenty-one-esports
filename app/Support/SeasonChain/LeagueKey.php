<?php

namespace App\Support\SeasonChain;

use App\Jobs\PublishNostrEvent;
use App\Models\NostrEvent;
use App\Support\Nostr\NostrKeys;
use App\Support\Nostr\SignedEvent;
use App\Support\TwentyOne\TwentyOneSigner;
use RuntimeException;
use swentel\nostr\Event\Event;
use swentel\nostr\Key\Key;

/**
 * The league key (`esports.league.nsec`): signs the league's own events of
 * the season chain, stores them as accepted events and queues them for the
 * league relays (`esports.relays`, never a public relay by default;
 * PublishNostrEvent runs after the commit).
 *
 * Fail closed: without a valid secret there is no signer, and every caller
 * refuses its action (Block 0 cannot be released, no attestation is signed).
 * The secret stays inside TwentyOneSigner, which never reveals it.
 */
final class LeagueKey
{
    private function __construct(private readonly TwentyOneSigner $signer) {}

    public static function fromConfig(): ?self
    {
        $hex = NostrKeys::secretToHex(is_string(config('esports.league.nsec')) ? config('esports.league.nsec') : null);

        if ($hex === null) {
            return null;
        }

        $signer = TwentyOneSigner::fromNsec((new Key)->convertPrivateKeyToBech32($hex));

        return $signer === null ? null : new self($signer);
    }

    /** @throws RuntimeException naming the variable, never its value */
    public static function required(): self
    {
        return self::fromConfig() ?? throw new RuntimeException('ESPORTS_LEAGUE_NSEC is not set or not a valid secret key.');
    }

    public function pubkey(): string
    {
        return $this->signer->pubkey;
    }

    /**
     * Sign, store and queue for the league relays. Call inside the
     * transaction that writes the state the event records.
     *
     * @param  list<list<string>>  $tags
     */
    public function publish(int $kind, array $tags, string $content, int $createdAt): NostrEvent
    {
        $event = (new Event)->setKind($kind)->setTags($tags)->setContent($content)->setCreatedAt($createdAt);
        $signed = SignedEvent::fromInput($this->signer->sign($event))
            ?? throw new RuntimeException('The league key produced a malformed event.');

        $stored = NostrEvent::fromSigned($signed);
        PublishNostrEvent::dispatch($stored);

        return $stored;
    }
}
