<?php

/*
|--------------------------------------------------------------------------
| Webfont preloads: latin only (performance plan P5, F12)
|--------------------------------------------------------------------------
|
| Every page preloads the latin file of Unbounded and JetBrains Mono; the
| latin-ext files stay in the @font-face rules and load only when a page
| uses one of their glyphs (App\Support\LatinFontPreloads). Read against the
| build's fonts manifest, so a rebuild with new hashes keeps the test true.
|
*/

test('a page preloads the latin webfont files and no latin-ext file', function () {
    $manifest = json_decode((string) file_get_contents(public_path('build/fonts-manifest.json')), true);
    $ranges = [];
    foreach ($manifest['families'] as $family) {
        foreach ($family['variants'] as $variant) {
            foreach ($variant['files'] as $file) {
                $ranges[basename($file['file'])] = $file['unicodeRange'];
            }
        }
    }

    $html = $this->get(route('rules'))->assertOk()->getContent();
    preg_match_all('#<link rel="preload" as="font" href="[^"]*/([^"/]+\.woff2)"#', $html, $matches);
    $preloaded = $matches[1];

    expect($preloaded)->toHaveCount(2);
    foreach ($preloaded as $file) {
        expect($ranges[$file] ?? '')->toStartWith('U+0000-00FF');
    }
    // The latin-ext files are still declared, so a page that needs them gets them.
    foreach ($ranges as $file => $range) {
        expect($html)->toContain($file);
    }
});
