<?php

use App\Support\TwentyOne\Stream\AudioProbe;
use App\Support\TwentyOne\Stream\LoudnessNormalizer;
use App\Support\TwentyOne\Stream\MusicCatalog;
use App\Support\TwentyOne\Stream\MusicCredits;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/*
 * The music catalog (plan "Musik-Katalog", user 2026-10-16): one JSON with every track and artist, the credits read
 * from it, duplicates found by Chromaprint, new songs added normalised. The command tests run the real ffmpeg and
 * fpcalc on a few seconds of generated audio.
 */

/**
 * Fifteen seconds of a stepped two-tone melody; another `$seed` is another song (Chromaprint similarity about 0.66),
 * the same seed at another bitrate the same recording (about 0.998).
 */
function catalogFixture(string $path, int $seed, string $bitrate = '128k', float $volume = 1.0): void
{
    File::ensureDirectoryExists(dirname($path));
    $source = "aevalsrc='0.5*sin(2*PI*(200+97*mod(floor(t*{$seed}),9))*t)*sin(PI*t*{$seed})+0.3*sin(2*PI*(500+61*mod(floor(t*{$seed}*1.7),7))*t)':s=44100:d=15";
    Process::run(['ffmpeg', '-y', '-v', 'error', '-f', 'lavfi', '-i', $source, '-filter:a', 'volume='.$volume, '-ac', '2', '-c:a', 'aac', '-b:a', $bitrate, $path])->throw();
}

beforeEach(function () {
    $this->root = storage_path('framework/testing/music-catalog-'.getmypid());
    File::deleteDirectory($this->root);
    File::ensureDirectoryExists($this->root.'/music');
    config(['twentyone.stream.music.catalog' => $this->root.'/data/catalog.json']);
    MusicCredits::flush();
});

afterEach(function () {
    File::deleteDirectory($this->root);
    MusicCredits::flush();
});

test('the catalog credits every file of the old indexes exactly as the indexes did', function () {
    config(['twentyone.stream.music.catalog' => resource_path('data/music/catalog.json')]);
    MusicCredits::flush();

    // The credits as MusicCredits read them from wavlake-index.json, plebrap-index.json and artists.json before the catalog.
    $read = fn (string $name): array => json_decode((string) file_get_contents(resource_path('data/music/'.$name)), true);
    $artists = $read('artists.json');
    $target = function (?array $identity, ?string $ownTarget, ?string $ownKind): array {
        if (is_string($identity['lud16'] ?? null) && preg_match('/^[a-z0-9._+-]+@[a-z0-9.-]+\.[a-z]{2,}$/i', $identity['lud16']) === 1) {
            return [strtolower($identity['lud16']), 'lud16'];
        }
        if (is_string($identity['npub'] ?? null) && preg_match('/^npub1[02-9ac-hj-np-z]{58}$/', $identity['npub']) === 1) {
            return ['https://njump.me/'.$identity['npub'], 'njump'];
        }
        $usable = fn (string $t, string $k): bool => $k === 'lud16' ? str_contains($t, '@') : str_starts_with($t, 'https://');
        foreach (['youtube' => 'youtube', 'qr_target' => $identity['qr_kind'] ?? null] as $key => $kind) {
            if (is_string($identity[$key] ?? null) && is_string($kind) && in_array($kind, MusicCredits::KINDS, true) && $usable($identity[$key], $kind)) {
                return [$identity[$key], $kind];
            }
        }

        return is_string($ownTarget) && is_string($ownKind) && $usable($ownTarget, $ownKind) ? [$ownTarget, $ownKind] : [null, null];
    };
    $legacy = [];
    foreach ($read('wavlake-index.json')['tracks'] as $track) {
        $own = (string) ($track['qr_target'] ?? '');
        $kind = match (true) {
            ($track['zap_route'] ?? '') === 'nostr-lud16' && str_contains($own, '@') => 'lud16',
            str_starts_with($own, 'https://njump.me/') => 'njump',
            str_starts_with($own, 'https://www.youtube.com/') || str_starts_with($own, 'https://youtube.com/') => 'youtube',
            str_starts_with($own, 'https://wavlake.com/') => 'wavlake',
            default => null,
        };
        $legacy[$track['file']] = [$track['artist'], $track['title'], ...$target($artists[$track['artist']] ?? null, $own, $kind)];
    }
    foreach ($read('plebrap-index.json')['tracks'] as $track) {
        $legacy[$track['file']] = [$track['artists'][0], $track['title'], ...$target($artists[$track['artists'][0]] ?? null, null, null)];
    }

    $missing = [];
    $differences = [];
    foreach ($legacy as $file => [$artist, $title, $qrTarget, $kind]) {
        if (MusicCatalog::track($file) === null) {
            $missing[] = $file;

            continue;
        }

        $credit = MusicCredits::forFile($file);
        $picture = is_string($artists[$artist]['picture'] ?? null) && str_starts_with($artists[$artist]['picture'], 'https://') ? $artists[$artist]['picture'] : null;
        $expected = $qrTarget === null ? null
            : ['artist' => $artist, 'title' => $title, 'target' => $qrTarget, 'kind' => $kind, 'picture' => $picture];

        if ($credit !== $expected) {
            $differences[$file] = [$expected, $credit];
        }
    }

    // 154 until 2026-10-17, when the 14 indexed Wavlake files of 11 artists left the library (user: "Wir brauchen sie nicht").
    expect(count($legacy))->toBe(140)
        ->and($differences)->toBe([])
        // Indexed, but not in the prod library the catalog was made from: the broken upload (~/music-broken) and the
        // four duplicates moved to disabled/ on 2026-10-16 (twentyone:music-duplicates).
        ->and($missing)->toEqualCanonicalizing([
            'Who Comes Home - Under The Mulga Groove__v1.m4a',
            'Alone In Valentine - single - Joe Martin__v1.m4a',
            'Backseat Driver - single - Joe Martin__v1.m4a',
            'wavlake/Empty Passenger Seat - Joe Martin__v1.m4a',
            'wavlake/Leave The Porch Light Low - Under The Mulga Groove__v1.m4a',
        ])
        // Every artist of artists.json in the catalog, every field kept.
        ->and(collect($artists)->except('_readme')->every(fn (array $identity, string $name): bool => MusicCatalog::artists()[MusicCatalog::slug($name)] === ['name' => $name, ...$identity]))->toBeTrue();

    // The rest of the library: the house artist where the house rule says so, nothing marked "none".
    foreach (MusicCatalog::tracks() as $file => $track) {
        if ($track['credit_source'] === 'house') {
            expect(MusicCredits::forFile($file))->toBe(MusicCredits::house());
        }
    }
    expect(collect(MusicCatalog::tracks())->pluck('credit_source')->unique()->sort()->values()->all())->toBe(['house', 'manual', 'plebrap-index', 'wavlake-index']);
});

