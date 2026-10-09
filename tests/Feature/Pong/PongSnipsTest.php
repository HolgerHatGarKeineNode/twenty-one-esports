<?php

/*
|--------------------------------------------------------------------------
| Proof of Pong's voices from the snippet library (plan "Proof of Pong", P7)
|--------------------------------------------------------------------------
|
| The soundboard cut into snippets (tools/sound-snips/cut.py, public/sounds/snips): every file of the manifest is
| there and between 0.5 and 4.6 seconds, every occasion of Pong has a pool, a draw never repeats the last snippet and
| deals a whole pool before repeating, a figure's own snippets come with their weight (tests/js/snips.test.mjs).
|
*/

use Illuminate\Support\Facades\Process;

test('the snippet library has its files and every occasion of Pong draws without repeats (Node)', function () {
    $run = Process::path(base_path())->timeout(60)->run(['node', '--test', 'tests/js/snips.test.mjs']);

    expect($run->successful())->toBeTrue($run->output().$run->errorOutput())
        ->and($run->output())->toContain('ℹ pass 7')->toContain('ℹ fail 0');
});

test('the manifest lists only files of the snippet folder, each snippet once', function () {
    $manifest = json_decode((string) file_get_contents(public_path('sounds/snips/manifest.json')), true, flags: JSON_THROW_ON_ERROR);
    $files = collect(glob(public_path('sounds/snips/*.mp3')))->map(fn (string $path): string => basename($path))->sort()->values();

    expect($manifest['base'])->toBe('/sounds/snips/')
        ->and(collect($manifest['snips'])->pluck('file')->sort()->values()->all())->toBe($files->all())
        ->and(collect($manifest['snips'])->pluck('dur')->filter(fn (float|int $dur): bool => $dur < 0.5 || $dur > 4.6)->all())->toBe([]);
});
