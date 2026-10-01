<?php

/*
| Ranked Blockfill weeks mine (plan "Blockfill", P7): the week the league
| opens is a score window with a solo block (NIP rev. 9.18, plan "AoE2 und
| Trackmania", P7). Its winner mines exactly one block once the window and
| the chain's review time are over, the week's top 3 are reviewed (on their
| own when none carries a cheat hint, else by an admin), its 31923 is signed
| and enough verified players are in the field. Blockfill
| mines only through the chain draft's proposal. The share card and the
| pride slide name the week and the winning time, never a ladder. The
| verifier is a fake (Tests\Support\FakeStackerVerifier); the verdict goes
| through the real job.
*/

use App\Enums\StackerRunStatus;
use App\Enums\TournamentStatus;
use App\Games\Blockfill;
use App\Jobs\VerifyStackerRun;
use App\Models\Admin;
use App\Models\NostrEvent;
use App\Models\ScoreRun;
use App\Models\Season;
use App\Models\SeasonAttestation;
use App\Models\StackerRun;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Cards\ShareCard;
use App\Support\Cards\ShareMoments;
use App\Support\Nostr\NostrKeys;
use App\Support\Nostr\SignedEvent;
use App\Support\Scores\ScoreLeaderboards;
use App\Support\Scores\ScoreWindow;
use App\Support\SeasonChain\ChainDraft;
use App\Support\SeasonChain\SeasonChains;
use App\Support\SeasonChain\TrustFacts;
use App\Support\Stacker\BlockfillWeeks;
use App\Support\Stacker\Verifier;
use App\Support\Tournaments\TournamentRuleViolation;
use App\Support\TwentyOne\Stream\PrideSlides;
use App\Support\TwentyOne\Stream\RotationPlanner;
use App\Support\TwentyOne\Stream\SceneRenderer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\BlockfillOn;
use Tests\Support\FakeStackerVerifier;
use Tests\Support\TestSigner;
use Tests\Support\TrustedFacts;

beforeEach(function () {
    // The verdict goes through the real job; everything else (relay publishing) only queues.
    Queue::fake()->except([VerifyStackerRun::class]);
    $this->withoutVite();
    BlockfillOn::play();
    $this->app->instance(Verifier::class, new FakeStackerVerifier);
    app()->bind(TrustFacts::class, TrustedFacts::class);
    // Monday 2026-10-05, noon Berlin: the week runs from 2026-10-04 22:00 UTC to 2026-10-11 22:00 UTC.
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00:00'));

    $this->admin = User::factory()->create(['name' => 'Reviewer']);
    Admin::query()->create(['pubkey' => $this->admin->pubkey]);
});

/**
 * The live season from Sunday noon on, Blockfill weighted in its genesis:
 * weight 1, a share of 10 % and one block a day.
 */
function blockfillSeason(): Season
{
    $parameters = ChainDraft::defaults();

    return openSeason([
        'genesis_at' => CarbonImmutable::parse('2026-10-04 12:00:00'),
        'ends_at' => CarbonImmutable::parse('2027-03-21 12:00:00'),
        'parameters' => [
            'weights' => [...$parameters['weights'], 'blockfill/40-blocks' => 1000],
            'groups' => $parameters['groups'],
            'shares' => ['chess' => 30, 'rocket-league' => 35, 'ea-sports-fc' => 25, 'blockfill' => 10],
            'daily' => [...$parameters['daily'], 'blockfill' => 1],
            'pairlimit' => $parameters['pairlimit'],
            'subtree' => $parameters['subtree'],
            'moves' => $parameters['moves'],
        ],
    ]);
}

/**
 * In the live season (blockfillSeason()), this week's leaderboard, opened
 * by the league and its 31923 signed (unless `$signed` is false), with one
 * ranked run verified per time in `$ticks`, each by a fresh player and
 * handed in an hour apart.
 *
 * @param  list<int>  $ticks
 * @return array{0: Tournament, 1: list<User>}
 */
