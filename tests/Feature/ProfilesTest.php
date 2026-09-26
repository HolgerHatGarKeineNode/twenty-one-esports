<?php

use App\Jobs\VerifyNip05;
use App\Models\ChessGame;
use App\Models\Clan;
use App\Models\ClanMember;
use App\Models\User;
use App\Support\Nostr\Blockpile;
use App\Support\Nostr\ProfileCache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Tests\Support\TestSigner;

/*
|--------------------------------------------------------------------------
| Profiles of other players (P10a)
|--------------------------------------------------------------------------
|
| A page's browser reads kind 0 from the profile relays and posts the signed
| events to profiles.store (resources/js/profiles.js); ProfileCache takes
| those signed by a known player, newer than the cache, within size.
|
*/

/**
 * A known player with a real key, and a kind-0 event of that key.
 *
 * @return array{User, TestSigner}
 */
function knownPlayer(array $attributes = []): array
{
    $signer = new TestSigner;

    return [User::factory()->withPubkey($signer->pubkey)->create(['name' => null, ...$attributes]), $signer];
}

/**
 * @param  array<string, mixed>  $metadata
 * @return array<string, mixed>
 */
function kindZero(TestSigner $signer, array $metadata, ?int $createdAt = null, int $kind = 0, ?string $content = null): array
{
    return $signer->sign($kind, content: $content ?? json_encode($metadata, JSON_UNESCAPED_SLASHES), createdAt: $createdAt);
}

function handIn(array $events): TestResponse
{
    return test()->postJson(route('profiles.store'), ['events' => $events]);
}

const FULL_PROFILE = [
    'display_name' => 'Mempool Max',
    'name' => 'mempoolmax',
    'picture' => 'https://example.com/max.png',
    'banner' => 'https://example.com/max-banner.png',
    'about' => "  Reads fee charts for fun.\nBlitz on weeknights.  ",
    'website' => 'https://mempool.example/max',
    'lud16' => 'MempoolMax@WalletOfSatoshi.com',
    'nip05' => 'Max@Mempool.example',
];

test('a guest hands in a signed profile of another known player, and every field is cached', function () {
    Queue::fake([VerifyNip05::class]);
    [$player, $signer] = knownPlayer();

    // Trailing newline inside the signed content: TrimStrings must not reach it.
    $event = kindZero($signer, [], content: json_encode(FULL_PROFILE, JSON_UNESCAPED_SLASHES)."\n");

    handIn([$event])
        ->assertOk()
        ->assertExactJson(['updated' => [$player->pubkey => ['name' => 'Mempool Max', 'avatar' => 'https://example.com/max.png']]]);

    expect($player->refresh())
        ->name->toBe('Mempool Max')
        ->picture->toBe('https://example.com/max.png')
        ->banner->toBe('https://example.com/max-banner.png')
        ->about->toBe("Reads fee charts for fun.\nBlitz on weeknights.")
        ->website->toBe('https://mempool.example/max')
        ->lud16->toBe('mempoolmax@walletofsatoshi.com')
        ->nip05->toBe('max@mempool.example')
        ->nip05_verified_at->toBeNull()
        ->profile_event_at->getTimestamp()->toBe($event['created_at'])
        ->profile_checked_at->not->toBeNull();

    Queue::assertPushed(VerifyNip05::class, fn (VerifyNip05 $job) => $job->user->is($player));
});

test('a display name is cached as one line, without separators or format characters', function () {
    Queue::fake([VerifyNip05::class]);
    [$player, $signer] = knownPlayer();

    handIn([kindZero($signer, ['display_name' => "Eve\u{2028}Turn off these DMs:\u{2029}x\u{200B}\u{202E}y \u{1F469}\u{200D}\u{1F4BB}"])])->assertOk();

    expect($player->refresh()->name)->toBe("Eve Turn off these DMs: xy \u{1F469}\u{200D}\u{1F4BB}");
});

