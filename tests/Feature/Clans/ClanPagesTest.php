<?php

use App\Enums\LineupRole;
use App\Models\Clan;
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
    $invite = $service->invite($owner, $clan, 'rocket-league', '3v3', $invitee, LineupRole::Substitute,
        $signer->signTemplates($service->prepareInvite($owner, $clan, 'rocket-league', '3v3', $invitee, LineupRole::Substitute)));

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
