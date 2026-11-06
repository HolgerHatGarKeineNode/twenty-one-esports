<?php

namespace App\Console\Commands;

use App\Support\TwentyOne\Stream\LoudnessNormalizer;
use App\Support\TwentyOne\Stream\MusicCatalog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Re-makes library songs from their originals with the chain of twentyone:music-add (LoudnessNormalizer), keeping
 * each file's name (plan "Musik-Katalog", user 2026-10-16: "Die 68 Songs über −1 dBTP werden mit P3 aus den Originalen
 * (~/music-prenorm/) neu erzeugt, mit Limiter auf −1,5 dBTP"). Without files it takes every catalog track whose true
 * peak is above --over.
 *
 * The original of `instrumental/12__v1.m4a` is looked up under --from as the same relative path, then as the same
 * name anywhere below it, then as the same name with any ending; none or more than one, the song is skipped. Each
 * result replaces the library file only once it passed the true-peak check. The catalog is not written here (on prod
 * it is a tracked file): run twentyone:music-catalog where the catalog is committed afterwards.
 */
#[Signature('twentyone:music-renormalize
    {files?* : Library files relative to the music folder (default: every catalog track above --over)}
    {--from= : The folder of the originals}
    {--over=-1.0 : Without files: the true peak (dBTP) above which a track is re-made}
    {--dir= : The music folder (default: twentyone.stream.music.dir)}
    {--dry-run : Only list each song and the original found for it}')]
#[Description('Re-make library songs from their originals at -15 LUFS / -1.5 dBTP, keeping the names')]
class TwentyOneMusicRenormalizeCommand extends Command
{
    public function handle(LoudnessNormalizer $normalizer): int
    {
        $dir = rtrim((string) ($this->option('dir') ?: config('twentyone.stream.music.dir')), '/');
        $from = is_string($this->option('from')) ? rtrim($this->option('from'), '/') : '';

        if ($from === '' || ! is_dir($from)) {
            $this->error('No folder of originals at '.$from.' (--from=)');

            return self::FAILURE;
        }

        /** @var list<string> $files */
        $files = (array) $this->argument('files');

        if ($files === []) {
            $over = (float) $this->option('over');
            $files = array_keys(array_filter(MusicCatalog::tracks(), fn (array $track): bool => is_numeric($track['true_peak'] ?? null) && (float) $track['true_peak'] > $over));
        }

        $originals = self::originals($from);
        $failed = 0;
        $done = 0;

        foreach ($files as $relative) {
            $target = $dir.'/'.$relative;
            $original = self::originalOf($relative, $from, $originals);

            if (! is_file($target) || $original === null) {
                $this->warn(sprintf('skip  %s (%s)', $relative, ! is_file($target) ? 'not in the library' : 'no single original under '.$from));
                $failed++;

                continue;
            }

            if ($this->option('dry-run')) {
                $this->line(sprintf('would %s <- %s', $relative, $original));

                continue;
            }

            try {
                $result = $normalizer->normalize($original, $target);
                $done++;
                $this->line(sprintf('done  %s: %.1f LUFS, true peak %.1f dBTP (limiter %.1f dB)', $relative, $result['lufs'] ?? NAN, $result['true_peak'] ?? NAN, $result['limit']));
            } catch (RuntimeException $e) {
                $failed++;
                $this->error('fail  '.$relative.': '.$e->getMessage());
            }
        }

        $this->info(sprintf('%d re-made, %d skipped or failed of %d.', $done, $failed, count($files)));

        if ($done > 0) {
            $this->line('Next: run php artisan twentyone:music-catalog where the catalog is committed, so it carries the new measurements.');
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Every file under the originals folder by its path relative to it.
     *
     * @return list<string>
     */
    private static function originals(string $from): array
    {
        $files = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $files[] = substr($file->getPathname(), strlen($from) + 1);
            }
        }

        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * @param  list<string>  $originals
     */
    private static function originalOf(string $relative, string $from, array $originals): ?string
    {
        if (is_file($from.'/'.$relative)) {
            return $from.'/'.$relative;
        }

        $name = basename($relative);
        $stem = pathinfo($name, PATHINFO_FILENAME);

        foreach ([fn (string $file): bool => basename($file) === $name, fn (string $file): bool => pathinfo($file, PATHINFO_FILENAME) === $stem] as $matches) {
            $found = array_values(array_filter($originals, $matches));

            if (count($found) === 1) {
                return $from.'/'.$found[0];
            }

            if (count($found) > 1) {
                return null;
            }
        }

        return null;
    }
}
