<?php

/*
 * Solo blocks in the app (plan "AoE2 und Trackmania", P7; NIP rev. 9.18): a
 * score window the league opened itself is attested once its leaderboard is
 * final and the chain's review time is over, and its winner mines one block
 * when the solo rules hold. A window an organizer opened stays a tournament
 * and never mines. In the chain draft a score game is only a proposal the
 * board fills in and saves, never a silent change of the other shares.
 */

use App\Enums\ClanRole;
use App\Enums\TournamentStatus;
use App\Livewire\Actions\DeleteAccount;
use App\Models\Clan;
use App\Models\ClanDeparture;
use App\Models\ClanMember;
use App\Models\NostrEvent;
use App\Models\Season;
use App\Models\SeasonAttestation;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\Nostr\NostrKeys;
use App\Support\Nostr\SignedEvent;
use App\Support\Rating\RatingSettings;
use App\Support\Scores\ScoreLeaderboards;
use App\Support\Scores\ScoreWindow;
use App\Support\SeasonChain\ChainDraft;
use App\Support\SeasonChain\ChainOverview;
use App\Support\SeasonChain\LeagueKey;
use App\Support\SeasonChain\SeasonChains;
use App\Support\SeasonChain\SeasonRelease;
use App\Support\SeasonChain\TrustFacts;
use App\Support\Tournaments\TournamentBrackets;
use App\Support\Tournaments\TournamentRunner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\ScoreDemoOn;
use Tests\Support\TestSigner;
use Tests\Support\TrustedFacts;

/**
 * A running score-demo leaderboard of $n players with a published `31923`,
 * opened by the league ($organizer null, `opened_by_league`) or by an organizer.
 *
 * @return array{0: Tournament, 1: list<User>}
 */
function soloWindow(int $n, ?User $organizer = null, string $slug = 'score-week-1'): array
{
    $tournament = Tournament::factory()->scoreDemo()->create([
        'status' => TournamentStatus::Running,
        'starts_at' => CarbonImmutable::parse('2026-10-05 17:00:00'),
        'capacity' => $n,
        'created_by_id' => $organizer?->id,
    ]);
    // As the league's own code marks it (BlockfillWeeks::open()); never mass assignable.
    $tournament->forceFill(['opened_by_league' => $organizer === null])->save();
    $users = [];

    foreach (range(1, $n) as $index) {
        $users[] = $user = User::factory()->create();
        TournamentParticipant::query()->create(['tournament_id' => $tournament->id, 'user_id' => $user->id, 'name' => "Player {$index}", 'rating' => 1500, 'members' => [$user->id]]);
    }

    app(TournamentBrackets::class)->generate($tournament, str_repeat('cd', 32));
    app(TournamentRunner::class)->sync($tournament);
    $event = LeagueKey::required()->publish(Tournament::CALENDAR_EVENT, [['d', $slug], ['title', 'Score Week'], ['alt', 'Calendar event']], '', now()->getTimestamp());
    $tournament->forceFill(['event_id' => $event->id, 'slug' => $slug])->save();

    return [$tournament->refresh(), $users];
}

/**
 * Every player sets a time inside the window, the first the fastest, and the
 * league reads them.
 *
 * @param  list<User>  $users
 */
function soloPlay(Tournament $tournament, array $users): void
{
    $start = ScoreWindow::of($tournament)->start;

    foreach ($users as $index => $user) {
        test()->fake->record($user->id, 'demo-1', 50_000 + 1_000 * $index, $start->addHours(1 + $index));
    }

    test()->travelTo($start->addHours(12));
    app(ScoreLeaderboards::class)->snapshot($tournament);
}

beforeEach(function () {
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-04 12:00:00'));
    $this->fake = ScoreDemoOn::play();
    app()->bind(TrustFacts::class, TrustedFacts::class);
});

