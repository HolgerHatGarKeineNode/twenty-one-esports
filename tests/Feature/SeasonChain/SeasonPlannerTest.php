<?php

/*
 * The season planner and the release of a later season (P38; NIP "Season
 * transition", "Season Genesis" > "Release"): the board plans the season
 * after the newest one and releases it like Block 0; the release closes the
 * old ladders with `ends`, seeds the new rated ratings with the soft reset
 * `seed = start_B + round((final_A - start_A) * f)` and opens the new
 * ladders with `reset` and `seed`. Casual ratings and everything of the old
 * season stay as they are.
 */

use App\Models\Admin;
use App\Models\Lineup;
use App\Models\NostrEvent;
use App\Models\Rating;
use App\Models\Season;
use App\Models\SeasonAttestation;
use App\Models\SeasonPlan;
use App\Models\SeasonSettingChange;
use App\Models\User;
use App\Support\Nostr\NostrKeys;
use App\Support\PreSeason;
use App\Support\Rating\RatingSettings;
use App\Support\SeasonChain\ChainDraft;
use App\Support\SeasonChain\LadderEvents;
use App\Support\SeasonChain\LeagueKey;
use App\Support\SeasonChain\SeasonPlans;
use App\Support\SeasonChain\SeasonRelease;
use App\Support\SeasonChain\SeasonReleaseRefused;
use App\Support\SeasonChain\Seasons;
use App\Support\Series\Ladders;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\TestSigner;

const PLAN_MESSAGE = 'Season 2 of the chain: every fair win is a block';

beforeEach(function () {
    Queue::fake();
    $this->boardSigner = new TestSigner;
    $this->board = User::factory()->withPubkey($this->boardSigner->pubkey)->create(['name' => 'vorstand']);
    $this->trust = new TestSigner;
    config(['esports.board' => [NostrKeys::hexToNpub($this->board->pubkey)], 'esports.trust.nsec' => $this->trust->secret]);
});

/** The Pre-Season, ended an hour ago, with its ladders opened. */
function endedPreSeason(): Season
{
    $season = openSeason(['genesis_at' => now()->subDays(30), 'ends_at' => now()->subHour()]);
    app(LadderEvents::class)->publish($season, LeagueKey::required(), (string) LeagueKey::trust()?->pubkey());

    return $season;
}

/** A rated row of season `$season` for a player (chess) or a lineup (Rocket League 2v2). */
function seasonRow(Season $season, User|Lineup $entity, int $rating, int $results = 3, string $game = 'chess', string $mode = 'blitz', string $pool = Rating::RATED): Rating
{
    $player = $entity instanceof User;

    return Rating::query()->create([
        'pool' => $pool, 'season' => $pool === Rating::RATED ? $season->slug : '', 'game' => $player ? $game : 'rocket-league', 'mode' => $player ? $mode : '2v2',
        'subject' => ($player ? 'user:' : 'lineup:').$entity->id, 'user_id' => $player ? $entity->id : null, 'lineup_id' => $player ? null : $entity->id,
        'rating' => $rating, 'results' => $results, 'wins' => $results,
    ]);
}

/** One attestation on the season's ladder of game/mode; returns its event id. */
function attestOn(Season $season, string $game, string $mode, int $sourceId): string
{
    $eventId = hash('sha256', "attestation-{$season->slug}-{$game}-{$mode}-{$sourceId}");

    SeasonAttestation::query()->create([
        'season_id' => $season->id, 'source' => 'series', 'source_id' => $sourceId, 'label' => '#'.$sourceId, 'game' => $game, 'mode' => $mode,
        'ladder_address' => Ladders::KIND.':'.$season->league_pubkey.':'.$game.'/'.$mode.'/'.$season->slug, 'attested_at' => now()->subDays(2), 'event_id' => $eventId,
    ]);

    return $eventId;
}

