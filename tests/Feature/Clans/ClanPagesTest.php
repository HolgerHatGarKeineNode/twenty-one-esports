<?php

use App\Enums\InviteStatus;
use App\Enums\LineupRole;
use App\Models\Clan;
use App\Models\ClanInvite;
use App\Models\Lineup;
use App\Models\User;
use App\Support\Clans\ClanDraft;
use App\Support\Clans\ClanService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\TestSigner;

beforeEach(fn () => Queue::fake());

test('the meetup import fills name, logo and text from the portal', function () {
    Http::fake(['portal.einundzwanzig.space/api/meetups*' => Http::response([
        ['id' => 7, 'name' => 'Einundzwanzig Kempten', 'city' => 'Kempten', 'country' => 'DE', 'portalLink' => 'https://portal.einundzwanzig.space/de/meetup/kempten',
            'logo' => 'https://portal.einundzwanzig.space/media/kempten.png', 'intro' => '<p>Monthly meetup in the Allgäu.</p>', 'latitude' => 47.72, 'longitude' => 10.31],
        ['id' => 8, 'name' => 'Einundzwanzig Leipzig', 'city' => 'Leipzig', 'country' => 'DE', 'portalLink' => 'https://portal.einundzwanzig.space/de/meetup/leipzig', 'logo' => null, 'intro' => null],
    ])]);

    Livewire::actingAs(User::factory()->create())->test('pages::clans.create')
        ->set('meetupQuery', 'kempt')
        ->assertSee('Einundzwanzig Kempten')
        ->assertDontSee('Einundzwanzig Leipzig')
        ->call('pickMeetup', 7)
        ->assertSet('name', 'Einundzwanzig Kempten')
        ->assertSet('picture', 'https://portal.einundzwanzig.space/media/kempten.png')
        ->assertSet('description', 'Monthly meetup in the Allgäu.')
        ->assertSet('meetupUrl', 'https://portal.einundzwanzig.space/de/meetup/kempten')
        ->assertSet('clantag', 'KEM');
});

test('with the portal down the page says so and the clan is still created', function () {
    Http::fake(['portal.einundzwanzig.space/*' => Http::response('down', 503)]);

    $signer = new TestSigner;
    $owner = User::factory()->withPubkey($signer->pubkey)->create();
    $signed = $signer->signTemplates(app(ClanService::class)->prepareCreate($owner, new ClanDraft('Nonce Hunters', 'NCE')));

    Livewire::actingAs($owner)->test('pages::clans.create')
        ->set('meetupQuery', 'Zürich')
        ->assertSee('The portal does not answer right now.')
        ->set('name', 'Nonce Hunters')
        ->set('clantag', 'nce')
        ->call('create', json_encode($signed))
        ->assertHasNoErrors()
        ->assertRedirect(route('clans.show', 'nonce-hunters'));

    expect(Clan::query()->sole()->only(['name', 'clantag', 'picture']))->toBe(['name' => 'Nonce Hunters', 'clantag' => 'NCE', 'picture' => null]);
});

test('every clan page survives a Livewire roundtrip', function (string $page, Closure $params) {
    $signer = new TestSigner;
    $owner = User::factory()->withPubkey($signer->pubkey)->create();
    $service = app(ClanService::class);
    $draft = new ClanDraft('Laser Eyes', 'LSR');
    $clan = $service->create($owner, $draft, $signer->signTemplates($service->prepareCreate($owner, $draft)));
    $invitee = User::factory()->create();
    $invite = $service->invite($owner, $clan, $invitee, $signer->signTemplates($service->prepareInvite($owner, $clan, $invitee)));

    Livewire::actingAs($page === 'pages::invites.show' ? $invitee : $owner)
        ->test($page, $params($clan, $invite))
        ->assertOk()
        ->call('$refresh')
        ->assertOk();
})->with([
    'clans' => ['pages::clans.index', fn () => []],
    'clan' => ['pages::clans.show', fn ($clan) => ['clan' => $clan]],
    'create' => ['pages::clans.create', fn () => []],
    'manage' => ['pages::clans.manage', fn ($clan) => ['clan' => $clan]],
    'invite' => ['pages::invites.show', fn ($clan, $invite) => ['invite' => $invite]],
    'rocket league' => ['pages::games.rocket-league', fn () => []],
]);

test('only captains open the manage page, and a tag link finds the clan', function () {
    $clan = Clan::factory()->create();

    $this->actingAs(User::factory()->create())->get(route('clans.manage', $clan))->assertForbidden();
    $this->actingAs($clan->owner)->get(route('clans.manage', $clan))->assertOk();
    $this->get(route('clans.show', strtolower($clan->clantag)))->assertOk();
});

