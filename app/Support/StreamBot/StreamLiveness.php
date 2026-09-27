<?php

namespace App\Support\StreamBot;

use App\Support\TwentyOne\Stream\StreamSession;
use Carbon\CarbonInterface;

/**
 * Whether the 24/7 stream is live, from the two files its daemon keeps on
 * this host, without any relay read:
 *
 * - the session (StreamSession, `twentyone.stream.session_file`): written
 *   only after a relay accepted a `live` 30311 and cleared by
 *   `twentyone:stream:end`; its `lastLiveAt` must be younger than
 *   `esports.stream_bot.live_minutes` (the daemon republishes every 20 min);
 * - the public playlist (`hls_dir` + the file name of `public_url`): the
 *   daemon announces `live` only while it is fresh, so a dead encoder shows
 *   here within `esports.stream_bot.playlist_fresh_seconds`.
 *
 * Both missing, unreadable or old: not live (the bot stays silent).
 */
final class StreamLiveness
{
    /**
     * Why the stream does not count as live at `$now`, null when it does.
     */
    public function problem(CarbonInterface $now): ?string
    {
        $session = StreamSession::fromConfig()->read();

        if ($session === null) {
            return 'no live session';
        }

        $age = $now->getTimestamp() - $session['lastLiveAt'];

        if ($age > 60 * (int) config('esports.stream_bot.live_minutes', 30)) {
            return 'the last live 30311 is '.intdiv(max(0, $age), 60).' min old';
        }

        $playlist = self::playlistPath();
        clearstatcache(true, $playlist);
        $modifiedAt = @filemtime($playlist);

        if ($modifiedAt === false || $now->getTimestamp() - $modifiedAt > (int) config('esports.stream_bot.playlist_fresh_seconds', 60)) {
            return 'the stream playlist is not fresh';
        }

        return null;
    }

    public function isLive(CarbonInterface $now): bool
    {
        return $this->problem($now) === null;
    }

    public static function playlistPath(): string
    {
        $hlsDir = rtrim((string) config('twentyone.stream.hls_dir'), '/');

        return $hlsDir.'/'.basename((string) parse_url((string) config('twentyone.stream.public_url'), PHP_URL_PATH));
    }
}
