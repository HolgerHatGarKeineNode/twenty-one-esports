<?php

use App\Models\AccountLink;
use App\Models\Admin;
use App\Models\Clan;
use App\Models\FairPlayBar;
use App\Models\HyperMatch;
use App\Models\ImpersonationHold;
use App\Models\Lineup;
use App\Models\NostrEvent;
use App\Models\TrustRank;
use App\Models\TrustRun;
use App\Models\User;
use App\Support\Board\RatedBoard;
use App\Support\Chess\RatedChess;
use App\Support\FairPlay\FairPlay;
use App\Support\FairPlay\Impersonation;
use App\Support\Hyper\HyperLobby;
use App\Support\Hyper\HyperRuleViolation;
use App\Support\Nostr\ProfileCache;
use App\Support\Nostr\SignedEvent;
use App\Support\SeasonChain\RatedTrustGate;
use App\Support\SeasonChain\TrustFacts;
use App\Support\Series\ChallengeDraft;
use App\Support\Series\SeriesRuleViolation;
use App\Support\Series\SeriesService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\Support\HyperOn;
use Tests\Support\NineMensMorrisOn;
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

test('the login also checks an account whose cached profile did not change, so accounts known before the switch went on are caught', function () {
    $original = ihOriginal();
    $signer = new TestSigner;
    User::factory()->withPubkey($signer->pubkey)->create(['name' => 'Odel1', 'picture' => null, 'created_at' => now()->subDay()]);
    expect(ImpersonationHold::query()->count())->toBe(0);
    $challenge = $this->postJson(route('auth.nostr.challenge'))->assertOk()->json('challenge');

    $this->postJson(route('auth.nostr.login'), ['event' => $signer->loginEvent($challenge)])->assertOk();

    expect(ImpersonationHold::query()->sole()->only(['pubkey', 'matched_pubkey']))->toBe(['pubkey' => $signer->pubkey, 'matched_pubkey' => $original->pubkey]);
});

test('a name in CJK letters gives a valid UTF-8 key and is held like any other, and a failing check never blocks the login', function () {
    expect(mb_check_encoding(Impersonation::nameKey('ニッキー'), 'UTF-8'))->toBeTrue()
        ->and(Impersonation::nameKey('ニッキー'))->toBe('ニッキー');

    $original = ihOriginal(['name' => 'ニッキー', 'picture' => null]);
    $fresh = ihFresh(['name' => 'ニッキー']);
    expect(ImpersonationHold::query()->sole()->only(['pubkey', 'matched_pubkey', 'name_skeleton']))->toBe(['pubkey' => $fresh->pubkey, 'matched_pubkey' => $original->pubkey, 'name_skeleton' => 'ニッキー']);

    ImpersonationHold::query()->delete();
    DB::listen(function ($query): void {
        if (str_contains($query->sql, 'impersonation_holds')) {
            throw new RuntimeException('detection broke');
        }
    });
    $signer = new TestSigner;
    User::factory()->withPubkey($signer->pubkey)->create(['name' => 'ニッキー', 'picture' => null, 'created_at' => now()->subHour()]);
    $challenge = $this->postJson(route('auth.nostr.challenge'))->assertOk()->json('challenge');

    $this->postJson(route('auth.nostr.login'), ['event' => $signer->loginEvent($challenge)])->assertOk();
    $this->assertAuthenticated();
});

test('a name NFKC spells out is compared up to the longest key and stored in full (10 x U+FDFA = 140 letters), and past it (100 x U+FDFA = 1400 letters) the name is not compared while the picture still is', function () {
    expect(Schema::getColumnType('impersonation_holds', 'name_skeleton'))->toBe('text')
        ->and(Schema::getColumnType('listed_profiles', 'name_key'))->toBe('text');

    $original = ihOriginal(['name' => str_repeat("\u{FDFA}", 10), 'picture' => null]);
    $fresh = ihFresh(['name' => str_repeat("\u{FDFA}", 10)]);
    $hold = ImpersonationHold::query()->sole();
    expect($hold->only(['pubkey', 'matched_pubkey', 'matched']))->toBe(['pubkey' => $fresh->pubkey, 'matched_pubkey' => $original->pubkey, 'matched' => 'name'])
        ->and(mb_strlen((string) $hold->name_skeleton))->toBe(140);

    ImpersonationHold::query()->delete();
    $long = ihOriginal(['name' => str_repeat("\u{FDFA}", 100), 'picture' => 'https://example.com/long.png']);
    $copy = ihFresh(['name' => str_repeat("\u{FDFA}", 100), 'picture' => 'https://example.com/long.png']);

    expect($copy->name)->toBe(str_repeat("\u{FDFA}", 100))
        ->and(ImpersonationHold::query()->sole()->only(['pubkey', 'matched_pubkey', 'matched', 'name_skeleton']))
        ->toBe(['pubkey' => $copy->pubkey, 'matched_pubkey' => $long->pubkey, 'matched' => 'picture', 'name_skeleton' => null]);
});

