<?php

namespace App\Console\Commands;

use App\Support\TwentyOne\Stream\MusicCredits;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Draws the QR code of every music credit's target (MusicCredits) into resources/stream/qr/music, as the stream's
 * other codes are made (resources/stream/qr/README.md: qrencode, EC level M, no margin, modules #17120A on white),
 * and removes codes no credit uses any more. The credits come from the music catalog (MusicCatalog). Run after the
 * catalog changes, commit the files: the daemon never
 * encodes at render time (NowPlaying shows no card for a target without its code).
 */
#[Signature('twentyone:music-qr {--qrencode=qrencode : The qrencode binary}')]
#[Description('Draw the QR codes of the music artists the stream slides credit (resources/stream/qr/music)')]
class TwentyOneMusicQrCommand extends Command
{
    public function handle(): int
    {
        MusicCredits::flush();
        $dir = resource_path('stream/qr/music');
        File::ensureDirectoryExists($dir);
        $wanted = [];
        $drawn = 0;

        // The artists of the nightly Wavlake sync too: the server adds their tracks without drawing (plan "Wavlake-Sync").
        $synced = array_filter(array_map(MusicCredits::forArtist(...), array_values((array) config('twentyone.stream.music.wavlake_sync.artists', []))));

        foreach ([...MusicCredits::all(), MusicCredits::house(), ...$synced] as $credit) {
            $path = MusicCredits::qrFile($credit['target'], $credit['kind']);

            if (isset($wanted[$path])) {
                continue;
            }

            $wanted[$path] = true;

            if (is_file($path)) {
                continue;
            }

            $error = self::draw($credit['target'], $credit['kind'], is_string($this->option('qrencode')) ? $this->option('qrencode') : '');

            if ($error !== null) {
                $this->error('qrencode failed for '.$credit['artist'].': '.$error);

                return self::FAILURE;
            }

            $drawn++;
        }

        $removed = 0;

        foreach (File::files($dir) as $file) {
            if ($file->getExtension() === 'svg' && ! isset($wanted[$file->getPathname()])) {
                File::delete($file->getPathname());
                $removed++;
            }
        }

        $this->info(sprintf('Music QR codes: %d drawn, %d kept, %d removed.', $drawn, count($wanted) - $drawn, $removed));

        return self::SUCCESS;
    }

    /**
     * Draws the code of one target unless it is drawn already (twentyone:music-add uses it for a new song); the
     * error of qrencode, null on success.
     */
    public static function draw(string $target, string $kind, string $qrencode = 'qrencode'): ?string
    {
        $path = MusicCredits::qrFile($target, $kind);

        if (is_file($path)) {
            return null;
        }

        File::ensureDirectoryExists(dirname($path));
        $run = Process::run([$qrencode, '-t', 'SVG', '--svg-path', '-l', 'M', '-m', '0', '-s', '1',
            '--foreground=17120A', '--background=FFFFFF', '-o', $path, MusicCredits::qrPayload($target, $kind)]);

        return $run->failed() ? trim($run->errorOutput()) : null;
    }
}
