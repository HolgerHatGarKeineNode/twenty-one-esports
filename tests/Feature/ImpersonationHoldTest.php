<?php

use App\Models\AccountLink;
use App\Models\Admin;
use App\Models\FairPlayBar;
use App\Models\ImpersonationHold;
use App\Models\NostrEvent;
use App\Models\TrustRank;
use App\Models\TrustRun;
use App\Models\User;
use App\Support\Chess\RatedChess;
use App\Support\FairPlay\FairPlay;
use App\Support\FairPlay\Impersonation;
use App\Support\Nostr\ProfileCache;
use App\Support\Nostr\SignedEvent;
use App\Support\SeasonChain\TrustFacts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\TestSigner;
use Tests\Support\TrustedFacts;

/*
|--------------------------------------------------------------------------
| Fair play: the impersonation hold (plan "Profile standard and
| impersonation", P2)
|--------------------------------------------------------------------------
|
| With `esports.fair_play.impersonation.enabled` on, an account whose name
| reads like (confusables folded) or whose picture URL equals that of an
| account with precedence is held from new rated play (FairPlay kind `held`)
| until an admin released it, linked it as the same person or barred it on
| /admin/checks. The player reads only that the account is being checked.
|
*/

beforeEach(function () {
    $this->withoutVite();
    $this->freezeSecond();
    Http::fake(fn () => Http::response([]));
    config(['esports.fair_play.impersonation.enabled' => true]);
});

/** An established player, known to the league for a year. */
function ihOriginal(array $attributes = []): User
{
    return User::factory()->create(['name' => 'Odell', 'picture' => 'https://example.com/odell.png', 'created_at' => now()->subYear(), ...$attributes]);
}

/** A fresh account whose profile arrives signed, through the profile cache as a page or the login hands it in. */
function ihFresh(array $profile, array $attributes = []): User
{
    $signer = new TestSigner;
    $user = User::factory()->withPubkey($signer->pubkey)->create(['name' => null, 'picture' => null, 'created_at' => now()->subDay(), ...$attributes]);
    ProfileCache::acceptEvents([ihProfile($signer, $profile)]);

    return $user->refresh();
}

function ihProfile(TestSigner $signer, array $profile, ?int $at = null): SignedEvent
{
    return SignedEvent::fromInput($signer->sign(0, [], (string) json_encode($profile), $at ?? now()->getTimestamp()));
}

function ihAdmin(): User
{
    $admin = User::factory()->create(['name' => 'Referee', 'picture' => null, 'created_at' => now()->subYears(2)]);
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    return $admin;
}

/** A published trust rank, so the account's reports count from 50 on. */
function ihRank(string $pubkey, int $rank): void
{
    $trust = new TestSigner;
    $anchors = NostrEvent::fromSigned(SignedEvent::fromInput($trust->sign(30000, [['d', 'esports/x/anchors']])));
    $run = TrustRun::query()->create(['trust_pubkey' => $trust->pubkey, 'anchor_list_nostr_event_id' => $anchors->id, 'anchors' => 0, 'lists' => 0, 'ranked' => 1, 'published' => 1, 'computed_at' => now()]);
    $assertion = NostrEvent::fromSigned(SignedEvent::fromInput($trust->sign(30382, [['d', $pubkey], ['p', $pubkey], ['rank', (string) $rank]])));

    TrustRank::query()->create(['pubkey' => $pubkey, 'rank' => $rank, 'raw' => 1.0, 'anchor' => null, 'anchor_share' => null,
        'anchor_list_event_id' => $anchors->event_id, 'trust_run_id' => $run->id, 'nostr_event_id' => $assertion->id, 'event_id' => $assertion->event_id]);
}