/** Plan the next season, Block 0 in an hour, as the board. */
function planNext(User $board, string $factor = '0.5', int $weeks = 12, string $name = 'Winter Season'): SeasonPlan
{
    return app(SeasonPlans::class)->save($board, $name, CarbonImmutable::now()->addHour(), (string) $weeks, $factor) ?? throw new RuntimeException('Nothing saved.');
}

/** Prepare, sign as the board member, release: the browser's three steps. */
function releasePlanned(User $admin, TestSigner $signer): Season
{
    // The genesis message is part of the board's chain draft (P43).
    saveChainDraft([...(ChainDraft::stored() ?? []), 'message' => PLAN_MESSAGE]);
    $release = app(SeasonRelease::class);
    $endsAt = SeasonRelease::plannedEnd(CarbonImmutable::now());
    $template = $release->prepare($admin, '2 100 000', $endsAt, SeasonRelease::draftHash());

    return $release->release($admin, '2 100 000', $endsAt, SeasonRelease::draftHash(), $signer->signTemplates([$template]));
}

/** @return list<list<string>> the tags of the newest version of a ladder */
function newestLadderTags(string $d): array
{
    return NostrEvent::query()->where(['kind' => Ladders::KIND, 'd' => $d])->orderByDesc('signed_at')->orderByDesc('id')->firstOrFail()->payload()['tags'];
}

test('only a board member plans: an admin of the admins table and a player are refused, on the page and in the action', function () {
    endedPreSeason();
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    expect(SeasonPlans::refusal($admin))->toContain('board member')
        ->and(fn () => planNext($admin))->toThrow(SeasonReleaseRefused::class, 'board member');

    Livewire::actingAs($admin)->test('pages::admin.season')
        ->assertSee('data-test="season-planner"', false)
        ->assertSee('Only a board member on the public admin list can plan a season.')
        ->call('savePlan')
        ->assertSet('planError', 'Only a board member on the public admin list can plan a season.');

    $this->actingAs(User::factory()->create())->get(route('admin.season'))->assertForbidden();

    expect(SeasonPlan::query()->count())->toBe(0)
        ->and(NostrEvent::query()->where('kind', SeasonRelease::ANNOUNCEMENT)->count())->toBe(0);
});

test('before the Pre-Season there is nothing to plan', function () {
    expect(SeasonPlans::refusal($this->board))->toContain('Release the Pre-Season first')
        ->and(fn () => planNext($this->board))->toThrow(SeasonReleaseRefused::class, 'Release the Pre-Season first');

    $this->actingAs($this->board)->get(route('admin.season'))->assertOk()
        ->assertSee('The planner is for the seasons after the Pre-Season. Release the Pre-Season first.');
});

