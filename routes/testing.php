<?php

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\Support\TestSigner;

/*
| Fixtures for tests/Browser only, never reachable outside testing
| (double-gated: the environment check below, and the "__test/" prefix is
| excluded from the route sweep in tests/Browser/RouteSweepTest.php).
*/

Route::prefix('__test')->name('testing.')->group(function () {
    // Positive control: proves the sweep's error collector actually catches a
    // thrown JavaScript error, rather than passing because nothing checks it.
    Route::get('js-throw', function () {
        abort_unless(app()->environment('testing'), 404);

        return view('pages.testing.js-throw');
    })->name('js-throw');

    // Positive control: proves the sweep's error collector catches a >=400
    // response on the page's own navigation.
    Route::get('server-error', function () {
        abort_unless(app()->environment('testing'), 404);

        throw new RuntimeException('Positive control: injected 500.');
    })->name('server-error');

    // Signs whatever event draft the browser's stubbed window.nostr posts,
    // using the same signer tests/Feature/Auth/NostrLoginTest.php trusts
    // (Tests\Support\TestSigner). This keeps the real signature/timing rules
    // in NostrLogin::verify() honest without hand-rolling secp256k1 in JS.
    Route::post('nostr/sign', function (Request $request) {
        abort_unless(app()->environment('testing'), 404);

        $draft = $request->json()->all();

        $signer = new TestSigner;

        /** @var list<list<string>> $tags */
        $tags = collect(is_array($draft['tags'] ?? null) ? $draft['tags'] : [])
            ->map(fn (mixed $tag): array => is_array($tag) ? array_map(strval(...), array_values($tag)) : [])
            ->values()
            ->all();

        return response()->json($signer->sign(
            (int) ($draft['kind'] ?? 27235),
            $tags,
            (string) ($draft['content'] ?? ''),
            isset($draft['created_at']) ? (int) $draft['created_at'] : null,
        ));
    })->name('nostr-sign')->withoutMiddleware(ValidateCsrfToken::class);
});
