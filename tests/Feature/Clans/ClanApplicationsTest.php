<?php

use App\Enums\ClanRole;
use App\Enums\JoinRequestOrigin;
use App\Enums\JoinRequestStatus;
use App\Jobs\SendNostrDm;
use App\Models\ChessGame;
use App\Models\Clan;
use App\Models\ClanInvite;
use App\Models\ClanJoinRequest;
use App\Models\ClanMember;
use App\Models\User;
use App\Support\Clans\ClanApplication;
use App\Support\Clans\ClanDraft;
use App\Support\Clans\ClanJoinRequests;
use App\Support\Clans\ClanRuleViolation;
use App\Support\Clans\ClanService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\TestSigner;

beforeEach(function () {
    Queue::fake();
});

/**
 * A clan founded through ClanService by an owner with a real key, plus a
 * captain who is not the owner and a plain member.
 *
 * @return array{clan: Clan, owner: User, ownerSigner: TestSigner, captain: User, member: User}
 */
function applicationClan(string $name = 'Laser Eyes', string $tag = 'LSR'): array
{
    $ownerSigner = new TestSigner;
    $owner = User::factory()->withPubkey($ownerSigner->pubkey)->create();
    $service = app(ClanService::class);
    $draft = new ClanDraft($name, $tag);
    $clan = $service->create($owner, $draft, $ownerSigner->signTemplates($service->prepareCreate($owner, $draft)));

    $captain = User::factory()->create(['locale' => 'de']);
    ClanMember::query()->create(['clan_id' => $clan->id, 'user_id' => $captain->id, 'role' => ClanRole::Captain, 'joined_at' => now()]);
    $member = User::factory()->create();
    ClanMember::query()->create(['clan_id' => $clan->id, 'user_id' => $member->id, 'role' => ClanRole::Member, 'joined_at' => now()]);

    return ['clan' => $clan->refresh(), 'owner' => $owner, 'ownerSigner' => $ownerSigner, 'captain' => $captain, 'member' => $member];
}

function applyForm(): ClanApplication
{
    return new ClanApplication(['rocket-league'], ['pc'], 'Europe/Berlin', 'Diamond in 2v2, evenings.');
}

test('a player applies from the clan page with games, platforms, time zone and a message', function () {
    ['clan' => $clan, 'owner' => $owner, 'captain' => $captain] = applicationClan();
    $player = User::factory()->create(['name' => 'satsjaeger']);

    $page = Livewire::actingAs($player)->test('clan-apply', ['clanId' => $clan->id])
        ->assertSeeHtml('data-test="clan-apply-open"')
        ->call('openForm')
        ->set('games', ['rocket-league', 'chess'])
        ->set('platforms', ['pc', 'playstation'])
        ->set('timezone', 'Europe/Berlin')
        ->set('message', 'Diamond in 2v2, evenings.');
    $dms = $page->instance()->apply(app(ClanJoinRequests::class));
    $page->assertHasNoErrors();

    $request = ClanJoinRequest::query()->sole();
    expect($request->origin)->toBe(JoinRequestOrigin::Application)
        ->and($request->invite_link_id)->toBeNull()
        ->and($request->status)->toBe(JoinRequestStatus::Pending)
        ->and($request->games)->toBe(['rocket-league', 'chess'])
        ->and($request->platforms)->toBe(['pc', 'playstation'])
        ->and($request->timezone)->toBe('Europe/Berlin')
        ->and($request->message)->toBe('Diamond in 2v2, evenings.');

    // One NIP-17 text per captain (the owner is one), each in their own language, with the way to answer.
    expect(collect($dms)->pluck('pubkey')->sort()->values()->all())->toBe(collect([$owner->pubkey, $captain->pubkey])->sort()->values()->all());
    $toCaptain = collect($dms)->firstWhere('pubkey', $captain->pubkey)['content'];
    expect($toCaptain)->toContain('satsjaeger')
        ->toContain('Laser Eyes')
        ->toContain('Rocket League')
        ->toContain('PlayStation')
        ->toContain('Europe/Berlin')
        ->toContain('Diamond in 2v2, evenings.')
        ->toContain(route('clans.manage', $clan).'#join-requests')
        ->toContain('Bewerbung');

    Livewire::actingAs($player)->test('clan-apply', ['clanId' => $clan->id])
        ->assertSeeHtml('data-status="pending"')
        ->assertDontSeeHtml('data-test="clan-apply-open"');
});

test('the form refuses unknown games and platforms, a made-up time zone and a message over 280 characters', function () {
    ['clan' => $clan] = applicationClan();

    Livewire::actingAs(User::factory()->create())->test('clan-apply', ['clanId' => $clan->id])
        ->call('openForm')
        ->set('games', ['minesweeper'])
        ->set('platforms', ['amiga'])
        ->set('timezone', 'Mars/Olympus')
        ->set('message', str_repeat('a', 281))
        ->call('apply')
        ->assertHasErrors(['games.0', 'platforms.0', 'timezone', 'message']);

    Livewire::actingAs(User::factory()->create())->test('clan-apply', ['clanId' => $clan->id])
        ->call('openForm')
        ->set('games', [])
        ->set('timezone', 'Europe/Berlin')
        ->call('apply')
        ->assertHasErrors(['games']);

    expect(ClanJoinRequest::query()->count())->toBe(0);
});