test('the board plans the next season through the page: defaults, a logged row per change, and the announcement', function () {
    $preSeason = endedPreSeason();
    $zone = PreSeason::timezoneFor($this->board);
    $start = CarbonImmutable::now()->addDays(2)->setTime(18, 0);

    $page = Livewire::actingAs($this->board)->test('pages::admin.season')
        // Defaults: the next slug's name, the Pre-Season's 24 weeks within 8 to 26, f = 0.5.
        ->assertSet('planName', 'Season 1')
        ->assertSet('planWeeks', '24')
        ->assertSet('planFactor', '0.5')
        ->set('planName', 'Winter Season')
        ->set('planStartsAt', $start->setTimezone($zone)->format('Y-m-d\TH:i'))
        ->set('planWeeks', '12')
        ->set('planFactor', '0,25')
        ->call('savePlan')
        ->assertSet('planError', '')
        ->assertSet('planFactor', '0.25')
        // The soft-reset preview starts at the planned f.
        ->assertSet('resetFactor', '0.25')
        ->assertSee('Winter Season');

    $plan = SeasonPlans::current();
    $announcement = NostrEvent::query()->where('kind', SeasonRelease::ANNOUNCEMENT)->sole()->payload();
    $tag = fn (string $name): ?string => collect($announcement['tags'])->firstWhere(0, $name)[1] ?? null;

    expect($plan->after_season_id)->toBe($preSeason->id)
        ->and($plan->slug)->toBe('season-1')
        ->and($plan->starts_at->getTimestamp())->toBe($start->getTimestamp())
        ->and($plan->weeks)->toBe(12)
        ->and($plan->reset_factor_milli)->toBe(250)
        ->and($plan->changed_by_id)->toBe($this->board->id)
        ->and($plan->changes)->toBe(['name' => [null, 'Winter Season'], 'starts_at' => [null, $start->getTimestamp()], 'weeks' => [null, 12], 'reset_factor_milli' => [null, 250]])
        ->and($announcement['pubkey'])->toBe(LeagueKey::required()->pubkey())
        ->and($tag('d'))->toBe('season/season-1')
        ->and($tag('title'))->toBe('TWENTY ONE Esports Winter Season: Block 0')
        ->and($tag('start'))->toBe((string) $start->getTimestamp())
        ->and($tag('end'))->toBe((string) ($start->getTimestamp() + 12 * 604800));

    // A change is a new row with only what changed and a newer announcement; the same values are nothing.
    $this->travel(1)->seconds();
    $page->set('planFactor', '0.5')->call('savePlan')->assertSet('planError', '');
    $page->call('savePlan')->assertSet('notice', 'Nothing changed.');

    expect(SeasonPlan::query()->count())->toBe(2)
        ->and(SeasonPlans::current()->changes)->toBe(['reset_factor_milli' => [250, 500]])
        ->and(NostrEvent::query()->where('kind', SeasonRelease::ANNOUNCEMENT)->count())->toBe(2)
        ->and(NostrEvent::query()->where('kind', SeasonRelease::ANNOUNCEMENT)->max('signed_at'))->toBeGreaterThan($announcement['created_at']);

    $page->assertSee('f: 0.25 → 0.5');
});

test('the plan is validated: name, length within the config limits, f from 0 to 1, Block 0 in the future and after the season ends', function (string $name, ?string $start, string $weeks, string $factor, string $error) {
    openSeason(['genesis_at' => now()->subDays(10), 'ends_at' => now()->addDays(3)]);
    $startsAt = $start === null ? null : CarbonImmutable::now()->modify($start);

    expect(fn () => app(SeasonPlans::class)->save($this->board, $name, $startsAt, $weeks, $factor))->toThrow(SeasonReleaseRefused::class, $error)
        ->and(SeasonPlan::query()->count())->toBe(0);
})->with([
    'no name' => ['  ', '+4 days', '12', '0.5', 'Give the season a name'],
    'name too long' => [str_repeat('x', 61), '+4 days', '12', '0.5', 'Give the season a name'],
    'shorter than min_weeks' => ['Winter', '+4 days', '7', '0.5', 'from 8 to 26'],
    'longer than max_weeks' => ['Winter', '+4 days', '27', '0.5', 'from 8 to 26'],
    'weeks not a number' => ['Winter', '+4 days', '1e1', '0.5', 'from 8 to 26'],
    'f above 1' => ['Winter', '+4 days', '12', '1.5', 'from 0 to 1'],
    'f negative' => ['Winter', '+4 days', '12', '-0.5', 'from 0 to 1'],
    'f with four decimals' => ['Winter', '+4 days', '12', '0.1255', 'from 0 to 1'],
    'no Block 0' => ['Winter', null, '12', '0.5', 'Choose the date'],
    'Block 0 before the season ends' => ['Winter', '+2 days', '12', '0.5', 'only be after pre-season ends'],
]);

test('Block 0 of the next season must lie in the future once the season has ended', function () {
    endedPreSeason();

    expect(fn () => app(SeasonPlans::class)->save($this->board, 'Winter', CarbonImmutable::now()->subMinutes(5), '12', '0.5'))
        ->toThrow(SeasonReleaseRefused::class, 'in the future');
});