test('a fresh account whose name reads like an established player\'s, in confusable letters, is held by name', function (string $lookalike) {
    $original = ihOriginal();
    $fresh = ihFresh(['name' => $lookalike]);

    $hold = ImpersonationHold::query()->sole();

    expect($hold->only(['pubkey', 'matched_pubkey', 'matched', 'name_skeleton', 'picture', 'decision']))
        ->toBe(['pubkey' => $fresh->pubkey, 'matched_pubkey' => $original->pubkey, 'matched' => 'name', 'name_skeleton' => 'odei', 'picture' => null, 'decision' => null])
        ->and(FairPlay::barred([$fresh->pubkey, $original->pubkey]))->toBe([$fresh->pubkey => ['kind' => 'held', 'until' => null, 'main' => null]]);
})->with([
    'Cyrillic О and е' => ['Оdеll'],
    'digits and case' => ['0DE11'],
    'spaces, dots and a diacritic' => ['Ö. dell'],
    'fullwidth letters' => ['Ｏｄｅｌｌ'],
]);

test('a fresh account with the exact picture URL of a player is held by picture, and by both when the name reads alike too', function () {
    $original = ihOriginal();
    $byPicture = ihFresh(['name' => 'Satoshi Fan', 'picture' => 'https://example.com/odell.png']);
    $byBoth = ihFresh(['name' => 'odel', 'picture' => 'https://example.com/odell.png']);

    expect(ImpersonationHold::query()->orderBy('id')->get()->map->only(['pubkey', 'matched_pubkey', 'matched', 'picture'])->all())->toBe([
        ['pubkey' => $byPicture->pubkey, 'matched_pubkey' => $original->pubkey, 'matched' => 'picture', 'picture' => 'https://example.com/odell.png'],
        ['pubkey' => $byBoth->pubkey, 'matched_pubkey' => $original->pubkey, 'matched' => 'both', 'picture' => 'https://example.com/odell.png'],
        // The newer of the two look-alikes is held against the older one too.
        ['pubkey' => $byBoth->pubkey, 'matched_pubkey' => $byPicture->pubkey, 'matched' => 'picture', 'picture' => 'https://example.com/odell.png'],
    ]);
});

test('the login checks the account: a fresh key logging in with a look-alike profile is held', function () {
    $original = ihOriginal();
    $signer = new TestSigner;
    $challenge = $this->postJson(route('auth.nostr.challenge'))->assertOk()->json('challenge');

    $this->postJson(route('auth.nostr.login'), ['event' => $signer->loginEvent($challenge), 'profile' => $signer->sign(0, [], (string) json_encode(['name' => 'Odel1']))])->assertOk();

    expect(ImpersonationHold::query()->sole()->only(['pubkey', 'matched_pubkey']))->toBe(['pubkey' => $signer->pubkey, 'matched_pubkey' => $original->pubkey]);
});

test('no hold for the account itself, an account linked to it, a name shorter than three letters as it reads, or a different name', function (Closure $arrange) {
    $original = ihOriginal(['picture' => null]);
    $arrange($original);

    expect(ImpersonationHold::query()->count())->toBe(0);
})->with([
    'its own profile checked' => [fn (User $original) => expect(Impersonation::check($original))->toBe([])],
    'a second account an admin linked to it' => [function (User $original): void {
        $second = User::factory()->create(['name' => null, 'picture' => null, 'created_at' => now()->subDay()]);
        AccountLink::query()->create(['main_user_id' => $original->id, 'main_pubkey' => $original->pubkey, 'linked_user_id' => $second->id, 'linked_pubkey' => $second->pubkey,
            'linked_by_pubkey' => str_repeat('a', 64), 'reason' => 'same person']);
        $second->forceFill(['name' => 'odell'])->save();
        Impersonation::check($second);
    }],
    'a name that reads as two letters against the same two letters' => [function (User $original): void {
        $original->forceFill(['name' => 'Al'])->save();
        ihFresh(['name' => 'A1']);
    }],
    'an emoji-only name against another emoji-only name' => [function (User $original): void {
        $original->forceFill(['name' => '🟧🟧🟧'])->save();
        ihFresh(['name' => '🟧🟧🟧']);
    }],
    'another name' => [fn (User $original) => ihFresh(['name' => 'Alice'])],
]);

