<?php

use App\Enums\TournamentFormat;
use App\Enums\TournamentStatus;
use App\Models\Rating;
use App\Models\Tournament;
use App\Models\User;
use App\Support\TwentyOne\Stream\TournamentSlides;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestSigner;

/*
 * The upcoming tournaments the stream's tournament slides show
 * (TournamentSlides): the contract fields as the public tournament page
 * reads them, the countdown from the frame's clock, the cache.
 */

beforeEach(function () {
    config(['esports.league.nsec' => (new TestSigner)->secret]);
});

/** A keyed player with a casual Elo, signed up solo. */
function slidePlayer(Tournament $tournament, string $name, int $elo): User
{
    [$user, $signer] = keyedPlayer();
    $user->forceFill(['name' => $name])->save();
    Rating::query()->create(['pool' => Rating::CASUAL, 'season' => '', 'game' => $tournament->game, 'mode' => $tournament->mode, 'subject' => 'user:'.$user->id, 'user_id' => $user->id, 'rating' => $elo, 'results' => 10]);
    soloSignup($tournament, $user, $signer);

    return $user;
}

/** The contract's keys (resources/views/stream/rotation/ta1-hero.blade.php). */
const SLIDE_KEYS = ['id', 'name', 'description', 'status', 'game', 'mode', 'format', 'teamSize', 'rated', 'where', 'startsAt', 'signupClosesAt',
    'countdown', 'countdownLabel', 'taken', 'places', 'spotsLeft', 'roster', 'openSpots', 'preview', 'cover', 'url'];

test('a two stage tournament: every contract field, the seeds by Elo, the projected groups, the ticking countdown', function () {
    $tournament = openTournament([
        'name' => "Halving\u{202E} Cup\nFinals",
        'description' => "Bring your own board.\n\nPizza at the break.",
        'format' => TournamentFormat::TwoStage,
        'capacity' => 8,
        'starts_at' => now()->addWeek()->setTime(18, 0),
    ]);
    slidePlayer($tournament, 'satsjaeger', 1100);
    slidePlayer($tournament, "hodl\u{200B}queen", 1400);
    $tournament->refresh();
    $closesMs = (int) $tournament->signup_closes_at->getTimestampMs();

    $data = app(TournamentSlides::class)->data($tournament, $closesMs - (6 * 86400 + 6 * 3600 + 5 * 60 + 1) * 1000);

    expect(array_keys($data))->toEqualCanonicalizing(SLIDE_KEYS)
        ->and($data)->toMatchArray([
            'id' => $tournament->id,
            'name' => 'Halving Cup Finals',
            'description' => 'Bring your own board. Pizza at the break.',
            'status' => 'Sign-up open',
            'game' => 'Chess',
            'mode' => 'Blitz 5+3',
            'format' => 'Two Stage',
            'teamSize' => 1,
            'rated' => false,
            'where' => 'Online',
            'startsAt' => $tournament->starts_at->copy()->timezone('Europe/Berlin')->format('D j M, H:i'),
            'signupClosesAt' => $tournament->signup_closes_at->copy()->timezone('Europe/Berlin')->format('D j M, H:i'),
            'countdown' => '6d 06:05:01',
            'countdownLabel' => 'Sign-up closes in',
            'taken' => 2,
            'places' => 8,
            'spotsLeft' => 6,
            'openSpots' => 6,
            'roster' => [['seed' => 1, 'name' => 'hodl queen', 'rating' => 1400], ['seed' => 2, 'name' => 'satsjaeger', 'rating' => 1100]],
            'url' => 'esports.einundzwanzig.space/tournaments/'.$tournament->id,
        ])
        // Berlin is UTC+1 or +2: 18:00 UTC is 19:00 or 20:00 there.
        ->and($data['startsAt'])->toMatch('/^[A-Z][a-z]{2} \d{1,2} [A-Z][a-z]{2}, (19|20):00$/')
        ->and($data['cover'])->toStartWith('data:image/jpeg;base64,/9j/')
        ->and($data['preview']['kind'])->toBe('groups')
        ->and($data['preview']['stageNote'])->toBe('Groups if sign-up closed now')
        ->and(array_keys($data['preview']['groups']))->toBe(['A', 'B'])
        ->and(array_merge(...array_values($data['preview']['groups'])))->toContain(['seed' => 1, 'name' => 'hodl queen'], ['seed' => 2, 'name' => 'satsjaeger'], ['seed' => 8, 'name' => null])
        ->and($data['preview'])->not->toHaveKey('matches')
        // Below a day the days go.
        ->and(app(TournamentSlides::class)->data($tournament, $closesMs - 3_661_000)['countdown'])->toBe('01:01:01');
});

