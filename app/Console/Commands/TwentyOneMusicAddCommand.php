<?php

namespace App\Console\Commands;

use App\Support\TwentyOne\Stream\AudioProbe;
use App\Support\TwentyOne\Stream\Chromaprint;
use App\Support\TwentyOne\Stream\LoudnessNormalizer;
use App\Support\TwentyOne\Stream\MusicCatalog;
use App\Support\TwentyOne\Stream\MusicCredits;
use App\Support\TwentyOne\Stream\MusicPlaylist;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Adds a new song to the music library and the catalog (plan "Musik-Katalog", P3): refuses a file that cannot be read
 * as audio, a song the catalog already holds (Chromaprint, Chromaprint::SAME_RECORDING) and a banned artist
 * (twentyone.stream.music.banned_artists); brings it to the library's loudness (LoudnessNormalizer) straight into the
 * music folder (the catalog knows artists, not folders), enters it with its credit and draws the credit's QR code.
 *
 * The name stays the user's (user: never rename music files); only the ending becomes .m4a, and a name without a
 * version gets `__v1`, because the stream plays `*__v*.m4a` only (TwentyOneStreamCommand::musicFiles()). Without
 * --artist the song is entered with credit_source `none`: no card, never the house artist in silence. Commit
 * catalog.json, fingerprints.json and the new code afterwards.
 *
 * With --overlay (the nightly Wavlake sync on the server) the song goes into the overlay catalog instead, the
 * duplicate check still covering both, and nothing is written into the repo tree: a QR code is only used when it is
 * drawn already (every listed artist has one), a missing one leaves the card off with a warning.
 */
