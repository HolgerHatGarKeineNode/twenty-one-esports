<?php

namespace App\Support\TwentyOne\Stream;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * The unix datagram socket nginx sends its playlist access log to, one
 * syslog datagram per request (config twentyone.stream.viewers):
 *
 *     # http level (log_format may not stand in a server block)
 *     log_format twentyone_hls escape=json '$remote_addr|$http_user_agent|$status';
 *     # in the location that serves /live/*.m3u8 (only there: segments are not logged)
 *     access_log syslog:server=unix:<dir>/viewers.sock,nohostname,tag=hls twentyone_hls;
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

    /** The socket's name inside its directory. */
    public const FILE = 'viewers.sock';

    /** setfacl answers at once; a hanging one must not hold the start. */
    private const SETFACL_TIMEOUT_SECONDS = 5;

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
     * Bind `<dir>/viewers.sock` in a private directory: the directory is
     * ours (owner), closed to others (no "other" bits) and opened by an ACL
     * to the nginx user alone (search only), so no other user on the host
     * can reach the socket to forge or flood lines. The socket itself is
     * 0666 behind it. A stale socket a previous run left is replaced, never
     * a file of another kind. Nothing here widens a permission to make it
     * work: every check that fails leaves the count off.
     *
     * @param  string  $nginxUser  the user nginx workers run as ('' = no ACL, owner access only)
     *
     * @throws RuntimeException when the directory is not private or the socket cannot be bound
     */
    public static function bind(string $dir, string $nginxUser = ''): self
    {
        $dir = rtrim($dir, '/');
        $path = $dir.'/'.self::FILE;

        if ($dir === '' || strlen($path) > self::MAX_PATH_BYTES) {
            throw new RuntimeException('socket path is empty or longer than '.self::MAX_PATH_BYTES.' bytes ('.strlen($path).')');
        }

        self::privateDirectory($dir, $nginxUser);

        if (file_exists($path) || is_link($path)) {
            if (@filetype($path) !== 'socket') {
                throw new RuntimeException('not a socket, left in place: '.$path);
            }

            if (! @unlink($path)) {
                throw new RuntimeException('stale socket not removable: '.$path);
            }
        }

        $socket = @stream_socket_server('udg://'.$path, $errorCode, $errorMessage, STREAM_SERVER_BIND);

        if ($socket === false) {
            throw new RuntimeException('bind failed: '.($errorMessage !== '' ? $errorMessage : (error_get_last()['message'] ?? 'unknown error')).' ('.$path.')');
        }

        stream_set_blocking($socket, false);

        // The nginx workers run as another user than the daemon may; the directory, not this mode, keeps others out.
        if (! @chmod($path, 0666)) {
            fclose($socket);
            @unlink($path);

            throw new RuntimeException('chmod 0666 failed: '.$path);
        }

        return new self($socket, $path);
    }

    /**
     * Create `$dir` (0700) if missing, grant the nginx user search access
     * by ACL, and check the result: a real directory (no symlink), owned by
     * this process's user, without "other" bits. The ACL mask may show in
     * the group bits; that is expected. An existing directory keeps its mode:
     * one that is open to others is refused, not repaired.
     *
     * @throws RuntimeException
     */
    private static function privateDirectory(string $dir, string $nginxUser): void
    {
        if (is_link($dir)) {
            throw new RuntimeException('socket directory is a symlink, refused: '.$dir);
        }

        if (! file_exists($dir)) {
            File::ensureDirectoryExists(dirname($dir));

            if (! @mkdir($dir, 0700) || ! @chmod($dir, 0700)) {
                throw new RuntimeException('socket directory not created: '.$dir);
            }
        }

        if ($nginxUser !== '') {
            // One user name, nothing that setfacl could read as a second ACL entry or an option.
            if (preg_match('/^[a-z_][a-z0-9_-]{0,31}$/i', $nginxUser) !== 1) {
                throw new RuntimeException('not a user name: '.$nginxUser);
            }

            $result = Process::timeout(self::SETFACL_TIMEOUT_SECONDS)->env(ChildEnvironment::withoutSecrets())
                ->run(['setfacl', '-m', 'u:'.$nginxUser.':x', $dir]);

            if ($result->failed()) {
                throw new RuntimeException('setfacl for '.$nginxUser.' failed: '.trim(substr($result->errorOutput(), 0, 200)));
            }
        }

        clearstatcache(true, $dir);
        $stat = @lstat($dir);

        if ($stat === false || ($stat['mode'] & 0170000) !== 0040000) {
            throw new RuntimeException('socket directory is not a directory: '.$dir);
        }

        if ($stat['uid'] !== posix_geteuid()) {
            throw new RuntimeException('socket directory is not owned by this user: '.$dir);
        }

        if (($stat['mode'] & 0007) !== 0) {
            throw new RuntimeException(sprintf('socket directory is open to other users (mode %o), refused: %s', $stat['mode'] & 0777, $dir));
        }
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