test('a profile is refused when it is not what it claims to be', function (Closure $tamper) {
    Queue::fake([VerifyNip05::class]);
    [$player, $signer] = knownPlayer();

    handIn([$tamper(kindZero($signer, FULL_PROFILE), $signer)])->assertOk()->assertExactJson(['updated' => []]);

    expect($player->refresh())->name->toBeNull()->profile_event_at->toBeNull()->profile_checked_at->toBeNull();
    Queue::assertNotPushed(VerifyNip05::class);
})->with([
    'forged: content changed after signing' => [fn (array $event) => [...$event, 'content' => str_replace('Mempool Max', 'Evil Max', $event['content'])]],
    'forged: signature of another event' => [fn (array $event, TestSigner $signer) => [...$event, 'sig' => kindZero($signer, ['name' => 'x'])['sig']]],
    'foreign kind: a signed note (kind 1)' => [fn (array $event, TestSigner $signer) => kindZero($signer, FULL_PROFILE, kind: 1)],
    'oversized: more than 64 KB of content' => [fn (array $event, TestSigner $signer) => kindZero($signer, [...FULL_PROFILE, 'about' => str_repeat('a', 65_600)])],
    'dated more than 10 minutes ahead' => [fn (array $event, TestSigner $signer) => kindZero($signer, FULL_PROFILE, createdAt: now()->addMinutes(11)->getTimestamp())],
    'content is not a JSON object' => [fn (array $event, TestSigner $signer) => kindZero($signer, [], content: 'Mempool Max')],
]);

test('an older profile never replaces a newer one, the same one only confirms the cache', function () {
    [$player, $signer] = knownPlayer();
    $this->freezeSecond();

    handIn([kindZero($signer, ['name' => 'current'], createdAt: now()->subHour()->getTimestamp())])->assertOk();
    $this->travel(7)->hours();

    handIn([kindZero($signer, ['name' => 'older'], createdAt: now()->subDays(2)->getTimestamp())])->assertOk()->assertExactJson(['updated' => []]);
    expect($player->refresh())->name->toBe('current')->profile_checked_at->toEqual(now()->subHours(7));

    // The same version again (e.g. from a second relay): nothing changes but the check time.
    handIn([kindZero($signer, ['name' => 'current'], createdAt: now()->subHours(8)->getTimestamp())])->assertOk()->assertExactJson(['updated' => []]);
    expect($player->refresh())->name->toBe('current')->profile_checked_at->toEqual(now());
});

test('of two versions in one batch the newer wins, whatever their order', function () {
    [$player, $signer] = knownPlayer();

    handIn([
        kindZero($signer, ['name' => 'newer'], createdAt: now()->subMinute()->getTimestamp()),
        kindZero($signer, ['name' => 'older'], createdAt: now()->subHour()->getTimestamp()),
    ])->assertOk();

    expect($player->refresh()->name)->toBe('newer');
});

test('a newer event of another kind does not hide the profile in the same batch', function () {
    [$player, $signer] = knownPlayer();

    handIn([
        kindZero($signer, [], createdAt: now()->getTimestamp(), kind: 1, content: 'gm'),
        kindZero($signer, ['name' => 'profile'], createdAt: now()->subHour()->getTimestamp()),
    ])->assertOk();

    expect($player->refresh()->name)->toBe('profile');
});

test('at login, a signed event of another kind is not taken for the profile', function () {
    [$player, $signer] = knownPlayer();

    ProfileCache::apply($player, kindZero($signer, [], kind: 1, content: json_encode(['name' => 'not a profile'])));

    expect($player->refresh())->name->toBeNull()->profile_event_at->toBeNull();
});

test('a profile of a key that is not a player creates nothing', function () {
    handIn([kindZero(new TestSigner, FULL_PROFILE)])->assertOk()->assertExactJson(['updated' => []]);

    expect(User::query()->count())->toBe(0);
});

