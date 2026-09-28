<?php

/*
 * P57: wherever rated play is refused or skipped because two players do not
 * list each other as opponents, the page says so with the name, and puts
 * the fix next to it: add them (or accept their request), or play casual.
 * Challenge (sender and the other lineup's captains), answer (answering
 * captain and sender) and the rated blitz queue (who searches rated and
 * lists you). The real opponent lists decide what the notice shows.
 */

use App\Models\ChessQueueEntry;
use App\Models\Clan;
use App\Models\Lineup;
use App\Models\NostrEvent;
use App\Models\TrustRank;
use App\Models\TrustRun;
use App\Models\User;
use App\Support\Chess\ChessQueue;
use App\Support\Nostr\SignedEvent;
use App\Support\SeasonChain\AnchoredTrustFacts;
use App\Support\SeasonChain\Opponents;
use App\Support\SeasonChain\Seasons;
use App\Support\SeasonChain\TrustFacts;
use App\Support\Series\ChallengeDraft;
use App\Support\Series\SeriesRuleViolation;
use App\Support\Series\SeriesService;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\TestSigner;

beforeEach(function () {
    Queue::fake();
    $this->league = new TestSigner;
    config(['esports.league.nsec' => $this->league->secret]);
    openSeason();
});

/** @return array{0: Lineup, 1: User, 2: TestSigner} a ready 1v1 lineup and its captain */
function p57Lineup(string $name): array
{
    $signer = new TestSigner;
    $captain = User::factory()->withPubkey($signer->pubkey)->create(['name' => $name]);
    $lineup = Lineup::factory()->mode('1v1')->ready()->create(['clan_id' => Clan::factory()->create(['owner_id' => $captain->id, 'name' => $name.' clan'])->id]);

    return [$lineup->load('clan', 'seats.user'), $captain, $signer];
}

function p57List(User $player, TestSigner $signer, User $opponent): void
{
    $opponents = app(Opponents::class);
    $opponents->add($player, $opponent, $signer->signTemplates($opponents->prepareAdd($player, $opponent)));
    test()->travel(1)->seconds();
}

/** A trust run of the live season: every given player Trusted (rank 100). */
function p57Trusted(User ...$players): void
{
    $trust = new TestSigner;
    $anchors = NostrEvent::fromSigned(SignedEvent::fromInput($trust->sign(30000, [['d', 'esports/x/anchors'], ['alt', 'anchors']])));
    $run = TrustRun::query()->create(['season_id' => Seasons::live()?->id, 'trust_pubkey' => $trust->pubkey, 'anchor_list_nostr_event_id' => $anchors->id,
        'anchors' => 0, 'lists' => 0, 'ranked' => count($players), 'published' => count($players), 'computed_at' => now()]);

    foreach ($players as $player) {
        $assertion = NostrEvent::fromSigned(SignedEvent::fromInput($trust->sign(30382, [['d', $player->pubkey], ['p', $player->pubkey], ['rank', '100'], ['alt', 'rank']])));
        TrustRank::query()->create(['pubkey' => $player->pubkey, 'rank' => 100, 'raw' => 1.0, 'anchor_list_event_id' => $anchors->event_id,
            'trust_run_id' => $run->id, 'nostr_event_id' => $assertion->id, 'event_id' => $assertion->event_id]);
    }
}

test('challenge: rated to a clan whose captain you do not list each other with names the captain, offers the add, and casual; the refusal holds until both listed', function () {
    app()->bind(TrustFacts::class, AnchoredTrustFacts::class);
    [$a, $b] = [p57Lineup('anna'), p57Lineup('bert')];
    p57Trusted(...collect([$a[1], $b[1], ...array_map(fn ($seat) => $seat->user, [...$a[0]->activeSeats(), ...$b[0]->activeSeats()])])->unique('id')->values()->all());

    $page = Livewire::actingAs($a[1])->test('pages::challenges.create')
        ->set('lineupId', $a[0]->id)->set('rated', true)->call('pickOpponent', $b[0]->id)
        ->assertSee("You and bert clan's captain do not list each other as opponents yet, so this challenge cannot be rated.")
        ->assertSeeHtml('data-test="needs-mutual-casual"');

    // The server refuses the rated challenge for the same reason.
    $start = now()->addHour()->startOfMinute()->getTimestamp();
    expect(fn () => app(SeriesService::class)->prepareChallenge($a[1], new ChallengeDraft($a[0]->id, $b[0]->id, 3, true, [$start], $start - 600, '')))
        ->toThrow(SeriesRuleViolation::class, 'Rated play needs both captains to have added each other as opponents.');

    // The fix, as the notice's button does it: anna adds bert; bert has a request and accepts.
    Livewire::actingAs($a[1])->test('opponent-button', ['player' => $b[1], 'inline' => true])->assertSee('Add bert as opponent');
    p57List($a[1], $a[2], $b[1]);
    Livewire::actingAs($b[1])->test('opponent-button', ['player' => $a[1], 'inline' => true])->assertSee("Accept anna's request");
    p57List($b[1], $b[2], $a[1]);

    $page->call('opponentListChanged')->assertDontSeeHtml('data-test="needs-mutual"');
    expect(app(SeriesService::class)->prepareChallenge($a[1], new ChallengeDraft($a[0]->id, $b[0]->id, 3, true, [now()->addHour()->startOfMinute()->getTimestamp()], now()->addHour()->startOfMinute()->getTimestamp() - 600, '')))->toBeArray();
});

