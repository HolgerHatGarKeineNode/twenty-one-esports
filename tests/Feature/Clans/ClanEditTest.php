<?php

use App\Enums\LineupRole;
use App\Jobs\PublishNostrEvent;
use App\Models\Clan;
use App\Models\ClanMember;
use App\Models\Lineup;
use App\Models\LineupSeat;
use App\Models\NostrEvent;
use App\Models\User;
use App\Support\Clans\ClanDraft;
use App\Support\Clans\ClanLogos;
use App\Support\Clans\ClanRuleViolation;
use App\Support\Clans\ClanService;
use App\Support\Nostr\EsportsEventRules;
use App\Support\Nostr\SignedEvent;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\TestSigner;

beforeEach(function () {
    Queue::fake();
    Storage::fake('public');
});

/**
 * A clan with the owner, a joined member (in the 2v2 with the owner) and a
 * pending invite: every kind of roster entry the clan event lists.
 *
 * @return array{clan: Clan, owner: User, signer: TestSigner, member: User, memberSigner: TestSigner, invitee: User}
 */
function editableClan(): array
{
    $service = app(ClanService::class);
    $signer = new TestSigner;
    $owner = User::factory()->withPubkey($signer->pubkey)->create();
    $draft = new ClanDraft('Laser Eyes', 'LSR', 'Rocket League clan of the Kempten meetup.');
    $clan = $service->create($owner, $draft, $signer->signTemplates($service->prepareCreate($owner, $draft)));

    $memberSigner = new TestSigner;
    $member = User::factory()->withPubkey($memberSigner->pubkey)->create();
    $invite = $service->invite($owner, $clan, $member, $signer->signTemplates($service->prepareInvite($owner, $clan, $member)));
    $service->accept($invite, $member, $memberSigner->signTemplates($service->prepareAccept($invite, $member)));

    $seats = [$owner->id => LineupRole::Captain, $member->id => LineupRole::Player];
    $service->saveLineup($owner, $clan, 'rocket-league', '2v2', $seats, $signer->signTemplates($service->prepareLineup($owner, $clan->refresh(), 'rocket-league', '2v2', $seats)));

    $invitee = User::factory()->create();
    $service->invite($owner, $clan, $invitee, $signer->signTemplates($service->prepareInvite($owner, $clan, $invitee)));

    return ['clan' => $clan->refresh(), 'owner' => $owner, 'signer' => $signer, 'member' => $member, 'memberSigner' => $memberSigner, 'invitee' => $invitee];
}

/**
 * Prepare on the page, sign what it returned, submit: the browser's run().
 */
function signEdit(Testable $page, TestSigner $signer): Testable
{
    $templates = null;
    $page->call('prepareEdit')->assertReturned(function (mixed $returned) use (&$templates): bool {
        $templates = $returned;

        return true;
    });

    return $page->call('saveEdit', json_encode(is_array($templates) ? $signer->signTemplates($templates) : []));
}

/**
 * A real image of one colour: UploadedFile::fake()->image() draws every file
 * the same, and two identical logos are one file (content-hashed).
 */
function colourLogo(string $name, int $width, int $height, int $red): UploadedFile
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, $red, 147, 26));
    ob_start();
    str_ends_with($name, '.jpg') ? imagejpeg($image) : imagepng($image);

    return UploadedFile::fake()->createWithContent($name, (string) ob_get_clean());
}

function latestClanEvent(): SignedEvent
{
    return SignedEvent::fromInput(NostrEvent::query()->where('kind', Clan::KIND)->latest('id')->firstOrFail()->payload());
}

/**
 * Everything an edit must leave alone.
 *
 * @return array<string, mixed>
 */
function rosterSnapshot(Clan $clan): array
{
    return [
        'members' => ClanMember::query()->where('clan_id', $clan->id)->orderBy('id')->get(['user_id', 'role', 'joined_at'])->toArray(),
        'lineups' => Lineup::query()->where('clan_id', $clan->id)->orderBy('id')->get(['id', 'game', 'mode', 'event_id'])->toArray(),
        'seats' => LineupSeat::query()->orderBy('id')->get(['lineup_id', 'user_id', 'role'])->toArray(),
        'p' => latestClanEvent()->tagsNamed('p'),
    ];
}

