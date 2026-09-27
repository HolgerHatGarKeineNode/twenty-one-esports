<?php

namespace App\Support\Wallet;

use Phrity\Net\Context;
use Phrity\Net\SocketClient;
use Phrity\Net\StreamFactory;
use Psr\Http\Message\UriInterface;

/**
 * Opens the websocket's TCP/TLS socket to the address {@see RelayGuard}
 * checked, not to whatever the host name resolves to a moment later (no DNS
 * rebinding). TLS still verifies the certificate against the host name
 * (`peer_name`, also the SNI), and the HTTP handshake still says `Host:` with
 * the name, so the relay sees an ordinary client.
 */
final class PinnedStreamFactory extends StreamFactory
{
    public function __construct(private readonly string $host, private readonly string $ip)
    {
        parent::__construct();
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