#[Signature('twentyone:music-add
    {file : The song to add (any format ffmpeg reads)}
    {--artist= : The artist, by name or catalog id; a new name is added to the catalog}
    {--title= : The title (default: the file name)}
    {--npub= : A new artist\'s npub}
    {--lud16= : A new artist\'s lightning address}
    {--dir= : The music folder (default: twentyone.stream.music.dir)}
    {--overlay : Enter it in the overlay catalog under storage (twentyone:music-wavlake-sync), not the repo catalog}
    {--wavlake-id= : The Wavlake track id it came from, kept with the track}')]
#[Description('Add a song to the music library: check, normalise to -15 LUFS / -1.5 dBTP, enter it in the catalog with its credit, draw the QR')]
class TwentyOneMusicAddCommand extends Command
{
    public function handle(AudioProbe $probe, LoudnessNormalizer $normalizer): int
    {
        // The duplicate check votes over every fingerprint of the library; past ~440 tracks it outgrows the CLI
        // default of 128 MB (measured 2026-10-16), as twentyone:music-duplicates does.
        $limit = (string) ini_get('memory_limit');
        $bytes = (int) $limit * match (strtoupper(substr($limit, -1))) {
            'G' => 1024 ** 3, 'M' => 1024 ** 2, 'K' => 1024, default => 1
        };

        if ($limit !== '-1' && $bytes < 1024 ** 3) {
            ini_set('memory_limit', '1G');
        }

        $file = is_string($this->argument('file')) ? $this->argument('file') : '';
        $dir = rtrim((string) ($this->option('dir') ?: config('twentyone.stream.music.dir')), '/');
        $artist = is_string($this->option('artist')) ? trim($this->option('artist')) : '';

        if ($dir === '' || ! is_dir($dir)) {
            return $this->refuse('No music folder at '.$dir);
        }

        if (! is_file($file) || ! is_readable($file)) {
            return $this->refuse('Cannot read '.$file);
        }

        foreach (array_filter(array_map('trim', explode(',', (string) config('twentyone.stream.music.banned_artists', '')))) as $banned) {
            if (str_contains(mb_strtolower(basename($file).' '.$artist), mb_strtolower($banned))) {
                return $this->refuse('Banned artist: '.$banned);
            }
        }

        $measured = $probe->measure(['in' => $file], loudness: false)['in'] ?? null;

        if ($measured === null) {
            return $this->refuse('Not readable as audio: '.$file);
        }

        MusicCredits::flush();
        $duplicate = self::duplicateOf($measured['fingerprint']);

        if ($duplicate !== null) {
            return $this->refuse(sprintf('Already in the library: %s (similarity %.3f)', $duplicate['file'], $duplicate['similarity']));
        }

        $name = self::libraryName($file);
        $relative = $name;
        $target = $dir.'/'.$relative;

        if (file_exists($target)) {
            return $this->refuse('A file of that name exists already: '.$relative);
        }

        try {
            $loudness = $normalizer->normalize($file, $target);
        } catch (RuntimeException $e) {
            return $this->refuse($e->getMessage());
        }

        $result = $probe->measure(['out' => $target])['out'] ?? null;

        if ($result === null) {
            @unlink($target);

            return $this->refuse('The normalised file cannot be read back; nothing added.');
        }

        $overlay = (bool) $this->option('overlay');
        ['tracks' => $tracks, 'artists' => $artists] = $overlay
            ? MusicCatalog::overlay()
            : ['tracks' => MusicCatalog::tracks(), 'artists' => MusicCatalog::artists()];
        $prints = [];

        foreach ($tracks as $track) {
            $prints[(string) $track['id']] = MusicCatalog::fingerprints()[(string) $track['id']] ?? [];
        }

        $artistIds = [];

        if ($artist !== '') {
            $id = MusicCatalog::artistId($artist) ?? MusicCatalog::slug($artist);
            // In the overlay only an artist the repo catalog does not know yet is entered.
            $known = MusicCatalog::artists()[$id] ?? null;
            $artists[$id] ??= $known !== null && $overlay ? null : array_filter(['name' => $artist, 'npub' => $this->option('npub'), 'lud16' => $this->option('lud16')], fn (mixed $value): bool => is_string($value) && $value !== '');
            $artistIds = [$id];
        }

        // Taken across both catalogs: an overlay id never repeats one of the repo's.
        $taken = array_fill_keys([...array_keys($prints), ...array_map(fn (array $track): string => (string) $track['id'], MusicCatalog::tracks())], true);
        $id = MusicCatalog::newId($relative, $taken);
        $title = $this->option('title');
        $wavlakeId = $this->option('wavlake-id');
        $tracks[$relative] = [
            'id' => $id,
            'artists' => $artistIds,
            'title' => is_string($title) && $title ? $title : MusicPlaylist::title($name),
            'duration' => $result['duration'],
            'bitrate' => $result['bitrate'],
            'lufs' => $result['lufs'],
            'true_peak' => $result['true_peak'],
            'fingerprint' => Chromaprint::hash($result['fingerprint']),
            'sha1' => (string) sha1_file($target),
            'size' => (int) filesize($target),
            'mtime' => (int) filemtime($target),
            'credit_source' => $artistIds === [] ? 'none' : 'manual',
            ...(is_string($wavlakeId) && $wavlakeId ? ['wavlake_track_id' => $wavlakeId] : []),
        ];
        $prints[$id] = $result['fingerprint'];
        $artists = array_filter($artists, 'is_array');

        $overlay ? MusicCatalog::writeOverlay($tracks, $artists, $prints) : MusicCatalog::write($tracks, $artists, $prints);
        MusicCredits::flush();

        $this->info(sprintf('Added %s: %.1f LUFS, true peak %.1f dBTP (limiter %.1f dB), %.1f s.', $relative, $result['lufs'] ?? $loudness['lufs'], $result['true_peak'] ?? $loudness['true_peak'], $loudness['limit'], $result['duration']));

        $credit = MusicCredits::forFile($relative);

        if ($credit === null) {
            $without = count(array_filter(array_keys(MusicCatalog::tracks()), fn (string $file): bool => MusicCredits::forFile($file) === null));
            $this->warn(sprintf('No credit (no artist, or an artist without npub, lud16 or link): no card on air. %d tracks without credit in the catalog.', $without));

            return self::SUCCESS;
        }

        if ($overlay && ! is_file(MusicCredits::qrFile($credit['target'], $credit['kind']))) {
            $this->warn(sprintf('Credit: %s, but no QR code drawn for %s %s: no card on air until twentyone:music-qr runs in the repo.', $credit['artist'], $credit['kind'], $credit['target']));

            return self::SUCCESS;
        }

        $error = TwentyOneMusicQrCommand::draw($credit['target'], $credit['kind']);

        if ($error !== null) {
            $this->error('The song is added, but its QR code failed: '.$error.' Run php artisan twentyone:music-qr.');

            return self::FAILURE;
        }

        $this->info(sprintf('Credit: %s, %s %s; QR %s.', $credit['artist'], $credit['kind'], $credit['target'], basename(MusicCredits::qrFile($credit['target'], $credit['kind']))));

        return self::SUCCESS;
    }

    /**
     * The library name of an upload: the user's name with the ending .m4a, `__v1` added when it carries no version.
     */
    public static function libraryName(string $file): string
    {
        $stem = pathinfo($file, PATHINFO_FILENAME);

        return (preg_match('/__v\d+$/', $stem) === 1 ? $stem : $stem.'__v1').'.m4a';
    }

    /**
     * The catalog track the fingerprint matches, null when none does.
     *
     * @param  list<int>  $fingerprint
     * @return array{file: string, similarity: float}|null
     */
    private static function duplicateOf(array $fingerprint): ?array
    {
        $files = [];
        $prints = ["\0new" => $fingerprint];

        foreach (MusicCatalog::tracks() as $file => $track) {
            $items = MusicCatalog::fingerprints()[(string) $track['id']] ?? null;

            if ($items !== null) {
                $prints[(string) $track['id']] = $items;
                $files[(string) $track['id']] = $file;
            }
        }

        $best = null;

        foreach (Chromaprint::candidates($prints, 4) as $candidate) {
            if ($candidate[0] !== "\0new" && $candidate[1] !== "\0new") {
                continue;
            }

            $other = $candidate[0] === "\0new" ? $candidate[1] : $candidate[0];
            $match = Chromaprint::compare($prints[$candidate[0]], $prints[$candidate[1]], 3, $candidate['offset']);

            if ($match['similarity'] >= Chromaprint::SAME_RECORDING && ($best === null || $match['similarity'] > $best['similarity'])) {
                $best = ['file' => $files[$other], 'similarity' => $match['similarity']];
            }
        }

        return $best;
    }

    private function refuse(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