test('one open application per clan, three open at most, none to the own clan', function () {
    $requests = app(ClanJoinRequests::class);
    ['clan' => $clan, 'member' => $member] = applicationClan();
    $player = User::factory()->create();

    $requests->apply($clan, $player, applyForm());
    expect(fn () => $requests->apply($clan, $player, applyForm()))->toThrow(ClanRuleViolation::class, 'You already asked to join Laser Eyes.')
        ->and(fn () => $requests->apply($clan, $member, applyForm()))->toThrow(ClanRuleViolation::class, 'You are already in Laser Eyes.');

    $requests->apply(applicationClan('Nonce Hunters', 'NCE')['clan'], $player, applyForm());
    $requests->apply(applicationClan('Hash Bandits', 'HSH')['clan'], $player, applyForm());
    $fourth = applicationClan('Block Party', 'BLK')['clan'];

    expect(fn () => $requests->apply($fourth, $player, applyForm()))->toThrow(ClanRuleViolation::class, 'You have 3 open applications. Wait for an answer or withdraw one first.');

    // A withdrawn application frees its place.
    $requests->withdraw(ClanJoinRequest::query()->where('clan_id', $clan->id)->sole(), $player);
    $requests->apply($fourth, $player, applyForm());

    expect(ClanJoinRequest::query()->where('user_id', $player->id)->whereIn('status', ['pending', 'approved'])->count())->toBe(3);
});

test('with applications closed the button is gone and the server refuses', function () {
    ['clan' => $clan, 'captain' => $captain] = applicationClan();
    $player = User::factory()->create();

    Livewire::actingAs($captain)->test('pages::clans.manage', ['clan' => $clan])
        ->assertSeeHtml('data-test="applications-open"')
        ->call('toggleApplications')
        ->assertHasNoErrors();
    expect($clan->refresh()->applications_open)->toBeFalse();

    Livewire::actingAs($player)->test('clan-apply', ['clanId' => $clan->id])
        ->assertDontSeeHtml('data-test="clan-apply-open"')
        ->assertSee('Laser Eyes is not taking applications right now.')
        ->call('openForm')
        ->set('games', ['rocket-league'])
        ->set('timezone', 'Europe/Berlin')
        ->call('apply')
        ->assertHasErrors(['application']);

    $this->actingAs($player)->get(route('clans.index'))->assertOk()->assertDontSee('data-test="clan-card-apply"', false);

    expect(fn () => app(ClanJoinRequests::class)->apply($clan, $player, applyForm()))->toThrow(ClanRuleViolation::class, 'Laser Eyes is not taking applications right now.')
        ->and(ClanJoinRequest::query()->count())->toBe(0);

    // A plain member cannot flip the switch.
    Livewire::actingAs(User::factory()->create())->test('pages::clans.manage', ['clan' => $clan])->assertForbidden();
});