test('the catalog command measures the library once, keeps ids across runs and moves, and leaves out disabled/ and other files', function () {
    $music = $this->root.'/music';
    catalogFixture($music.'/Song One - Somebody__v1.m4a', 3);
    catalogFixture($music.'/plebrap/Song Two__v1.m4a', 7);
    catalogFixture($music.'/disabled/Old__v1.m4a', 3);
    File::ensureDirectoryExists($music.'/instrumental');
    File::put($music.'/instrumental/SHA256SUMS', "abc  x.m4a\n");

    $this->artisan('twentyone:music-catalog', ['--dir' => $music])->expectsOutputToContain('2 files, 2 to measure')->assertSuccessful();

    $tracks = MusicCatalog::tracks();
    expect(array_keys($tracks))->toBe(['Song One - Somebody__v1.m4a', 'plebrap/Song Two__v1.m4a'])
        // Files no index names have no credit, wherever they lie: the folder decides nothing (user 2026-10-16).
        ->and($tracks['Song One - Somebody__v1.m4a'])->toMatchArray(['credit_source' => 'none', 'artists' => [], 'title' => 'Song One - Somebody'])
        ->and($tracks['plebrap/Song Two__v1.m4a'])->toMatchArray(['credit_source' => 'none', 'artists' => []])
        ->and(MusicCredits::forFile('plebrap/Song Two__v1.m4a'))->toBeNull()
        ->and($tracks['Song One - Somebody__v1.m4a']['duration'])->toEqualWithDelta(15.0, 0.2)
        ->and($tracks['Song One - Somebody__v1.m4a']['lufs'])->toBeFloat()->toBeLessThan(0.0)
        ->and($tracks['Song One - Somebody__v1.m4a']['true_peak'])->toBeFloat()
        ->and($tracks['Song One - Somebody__v1.m4a']['sha1'])->toBe(sha1_file($music.'/Song One - Somebody__v1.m4a'))
        ->and(count(MusicCatalog::fingerprints()[$tracks['plebrap/Song Two__v1.m4a']['id']]))->toBeGreaterThan(50);

    // The catalog names the house artist for a track: a new run keeps it, as every other credit.
    $tracks['Song One - Somebody__v1.m4a'] = [...$tracks['Song One - Somebody__v1.m4a'], 'artists' => ['dj-bitcoin'], 'credit_source' => 'house'];
    MusicCatalog::write($tracks, MusicCatalog::artists(), collect($tracks)->mapWithKeys(fn (array $track): array => [$track['id'] => MusicCatalog::fingerprints()[$track['id']]])->all());
    $before = (string) file_get_contents(MusicCatalog::path());
    $this->artisan('twentyone:music-catalog', ['--dir' => $music])->expectsOutputToContain('2 files, 0 to measure, 2 unchanged')->assertSuccessful();
    expect((string) file_get_contents(MusicCatalog::path()))->toBe($before)
        ->and(MusicCredits::forFile('Song One - Somebody__v1.m4a'))->toBe(MusicCredits::house());

    // A moved file keeps its id.
    $id = $tracks['plebrap/Song Two__v1.m4a']['id'];
    File::ensureDirectoryExists($music.'/wavlake');
    rename($music.'/plebrap/Song Two__v1.m4a', $music.'/wavlake/Song Two__v1.m4a');
    $this->artisan('twentyone:music-catalog', ['--dir' => $music])->assertSuccessful();
    expect(MusicCatalog::track('wavlake/Song Two__v1.m4a'))->toMatchArray(['id' => $id])
        ->and(MusicCatalog::track('plebrap/Song Two__v1.m4a'))->toBeNull();
});

