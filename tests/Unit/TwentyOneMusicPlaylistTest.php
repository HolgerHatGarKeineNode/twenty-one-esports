<?php

use App\Support\TwentyOne\Stream\MusicPlaylist;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * The shape of the real music folder: 14 titles with 2 versions, one with 4.
 *
 * @return list<string>
 */
function musicFiles(): array
{
    $files = ['/music/shitcoin-shuffle__v1.m4a', '/music/shitcoin-shuffle__v2.m4a', '/music/shitcoin-shuffle__v3.m4a', '/music/shitcoin-shuffle__v4.m4a'];

    foreach (range(1, 14) as $title) {
        $files[] = "/music/title-{$title}__v1.m4a";
        $files[] = "/music/title-{$title}__v2.m4a";
    }

    return $files;
}

test('no title plays twice in a row, across passes and the wrap, and every pass plays every file', function () {
    $files = musicFiles();
    sort($files);
    $violations = [];

    foreach (range(1, 200) as $seed) {
        $order = MusicPlaylist::order($files, 8, new Randomizer(new Mt19937($seed)));
        $titles = array_map(MusicPlaylist::title(...), $order);

        foreach ($titles as $index => $title) {
            if ($title === $titles[($index + 1) % count($titles)]) {
                $violations[] = "seed {$seed}: {$title} at {$index}";
            }
        }

        foreach (array_chunk($order, count($files)) as $number => $pass) {
            sort($pass);

            if ($pass !== $files) {
                $violations[] = "seed {$seed}: pass {$number} is not every file once";
            }
        }
    }

    expect($violations)->toBe([]);
});

test('a small set boxed in at the wrap is still ordered', function () {
    // Half the files share one title: every pass must alternate, and the list wraps.
    foreach (range(1, 50) as $seed) {
        $titles = array_map(MusicPlaylist::title(...), MusicPlaylist::order(['/m/a__v1.m4a', '/m/a__v2.m4a', '/m/b__v1.m4a', '/m/c__v1.m4a'], 8, new Randomizer(new Mt19937($seed))));

        foreach ($titles as $index => $title) {
            expect($title)->not->toBe($titles[($index + 1) % count($titles)]);
        }
    }
});

test('the order differs between seeds and passes', function () {
    $first = MusicPlaylist::order(musicFiles(), 2, new Randomizer(new Mt19937(1)));
    $second = MusicPlaylist::order(musicFiles(), 2, new Randomizer(new Mt19937(2)));

    expect($first)->not->toBe($second)
        ->and(array_slice($first, 0, 32))->not->toBe(array_slice($first, 32));
});

test('a set that cannot be separated is refused instead of looping forever', function () {
    MusicPlaylist::order(['/m/a__v1.m4a', '/m/a__v2.m4a', '/m/a__v3.m4a', '/m/b__v1.m4a'], 1);
})->throws(InvalidArgumentException::class);

test('the ffconcat list quotes every path for the concat demuxer', function () {
    expect(MusicPlaylist::ffconcat(['/music/a__v1.m4a', "/music/it's__v1.m4a"]))
        ->toBe("ffconcat version 1.0\nfile '/music/a__v1.m4a'\nfile '/music/it'\\''s__v1.m4a'\n")
        ->and(MusicPlaylist::title('/music/not-your-keys-not-your-coins-dnb-remix__v2.m4a'))->toBe('not-your-keys-not-your-coins-dnb-remix');
});
