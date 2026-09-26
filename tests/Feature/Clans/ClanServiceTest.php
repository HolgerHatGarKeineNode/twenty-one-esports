<?php

use App\Enums\ClanRole;
use App\Enums\InviteStatus;
use App\Enums\LineupRole;
use App\Jobs\PublishNostrEvent;
use App\Livewire\Actions\DeleteAccount;
use App\Models\Clan;
use App\Models\ClanDeparture;
use App\Models\ClanInvite;
use App\Models\ClanMember;
use App\Models\Lineup;
use App\Models\LineupSeat;
use App\Models\NostrEvent;
use App\Models\User;
use App\Support\Clans\ClanDraft;
use App\Support\Clans\ClanRuleViolation;
use App\Support\Clans\ClanService;
use App\Support\Nostr\EsportsEventRules;
use App\Support\Nostr\RejectedEvent;
use App\Support\Nostr\SignedEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TestSigner;

beforeEach(function () {
    Queue::fake();
    $this->clans = app(ClanService::class);
});

/**
 * @return array{0: User, 1: TestSigner}
 */
function player(): array
{
    $signer = new TestSigner;

    return [User::factory()->withPubkey($signer->pubkey)->create(), $signer];
}

function foundClan(User $owner, TestSigner $signer, string $name = 'Laser Eyes', string $tag = 'LSR'): Clan
{
    $draft = new ClanDraft($name, $tag, 'Rocket League clan of the Kempten meetup.');

    return app(ClanService::class)->create($owner, $draft, $signer->signTemplates(app(ClanService::class)->prepareCreate($owner, $draft)));
}

/**
 * Invite through the service as the owner and return the invite.
 */
function invitePlayer(Clan $clan, User $owner, TestSigner $ownerSigner, User $invitee): ClanInvite
{
    $service = app(ClanService::class);

    return $service->invite($owner, $clan, $invitee, $ownerSigner->signTemplates($service->prepareInvite($owner, $clan, $invitee)));
}

/**
 * Invite and accept: the player is an active member afterwards.
 */
function joinClan(Clan $clan, User $owner, TestSigner $ownerSigner, User $player, TestSigner $playerSigner): void
{
    $service = app(ClanService::class);
    $invite = invitePlayer($clan, $owner, $ownerSigner, $player);

    $service->accept($invite, $player, $playerSigner->signTemplates($service->prepareAccept($invite, $player)));
}

/**
 * Save a lineup through the service as the owner.
 *
 * @param  array<int, LineupRole>  $seats
 */
function saveLineup(Clan $clan, User $owner, TestSigner $ownerSigner, string $mode, array $seats): Lineup
{
    $service = app(ClanService::class);

    return $service->saveLineup($owner, $clan, 'rocket-league', $mode, $seats,
        $ownerSigner->signTemplates($service->prepareLineup($owner, $clan, 'rocket-league', $mode, $seats)));
}

test('founding a clan stores a valid signed clan event and the founder membership, and queues both for the relays', function () {
    [$owner, $signer] = player();

    $clan = foundClan($owner, $signer);

    $clanEvent = SignedEvent::fromInput(NostrEvent::query()->where('kind', Clan::KIND)->sole()->payload());

    expect($clanEvent->hasValidSignature())->toBeTrue()
        ->and(app(EsportsEventRules::class)->check($clanEvent))->toBeNull()
        ->and($clanEvent->pubkey)->toBe($owner->pubkey)
        ->and($clanEvent->tag('d'))->toBe('laser-eyes')
        ->and($clanEvent->tagsNamed('p'))->toBe([[$owner->pubkey, '', 'captain']])
        ->and($clan->event_id)->toBe($clanEvent->id)
        ->and($owner->clanMember->clan_id)->toBe($clan->id)
        ->and($owner->clanMember->role)->toBe(ClanRole::Captain)
        ->and(NostrEvent::query()->where('kind', EsportsEventRules::MEMBERSHIP_KIND)->sole()->payload()['tags'][0])->toBe(['a', $clan->address(), '']);

    Queue::assertPushed(PublishNostrEvent::class, 2);
});

