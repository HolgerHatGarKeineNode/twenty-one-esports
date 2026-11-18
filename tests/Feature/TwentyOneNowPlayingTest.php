<?php

use App\Support\TwentyOne\Stream\MusicCredits;
use App\Support\TwentyOne\Stream\NowPlaying;
use App\Support\TwentyOne\Stream\RotationKit;
use App\Support\TwentyOne\Stream\SceneRenderer;
use App\Support\TwentyOne\Stream\StreamImages;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;

/*
 * The music on air on every stream slide (user 2026-10-15): the artist, their Nostr avatar and a QR code to tip
 * them; the house artist for the uncredited instrumentals and songs.
 */

afterEach(fn () => MusicCredits::flush());

test('every credited track and the house artist have their QR code drawn, so no card ever goes missing', function () {
    MusicCredits::flush();
    $credits = [...array_filter(MusicCredits::all(), fn (array $credit): bool => $credit['artist'] !== MusicCredits::HOUSE_ARTIST), MusicCredits::house()];

    // Every credited, non-house track of the catalog and the house artist: the count follows the catalog, so an
    // import (twentyone:music-add) never has to touch this test; the floor catches a catalog that lost its credits.
    $catalog = json_decode((string) file_get_contents(resource_path('data/music/catalog.json')), true);
    $credited = count(array_filter($catalog['tracks'], fn (array $track): bool => ! in_array($track['credit_source'] ?? 'none', ['none', 'house'], true)));
    expect(count($credits))->toBe($credited + 1)->toBeGreaterThanOrEqual(140);

    foreach ($credits as $credit) {
        expect(in_array($credit['kind'], MusicCredits::KINDS, true))->toBeTrue($credit['artist'])
            ->and(is_file(MusicCredits::qrFile($credit['target'], $credit['kind'])))->toBeTrue('php artisan twentyone:music-qr is missing a code for '.$credit['artist']);
    }
});

test('a Wavlake track credits its artist with the lightning address, a PlebRap track its first artist, a house track DJ Bitcoin, a file the catalog does not know nobody', function () {
    $dir = rtrim((string) config('twentyone.stream.music.dir'), '/');

    expect(MusicCredits::forFile($dir."/wavlake/Can't Say I'm Not Trying - Ainsley Costello__v1.m4a"))->toMatchArray(['artist' => 'Ainsley Costello', 'kind' => 'lud16', 'target' => 'ainsleycostello@getalby.com'])
        ->and(MusicCredits::forFile('instrumental/01__v1.m4a'))->toMatchArray(['artist' => 'DJ Bitcoin', 'kind' => 'lud16', 'target' => 'theben@getalby.com'])
        ->and(MusicCredits::forFile($dir.'/einundzwanzig-song__v1.m4a'))->toMatchArray(['artist' => 'DJ Bitcoin'])
        // The folder decides nothing any more: an instrumental the catalog does not list has no credit.
        ->and(MusicCredits::forFile('instrumental/07 - Something__v1.m4a'))->toBeNull()
        // A Wavlake track of an artist nobody found stays without a card, never a guess.
        ->and(MusicCredits::qrPayload('theben@getalby.com', 'lud16'))->toBe('lightning:theben@getalby.com')
        ->and(MusicCredits::qrPayload('https://njump.me/npub1x', 'njump'))->toBe('https://njump.me/npub1x');

    $plebrap = collect(json_decode((string) file_get_contents(resource_path('data/music/plebrap-index.json')), true)['tracks']);
    expect($plebrap)->toHaveCount(38)
        ->and($plebrap->firstWhere('file', 'plebrap/07 - Bank gegen Node - Too Bit to Fail, JustAnotherNode, Hanspanzer__v1.m4a')['artists'])->toBe(['Too Bit to Fail', 'JustAnotherNode', 'Hanspanzer']);
});

