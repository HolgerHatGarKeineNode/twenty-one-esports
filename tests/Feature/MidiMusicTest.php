<?php

/*
|--------------------------------------------------------------------------
| The games' MIDI background music (Blockfill, Hyperbitcoinization, Proof of Pong)
|--------------------------------------------------------------------------
|
| The MIDI reader, the voices, the shuffled playlist and the player under Node (tests/js/midiParse.test.mjs,
| tests/js/midiPlaylist.test.mjs), and the manifest in public/music/midi/: every listed file is there, with
| its credits, and LICENSES.md lists it.
| Adding a track is a file and a manifest entry, no code (resources/js/midi/player.js).
|
*/

use App\Support\Pages\RulesPage;
use Illuminate\Support\Facades\Process;
use Tests\Support\BlockfillOn;
use Tests\Support\HyperOn;
use Tests\Support\PongOn;

test('the MIDI reader parses the ten tracks, the fixtures and hand-built files, broken ones throw, every note has a voice (Node)', function () {
    $run = Process::path(base_path())->timeout(60)->run(['node', '--test', 'tests/js/midiParse.test.mjs']);

    expect($run->successful())->toBeTrue($run->output().$run->errorOutput())
        ->and($run->output())->toContain('ℹ pass 7')->toContain('ℹ fail 0')->toContain('ℹ skipped 0');
});

test('the playlist plays every track once per cycle, never twice in a row, and the player schedules and stops (Node)', function () {
    $run = Process::path(base_path())->timeout(60)->run(['node', '--test', 'tests/js/midiPlaylist.test.mjs']);

    expect($run->successful())->toBeTrue($run->output().$run->errorOutput())
        ->and($run->output())->toContain('ℹ pass 6')->toContain('ℹ fail 0')->toContain('ℹ skipped 0');
});

test('every track the manifest lists is a file next to it, and LICENSES.md carries its credits', function () {
    $dir = public_path('music/midi');
    $manifest = json_decode((string) file_get_contents("{$dir}/manifest.json"), true, flags: JSON_THROW_ON_ERROR);
    $licenses = (string) file_get_contents("{$dir}/LICENSES.md");

    expect($manifest['tracks'])->not->toBeEmpty();
    foreach ($manifest['tracks'] as $track) {
        expect($track['file'])->toMatch('/^[\w.-]+\.midi?$/i')
            ->and("{$dir}/{$track['file']}")->toBeFile()
            ->and($licenses)->toContain("| {$track['file']} | {$track['title']} | {$track['author']} | {$track['license']} | {$track['source']} |");
    }
});

test('the rules page credits every track with its author, license and source', function () {
    HyperOn::play();
    $section = collect(RulesPage::sections())->firstWhere('id', 'music');

    expect($section['table']['rows'])->toHaveCount(10)
        ->and($section['table']['rows'])->toContain(['Elegy for the Summer of 4096', 'Aureolus_Omicron', 'CC-BY 4.0, OGA-BY 3.0', 'Synthwave'])
        ->and($section['table']['rows'])->toContain(['Happy Sunset', 'kenliten', 'CC-BY 4.0', 'Trance'])
        ->and($section['links'])->toContain(['Happy Sunset', 'https://opengameart.org/content/happy-sunset']);

    $this->get(route('rules'))->assertOk()
        ->assertSee('Aureolus_Omicron')
        ->assertSee('https://opengameart.org/content/elegy-for-the-summer-of-4096', false);
});

test('the credits name only the games that are on, and stay off the page while both are off', function () {
    $lead = fn (): ?string => collect(RulesPage::sections())->firstWhere('id', 'music')['lead'] ?? null;

    // the test app boots with both games off
    expect($lead())->toBeNull();

    BlockfillOn::play();
    expect($lead())->toStartWith('The background music of Blockfill:');

    HyperOn::play();
    expect($lead())->toStartWith('The background music of Blockfill and Hyperbitcoinization:');

    PongOn::play();
    expect($lead())->toStartWith('The background music of Blockfill, Hyperbitcoinization and Proof of Pong:');
});

test('the Proof of Pong rules name its music and point to the credits', function () {
    PongOn::play();
    $pong = collect(RulesPage::sections())->firstWhere('id', 'proof-of-pong');

    expect($pong['items'])->toContain('The background music is Blockfill\'s shuffled MIDI playlist; every track with its author and license is listed under Music.')
        ->and(collect(RulesPage::sections())->firstWhere('id', 'music')['lead'])->toStartWith('The background music of Proof of Pong:');
});
