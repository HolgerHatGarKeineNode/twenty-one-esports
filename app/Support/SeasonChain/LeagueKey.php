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
        return self::fromSecret(config('esports.league.nsec'));
    }

    /**
     * The trust key (`esports.trust.nsec`, NIP "Trust rank"): signs only the
     * trust job's events, its description (`0`), the anchor list (`30000`)
     * and the trust assertions (`30382`). NIP-85 wants a key per algorithm,
     * so it is never the league key. Null without a valid secret.
     */
    public static function trust(): ?self
    {
        return self::fromSecret(config('esports.trust.nsec'));
    }

    /**
     * The badge key (`esports.badges.nsec`, NIP "Rank badges"): signs only
     * the NIP-58 badge definitions (30009) and awards (8), automatically on
     * every rank change. Never the league key. Null without a valid secret.
     */
    public static function badge(): ?self
    {
        return self::fromSecret(config('esports.badges.nsec'));
    }

    /**
     * The LNURL server key (`esports.wallet.lnurl_nsec`, NIP "Prize pool
     * funding"): signs only the zap receipts (9735) of the league's own LNURL
     * endpoint. Null without a valid secret (no invoice is made then).
     */
    public static function lnurl(): ?self
    {
        return self::fromSecret(config('esports.wallet.lnurl_nsec'));
    }

    /**
     * The sponsor desk key (`esports.wallet.sponsor_nsec`): signs only the
     * zap requests (9734) behind sponsor invoices. Null without a valid secret.
     */
    public static function sponsorDesk(): ?self
    {
        return self::fromSecret(config('esports.wallet.sponsor_nsec'));
    }

    /**
     * A fresh key for one anonymous zap request (NIP "Who pays how": a
     * visitor without Nostr pays an invoice whose zap request was signed with
     * a throwaway key). Its secret is dropped with the object.
     */
    public static function throwaway(): self
    {
        return self::fromSecret(bin2hex(random_bytes(32))) ?? self::throwaway();
    }

    private static function fromSecret(#[\SensitiveParameter] mixed $secret): ?self
    {
        $hex = NostrKeys::secretToHex(is_string($secret) ? $secret : null);

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
     * Sign only: nothing is stored or published (a zap request goes to the
     * LNURL endpoint, not to a relay).
     *
     * @param  list<list<string>>  $tags
     */
    public function sign(int $kind, array $tags, string $content, int $createdAt): SignedEvent
    {
        $event = (new Event)->setKind($kind)->setTags($tags)->setContent($content)->setCreatedAt($createdAt);

        return SignedEvent::fromInput($this->signer->sign($event))
            ?? throw new RuntimeException('The key produced a malformed event.');
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
