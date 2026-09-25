<?php

namespace App\Support\TwentyOne;

/**
 * One non-blocking WebSocket client connection that publishes one event.
 *
 * Driven by {@see RelayPublisher}'s select loop through advance(); it never
 * blocks after open() (which resolves the host name). States in order:
 * TCP connect, TLS handshake (wss only), HTTP upgrade, then the EVENT frame
 * and reading frames until the relay's OK for our event id. `result` is set
 * once the outcome is known: [accepted, message].
 */
final class RelayConnection
{
    private const CONNECTING = 'connecting';

    private const TLS = 'tls';

    private const UPGRADING = 'upgrading';

    private const OPEN = 'open';

    private const WEBSOCKET_GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    /** Bytes read per socket and loop pass. */
    private const READ_CHUNK = 65536;

    /** A relay that sends more than this without an OK is dropped. */
    private const MAX_BUFFER_BYTES = 1_048_576;

    /** @var resource */
    public $socket;

    public ?string $failure = null;

    /** @var array{0: bool, 1: string}|null */
    public ?array $result = null;

    private string $state = self::CONNECTING;

    private string $outgoing = '';

    private string $incoming = '';

    private string $fragments = '';

    private string $key = '';

    private function __construct(
        private string $host,
        private int $port,
        private string $path,
        private bool $secure,
        private string $payload,
    ) {}

    /**
     * @param  array<string, mixed>  $sslOptions
     */
    public static function open(string $url, string $payload, array $sslOptions = []): self
    {
        $parts = parse_url($url);
        $secure = ($parts['scheme'] ?? '') === 'wss';
        $host = (string) ($parts['host'] ?? '');
        $path = ($parts['path'] ?? '') === '' ? '/' : (string) $parts['path'];

        if (isset($parts['query'])) {
            $path .= '?'.$parts['query'];
        }

        $connection = new self($host, (int) ($parts['port'] ?? ($secure ? 443 : 80)), $path, $secure, $payload);

        $context = stream_context_create(['ssl' => [
            'peer_name' => $host,
            'SNI_enabled' => true,
            'verify_peer' => true,
            'verify_peer_name' => true,
            ...$sslOptions,
        ]]);

        $socket = @stream_socket_client(
            'tcp://'.$host.':'.$connection->port,
            $errorCode,
            $errorMessage,
            0,
            STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT,
            $context,
        );

        if ($socket === false) {
            $connection->failure = 'could not connect: '.($errorMessage !== '' ? $errorMessage : 'error '.$errorCode);

            return $connection;
        }

        stream_set_blocking($socket, false);
        $connection->socket = $socket;

        return $connection;
    }

    public function wantsRead(): bool
    {
        return $this->state !== self::CONNECTING;
    }

    public function wantsWrite(): bool
    {
        return $this->state === self::CONNECTING || $this->state === self::TLS || $this->outgoing !== '';
    }

    /**
     * Do whatever the socket allows now, without blocking.
     */
    public function advance(bool $readable, bool $writable, string $eventId): void
    {
        if ($this->state === self::CONNECTING) {
            if (! $writable) {
                return;
            }

            if (@stream_socket_get_name($this->socket, true) === false) {
                $this->fail('connection refused');

                return;
            }

            $this->state = $this->secure ? self::TLS : self::UPGRADING;

            if (! $this->secure) {
                $this->queueUpgrade();
            }
        }

        if ($this->state === self::TLS) {
            $done = @stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);

            if ($done === false) {
                $this->fail('TLS handshake failed');

                return;
            }

            if ($done === 0) {
                return;
            }

            $this->state = self::UPGRADING;
            $this->queueUpgrade();
        }

        if ($this->outgoing !== '') {
            $written = @fwrite($this->socket, $this->outgoing);

            if ($written === false) {
                $this->fail('write failed');

                return;
            }

            $this->outgoing = (string) substr($this->outgoing, $written);
        }

        if ($readable) {
            $chunk = @fread($this->socket, self::READ_CHUNK);

            if ($chunk === false || ($chunk === '' && feof($this->socket))) {
                $this->fail('relay closed the connection');

                return;
            }

            $this->incoming .= $chunk;

            if (strlen($this->incoming) > self::MAX_BUFFER_BYTES) {
                $this->fail('relay sent too much without an OK');

                return;
            }
        }

        if ($this->state === self::UPGRADING) {
            $this->readUpgradeResponse();
        }

