<?php

use App\Models\Admin;
use App\Models\ImpersonationHold;
use App\Models\ListedProfile;
use App\Models\NostrEvent;
use App\Models\User;
use App\Support\FairPlay\FairPlay;
use App\Support\FairPlay\Impersonation;
use App\Support\FairPlay\ListedProfiles;
use App\Support\Nostr\RelayReader;
use App\Support\Nostr\SignedEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Support\TestSigner;

/*
|--------------------------------------------------------------------------
| Fair play: profiles of accounts on members' opponent lists (plan "Profile
| standard and impersonation", P3, D13)
|--------------------------------------------------------------------------
|
| Accounts a Verein member lists on the league opponent list that never
| logged in: the league stores their kind-0 profile from the relays, and a
| new account that looks like one of them is held when the league saw the
| key on a member's list before the new account first logged in. A relay
| read that fails holds nobody and never blocks a login.
|
*/

beforeEach(function () {
    $this->withoutVite();
    $this->freezeSecond();
    Http::fake(fn () => Http::response([]));
    config(['esports.fair_play.impersonation.enabled' => true, 'esports.league.nsec' => (new TestSigner)->secret]);

    $this->relays = new class extends RelayReader
    {
        /** @var list<SignedEvent> */
        public array $events = [];

        public int $answered = 1;

        /** @var list<list<array<string, mixed>>> */
        public array $asked = [];

        public function fetchCounted(array $filters, ?array $relays = null, array $known = [], int $perAuthor = 1, ?float $until = null): array
        {
            $this->asked[] = $filters;

            return ['events' => $this->answered === 0 ? [] : $this->events, 'answered' => $this->answered];
        }
    };
    $this->app->instance(RelayReader::class, $this->relays);
});

/** A Verein member and the league key their opponent list is for. */
function lpMember(): TestSigner
{
    $signer = new TestSigner;
    User::factory()->withPubkey($signer->pubkey)->create(['name' => 'Member', 'picture' => null, 'is_member' => true, 'created_at' => now()->subYears(2)]);

    return $signer;
}

/** A member's league opponent list naming these keys, archived now (signed now unless $signedAt says otherwise). */
function lpList(TestSigner $member, array $pubkeys, ?int $signedAt = null): NostrEvent
{
    $league = (new TestSigner(config('esports.league.nsec')))->pubkey;

    return NostrEvent::fromSigned(SignedEvent::fromInput($member->sign(30000, [['d', 'esports/'.$league], ...array_map(fn (string $pubkey): array => ['p', $pubkey], $pubkeys)], '', $signedAt ?? now()->getTimestamp())));
}

function lpProfile(TestSigner $signer, array $profile, ?int $at = null): SignedEvent
{
    return SignedEvent::fromInput($signer->sign(0, [], (string) json_encode($profile), $at ?? now()->getTimestamp()));
}

test('the sync keeps one row per key a member lists, first seen when the league first archived a member\'s list naming it, and marks a key no list names any more', function () {
    $member = lpMember();
    $stranger = new TestSigner;
    $listed = new TestSigner;
    $later = new TestSigner;
    $nobody = new TestSigner;

    // A non-member's list never counts, not even for its first-seen time.
    lpList($stranger, [$listed->pubkey, $nobody->pubkey]);
    $this->travel(3)->days();
    lpList($member, [$listed->pubkey]);
    $firstSeen = now()->toImmutable();
    $this->travel(2)->days();
    lpList($member, [$listed->pubkey, $later->pubkey]);

    expect(ListedProfiles::sync())->toBe(['listed' => 2, 'added' => 2, 'unlisted' => 0])
        ->and(ListedProfile::query()->pluck('first_seen_at', 'pubkey')->map->getTimestamp()->all())
        ->toEqualCanonicalizing([$listed->pubkey => $firstSeen->getTimestamp(), $later->pubkey => now()->getTimestamp()]);

    $this->travel(1)->days();
    lpList($member, [$later->pubkey]);

    expect(ListedProfiles::sync())->toBe(['listed' => 1, 'added' => 0, 'unlisted' => 1])
        ->and(ListedProfile::query()->where('pubkey', $listed->pubkey)->sole()->unlisted_at)->not->toBeNull()
        ->and(ListedProfile::query()->where('pubkey', $listed->pubkey)->sole()->first_seen_at->getTimestamp())->toBe($firstSeen->getTimestamp());
});