function rankedWeek(array $ticks, bool $signed = true): array
{
    test()->season = blockfillSeason();
    $weeks = app(BlockfillWeeks::class);
    $week = $weeks->open();

    if ($signed) {
        $weeks->announce();
    }

    $users = [];
    $start = CarbonImmutable::now();

    foreach ($ticks as $index => $played) {
        $users[] = $user = User::factory()->create(['name' => 'Stacker '.($index + 1)]);
        test()->travelTo($start->addHours($index + 1));
        $run = StackerRun::factory()->for($user)->create([
            'status' => StackerRunStatus::Verifying, 'issued_at' => now(), 'started_at' => now(), 'submitted_at' => now(),
            'ticks' => $played, 'state_hash' => '00000000', 'replay' => 'AAAA',
        ]);
        VerifyStackerRun::dispatchSync($run->id);
    }

    return [$week->refresh(), $users];
}

/** Marks the verified run of `$user` with a cheat hint, as one verified outside its week's top 10 carries it. */
function hintedRun(User $user): void
{
    $run = StackerRun::query()->where('user_id', $user->id)->sole();
    $run->forceFill(['flags' => ['hints' => ['flags' => ['timing'], 'pps' => 0.4, 'maxPressesPerTick' => 1, 'timingCv' => 0.1, 'finesse' => ['perfect' => 3, 'of' => 30]]]])->save();
}

/** Every tick of `scores:tick` at `$at`: the end of the leaderboard and the attestations that are due. */
function tickAt(CarbonImmutable $at): void
{
    test()->travelTo($at);
    app(ScoreLeaderboards::class)->tick();
    app(ScoreLeaderboards::class)->tick();
}

test('a ranked week with a signed 31923, five verified players and its top 3 reviewed mines exactly one block for its winner, after the window and the review time', function () {
    [$week, $users] = rankedWeek([2870, 2900, 2950, 3000, 3100]);
    $end = ScoreWindow::of($week)->end;

    expect($week->created_by_id)->toBeNull()
        ->and($week->address())->not->toBeNull()
        ->and($week->participants()->count())->toBe(5);

    // The leaderboard ends after its own review (24 h); the admin reviews the top 3 then.
    tickAt($end->addHours(25));
    expect($week->refresh()->status)->toBe(TournamentStatus::Finished)
        ->and(SeasonAttestation::query()->count())->toBe(0);

    expect(app(ScoreLeaderboards::class)->confirmReview($week, $this->admin))->toBe(3);

    // Reviewed, but the chain's own review time (48 h) is not over yet.
    tickAt($end->addHours(47));
    expect(SeasonAttestation::query()->count())->toBe(0);

    tickAt($end->addHours(48));
    tickAt($end->addHours(72));

    $block = SeasonAttestation::query()->sole();
    $event = SignedEvent::fromInput(NostrEvent::query()->findOrFail($block->nostr_event_id)->payload());

    expect($block->source)->toBe(SeasonAttestation::SCORE)
        ->and($block->source_id)->toBe($week->id)
        ->and([$block->game, $block->mode])->toBe([Blockfill::SLUG, Blockfill::MODE])
        ->and([$block->height, $block->rule, $block->reason])->toBe([1, null, null])
        ->and($block->winners())->toBe([$users[0]->pubkey])
        ->and($block->candidate['solo']['entrants'])->toBe(array_map(fn (User $user): string => $user->pubkey, array_slice($users, 1)))
        ->and($block->candidate['solo']['source'])->toBe('replay')
        ->and($block->ladder_address)->toBe($week->address())
        ->and($event->hasValidSignature())->toBeTrue()
        ->and($event->tagsNamed('block'))->toBe([['1', $this->season->genesisId()]])
        ->and($event->tagsNamed('p'))->toBe([[$users[0]->pubkey, '', 'winner']]);
});