test('Q1: an old account that renames to the name of an account that had it first is held, and the original\'s own later refresh never holds it', function () {
    $originalSigner = new TestSigner;
    $original = ihOriginal(['pubkey' => $originalSigner->pubkey, 'picture' => null]);
    $oldSigner = new TestSigner;
    $old = User::factory()->withPubkey($oldSigner->pubkey)->create(['name' => null, 'picture' => null, 'created_at' => now()->subYears(2)]);

    // Its first name counts from the time the league knows it: two years, older than the original.
    ProfileCache::acceptEvents([ihProfile($oldSigner, ['name' => 'Bob'], now()->getTimestamp() - 60)]);
    expect(ImpersonationHold::query()->count())->toBe(0)
        ->and(DB::table('profile_values_seen')->where('pubkey', $old->pubkey)->sole()->first_seen_at)->toBe($old->created_at->toDateTimeString());

    $this->travel(1)->days();
    ProfileCache::acceptEvents([ihProfile($oldSigner, ['name' => 'Odell'])]);

    expect(ImpersonationHold::query()->open()->sole()->only(['pubkey', 'matched_pubkey', 'matched']))
        ->toBe(['pubkey' => $old->pubkey, 'matched_pubkey' => $original->pubkey, 'matched' => 'name']);

    // The original refreshes its profile (a picture, then the same name written anew): it is never held against the renamed account.
    $this->travel(1)->days();
    ProfileCache::acceptEvents([ihProfile($originalSigner, ['name' => 'Odell', 'picture' => 'https://example.com/odell.png'])]);
    $this->travel(1)->minute();
    ProfileCache::acceptEvents([ihProfile($originalSigner, ['name' => 'ODELL', 'picture' => 'https://example.com/odell.png'])]);

    expect(ImpersonationHold::query()->where('pubkey', $original->pubkey)->count())->toBe(0)
        ->and(ImpersonationHold::query()->count())->toBe(1);
});

test('Q1: an old account whose first stored profile had no usable name (none, or too short) carries a name it takes later from then, not from its registration: it is held, and the original never is', function (array $firstProfile) {
    $originalSigner = new TestSigner;
    $original = ihOriginal(['pubkey' => $originalSigner->pubkey, 'picture' => null]);
    $sleeperSigner = new TestSigner;
    $sleeper = User::factory()->withPubkey($sleeperSigner->pubkey)->create(['name' => null, 'picture' => null, 'created_at' => now()->subYears(2)]);
    ProfileCache::acceptEvents([ihProfile($sleeperSigner, $firstProfile, now()->getTimestamp() - 60)]);

    $this->travel(1)->days();
    ProfileCache::acceptEvents([ihProfile($sleeperSigner, ['name' => 'Odell'])]);

    expect(ImpersonationHold::query()->open()->sole()->only(['pubkey', 'matched_pubkey']))->toBe(['pubkey' => $sleeper->pubkey, 'matched_pubkey' => $original->pubkey])
        ->and(DB::table('profile_values_seen')->where('pubkey', $sleeper->pubkey)->where('kind', 'name')->sole()->first_seen_at)->toBe(now()->toDateTimeString());

    $this->travel(1)->days();
    ProfileCache::acceptEvents([ihProfile($originalSigner, ['name' => 'Odell', 'picture' => 'https://example.com/odell.png'])]);
    expect(ImpersonationHold::query()->where('pubkey', $original->pubkey)->count())->toBe(0);
})->with([
    'no name' => [['about' => 'just here']],
    'too short' => [['name' => 'xy']],
]);

