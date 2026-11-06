<?php

namespace App\Console\Commands;

use App\Support\Caster\Programme\MusicTeasers;
use App\Support\TwentyOne\Stream\MusicCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Warms the last-note cache of the music announcements (MusicTeasers::lastNote) for every catalog artist with a
 * Nostr key, hourly: the answering path (caster:music-teaser) reads the cache only — a relay read hung the minute
 * lane behind it once, live 2026-10-17, and the 16:00 Sport und Wetter never aired. This lane may hang on a relay
 * for all it costs: its own overlap lock and nobody else's airtime. Notes stay cached for six hours
 * (MusicTeasers::NOTE_CACHE_MINUTES), a nothing included.
 */
#[Signature('caster:music-notes')]
#[Description('Warm the artists\' last Nostr notes for the music announcements (cache only on the answering path)')]
class CasterMusicNotesCommand extends Command
{
    public function handle(): int
    {
        $teasers = app(MusicTeasers::class);
        $now = CarbonImmutable::now();
        $warmed = 0;

        foreach (MusicCatalog::artists() as $artist) {
            if (! is_string($artist['name'] ?? null) || ! is_string($artist['npub'] ?? null) || $artist['npub'] === '') {
                continue;
            }

            $teasers->lastNote(['artist' => $artist['name'], 'npub' => $artist['npub']], $now);
            $warmed++;
        }

        if ($this->output->isVerbose()) {
            $this->line('artists warmed: '.$warmed);
        }

        return self::SUCCESS;
    }
}