test('only the account without precedence is held: the original\'s own profile change never holds it, and a member keeps precedence over an older non-member', function () {
    $original = ihOriginal();
    $fresh = ihFresh(['name' => 'Odell']);
    expect(Impersonation::check($original))->toBe([]);

    $member = User::factory()->create(['name' => 'Ada', 'picture' => null, 'is_member' => true, 'created_at' => now()]);
    $squatter = User::factory()->create(['name' => 'Ada', 'picture' => null, 'created_at' => now()->subYear()]);

    expect(Impersonation::check($member))->toBe([])
        ->and(collect(Impersonation::check($squatter))->map->matched_pubkey->all())->toBe([$member->pubkey])
        ->and(ImpersonationHold::query()->pluck('pubkey')->all())->toEqualCanonicalizing([$fresh->pubkey, $squatter->pubkey]);
});

test('an account on a member\'s league opponent list has precedence over an older account no member lists', function () {
    openSeason(['slug' => 'season-1', 'genesis_at' => now()->subDays(10), 'ends_at' => now()->addDays(60)], ladders: false);
    $memberKey = new TestSigner;
    $member = User::factory()->withPubkey($memberKey->pubkey)->create(['name' => 'Member', 'is_member' => true, 'created_at' => now()->subYears(2)]);
    $listed = User::factory()->create(['name' => 'Grace', 'picture' => null, 'created_at' => now()]);
    $older = User::factory()->create(['name' => 'Grace', 'picture' => null, 'created_at' => now()->subYear()]);
    $league = (new TestSigner(config('esports.league.nsec')))->pubkey;
    NostrEvent::fromSigned(SignedEvent::fromInput($memberKey->sign(30000, [['d', 'esports/'.$league], ['p', $listed->pubkey]])));

    expect(Impersonation::check($listed))->toBe([])
        ->and(collect(Impersonation::check($older))->map->matched_pubkey->all())->toBe([$listed->pubkey]);
});

test('switch off: a look-alike profile and a login write no hold, an open hold bars nobody, and none of it reads the holds or scans the accounts', function () {
    $original = ihOriginal();
    $open = ImpersonationHold::factory()->create(['matched_user_id' => $original->id]);
    config(['esports.fair_play.impersonation.enabled' => false]);
    $signer = new TestSigner;
    $challenge = $this->postJson(route('auth.nostr.challenge'))->assertOk()->json('challenge');

    DB::enableQueryLog();
    $this->postJson(route('auth.nostr.login'), ['event' => $signer->loginEvent($challenge), 'profile' => $signer->sign(0, [], (string) json_encode(['name' => 'Odell', 'picture' => 'https://example.com/odell.png']))])->assertOk();
    $barred = FairPlay::barred([$open->pubkey, $signer->pubkey]);
    $queries = array_column(DB::getQueryLog(), 'query');
    DB::disableQueryLog();

    expect(ImpersonationHold::query()->count())->toBe(1)
        ->and($barred)->toBe([])
        ->and(FairPlay::message($open->pubkey))->toBeNull()
        ->and(array_values(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'impersonation_holds') || str_contains($sql, '"is_member" from "users"') || str_contains($sql, 'opponent_lists_current'))))->toBe([]);

    $user = User::query()->where('pubkey', $signer->pubkey)->sole();
    DB::flushQueryLog();
    DB::enableQueryLog();
    $checked = Impersonation::check($user);
    expect($checked)->toBe([])->and(DB::getQueryLog())->toBe([]);
});

