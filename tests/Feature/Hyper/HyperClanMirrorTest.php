<?php

use App\Enums\LineupRole;
use App\Games\Hyperbitcoinization;
use App\Models\Clan;
use App\Models\Lineup;
use App\Models\NostrEvent;
use App\Models\User;
use App\Support\Cards\PageCardFacts;
use App\Support\Clans\ClanDraft;
use App\Support\Clans\ClanService;
use App\Support\Games\GameLanding;
use App\Support\Hyper\HyperTournamentTeams;
use Illuminate\Support\Facades\Queue;
use Tests\Support\HyperOn;
use Tests\Support\TestSigner;

/*
| The mirror lineup a clan enters a Hyperbitcoinization clan bracket with (plan "Hyperbitcoinization", P5b,
| HyperTournamentTeams::lineup()) is no lineup the clan set up (P5c): it is counted on no card or game page, and
| removing a member signs and publishes nothing for it, while the clan's own lineups are signed again as before.
*/

beforeEach(function () {
    Queue::fake();
    HyperOn::play();
});

/**
 * A clan of three players with their signers, founded and joined through the service, a Rocket League 2v2 lineup of
 * all three, and the clan's Hyperbitcoinization mirror.
 *
 * @return array{clan: Clan, owner: User, signer: TestSigner, nick: User, lineup: Lineup, mirror: Lineup}
 */
function hyperMirrorClan(): array
{
    $service = app(ClanService::class);
    $people = [];

    foreach (range(0, 2) as $index) {
        $signer = new TestSigner;
        $people[] = [User::factory()->withPubkey($signer->pubkey)->create(), $signer];
    }

    [[$owner, $ownerSigner], [$queen, $queenSigner], [$nick, $nickSigner]] = $people;
    $draft = new ClanDraft('Laser Eyes', 'LSR', 'Rocket League clan of the Kempten meetup.');
    $clan = $service->create($owner, $draft, $ownerSigner->signTemplates($service->prepareCreate($owner, $draft)));

    foreach ([[$queen, $queenSigner], [$nick, $nickSigner]] as [$player, $signer]) {
        $invite = $service->invite($owner, $clan, $player, $ownerSigner->signTemplates($service->prepareInvite($owner, $clan, $player)));
        $service->accept($invite, $player, $signer->signTemplates($service->prepareAccept($invite, $player)));
    }

    $seats = [$owner->id => LineupRole::Captain, $queen->id => LineupRole::Player, $nick->id => LineupRole::Player];
    $lineup = $service->saveLineup($owner, $clan, 'rocket-league', '2v2', $seats, $ownerSigner->signTemplates($service->prepareLineup($owner, $clan, 'rocket-league', '2v2', $seats)));

    return ['clan' => $clan, 'owner' => $owner, 'signer' => $ownerSigner, 'nick' => $nick, 'lineup' => $lineup, 'mirror' => HyperTournamentTeams::lineup($clan, 'live')];
}

test('removing a member signs the clan\'s own lineup again, never the Hyperbitcoinization mirror', function () {
    ['clan' => $clan, 'owner' => $owner, 'signer' => $signer, 'nick' => $nick, 'lineup' => $lineup, 'mirror' => $mirror] = hyperMirrorClan();
    $service = app(ClanService::class);

    expect($mirror->seats()->where('user_id', $nick->id)->exists())->toBeTrue();

    $templates = $service->prepareRemove($owner, $clan, $nick);
    $lineupDs = collect($templates)->where('kind', Lineup::KIND)
        ->map(fn (array $template): ?string => collect($template['tags'])->firstWhere(0, 'd')[1] ?? null)->values()->all();

    expect($lineupDs)->toBe([$lineup->d()]);

    $service->remove($owner, $clan, $nick, $signer->signTemplates($templates));

    expect(NostrEvent::query()->where('kind', Lineup::KIND)->where('d', 'like', '%/'.Hyperbitcoinization::SLUG.'/%')->exists())->toBeFalse()
        ->and($mirror->fresh()->event_id)->toBeNull()
        ->and($lineup->fresh()->event_id)->not->toBeNull();
});

test('the mirror counts as no lineup of the clan: not on the clan card, not among the game page\'s clans', function () {
    ['clan' => $clan] = hyperMirrorClan();

    expect(Lineup::query()->where('clan_id', $clan->id)->count())->toBe(2)
        ->and(PageCardFacts::clan($clan)['lineups'])->toBe(1)
        ->and(app(GameLanding::class)->pulse(Hyperbitcoinization::SLUG)['clans'])->toBe(0)
        ->and(app(GameLanding::class)->pulse('rocket-league')['clans'])->toBe(1);
});