        if ($this->state === self::OPEN) {
            $this->readFrames($eventId);
        }
    }

    public function timeoutReason(): string
    {
        return match ($this->state) {
            self::CONNECTING => 'timed out while connecting',
            self::TLS => 'timed out during the TLS handshake',
            self::UPGRADING => 'timed out waiting for the WebSocket handshake',
            default => 'no OK before the timeout',
        };
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
    }

    private function queueUpgrade(): void
    {
        $this->key = base64_encode(random_bytes(16));
        $defaultPort = $this->secure ? 443 : 80;
        $host = $this->port === $defaultPort ? $this->host : $this->host.':'.$this->port;

        $this->outgoing .= "GET {$this->path} HTTP/1.1\r\n"
            ."Host: {$host}\r\n"
            ."Upgrade: websocket\r\n"
            ."Connection: Upgrade\r\n"
            ."Sec-WebSocket-Key: {$this->key}\r\n"
            ."Sec-WebSocket-Version: 13\r\n\r\n";
    }

    private function readUpgradeResponse(): void
    {
        $end = strpos($this->incoming, "\r\n\r\n");

        if ($end === false) {
            return;
        }

        $head = substr($this->incoming, 0, $end);
        $this->incoming = (string) substr($this->incoming, $end + 4);
        $lines = explode("\r\n", $head);

        if (preg_match('#^HTTP/1\.[01] 101 #', $lines[0].' ') !== 1) {
            $this->fail('WebSocket upgrade refused: '.$lines[0]);

            return;
        }

        $expected = base64_encode(sha1($this->key.self::WEBSOCKET_GUID, true));
        $accepted = false;

        foreach (array_slice($lines, 1) as $line) {
            [$name, $value] = array_pad(explode(':', $line, 2), 2, '');

            if (strcasecmp(trim($name), 'Sec-WebSocket-Accept') === 0 && trim($value) === $expected) {
                $accepted = true;
            }
        }

        if (! $accepted) {
            $this->fail('WebSocket upgrade without a valid Sec-WebSocket-Accept');

            return;
        }

        $this->state = self::OPEN;
        $this->outgoing .= self::frame(0x1, $this->payload);
    }

    private function readFrames(string $eventId): void
    {
        while ($this->result === null && strlen($this->incoming) >= 2) {
            $first = ord($this->incoming[0]);
            $second = ord($this->incoming[1]);
            $length = $second & 0x7F;
            $offset = 2;

            if ($length === 126) {
                if (strlen($this->incoming) < 4) {
                    return;
                }

                $length = self::bigEndian(substr($this->incoming, 2, 2));
                $offset = 4;
            } elseif ($length === 127) {
                if (strlen($this->incoming) < 10) {
                    return;
                }

                $length = self::bigEndian(substr($this->incoming, 2, 8));
                $offset = 10;
            }

            $mask = '';

            if (($second & 0x80) !== 0) {
                $mask = substr($this->incoming, $offset, 4);
                $offset += 4;
            }

            if (strlen($this->incoming) < $offset + $length) {
                return;
            }

            $data = substr($this->incoming, $offset, $length);
            $this->incoming = (string) substr($this->incoming, $offset + $length);

            if ($mask !== '') {
                $data = $data ^ substr(str_repeat($mask, intdiv($length, 4) + 1), 0, $length);
            }

            $this->handleFrame($first & 0x0F, ($first & 0x80) !== 0, $data, $eventId);
        }
    }

    private function handleFrame(int $opcode, bool $final, string $data, string $eventId): void
    {
        if ($opcode === 0x8) {
            $this->fail('relay closed the connection'.(strlen($data) > 2 ? ': '.substr($data, 2) : ''));

            return;
        }

        if ($opcode === 0x9) {
            $this->outgoing .= self::frame(0xA, $data);

            return;
        }

        if ($opcode !== 0x1 && $opcode !== 0x0) {
            return;
        }

        $this->fragments .= $data;

        if (! $final) {
            return;
        }

        $message = json_decode($this->fragments, true);
        $this->fragments = '';

        if (is_array($message) && ($message[0] ?? null) === 'OK' && ($message[1] ?? null) === $eventId) {
            $this->result = [($message[2] ?? null) === true, is_string($message[3] ?? null) ? $message[3] : ''];
        }
    }

    /**
     * A masked client frame (RFC 6455 section 5.2).
     */
    private static function frame(int $opcode, string $payload): string
    {
        $length = strlen($payload);
        $header = chr(0x80 | ($opcode & 0x0F));

        if ($length < 126) {
            $header .= chr(0x80 | $length);
        } elseif ($length < 65536) {
            $header .= chr(0x80 | 126).pack('n', $length);
        } else {
            $header .= chr(0x80 | 127).pack('J', $length);
        }

        $mask = random_bytes(4);

        return $header.$mask.($payload ^ substr(str_repeat($mask, intdiv($length, 4) + 1), 0, $length));
    }

    /**
     * An unsigned big-endian integer (RFC 6455 extended payload length).
     */
    private static function bigEndian(string $bytes): int
    {
        $value = 0;

        foreach (str_split($bytes) as $byte) {
            $value = ($value << 8) | ord($byte);
        }

        return $value;
    }

    private function fail(string $reason): void
    {
        $this->result = [false, $reason];
    }
}