test('the refresh stores the newest validly signed profile of a listed key that never logged in, and skips a key that has an account', function () {
    $member = lpMember();
    $listed = new TestSigner;
    $player = User::factory()->create(['name' => 'Player']);
    lpList($member, [$listed->pubkey, $player->pubkey]);
    ListedProfiles::sync();

    $forged = lpProfile($listed, ['name' => 'Forged'])->toArray();
    $forged['sig'] = str_repeat('0', 128);
    $this->relays->events = [
        lpProfile($listed, ['display_name' => 'Grace Hopper', 'name' => 'grace', 'picture' => 'https://example.com/grace.png'], now()->getTimestamp() - 60),
        SignedEvent::fromInput($forged),
    ];

    $read = app(ListedProfiles::class)->refresh();

    expect($read)->toBe(['asked' => 1, 'stored' => 1, 'skipped' => 0, 'failed' => 0, 'held' => 0])
        ->and($this->relays->asked[0][0]['authors'])->toBe([$listed->pubkey])
        ->and(ListedProfile::query()->where('pubkey', $listed->pubkey)->sole()->only(['name', 'name_key', 'picture']))
        ->toBe(['name' => 'Grace Hopper', 'name_key' => 'gracehoper', 'picture' => 'https://example.com/grace.png']);

    // Read within the day: not due again.
    expect(app(ListedProfiles::class)->refresh()['asked'])->toBe(0);
});

test('D13: a new account named like a listed profile is held against it when the league saw the key first; an older account, a member and an unlisted key are not', function () {
    $member = lpMember();
    $listed = new TestSigner;
    lpList($member, [$listed->pubkey]);
    ListedProfiles::sync();
    $this->relays->events = [lpProfile($listed, ['name' => 'Grace', 'picture' => 'https://example.com/grace.png'])];
    app(ListedProfiles::class)->refresh();

    $this->travel(1)->days();
    $copy = User::factory()->create(['name' => 'Grасe', 'picture' => null, 'created_at' => now()]);
    $before = User::factory()->create(['name' => 'Grace', 'picture' => null, 'created_at' => now()->subYear()]);
    $copyingMember = User::factory()->create(['name' => 'Grace', 'picture' => null, 'is_member' => true, 'created_at' => now()]);
    $picture = User::factory()->create(['name' => 'Someone', 'picture' => 'https://example.com/grace.png', 'created_at' => now()]);

    // The copy also reads like the older account and the member, which precede it as well.
    expect(collect(Impersonation::check($copy))->map->only(['matched_user_id', 'matched_pubkey', 'matched'])->firstWhere('matched_pubkey', $listed->pubkey))
        ->toBe(['matched_user_id' => null, 'matched_pubkey' => $listed->pubkey, 'matched' => 'name'])
        ->and(collect(Impersonation::check($before))->pluck('matched_pubkey')->all())->not->toContain($listed->pubkey)
        ->and(collect(Impersonation::check($copyingMember))->pluck('matched_pubkey')->all())->not->toContain($listed->pubkey)
        ->and(collect(Impersonation::check($picture))->map->only(['matched_pubkey', 'matched'])->all())->toBe([['matched_pubkey' => $listed->pubkey, 'matched' => 'picture']])
        ->and(FairPlay::barred([$copy->pubkey])[$copy->pubkey]['kind'] ?? null)->toBe('held');

    // The member drops the key: it is no longer compared.
    ImpersonationHold::query()->delete();
    lpList($member, []);
    ListedProfiles::sync();
    expect(collect(Impersonation::check($copy->refresh()))->pluck('matched_pubkey')->all())->not->toContain($listed->pubkey);
});

test('the decision page shows the profile the league read for a matched account that never logged in', function () {
    $member = lpMember();
    $listed = new TestSigner;
    lpList($member, [$listed->pubkey]);
    ListedProfiles::sync();
    $this->relays->events = [lpProfile($listed, ['name' => 'Grace', 'picture' => 'https://example.com/grace.png'])];
    app(ListedProfiles::class)->refresh();
    $this->travel(1)->days();
    $copy = User::factory()->create(['name' => 'Grace', 'picture' => null, 'created_at' => now()]);
    $hold = Impersonation::check($copy)[0];
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    $this->actingAs($admin)->get(route('admin.checks.hold', $hold))->assertOk()
        ->assertSee('https://example.com/grace.png')
        ->assertSee('data-test="hold-side-listed"', false);
});

test('a logged-in account the league saw on a member\'s list before a look-alike appeared keeps precedence over it, though it logged in later', function () {
    $member = lpMember();
    $original = new TestSigner;
    lpList($member, [$original->pubkey]);
    ListedProfiles::sync();

    $this->travel(30)->days();
    $squatter = User::factory()->create(['name' => 'Grace', 'picture' => null, 'created_at' => now()]);
    $this->travel(30)->days();
    $late = User::factory()->withPubkey($original->pubkey)->create(['name' => 'Grace', 'picture' => null, 'created_at' => now()]);

    expect(Impersonation::check($late))->toBe([])
        ->and(collect(Impersonation::check($squatter))->pluck('matched_pubkey')->all())->toBe([$late->pubkey]);
});

