<?php

namespace App\Support\Nostr;

use Phrity\Net\Context;
use Phrity\Net\SocketClient;
use Phrity\Net\SocketStream;
use Phrity\Net\StreamFactory;
use Psr\Http\Message\UriInterface;

/**
 * Websocket connections whose every read stops at an absolute deadline and a
 * byte budget ({@see BoundedSocketStream}). Without a deadline it is phrity's
 * own factory, unchanged (the NIP-47 wallet connections wait on purpose).
 */
class BoundedStreamFactory extends StreamFactory
{
    public function __construct(private readonly ?float $deadline = null, private readonly int $maxBytes = 65536)
    {
        parent::__construct();
    }

    public function createSocketClient(UriInterface $uri, ?Context $context = null): SocketClient
    {
        if ($this->deadline === null) {
            return parent::createSocketClient($uri, $context);
        }

        $deadline = $this->deadline;
        $maxBytes = $this->maxBytes;

        return new class($uri, $context, $deadline, $maxBytes) extends SocketClient
        {
            public function __construct(UriInterface $uri, ?Context $context, private readonly float $deadline, private readonly int $maxBytes)
            {
                parent::__construct($uri, $context);
            }

            public function connect(): SocketStream
            {
                $this->setTimeout(max(0.01, min((float) ($this->timeout ?? 5), $this->deadline - microtime(true))));
                $resource = parent::connect()->detach();

                return new BoundedSocketStream($resource, $this->deadline, $this->maxBytes);
            }
        };
    }
}