test('applications are open for every clan: new ones by default, existing ones after the migration', function () {
    ['clan' => $clan] = applicationClan();
    expect($clan->refresh()->applications_open)->toBeTrue();

    // A row written without the column gets the default.
    $id = DB::table('clans')->insertGetId([
        'slug' => 'raw-clan', 'owner_pubkey' => str_repeat('a', 64), 'name' => 'Raw Clan', 'clantag' => 'RAW',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    expect(Clan::query()->findOrFail($id)->applications_open)->toBeTrue();

    // The migration switches existing clans on.
    DB::table('clans')->update(['applications_open' => false]);
    $migration = require database_path('migrations/2026_10_04_111004_add_clan_applications.php');
    $migration->down();
    $migration->up();

    expect(Clan::query()->pluck('applications_open')->unique()->values()->all())->toBe([true]);
});

test('a captain approves an application, the owner approves and lists in one step', function () {
    ['clan' => $clan, 'owner' => $owner, 'ownerSigner' => $ownerSigner, 'captain' => $captain] = applicationClan();
    $requests = app(ClanJoinRequests::class);
    [$first, $second] = User::factory()->count(2)->create();
    $one = $requests->apply($clan, $first, applyForm());
    $two = $requests->apply($clan, $second, new ClanApplication(['chess'], [], 'America/New_York', null));

    Livewire::actingAs($captain)->test('pages::clans.manage', ['clan' => $clan])
        ->assertSeeHtml('data-test="application"')
        ->assertSee('Diamond in 2v2, evenings.')
        ->assertSee('Rocket League')
        ->assertSee('America/New_York')
        ->call('approveRequest', $one->id)
        ->assertHasNoErrors();
    expect($one->refresh()->status)->toBe(JoinRequestStatus::Approved);

    $page = Livewire::actingAs($owner)->test('pages::clans.manage', ['clan' => $clan]);
    $templates = $page->instance()->prepareListRequest($two->id, $requests);
    $page->call('listRequest', $two->id, json_encode($ownerSigner->signTemplates($templates)))->assertHasNoErrors();

    expect($two->refresh()->status)->toBe(JoinRequestStatus::Listed)
        ->and(ClanInvite::query()->sole()->invitee_id)->toBe($second->id);

    Livewire::actingAs($second)->test('clan-apply', ['clanId' => $clan->id])
        ->assertSeeHtml('data-status="listed"')
        ->assertSeeHtml('href="'.route('invites.show', ClanInvite::query()->sole()).'"');
});

test('a decline with a reply hands the captain a NIP-17 text for the applicant; without one there is none', function () {
    ['clan' => $clan, 'captain' => $captain] = applicationClan();
    $requests = app(ClanJoinRequests::class);
    [$first, $second] = User::factory()->count(2)->create();
    $one = $requests->apply($clan, $first, applyForm());
    $two = $requests->apply($clan, $second, applyForm());

    $page = Livewire::actingAs($captain)->test('pages::clans.manage', ['clan' => $clan]);
    $dm = $page->instance()->declineRequest($one->id, '  We are full for this season, try again in spring.  ');
    $none = $page->instance()->declineRequest($two->id, '   ');

    expect($one->refresh()->status)->toBe(JoinRequestStatus::Declined)
        ->and($two->refresh()->status)->toBe(JoinRequestStatus::Declined)
        ->and($dm['pubkey'])->toBe($first->pubkey)
        ->and($dm['content'])->toContain('Laser Eyes')->toContain('We are full for this season, try again in spring.')
        ->and($none)->toBeNull()
        ->and($first->notifications()->sole()->data['title'])->toBe(__(':clan declined your request', ['clan' => 'Laser Eyes']));

    Livewire::actingAs($first)->test('clan-apply', ['clanId' => $clan->id])
        ->assertSeeHtml('data-status="declined"')
        ->assertSeeHtml('data-test="clan-apply-open"');
});

test('the owner and every captain get the bell, nobody else, and no league DM on top of the applicant\'s', function () {
    ['clan' => $clan, 'owner' => $owner, 'captain' => $captain, 'member' => $member] = applicationClan();
    $player = User::factory()->create(['name' => 'satsjaeger']);

    app(ClanJoinRequests::class)->apply($clan, $player, applyForm());

    expect($owner->notifications()->sole()->data['title'])->toBe('satsjaeger applied to Laser Eyes')
        ->and($owner->notifications()->sole()->data['url'])->toBe(route('clans.manage', $clan).'#join-requests')
        ->and($captain->notifications()->sole()->data['title'])->toBe('satsjaeger hat sich bei Laser Eyes beworben')
        ->and($member->notifications()->count())->toBe(0)
        ->and($player->notifications()->count())->toBe(0);
    Queue::assertNotPushed(SendNostrDm::class);
});

test('the applicant withdraws on the clan page and can apply again', function () {
    ['clan' => $clan] = applicationClan();
    $player = User::factory()->create();
    app(ClanJoinRequests::class)->apply($clan, $player, applyForm());

    Livewire::actingAs($player)->test('clan-apply', ['clanId' => $clan->id])
        ->assertSeeHtml('data-status="pending"')
        ->call('withdraw')
        ->assertHasNoErrors()
        ->assertSeeHtml('data-test="clan-apply-open"');

    expect(ClanJoinRequest::query()->sole()->status)->toBe(JoinRequestStatus::Withdrawn);
});

test('the clan list shows Apply on other clans, not on the own one, and a guest is sent to log in', function () {
    ['clan' => $clan, 'member' => $member] = applicationClan();
    applicationClan('Nonce Hunters', 'NCE');

    Livewire::test('clan-apply', ['clanId' => $clan->id])->assertSeeHtml('href="'.route('login').'"');

    $this->actingAs($member)->get(route('clans.index'))->assertOk()
        ->assertSee('href="'.route('clans.show', ['clan' => 'nonce-hunters', 'apply' => 1]).'"', false)
        ->assertDontSee('href="'.route('clans.show', ['clan' => $clan, 'apply' => 1]).'"', false);
});

test('the manage page asks the same queries for one applicant as for five, and counts each one\'s games', function () {
    ['clan' => $clan, 'owner' => $owner] = applicationClan();
    $applicant = function () use ($clan): void {
        $player = User::factory()->create();
        ChessGame::factory()->create(['white_id' => $player->id]);
        app(ClanJoinRequests::class)->apply($clan, $player, applyForm());
    };
    $queries = function () use ($clan, $owner): array {
        $this->actingAs($owner)->get(route('clans.manage', $clan))->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $html = (string) $this->actingAs($owner)->get(route('clans.manage', $clan))->assertOk()->getContent();
        DB::disableQueryLog();

        return [count(DB::getQueryLog()), substr_count($html, '1 game played')];
    };

    $applicant();
    [$one, $oneShown] = $queries();
    foreach (range(2, 5) as $n) {
        $applicant();
    }
    [$five, $fiveShown] = $queries();

    expect([$oneShown, $fiveShown])->toBe([1, 5])
        ->and($five)->toBe($one);
});