test('the card carries the artist, the cached avatar and the drawn code; a track without a credit or without a code has none', function () {
    $images = Mockery::mock(StreamImages::class);
    // The first ask comes before the builder fetched the picture, the next after: the kept card shows it then.
    $images->shouldReceive('musicAvatar')->andReturn(null, 'data:image/jpeg;base64,AAAA');
    $playing = new NowPlaying($images, '/nonexistent');

    expect($playing->card('wavlake/Debasement - Contra__v1.m4a')['avatar'])->toBeNull();
    $card = $playing->card('wavlake/Debasement - Contra__v1.m4a');
    expect($card)->toMatchArray(['artist' => 'Contra', 'title' => MusicCredits::forFile('wavlake/Debasement - Contra__v1.m4a')['title'], 'kind' => 'lud16', 'avatar' => 'data:image/jpeg;base64,AAAA'])
        ->and($card['title'])->toBeString()->not->toBe('')
        ->and($card['qr'])->toContain('<svg')
        ->and($playing->card('plebrap/unknown file__v1.m4a'))->toBeNull()
        // No timeline yet: nothing plays, no card.
        ->and($playing->at(microtime(true)))->toBeNull();
});

test('every slide carries the column at x 1040 to 1280: the music with a card, the league without one, the viewer count below; the lower third ends before it', function () {
    $card = ['artist' => 'DJ Bitcoin', 'avatar' => null, 'qr' => (string) file_get_contents(MusicCredits::qrFile(MusicCredits::HOUSE_LUD16, 'lud16')), 'kind' => 'lud16'];
    $renderer = SceneRenderer::fromConfig();
    $scene = ['voice_caption' => str_repeat('Markus hier, der Cup läuft. ', 6), 'viewers' => 1234];
    // An empty slide of our own: the lower third and the column go in before its </svg>.
    $dir = storage_path('framework/testing/now-playing-views');
    File::ensureDirectoryExists($dir);
    File::put($dir.'/blank.blade.php', '<svg xmlns="http://www.w3.org/2000/svg" width="1280" height="720"></svg>');
    View::addNamespace('np-test', $dir);

    $with = $renderer->svg([...$scene, 'now_playing' => $card], 'np-test::blank');
    $without = $renderer->svg($scene, 'np-test::blank');

    expect($with)->toContain('data-unit="now-playing-column" data-box="1040 0 1280 720"')
        ->toContain('JETZT LÄUFT')->toContain('DJ Bitcoin')->toContain('Zap den Artist')
        ->toContain('1,234')->toContain('watching')->toContain('einundzwanzig.space')
        // Without a card the same column holds the league and the site's code, never an empty box.
        ->and($without)->toContain('data-unit="now-playing-column" data-box="1040 0 1280 720"')
        ->toContain('data-unit="now-playing-league"')->toContain('Scan and play')->toContain('1,234')
        ->not->toContain('JETZT LÄUFT')
        ->and(substr_count($without, 'width="196" height="196" fill="#FFFFFF"'))->toBe(1)
        // No count, no viewer row.
        ->and($renderer->svg([], 'np-test::blank'))->toContain('now-playing-column')->not->toContain('watching');

    // The lower third runs from x 64 and ends at x 1016, 24 px before the column.
    preg_match('/data-unit="voice-caption"><rect x="(\d+)" y="495" width="(\d+)"/', $with, $m);
    expect((int) $m[1] + (int) $m[2])->toBe(1016)
        // The column comes last, over whatever the slide drew.
        ->and(strrpos($with, 'now-playing-column'))->toBeGreaterThan(strrpos($with, 'voice-caption'));
});