test('a planned season is released only after the season before has ended, with a plan, and from its planned Block 0 on', function () {
    $preSeason = openSeason(['genesis_at' => now()->subDays(10), 'ends_at' => now()->addMinutes(30)]);

    expect(SeasonRelease::refusal($this->board))->toContain('Only one chain runs at a time');

    $this->travel(31)->minutes();
    expect(SeasonRelease::refusal($this->board))->toContain('Plan the next season first');

    planNext($this->board);
    expect(SeasonRelease::refusal($this->board))->toContain('Winter Season is planned for Block 0')
        ->and(fn () => releasePlanned($this->board, $this->boardSigner))->toThrow(SeasonReleaseRefused::class, 'planned for Block 0');

    $this->travel(61)->minutes();

    expect(SeasonRelease::refusal($this->board))->toBeNull()
        ->and(SeasonRelease::refusal(User::factory()->create()))->toContain('board member')
        ->and(Season::query()->count())->toBe(1)
        ->and($preSeason->refresh()->ends_at->isPast())->toBeTrue();
});

test('the release of a planned season seeds every carried-over rating exactly per the NIP formula and writes reset and seed into the new ladders', function () {
    $preSeason = endedPreSeason();
    $league = LeagueKey::required()->pubkey();

    // NIP example, revision 4 (season 3 to 4, f = 0.5): halves round away from zero in both directions.
    $players = collect(['alice' => 1028, 'frank' => 1021, 'erin' => 1001, 'bob' => 979, 'carol' => 971])
        ->map(fn (int $rating, string $name): array => [User::factory()->create(['name' => $name]), $rating]);
    foreach ($players as [$user, $rating]) {
        seasonRow($preSeason, $user, $rating);
    }
    // A player whose only result in chess blitz was voided: no rated result, no seed.
    $idle = User::factory()->create();
    seasonRow($preSeason, $idle, 1000, results: 0);
    $strikers = Lineup::factory()->mode('2v2')->create();
    $rockets = Lineup::factory()->mode('2v2')->create();
    seasonRow($preSeason, $strikers, 1020);
    seasonRow($preSeason, $rockets, 980);
    attestOn($preSeason, 'chess', 'blitz', 1);
    $lastBlitz = attestOn($preSeason, 'chess', 'blitz', 2);
    $lastDoubles = attestOn($preSeason, 'rocket-league', '2v2', 3);

    planNext($this->board);
    $this->travel(61)->minutes();
    $before = NostrEvent::query()->where('kind', Ladders::KIND)->where('d', 'like', '%/pre-season')->orderBy('id')->get()->map->payload()->all();

    $season = releasePlanned($this->board, $this->boardSigner);

    $seeds = fn (string $game, string $mode): array => Rating::query()->where(['pool' => Rating::RATED, 'season' => 'season-1', 'game' => $game, 'mode' => $mode])
        ->orderByDesc('rating')->orderBy('id')->get()->map(fn (Rating $row): array => [$row->subject, $row->rating, $row->results])->all();
    $subject = fn (string $name): string => 'user:'.$players[$name][0]->id;

    expect($season->slug)->toBe('season-1')
        ->and($season->previous_season_id)->toBe($preSeason->id)
        ->and($season->reset_factor_milli)->toBe(500)
        ->and($season->ends_at->getTimestamp() - $season->genesis_at->getTimestamp())->toBe(12 * 604800)
        ->and(Seasons::live()?->is($season))->toBeTrue()
        ->and(Ladders::address('chess', 'blitz'))->toBe(Ladders::KIND.':'.$league.':chess/blitz/season-1')
        ->and($seeds('chess', 'blitz'))->toBe([
            [$subject('alice'), 1014, 0],
            [$subject('frank'), 1011, 0],
            [$subject('erin'), 1001, 0],
            [$subject('bob'), 989, 0],
            [$subject('carol'), 985, 0],
        ])
        ->and($seeds('rocket-league', '2v2'))->toBe([['lineup:'.$strikers->id, 1010, 0], ['lineup:'.$rockets->id, 990, 0]])
        ->and(Rating::query()->where(['season' => 'season-1'])->count())->toBe(7);

    $blitz = newestLadderTags('chess/blitz/season-1');
    $doubles = newestLadderTags('rocket-league/2v2/season-1');
    $pubkey = fn (string $name): string => $players[$name][0]->pubkey;

    expect(collect($blitz)->whereIn(0, ['reset', 'seed'])->values()->all())->toBe([
        ['reset', Ladders::KIND.':'.$league.':chess/blitz/pre-season', $lastBlitz, '0.5'],
        ['seed', $pubkey('alice'), '1014'],
        ['seed', $pubkey('frank'), '1011'],
        ['seed', $pubkey('erin'), '1001'],
        ['seed', $pubkey('bob'), '989'],
        ['seed', $pubkey('carol'), '985'],
    ])
        // Every seeded player is a plain p, no standing yet.
        ->and(collect($blitz)->where(0, 'p')->pluck(1)->sort()->values()->all())->toBe($players->map(fn (array $pair): string => $pair[0]->pubkey)->sort()->values()->all())
        ->and(collect($blitz)->where(0, 'standing')->all())->toBe([])
        ->and($blitz)->toContain(['season', 'season-1'], ['starts', (string) $season->genesis_at->getTimestamp()], ['e', $season->genesisId(), ''])
        ->and(collect($doubles)->whereIn(0, ['reset', 'seed'])->values()->all())->toBe([
            ['reset', Ladders::KIND.':'.$league.':rocket-league/2v2/pre-season', $lastDoubles, '0.5'],
            ['seed', $strikers->address(), '1010'],
            ['seed', $rockets->address(), '990'],
        ])
        ->and($doubles)->toContain(['a', $strikers->address(), ''], ['a', $rockets->address(), ''])
        // A ladder without a rated result in the Pre-Season starts without reset.
        ->and(collect(newestLadderTags('rocket-league/3v3/season-1'))->whereIn(0, ['reset', 'seed'])->all())->toBe([]);

    // The Pre-Season ladders got a last version with `ends`; every earlier version is untouched.
    $closing = newestLadderTags('chess/blitz/pre-season');

    expect($closing)->toContain(['ends', (string) $preSeason->ends_at->getTimestamp()], ['alt', 'Esports ladder: chess blitz, pre-season, closed'])
        ->and(NostrEvent::query()->where('kind', Ladders::KIND)->where('d', 'like', '%/pre-season')->orderBy('id')->limit(count($before))->get()->map->payload()->all())->toBe($before);

    // The genesis names the planned season; the announcement's last version has the real times.
    $genesis = NostrEvent::query()->whereKey($season->genesis_event_id)->sole()->payload();
    $announcement = NostrEvent::query()->where(['kind' => SeasonRelease::ANNOUNCEMENT, 'd' => 'season/season-1'])->orderByDesc('signed_at')->orderByDesc('id')->firstOrFail()->payload();

    expect($genesis['tags'])->toContain(['season', 'season-1'], ['a', SeasonRelease::ANNOUNCEMENT.':'.$league.':season/season-1', ''], ['alt', 'Season genesis: TWENTY ONE Esports Winter Season, Block 0'])
        ->and($announcement['tags'])->toContain(['start', (string) $season->genesis_at->getTimestamp()], ['end', (string) $season->ends_at->getTimestamp()]);
});

