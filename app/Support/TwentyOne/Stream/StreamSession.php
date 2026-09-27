<?php

namespace App\Support\TwentyOne\Stream;

use Illuminate\Support\Facades\File;
use Throwable;

/**
 * The live session the kind-30311 event announces, kept across daemon
 * restarts in a small JSON file (`twentyone.stream.session_file`):
 * `{"starts": <first live>, "lastLiveAt": <last accepted live publish>}`.
 *
 * zap.stream shows only the chat from `starts` on
 * (src/element/chat/live-chat.tsx: `.filter(a => a.created_at >= started …)`),
 * so a restart that announced a new `starts` would hide the whole chat. A
 * start therefore resumes the session when its last live publish is younger
 * than `session_resume_minutes`; older, missing, unreadable or corrupt means
 * a new session. `twentyone:stream:end` clears it.
 */
final class StreamSession
{
    public function __construct(private string $path) {}

    public static function fromConfig(): self
    {
        return new self((string) config('twentyone.stream.session_file'));
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * The stored session, null when there is none; `$problem` says why a file
     * that exists could not be used (unreadable or corrupt).
     *
     * @param-out string|null $problem
     *
     * @return array{starts: int, lastLiveAt: int}|null
     */
    public function read(?string &$problem = null): ?array
    {
        $problem = null;

        if (! is_file($this->path)) {
            return null;
        }

        try {
            $data = json_decode((string) file_get_contents($this->path), true, 4, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            $problem = 'unreadable or not JSON ('.$e->getMessage().')';

            return null;
        }

        if (! is_array($data) || ! is_int($data['starts'] ?? null) || ! is_int($data['lastLiveAt'] ?? null)
            || $data['starts'] <= 0 || $data['lastLiveAt'] < $data['starts']) {
            $problem = 'not a session (starts and lastLiveAt must be timestamps, lastLiveAt not before starts)';

            return null;
        }

        return ['starts' => $data['starts'], 'lastLiveAt' => $data['lastLiveAt']];
    }

    /**
     * The `starts` to continue at `$now`, or null for a new session.
     *
     * @param-out string|null $problem
     */
    public function resumableStarts(int $now, int $resumeSeconds, ?string &$problem = null): ?int
    {
        $session = $this->read($problem);

        if ($session === null || $now - $session['lastLiveAt'] >= $resumeSeconds || $session['lastLiveAt'] > $now + 60) {
            return null;
        }

        return $session['starts'];
    }

    /**
     * Record an accepted `live` publish of the session that began at `$starts`.
     */
    public function recordLive(int $starts, int $publishedAt): void
    {
        File::ensureDirectoryExists(dirname($this->path));
        PlaylistWriter::writeAtomically($this->path, json_encode(['starts' => $starts, 'lastLiveAt' => $publishedAt], JSON_THROW_ON_ERROR)."\n");
    }

    public function clear(): void
    {
        File::delete($this->path);
    }
}
