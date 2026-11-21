<?php

use App\Models\Clan;
use App\Models\ImpersonationHold;
use App\Models\Lineup;
use App\Models\SeasonAttestation;
use App\Models\User;
use App\Support\FairPlay\AcceptWarnings;
use App\Support\FairPlay\FairPlay;
use App\Support\FairPlay\ProfileStandard;
use App\Support\LeagueTime;
use App\Support\SeasonChain\Opponents;
use App\Support\SeasonChain\TrustFacts;
use App\Support\Series\ChallengeDraft;
use App\Support\Series\SeriesService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\TestSigner;

/*
|--------------------------------------------------------------------------
| The warning when a member accepts (plan "Profile standard and
| impersonation", P4)
|--------------------------------------------------------------------------
|
| A request on /settings/opponents from an account below the profile
| standard, or with an open impersonation hold, carries a warning, and the
| accept then needs the explicit confirm (`prepareConfirmedAdd`): the plain
| `prepareAdd` refuses. Each part follows its switch and the exemption; a
| hold says only that the account is being checked. The accept still makes
| the lists mutual, and rated play stays barred until the standard is met.
| D17: the same warning and confirm on the opponent button, which is the
| player page's "Add as opponent" and the match room's accept of the
| sender's request (inline, inside the "do not list each other" notice).
|
*/

beforeEach(function () {
    Queue::fake();
    $this->freezeSecond();
    $league = new TestSigner;
    config(['esports.league.nsec' => $league->secret]);
    config(['esports.fair_play.profile_standard.enabled' => true, 'esports.fair_play.profile_standard.since' => null, 'esports.fair_play.impersonation.enabled' => true]);

    $this->aliceSigner = new TestSigner;
    $this->alice = User::factory()->withPubkey($this->aliceSigner->pubkey)->create(['name' => 'alice']);
});

/** A requester who meets all three hard marks, unless `$attributes` takes one away, and who has added alice. */
function awRequester(array $attributes = []): User
{
    $signer = new TestSigner;
    $player = User::factory()->withPubkey($signer->pubkey)->create([
        'name' => 'bob',
        'picture' => 'https://example.com/bob.png',
        'nip05' => 'bob@example.com',
        'nip05_verified_at' => now()->subDays(20),
        'created_at' => now()->subDays(8),
        ...$attributes,
    ]);
    $opponents = app(Opponents::class);
    $opponents->add($player, test()->alice, $signer->signTemplates($opponents->prepareAdd($player, test()->alice)));
    test()->travel(1)->seconds();

    return $player;
}

function awHold(User $held, User $original): ImpersonationHold
{
    return ImpersonationHold::factory()->create(['user_id' => $held->id, 'pubkey' => $held->pubkey, 'matched_user_id' => $original->id, 'matched_pubkey' => $original->pubkey]);
}

/** The requester's card, from its opening tag to its profile link. */
function awCard(string $html, User $requester): string
{
    return str($html)->after('data-pubkey="'.$requester->pubkey.'"')->before('data-test="opponent-request-profile"')->toString();
}

/**
 * The warning lines on the requester's card, by their data-test marker.
 *
 * @return list<string>
 */
function awLines(string $html, User $requester): array
{
    preg_match_all('/data-test="opponent-request-warning-(\w+)"/', awCard($html, $requester), $matches);

    return $matches[1];
}

test('each missing hard mark alone shows its line in plain words, behind a confirm step', function (array $without, string $mark, string $words) {
    $bob = awRequester($without);

    $page = Livewire::actingAs($this->alice)->test('pages::settings.opponents');
    $card = awCard($page->html(), $bob);

    expect(awLines($page->html(), $bob))->toBe([$mark])
        ->and($card)->toContain('data-test="opponent-request-confirm"')->toContain('prepareConfirmedAdd')->not->toContain("run('prepareAdd'");
    $page->assertSeeInOrder([' cannot play rated yet:', $words, 'You can still accept.']);
})->with([
    'known for 6 days only' => [['created_at' => now()->subDays(6)], 'known', 'New here: an account plays rated from 7 days after its first login'],
    'no NIP-05' => [['nip05' => null, 'nip05_verified_at' => null], 'nip05', 'No NIP-05 address set.'],
    'no picture' => [['picture' => null], 'profile', 'No profile picture'],
    'empty name' => [['name' => ' '], 'profile', 'No name in the profile'],
    'no name and no picture' => [['name' => '', 'picture' => null], 'profile', 'No name and no profile picture'],
]);

