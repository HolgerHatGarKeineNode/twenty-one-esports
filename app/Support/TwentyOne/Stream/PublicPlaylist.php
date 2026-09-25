<?php

namespace App\Support\TwentyOne\Stream;

use Illuminate\Support\Facades\File;

/**
 * The public `stream.m3u8` on disk: reads an encoder's own playlist, lets
 * {@see PlaylistWriter} advance the window and writes playlist and state
 * atomically. Layout under hls_dir:
 *
 *   stream.m3u8              public playlist (served as /live/stream.m3u8)
 *   stream.m3u8.state.json   sequence numbers + window, survives restarts
 *   loop/, scene/            one directory per encoder, run-prefixed names
 */
final class PublicPlaylist
{
    private PlaylistState $state;

    /** Why the state was rebuilt on start ('missing' or 'unreadable'), null when it was read. */
    public readonly ?string $recoveredFrom;

    public function __construct(
        private string $hlsDir,
        private string $playlistName = 'stream.m3u8',
        private PlaylistWriter $writer = new PlaylistWriter,
    ) {
        $this->hlsDir = rtrim($hlsDir, '/');
        $statePath = $this->statePath();
        [$this->state, $this->recoveredFrom] = PlaylistState::recover(is_file($statePath) ? (string) file_get_contents($statePath) : null, time());
    }

    public function path(): string
    {
        return $this->hlsDir.'/'.$this->playlistName;
    }

    public function statePath(): string
    {
        return $this->path().'.state.json';
    }

    public function state(): PlaylistState
    {
        return $this->state;
    }

    /**
     * Take whatever the encoder in `$encoderDir` (relative to hls_dir) has
     * listed and publish it. Writes only when something changed, so the
     * playlist's mtime says when the stream last moved.
     */
    public function update(string $encoderDir): bool
    {
        $encoderPlaylist = $this->hlsDir.'/'.$encoderDir.'/'.FfmpegCommands::ENCODER_PLAYLIST;
        $available = is_file($encoderPlaylist)
            ? EncoderPlaylist::parse((string) @file_get_contents($encoderPlaylist), $encoderDir)
            : [];

        $next = $this->writer->advance($this->state, $available, fn (string $uri): bool => is_file($this->hlsDir.'/'.$uri));

        if ($next == $this->state && is_file($this->path()) === ($next->window !== [])) {
            return false;
        }

        $this->state = $next;

        if ($next->window === []) {
            // Nothing playable: no playlist rather than one pointing at gone files.
            File::delete($this->path());
        } else {
            PlaylistWriter::writeAtomically($this->path(), $this->writer->render($next));
        }

        PlaylistWriter::writeAtomically($this->statePath(), $next->toJson());

        return true;
    }

    /**
     * Delete what an encoder directory holds except the files the public
     * window still references and those of the run that is starting now.
     */
    public function prune(string $encoderDir, ?string $keepRunId = null): void
    {
        $referenced = [];

        foreach ($this->state->window as $segment) {
            $referenced[$this->hlsDir.'/'.$segment->uri] = true;
            $referenced[$this->hlsDir.'/'.$segment->initUri] = true;
        }

        foreach (File::glob($this->hlsDir.'/'.$encoderDir.'/*') as $file) {
            $name = basename($file);

            if (isset($referenced[$file]) || ($keepRunId !== null && str_starts_with($name, $keepRunId.'-'))) {
                continue;
            }

            File::delete($file);
        }
    }

    /**
     * The stream is over: remove the public playlist and the media, keep the
     * state so the next start continues the sequence numbers.
     */
    public function remove(string ...$encoderDirs): void
    {
        File::delete($this->path());

        foreach ($encoderDirs as $encoderDir) {
            File::delete(File::glob($this->hlsDir.'/'.$encoderDir.'/*'));
        }
    }
}