test('a profile that arrives later holds a look-alike that logged in before it', function () {
    $member = lpMember();
    $listed = new TestSigner;
    lpList($member, [$listed->pubkey]);
    ListedProfiles::sync();
    $this->travel(1)->days();
    $copy = User::factory()->create(['name' => 'Grace', 'picture' => null, 'created_at' => now()]);
    expect(Impersonation::check($copy))->toBe([]);

    $this->relays->events = [lpProfile($listed, ['name' => 'Grace'])];

    expect(app(ListedProfiles::class)->refresh()['held'])->toBe(1)
        ->and(ImpersonationHold::query()->open()->sole()->only(['pubkey', 'matched_pubkey']))->toBe(['pubkey' => $copy->pubkey, 'matched_pubkey' => $listed->pubkey]);
});

test('a read no relay answered stores nothing, is reported, holds nobody, and a look-alike still logs in', function () {
    Log::spy();
    $member = lpMember();
    $listed = new TestSigner;
    lpList($member, [$listed->pubkey]);
    ListedProfiles::sync();
    $this->relays->answered = 0;

    $this->artisan('fair-play:listed-profiles')->expectsOutputToContain('found no relay answering')->assertSuccessful();

    expect(ListedProfile::query()->sole()->only(['name', 'checked_at']))->toBe(['name' => null, 'checked_at' => null]);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'no relay answered'))->once();

    $signer = new TestSigner;
    $challenge = $this->postJson(route('auth.nostr.challenge'))->assertOk()->json('challenge');
    $this->postJson(route('auth.nostr.login'), ['event' => $signer->loginEvent($challenge), 'profile' => $signer->sign(0, [], (string) json_encode(['name' => 'Grace']))])->assertOk();

    expect(ImpersonationHold::query()->count())->toBe(0);
});

test('first seen is when the league archived the list, never the time the member signed it: a list backdated to 2020 gives the key no precedence over an account that logged in before the league saw it', function () {
    $member = lpMember();
    $listed = new TestSigner;
    $squatter = User::factory()->create(['name' => 'Grace', 'picture' => null, 'created_at' => now()->subDays(10)]);

    lpList($member, [$listed->pubkey], CarbonImmutable::parse('2020-01-01 12:00:00')->getTimestamp());
    $archivedAt = NostrEvent::query()->where('pubkey', $member->pubkey)->sole()->created_at->getTimestamp();
    ListedProfiles::sync();
    $this->relays->events = [lpProfile($listed, ['name' => 'Grace'])];
    app(ListedProfiles::class)->refresh();

    expect(ListedProfile::query()->sole()->first_seen_at->getTimestamp())->toBe($archivedAt)
        ->and($archivedAt)->toBe(now()->getTimestamp())
        ->and(Impersonation::check($squatter))->toBe([]);
});

test('a name NFKC spells out past the longest key (100 x U+FDFA = 1400 letters) is stored without a key, and a profile the database refuses is reported, checked and never holds back its batch', function () {
    Log::spy();
    $member = lpMember();
    $long = new TestSigner;
    $refused = new TestSigner;
    $plain = new TestSigner;
    lpList($member, [$long->pubkey, $refused->pubkey, $plain->pubkey]);
    ListedProfiles::sync();
    $this->relays->events = [
        lpProfile($long, ['name' => str_repeat("\u{FDFA}", 100)]),
        lpProfile($refused, ['name' => 'Refused']),
        lpProfile($plain, ['name' => 'Grace']),
    ];
    // The database refuses this one row (on Postgres: 22001 and the like).
    ListedProfile::saving(function (ListedProfile $row) use ($refused): void {
        if ($row->pubkey === $refused->pubkey) {
            throw new RuntimeException('value too long for type character varying(100)');
        }
    });

    $read = app(ListedProfiles::class)->refresh();

    expect(Impersonation::comparable(Impersonation::nameKey(str_repeat("\u{FDFA}", 100))))->toBeFalse()
        ->and($read)->toBe(['asked' => 3, 'stored' => 2, 'skipped' => 1, 'failed' => 0, 'held' => 0])
        ->and(ListedProfile::query()->where('pubkey', $long->pubkey)->sole()->only(['name', 'name_key']))->toBe(['name' => str_repeat("\u{FDFA}", 100), 'name_key' => null])
        ->and(ListedProfile::query()->where('pubkey', $plain->pubkey)->sole()->name_key)->toBe('grace')
        ->and(ListedProfile::query()->whereNull('checked_at')->count())->toBe(0)
        ->and(app(ListedProfiles::class)->refresh()['asked'])->toBe(0);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []): bool => str_contains($message, 'could not be stored') && ($context['pubkey'] ?? null) === $refused->pubkey)->once();
});