test('a forged signature or a foreign author is refused and nothing is stored', function (Closure $tamper, string $reason) {
    [$owner, $signer] = player();
    $draft = new ClanDraft('Laser Eyes', 'LSR');
    $signed = $signer->signTemplates(app(ClanService::class)->prepareCreate($owner, $draft));

    $signed = $tamper($signed);

    expect(fn () => app(ClanService::class)->create($owner, $draft, $signed))
        ->toThrow(fn (RejectedEvent $e) => expect($e->reason)->toBe($reason));

    expect(Clan::query()->count())->toBe(0)
        ->and(NostrEvent::query()->count())->toBe(0);
    Queue::assertNothingPushed();
})->with([
    'forged signature' => [fn (array $signed) => [array_replace($signed[0], ['sig' => str_repeat('ab', 64)]), $signed[1]], 'invalid_signature'],
    'foreign author' => [function (array $signed) {
        $stranger = new TestSigner;

        return array_map(fn (array $event) => $stranger->sign($event['kind'], $event['tags'], $event['content'], $event['created_at']), $signed);
    }, 'foreign_author'],
]);

test('an invite lists the player in the clan roster only, with no lineup, mode or role', function () {
    [$owner, $ownerSigner] = player();
    [$ute] = player();
    $clan = foundClan($owner, $ownerSigner);

    $templates = $this->clans->prepareInvite($owner, $clan, $ute);
    $invite = $this->clans->invite($owner, $clan, $ute, $ownerSigner->signTemplates($templates));

    $clanEvent = SignedEvent::fromInput(NostrEvent::query()->where('kind', Clan::KIND)->latest('id')->firstOrFail()->payload());

    expect(array_column($templates, 'kind'))->toBe([Clan::KIND])
        ->and($clanEvent->tagsNamed('p'))->toBe([[$owner->pubkey, '', 'captain'], [$ute->pubkey, '', 'member']])
        ->and($clan->fresh()->event_id)->toBe($clanEvent->id)
        ->and($invite->status)->toBe(InviteStatus::Pending)
        ->and($invite->getAttributes())->not->toHaveKeys(['lineup_id', 'role'])
        ->and(Lineup::query()->count())->toBe(0)
        ->and($ute->fresh()->clanMember)->toBeNull();
});

test('only the invitee accepts a named invite, with a membership that names the clan and nothing else', function () {
    [$owner, $ownerSigner] = player();
    [$ute, $uteSigner] = player();
    [$stranger] = player();
    $clan = foundClan($owner, $ownerSigner);
    $invite = invitePlayer($clan, $owner, $ownerSigner, $ute);

    $templates = $this->clans->prepareAccept($invite, $ute);

    // Nobody else can take the invite ...
    expect(fn () => $this->clans->prepareAccept($invite, $stranger))->toThrow(ClanRuleViolation::class, 'This invite is no longer open.')
        // ... the owner cannot confirm on the invitee's behalf ...
        ->and(fn () => $this->clans->accept($invite, $ute, $ownerSigner->signTemplates($templates)))
        ->toThrow(fn (RejectedEvent $e) => expect($e->reason)->toBe('foreign_author'))
        // ... and a membership that also names a lineup is not the one asked for.
        ->and(fn () => $this->clans->accept($invite, $ute, [$uteSigner->sign(12150, [['a', $clan->address(), ''], ['a', '32151:'.$owner->pubkey.':laser-eyes/rocket-league/3v3', ''], ['alt', 'Esports clan membership']])]))
        ->toThrow(fn (RejectedEvent $e) => expect($e->reason)->toBe('not_the_prepared_event'))
        ->and($ute->fresh()->clanMember)->toBeNull();

    $this->clans->accept($invite, $ute, $uteSigner->signTemplates($templates));

    $membership = SignedEvent::fromInput(NostrEvent::query()->where(['kind' => EsportsEventRules::MEMBERSHIP_KIND, 'pubkey' => $ute->pubkey])->sole()->payload());

    expect($membership->tagsNamed('a'))->toBe([[$clan->address(), '']])
        ->and(app(EsportsEventRules::class)->check($membership))->toBeNull()
        ->and($ute->fresh()->clanMember->clan_id)->toBe($clan->id)
        ->and($ute->fresh()->clanMember->role)->toBe(ClanRole::Member)
        ->and(LineupSeat::query()->where('user_id', $ute->id)->exists())->toBeFalse()
        ->and($invite->fresh()->status)->toBe(InviteStatus::Accepted);
});

