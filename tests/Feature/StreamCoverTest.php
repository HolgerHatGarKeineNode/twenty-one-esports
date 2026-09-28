<?php

use App\Support\TwentyOne\Stream\SceneRenderer;
use App\Support\TwentyOne\Stream\StreamCover;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->dir = storage_path('framework/testing/cover-'.bin2hex(random_bytes(4)));
    File::ensureDirectoryExists($this->dir);
    config(['twentyone.stream.cover.path' => $this->dir.'/cover.png']);
});

afterEach(function () {
    File::deleteDirectory($this->dir);
});

/**
 * A StreamCover whose render "binary" keeps every SVG in `svgs` and answers
 * with a PNG signature plus the SVG's hash (a picture per slide); `$fail`
 * makes it fail instead.
 */
function coverWithRenderer(string $dir, bool $fail = false): StreamCover
{
    $body = $fail ? "echo broken >&2\nexit 1\n" : "svg=$(cat)\nprintf %s \"\$svg\" >> {$dir}/svgs\nprintf '\\211PNG'\nprintf %s \"\$svg\" | md5sum\n";
    File::put($dir.'/rsvg-convert', "#!/bin/sh\n".$body);
    chmod($dir.'/rsvg-convert', 0755);

    return new StreamCover(new SceneRenderer($dir.'/rsvg-convert', resource_path('fonts/stream'), $dir.'/work'), $dir.'/cover.png', 'https://example.test/stream/cover.png', 900);
}

/**
 * The scene data a slide gets: a tournament for the hero, stats for the teasers.
 *
 * @return Closure(string, int|null): array<string, mixed>
 */
function coverData(): Closure
{
    return fn (string $scene, ?int $tournamentId): array => $tournamentId === null
        ? ['stats' => [], 'backdrop' => null, 'viewers' => null]
        : ['tournament' => ['id' => $tournamentId, 'name' => 'Zero Ball Control', 'countdown' => '23:59:59', 'countdownLabel' => 'Sign-up closes in'], 'stats' => [], 'backdrop' => null, 'viewers' => null];
}

test('the cover slides are every open tournament\'s hero, then the pot, cup, join and ladder teasers', function () {
    $teasers = [
        ['scene' => 'd1', 'tournamentId' => null],
        ['scene' => 'd2', 'tournamentId' => null],
        ['scene' => 'a4', 'tournamentId' => null],
        ['scene' => 'a3', 'tournamentId' => null],
    ];

    expect(StreamCover::slides([7, 3]))->toBe([['scene' => 'ta1', 'tournamentId' => 7], ['scene' => 'ta1', 'tournamentId' => 3], ...$teasers])
        ->and(StreamCover::slides([]))->toBe($teasers);
});

test('each slot renders the next slide into the one cover file, with its hash in the URL', function () {
    $cover = coverWithRenderer($this->dir);

    expect($cover->image())->toBeNull()->and($cover->due(0))->toBeTrue();

    $first = $cover->advance(1000, [7], coverData());
    $firstImage = $cover->image();
    $firstPng = File::get($this->dir.'/cover.png');

    expect($first)->toBe('ta1 tournament 7')
        ->and($firstImage)->toBe('https://example.test/stream/cover.png?v='.substr(hash('sha256', $firstPng), 0, 16))
        // A still cannot tick: the hero says the sign-up is open instead of a frozen countdown.
        ->and(File::get($this->dir.'/svgs'))->toContain('Open now')->not->toContain('23:59:59')
        ->and($cover->due(1000 + 899))->toBeFalse()
        ->and($cover->due(1000 + 900))->toBeTrue();

    expect($cover->advance(1900, [7], coverData()))->toBe('d1')
        ->and($cover->image())->not->toBe($firstImage)
        ->and($cover->advance(2800, [7], coverData()))->toBe('d2')
        ->and($cover->advance(3700, [7], coverData()))->toBe('a4')
        ->and($cover->advance(4600, [7], coverData()))->toBe('a3')
        ->and($cover->advance(5500, [7], coverData()))->toBe('ta1 tournament 7')
        // One file, overwritten in place: nothing piles up.
        ->and(File::glob($this->dir.'/*.png'))->toBe([$this->dir.'/cover.png']);
});

test('a cover that fails to render keeps the last one and is tried again a minute later', function () {
    coverWithRenderer($this->dir)->advance(1000, [], coverData());
    $cover = coverWithRenderer($this->dir, fail: true);

    expect(fn () => $cover->advance(2000, [], coverData()))->toThrow(RuntimeException::class)
        ->and($cover->image())->toBeNull()
        ->and($cover->due(2059))->toBeFalse()
        ->and($cover->due(2060))->toBeTrue()
        ->and(File::exists($this->dir.'/cover.png'))->toBeTrue();
});

test('the cover route serves the file as a PNG, and 404 before the first cover', function () {
    $this->get(route('stream.cover'))->assertNotFound();

    File::put($this->dir.'/cover.png', "\x89PNGcover");

    $response = $this->get(route('stream.cover', ['v' => 'abc']));

    $response->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($response->headers->get('Cache-Control'))->toContain('max-age=86400')
        ->and($response->getFile()->getContent())->toBe("\x89PNGcover");
});