test('a week whose top 3 carry no cheat hint mines once the chain\'s review time is over, without an admin', function () {
    [$week, $users] = rankedWeek([2870, 2900, 2950, 3000, 3100]);
    $end = ScoreWindow::of($week)->end;
    $leaderboards = app(ScoreLeaderboards::class);

    // The board is final; the top 3 count as reviewed only once the review time (48 h) is over.
    tickAt($end->addHours(47));
    expect($week->refresh()->status)->toBe(TournamentStatus::Finished)
        ->and(SeasonAttestation::query()->count())->toBe(0);

    tickAt($end->addHours(48));

    expect(SeasonAttestation::query()->sole()->winners())->toBe([$users[0]->pubkey])
        ->and($leaderboards->reviewed($week->refresh()))->toBeTrue()
        ->and(ScoreRun::query()->whereNotNull('verified_by_id')->count())->toBe(0);

    // An hour before, the page said so and offered an admin no button.
    $this->travelTo($end->addHours(47));
    expect($leaderboards->reviewed($week))->toBeFalse()
        ->and($leaderboards->needsAdminReview($week))->toBeFalse();
    Livewire::actingAs($this->admin)->test('pages::scores.tournament', ['tournament' => $week])
        ->assertSee('No run of the top 3 carries a cheat hint: they count as reviewed 48 hours after the window')
        ->assertDontSee('data-test="score-review-confirm"', false);
});

test('a week with a cheat hint in its top 3 waits for an admin\'s review; confirmed later, it mines on the next tick', function () {
    [$week, $users] = rankedWeek([2870, 2900, 2950, 3000, 3100]);
    hintedRun($users[1]);
    $end = ScoreWindow::of($week)->end;

    tickAt($end->addHours(49));
    tickAt($end->addHours(96));

    expect($week->refresh()->status)->toBe(TournamentStatus::Finished)
        ->and(app(ScoreLeaderboards::class)->reviewed($week))->toBeFalse()
        ->and(app(ScoreLeaderboards::class)->needsAdminReview($week))->toBeTrue()
        ->and(app(SeasonChains::class)->attestScoreWindow($week))->toBeNull()
        ->and(SeasonAttestation::query()->count())->toBe(0);

    Livewire::actingAs($this->admin)->test('pages::scores.tournament', ['tournament' => $week])
        ->assertSee('A run of the top 3 carries cheat hints')
        ->assertSee('data-test="score-review-confirm"', false)
        ->call('confirmReview')
        ->assertSee(__('The top 3 are reviewed.'));
    tickAt($end->addHours(97));

    expect(SeasonAttestation::query()->sole()->winners())->toBe([$users[0]->pubkey]);
});

test('a player who moves into the top 3 after the review needs a review of his own', function () {
    [$week, $users] = rankedWeek([2870, 2900, 2950, 3000, 3100]);
    hintedRun($users[3]);
    $end = ScoreWindow::of($week)->end;
    $leaderboards = app(ScoreLeaderboards::class);

    // Reviewed while the board still runs; then the winner is taken off it, and the 4th (with a hint) moves up unreviewed.
    $this->travelTo($end->addHours(2));
    expect($leaderboards->confirmReview($week->refresh(), $this->admin))->toBe(3);
    $leaderboards->correct($week->refresh(), $this->admin, $users[0]->id, null, 'Not a human run');

    expect($leaderboards->reviewed($week->refresh()))->toBeFalse();

    tickAt($end->addHours(49));
    expect($week->refresh()->status)->toBe(TournamentStatus::Finished)
        ->and(SeasonAttestation::query()->count())->toBe(0);

    expect($leaderboards->confirmReview($week->refresh(), $this->admin))->toBe(1);
    tickAt($end->addHours(50));

    expect(SeasonAttestation::query()->sole()->winners())->toBe([$users[1]->pubkey]);
});

