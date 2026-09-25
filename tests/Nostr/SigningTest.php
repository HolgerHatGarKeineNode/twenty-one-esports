<?php

use App\Support\Nostr\SignerMessages;
use Illuminate\Support\Facades\Process;

/*
 * Browser signing (resources/js/signing.js) runs where it lives:
 * tests/js/signing.test.mjs under Node. It fails if any Node test fails or is
 * skipped.
 */
test('signing hands the signer a plain copy and tells declined, unreachable, wrong key and other failures apart', function () {
    $run = Process::path(base_path())
        ->timeout(60)
        ->run(['node', '--test', 'tests/js/signing.test.mjs']);

    expect($run->successful())->toBeTrue($run->output().$run->errorOutput())
        ->and($run->output())->toContain('ℹ pass 5')->toContain('ℹ skipped 0');
});

test('every signer message is translated, and the reason placeholder survives translation', function () {
    app()->setLocale('de');
    $german = SignerMessages::labels();
    app()->setLocale('en');
    $english = SignerMessages::labels();

    foreach ($english as $key => $text) {
        expect($german[$key])->not->toBe($text, "{$key} has no German text");
    }

    expect($german['signerFailed'])->toContain(':reason')
        ->and($english['signerFailed'])->toContain(':reason');
});
