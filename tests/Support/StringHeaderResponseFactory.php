<?php

namespace Tests\Support;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The response factory MiniRelay hands to the Ratchet handshake.
 *
 * ratchet/rfc6455 0.4.1 sets `Sec-WebSocket-Version` from an int (`ServerNegotiator::getVersionNumber()`),
 * and guzzlehttp/psr7 2.13.x refuses a non-string header value ("Header value must be a string or array of
 * strings but int provided"). Without this the relay dies in the very first WebSocket handshake, so every
 * test that publishes to it sees "relay closed the connection". The responses here turn scalar header
 * values into strings first; nothing else differs from GuzzleHttp\Psr7\HttpFactory.
 */
final class StringHeaderResponseFactory implements ResponseFactoryInterface
{
    public function createResponse(int $code = 200, string $reasonPhrase = ''): ResponseInterface
    {
        return new class($code, [], null, '1.1', $reasonPhrase === '' ? null : $reasonPhrase) extends Response
        {
            /**
             * @param  string  $name
             * @param  mixed  $value
             */
            public function withHeader($name, $value): static
            {
                $value = is_array($value) ? array_map(fn (mixed $item): mixed => is_scalar($item) ? (string) $item : $item, $value) : (is_scalar($value) ? (string) $value : $value);

                return parent::withHeader($name, $value);
            }
        };
    }
}
