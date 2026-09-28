<?php

use App\Support\TwentyOne\Stream\MusicPlaylist;
use App\Support\TwentyOne\Stream\MusicTimeline;
use Random\Engine\Mt19937;
use Random\Randomizer;

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/music-timeline-'.bin2hex(random_bytes(4));
    mkdir($this->dir.'/instrumental', 0777, true);
    $this->vocals = [];
    $this->instrumentals = [];

    foreach (range(1, 4) as $title) {
        foreach ([1, 2] as $version) {
            touch($this->vocals[] = "{$this->dir}/song-{$title}__v{$version}.m4a");
        }
    }

    foreach (range(1, 6) as $number) {
        touch($this->instrumentals[] = sprintf('%s/instrumental/%02d__v1.m4a', $this->dir, $number));
    }

    // Every file lasts 100 s; the probes are counted.
    $this->probes = 0;
    $this->timeline = fn (): MusicTimeline => new MusicTimeline($this->dir.'/timeline.json', function (): float {
        $this->probes++;

        return 100.0;
    });
});

afterEach(function () {
    exec('rm -rf '.escapeshellarg($this->dir));
});

test('a new encoder goes on with the playing track at its second, and ffprobe runs once per file', function () {
    $first = ($this->timeline)()->from($this->vocals, $this->instrumentals, 3, 1000.0, 1, new Randomizer(new Mt19937(1)));
    $again = ($this->timeline)()->from($this->vocals, $this->instrumentals, 3, 1000.0 + 250.5, 1, new Randomizer(new Mt19937(2)));

    expect($first['restarted'])->toBeTrue()
        ->and($first['inpoint'])->toBe(0.0)
        ->and($again['restarted'])->toBeFalse()
        // 250.5 s later: two tracks of 100 s played, the third is 50.5 s in.
        ->and($again['files'][0])->toBe($first['files'][2])
        ->and($again['inpoint'])->toBe(50.5)
        ->and(array_slice($again['files'], 0, 10))->toBe(array_slice($first['files'], 2, 10))
        ->and($this->probes)->toBe(count($this->vocals) + count($this->instrumentals))
        ->and(MusicPlaylist::ffconcat($again['files'], $again['inpoint']))->toStartWith("ffconcat version 1.0\nfile '{$again['files'][0]}'\ninpoint 50.500\nfile ");
});

test('a set plays every vocal once before any repeats, and the next set is a new shuffle that never repeats the last title', function () {
    $music = ($this->timeline)()->from($this->vocals, $this->instrumentals, 3, 0.0, 3, new Randomizer(new Mt19937(7)));
    // A set: 8 vocals, each followed by 3 instrumentals = 32 entries.
    $sets = array_chunk($music['files'], 32);
    $vocalsOf = fn (array $set): array => array_values(array_filter($set, fn (string $file): bool => ! str_contains($file, '/instrumental/')));

    expect(count($sets))->toBeGreaterThanOrEqual(3);

    foreach (array_slice($sets, 0, 3) as $index => $set) {
        $vocals = $vocalsOf($set);
        sort($vocals);
        $expected = $this->vocals;
        sort($expected);

        expect($vocals)->toBe($expected);

        if ($index > 0) {
            expect(MusicPlaylist::title($set[0]))->not->toBe(MusicPlaylist::title($sets[$index - 1][31]));
        }
    }

    expect($vocalsOf($sets[0]))->not->toBe($vocalsOf($sets[1]));
});

test('added music, a corrupt file or a stream that was off for longer than the timeline start a new timeline', function () {
    $timeline = ($this->timeline)();
    $timeline->from($this->vocals, $this->instrumentals, 3, 1000.0, 1);
    touch($this->instrumentals[] = $this->dir.'/instrumental/07__v1.m4a');

    $added = $timeline->from($this->vocals, $this->instrumentals, 3, 1010.0, 1);
    file_put_contents($this->dir.'/timeline.json', '{"anchor": 1');
    $corrupt = $timeline->from($this->vocals, $this->instrumentals, 3, 1020.0, 1);
    $later = $timeline->from($this->vocals, $this->instrumentals, 3, 1020.0 + 3 * 86400, 1);

    expect([$added['restarted'], $corrupt['restarted']])->toBe([true, true])
        ->and($added['inpoint'])->toBe(0.0)
        ->and($later['restarted'])->toBeFalse()
        ->and($later['inpoint'])->toBe(0.0);
});