test('the column is the artist\'s stage: a long name in two whole lines as its largest text, the title only when known', function () {
    $card = ['artist' => 'DJ Bitcoin', 'title' => null, 'avatar' => null, 'qr' => (string) file_get_contents(MusicCredits::qrFile(MusicCredits::HOUSE_LUD16, 'lud16')), 'kind' => 'lud16'];
    $renderer = SceneRenderer::fromConfig();

    foreach (['Under The Mulga Groove', 'Yellow Velvet Milk Club', 'Bitcoin Jesus Rapper Collective', 'El Presidento Ben'] as $artist) {
        $svg = $renderer->svg(['stats' => [], 'viewers' => 1234, 'now_playing' => [...$card, 'artist' => $artist, 'title' => 'Proof of Work']], 'stream.rotation.a4-join');
        preg_match_all('/data-unit="now-playing-artist-\d" data-box="[^"]+" x="([\d.]+)" y="[\d.]+" font-family="([^"]+)" font-weight="800" font-size="([\d.]+)" fill="#FFFFFF" text-anchor="middle">([^<]+)</', $svg, $m, PREG_SET_ORDER);

        expect(count($m))->toBeGreaterThanOrEqual(1, $artist)->toBeLessThanOrEqual(2)
            ->and(implode(' ', array_map(fn (array $line): string => $line[4], $m)))->toBe($artist)
            ->and($svg)->toContain('>Proof of Work<');
        foreach ($m as $line) {
            // Centred on x 1162 (the middle of x 1044..1280), within the 200 px of text, and larger than every other text of the column (11 to 15 px).
            expect(RotationKit::width($line[4], $line[2], (float) $line[3]) * 1.04)->toBeLessThanOrEqual(200.0, $line[4])
                ->and((float) $line[1])->toBe(1162.0)
                ->and((float) $line[3])->toBeGreaterThan(15.0);
        }
    }

    // Every real credited artist (and the house artist) stands whole in the column, never cut.
    foreach (collect(MusicCredits::all())->pluck('artist')->push(MusicCredits::HOUSE_ARTIST)->unique() as $artist) {
        $svg = $renderer->svg(['stats' => [], 'now_playing' => [...$card, 'artist' => $artist]], 'stream.rotation.a4-join');
        preg_match_all('/data-unit="now-playing-artist-\d"[^>]*>([^<]+)</', $svg, $m);

        expect(html_entity_decode(implode(' ', $m[1])))->toBe(RotationKit::clean($artist), $artist);
    }

    // A credit without a title (the house artist): no title line, never a placeholder.
    expect($renderer->svg(['stats' => [], 'now_playing' => $card], 'stream.rotation.a4-join'))->not->toContain('now-playing-title')
        // No cached picture: the artist's initials in the ring.
        ->toContain('data-unit="now-playing-initials"')->toContain('>DB<');
});

test('the column is opaque on a real slide: nothing of the slide shows in x 1040 to 1280', function () {
    $png = SceneRenderer::fromConfig()->png(['stats' => [], 'viewers' => 7], 'stream.rotation.a4-join');
    $image = imagecreatefromstring($png);

    // Above y 112 only the calm ground (the players' LIVE badge sits there): every pixel right of the orange edge is #121215.
    $other = 0;
    for ($x = 1046; $x < 1280; $x += 3) {
        for ($y = 0; $y < 112; $y += 3) {
            $other += imagecolorat($image, $x, $y) === 0x121215 ? 0 : 1;
        }
    }
    expect($other)->toBe(0)
        ->and(imagecolorat($image, 1041, 400))->toBe(0xF7931A);
});

test('the column shows whether Markus and Sirius are on air, in its top band, within its 200 px, the top 32 px kept calm', function (bool $voice) {
    $renderer = SceneRenderer::fromConfig();
    $svg = $renderer->svg(['stats' => [], 'viewers' => 7, 'voice_on_air' => $voice], 'stream.rotation.a4-join');
    preg_match('#<g data-unit="voice-status"[^>]*>(.*?)</g>#s', $svg, $status);
    preg_match_all('#<text x="([\d.]+)" y="([\d.]+)"[^>]*font-size="(\d+)"[^>]*>([^<]+)</text>#', $status[1] ?? '', $texts, PREG_SET_ORDER);

    expect(html_entity_decode(implode(' / ', array_column($texts, 4))))->toBe($voice
        ? 'ON AIR / Markus und Sirius / sind live on air'
        : 'OFF AIR / Markus und Sirius suchen / das Kabel für den Neustart');

    foreach ($texts as [, $x, $y, $size, $text]) {
        expect(RotationKit::width(html_entity_decode($text), RotationKit::MONO, (float) $size))->toBeLessThanOrEqual(200.0, $text)
            ->and((float) $y)->toBeGreaterThan(36.0)->toBeLessThan(112.0);
    }

    // Without the light (commentary off) the top stays empty; with it, the band above y 32 still does.
    expect($renderer->svg(['stats' => [], 'viewers' => 7], 'stream.rotation.a4-join'))->not->toContain('voice-status');

    $png = $renderer->png(['stats' => [], 'viewers' => 7, 'voice_on_air' => $voice], 'stream.rotation.a4-join');
    $image = imagecreatefromstring($png);
    $other = 0;
    for ($x = 1046; $x < 1280; $x += 3) {
        for ($y = 0; $y < 32; $y += 3) {
            $other += imagecolorat($image, $x, $y) === 0x121215 ? 0 : 1;
        }
    }
    expect($other)->toBe(0);
})->with(['on air' => true, 'off air' => false]);