describe('mining', function () {
    beforeEach(function () {
        $parameters = ChainDraft::defaults();
        $this->season = openSeason([
            'genesis_at' => now()->startOfSecond(),
            'ends_at' => now()->addWeeks(24)->startOfSecond(),
            'parameters' => [
                'weights' => [...$parameters['weights'], 'score-demo/time-trial' => 1000],
                'groups' => $parameters['groups'],
                'shares' => ['chess' => 30, 'rocket-league' => 35, 'ea-sports-fc' => 25, 'score-demo' => 10],
                'daily' => [...$parameters['daily'], 'score-demo' => 1],
                'pairlimit' => $parameters['pairlimit'],
                'subtree' => $parameters['subtree'],
                'moves' => $parameters['moves'],
            ],
        ]);
    });

    test('a score window with every solo rule met attests exactly one block for its winner, after the review time', function () {
        [$tournament, $users] = soloWindow(5);
        soloPlay($tournament, $users);
        $end = ScoreWindow::of($tournament)->end;

        // The leaderboard is final after its own review (24 h), the chain waits for its review (48 h).
        $this->travelTo($end->addHours(25));
        app(ScoreLeaderboards::class)->tick();

        expect($tournament->refresh()->status)->toBe(TournamentStatus::Finished)
            ->and(SeasonAttestation::query()->count())->toBe(0);

        $this->travelTo($end->addHours(48));
        app(ScoreLeaderboards::class)->tick();
        app(ScoreLeaderboards::class)->tick();

        $block = SeasonAttestation::query()->sole();
        $event = SignedEvent::fromInput(NostrEvent::query()->findOrFail($block->nostr_event_id)->payload());

        expect($block->source)->toBe(SeasonAttestation::SCORE)
            ->and($block->source_id)->toBe($tournament->id)
            ->and([$block->height, $block->rule, $block->reward_per_player, $block->reward])->toBe([1, null, 2_100, 2_100])
            ->and($block->winners())->toBe([$users[0]->pubkey])
            ->and($block->candidate['losers'])->toBe([])
            ->and($block->candidate['solo']['entrants'])->toHaveCount(4)
            ->and($block->ladder_address)->toBe($tournament->address())
            ->and($event->kind)->toBe(SeasonChains::ATTESTATION)
            ->and($event->hasValidSignature())->toBeTrue()
            ->and($event->tagsNamed('block'))->toBe([['1', $this->season->genesisId()]])
            ->and($event->tagsNamed('a'))->toBe([[$tournament->address(), '']])
            ->and($event->tagsNamed('p'))->toBe([[$users[0]->pubkey, '', 'winner']])
            ->and($event->tag('resolution'))->toBe('admin')
            ->and($event->tagsNamed('window'))->toBe([[(string) ScoreWindow::of($tournament)->start->getTimestamp(), (string) $end->getTimestamp()]])
            ->and($event->tagsNamed('gate'))->toHaveCount(5)
            ->and($event->tagsNamed('elo'))->toBe([]);
    });

    test('a window with fewer entrants than the minimum is attested without a block', function () {
        [$tournament, $users] = soloWindow(4);
        soloPlay($tournament, $users);
        $this->travelTo(ScoreWindow::of($tournament)->end->addHours(49));
        app(ScoreLeaderboards::class)->tick();
        app(ScoreLeaderboards::class)->tick();

        $attestation = SeasonAttestation::query()->sole();
        $event = SignedEvent::fromInput(NostrEvent::query()->findOrFail($attestation->nostr_event_id)->payload());

        expect([$attestation->height, $attestation->rule, $attestation->reason, $attestation->reward])->toBe([null, 1, 'too-few-entrants', 0])
            ->and($event->tagsNamed('block'))->toBe([['', $this->season->genesisId()]])
            ->and(ChainOverview::reasonLabel('too-few-entrants'))->not->toBe('too-few-entrants');
    });

    test('a window an organizer opened stays a tournament: never attested, never a block', function () {
        [$tournament, $users] = soloWindow(5, User::factory()->create());
        soloPlay($tournament, $users);
        $this->travelTo(ScoreWindow::of($tournament)->end->addHours(49));
        app(ScoreLeaderboards::class)->tick();
        app(ScoreLeaderboards::class)->tick();

        expect($tournament->refresh()->status)->toBe(TournamentStatus::Finished)
            ->and(SeasonAttestation::query()->count())->toBe(0)
            ->and(app(SeasonChains::class)->attestScoreWindow($tournament))->toBeNull();
    });

    test('a window whose organizer deleted the account stays a tournament: never attested, never a block (audit F1)', function () {
        $organizer = User::factory()->create();
        [$tournament, $users] = soloWindow(5, $organizer);
        soloPlay($tournament, $users);
        $this->travelTo(ScoreWindow::of($tournament)->end->addHours(25));
        app(ScoreLeaderboards::class)->tick();

        $this->actingAs($organizer);
        app(DeleteAccount::class)($organizer);
        $this->travelTo(ScoreWindow::of($tournament)->end->addHours(49));
        app(ScoreLeaderboards::class)->tick();
        app(ScoreLeaderboards::class)->tick();

        expect($tournament->refresh()->created_by_id)->toBeNull()
            ->and(SeasonAttestation::query()->count())->toBe(0)
            ->and(app(SeasonChains::class)->attestScoreWindow($tournament))->toBeNull()
            ->and($tournament->opened_by_league)->toBeFalse();
    });

    test('a winner who leaves the field\'s clan after the window still counts its players as clan mates, and the clan he left is published (audit F2)', function () {
        [$tournament, $users] = soloWindow(5);
        // One clan for all five (its factory seats the owner, the second player).
        $clan = Clan::factory()->create(['owner_id' => $users[1]->id]);

        foreach ([$users[0], ...array_slice($users, 2)] as $member) {
            ClanMember::query()->create(['clan_id' => $clan->id, 'user_id' => $member->id, 'role' => ClanRole::Member, 'joined_at' => now()]);
        }
        soloPlay($tournament, $users);
        $end = ScoreWindow::of($tournament)->end;
        $this->travelTo($end->addHours(30));
        app(ScoreLeaderboards::class)->tick();

        // As ClanService::leaveCurrentClan(): the membership goes, a departure stays.
        ClanMember::query()->where('user_id', $users[0]->id)->delete();
        ClanDeparture::query()->create(['clan_id' => $clan->id, 'clan_address' => $clan->address(), 'clan_name' => $clan->name,
            'user_id' => $users[0]->id, 'pubkey' => $users[0]->pubkey, 'reason' => 'left', 'left_at' => now()]);

        $this->travelTo($end->addHours(49));
        app(ScoreLeaderboards::class)->tick();
        app(ScoreLeaderboards::class)->tick();

        $attestation = SeasonAttestation::query()->sole();
        $event = SignedEvent::fromInput(NostrEvent::query()->findOrFail($attestation->nostr_event_id)->payload());

        expect([$attestation->height, $attestation->rule, $attestation->reason, $attestation->reward])->toBe([null, 3, 'same-clan', 0])
            ->and($event->tagsNamed('clan'))->toContain([$users[0]->pubkey, $clan->address()])
            ->and($event->tagsNamed('clan'))->toHaveCount(5);
    });

    test('without trust facts for the live season the league signs nothing and tries again on the next tick (audit F3)', function () {
        app()->bind(TrustFacts::class, fn () => new class implements TrustFacts
        {
            public function available(): bool
            {
                return false;
            }

            public function at(array $players, array $gatekeepers): array
            {
                return ['trust' => [], 'anchors' => [], 'connected' => false];
            }
        });
        [$tournament, $users] = soloWindow(5);
        soloPlay($tournament, $users);
        $this->travelTo(ScoreWindow::of($tournament)->end->addHours(49));
        app(ScoreLeaderboards::class)->tick();
        app(ScoreLeaderboards::class)->tick();

        expect(SeasonAttestation::query()->count())->toBe(0);

        app()->bind(TrustFacts::class, TrustedFacts::class);
        app(ScoreLeaderboards::class)->tick();

        expect(SeasonAttestation::query()->sole()->height)->toBe(1);
    });

    test('an entry whose frozen standing points at another player\'s run is no verified entrant (audit N1)', function () {
        [$tournament, $users] = soloWindow(5);
        soloPlay($tournament, $users);
        $this->travelTo(ScoreWindow::of($tournament)->end->addHours(25));
        app(ScoreLeaderboards::class)->tick();

        // The last entry's frozen row borrows the winner's run.
        $board = TournamentMatch::query()->where(['tournament_id' => $tournament->id, 'bracket' => 'board'])->sole();
        $result = $board->result;
        $winnerRun = $result['standings'][0]['run'];
        $result['standings'][4]['run'] = $winnerRun;
        $board->forceFill(['result' => $result])->save();

        $this->travelTo(ScoreWindow::of($tournament)->end->addHours(49));
        app(ScoreLeaderboards::class)->tick();

        $attestation = SeasonAttestation::query()->sole();

        expect([$attestation->height, $attestation->reason])->toBe([null, 'too-few-entrants'])
            ->and($attestation->candidate['solo']['entrants'])->toHaveCount(3);
    });

    test('attesting the same window twice keeps one attestation', function () {
        [$tournament, $users] = soloWindow(5);
        soloPlay($tournament, $users);
        $this->travelTo(ScoreWindow::of($tournament)->end->addHours(49));
        app(ScoreLeaderboards::class)->tick();
        app(ScoreLeaderboards::class)->tick();
        $first = SeasonAttestation::query()->sole();

        expect(app(SeasonChains::class)->attestScoreWindow($tournament->refresh())->id)->toBe($first->id)
            ->and(SeasonAttestation::query()->count())->toBe(1);
    });

    test('a score game whose switch is on can mine; ChainOverview says so', function () {
        expect(ChainOverview::mines('score-demo/time-trial'))->toBeTrue();
    });
});