test('duplicates: the same recording across folders is found, the credited copy kept, the other gets an mv line; nothing is moved', function () {
    $music = $this->root.'/music';
    catalogFixture($music.'/instrumental/120__v1.m4a', 3, '192k');
    catalogFixture($music.'/plebrap/29 - Song - Rapper__v1.m4a', 3, '96k');
    catalogFixture($music.'/Other - Somebody__v1.m4a', 7);
    catalogFixture($music.'/disabled/copies/Other copy__v1.m4a', 7);
    $this->artisan('twentyone:music-catalog', ['--dir' => $music])->assertSuccessful();

    // The PlebRap copy is credited (as the index credits it), so it stays despite its lower bitrate.
    $tracks = MusicCatalog::tracks();
    $tracks['plebrap/29 - Song - Rapper__v1.m4a'] = [...$tracks['plebrap/29 - Song - Rapper__v1.m4a'], 'artists' => ['rapper'], 'credit_source' => 'manual'];
    $prints = [];
    foreach ($tracks as $track) {
        $prints[$track['id']] = MusicCatalog::fingerprints()[$track['id']];
    }
    MusicCatalog::write($tracks, [...MusicCatalog::artists(), 'rapper' => ['name' => 'Rapper', 'lud16' => 'theben@getalby.com']], $prints);
    MusicCredits::flush();

    $json = $this->root.'/report.json';
    $this->artisan('twentyone:music-duplicates', ['--dir' => $music, '--json' => $json])->assertSuccessful();
    $report = json_decode((string) file_get_contents($json), true);

    expect($report['clusters'])->toHaveCount(1)
        ->and($report['clusters'][0]['keeper']['file'])->toBe('plebrap/29 - Song - Rapper__v1.m4a')
        ->and($report['clusters'][0]['losers'][0]['file'])->toBe('instrumental/120__v1.m4a')
        ->and($report['clusters'][0]['losers'][0]['similarity'])->toBeGreaterThan(0.95)
        ->and($report['mv'])->toBe(["mkdir -p 'disabled/instrumental' && mv -n -- 'instrumental/120__v1.m4a' 'disabled/instrumental/120__v1.m4a'"])
        // A copy already under disabled/ is reported, not moved again.
        ->and($report['already_moved'])->toHaveCount(1)
        ->and($report['already_moved'][0])->toMatchArray(['file' => 'Other - Somebody__v1.m4a', 'disabled' => 'disabled/copies/Other copy__v1.m4a'])
        ->and(is_file($music.'/instrumental/120__v1.m4a'))->toBeTrue();
});

