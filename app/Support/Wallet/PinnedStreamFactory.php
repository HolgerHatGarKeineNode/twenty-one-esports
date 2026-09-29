<?php

namespace App\Support\Wallet;

use App\Support\Nostr\BoundedSocketStream;
use App\Support\Nostr\BoundedStreamFactory;
use Phrity\Net\Context;
use Phrity\Net\SocketClient;
use Psr\Http\Message\UriInterface;

/**
 * Opens the websocket's TCP/TLS socket to the address {@see RelayGuard}
 * checked, not to whatever the host name resolves to a moment later (no DNS
 * rebinding). TLS still verifies the certificate against the host name
 * (`peer_name`, also the SNI), and the HTTP handshake still says `Host:` with
 * the name, so the relay sees an ordinary client.
 *
 * With a deadline (P45 audit F1, the relays of a player's DM relay list) every
 * read also stops at that deadline and a byte budget ({@see BoundedSocketStream}),
 * as on the NIP-47 wallet connections.
 */
final class PinnedStreamFactory extends BoundedStreamFactory
{
    public function __construct(private readonly string $host, private readonly string $ip, ?float $deadline = null, int $maxBytes = 65536, ?int $maxFrameBytes = null)
    {
        parent::__construct($deadline, $maxBytes, $maxFrameBytes);
    }

    public function createSocketClient(UriInterface $uri, ?Context $context = null): SocketClient
    {
        $context ??= new Context;
        $context->setOption('ssl', 'peer_name', $this->host);
        $context->setOption('ssl', 'verify_peer', true);
        $context->setOption('ssl', 'verify_peer_name', true);

        return parent::createSocketClient($uri->withHost(str_contains($this->ip, ':') ? '['.$this->ip.']' : $this->ip), $context);
    }
}
