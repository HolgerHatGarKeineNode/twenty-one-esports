<?php

use App\Enums\ClanRole;
use App\Enums\InviteStatus;
use App\Enums\LineupRole;
use App\Jobs\PublishNostrEvent;
use App\Models\Clan;
use App\Models\ClanInvite;
use App\Models\ClanMember;
use App\Models\Lineup;
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
function invitePlayer(Clan $clan, User $owner, TestSigner $ownerSigner, User $invitee, string $mode = '3v3', LineupRole $role = LineupRole::Player): ?ClanInvite
{
    $service = app(ClanService::class);
    $templates = $service->prepareInvite($owner, $clan, 'rocket-league', $mode, $invitee, $role);

    return $service->invite($owner, $clan, 'rocket-league', $mode, $invitee, $role, $ownerSigner->signTemplates($templates));
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

test('joining a lineup needs the invitee\'s own signed acceptance', function () {
    [$owner, $ownerSigner] = player();
    [$ute, $uteSigner] = player();
    $clan = foundClan($owner, $ownerSigner);

    $invite = invitePlayer($clan, $owner, $ownerSigner, $ute, role: LineupRole::Substitute);

    expect($ute->fresh()->clanMember)->toBeNull()
        ->and($invite->status)->toBe(InviteStatus::Pending);

    $templates = $this->clans->prepareAccept($invite, $ute);

    // The owner cannot accept on the invitee's behalf ...
    expect(fn () => $this->clans->accept($invite, $ute, $ownerSigner->signTemplates($templates)))
        ->toThrow(RejectedEvent::class);
    // ... and a membership without the invited lineup is not the acceptance.
    $withoutLineup = [$uteSigner->sign(12150, [$templates[0]['tags'][0], ['alt', 'x']])];
    expect(fn () => $this->clans->accept($invite, $ute, $withoutLineup))->toThrow(RejectedEvent::class)
        ->and($ute->fresh()->clanMember)->toBeNull();

    $this->clans->accept($invite, $ute, $uteSigner->signTemplates($templates));

    $seat = Lineup::query()->sole()->seats()->where('user_id', $ute->id)->sole();

    expect($ute->fresh()->clanMember->clan_id)->toBe($clan->id)
        ->and($seat->accepted_at)->not->toBeNull()
        ->and($invite->fresh()->status)->toBe(InviteStatus::Accepted);
});

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
    foreach ([[$clanA, $ownerA, $signerA], [$clanB, $ownerB, $signerB]] as [$clan, $owner, $signer]) {
        $invite = invitePlayer($clan, $owner, $signer, $nick);
        $this->clans->accept($invite, $nick, $nickSigner->signTemplates($this->clans->prepareAccept($invite, $nick)));
    }

    expect(ClanMember::query()->where('user_id', $nick->id)->pluck('clan_id')->all())->toBe([$clanB->id])
        ->and($clanA->lineups()->sole()->seats()->where('user_id', $nick->id)->exists())->toBeFalse();

    // And the database refuses a second row outright.
    expect(fn () => ClanMember::query()->create(['clan_id' => $clanA->id, 'user_id' => $nick->id, 'role' => ClanRole::Member, 'joined_at' => now()]))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('the owner hands over before leaving, and a lineup below its size stops being ready', function () {
    [$owner, $ownerSigner] = player();
    [$queen, $queenSigner] = player();
    [$nick, $nickSigner] = player();
    $clan = foundClan($owner, $ownerSigner);

    $this->clans->invite($owner, $clan, 'rocket-league', '2v2', $owner, LineupRole::Captain,
        $ownerSigner->signTemplates($this->clans->prepareInvite($owner, $clan, 'rocket-league', '2v2', $owner, LineupRole::Captain)));

    foreach ([[$queen, $queenSigner], [$nick, $nickSigner]] as [$user, $signer]) {
        $invite = invitePlayer($clan, $owner, $ownerSigner, $user, '2v2');
        $this->clans->accept($invite, $user, $signer->signTemplates($this->clans->prepareAccept($invite, $user)));
    }

    $lineup = Lineup::query()->where('mode', '2v2')->sole();
    expect($lineup->isReady())->toBeTrue()->and($lineup->event_id)->not->toBeNull();

    // Removing one player keeps 2 of 2: signed again. Removing the next drops it below 2.
    $this->clans->remove($owner, $clan, $nick, $ownerSigner->signTemplates($this->clans->prepareRemove($owner, $clan, $nick)));
    expect($lineup->fresh()->isReady())->toBeTrue();

    expect(fn () => $this->clans->prepareLeave($owner))->toThrow(ClanRuleViolation::class);

    $this->clans->makeCaptain($owner, $clan, $queen, $ownerSigner->signTemplates($this->clans->prepareMakeCaptain($owner, $clan, $queen)));
    $this->clans->leave($owner, $ownerSigner->signTemplates($this->clans->prepareLeave($owner)));

    $lineup = $lineup->fresh();
    expect($clan->fresh()->members()->pluck('user_id')->all())->toBe([$queen->id])
        ->and($lineup->activeCount())->toBe(1)
        ->and($lineup->isReady())->toBeFalse();

    // The last member leaving ends the clan.
    $this->clans->leave($queen, $queenSigner->signTemplates($this->clans->prepareLeave($queen)));
    expect(Clan::query()->count())->toBe(0);
});
