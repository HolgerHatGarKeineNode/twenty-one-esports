<?php

use App\Models\User;
use App\Support\Nostr\LoginChallenges;
use App\Support\Nostr\NostrKeys;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\TestSigner;

pest()->group('nostr');

beforeEach(function () {
    $this->paidPubkeys = [];
    Http::fake(fn () => Http::response(array_map(fn (string $pubkey) => ['pubkey' => $pubkey], $this->paidPubkeys)));
});

function issueChallenge(): string
{
    return test()->postJson(route('auth.nostr.challenge'))->assertOk()->json('challenge');
}

test('a signed login event creates a session for the pubkey', function () {
    $signer = new TestSigner;
    $this->paidPubkeys = [$signer->pubkey];
    $event = $signer->loginEvent(issueChallenge());

    $this->postJson(route('auth.nostr.login'), ['event' => $event])
        ->assertOk()
        ->assertJson(['redirect' => route('home')]);

    $user = User::query()->sole();
    expect($user->pubkey)->toBe($signer->pubkey)
        ->and($user->npub)->toBe(NostrKeys::hexToNpub($signer->pubkey))
        ->and($user->is_member)->toBeTrue();
    $this->assertAuthenticatedAs($user);
});

test('a guest is sent to the login page and back to the page they wanted', function () {
    $this->get(route('gaming.edit'))->assertRedirect(route('login'));

    $this->postJson(route('auth.nostr.login'), ['event' => (new TestSigner)->loginEvent(issueChallenge())])
        ->assertOk()
        ->assertJson(['redirect' => route('gaming.edit')]);
});

test('the membership check runs after the login response, not before it', function () {
    $signer = new TestSigner;
    $this->paidPubkeys = [$signer->pubkey];
    $deferred = new class extends DeferredCallbackCollection
    {
        public bool $held = true;

        public function invokeWhen(?Closure $when = null): void
        {
            if (! $this->held) {
                parent::invokeWhen($when);
            }
        }
    };
    $this->app->instance(DeferredCallbackCollection::class, $deferred);

    $this->postJson(route('auth.nostr.login'), ['event' => $signer->loginEvent(issueChallenge())])->assertOk();

    Http::assertNothingSent();
    expect(User::query()->sole()->is_member)->toBeFalse();

    $deferred->held = false;
    $deferred->invoke();

    expect(User::query()->sole()->is_member)->toBeTrue();
});

test('a login is refused', function (Closure $makeEvent) {
    $signer = new TestSigner;

    $this->postJson(route('auth.nostr.login'), ['event' => $makeEvent($signer, issueChallenge())])
        ->assertUnauthorized();

    $this->assertGuest();
    expect(User::query()->count())->toBe(0);
})->with([
    'with a wrong signature' => function (TestSigner $signer, string $challenge) {
        $event = $signer->loginEvent($challenge);
        $event['sig'] = (new TestSigner)->loginEvent($challenge)['sig'];

        return $event;
    },
    'with an expired event' => fn (TestSigner $signer, string $challenge) => $signer->loginEvent($challenge, createdAt: now()->subSeconds(61)->getTimestamp()),
    'for a foreign url' => fn (TestSigner $signer, string $challenge) => $signer->loginEvent($challenge, url: 'https://evil.example/auth/nostr/login'),
    'with a challenge the server never issued' => fn (TestSigner $signer) => $signer->loginEvent(bin2hex(random_bytes(32))),
    'with another kind' => fn (TestSigner $signer, string $challenge) => $signer->loginEvent($challenge, kind: 22242),
]);

test('a challenge works only once', function () {
    $event = (new TestSigner)->loginEvent(issueChallenge());

    $this->postJson(route('auth.nostr.login'), ['event' => $event])->assertOk();

    // Same session, same event: only the single-use rule can refuse it here
    // (a logout in between would flush the session and hide a broken rule).
    $this->postJson(route('auth.nostr.login'), ['event' => $event])->assertUnauthorized();

    // Still refused after the cache was cleared (e.g. a deploy running cache:clear).
    Cache::flush();
    $this->postJson(route('auth.nostr.login'), ['event' => $event])->assertUnauthorized();
});

test('a challenge issued to another session is refused', function () {
    $event = (new TestSigner)->loginEvent(issueChallenge());

    $this->flushSession();

    $this->postJson(route('auth.nostr.login'), ['event' => $event])->assertUnauthorized();
});

test('two concurrent requests cannot both consume one challenge', function () {
    $challenges = app(LoginChallenges::class);
    $issuedTo = new Store('a', new ArraySessionHandler(10));
    $challenge = $challenges->issue($issuedTo);

    // Both requests read the same session snapshot before either writes it back.
    $first = new Store('a', new ArraySessionHandler(10));
    $second = new Store('a', new ArraySessionHandler(10));
    $first->put($issuedTo->all());
    $second->put($issuedTo->all());

    expect($challenges->consume($first, $challenge))->toBeTrue()
        ->and($challenges->consume($second, $challenge))->toBeFalse();
});

test('a signed profile of the same key fills name and picture, a foreign one is ignored', function () {
    $signer = new TestSigner;
    $profile = fn (TestSigner $by) => $by->sign(0, content: json_encode([
        'name' => 'satoshi', 'display_name' => 'Satoshi', 'picture' => 'https://example.com/s.png',
    ]));

    $this->postJson(route('auth.nostr.login'), [
        'event' => $signer->loginEvent(issueChallenge()),
        'profile' => $profile(new TestSigner),
    ])->assertOk();

    expect(User::query()->sole()->name)->toBeNull();

    $this->postJson(route('auth.nostr.login'), [
        'event' => $signer->loginEvent(issueChallenge()),
        'profile' => $profile($signer),
    ])->assertOk();

    expect(User::query()->sole())
        ->name->toBe('Satoshi')
        ->picture->toBe('https://example.com/s.png');
});
