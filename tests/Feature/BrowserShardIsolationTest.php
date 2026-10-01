<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/*
 * scripts/test-browser.sh runs the Browser suite as parallel `pest` processes
 * ("shards") with one in-memory SQLite and one Reverb each, but ONE filesystem.
 * Storage::fake() cleans a fixed directory (storage/framework/testing/disks/<disk>),
 * so a shard's beforeEach emptied the disk another shard had just written to:
 * ShareTest::beforeEach wiped the lobby end screen AoeLobbyTest had stored, and
 * the director's fetch of it came back 404 (AoeLobbyTest.php:262). The fix is
 * TEST_TOKEN per shard — Laravel keys the fake root by it. These tests hold the
 * mechanism (two "shards" in one process, a fresh disk name so no real fake disk
 * is touched) and the script that has to export it.
 */

beforeEach(function () {
    $this->disk = 'shard-guard-'.bin2hex(random_bytes(4));
    $this->hadToken = array_key_exists('TEST_TOKEN', $_SERVER);
    $this->token = $_SERVER['TEST_TOKEN'] ?? null;
});

afterEach(function () {
    if ($this->hadToken) {
        $_SERVER['TEST_TOKEN'] = $this->token;
    } else {
        unset($_SERVER['TEST_TOKEN']);
    }

    foreach (glob(storage_path('framework/testing/disks/'.$this->disk.'*')) ?: [] as $directory) {
        File::deleteDirectory($directory);
    }
});

/**
 * Shard A stores a file on a fake disk, then shard B fakes the same disk, as a
 * beforeEach does. The file is looked at on the filesystem, by the absolute path
 * shard A's disk gave out, so it is shard A's directory that is asked about.
 */
function shardWritesThenAnotherShardFakes(string $disk, ?string $tokenA, ?string $tokenB): bool
{
    $set = function (?string $token): void {
        if ($token === null) {
            unset($_SERVER['TEST_TOKEN']);
        } else {
            $_SERVER['TEST_TOKEN'] = $token;
        }
    };

    $set($tokenA);
    Storage::fake($disk)->put('lobby-results/1/end-screen.webp', 'the end screen');
    $path = Storage::disk($disk)->path('lobby-results/1/end-screen.webp');

    $set($tokenB);
    Storage::fake($disk);

    return is_file($path);
}

it('loses shard A\'s file when shard B fakes the same disk and no shard has a token (the 404 of AoeLobbyTest)', function () {
    // Negative control: the shared state this guard exists for. If Laravel ever
    // isolates fakes on its own, this turns red and the whole guard can go.
    expect(shardWritesThenAnotherShardFakes($this->disk, null, null))->toBeFalse();
});

it('keeps shard A\'s file when shard B fakes the same disk under its own token', function () {
    expect(shardWritesThenAnotherShardFakes($this->disk, 'browser-shard-4', 'browser-shard-2'))->toBeTrue();
});

it('still shares the fake between two tests of the SAME shard, so a test sees what its own beforeEach reset', function () {
    // Same token = same directory: this is what makes the second fake a reset
    // inside one shard, as every beforeEach in the suite expects.
    expect(shardWritesThenAnotherShardFakes($this->disk, 'browser-shard-4', 'browser-shard-4'))->toBeFalse();
});

it('exports a TEST_TOKEN made from the shard index in every shard of scripts/test-browser.sh', function () {
    $script = (string) file_get_contents(base_path('scripts/test-browser.sh'));

    // The body of run_shard, so an export elsewhere in the file cannot satisfy this.
    expect(preg_match('/^run_shard\(\) \{\n(.*?)^\}$/ms', $script, $function))->toBe(1)
        ->and($function[1])->toMatch('/^\s*export TEST_TOKEN="[^"$]*\$idx[^"]*"\s*$/m');

    // And it is the shard's own index the loop hands in, not a constant.
    expect($script)->toMatch('/\(run_shard "\$idx" /');
});
