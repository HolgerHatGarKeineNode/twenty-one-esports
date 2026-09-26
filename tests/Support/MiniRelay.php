<?php

namespace Tests\Support;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Message;
use Ratchet\RFC6455\Handshake\RequestVerifier;
use Ratchet\RFC6455\Handshake\ServerNegotiator;
use Ratchet\RFC6455\Messaging\CloseFrameChecker;
use Ratchet\RFC6455\Messaging\Frame;
use Ratchet\RFC6455\Messaging\FrameInterface;
use Ratchet\RFC6455\Messaging\MessageBuffer;
use Ratchet\RFC6455\Messaging\MessageInterface;
use React\Socket\ConnectionInterface;
use React\Socket\SocketServer;
use Throwable;

/**
 * An in-memory Nostr relay (NIP-01: EVENT/OK, REQ/EVENT/EOSE, CLOSE) for the
 * browser tests, so the game chat is tested over a real websocket and a real
 * relay protocol without ndak. Built from the websocket pieces Laravel Reverb
 * already brings (react/socket, ratchet/rfc6455); nothing new is installed.
 *
 * Deliberately naive: every event is kept (no replaceable semantics), no
 * signature check (the chat checks what it receives), no AUTH. Filters:
 * ids, authors, kinds, since, until, limit and single-letter tags (#p, #e).
 * Started as its own process by tests/Support/mini-relay.php.
 */
final class MiniRelay
{
    /** @var list<array<string, mixed>> */
    private array $events = [];

    /** @var array<int, array{connection: ConnectionInterface, buffer: MessageBuffer, subscriptions: array<string, list<array<string, mixed>>>}> */
    private array $clients = [];

    /**
     * Events the relay holds before the first client connects (e.g. a
     * player's kind-0 profile for tests/Browser/ChatAndDailyTest.php).
     *
     * @param  list<array<string, mixed>>  $events
     */
    public function seed(array $events): self
    {
        array_push($this->events, ...$events);

        return $this;
    }

    public function run(int $port): void
    {
        $negotiator = new ServerNegotiator(new RequestVerifier, new HttpFactory);
        $server = new SocketServer('127.0.0.1:'.$port);

        $server->on('connection', function (ConnectionInterface $connection) use ($negotiator): void {
            $head = '';
            $key = spl_object_id($connection);

            $connection->on('data', function (string $data) use ($connection, $negotiator, &$head, $key): void {
                if (isset($this->clients[$key])) {
                    $this->clients[$key]['buffer']->onData($data);

                    return;
                }

                $head .= $data;

                if (! str_contains($head, "\r\n\r\n")) {
                    return;
                }

                $response = $negotiator->handshake(Message::parseRequest($head));
                $connection->write(Message::toString($response));

                if ($response->getStatusCode() !== 101) {
                    $connection->end();

                    return;
                }

                $this->clients[$key] = [
                    'connection' => $connection,
                    'subscriptions' => [],
                    'buffer' => new MessageBuffer(
                        new CloseFrameChecker,
                        fn (MessageInterface $message) => $this->receive($key, $message->getPayload()),
                        function (FrameInterface $frame) use ($connection, $key): void {
                            match ($frame->getOpcode()) {
                                Frame::OP_PING => $this->clients[$key]['buffer']->sendFrame(new Frame($frame->getPayload(), true, Frame::OP_PONG)),
                                Frame::OP_CLOSE => $connection->end(),
                                default => null,
                            };
                        },
                        sender: fn (string $bytes) => $connection->write($bytes),
                    ),
                ];
            });

            $connection->on('close', function () use ($key): void {
                unset($this->clients[$key]);
            });
        });
    }

    private function receive(int $key, string $payload): void
    {
        try {
            $message = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $this->send($key, ['NOTICE', 'invalid: not JSON']);

            return;
        }

        match ($message[0] ?? null) {
            'EVENT' => $this->publish($key, $message[1] ?? []),
            'REQ' => $this->subscribe($key, (string) ($message[1] ?? ''), array_slice($message, 2)),
            'CLOSE' => $this->unsubscribe($key, (string) ($message[1] ?? '')),
            default => $this->send($key, ['NOTICE', 'invalid: unknown message']),
        };
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function publish(int $key, array $event): void
    {
        $id = (string) ($event['id'] ?? '');
        $duplicate = collect($this->events)->contains(fn (array $stored) => $stored['id'] === $id);

        if (! $duplicate) {
            $this->events[] = $event;
        }

        $this->send($key, ['OK', $id, true, $duplicate ? 'duplicate: already have it' : '']);

        if ($duplicate) {
            return;
        }

        foreach ($this->clients as $client => $state) {
            foreach ($state['subscriptions'] as $subscription => $filters) {
                if ($this->matchesAny($event, $filters)) {
                    $this->send($client, ['EVENT', $subscription, $event]);
                }
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $filters
     */
    private function subscribe(int $key, string $subscription, array $filters): void
    {
        $this->clients[$key]['subscriptions'][$subscription] = $filters;

        // NIP-01: `limit` applies to each filter's own initial query; the answer is their union.
        $matching = [];

        foreach ($filters ?: [[]] as $filter) {
            $own = array_values(array_filter($this->events, fn (array $event) => $this->matchesAny($event, [$filter])));
            usort($own, fn (array $a, array $b) => ($b['created_at'] ?? 0) <=> ($a['created_at'] ?? 0));

            foreach (array_slice($own, 0, (int) ($filter['limit'] ?? PHP_INT_MAX)) as $event) {
                $matching[(string) ($event['id'] ?? '')] = $event;
            }
        }

        $matching = array_values($matching);
        usort($matching, fn (array $a, array $b) => ($b['created_at'] ?? 0) <=> ($a['created_at'] ?? 0));

        foreach ($matching as $event) {
            $this->send($key, ['EVENT', $subscription, $event]);
        }

        $this->send($key, ['EOSE', $subscription]);
    }

    private function unsubscribe(int $key, string $subscription): void
    {
        unset($this->clients[$key]['subscriptions'][$subscription]);
    }

    /**
     * @param  array<string, mixed>  $event
     * @param  list<array<string, mixed>>  $filters
     */
    private function matchesAny(array $event, array $filters): bool
    {
        foreach ($filters as $filter) {
            if ($this->matches($event, $filter)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $event
     * @param  array<string, mixed>  $filter
     */
    private function matches(array $event, array $filter): bool
    {
        foreach (['ids' => 'id', 'authors' => 'pubkey', 'kinds' => 'kind'] as $field => $property) {
            if (isset($filter[$field]) && ! in_array($event[$property] ?? null, $filter[$field], true)) {
                return false;
            }
        }

        if ((isset($filter['since']) && ($event['created_at'] ?? 0) < $filter['since']) || (isset($filter['until']) && ($event['created_at'] ?? 0) > $filter['until'])) {
            return false;
        }

        foreach ($filter as $name => $values) {
            if (is_string($name) && strlen($name) === 2 && $name[0] === '#') {
                $tagged = array_map(fn (array $tag) => $tag[1] ?? null, array_filter($event['tags'] ?? [], fn (array $tag) => ($tag[0] ?? null) === $name[1]));

                if (array_intersect($tagged, (array) $values) === []) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param  list<mixed>  $message
     */
    private function send(int $key, array $message): void
    {
        if (isset($this->clients[$key])) {
            $this->clients[$key]['buffer']->sendMessage((string) json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
    }
}
