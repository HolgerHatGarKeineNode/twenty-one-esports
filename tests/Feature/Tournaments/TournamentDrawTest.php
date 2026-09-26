<?php

use App\Enums\TournamentStatus;
use App\Models\NostrEvent;
use App\Models\SeriesMatch;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\Tournaments\DrawOrder;
use App\Support\Tournaments\MemeNames;
use App\Support\Tournaments\TournamentDraws;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TestSigner;

/*
|--------------------------------------------------------------------------
| The draw (P8b, NIP "Tournament Draw", sha256-v1)
|--------------------------------------------------------------------------
|
| At the deadline the solo pool is frozen and the draw commits to the next
| Bitcoin block (2155); its hash sorts the pool into mix teams with meme
| names, the bracket is built with it and the first matches start.
|
*/

beforeEach(function () {
    Queue::fake();
    $this->league = new TestSigner;
    config(['esports.league.nsec' => $this->league->secret]);
});

test('the draw order is sha256 of block hash and pubkey, whatever order the pool comes in', function () {
    $hash = str_repeat('0f', 32);
    $pubkeys = array_map(fn (int $i) => hash('sha256', "player {$i}"), range(1, 7));
    $expected = $pubkeys;
    usort($expected, fn ($a, $b) => strcmp(hash('sha256', "{$hash}:{$a}"), hash('sha256', "{$hash}:{$b}")));

    $drawn = DrawOrder::teams($hash, array_reverse($pubkeys), 3);

    expect(DrawOrder::order($hash, $pubkeys))->toBe($expected)
        ->and($drawn)->toBe(['teams' => [array_slice($expected, 0, 3), array_slice($expected, 3, 3)], 'reserves' => [$expected[6]]])
        ->and(DrawOrder::order(str_repeat('a0', 32), $pubkeys))->not->toBe($expected);
});

test('sign-up closes into a committed draw, and the mined block draws mix teams with meme names and starts the bracket', function () {
    $hash = hash('sha256', 'block 900001');
    $tip = 900000;
    Http::fake(function ($request) use (&$tip, $hash) {
        return match (true) {
            str_ends_with($request->url(), '/blocks/tip/height') => Http::response((string) $tip),
            str_ends_with($request->url(), '/block-height/900001') => Http::response($hash),
            str_ends_with($request->url(), '/block/'.$hash) => Http::response(['timestamp' => now()->addMinutes(10)->getTimestamp()]),
            default => Http::response('', 404),
        };
    });

    $tournament = openTournament(['capacity' => 5], rocketLeague: true);
    [$lineupA, $captainA, $signerA] = keyedLineup();
    [$lineupB, $captainB, $signerB] = keyedLineup();
    lineupSignup($tournament, $lineupA, $captainA, $signerA);
    lineupSignup($tournament, $lineupB, $captainB, $signerB);
    $solos = collect(range(1, 7))->map(function () use ($tournament) {
        [$player, $signer] = keyedPlayer();
        soloSignup($tournament, $player, $signer);

        return $player;
    });

    $this->travel(25)->hours();
    $draws = app(TournamentDraws::class);

    expect($draws->close($tournament->refresh()))->toBeTrue();

    $tournament->refresh();
    $draw = NostrEvent::query()->findOrFail($tournament->draw_event_id)->payload();
    $names = MemeNames::for($tournament->slug, 2);

    expect($tournament->status)->toBe(TournamentStatus::Drawing)
        ->and($tournament->draw_height)->toBe(900001)
        ->and($draw['kind'])->toBe(2155)
        ->and($draw['pubkey'])->toBe($this->league->pubkey)
        ->and(collect($draw['tags'])->where(0, 'p')->pluck(1)->sort()->values()->all())->toBe($solos->pluck('pubkey')->sort()->values()->all())
        ->and($draw['tags'])->toContain(['draw', '900001', 'sha256-v1'], ['teams', '3'], ['teamname', $names[0]], ['teamname', $names[1]], ['a', $tournament->address(), '']);

    $tip = 900006;

    expect($draws->resolve($tournament))->toBeTrue();

    $tournament->refresh();
    $mix = TournamentParticipant::query()->whereNotNull('draw_position')->orderBy('draw_position')->get();
    $expected = DrawOrder::teams($hash, $solos->pluck('pubkey')->all(), 3);
    $byKey = User::query()->pluck('id', 'pubkey');

    expect($tournament->status)->toBe(TournamentStatus::Running)
        ->and($tournament->seed)->toBe($hash)
        ->and($mix->pluck('name')->all())->toBe($names)
        ->and($mix->map(fn ($team) => $team->members)->all())->toBe(array_map(fn ($team) => array_map(fn ($key) => $byKey[$key], $team), $expected['teams']))
        // Reproducible: the same hash gives the same teams again.
        ->and($draws->mixTeams($tournament, $hash)['teams'][1]['members'])->toBe($mix[1]->members)
        ->and($draws->mixTeams($tournament, $hash)['reserves'])->toBe([$byKey[$expected['reserves'][0]]])
        // The lineups are seeded first, the mix teams after them in draw order.
        ->and($mix->pluck('seed')->all())->toBe([3, 4])
        // Round 1 is played as two normal series; the mix team side has no lineup.
        ->and(SeriesMatch::query()->whereNotNull('tournament_match_id')->count())->toBe(2)
        ->and(SeriesMatch::query()->whereNotNull('sides')->count())->toBe(2);

    $this->get(route('tournaments.draw', $tournament))->assertOk()->assertSee($names[0])->assertSee('Verified draw');
});

test('without a readable block the draw waits, and without two entries the tournament is called off', function () {
    $up = false;
    Http::fake(function () use (&$up) {
        return $up ? Http::response('900000') : Http::response('', 503);
    });
    $tournament = openTournament(['capacity' => 4]);
    [$player, $signer] = keyedPlayer();
    soloSignup($tournament, $player, $signer);
    $this->travel(25)->hours();

    expect(app(TournamentDraws::class)->close($tournament->refresh()))->toBeFalse()
        ->and($tournament->refresh()->status)->toBe(TournamentStatus::Signup);

    $up = true;
    $this->artisan('tournaments:advance')->assertSuccessful();

    expect($tournament->refresh()->status)->toBe(TournamentStatus::Cancelled);
});