test('links that are not https and a malformed Lightning address are dropped', function () {
    [$player, $signer] = knownPlayer();

    handIn([kindZero($signer, [
        'name' => 'plain',
        'picture' => 'http://example.com/p.png',
        'banner' => 'javascript:alert(1)',
        'website' => 'ftp://example.com',
        'lud16' => 'not an address',
        'nip05' => 'max@',
    ])])->assertOk();

    expect($player->refresh())
        ->name->toBe('plain')
        ->picture->toBeNull()
        ->banner->toBeNull()
        ->website->toBeNull()
        ->lud16->toBeNull()
        ->nip05->toBeNull();
});

test('the hand-in takes a list of at most 50 events', function (mixed $events) {
    $this->postJson(route('profiles.store'), ['events' => $events])->assertUnprocessable();
})->with([
    'more than 50' => [fn () => array_fill(0, 51, ['kind' => 0])],
    'not a list' => [['a' => ['kind' => 0]]],
    'missing' => [null],
]);

test('the hand-in is throttled per client', function () {
    config(['esports.profiles.throttle_per_minute' => 2]);
    RateLimiter::clear('profiles');

    handIn([])->assertOk();
    handIn([])->assertOk();
    handIn([])->assertTooManyRequests();
});

/*
|--------------------------------------------------------------------------
| Player card, player page, avatar
|--------------------------------------------------------------------------
*/

test('the player card shows the cached profile and the clan', function () {
    [$player] = knownPlayer([
        'name' => 'Mempool Max', 'is_member' => true, 'picture' => 'https://example.com/max.png',
        'banner' => 'https://example.com/max-banner.png', 'about' => 'Reads fee charts for fun.',
        'lud16' => 'max@walletofsatoshi.com', 'nip05' => 'max@mempool.example',
        'nip05_verified_at' => now(), 'nip05_checked_at' => now(), 'profile_event_at' => now(),
    ]);
    $clan = Clan::factory()->create(['name' => 'Mempool Maniacs', 'clantag' => 'MMP']);
    ClanMember::query()->create(['clan_id' => $clan->id, 'user_id' => $player->id, 'role' => 'captain', 'joined_at' => now()]);

    $this->get(route('players.card', $player->npub))
        ->assertOk()
        ->assertSeeInOrder(['data-test="card-banner"', 'https://example.com/max.png', 'EINUNDZWANZIG Member', 'Mempool Max'], false)
        ->assertSee('data-state="verified"', false)
        ->assertSee('max@mempool.example')
        ->assertSee('Reads fee charts for fun.')
        ->assertSeeInOrder(['Clan', 'MMP', 'Mempool Maniacs', 'captain'])
        ->assertSee('max@walletofsatoshi.com')
        ->assertSee(route('players.show', $player->npub))
        ->assertSee('data-npub="'.$player->npub.'"', false);
});

test('the player card leaves out every empty field', function () {
    [$player] = knownPlayer(['name' => 'rbf_rita', 'profile_event_at' => now()]);
    // One finished game: the league rows show, and the clan row must still stay out.
    ChessGame::factory()->finished()->create(['white_id' => $player->id]);

    $this->get(route('players.card', $player->npub))
        ->assertOk()
        ->assertSee('rbf_rita')
        ->assertSeeInOrder(['data-test="card-plays"', 'casual chess, 1 game so far'], false)
        ->assertDontSee('data-test="card-banner"', false)
        ->assertDontSee('data-test="nip05"', false)
        ->assertDontSee('data-test="card-about"', false)
        ->assertDontSee('data-test="card-clan"', false)
        ->assertDontSee('data-test="card-lud16"', false)
        ->assertDontSee('EINUNDZWANZIG Member')
        ->assertDontSee('No Nostr profile yet')
        // No picture: the Blockpile, with the spec's alt text.
        ->assertSee('alt="rbf_rita avatar, generated"', false)
        ->assertSee(route('avatars.generated', ['pubkey' => $player->pubkey, 'v' => 1]), false);
});