test('the owner edits name, description and logo: a new signed clan event with the same d and the roster untouched', function () {
    ['clan' => $clan, 'owner' => $owner, 'signer' => $signer] = editableClan();
    $before = rosterSnapshot($clan);
    Queue::fake();

    $page = Livewire::actingAs($owner)->test('pages::clans.manage', ['clan' => $clan])
        ->call('openEdit')
        ->assertSet('editName', 'Laser Eyes')
        ->set('editName', 'Laser Eyes Allgäu')
        ->set('editDescription', 'Now with a logo.')
        ->set('logo', UploadedFile::fake()->image('logo.png', 900, 600))
        ->assertHasNoErrors();

    signEdit($page, $signer)->assertHasNoErrors()->assertSet('editingClan', false);

    $clan->refresh();
    $event = latestClanEvent();
    $files = Storage::disk('public')->files(ClanLogos::DIRECTORY);

    expect($clan->only(['name', 'clantag', 'description', 'slug']))->toBe(['name' => 'Laser Eyes Allgäu', 'clantag' => 'LSR', 'description' => 'Now with a logo.', 'slug' => 'laser-eyes'])
        ->and($files)->toHaveCount(1)
        ->and($clan->picture)->toBe(url(Storage::disk('public')->url($files[0])))
        ->and($clan->picture)->toStartWith('http')
        ->and(getimagesizefromstring((string) Storage::disk('public')->get($files[0]))[0] ?? null)->toBe(512)
        ->and(getimagesizefromstring((string) Storage::disk('public')->get($files[0]))[1] ?? null)->toBe(512)
        ->and($files[0])->toBe(ClanLogos::DIRECTORY.'/'.hash('sha256', (string) Storage::disk('public')->get($files[0])).'.png')
        ->and($event->hasValidSignature())->toBeTrue()
        ->and(app(EsportsEventRules::class)->check($event))->toBeNull()
        ->and($event->pubkey)->toBe($owner->pubkey)
        ->and($event->tag('d'))->toBe('laser-eyes')
        ->and($event->tag('name'))->toBe('Laser Eyes Allgäu')
        ->and($event->tag('picture'))->toBe($clan->picture)
        ->and($event->content)->toBe('Now with a logo.')
        ->and($clan->event_id)->toBe($event->id)
        ->and(rosterSnapshot($clan))->toBe($before)
        ->and($before['p'])->toHaveCount(3);

    Queue::assertPushed(PublishNostrEvent::class, 1);

    $this->get(route('clans.show', $clan))->assertOk()->assertSee($clan->picture, false)->assertSee('Laser Eyes Allgäu');
});

test('the tag can change like at creation: format and uniqueness checked, the address stays', function () {
    ['clan' => $clan, 'owner' => $owner, 'signer' => $signer] = editableClan();
    Clan::factory()->create(['clantag' => 'TKN']);

    $page = Livewire::actingAs($owner)->test('pages::clans.manage', ['clan' => $clan])->call('openEdit');

    $page->set('editClantag', 'tkn')->call('prepareEdit')->assertHasErrors(['editClantag' => 'The tag TKN is taken.']);
    $page->set('editClantag', 'X!')->call('prepareEdit')->assertHasErrors(['editClantag' => '2 to 4 capital letters or digits.']);

    signEdit($page->set('editClantag', 'lsx'), $signer)->assertHasNoErrors();

    expect($clan->refresh()->clantag)->toBe('LSX')
        ->and(latestClanEvent()->tag('clantag'))->toBe('LSX')
        ->and(latestClanEvent()->tag('d'))->toBe('laser-eyes')
        ->and($clan->address())->toBe(Clan::KIND.':'.$owner->pubkey.':laser-eyes');
});

test('the service refuses a tag another clan uses, and an edit that changes nothing', function () {
    ['clan' => $clan, 'owner' => $owner] = editableClan();
    Clan::factory()->create(['clantag' => 'TKN']);
    $service = app(ClanService::class);

    expect(fn () => $service->prepareEdit($owner, $clan, new ClanDraft('Laser Eyes', 'TKN', $clan->description)))
        ->toThrow(ClanRuleViolation::class, 'The tag TKN is taken.')
        ->and(fn () => $service->prepareEdit($owner, $clan, new ClanDraft('Laser Eyes', 'LSR', $clan->description)))
        ->toThrow(ClanRuleViolation::class, 'Nothing to save: nothing was changed.');
});

test('only the owner can edit: a captain, a member and an outsider are refused', function () {
    ['clan' => $clan, 'owner' => $owner, 'signer' => $signer, 'member' => $member] = editableClan();
    $service = app(ClanService::class);
    $outsider = User::factory()->create();
    $draft = new ClanDraft('Hijacked', 'LSR');

    // A plain member and an outsider: not even the manage page, and the service says no.
    foreach ([$member, $outsider] as $user) {
        $this->actingAs($user)->get(route('clans.manage', $clan))->assertForbidden();
        expect(fn () => $service->prepareEdit($user, $clan, $draft))->toThrow(ClanRuleViolation::class);
    }

    // Promoted to captain: the manage page opens, the edit card does not.
    $service->makeCaptain($owner, $clan, $member, $signer->signTemplates($service->prepareMakeCaptain($owner, $clan, $member)));

    Livewire::actingAs($member)->test('pages::clans.manage', ['clan' => $clan])
        ->assertSee('Only the founder of Laser Eyes can edit it')
        ->assertDontSee('data-test="open-edit"', false)
        ->call('openEdit')
        ->assertForbidden();

    Livewire::actingAs($member)->test('pages::clans.manage', ['clan' => $clan])
        ->call('prepareEdit')
        ->assertForbidden();

    expect(fn () => $service->prepareEdit($member, $clan, $draft))->toThrow(ClanRuleViolation::class)
        ->and($clan->refresh()->name)->toBe('Laser Eyes');
});