test('an open hold refuses new rated play with a sentence that names neither the reason nor the other account; the lobby badge says so, not "barred"', function () {
    app()->bind(TrustFacts::class, TrustedFacts::class);
    config(['esports.chess.rated_queue' => true]);
    openSeason(['slug' => 'season-1', 'genesis_at' => now()->subDays(10), 'ends_at' => now()->addDays(60)]);
    $original = ihOriginal();
    $fresh = ihFresh(['name' => 'Odell']);
    $viewer = User::factory()->create(['name' => 'Viewer', 'picture' => null]);

    $own = FairPlay::message($fresh->pubkey, $fresh);
    $other = FairPlay::message($fresh->pubkey, $viewer);
    $reason = app(RatedChess::class)->reason($fresh, 'blitz');

    expect($own)->toBe(__('Your account is being checked. Rated play opens again once the check is done. Casual play stays open.'))
        ->and($other)->toBe(__(':name cannot play rated at the moment.', ['name' => 'Odell']))
        ->and($reason)->toBe(['kind' => 'held', 'message' => $own]);

    foreach ([$own, $other] as $sentence) {
        expect(mb_strtolower((string) $sentence))->not->toContain('imperson')->not->toContain($original->npub)->not->toContain('face')->not->toContain('gesicht');
    }

    app()->setLocale('de');
    expect(FairPlay::message($fresh->pubkey, $fresh))->toStartWith('Dein Konto wird gerade geprüft.');
    app()->setLocale('en');

    Livewire::actingAs($fresh)->test('pages::chess.lobby')
        ->assertSee('account being checked')
        ->assertDontSee('>barred<', false)
        ->assertDontSee('profile not ready');
});

test('admins see the open hold on /admin/checks and both profiles side by side on its decision page; players get 403', function () {
    $admin = ihAdmin();
    $original = ihOriginal(['nip05' => 'odell@example.com', 'nip05_verified_at' => now(), 'is_member' => true]);
    $fresh = ihFresh(['name' => 'Оdеll', 'picture' => 'https://example.com/odell.png']);
    $hold = ImpersonationHold::query()->sole();

    $this->actingAs($admin)->get(route('admin.checks'))->assertOk()
        ->assertSee('data-test="checks-hold"', false)->assertSee(route('admin.checks.hold', $hold), false);

    $page = $this->actingAs($admin)->get(route('admin.checks.hold', $hold))->assertOk();
    $html = $page->getContent();

    expect(substr_count($html, 'data-test="hold-side"'))->toBe(2)
        ->and($html)->toContain('odell@example.com')->toContain($fresh->npub)->toContain($original->npub)->toContain('data-matched="both"');

    $this->actingAs($fresh)->get(route('admin.checks.hold', $hold))->assertForbidden();
});

test('each decision ends the hold with its effect, and nothing is written before the confirm button', function (string $decision, Closure $effect) {
    openSeason(['slug' => 'season-1', 'genesis_at' => now()->subDays(10), 'ends_at' => now()->addDays(60)], ladders: false);
    $admin = ihAdmin();
    $original = ihOriginal();
    $fresh = ihFresh(['name' => 'Odell']);
    ihFresh(['picture' => 'https://example.com/odell.png'], ['created_at' => now()->subHours(2)]);
    $hold = ImpersonationHold::query()->where('pubkey', $fresh->pubkey)->sole();

    $page = Livewire::actingAs($admin)->test('pages::admin.impersonation-hold', ['hold' => $hold])
        ->call('$set', 'decision', $decision)->set('reason', 'Looked into it')->set('until', '');
    expect($hold->refresh()->isOpen())->toBeTrue();

    $page->call('decide')->assertHasNoErrors()->assertSee('data-test="hold-decided"', false);

    expect($hold->refresh()->only(['decision', 'decided_by_pubkey', 'note']))->toBe(['decision' => $decision === 'release' ? 'released' : ($decision === 'link' ? 'linked' : 'barred'), 'decided_by_pubkey' => $admin->pubkey, 'note' => 'Looked into it'])
        ->and(ImpersonationHold::query()->open()->where('pubkey', $fresh->pubkey)->exists())->toBeFalse()
        ->and(ImpersonationHold::query()->open()->count())->toBe(1);
    $effect($fresh, $original);
})->with([
    'release' => ['release', function (User $fresh): void {
        expect(FairPlay::barred([$fresh->pubkey]))->toBe([]);
    }],
    'same person' => ['link', function (User $fresh, User $original): void {
        expect(AccountLink::query()->active()->where('linked_pubkey', $fresh->pubkey)->value('main_pubkey'))->toBe($original->pubkey)
            ->and(FairPlay::barred([$fresh->pubkey])[$fresh->pubkey]['kind'])->toBe('linked');
    }],
    'bar' => ['bar', function (User $fresh): void {
        expect(FairPlayBar::query()->running()->where('pubkey', $fresh->pubkey)->value('reason'))->toBe('Looked into it')
            ->and(FairPlay::barred([$fresh->pubkey])[$fresh->pubkey]['kind'])->toBe('barred');
    }],
]);