test('challenge: "Make it casual instead" switches the draft to casual and the notice goes', function () {
    app()->bind(TrustFacts::class, AnchoredTrustFacts::class);
    [$a, $b] = [p57Lineup('anna'), p57Lineup('bert')];
    p57Trusted($a[1], $b[1]);

    Livewire::actingAs($a[1])->test('pages::challenges.create')
        ->set('lineupId', $a[0]->id)->set('rated', true)->call('pickOpponent', $b[0]->id)
        ->assertSeeHtml('data-test="needs-mutual"')
        ->set('rated', false)
        ->assertDontSeeHtml('data-test="needs-mutual"');
});

test('answer: the challenged captain who does not list the sender sees why a rated accept fails, the add, and a casual challenge back', function () {
    [$a, $b] = [p57Lineup('anna'), p57Lineup('bert')];
    // Facts that let the challenge through; the room reads the real lists, where only anna lists bert.
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
    $service = app(SeriesService::class);
    $start = now()->addHour()->startOfMinute()->getTimestamp();
    $draft = new ChallengeDraft($a[0]->id, $b[0]->id, 3, true, [$start], $start - 600, '');
    $match = $service->challenge($a[1], $draft, $a[2]->signTemplates($service->prepareChallenge($a[1], $draft)['templates']));
    p57List($a[1], $a[2], $b[1]);

    $this->actingAs($b[1])->get(route('matches.room', $match))->assertOk()
        ->assertSee('You and anna do not list each other as opponents yet, so you cannot accept this rated challenge.')
        ->assertSee("Accept anna's request")
        ->assertSee(route('challenges.create', ['to' => $a[0]->id, 'game' => $a[0]->game]));

    // The sender's side of the room shows no such notice; neither does a mutual pair.
    $this->actingAs($a[1])->get(route('matches.room', $match))->assertOk()->assertDontSee('data-test="needs-mutual"', false);
    p57List($b[1], $b[2], $a[1]);
    $this->actingAs($b[1])->get(route('matches.room', $match))->assertOk()->assertDontSee('data-test="needs-mutual"', false);
});

test('rated queue: players searching rated who do not list each other are told in counts only, the one listed is sent to the requests, accepts, and the next poll pairs them', function () {
    config(['esports.chess.rated_queue' => true]);
    app()->bind(TrustFacts::class, AnchoredTrustFacts::class);
    $signers = [];
    $users = [];
    foreach (['anna', 'bert', 'carl'] as $name) {
        $signers[$name] = new TestSigner;
        $users[$name] = User::factory()->withPubkey($signers[$name]->pubkey)->create(['name' => $name]);
    }
    ['anna' => $anna, 'bert' => $bert, 'carl' => $carl] = $users;
    p57Trusted($anna, $bert, $carl);
    // Both list each other with carl (so Rated is open for them); bert also lists anna.
    foreach ([['anna', 'carl'], ['carl', 'anna'], ['bert', 'carl'], ['carl', 'bert'], ['bert', 'anna']] as [$from, $to]) {
        p57List($users[$from], $signers[$from], $users[$to]);
    }

    Livewire::actingAs($bert)->test('pages::chess.lobby')->call('findOpponent', true);
    $annaLobby = Livewire::actingAs($anna)->test('pages::chess.lobby')->call('findOpponent', true);

    expect(ChessQueueEntry::query()->where('rated', true)->count())->toBe(2);
    // Counts only: nobody searching is named (live presence, P57 review).
    $annaLobby->assertSee('1 other player searches rated right now, but you do not list each other, so the queue cannot pair you.')
        ->assertSee('1 player in the queue lists you. Accept the request on your Opponents page and the queue can pair you.')
        ->assertSeeHtml('href="'.route('settings.opponents').'#requests"')
        ->assertDontSee('bert');
    Livewire::actingAs($bert)->test('pages::chess.lobby')
        ->assertSee('1 other player searches rated right now, but you do not list each other, so the queue cannot pair you.')
        ->assertSee('A rated game needs both of you to add the other as an opponent. Casual pairs you with anyone.')
        ->assertDontSeeHtml('data-test="needs-mutual-requests"')
        ->assertDontSee('>anna<', false);

    // Anna accepts (the notice's button is the signed add); the poll pairs them rated.
    p57List($anna, $signers['anna'], $bert);
    $annaLobby->call('opponentListChanged')->assertDontSeeHtml('data-test="needs-mutual"');
    app(ChessQueue::class)->pair($anna);

    expect(ChessQueueEntry::query()->count())->toBe(0);
});

test('rated queue: "Search casual instead" leaves the rated search and searches casual', function () {
    config(['esports.chess.rated_queue' => true]);
    app()->bind(TrustFacts::class, AnchoredTrustFacts::class);
    $annaSigner = new TestSigner;
    $anna = User::factory()->withPubkey($annaSigner->pubkey)->create(['name' => 'anna']);
    $carlSigner = new TestSigner;
    $carl = User::factory()->withPubkey($carlSigner->pubkey)->create(['name' => 'carl']);
    p57Trusted($anna, $carl);
    p57List($anna, $annaSigner, $carl);
    p57List($carl, $carlSigner, $anna);

    Livewire::actingAs($anna)->test('pages::chess.lobby')->call('findOpponent', true)->call('searchCasualInstead');

    expect(ChessQueueEntry::query()->where('user_id', $anna->id)->sole()->rated)->toBeFalse();
});