test('the soft reset uses both seasons\' start ratings and the planned f in integers: 50 × 0.29 = 14.5 rounds to 15', function () {
    $preSeason = endedPreSeason();
    [$up, $down] = [User::factory()->create(), User::factory()->create()];
    seasonRow($preSeason, $up, 1050);
    seasonRow($preSeason, $down, 950);
    attestOn($preSeason, 'chess', 'blitz', 1);

    // The rating draft is open again between seasons: season 1 starts at 1200.
    SeasonSettingChange::query()->create(['changed_by_pubkey' => $this->board->pubkey, 'changed_by_id' => $this->board->id,
        'values' => array_replace_recursive(RatingSettings::defaults(), ['rating' => ['start' => 1200]]), 'changes' => ['rating.start' => [1000, 1200]]]);
    planNext($this->board, '0.29');
    $this->travel(61)->minutes();

    $season = releasePlanned($this->board, $this->boardSigner);

    expect(RatingSettings::forSeason($season)['rating']['start'])->toBe(1200)
        ->and(Rating::query()->where(['season' => 'season-1', 'user_id' => $up->id])->value('rating'))->toBe(1215)
        ->and(Rating::query()->where(['season' => 'season-1', 'user_id' => $down->id])->value('rating'))->toBe(1185)
        ->and(collect(newestLadderTags('chess/blitz/season-1'))->whereIn(0, ['reset', 'seed'])->pluck(3)->filter()->all())->toBe(['0.29'])
        ->and(collect(newestLadderTags('chess/blitz/season-1'))->where(0, 'seed')->pluck(2)->all())->toBe(['1215', '1185']);
});