test('only an admin without a stake confirms the review, once the window has closed', function () {
    [$week, $users] = rankedWeek([2870, 2900, 2950, 3000, 3100]);
    $leaderboards = app(ScoreLeaderboards::class);

    expect(fn () => $leaderboards->confirmReview($week, $this->admin))->toThrow(TournamentRuleViolation::class)
        ->and(ScoreRun::query()->whereNotNull('verified_by_id')->count())->toBe(0);

    $this->travelTo(ScoreWindow::of($week)->end->addMinute());
    Admin::query()->create(['pubkey' => $users[2]->pubkey]);

    expect(fn () => $leaderboards->confirmReview($week->refresh(), User::factory()->create()))->toThrow(TournamentRuleViolation::class)
        ->and(fn () => $leaderboards->confirmReview($week->refresh(), $users[2]->refresh()))->toThrow(TournamentRuleViolation::class)
        ->and(ScoreRun::query()->whereNotNull('verified_by_id')->count())->toBe(0);

    Livewire::actingAs($this->admin)->test('pages::scores.tournament', ['tournament' => $week])
        ->assertSee('data-test="score-review"', false)
        ->call('confirmReview')
        ->assertSee(__('The top 3 are reviewed.'));

    expect($leaderboards->reviewed($week->refresh()))->toBeTrue()
        ->and(ScoreRun::query()->whereNotNull('verified_by_id')->pluck('verified_by_id')->unique()->all())->toBe([$this->admin->id]);
});

test('a week whose 31923 was never signed mines nothing', function () {
    [$week] = rankedWeek([2870, 2900, 2950, 3000, 3100], signed: false);
    $end = ScoreWindow::of($week)->end;

    tickAt($end->addHours(25));
    app(ScoreLeaderboards::class)->confirmReview($week->refresh(), $this->admin);
    tickAt($end->addHours(49));

    expect($week->refresh()->address())->toBeNull()
        ->and(app(SeasonChains::class)->attestScoreWindow($week))->toBeNull()
        ->and(SeasonAttestation::query()->count())->toBe(0);
});

test('a week an organizer opened mines nothing', function () {
    [$week] = rankedWeek([2870, 2900, 2950, 3000, 3100]);
    Tournament::query()->whereKey($week->id)->update(['created_by_id' => User::factory()->create()->id]);
    $end = ScoreWindow::of($week)->end;

    tickAt($end->addHours(25));
    app(ScoreLeaderboards::class)->confirmReview($week->refresh(), $this->admin);
    tickAt($end->addHours(49));

    expect($week->refresh()->address())->not->toBeNull()
        ->and(app(SeasonChains::class)->attestScoreWindow($week))->toBeNull()
        ->and(SeasonAttestation::query()->count())->toBe(0);
});

test('a week with fewer verified players than the minimum is attested without a block', function () {
    [$week] = rankedWeek([2870, 2900, 2950, 3000]);
    $end = ScoreWindow::of($week)->end;

    tickAt($end->addHours(25));
    app(ScoreLeaderboards::class)->confirmReview($week->refresh(), $this->admin);
    tickAt($end->addHours(49));

    $attestation = SeasonAttestation::query()->sole();

    expect([$attestation->height, $attestation->rule, $attestation->reason, $attestation->reward])->toBe([null, 1, 'too-few-entrants', 0]);
});

test('the chain draft proposes Blockfill: a weight for 40 blocks, its own share and one block a day, saved only by the board', function () {
    $board = User::factory()->withPubkey((new TestSigner)->pubkey)->create(['timezone' => 'UTC']);
    config(['esports.board' => [NostrKeys::hexToNpub($board->pubkey)]]);
    $defaults = ChainDraft::defaults();

    expect(ChainDraft::proposalNames())->toContain(Blockfill::SLUG)
        ->and($defaults['weights'])->not->toHaveKey('blockfill/40-blocks')
        ->and(ChainDraft::proposal(Blockfill::SLUG, $defaults))->toBe([
            'weights' => ['blockfill/40-blocks' => 1000],
            'shares' => ['chess' => 33, 'rocket-league' => 38, 'ea-sports-fc' => 24, 'blockfill' => 5],
            'daily' => ['blockfill' => 1],
        ]);

    Livewire::actingAs($board)->test('pages::admin.season')
        ->assertSee('data-test="blockfill-proposal"', false)
        ->call('fillProposal', Blockfill::SLUG)
        ->assertSet('draftWeights.blockfill/40-blocks', '1')
        ->tap(fn () => expect(ChainDraft::stored())->toBeNull());
});