test('the owner builds a lineup from members, and the lineup event lists them without a signature of theirs', function () {
    [$owner, $ownerSigner] = player();
    [$queen, $queenSigner] = player();
    [$nick, $nickSigner] = player();
    $clan = foundClan($owner, $ownerSigner);
    joinClan($clan, $owner, $ownerSigner, $queen, $queenSigner);
    joinClan($clan, $owner, $ownerSigner, $nick, $nickSigner);

    $seats = [$nick->id => LineupRole::Substitute, $queen->id => LineupRole::Player, $owner->id => LineupRole::Captain];
    $templates = $this->clans->prepareLineup($owner, $clan, 'rocket-league', '2v2', $seats);
    $lineup = $this->clans->saveLineup($owner, $clan, 'rocket-league', '2v2', $seats, $ownerSigner->signTemplates($templates));

    $event = SignedEvent::fromInput(NostrEvent::query()->where('kind', Lineup::KIND)->sole()->payload());

    expect($templates)->toHaveCount(1)
        ->and($event->pubkey)->toBe($owner->pubkey)
        ->and(app(EsportsEventRules::class)->check($event))->toBeNull()
        ->and($event->tagsNamed('p'))->toBe([[$owner->pubkey, '', 'captain'], [$queen->pubkey, '', 'player'], [$nick->pubkey, '', 'substitute']])
        ->and($lineup->event_id)->toBe($event->id)
        ->and($lineup->fresh()->isReady())->toBeTrue()
        ->and(array_map(fn (LineupSeat $seat) => $seat->user_id, $lineup->fresh()->activeSeats()))->toBe([$owner->id, $queen->id, $nick->id])
        ->and(NostrEvent::query()->where(['kind' => EsportsEventRules::MEMBERSHIP_KIND, 'pubkey' => $queen->pubkey])->count())->toBe(1);
});

test('only active members can be placed in a lineup', function (Closure $outsider) {
    [$owner, $ownerSigner] = player();
    $clan = foundClan($owner, $ownerSigner);
    $user = $outsider($clan, $owner, $ownerSigner);

    expect(fn () => $this->clans->prepareLineup($owner, $clan, 'rocket-league', '1v1', [$user->id => LineupRole::Player]))
        ->toThrow(ClanRuleViolation::class, 'Only players of Laser Eyes can be placed in a lineup.')
        ->and(Lineup::query()->count())->toBe(0)
        ->and(NostrEvent::query()->where('kind', Lineup::KIND)->count())->toBe(0);
})->with([
    'a stranger' => [fn () => player()[0]],
    'an invited player who has not accepted' => [function (Clan $clan, User $owner, TestSigner $ownerSigner) {
        [$ute] = player();
        invitePlayer($clan, $owner, $ownerSigner, $ute);

        return $ute;
    }],
    'a member of another clan' => [function () {
        [$other, $otherSigner] = player();
        foundClan($other, $otherSigner, 'Mempool Maniacs', 'MMP');

        return $other;
    }],
]);