test('the release leaves casual ratings and every row of the season before as they were, and a second release is refused', function () {
    $preSeason = endedPreSeason();
    $alice = User::factory()->create();
    seasonRow($preSeason, $alice, 1040);
    seasonRow($preSeason, $alice, 1111, 9, pool: Rating::CASUAL);
    attestOn($preSeason, 'chess', 'blitz', 1);
    $snapshot = fn (): array => [
        Rating::query()->where('season', '!=', 'season-1')->orderBy('id')->get(['id', 'pool', 'season', 'rating', 'results', 'wins', 'updated_at'])->toArray(),
        SeasonAttestation::query()->orderBy('id')->get()->toArray(),
        Arr::only($preSeason->refresh()->toArray(), ['slug', 'genesis_at', 'ends_at', 'rating_parameters', 'digest', 'genesis_event_id']),
    ];

    planNext($this->board);
    $this->travel(61)->minutes();
    $before = $snapshot();

    releasePlanned($this->board, $this->boardSigner);

    expect($snapshot())->toBe($before)
        ->and(Rating::query()->where('pool', Rating::CASUAL)->count())->toBe(1)
        ->and(Rating::query()->where(['season' => 'season-1', 'user_id' => $alice->id])->value('rating'))->toBe(1020);

    // The same plan again: the planned season is live, and the release is refused.
    $events = NostrEvent::query()->count();

    expect(fn () => releasePlanned($this->board, $this->boardSigner))->toThrow(SeasonReleaseRefused::class, 'Only one chain')
        ->and(Season::query()->count())->toBe(2)
        ->and(NostrEvent::query()->count())->toBe($events)
        ->and(Rating::query()->where('season', 'season-1')->count())->toBe(1);
});

test('a rated ladder without an attestation to pin is refused as a league error, and nothing is written', function () {
    $preSeason = endedPreSeason();
    seasonRow($preSeason, User::factory()->create(), 1040);
    planNext($this->board);
    $this->travel(61)->minutes();
    $events = NostrEvent::query()->count();

    expect(fn () => releasePlanned($this->board, $this->boardSigner))->toThrow(SeasonReleaseRefused::class, 'no attestation')
        ->and(Season::query()->count())->toBe(1)
        ->and(NostrEvent::query()->count())->toBe($events)
        ->and(Rating::query()->where('season', 'season-1')->count())->toBe(0);
});

test('the rating draft is locked while a season is live and open again between seasons', function () {
    $season = openSeason(['genesis_at' => now()->subDays(10), 'ends_at' => now()->addMinutes(30)]);

    expect(RatingSettings::locked())->toBeTrue()
        ->and(fn () => RatingSettings::saveDraft($this->board, RatingSettings::defaults()))->toThrow(SeasonReleaseRefused::class, 'frozen');

    $this->travel(31)->minutes();

    expect(RatingSettings::locked())->toBeFalse()
        ->and(RatingSettings::saveDraft($this->board, array_replace_recursive(RatingSettings::defaults(), ['rating' => ['k' => 24]]))?->changes)->toBe(['rating.k' => [32, 24]])
        // The ended season keeps what it froze; only the next release reads the draft.
        ->and(RatingSettings::forSeason($season))->toBe(RatingSettings::defaults());
});