describe('chain draft', function () {
    beforeEach(function () {
        $this->board = User::factory()->withPubkey((new TestSigner)->pubkey)->create(['timezone' => 'UTC']);
        config(['esports.board' => [NostrKeys::hexToNpub($this->board->pubkey)], 'esports.league.nsec' => (new TestSigner)->secret, 'esports.trust.nsec' => (new TestSigner)->secret]);
    });

    test('a score game is a row of the draft without a weight: it does not mine by default', function () {
        $defaults = ChainDraft::defaults();

        expect(ChainDraft::table($defaults))->toHaveKey('score-demo')
            ->and(ChainDraft::table($defaults)['score-demo'])->toBe(['score-demo/time-trial', 'score-demo/highscore'])
            ->and($defaults['weights'])->not->toHaveKey('score-demo/time-trial')
            ->and($defaults['shares'])->toBe(['chess' => 35, 'rocket-league' => 40, 'ea-sports-fc' => 25]);
    });

    test('the chain form shows a score game only as a proposal; filling it in saves nothing, saving it shrinks the other shares visibly', function () {
        Livewire::actingAs($this->board)->test('pages::admin.season')
            ->assertSee('data-test="score-demo-proposal"', false)
            ->assertSet('draftWeights.score-demo/time-trial', '')
            ->assertSet('draftShares.score-demo', '')
            ->assertSet('draftShares.chess', '35')
            ->call('fillProposal', 'score-demo')
            ->assertSet('draftWeights.score-demo/time-trial', '1')
            ->assertSet('draftShares.score-demo', '5')
            ->assertSet('draftDaily.score-demo', '1')
            ->assertSet('draftShares.chess', '33')
            ->tap(fn () => expect(ChainDraft::stored())->toBeNull())
            ->call('saveDraft')
            ->assertSet('draftError', '')
            ->assertDontSee('data-test="score-demo-proposal"', false);

        expect(ChainDraft::stored()['weights'])->toMatchArray(['score-demo/time-trial' => 1000, 'chess/blitz' => 1000])
            ->and(ChainDraft::stored()['shares'])->toBe(['chess' => 33, 'rocket-league' => 38, 'ea-sports-fc' => 24, 'score-demo' => 5]);
    });

    test('Block 0 signs the solo rules after moves only when a score game mines; a draft of versus games signs the tags it always did', function () {
        $versus = SeasonRelease::draft();
        $versusNames = array_column(SeasonRelease::parameterTags($versus, 1_790_353_500), 0);

        $defaults = ChainDraft::defaults();
        saveChainDraft(['weights' => [...$defaults['weights'], 'score-demo/time-trial' => 1000], 'shares' => ['chess' => 35, 'rocket-league' => 40, 'ea-sports-fc' => 20, 'score-demo' => 5], 'daily' => [...$defaults['daily'], 'score-demo' => 1]]);
        RatingSettings::forget();
        $score = SeasonRelease::draft();
        $tags = SeasonRelease::parameterTags($score, 1_790_353_500);
        $names = array_column($tags, 0);

        expect($versus['parameters'])->not->toHaveKey('solo')
            ->and($versusNames)->not->toContain('solo')
            ->and($score['parameters']['solo'])->toBe([5, 3, 172_800])
            ->and($tags[array_search('solo', $names, true)])->toBe(['solo', '5', '3', '172800'])
            ->and(array_search('solo', $names, true))->toBe(array_search('moves', $names, true) + 1);
    });

    test('a season whose genesis carries solo values reads them; one without reads the NIP defaults', function () {
        $with = Season::factory()->make(['parameters' => [...Season::factory()->make()->parameters, 'solo' => [7, 2, 3600]]])->chainParameters()->genesis;
        $without = Season::factory()->make()->chainParameters()->genesis;

        expect([$with->soloEntrants, $with->soloWins, $with->soloReview])->toBe([7, 2, 3600])
            ->and([$without->soloEntrants, $without->soloWins, $without->soloReview])->toBe([5, 3, 172_800]);
    });

    test('saving the draft without the proposal leaves the score game unmined and the other shares as they were', function () {
        Livewire::actingAs($this->board)->test('pages::admin.season')
            ->assertSee('data-test="draft-share-score-demo"', false)
            ->assertSee('data-test="draft-weight-score-demo-time-trial"', false)
            ->set('draftWeights.chess/blitz', '1.5')
            ->call('saveDraft')
            ->assertSet('draftError', '');

        expect(ChainDraft::stored()['weights'])->not->toHaveKey('score-demo/time-trial')
            ->and(ChainDraft::stored()['shares'])->toBe(['chess' => 35, 'rocket-league' => 40, 'ea-sports-fc' => 25]);
    });
});

test('before Block 0 the forecast counts a closed league window with a top score as one solo win; an organizer\'s window and one nobody placed in do not', function () {
    config(['esports.league.nsec' => (new TestSigner)->secret]);
    [$won, $users] = soloWindow(3);
    soloPlay($won, $users);
    [$organizers, $drivers] = soloWindow(2, User::factory()->create(), 'score-week-2');
    soloPlay($organizers, $drivers);
    [$empty] = soloWindow(2, null, 'score-week-3');
    $end = ScoreWindow::of($won)->end;

    $this->travelTo($end->addHours(25));
    app(ScoreLeaderboards::class)->tick();

    expect([$won->refresh()->status, $organizers->refresh()->status, $empty->refresh()->status])->each->toBe(TournamentStatus::Finished);

    $streams = collect(app(ChainOverview::class)->draft()['streams'])->keyBy('weight_key');

    expect($streams->get('score-demo/time-trial'))->toBe(['weight_key' => 'score-demo/time-trial', 'game' => 'score-demo', 'winners' => 1, 'per_week' => 1 / 4]);
});
