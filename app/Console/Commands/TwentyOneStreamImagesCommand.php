<?php

namespace App\Console\Commands;

use App\Support\TwentyOne\Stream\StreamImageBuilder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('twentyone:stream:images')]
#[Description('Cache the avatars and clan logos and build the backdrops the stream scenes embed (StreamImageBuilder)')]
class TwentyOneStreamImagesCommand extends Command
{
    /**
     * Execute the console command. One player's failure is logged and
     * counted, never fatal; the backdrops are built here too (and not at
     * daemon start), so the daemon stays a reader of finished files.
     */
    public function handle(StreamImageBuilder $builder): int
    {
        // GD holds a decoded picture of up to 4096x4096 (64 MB) at once.
        if ($this->bytes((string) ini_get('memory_limit')) < 256 * 1024 * 1024) {
            ini_set('memory_limit', '256M');
        }

        $backdrops = $builder->buildBackdrops();
        $logos = $builder->refreshLogos();
        $avatars = $builder->refreshAvatars();

        $this->info(sprintf(
            'Backdrops: %d built, %d fresh, %d failed. Clan logos: %d built, %d fresh, %d failed, %d old file(s) removed. Avatars: %d fetched, %d fresh, %d waiting after a failure, %d failed, %d without a picture, %d old file(s) removed.',
            $backdrops['built'], $backdrops['fresh'], $backdrops['failed'],
            $logos['built'], $logos['fresh'], $logos['failed'], $logos['removed'],
            $avatars['fetched'], $avatars['fresh'], $avatars['waiting'], $avatars['failed'], $avatars['none'], $avatars['removed'],
        ));

        foreach ($builder->avatarFailures() as $host => $failure) {
            $this->warn(sprintf('Avatars failing from %s: %d player(s), last: %s', $host, $failure['players'], $failure['reason']));
        }

        return self::SUCCESS;
    }

    /**
     * A php.ini size ("128M", "-1") in bytes; unlimited counts as enough.
     */
    private function bytes(string $size): int
    {
        if ($size === '' || $size === '-1') {
            return PHP_INT_MAX;
        }

        $number = (int) $size;

        return match (strtolower(substr($size, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