test('the known line names the moment the account reaches the standard, and every missing mark shows in the player\'s step order, in English and German', function () {
    $bob = awRequester(['created_at' => now()->subDays(2), 'nip05' => null, 'nip05_verified_at' => null, 'picture' => null]);

    $page = Livewire::actingAs($this->alice)->test('pages::settings.opponents');

    expect(awLines($page->html(), $bob))->toBe(['known', 'nip05', 'profile']);
    $page->assertSee(LeagueTime::stamp(ProfileStandard::knownAt($bob)));

    app()->setLocale('de');
    Livewire::actingAs($this->alice)->test('pages::settings.opponents')
        ->assertSeeInOrder(['bob kann noch nicht gewertet spielen:', 'Neu hier: Ein Konto spielt ab 7 Tagen', 'Keine NIP-05-Adresse festgelegt.', 'Kein Profilbild', 'Du kannst trotzdem annehmen.', 'Trotzdem annehmen']);
});

test('a hold reads only that the account is being checked: never whom it resembles, never why', function () {
    $original = User::factory()->create(['name' => 'Odell', 'created_at' => now()->subYear()]);
    $bob = awRequester(['name' => 'Odeli']);
    awHold($bob, $original);

    foreach (['en' => 'This account is being checked.', 'de' => 'Dieses Konto wird gerade geprüft.'] as $locale => $sentence) {
        app()->setLocale($locale);
        $html = Livewire::actingAs($this->alice)->test('pages::settings.opponents')->html();
        $card = mb_strtolower(awCard($html, $bob));

        expect(awLines($html, $bob))->toBe(['held'])
            ->and($card)->toContain(mb_strtolower($sentence))
            ->and($card)->not->toContain('odell')->not->toContain('imperson')->not->toContain('identit')->not->toContain('face')->not->toContain('gesicht')
            ->and($card)->not->toContain('resembl')->not->toContain('ähnel');
    }
});

test('no warning and the one-click accept as before when the standard is met, while both switches are off, or for an exempt player', function () {
    $met = awRequester(['name' => 'met']);
    $page = Livewire::actingAs($this->alice)->test('pages::settings.opponents');
    expect(awLines($page->html(), $met))->toBe([])
        ->and(awCard($page->html(), $met))->toContain("run('prepareAdd'")->not->toContain('prepareConfirmedAdd')->not->toContain('opponent-request-confirm');

    // Switches off: an account lacking every mark and under a hold reads no warning.
    config(['esports.fair_play.profile_standard.enabled' => false, 'esports.fair_play.impersonation.enabled' => false]);
    $bare = awRequester(['name' => '', 'picture' => null, 'nip05' => null, 'nip05_verified_at' => null, 'created_at' => now()]);
    awHold($bare, $met);
    Livewire::actingAs($this->alice)->test('pages::settings.opponents')->assertDontSeeHtml('data-test="opponent-request-warning"');

    // Each switch on its own brings back its own part (control for the line above).
    config(['esports.fair_play.impersonation.enabled' => true]);
    expect(awLines(Livewire::actingAs($this->alice)->test('pages::settings.opponents')->html(), $bare))->toBe(['held']);
    config(['esports.fair_play.impersonation.enabled' => false, 'esports.fair_play.profile_standard.enabled' => true]);
    expect(awLines(Livewire::actingAs($this->alice)->test('pages::settings.opponents')->html(), $bare))->toBe(['known', 'nip05', 'profile']);
});

test('a player who played rated in the live season before the switch-on is exempt and reads no warning', function () {
    $season = openSeason(['slug' => 'season-1', 'genesis_at' => now()->subDays(30), 'ends_at' => now()->addDays(60)]);
    $veteran = awRequester(['name' => 'veteran', 'nip05' => null, 'nip05_verified_at' => null]);
    $newcomer = awRequester(['name' => 'newcomer', 'nip05' => null, 'nip05_verified_at' => null]);
    SeasonAttestation::query()->create([
        'season_id' => $season->id, 'source' => 'chess', 'source_id' => 900_001, 'label' => '#1',
        'game' => 'chess', 'mode' => 'blitz', 'ladder_address' => 'chess/blitz', 'attested_at' => now()->subDays(3),
        'candidate' => ['game' => 'chess', 'winners' => [$veteran->pubkey], 'losers' => [$this->alice->pubkey]],
        'era' => 1, 'event_id' => hash('sha256', 'accept-warning-1'),
    ]);

    $html = Livewire::actingAs($this->alice)->test('pages::settings.opponents')->html();

    expect(awLines($html, $veteran))->toBe([])
        ->and(awLines($html, $newcomer))->toBe(['nip05']);
});

