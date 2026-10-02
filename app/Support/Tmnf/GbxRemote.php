<?php

namespace App\Support\Tmnf;

/**
 * A GBXRemote 2 connection to a TMNF dedicated server (plan "Trackmania und
 * Restposten", P1), the protocol of its XML-RPC port:
 *
 * - On connect the server greets with a 4-byte little-endian length and
 *   `GBXRemote 2`; anything else is refused (GbxProtocolError).
 * - Every frame is a 4-byte little-endian size, a 4-byte little-endian
 *   handle and an XML-RPC body. The client numbers its calls from
 *   0x80000000 up; the answer carries the same handle. A frame with a handle
 *   below 0x80000000 is a callback (`methodCall`) the server sent by itself.
 * - Callbacks that arrive while the client waits for an answer are queued
 *   and handed out by callbacks(), in the order they came.
 *
 * Fails closed: a closed socket, a timeout, an oversized or malformed frame
 * throw (GbxUnavailable, GbxProtocolError) and the connection is closed; the
 * listener reconnects. The stream is injectable (over()), so the unit tests
 * replay recorded frames through a socket pair.
 */
final class GbxRemote
{
    /** The largest frame read; the server's own limit for a request is 1 MB, rankings stay far below. */
    public const MAX_FRAME_BYTES = 4 * 1024 * 1024;

    private const FIRST_HANDLE = 0x80000000;

    private const LAST_HANDLE = 0xFFFFFFFF;

    /** @var resource|null */
    private $stream;

    private int $handle = self::FIRST_HANDLE;

    /** @var list<TmnfCallback> */
    private array $queued = [];

    /**
     * @param  resource  $stream
     */
    private function __construct($stream, float $timeout)
    {
        $this->stream = $stream;
        stream_set_blocking($stream, true);

        $greeting = $this->read(4, $timeout);
        $length = self::uint32($greeting);

        if ($length !== 11 || $this->read($length, $timeout) !== 'GBXRemote 2') {
            $this->close();

            throw new GbxProtocolError('The peer is no GBXRemote 2 server.');
        }
    }

    /**
     * Connects and reads the greeting.
     *
     * @throws GbxUnavailable
     * @throws GbxProtocolError
     */
    public static function connect(string $host, int $port, float $timeout = 5.0): self
    {
        $errno = 0;
        $error = '';
        $stream = @stream_socket_client("tcp://{$host}:{$port}", $errno, $error, $timeout);

        if ($stream === false) {
            throw new GbxUnavailable("Cannot reach the TMNF server at {$host}:{$port}: {$error} ({$errno}).");
        }

        return new self($stream, $timeout);
    }

    /**
     * A connection over an open stream (a test's socket pair); reads the greeting.
     *
     * @param  resource  $stream
     */
    public static function over($stream, float $timeout = 5.0): self
    {
        return new self($stream, $timeout);
    }

    public function isOpen(): bool
    {
        return $this->stream !== null;
    }

    /**
     * Calls a method and waits for its answer; callbacks that arrive meanwhile are queued.
     *
     * @param  list<mixed>  $params
     *
     * @throws GbxFault
     * @throws GbxUnavailable
     * @throws GbxProtocolError
     */
    public function call(string $method, array $params = [], float $timeout = 10.0): mixed
    {
        $handle = $this->nextHandle();
        $body = XmlRpc::encodeCall($method, $params);

        if (strlen($body) > self::MAX_FRAME_BYTES) {
            throw new GbxProtocolError("The call [{$method}] is too large to send.");
        }

        $this->write(pack('VV', strlen($body), $handle).$body);
        $deadline = microtime(true) + $timeout;

        while (true) {
            [$frameHandle, $xml] = $this->frame(max(0.0, $deadline - microtime(true)));

            if ($frameHandle === $handle) {
                return XmlRpc::decodeResponse($xml);
            }

            if ($frameHandle < self::FIRST_HANDLE) {
                $this->queued[] = new TmnfCallback(...XmlRpc::decodeCall($xml));

                continue;
            }

            // An answer to a call this client never waits for any more: not ours to read.
            $this->close();

            throw new GbxProtocolError("An answer to handle {$frameHandle} while waiting for {$handle}.");
        }
    }