test('an upload that is not a PNG, JPG or WebP image of the right size is refused', function (Closure $file, string $message) {
    ['clan' => $clan, 'owner' => $owner] = editableClan();

    Livewire::actingAs($owner)->test('pages::clans.manage', ['clan' => $clan])
        ->call('openEdit')
        ->set('logo', $file())
        ->assertHasErrors(['logo' => $message])
        ->assertSet('logo', null);

    expect(Storage::disk('public')->files(ClanLogos::DIRECTORY))->toBe([]);
})->with([
    'svg' => [fn () => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100"><script>alert(1)</script></svg>'), 'Use a PNG, JPG or WebP image. SVG is not supported.'],
    'svg named png' => [fn () => UploadedFile::fake()->createWithContent('logo.png', '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100"></svg>'), 'Use a PNG, JPG or WebP image. SVG is not supported.'],
    'text named png' => [fn () => UploadedFile::fake()->createWithContent('logo.png', str_repeat('not an image ', 50)), 'Use a PNG, JPG or WebP image. SVG is not supported.'],
    'gif' => [fn () => UploadedFile::fake()->image('logo.gif', 200, 200), 'Use a PNG, JPG or WebP image. SVG is not supported.'],
    'over 2 MB' => [fn () => UploadedFile::fake()->image('logo.png', 200, 200)->size(2049), 'The logo can be at most 2 MB.'],
    'too small' => [fn () => UploadedFile::fake()->image('logo.png', 32, 32), 'The logo needs at least 64 × 64 and at most 3000 × 3000 pixels.'],
    'too large' => [fn () => UploadedFile::fake()->image('logo.png', 3001, 100), 'The logo needs at least 64 × 64 and at most 3000 × 3000 pixels.'],
]);

test('a replaced or removed upload is deleted, a portal logo is never touched', function () {
    ['clan' => $clan, 'owner' => $owner, 'signer' => $signer] = editableClan();
    Http::fake(['portal.einundzwanzig.space/api/meetups*' => Http::response([
        ['id' => 7, 'name' => 'Einundzwanzig Kempten', 'city' => 'Kempten', 'country' => 'DE', 'portalLink' => 'https://portal.einundzwanzig.space/de/meetup/kempten',
            'logo' => 'https://portal.einundzwanzig.space/media/kempten.png', 'intro' => null, 'latitude' => 47.72, 'longitude' => 10.31],
    ])]);
    $page = fn () => Livewire::actingAs($owner)->test('pages::clans.manage', ['clan' => $clan])->call('openEdit');

    signEdit($page()->set('logo', colourLogo('a.png', 300, 300, 10)), $signer)->assertHasNoErrors();
    $first = Storage::disk('public')->files(ClanLogos::DIRECTORY);

    signEdit($page()->set('logo', colourLogo('b.jpg', 400, 200, 200)), $signer)->assertHasNoErrors();
    $second = Storage::disk('public')->files(ClanLogos::DIRECTORY);

    expect($first)->toHaveCount(1)
        ->and($second)->toHaveCount(1)
        ->and($second)->not->toBe($first)
        ->and($clan->refresh()->picture)->toBe(url(Storage::disk('public')->url($second[0])));

    // Link the meetup, take its logo: the upload goes, the r tag comes.
    signEdit($page()->set('meetupQuery', 'kempt')->call('pickMeetup', 7)->call('useMeetupLogo'), $signer)->assertHasNoErrors();

    expect(Storage::disk('public')->files(ClanLogos::DIRECTORY))->toBe([])
        ->and($clan->refresh()->picture)->toBe('https://portal.einundzwanzig.space/media/kempten.png')
        ->and($clan->meetup_name)->toBe('Einundzwanzig Kempten')
        ->and(latestClanEvent()->tag('r'))->toBe('https://portal.einundzwanzig.space/de/meetup/kempten')
        ->and(latestClanEvent()->tag('picture'))->toBe('https://portal.einundzwanzig.space/media/kempten.png');

    // Unlink and remove: no r, no picture, nothing on the disk.
    signEdit($page()->call('forgetMeetup')->call('removeLogo'), $signer)->assertHasNoErrors();

    expect($clan->refresh()->only(['picture', 'meetup_name', 'meetup_url']))->toBe(['picture' => null, 'meetup_name' => null, 'meetup_url' => null])
        ->and(latestClanEvent()->tag('r'))->toBeNull()
        ->and(latestClanEvent()->tag('picture'))->toBeNull();
});

test('a refused signature leaves the clan and the disk as they were', function () {
    ['clan' => $clan, 'owner' => $owner] = editableClan();
    $stranger = new TestSigner;

    signEdit(Livewire::actingAs($owner)->test('pages::clans.manage', ['clan' => $clan])
        ->call('openEdit')
        ->set('editName', 'Forged')
        ->set('logo', UploadedFile::fake()->image('logo.png', 300, 300)), $stranger)
        ->assertHasErrors(['edit' => 'The confirmation did not match. Please try again.']);

    expect($clan->refresh()->name)->toBe('Laser Eyes')
        ->and($clan->picture)->toBeNull()
        ->and(Storage::disk('public')->files(ClanLogos::DIRECTORY))->toBe([]);
});

test('no logo file exists while the signature is being checked, and none after it is refused', function () {
    ['clan' => $clan, 'owner' => $owner, 'signer' => $signer] = editableClan();

    // A forged sig from the right author gets past the tag checks, so the
    // gate looks the event id up in nostr_events before Schnorr refuses it:
    // that lookup is inside edit(), before it throws.
    $filesDuringCheck = null;
    DB::listen(function (QueryExecuted $query) use (&$filesDuringCheck): void {
        if ($filesDuringCheck === null && str_contains($query->sql, 'nostr_events') && str_contains($query->sql, 'event_id')) {
            $filesDuringCheck = Storage::disk('public')->files(ClanLogos::DIRECTORY);
        }
    });

    $page = Livewire::actingAs($owner)->test('pages::clans.manage', ['clan' => $clan])
        ->call('openEdit')
        ->set('logo', colourLogo('logo.png', 300, 300, 40));
    $templates = null;
    $page->call('prepareEdit')->assertReturned(function (mixed $returned) use (&$templates): bool {
        $templates = $returned;

        return true;
    });
    $forged = array_map(fn (array $event) => array_replace($event, ['sig' => str_repeat('ab', 64)]), $signer->signTemplates($templates));

    $page->call('saveEdit', json_encode($forged))
        ->assertHasErrors(['edit' => 'The confirmation did not match. Please try again.']);

    expect($filesDuringCheck)->toBe([])
        ->and(Storage::disk('public')->files(ClanLogos::DIRECTORY))->toBe([])
        ->and($clan->refresh()->picture)->toBeNull();
});

test('a logo that cannot be written after the edit is reported and shown as an error', function () {
    ['clan' => $clan, 'owner' => $owner, 'signer' => $signer] = editableClan();
    $root = Storage::disk('public')->path('');
    File::put($root.ClanLogos::DIRECTORY, 'a file where the directory should be');
    Exceptions::fake();

    signEdit(Livewire::actingAs($owner)->test('pages::clans.manage', ['clan' => $clan])
        ->call('openEdit')
        ->set('logo', colourLogo('logo.png', 300, 300, 80)), $signer)
        ->assertHasErrors(['logo' => 'The clan was saved, but the logo could not be stored. Please upload it again.']);

    Exceptions::assertReported(RuntimeException::class);
    expect($clan->refresh()->picture)->toStartWith('http')
        ->and(Storage::disk('public')->exists(ClanLogos::DIRECTORY.'/'.basename($clan->picture)))->toBeFalse();

    // Uploading the same logo again writes the file the signed event names; nothing new is signed.
    File::delete($root.ClanLogos::DIRECTORY);
    $events = NostrEvent::query()->count();

    Livewire::actingAs($owner)->test('pages::clans.manage', ['clan' => $clan])
        ->call('openEdit')
        ->set('logo', colourLogo('logo.png', 300, 300, 80))
        ->call('prepareEdit')
        ->assertReturned(null)
        ->assertHasNoErrors()
        ->assertSet('editingClan', false);

    expect(Storage::disk('public')->exists(ClanLogos::DIRECTORY.'/'.basename($clan->picture)))->toBeTrue()
        ->and(NostrEvent::query()->count())->toBe($events);
});

test('the manage page survives a Livewire roundtrip with the edit card open and a logo picked', function () {
    ['clan' => $clan, 'owner' => $owner] = editableClan();

    Livewire::actingAs($owner)->test('pages::clans.manage', ['clan' => $clan])
        ->call('openEdit')
        ->set('logo', UploadedFile::fake()->image('logo.png', 300, 300))
        ->assertOk()
        ->assertSee('Preview of the new logo')
        ->call('$refresh')
        ->assertOk()
        ->assertSee('Preview of the new logo');
});
