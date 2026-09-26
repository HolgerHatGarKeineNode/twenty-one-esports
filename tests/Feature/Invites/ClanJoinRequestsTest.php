<?php

use App\Enums\ClanRole;
use App\Enums\InviteLinkType;
use App\Enums\InviteStatus;
use App\Enums\JoinRequestStatus;
use App\Models\Clan;
use App\Models\ClanInvite;
use App\Models\ClanJoinRequest;
use App\Models\ClanMember;
use App\Models\InviteLink;
use App\Models\NostrEvent;
use App\Models\User;
use App\Support\Clans\ClanDraft;
use App\Support\Clans\ClanJoinRequests;
use App\Support\Clans\ClanRuleViolation;
use App\Support\Clans\ClanService;
use App\Support\Invites\InviteLinkRefused;
use App\Support\Invites\InviteLinks;
use App\Support\Nostr\EsportsEventRules;
use App\Support\Nostr\SignedEvent;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\TestSigner;

beforeEach(function () {
    Queue::fake();
});

/**
 * A clan founded through ClanService by an owner with a real key, plus a
 * captain who is not the owner.
 *
 * @return array{clan: Clan, owner: User, ownerSigner: TestSigner, captain: User}
 */
function joinRequestClan(): array
{
    $ownerSigner = new TestSigner;
    $owner = User::factory()->withPubkey($ownerSigner->pubkey)->create();
    $service = app(ClanService::class);
    $draft = new ClanDraft('Laser Eyes', 'LSR', 'Rocket League clan of the Kempten meetup.');
    $clan = $service->create($owner, $draft, $ownerSigner->signTemplates($service->prepareCreate($owner, $draft)));

    $captain = User::factory()->create();
    ClanMember::query()->create(['clan_id' => $clan->id, 'user_id' => $captain->id, 'role' => ClanRole::Captain, 'joined_at' => now()]);

    return ['clan' => $clan->refresh(), 'owner' => $owner, 'ownerSigner' => $ownerSigner, 'captain' => $captain];
}

function joinLink(Clan $clan, User $by): InviteLink
{
    return app(InviteLinks::class)->create($by, InviteLinkType::Clan, ['clan' => $clan]);
}

test('a clan link sends a join request, never a membership, and every captain hears about it', function () {
    ['clan' => $clan, 'owner' => $owner, 'captain' => $captain] = joinRequestClan();
    $stranger = User::factory()->create();
    $link = joinLink($clan, $captain);
    $events = NostrEvent::query()->count();

    Livewire::actingAs($stranger)->test('pages::invites.link', ['link' => $link])
        ->assertSee(__(':name invites you to join :clan', ['name' => $captain->displayName(), 'clan' => 'Laser Eyes']))
        ->call('accept')
        ->assertSee('You asked to join Laser Eyes')
        ->assertSee('Waiting for a captain')
        ->assertSeeHtml('data-status="pending"');

    $request = ClanJoinRequest::query()->sole();
    expect($request->only(['clan_id', 'user_id', 'invite_link_id']))->toBe(['clan_id' => $clan->id, 'user_id' => $stranger->id, 'invite_link_id' => $link->id])
        ->and($request->status)->toBe(JoinRequestStatus::Pending)
        ->and(ClanMember::query()->where('user_id', $stranger->id)->exists())->toBeFalse()
        ->and(NostrEvent::query()->count())->toBe($events); // a request is league data, no event

    foreach ([$owner, $captain] as $notified) {
        expect($notified->notifications()->sole()->data['title'])->toBe(__(':name wants to join :clan', ['name' => $stranger->displayName(), 'clan' => 'Laser Eyes']));
    }
});

