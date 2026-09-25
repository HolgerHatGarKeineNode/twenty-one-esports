<?php

use App\Support\TwentyOne\Stream\EncoderPlaylist;
use App\Support\TwentyOne\Stream\HlsSegment;
use App\Support\TwentyOne\Stream\PlaylistState;
use App\Support\TwentyOne\Stream\PlaylistWriter;

/**
 * Segments `$from`…`$to` of one encoder run, as EncoderPlaylist yields them.
 *
 * @return list<HlsSegment>
 */
function run(string $dir, string $runId, int $from, int $to): array
{
    return array_map(
        fn (int $n): HlsSegment => new HlsSegment(sprintf('%s/%s-seg-%09d.m4s', $dir, $runId, $n), 6.0, "{$dir}/{$runId}-init.mp4", $runId),
        range($from, $to),
    );
}

/**
 * Feed the writer tick by tick: each tick sees the encoder's current list.
 *
 * @param  list<list<HlsSegment>>  $ticks
 */
function publishTicks(array $ticks, PlaylistState $state = new PlaylistState, ?Closure $exists = null): PlaylistState
{
    foreach ($ticks as $available) {
        $state = (new PlaylistWriter)->advance($state, $available, $exists ?? fn (): bool => true);
    }

    return $state;
}

/**
 * @return list<string>
 */
function playlistLines(PlaylistState $state): array
{
    return explode("\n", trim((new PlaylistWriter)->render($state)));
}

test('the playlist has a constant header and a map at the start and after every discontinuity', function () {
    // loop → scene → loop, each run 2 segments, window 6: all in the window.
    $state = publishTicks([run('loop', 'aaa', 0, 1), run('scene', 'bbb', 0, 1), run('loop', 'ccc', 0, 1)]);

    expect(playlistLines($state))->toBe([
        '#EXTM3U',
        '#EXT-X-VERSION:7',
        '#EXT-X-TARGETDURATION:6',
        '#EXT-X-MEDIA-SEQUENCE:0',
        '#EXT-X-DISCONTINUITY-SEQUENCE:0',
        '#EXT-X-INDEPENDENT-SEGMENTS',
        '#EXT-X-MAP:URI="loop/aaa-init.mp4"',
        '#EXTINF:6.000000,', 'loop/aaa-seg-000000000.m4s',
        '#EXTINF:6.000000,', 'loop/aaa-seg-000000001.m4s',
        '#EXT-X-DISCONTINUITY',
        '#EXT-X-MAP:URI="scene/bbb-init.mp4"',
        '#EXTINF:6.000000,', 'scene/bbb-seg-000000000.m4s',
        '#EXTINF:6.000000,', 'scene/bbb-seg-000000001.m4s',
        '#EXT-X-DISCONTINUITY',
        '#EXT-X-MAP:URI="loop/ccc-init.mp4"',
        '#EXTINF:6.000000,', 'loop/ccc-seg-000000000.m4s',
        '#EXTINF:6.000000,', 'loop/ccc-seg-000000001.m4s',
    ]);
});

test('the window slides by six and the discontinuity sequence counts the tags that left it', function () {
    // aaa 0-3, then bbb 0-1 (1 tag), then ccc 0-6 (1 tag): 13 segments, 2 tags.
    $ticks = [...array_map(fn (int $n) => run('loop', 'aaa', 0, $n), range(0, 3)), run('scene', 'bbb', 0, 1), ...array_map(fn (int $n) => run('loop', 'ccc', 0, $n), range(0, 6))];
    $sequences = [];
    $state = new PlaylistState;

    foreach ($ticks as $available) {
        $state = publishTicks([$available], $state);
        $sequences[] = [$state->mediaSequence, $state->discontinuitySequence, count($state->window)];
    }

    expect($sequences)->toBe([
        [0, 0, 1], [0, 0, 2], [0, 0, 3], [0, 0, 4],
        [0, 0, 6],                                    // bbb 0-1 arrive together
        [1, 0, 6], [2, 0, 6], [3, 0, 6], [4, 0, 6],   // aaa leaves, no tag on it
        [5, 1, 6],                                    // bbb 0 (with its tag) leaves
        [6, 1, 6],
        [7, 2, 6],                                    // ccc 0 (with its tag) leaves
    ])
        ->and(playlistLines($state))->toContain('#EXT-X-MEDIA-SEQUENCE:7', '#EXT-X-DISCONTINUITY-SEQUENCE:2')
        ->and(array_count_values(playlistLines($state))['#EXT-X-MAP:URI="loop/ccc-init.mp4"'])->toBe(1);
});