test('a single elimination tournament previews round 1 with open spots and byes', function () {
    $tournament = openTournament(['format' => TournamentFormat::SingleElimination, 'capacity' => 6]);
    slidePlayer($tournament, 'topseed', 1500);

    $preview = app(TournamentSlides::class)->data($tournament->refresh(), now()->getTimestampMs())['preview'];

    expect($preview['kind'])->toBe('bracket')
        ->and($preview['stageNote'])->toBe('Round 1 if sign-up closed now')
        ->and($preview)->not->toHaveKey('groups')
        ->and($preview['byes'])->toBe([1, 2])
        ->and($preview['matches'])->toHaveCount(2)
        ->and(array_merge(...array_column($preview['matches'], 'sides')))->toEqualCanonicalizing([
            ['seed' => 3, 'name' => null], ['seed' => 4, 'name' => null], ['seed' => 5, 'name' => null], ['seed' => 6, 'name' => null],
        ]);
});

test('a team mode counts player places, seeds the lineup under its clan and leaves solo players unseeded', function () {
    $tournament = openTournament([], rocketLeague: true);
    [$lineup, $captain, $signer] = keyedLineup();
    lineupSignup($tournament, $lineup, $captain, $signer);
    [$solo, $soloSigner] = keyedPlayer();
    soloSignup($tournament, $solo, $soloSigner);

    $data = app(TournamentSlides::class)->data($tournament->refresh(), now()->getTimestampMs());

    expect($data)->toMatchArray([
        'game' => 'Rocket League',
        'mode' => '3v3',
        'format' => 'Single Elimination',
        'teamSize' => 3,
        'taken' => 4,
        'places' => 24,
        'spotsLeft' => 20,
        'roster' => [['seed' => 1, 'name' => $lineup->clan->name, 'rating' => (int) config('season.rating.start', 1000)]],
    ])->and($data['preview']['matches'][0]['sides'][0])->toBe(['seed' => 1, 'name' => $lineup->clan->name]);
});

test('a full tournament stays upcoming with no spot left', function () {
    $tournament = openTournament(['capacity' => 2]);
    slidePlayer($tournament, 'a', 1100);
    slidePlayer($tournament, 'b', 1000);

    $slides = app(TournamentSlides::class);

    expect($slides->upcoming()->modelKeys())->toBe([$tournament->id])
        ->and($slides->data($tournament->refresh(), now()->getTimestampMs()))->toMatchArray(['taken' => 2, 'places' => 2, 'spotsLeft' => 0, 'openSpots' => 0]);
});

test('upcoming means open for sign-up, soonest close first; a draft, a closed sign-up and a missing close are left out', function () {
    $later = openTournament();
    $later->forceFill(['signup_closes_at' => now()->addDays(3)])->save();
    $sooner = openTournament();
    $sooner->forceFill(['signup_closes_at' => now()->addHours(2)])->save();
    Tournament::factory()->create();
    Tournament::factory()->signup()->create();
    Tournament::factory()->signup()->create(['signup_closes_at' => now()->subMinute()]);
    Tournament::factory()->create(['status' => TournamentStatus::Running, 'signup_closes_at' => now()->addDay()]);

    expect(app(TournamentSlides::class)->upcoming()->modelKeys())->toBe([$sooner->id, $later->id]);
});

test('all() reads the database once per cache period, ticks from the frame clock and drops a tournament the second its sign-up closes', function () {
    $tournament = openTournament();
    $closesMs = (int) $tournament->refresh()->signup_closes_at->getTimestampMs();
    $slides = app(TournamentSlides::class);

    $first = $slides->all($closesMs - 2000);
    DB::enableQueryLog();
    $second = $slides->all($closesMs - 1000);
    $queries = count(DB::getQueryLog());

    expect(array_column($first, 'id'))->toBe([$tournament->id])
        ->and($first[0]['countdown'])->toBe('00:00:02')
        ->and($second[0]['countdown'])->toBe('00:00:01')
        ->and($queries)->toBe(0)
        ->and($slides->all($closesMs))->toBe([]);
});
