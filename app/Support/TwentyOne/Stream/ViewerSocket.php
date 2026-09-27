<?php

namespace App\Support\TwentyOne\Stream;

use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * The unix datagram socket nginx sends its playlist access log to, one
 * syslog datagram per request (config twentyone.stream.viewers):
 *
 *     # http level (log_format may not stand in a server block)
 *     log_format twentyone_hls escape=json '$remote_addr|$http_user_agent|$status';
 *     # in the location that serves /live/*.m3u8 (only there: segments are not logged)
 *     access_log syslog:server=unix:<socket>,nohostname,tag=hls twentyone_hls;
 *
 * An access_log in a location replaces the inherited ones for it; list the
 * site's regular access_log there too if the playlist should stay in it.
 * The daemon binds it and reads it without blocking once per loop turn.
 * nginx sends without blocking, too: while nobody listens (daemon down)
 * its lines are simply lost, and no request waits for us.
 */
final class ViewerSocket
{
    /** sun_path holds 108 bytes with the terminating NUL; PHP truncates a longer path silently. */
    public const MAX_PATH_BYTES = 107;

    /** nginx caps a syslog message well below this. */
    private const MAX_DATAGRAM_BYTES = 8192;

    /**
     * @param  resource  $socket
     */
    private function __construct(
        private $socket,
        public readonly string $path,
    ) {}

    /**
     * Bind `$path`, replacing a stale socket a previous run left (never a
     * file of another kind), writable for the nginx workers.
     *
     * @throws RuntimeException when the socket cannot be bound
     */
    public static function bind(string $path): self
    {
        if ($path === '' || strlen($path) > self::MAX_PATH_BYTES) {
            throw new RuntimeException('socket path is empty or longer than '.self::MAX_PATH_BYTES.' bytes ('.strlen($path).')');
        }

        if (file_exists($path) || is_link($path)) {
            if (@filetype($path) !== 'socket') {
                throw new RuntimeException('not a socket, left in place: '.$path);
            }

            if (! @unlink($path)) {
                throw new RuntimeException('stale socket not removable: '.$path);
            }
        }

        File::ensureDirectoryExists(dirname($path));
        $socket = @stream_socket_server('udg://'.$path, $errorCode, $errorMessage, STREAM_SERVER_BIND);

        if ($socket === false) {
            throw new RuntimeException('bind failed: '.($errorMessage !== '' ? $errorMessage : (error_get_last()['message'] ?? 'unknown error')).' ('.$path.')');
        }

        stream_set_blocking($socket, false);

        // The nginx workers run as another user; the socket is useless to them without write access.
        if (! @chmod($path, 0666)) {
            fclose($socket);
            @unlink($path);

            throw new RuntimeException('chmod 0666 failed: '.$path);
        }

        return new self($socket, $path);
    }

    /**
     * Feed the pending datagrams to `$counter`, at most `$limit` of them, so a
     * flood cannot hold the supervisor loop; the rest waits for the next turn.
     *
     * @return int datagrams read
     */
    public function drain(ViewerCounter $counter, int $now, int $limit): int
    {
        $read = 0;

        while ($read < $limit && ($datagram = @stream_socket_recvfrom($this->socket, self::MAX_DATAGRAM_BYTES)) !== false) {
            $read++;
            $counter->record($datagram, $now);
        }

        return $read;
    }

    /**
     * Close and remove the socket (best effort, on shutdown).
     */
    public function close(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }

        if (@filetype($this->path) === 'socket') {
            @unlink($this->path);
        }
    }
}