test('captain approves, the owner lists with their key, the player joins with their own membership', function () {
    ['clan' => $clan, 'owner' => $owner, 'ownerSigner' => $ownerSigner, 'captain' => $captain] = joinRequestClan();
    $playerSigner = new TestSigner;
    $player = User::factory()->withPubkey($playerSigner->pubkey)->create();
    $requests = app(ClanJoinRequests::class);
    app(InviteLinks::class)->accept(joinLink($clan, $captain), $player);
    $request = ClanJoinRequest::query()->sole();

    // 1. A captain who is not the owner says yes: approved, the owner is asked.
    Livewire::actingAs($captain)->test('pages::clans.manage', ['clan' => $clan])
        ->assertSee($player->displayName())
        ->call('approveRequest', $request->id)
        ->assertSee('Waiting for the founder to add them to the clan record.');

    expect($request->refresh()->status)->toBe(JoinRequestStatus::Approved)
        ->and($request->decided_by_id)->toBe($captain->id)
        ->and($owner->notifications()->where('data->title', __(':captain approved :name for :clan', ['captain' => $captain->displayName(), 'name' => $player->displayName(), 'clan' => 'Laser Eyes']))->exists())->toBeTrue();

    Livewire::actingAs($player)->test('pages::invites.link', ['link' => $request->link])->assertSeeHtml('data-status="approved"');

    // 2. The owner lists the player: the clan event signed with the owner's key.
    $invite = $requests->list($request, $owner, $ownerSigner->signTemplates($requests->prepareList($request, $owner)));

    $clanEvent = SignedEvent::fromInput(NostrEvent::query()->where('kind', Clan::KIND)->latest('id')->firstOrFail()->payload());
    expect($request->refresh()->status)->toBe(JoinRequestStatus::Listed)
        ->and($request->clan_invite_id)->toBe($invite->id)
        ->and($invite->status)->toBe(InviteStatus::Pending)
        ->and($clanEvent->pubkey)->toBe($owner->pubkey)
        ->and($clanEvent->kind)->toBe(Clan::KIND)
        ->and(collect($clanEvent->tags)->contains(fn (array $tag) => $tag === ['p', $player->pubkey, '', 'member']))->toBeTrue()
        ->and(app(EsportsEventRules::class)->check($clanEvent))->toBeNull()
        ->and($player->notifications()->sole()->data['url'])->toBe(route('invites.show', $invite));

    Livewire::actingAs($player)->test('pages::invites.link', ['link' => $request->link])
        ->assertSeeHtml('data-status="listed"')
        ->assertSeeHtml('href="'.route('invites.show', $invite).'"');

    // 3. The player joins with their own Clan Membership (12150), as before.
    $service = app(ClanService::class);
    $service->accept($invite, $player, $playerSigner->signTemplates($service->prepareAccept($invite, $player)));

    expect(ClanMember::query()->where(['clan_id' => $clan->id, 'user_id' => $player->id])->exists())->toBeTrue();
});

test('the owner approves and lists in one step from the manage page', function () {
    ['clan' => $clan, 'owner' => $owner, 'ownerSigner' => $ownerSigner, 'captain' => $captain] = joinRequestClan();
    $player = User::factory()->create();
    app(InviteLinks::class)->accept(joinLink($clan, $owner), $player);
    $request = ClanJoinRequest::query()->sole();

    $page = Livewire::actingAs($owner)->test('pages::clans.manage', ['clan' => $clan]);
    $templates = $page->instance()->prepareListRequest($request->id, app(ClanJoinRequests::class));
    $page->call('listRequest', $request->id, json_encode($ownerSigner->signTemplates($templates)))->assertHasNoErrors();

    expect($request->refresh()->status)->toBe(JoinRequestStatus::Listed)
        ->and(ClanInvite::query()->sole()->invitee_id)->toBe($player->id);
});

test('a declined player is told plainly; a player outside the clan cannot answer', function () {
    ['clan' => $clan, 'captain' => $captain] = joinRequestClan();
    [$player, $outsider] = User::factory()->count(2)->create();
    app(InviteLinks::class)->accept(joinLink($clan, $captain), $player);
    $request = ClanJoinRequest::query()->sole();
    $requests = app(ClanJoinRequests::class);

    expect(fn () => $requests->approve($request, $outsider))->toThrow(ClanRuleViolation::class)
        ->and(fn () => $requests->decline($request, $player))->toThrow(ClanRuleViolation::class);

    $requests->decline($request, $captain);

    expect($request->refresh()->status)->toBe(JoinRequestStatus::Declined)
        ->and($player->notifications()->sole()->data['title'])->toBe(__(':clan declined your request', ['clan' => 'Laser Eyes']));
});

test('one open request per clan: a second link of the same clan is refused, a withdrawn request is closed', function () {
    ['clan' => $clan, 'owner' => $owner, 'captain' => $captain] = joinRequestClan();
    $player = User::factory()->create();
    app(InviteLinks::class)->accept(joinLink($clan, $captain), $player);

    $second = joinLink($clan, $owner);
    expect(app(InviteLinks::class)->state($second, $player))->toBe('requested')
        ->and(fn () => app(InviteLinks::class)->accept($second, $player))->toThrow(InviteLinkRefused::class)
        ->and($second->refresh()->uses)->toBe(0);

    Livewire::actingAs($player)->test('pages::invites.link', ['link' => $second])->call('withdrawRequest');

    expect(ClanJoinRequest::query()->sole()->status)->toBe(JoinRequestStatus::Withdrawn);
});

test('only a captain makes a join link', function () {
    ['clan' => $clan] = joinRequestClan();
    $member = User::factory()->create();
    ClanMember::query()->create(['clan_id' => $clan->id, 'user_id' => $member->id, 'role' => ClanRole::Member, 'joined_at' => now()]);

    expect(fn () => joinLink($clan->refresh(), $member))->toThrow(InviteLinkRefused::class);
    expect(InviteLink::query()->count())->toBe(0);
});