test('the share card and the pride slide name the week and the winning time and whom the winner beat, never a ladder', function () {
    Storage::fake('local');
    [$week, $users] = rankedWeek([2870, 2900, 2950, 3000, 3100]);
    $end = ScoreWindow::of($week)->end;
    tickAt($end->addHours(25));
    app(ScoreLeaderboards::class)->confirmReview($week->refresh(), $this->admin);
    tickAt($end->addHours(49));
    $block = SeasonAttestation::query()->sole();
    $winner = $users[0]->refresh();

    $facts = ShareMoments::block($block, $winner);

    expect($facts['ladder'])->toBe('Blockfill')
        ->and($facts['window'])->toBe('Blockfill Week 41, 2026')
        ->and($facts['value'])->toBe('0:47.833')
        ->and($facts['opponents'])->toBe(['Stacker 2', 'Stacker 3'])
        ->and(ShareCard::block($block, $winner)->beat())->toBe('Mined block 1 in Blockfill Week 41, 2026 with 0:47.833, ahead of Stacker 2 and Stacker 3');

    App::setLocale('de');
    expect(ShareCard::block($block, $winner)->beat())->toBe('Block 1 geschürft in Blockfill Woche 41, 2026 mit 0:47.833, vor Stacker 2 und Stacker 3');
    App::setLocale('en');

    $pride = app(PrideSlides::class)->all();
    $svg = SceneRenderer::fromConfig()->svg(['pride' => $pride, 'stats' => [], 'backdrop' => null, 'viewers' => null], RotationPlanner::VIEWS['e5']);

    expect($pride['block'])->toMatchArray(['height' => 1, 'line' => 'won Blockfill Week 41, 2026 with 0:47.833', 'beat' => ['Stacker 2', 'Stacker 3']])
        ->and($svg)->toContain('won Blockfill Week 41, 2026 with 0:47.833', 'ahead of Stacker 2 and Stacker 3', 'Stacker 1')
        ->and($svg)->not->toContain('40-blocks', 'beat Stacker');

    $dir = getenv('BLOCKFILL_SHOTS');

    if (is_string($dir) && $dir !== '') {
        File::ensureDirectoryExists($dir);
        File::put("{$dir}/pride-e5.png", SceneRenderer::fromConfig()->png(['pride' => $pride, 'stats' => [], 'backdrop' => null, 'viewers' => null], RotationPlanner::VIEWS['e5']));

        foreach (['en', 'de'] as $locale) {
            App::setLocale($locale);

            foreach (array_keys(ShareCard::FORMATS) as $format) {
                File::put("{$dir}/block-{$format}-{$locale}.png", ShareCard::block($block, $winner)->png($format));
            }
        }
    }
});

test('a hinted run an admin already approved on the review list counts as reviewed: the week mines without a second click', function () {
    [$week, $users] = rankedWeek([2870, 2900, 2950, 3000, 3100]);
    hintedRun($users[1]);
    $run = StackerRun::query()->where('user_id', $users[1]->id)->sole();
    $run->forceFill(['flags' => [...(array) $run->flags, 'review' => ['decision' => 'approved', 'by' => $this->admin->id, 'at' => now()->toIso8601ZuluString()]]])->save();
    $end = ScoreWindow::of($week)->end;

    tickAt($end->addHours(49));

    expect(app(ScoreLeaderboards::class)->needsAdminReview($week->refresh()))->toBeFalse()
        ->and(SeasonAttestation::query()->sole()->winners())->toBe([$users[0]->pubkey]);
});