test('with a warning the plain accept is refused; after the confirm it is the signed add, and rated play still waits for the standard', function () {
    $bob = awRequester(['nip05' => null, 'nip05_verified_at' => null]);
    $page = Livewire::actingAs($this->alice)->test('pages::settings.opponents');

    // One click is not enough: no templates, the reason on the page.
    $page->call('prepareAdd', $bob->pubkey)->assertHasErrors('opponents')->assertReturned(null)
        ->assertSee('Read the note on this request first, then confirm the accept.');

    // Confirmed: signed and submitted like any accept, the lists are mutual.
    $templates = $page->instance()->prepareConfirmedAdd($bob->pubkey, app(Opponents::class));
    expect($templates)->toBeArray()->not->toBeEmpty();
    $page->call('add', $bob->pubkey, json_encode($this->aliceSigner->signTemplates($templates)))
        ->assertSee('1 listed, 1 list you back');

    expect(app(Opponents::class)->listEachOther($this->alice, $bob))->toBeTrue()
        ->and(FairPlay::barred([$bob->pubkey])[$bob->pubkey]['kind'] ?? null)->toBe('standard');
});

test('a held requester accepted after the confirm stays out of rated play until the hold ends', function () {
    $original = User::factory()->create(['name' => 'Odell', 'created_at' => now()->subYear()]);
    $bob = awRequester();
    awHold($bob, $original);
    $page = Livewire::actingAs($this->alice)->test('pages::settings.opponents');

    $page->call('prepareAdd', $bob->pubkey)->assertHasErrors('opponents')->assertReturned(null);
    $templates = $page->instance()->prepareConfirmedAdd($bob->pubkey, app(Opponents::class));
    $page->call('add', $bob->pubkey, json_encode($this->aliceSigner->signTemplates($templates)));

    expect(app(Opponents::class)->listEachOther($this->alice, $bob))->toBeTrue()
        ->and(FairPlay::barred([$bob->pubkey])[$bob->pubkey]['kind'] ?? null)->toBe('held');
});

test('without a warning the plain accept still signs in one step', function () {
    $bob = awRequester();
    $page = Livewire::actingAs($this->alice)->test('pages::settings.opponents');

    $templates = $page->instance()->prepareAdd($bob->pubkey, app(Opponents::class));
    expect($templates)->toBeArray()->not->toBeEmpty();
    $page->call('add', $bob->pubkey, json_encode($this->aliceSigner->signTemplates($templates)))->assertHasNoErrors();

    expect(app(Opponents::class)->listEachOther($this->alice, $bob))->toBeTrue()
        ->and(FairPlay::barred([$bob->pubkey]))->toBe([]);
});

test('player page: adding an account below the standard shows the warning, refuses the one-step add, and adds after the confirm', function () {
    $carolSigner = new TestSigner;
    $carol = User::factory()->withPubkey($carolSigner->pubkey)->create(['name' => 'carol', 'picture' => null, 'created_at' => now()->subDays(8)]);
    $page = Livewire::actingAs($this->alice)->test('opponent-button', ['player' => $carol]);

    preg_match_all('/data-test="opponent-request-warning-(\w+)"/', $page->html(), $lines);
    expect($lines[1])->toBe(['nip05', 'profile']);
    $page->assertSeeInOrder(['carol cannot play rated yet:', 'No NIP-05 address set.', 'No profile picture', 'You can still add them.', 'Add carol anyway?', 'Add anyway', 'Add as opponent'])
        ->assertSeeHtml('data-test="opponent-confirm"')->assertDontSeeHtml("run('prepareAdd', 'add')");

    $page->call('prepareAdd')->assertHasErrors('opponent')->assertReturned(null)->assertSee('Read the note above first, then confirm.');

    $templates = $page->instance()->prepareConfirmedAdd(app(Opponents::class));
    expect($templates)->toBeArray()->not->toBeEmpty();
    $page->call('add', json_encode($this->aliceSigner->signTemplates($templates)));

    expect(app(Opponents::class)->lists($this->alice, $carol))->toBeTrue()
        ->and(FairPlay::barred([$carol->pubkey])[$carol->pubkey]['kind'] ?? null)->toBe('standard');
});

test('player page: accepting the request of a held account reads only that it is being checked, and accepts after the confirm', function () {
    $original = User::factory()->create(['name' => 'Odell', 'created_at' => now()->subYear()]);
    $bob = awRequester(['name' => 'Odeli']);
    awHold($bob, $original);

    foreach (['en' => ['This account is being checked.', 'Accept Odeli anyway?', 'Accept anyway'], 'de' => ['Dieses Konto wird gerade geprüft.', 'Odeli trotzdem annehmen?', 'Trotzdem annehmen']] as $locale => $texts) {
        app()->setLocale($locale);
        $html = Livewire::actingAs($this->alice)->test('opponent-button', ['player' => $bob])->html();
        preg_match_all('/data-test="opponent-request-warning-(\w+)"/', $html, $lines);
        $lower = mb_strtolower($html);

        expect($lines[1])->toBe(['held'])
            ->and($html)->toContain($texts[0])->toContain($texts[1])->toContain($texts[2])
            ->and($lower)->not->toContain('odell')->not->toContain('imperson')->not->toContain('gesicht')->not->toContain('face');
    }

    app()->setLocale('en');
    $page = Livewire::actingAs($this->alice)->test('opponent-button', ['player' => $bob]);
    $page->call('prepareAdd')->assertHasErrors('opponent')->assertReturned(null);
    $page->call('add', json_encode($this->aliceSigner->signTemplates($page->instance()->prepareConfirmedAdd(app(Opponents::class)))));

    expect(app(Opponents::class)->listEachOther($this->alice, $bob))->toBeTrue()
        ->and(FairPlay::barred([$bob->pubkey])[$bob->pubkey]['kind'] ?? null)->toBe('held');
});

