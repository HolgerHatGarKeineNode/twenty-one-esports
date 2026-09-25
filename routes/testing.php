<?php

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use swentel\nostr\Encryption\Nip44;
use Tests\Support\TestSigner;

/*
| Fixtures for tests/Browser only, never reachable outside testing
| (double-gated: the environment check below, and the "__test/" prefix is
| excluded from the route sweep in tests/Browser/RouteSweepTest.php).
*/

// A signer receives the draft untouched: TrimStrings (global middleware, so
// withoutMiddleware() cannot reach it) cut the PGN's trailing newline, and the
// league rightly refused the note as not the prepared one.
TrimStrings::skipWhen(fn (Request $request): bool => $request->is('__test/nostr/*'));
ConvertEmptyStringsToNull::skipWhen(fn (Request $request): bool => $request->is('__test/nostr/*'));

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

    // Logs this browser context in as the given user, then goes to `to`. Two
    // contexts, two users: actingAs() would make every request the same user
    // (tests/Browser/BlitzGameTest.php). The real login flow has its own test.
    Route::get('login/{user}', function (Request $request, User $user) {
        abort_unless(app()->environment('testing'), 404);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect((string) $request->query('to', '/'));
    })->name('login');

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

    // A stubbed window.nostr per browser context (TestSigner::browserStub):
    // signs and does NIP-44 with the key TestSigner::forBrowser() gave this
    // user, so a test can sign daily moves and chat as two different players.
    Route::post('nostr/{user}/sign', function (Request $request, User $user) {
        abort_unless(app()->environment('testing'), 404);
        $secret = Cache::get('test-nostr-secret:'.$user->id) ?? abort(404);
        $draft = $request->json()->all();

        /** @var list<list<string>> $tags */
        $tags = array_map(fn (mixed $tag): array => array_map(strval(...), (array) $tag), is_array($draft['tags'] ?? null) ? array_values($draft['tags']) : []);

        return response()->json((new TestSigner($secret))->sign((int) ($draft['kind'] ?? 1), $tags, (string) ($draft['content'] ?? ''), isset($draft['created_at']) ? (int) $draft['created_at'] : null));
    })->name('nostr-sign-as')->withoutMiddleware(ValidateCsrfToken::class);

    Route::post('nostr/{user}/nip44', function (Request $request, User $user) {
        abort_unless(app()->environment('testing'), 404);
        $secret = Cache::get('test-nostr-secret:'.$user->id) ?? abort(404);
        $key = Nip44::getConversationKey($secret, (string) $request->json('pubkey'));
        $text = (string) $request->json('text');

        return response()->json(['result' => $request->json('op') === 'encrypt' ? Nip44::encrypt($text, $key) : Nip44::decrypt($text, $key)]);
    })->name('nostr-nip44')->withoutMiddleware(ValidateCsrfToken::class);
});