test('a restart from the persisted state keeps counting and starts with a discontinuity', function () {
    $before = publishTicks([run('loop', 'aaa', 0, 7)]);
    // The daemon is down; its state went through JSON. A new run, and the old files are gone.
    $restored = PlaylistState::fromJson($before->toJson());
    $after = publishTicks([run('loop', 'bbb', 0, 1)], $restored, fn (string $uri): bool => str_contains($uri, 'bbb'));

    expect($restored)->toEqual($before)
        ->and($before->mediaSequence)->toBe(2)
        ->and($after->mediaSequence)->toBe(8)
        ->and($after->discontinuitySequence)->toBe(0)
        ->and(playlistLines($after))->toContain('#EXT-X-DISCONTINUITY')
        ->and(array_map(fn (HlsSegment $segment): string => $segment->runId, $after->window))->toBe(['bbb', 'bbb']);
});

test('a new run after the window emptied still starts with a discontinuity', function () {
    // On restart the old files are gone before the new encoder lists anything.
    $emptied = publishTicks([[]], publishTicks([run('loop', 'aaa', 0, 2)]), fn (): bool => false);
    $after = publishTicks([run('loop', 'bbb', 0, 0)], $emptied);

    expect($emptied->window)->toBe([])
        ->and($after->window[0]->discontinuityBefore)->toBeTrue()
        ->and(playlistLines($after))->toContain('#EXT-X-DISCONTINUITY');
});

test('the window never references a missing file', function () {
    $present = array_flip([
        'loop/aaa-init.mp4', 'loop/aaa-seg-000000004.m4s', 'loop/aaa-seg-000000005.m4s', 'loop/aaa-seg-000000006.m4s',
    ]);
    // Segment 3 is gone although 4-6 are there: 0-3 must all leave, from the front.
    $state = publishTicks([run('loop', 'aaa', 0, 6)], exists: fn (string $uri): bool => isset($present[$uri]));

    expect(array_map(fn (HlsSegment $segment): string => $segment->uri, $state->window))
        ->toBe(['loop/aaa-seg-000000004.m4s', 'loop/aaa-seg-000000005.m4s', 'loop/aaa-seg-000000006.m4s'])
        ->and($state->mediaSequence)->toBe(4);
});

test('segments the encoder still lists are not published twice', function () {
    // Tick 2 sees 0-3 again (the encoder keeps 9); only 3 is new.
    $state = publishTicks([run('loop', 'aaa', 0, 2), run('loop', 'aaa', 0, 3), run('loop', 'aaa', 1, 3)]);

    expect(array_map(fn (HlsSegment $segment): string => basename($segment->uri), $state->window))
        ->toBe(['aaa-seg-000000000.m4s', 'aaa-seg-000000001.m4s', 'aaa-seg-000000002.m4s', 'aaa-seg-000000003.m4s']);
});

test('the encoder playlist is read with its run id, durations and init', function () {
    $text = "#EXTM3U\n#EXT-X-VERSION:7\n#EXT-X-TARGETDURATION:6\n#EXT-X-MEDIA-SEQUENCE:4\n#EXT-X-MAP:URI=\"1790000000abcdef-init.mp4\"\n"
        ."#EXTINF:6.023242,\n1790000000abcdef-seg-000000004.m4s\n#EXTINF:2.5,\n1790000000abcdef-seg-000000005.m4s\n";

    expect(EncoderPlaylist::parse($text, 'loop/'))->toEqual([
        new HlsSegment('loop/1790000000abcdef-seg-000000004.m4s', 6.023242, 'loop/1790000000abcdef-init.mp4', '1790000000abcdef'),
        new HlsSegment('loop/1790000000abcdef-seg-000000005.m4s', 2.5, 'loop/1790000000abcdef-init.mp4', '1790000000abcdef'),
    ]);
});