test('Q1: an old account that takes over the picture of an account that showed it first is held for the picture; a Verein member that renames is not (D12)', function () {
    $original = ihOriginal(['picture' => 'https://example.com/odell.png']);
    $oldSigner = new TestSigner;
    $old = User::factory()->withPubkey($oldSigner->pubkey)->create(['name' => 'Bob', 'picture' => 'https://example.com/bob.png', 'created_at' => now()->subYears(2)]);
    $memberSigner = new TestSigner;
    User::factory()->withPubkey($memberSigner->pubkey)->create(['name' => 'Carol', 'picture' => null, 'is_member' => true, 'created_at' => now()->subYears(2)]);

    ProfileCache::acceptEvents([ihProfile($oldSigner, ['name' => 'Bob', 'picture' => 'https://example.com/odell.png'])]);
    ProfileCache::acceptEvents([ihProfile($memberSigner, ['name' => 'Odell'])]);

    expect(ImpersonationHold::query()->open()->get()->map->only(['pubkey', 'matched_pubkey', 'matched'])->all())
        ->toBe([['pubkey' => $old->pubkey, 'matched_pubkey' => $original->pubkey, 'matched' => 'picture']]);
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

test('D12: an account on a member\'s league opponent list has no precedence of its own: the older account wins, and only Verein membership beats age', function () {
    openSeason(['slug' => 'season-1', 'genesis_at' => now()->subDays(10), 'ends_at' => now()->addDays(60)], ladders: false);
    $memberKey = new TestSigner;
    User::factory()->withPubkey($memberKey->pubkey)->create(['name' => 'Member', 'is_member' => true, 'created_at' => now()->subYears(2)]);
    $listed = User::factory()->create(['name' => 'Grace', 'picture' => null, 'created_at' => now()]);
    $older = User::factory()->create(['name' => 'Grace', 'picture' => null, 'created_at' => now()->subYear()]);
    $league = (new TestSigner(config('esports.league.nsec')))->pubkey;
    NostrEvent::fromSigned(SignedEvent::fromInput($memberKey->sign(30000, [['d', 'esports/'.$league], ['p', $listed->pubkey]])));

    expect(Impersonation::check($older))->toBe([])
        ->and(collect(Impersonation::check($listed))->map->matched_pubkey->all())->toBe([$older->pubkey]);
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

test('every other place that offers rated play refuses a held player with the same sentence: the trust gate and its pin, a rated challenge and its accept, rated tables, the rated board queue; casual stays open', function () {
    app()->bind(TrustFacts::class, TrustedFacts::class);
    HyperOn::play();
    NineMensMorrisOn::play();
    config(['esports.board_games.rated_queue' => true]);
    openSeason(['slug' => 'season-1', 'genesis_at' => now()->subDays(10), 'ends_at' => now()->addDays(60)]);
    ihOriginal();
    $captain = ihFresh(['name' => 'Odell']);
    $otherSigner = new TestSigner;
    $other = User::factory()->withPubkey($otherSigner->pubkey)->create(['name' => 'Viewer', 'picture' => null]);
    $own = __('Your account is being checked. Rated play opens again once the check is done. Casual play stays open.');
    $gate = app(RatedTrustGate::class);
    $series = app(SeriesService::class);
    $lineup = fn (User $owner): Lineup => Lineup::factory()->game('rocket-league', '3v3')->ready()->create(['clan_id' => Clan::factory()->create(['owner_id' => $owner->id])->id])->load('clan', 'seats.user');
    $mine = $lineup($captain);
    $theirs = $lineup($other);
    $start = now()->addHour()->startOfMinute()->getTimestamp();
    $draft = fn (bool $rated): ChallengeDraft => new ChallengeDraft($mine->id, $theirs->id, 3, $rated, [$start, $start + 3600], $start - 600, null);
    $this->actingAs($captain);

    expect(FairPlay::barred([$captain->pubkey])[$captain->pubkey]['kind'])->toBe('held')
        ->and($gate->refusal([$captain->pubkey, $other->pubkey], [$captain->pubkey, $other->pubkey]))->toBe(RatedTrustGate::FAIR_PLAY)
        ->and($gate->pin([$captain->pubkey, $other->pubkey], [$other->pubkey, $other->pubkey])->isEligible($captain->pubkey))->toBeFalse()
        ->and(RatedTrustGate::message(RatedTrustGate::FAIR_PLAY, [$other->pubkey, $captain->pubkey]))->toBe($own)
        ->and(fn () => $series->prepareChallenge($captain, $draft(true)))->toThrow(SeriesRuleViolation::class, $own)
        ->and($series->prepareChallenge($captain, $draft(false))['templates'])->toBe([])
        ->and(fn () => app(HyperLobby::class)->open($captain, 2, HyperMatch::LIVE, 0))->toThrow(HyperRuleViolation::class, $own)
        ->and(app(RatedBoard::class)->refusal($captain, 'nine-mens-morris', 'correspondence'))->toBe($own);

    // The challenge wizard's step 3 shows the sentence where rated would be offered.
    Livewire::actingAs($captain)->test('pages::challenges.create')
        ->call('next')->call('pickOpponent', $theirs->id)->call('next')->assertSet('step', 3)
        ->assertSeeHtml('data-test="rated-fair-play"')->assertSee($own);

    // A rated challenge sent to the player before the hold counted (switch off), answered once it counts.
    config(['esports.fair_play.impersonation.enabled' => false]);
    $incoming = new ChallengeDraft($theirs->id, $mine->id, 3, true, [$start, $start + 3600], $start - 600, null);
    $sent = $series->challenge($other, $incoming, $otherSigner->signTemplates($series->prepareChallenge($other, $incoming)['templates']));
    config(['esports.fair_play.impersonation.enabled' => true]);

    expect(fn () => $series->prepareAnswer($sent, $captain, 'accepted', $start))->toThrow(SeriesRuleViolation::class, $own);

    // Another captain reads only that the player cannot play rated now.
    $this->actingAs($other);
    expect(RatedTrustGate::message(RatedTrustGate::FAIR_PLAY, [$captain->pubkey]))->toBe('Odell cannot play rated at the moment.');
});

test('the opponents page tells a held player that the account is being checked, above any missing profile step, and drops it once released', function () {
    config(['esports.fair_play.profile_standard.enabled' => true]);
    ihOriginal();
    $fresh = ihFresh(['name' => 'Odell']);
    $notice = __('Your account is being checked. Rated play opens again once the check is done.');

    // Held and below the standard (a day old, no NIP-05, no picture): the notice first, then the steps.
    $html = Livewire::actingAs($fresh)->test('pages::settings.opponents')->assertSeeHtml('data-test="rated-held"')->assertSee($notice)->html();
    expect(strpos($html, 'data-test="rated-held"'))->toBeLessThan(strpos($html, 'data-test="rated-standard-nip05"'))
        ->and(mb_strtolower($html))->not->toContain('imperson')->not->toContain('gesicht');

    app()->setLocale('de');
    Livewire::actingAs($fresh)->test('pages::settings.opponents')->assertSee('Dein Konto wird gerade geprüft.');
    app()->setLocale('en');

    ImpersonationHold::query()->update(['decided_at' => now(), 'decision' => ImpersonationHold::RELEASED]);
    Livewire::actingAs($fresh)->test('pages::settings.opponents')->assertDontSeeHtml('data-test="rated-held"')->assertSeeHtml('data-test="rated-standard-nip05"');
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

test('Q4: a released picture match is not raised again for the same picture, but a different picture of the same original is', function () {
    $original = ihOriginal();
    $signer = new TestSigner;
    $fresh = User::factory()->withPubkey($signer->pubkey)->create(['name' => null, 'picture' => null, 'created_at' => now()->subDay()]);
    ProfileCache::acceptEvents([ihProfile($signer, ['name' => 'Freshman', 'picture' => 'https://example.com/odell.png'], now()->getTimestamp() - 30)]);
    $hold = ImpersonationHold::query()->sole();
    expect($hold->only(['matched_pubkey', 'matched', 'picture']))->toBe(['matched_pubkey' => $original->pubkey, 'matched' => 'picture', 'picture' => 'https://example.com/odell.png']);

    app(Impersonation::class)->release(ihAdmin(), $hold);
    ProfileCache::acceptEvents([ihProfile($signer, ['name' => 'Freshman Two', 'picture' => 'https://example.com/odell.png'], now()->getTimestamp() - 20)]);
    Impersonation::check($fresh->refresh());
    expect(ImpersonationHold::query()->count())->toBe(1)
        ->and(FairPlay::barred([$fresh->pubkey]))->toBe([]);

    $original->forceFill(['picture' => 'https://example.com/odell-2026.png'])->save();
    ProfileCache::acceptEvents([ihProfile($signer, ['name' => 'Freshman Two', 'picture' => 'https://example.com/odell-2026.png'], now()->getTimestamp() - 10)]);
    expect(ImpersonationHold::query()->open()->sole()->only(['matched_pubkey', 'matched', 'picture']))
        ->toBe(['matched_pubkey' => $original->pubkey, 'matched' => 'picture', 'picture' => 'https://example.com/odell-2026.png'])
        ->and(FairPlay::barred([$fresh->pubkey])[$fresh->pubkey]['kind'] ?? null)->toBe('held');
});

test('a bar ends the hold: the next check raises no new hold while the bar runs, and raises it again once the bar ended', function () {
    ihOriginal();
    $fresh = ihFresh(['name' => 'Odell']);
    app(Impersonation::class)->bar(ihAdmin(), ImpersonationHold::query()->sole(), 'Copies Odell', now()->addDays(3)->toImmutable());

    Impersonation::check($fresh->refresh());
    expect(ImpersonationHold::query()->open()->count())->toBe(0);

    $this->travel(4)->days();
    Impersonation::check($fresh->refresh());
    expect(ImpersonationHold::query()->open()->where('pubkey', $fresh->pubkey)->count())->toBe(1);
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