/**
 * A founded clan with the owner and one joined member.
 *
 * @return array{0: Clan, 1: User, 2: TestSigner, 3: User}
 */
function clanWithMember(): array
{
    $service = app(ClanService::class);
    $signer = new TestSigner;
    $owner = User::factory()->withPubkey($signer->pubkey)->create();
    $draft = new ClanDraft('Laser Eyes', 'LSR');
    $clan = $service->create($owner, $draft, $signer->signTemplates($service->prepareCreate($owner, $draft)));

    $memberSigner = new TestSigner;
    $member = User::factory()->withPubkey($memberSigner->pubkey)->create(['name' => 'queen_q']);
    $invite = $service->invite($owner, $clan, $member, $signer->signTemplates($service->prepareInvite($owner, $clan, $member)));
    $service->accept($invite, $member, $memberSigner->signTemplates($service->prepareAccept($invite, $member)));

    return [$clan, $owner, $signer, $member];
}

test('the manage page invites a player by npub into the roster and hands out the invite link', function () {
    [$clan, $owner, $signer] = clanWithMember();
    $invitee = User::factory()->create();
    $signed = $signer->signTemplates(app(ClanService::class)->prepareInvite($owner, $clan, $invitee));

    $page = Livewire::actingAs($owner)->test('pages::clans.manage', ['clan' => $clan])
        ->set('player', $invitee->npub)
        ->call('invite', json_encode($signed))
        ->assertHasNoErrors();

    $invite = ClanInvite::query()->where('invitee_id', $invitee->id)->sole();

    $page->assertSet('inviteLink', route('invites.show', $invite));
    expect($invite->status)->toBe(InviteStatus::Pending);
});

test('the owner builds a lineup on the manage page by picking members from the roster', function () {
    [$clan, $owner, $signer, $member] = clanWithMember();
    $seats = [$owner->id => LineupRole::Captain, $member->id => LineupRole::Player];
    $signed = $signer->signTemplates(app(ClanService::class)->prepareLineup($owner, $clan, 'rocket-league', '2v2', $seats));

    Livewire::actingAs($owner)->test('pages::clans.manage', ['clan' => $clan])
        ->call('editLineup', '2v2')
        ->assertSet('picks', [$owner->id => '', $member->id => ''])
        ->set("picks.{$owner->id}", 'captain')
        ->set("picks.{$member->id}", 'player')
        ->call('saveLineup', json_encode($signed))
        ->assertHasNoErrors()
        ->assertSet('editing', null)
        ->assertSee('ready, 2 of 2');

    expect(Lineup::query()->where(['clan_id' => $clan->id, 'mode' => '2v2'])->sole()->seats()->pluck('role', 'user_id')->all())
        ->toBe([$owner->id => LineupRole::Captain, $member->id => LineupRole::Player]);
});

test('a player who is not in the roster cannot be slipped into a lineup from the page', function () {
    [$clan, $owner] = clanWithMember();
    $stranger = User::factory()->create();

    Livewire::actingAs($owner)->test('pages::clans.manage', ['clan' => $clan])
        ->call('editLineup', '1v1')
        ->set("picks.{$stranger->id}", 'player')
        ->call('saveLineup', '[]')
        ->assertHasErrors(['lineup' => 'Only players of Laser Eyes can be placed in a lineup.']);

    expect(Lineup::query()->count())->toBe(0);
});

test('a captain who is not the founder cannot open the lineup builder', function () {
    [$clan, $owner, $signer, $member] = clanWithMember();
    $service = app(ClanService::class);
    $service->makeCaptain($owner, $clan, $member, $signer->signTemplates($service->prepareMakeCaptain($owner, $clan, $member)));

    Livewire::actingAs($member)->test('pages::clans.manage', ['clan' => $clan])
        ->call('editLineup', '3v3')
        ->assertForbidden();
});

test('the invite page speaks of the roster, and only the invitee and the clan captains may open it', function () {
    [$clan, $owner, $signer] = clanWithMember();
    $invitee = User::factory()->create();
    $invite = app(ClanService::class)->invite($owner, $clan, $invitee, $signer->signTemplates(app(ClanService::class)->prepareInvite($owner, $clan, $invitee)));

    $this->actingAs($invitee)->get(route('invites.show', $invite))
        ->assertSee('Laser Eyes wants you in its roster')
        ->assertSee('You join the Laser Eyes roster. The owner puts you in its lineups; you can leave the clan at any time.');

    $this->actingAs(User::factory()->create())->get(route('invites.show', $invite))->assertForbidden();
});