test('the player card of a player without a Nostr profile says so', function () {
    [$player] = knownPlayer(['name' => null]);

    $this->get(route('players.card', $player->npub))
        ->assertOk()
        ->assertSee('No Nostr profile yet')
        ->assertSee('data-test="card-no-profile"', false)
        ->assertDontSee('data-test="card-plays"', false);
});

test('a NIP-05 address the league could not confirm is shown as not verified', function () {
    [$player] = knownPlayer(['name' => 'feebump', 'nip05' => 'feebump@example.com', 'nip05_checked_at' => now(), 'profile_event_at' => now()]);

    $this->get(route('players.card', $player->npub))
        ->assertSee('data-state="unverified"', false)
        ->assertSee('not verified');
});

test('profile text is escaped on the card and the player page', function () {
    [$player] = knownPlayer(['name' => '<script>alert(1)</script>', 'about' => '<img src=x onerror=alert(2)>', 'profile_event_at' => now()]);

    foreach ([route('players.card', $player->npub), route('players.show', $player->npub)] as $url) {
        $this->get($url)
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('<img src=x onerror=alert(2)>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }
});

test('the player page shows the header of a known player and 404s for an unknown npub', function () {
    [$player] = knownPlayer(['name' => 'satsjaeger', 'about' => 'Stay humble, stack sats.', 'website' => 'https://www.example.com/', 'profile_event_at' => now()]);

    $this->get(route('players.show', $player->npub))
        ->assertOk()
        ->assertSee('satsjaeger')
        ->assertSee('Stay humble, stack sats.')
        ->assertSee('data-test="header-website"', false)
        ->assertSee('example.com')
        ->assertSee($player->shortNpub());

    $this->get(route('players.show', 'npub1nobody'))->assertNotFound();
    $this->get(route('players.card', 'npub1nobody'))->assertNotFound();
});

test('the generated avatar is served as a cacheable SVG', function () {
    $pubkey = str_repeat('ab', 32);

    $this->get(route('avatars.generated', ['pubkey' => $pubkey]))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/svg+xml')
        ->assertHeader('Cache-Control', 'immutable, max-age=31536000, public')
        ->assertContent(Blockpile::svg($pubkey));

    $this->get('/avatars/'.str_repeat('AB', 32).'.svg')->assertNotFound();
});

test('an avatar with a picture falls back to the Blockpile when the picture breaks', function () {
    $player = User::factory()->create(['name' => 'max', 'picture' => 'https://example.com/max.png']);

    $html = (string) $this->blade('<x-avatar :user="$user" :size="20" />', ['user' => $player]);

    expect($html)
        ->toContain('src="https://example.com/max.png"')
        ->toContain('width="20"')
        ->toContain('height="20"')
        ->toContain('alt="max avatar"')
        ->toContain('loading="lazy"')
        ->toContain('data-fallback="'.route('avatars.generated', ['pubkey' => $player->pubkey, 'v' => 1]).'"')
        ->toContain('onerror="this.onerror=null;this.src=this.dataset.fallback;');
});

test('an old picture that is not https is never loaded', function () {
    $player = User::factory()->create(['name' => 'max', 'picture' => 'http://example.com/max.png']);

    expect((string) $this->blade('<x-avatar :user="$user" />', ['user' => $player]))
        ->not->toContain('http://example.com/max.png')
        ->toContain('alt="max avatar, generated"');
});

test('only players whose profile was not confirmed within the TTL are asked for again', function () {
    config(['esports.profiles.ttl_minutes' => 60]);
    $fresh = User::factory()->create(['profile_checked_at' => now()->subMinutes(59)]);
    $stale = User::factory()->create(['profile_checked_at' => now()->subMinutes(61)]);
    $never = User::factory()->create(['profile_checked_at' => null]);

    $marked = fn (User $user) => str_contains((string) $this->blade('<x-avatar :user="$user" />', ['user' => $user]), 'data-profile-stale');

    expect($marked($fresh))->toBeFalse()
        ->and($marked($stale))->toBeTrue()
        ->and($marked($never))->toBeTrue();
});