test('player page: without a warning, while the switches are off or for a listed player, the button adds in one click as before and the warnings run no query', function () {
    $met = awRequester(['name' => 'met']);
    $page = Livewire::actingAs($this->alice)->test('opponent-button', ['player' => $met]);
    $page->assertDontSeeHtml('data-test="opponent-request-warning"')->assertDontSeeHtml('data-test="opponent-confirm"')->assertSeeHtml("run('prepareAdd', 'add')");
    expect($page->instance()->prepareAdd(app(Opponents::class)))->toBeArray()->not->toBeEmpty();

    config(['esports.fair_play.profile_standard.enabled' => false, 'esports.fair_play.impersonation.enabled' => false]);
    $bare = awRequester(['name' => '', 'picture' => null, 'nip05' => null, 'nip05_verified_at' => null, 'created_at' => now()]);
    awHold($bare, $met);
    Livewire::actingAs($this->alice)->test('opponent-button', ['player' => $bare])
        ->assertDontSeeHtml('data-test="opponent-request-warning"')->assertSeeHtml("run('prepareAdd', 'add')");

    DB::flushQueryLog();
    DB::enableQueryLog();
    $warnings = AcceptWarnings::for([$bare, $met]);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();
    expect($warnings)->toBe([])->and($queries)->toBe(0);
});

test('match room: the challenged captain whose challenger is held sees the warning inside the notice, and the accept there needs the confirm', function () {
    openSeason();
    app()->instance(TrustFacts::class, new class implements TrustFacts
    {
        public function available(): bool
        {
            return true;
        }

        public function at(array $players, array $gatekeepers): array
        {
            return ['trust' => array_fill_keys($players, 100), 'anchors' => [], 'connected' => true];
        }
    });
    $captains = [];
    foreach (['anna', 'bert'] as $name) {
        $signer = new TestSigner;
        $captain = User::factory()->withPubkey($signer->pubkey)->create(['name' => $name, 'picture' => 'https://example.com/'.$name.'.png', 'nip05' => $name.'@example.com', 'nip05_verified_at' => now()->subDays(20), 'created_at' => now()->subMonths(2)]);
        $lineup = Lineup::factory()->mode('1v1')->ready()->create(['clan_id' => Clan::factory()->create(['owner_id' => $captain->id, 'name' => $name.' clan'])->id]);
        $captains[$name] = [$lineup, $captain, $signer];
    }
    [$a, $b] = [$captains['anna'], $captains['bert']];
    $service = app(SeriesService::class);
    $start = now()->addHour()->startOfMinute()->getTimestamp();
    $draft = new ChallengeDraft($a[0]->id, $b[0]->id, 3, true, [$start], $start - 600, '');
    $match = $service->challenge($a[1], $draft, $a[2]->signTemplates($service->prepareChallenge($a[1], $draft)['templates']));
    $opponents = app(Opponents::class);
    $opponents->add($a[1], $b[1], $a[2]->signTemplates($opponents->prepareAdd($a[1], $b[1])));
    // The hold is raised after the challenge was sent (on a profile change, P2).
    awHold($a[1], User::factory()->create(['name' => 'Anna Original', 'created_at' => now()->subYears(2)]));

    $html = $this->actingAs($b[1])->get(route('matches.room', $match))->assertOk()->getContent();
    $notice = str($html)->after('data-test="needs-mutual"')->toString();
    preg_match_all('/data-test="opponent-request-warning-(\w+)"/', $notice, $lines);

    expect($lines[1])->toBe(['held'])
        ->and($notice)->toContain('This account is being checked.')->toContain('Accept anna anyway?')->toContain('Accept anna&#039;s request')
        ->and(mb_strtolower($notice))->not->toContain('anna original');

    $button = Livewire::actingAs($b[1])->test('opponent-button', ['player' => $a[1], 'inline' => true]);
    $button->call('prepareAdd')->assertHasErrors('opponent')->assertReturned(null);
    $button->call('add', json_encode($b[2]->signTemplates($button->instance()->prepareConfirmedAdd(app(Opponents::class)))));

    expect($opponents->listEachOther($a[1], $b[1]))->toBeTrue();
});