test('a player is never in two clans', function () {
    [$ownerA, $signerA] = player();
    [$ownerB, $signerB] = player();
    [$nick, $nickSigner] = player();
    $clanA = foundClan($ownerA, $signerA);
    $clanB = foundClan($ownerB, $signerB, 'Mempool Maniacs', 'MMP');

    // A membership naming two clans breaks NIP rule 9.
    $twoClans = SignedEvent::fromInput($nickSigner->sign(12150, [['a', $clanA->address(), ''], ['a', $clanB->address(), ''], ['alt', 'x']]));
    expect(app(EsportsEventRules::class)->check($twoClans))->toBe('membership_two_clans');

    // Accepting a second clan's invite switches clans instead of adding one.
    joinClan($clanA, $ownerA, $signerA, $nick, $nickSigner);
    saveLineup($clanA, $ownerA, $signerA, '1v1', [$nick->id => LineupRole::Player]);
    joinClan($clanB, $ownerB, $signerB, $nick, $nickSigner);

    expect(ClanMember::query()->where('user_id', $nick->id)->pluck('clan_id')->all())->toBe([$clanB->id])
        ->and($clanA->lineups()->sole()->seats()->where('user_id', $nick->id)->exists())->toBeFalse();

    // And the database refuses a second row outright.
    expect(fn () => ClanMember::query()->create(['clan_id' => $clanA->id, 'user_id' => $nick->id, 'role' => ClanRole::Member, 'joined_at' => now()]))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('a member who leaves drops out of every lineup, and a lineup below its size stops being ready', function () {
    [$owner, $ownerSigner] = player();
    [$queen, $queenSigner] = player();
    [$nick, $nickSigner] = player();
    $clan = foundClan($owner, $ownerSigner);
    joinClan($clan, $owner, $ownerSigner, $queen, $queenSigner);
    joinClan($clan, $owner, $ownerSigner, $nick, $nickSigner);
    $twos = saveLineup($clan, $owner, $ownerSigner, '2v2', [$owner->id => LineupRole::Captain, $queen->id => LineupRole::Player, $nick->id => LineupRole::Substitute]);
    $solo = saveLineup($clan, $owner, $ownerSigner, '1v1', [$queen->id => LineupRole::Player]);

    $this->clans->leave($queen, $queenSigner->signTemplates($this->clans->prepareLeave($queen)));

    expect(LineupSeat::query()->where('user_id', $queen->id)->exists())->toBeFalse()
        ->and($twos->fresh()->activeCount())->toBe(1)
        ->and($twos->fresh()->isReady())->toBeFalse()
        ->and($solo->fresh()->isReady())->toBeFalse();

    // Below the mode's size the lineup is kept but not published (rule 8).
    $refill = [$owner->id => LineupRole::Captain, $nick->id => LineupRole::Substitute];
    expect($this->clans->prepareLineup($owner, $clan, 'rocket-league', '2v2', $refill))->toBe([]);

    $this->clans->saveLineup($owner, $clan, 'rocket-league', '2v2', $refill, []);
    expect($twos->fresh()->event_id)->toBeNull()
        ->and($twos->fresh()->seats()->pluck('role', 'user_id')->all())->toBe([$owner->id => LineupRole::Captain, $nick->id => LineupRole::Substitute]);
});

test('the owner hands over before leaving, and the last member leaving ends the clan', function () {
    [$owner, $ownerSigner] = player();
    [$queen, $queenSigner] = player();
    [$nick, $nickSigner] = player();
    $clan = foundClan($owner, $ownerSigner);
    joinClan($clan, $owner, $ownerSigner, $queen, $queenSigner);
    joinClan($clan, $owner, $ownerSigner, $nick, $nickSigner);
    $lineup = saveLineup($clan, $owner, $ownerSigner, '2v2', [$owner->id => LineupRole::Captain, $queen->id => LineupRole::Player, $nick->id => LineupRole::Player]);

    // Removing one player keeps 2 of 2: signed again without them.
    $this->clans->remove($owner, $clan, $nick, $ownerSigner->signTemplates($this->clans->prepareRemove($owner, $clan, $nick)));
    expect($lineup->fresh()->isReady())->toBeTrue()
        ->and(SignedEvent::fromInput(NostrEvent::query()->where('kind', Lineup::KIND)->latest('id')->firstOrFail()->payload())->tagsNamed('p'))
        ->toBe([[$owner->pubkey, '', 'captain'], [$queen->pubkey, '', 'player']]);

    expect(fn () => $this->clans->prepareLeave($owner))->toThrow(ClanRuleViolation::class);

    $this->clans->makeCaptain($owner, $clan, $queen, $ownerSigner->signTemplates($this->clans->prepareMakeCaptain($owner, $clan, $queen)));
    $this->clans->leave($owner, $ownerSigner->signTemplates($this->clans->prepareLeave($owner)));

    expect($clan->fresh()->members()->pluck('user_id')->all())->toBe([$queen->id])
        ->and($lineup->fresh()->isReady())->toBeFalse();

    $this->clans->leave($queen, $queenSigner->signTemplates($this->clans->prepareLeave($queen)));
    expect(Clan::query()->count())->toBe(0);
});

test('P7e: a clan whose last member deletes the account ends as if they had left; with members left it stays', function () {
    [$owner, $ownerSigner] = player();
    [$queen, $queenSigner] = player();
    $clan = foundClan($owner, $ownerSigner);
    joinClan($clan, $owner, $ownerSigner, $queen, $queenSigner);
    saveLineup($clan, $owner, $ownerSigner, '2v2', [$owner->id => LineupRole::Captain, $queen->id => LineupRole::Player]);

    // A member deletes the account: the clan goes on with the owner.
    app(DeleteAccount::class)($queen);

    expect(Clan::query()->whereKey($clan->id)->exists())->toBeTrue()
        ->and(ClanMember::query()->where('clan_id', $clan->id)->pluck('user_id')->all())->toBe([$owner->id]);

    // The last member deletes the account: the clan and its lineups end, the departures stay.
    app(DeleteAccount::class)($owner);

    expect(Clan::query()->whereKey($clan->id)->exists())->toBeFalse()
        ->and(Lineup::query()->where('clan_id', $clan->id)->exists())->toBeFalse()
        ->and(ClanDeparture::query()->where('clan_id', $clan->id)->orderBy('id')->get()->map(fn (ClanDeparture $row) => [$row->user_id, $row->pubkey, $row->reason, $row->clan_name])->all())
        ->toBe([[null, $queenSigner->pubkey, 'deleted', 'Laser Eyes'], [null, $ownerSigner->pubkey, 'deleted', 'Laser Eyes']]);
});