test('linking or barring needs a reason, and a hold decided meanwhile is refused', function () {
    $admin = ihAdmin();
    ihOriginal();
    ihFresh(['name' => 'Odell']);
    $hold = ImpersonationHold::query()->sole();

    Livewire::actingAs($admin)->test('pages::admin.impersonation-hold', ['hold' => $hold])
        ->call('$set', 'decision', 'link')->call('decide')->assertHasErrors('decision');
    expect($hold->refresh()->isOpen())->toBeTrue();

    $page = Livewire::actingAs($admin)->test('pages::admin.impersonation-hold', ['hold' => $hold])->call('$set', 'decision', 'release');
    $hold->forceFill(['decided_at' => now(), 'decision' => 'released'])->save();
    $page->call('decide')->assertHasErrors('decision');
});

test('a released match is not raised again for the same name and picture, but a new matching picture raises a hold', function () {
    $original = ihOriginal();
    $signer = new TestSigner;
    $fresh = User::factory()->withPubkey($signer->pubkey)->create(['name' => null, 'picture' => null, 'created_at' => now()->subDay()]);
    ProfileCache::acceptEvents([ihProfile($signer, ['name' => 'Odell'], now()->getTimestamp() - 30)]);
    app(Impersonation::class)->release(ihAdmin(), ImpersonationHold::query()->sole());

    ProfileCache::acceptEvents([ihProfile($signer, ['name' => '0dell'], now()->getTimestamp() - 20)]);
    Impersonation::check($fresh->refresh());
    expect(ImpersonationHold::query()->count())->toBe(1)
        ->and(FairPlay::barred([$fresh->pubkey]))->toBe([]);

    ProfileCache::acceptEvents([ihProfile($signer, ['name' => '0dell', 'picture' => 'https://example.com/odell.png'], now()->getTimestamp() - 10)]);
    expect(ImpersonationHold::query()->open()->sole()->only(['matched_pubkey', 'matched']))->toBe(['matched_pubkey' => $original->pubkey, 'matched' => 'both']);
});

test('the decision page counts impersonation reports against the held account only from accounts ranked 50 or more, each account once', function () {
    $held = User::factory()->create(['name' => 'Held', 'picture' => null]);
    $report = function (int $rank, string $type, int $times = 1) use ($held): void {
        $author = new TestSigner;
        ihRank($author->pubkey, $rank);

        for ($i = 0; $i < $times; $i++) {
            NostrEvent::fromSigned(SignedEvent::fromInput($author->sign(1984, [['p', $held->pubkey, $type], ['L', 'space.einundzwanzig.esports'], ['l', 'abuse', 'space.einundzwanzig.esports']], 'report '.$i, now()->getTimestamp() - $i)));
        }
    };

    $report(50, 'impersonation', 2);
    $report(80, 'impersonation');
    $report(49, 'impersonation');
    $report(90, 'spam');

    expect(Impersonation::reports($held->pubkey))->toBe(2);
});