test('a new song is normalised to -15 LUFS and at most -1 dBTP under the user\'s name, entered with its credit; a duplicate, a banned artist or an unreadable file is refused', function () {
    $music = $this->root.'/music';
    File::ensureDirectoryExists($music.'/plebrap');
    catalogFixture($this->root.'/upload/Loud Song.m4a', 3, '192k', 4.0);
    $this->artisan('twentyone:music-catalog', ['--dir' => $music])->assertSuccessful();

    $this->artisan('twentyone:music-add', ['file' => $this->root.'/upload/Loud Song.m4a', '--artist' => 'New Rapper', '--lud16' => 'theben@getalby.com', '--dir' => $music])
        ->expectsOutputToContain('Credit: New Rapper, lud16 theben@getalby.com')->assertSuccessful();

    // Straight into the music folder: the catalog knows the artist, not a folder.
    $track = MusicCatalog::track('Loud Song__v1.m4a');
    expect(is_file($music.'/Loud Song__v1.m4a'))->toBeTrue()
        ->and($track)->toMatchArray(['artists' => ['new-rapper'], 'credit_source' => 'manual', 'title' => 'Loud Song'])
        ->and($track['lufs'])->toEqualWithDelta(-15.0, 1.0)
        ->and($track['true_peak'])->toBeLessThanOrEqual(-1.0)
        ->and(MusicCatalog::artists()['new-rapper'])->toBe(['name' => 'New Rapper', 'lud16' => 'theben@getalby.com'])
        ->and(MusicCredits::forFile('Loud Song__v1.m4a'))->toMatchArray(['artist' => 'New Rapper', 'kind' => 'lud16']);

    // The same recording under another name is refused.
    copy($this->root.'/upload/Loud Song.m4a', $this->root.'/upload/Same Again.m4a');
    $this->artisan('twentyone:music-add', ['file' => $this->root.'/upload/Same Again.m4a', '--dir' => $music])
        ->expectsOutputToContain('Already in the library: Loud Song__v1.m4a')->assertFailed();

    catalogFixture($this->root.'/upload/Drone - ICT - DJ Lexo.m4a', 7);
    $this->artisan('twentyone:music-add', ['file' => $this->root.'/upload/Drone - ICT - DJ Lexo.m4a', '--dir' => $music])
        ->expectsOutputToContain('Banned artist')->assertFailed();

    File::put($this->root.'/upload/broken.m4a', 'not audio');
    $this->artisan('twentyone:music-add', ['file' => $this->root.'/upload/broken.m4a', '--dir' => $music])
        ->expectsOutputToContain('Not readable as audio')->assertFailed();

    // Without an artist: entered as "none", no card, never the house artist in silence.
    catalogFixture($this->root.'/upload/Quiet__v2.m4a', 7);
    $this->artisan('twentyone:music-add', ['file' => $this->root.'/upload/Quiet__v2.m4a', '--dir' => $music])->assertSuccessful();
    expect(MusicCatalog::track('Quiet__v2.m4a'))->toMatchArray(['credit_source' => 'none'])
        ->and(MusicCredits::forFile('Quiet__v2.m4a'))->toBeNull()
        ->and(collect(File::allFiles($music))->map->getFilename()->sort()->values()->all())->toBe(['Loud Song__v1.m4a', 'Quiet__v2.m4a']);
});

test('renormalize re-makes a loud library song from its original under the same name', function () {
    $music = $this->root.'/music';
    catalogFixture($music.'/instrumental/12__v1.m4a', 3, '128k', 4.0);
    catalogFixture($this->root.'/prenorm/instrumental/12__v1.m4a', 3, '192k', 4.0);
    $this->artisan('twentyone:music-catalog', ['--dir' => $music])->assertSuccessful();
    expect(MusicCatalog::track('instrumental/12__v1.m4a')['true_peak'])->toBeGreaterThan(-1.0);
    $before = sha1_file($music.'/instrumental/12__v1.m4a');

    $this->artisan('twentyone:music-renormalize', ['--from' => $this->root.'/prenorm', '--dir' => $music])
        ->expectsOutputToContain('1 re-made, 0 skipped or failed of 1')->assertSuccessful();
    $this->artisan('twentyone:music-catalog', ['--dir' => $music])->assertSuccessful();

    expect(sha1_file($music.'/instrumental/12__v1.m4a'))->not->toBe($before)
        ->and(MusicCatalog::track('instrumental/12__v1.m4a')['true_peak'])->toBeLessThanOrEqual(-1.0)
        ->and(MusicCatalog::track('instrumental/12__v1.m4a')['lufs'])->toEqualWithDelta(-15.0, 1.0);
});

test('a source that measures above 0 LUFS is still normalised, though loudnorm refuses a measured_I above 0', function () {
    // Float WAV, so the level survives any encoder: lavfi's sine runs at 1/8 amplitude, so volume 24 is amplitude 3 and reads above 0 LUFS.
    $input = $this->root.'/upload/over-full-scale.wav';
    File::ensureDirectoryExists(dirname($input));
    Process::run(['ffmpeg', '-y', '-v', 'error', '-f', 'lavfi', '-i', 'sine=frequency=440:duration=6', '-filter:a', 'volume=24.0', '-ac', '2', '-c:a', 'pcm_f32le', $input])->throw();
    $source = AudioProbe::loudness(Process::run(['ffmpeg', '-hide_banner', '-nostats', '-i', $input, '-filter:a', 'ebur128=peak=true', '-f', 'null', '-'])->errorOutput());
    expect($source['lufs'])->toBeGreaterThan(0.0);

    $result = (new LoudnessNormalizer)->normalize($input, $this->root.'/over.m4a');

    expect(is_file($this->root.'/over.m4a'))->toBeTrue()
        ->and($result['lufs'])->toEqualWithDelta(-15.0, 1.0)
        ->and($result['true_peak'])->toBeLessThanOrEqual(-1.0);
});