    /**
     * The callbacks that came: the queued ones at once, else those that
     * arrive within `$wait` seconds (then every frame that is already there).
     *
     * @return list<TmnfCallback>
     *
     * @throws GbxUnavailable
     * @throws GbxProtocolError
     */
    public function callbacks(float $wait = 1.0): array
    {
        if ($this->queued === [] && $this->readable($wait)) {
            do {
                try {
                    [$frameHandle, $xml] = $this->frame(5.0);
                } catch (GbxException $e) {
                    // The callbacks read before the connection broke are handed out; the next read meets the closed connection.
                    if ($this->queued === []) {
                        throw $e;
                    }

                    break;
                }

                if ($frameHandle >= self::FIRST_HANDLE) {
                    $this->close();

                    throw new GbxProtocolError("An answer to handle {$frameHandle} nobody waits for.");
                }

                $this->queued[] = new TmnfCallback(...XmlRpc::decodeCall($xml));
            } while ($this->readable(0.0));
        }

        $callbacks = $this->queued;
        $this->queued = [];

        return $callbacks;
    }

    public function close(): void
    {
        if ($this->stream !== null) {
            @fclose($this->stream);
            $this->stream = null;
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    private function nextHandle(): int
    {
        $handle = $this->handle;
        $this->handle = $handle >= self::LAST_HANDLE ? self::FIRST_HANDLE : $handle + 1;

        return $handle;
    }

    /**
     * @return array{0: int, 1: string} handle and body
     */
    private function frame(float $timeout): array
    {
        $deadline = microtime(true) + $timeout;
        $header = $this->read(8, $timeout);
        $size = self::uint32(substr($header, 0, 4));
        $handle = self::uint32(substr($header, 4, 4));

        if ($size === 0 || $size > self::MAX_FRAME_BYTES) {
            $this->close();

            throw new GbxProtocolError("A frame of {$size} bytes is refused.");
        }

        return [$handle, $this->read($size, max(0.0, $deadline - microtime(true)))];
    }

    private function readable(float $wait): bool
    {
        $stream = $this->open();
        $read = [$stream];
        $write = null;
        $except = null;
        $seconds = (int) floor($wait);
        $ready = @stream_select($read, $write, $except, $seconds, (int) (($wait - $seconds) * 1_000_000));

        if ($ready === false) {
            $this->close();

            throw new GbxUnavailable('Waiting for the TMNF server failed.');
        }

        return $ready > 0;
    }

    private function read(int $length, float $timeout): string
    {
        $deadline = microtime(true) + $timeout;
        $data = '';

        while (strlen($data) < $length) {
            $left = $deadline - microtime(true);

            if ($left <= 0 || ! $this->readable($left)) {
                $this->close();

                throw new GbxUnavailable('The TMNF server did not answer in time.');
            }

            $chunk = @fread($this->open(), max(1, $length - strlen($data)));

            if ($chunk === false || $chunk === '') {
                $this->close();

                throw new GbxUnavailable('The TMNF server closed the connection.');
            }

            $data .= $chunk;
        }

        return $data;
    }

    private function write(string $bytes): void
    {
        while ($bytes !== '') {
            $written = @fwrite($this->open(), $bytes);

            if ($written === false || $written === 0) {
                $this->close();

                throw new GbxUnavailable('Writing to the TMNF server failed.');
            }

            $bytes = substr($bytes, $written);
        }
    }

    /**
     * @return resource
     */
    private function open()
    {
        return $this->stream ?? throw new GbxUnavailable('The connection to the TMNF server is closed.');
    }

    private static function uint32(string $bytes): int
    {
        $value = unpack('V', $bytes);

        return is_array($value) ? (int) $value[1] : 0;
    }
}
