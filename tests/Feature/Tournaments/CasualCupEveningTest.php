<?php

use App\Enums\ChessGameStatus;
use App\Enums\TournamentFormat;
use App\Enums\TournamentStatus;
use App\Models\ChessGame;
use App\Models\NostrEvent;
use App\Models\SeriesMatch;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentParticipant;
use App\Models\TournamentRound;
use App\Models\User;
use App\Support\Chess\ChessGameService;
use App\Support\Tournaments\CasualCups;
use App\Support\Tournaments\Engine\Standings;
use App\Support\Tournaments\TournamentChampion;
use App\Support\Tournaments\TournamentRunner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TestSigner;

/*
|--------------------------------------------------------------------------
| Small casual cups: one live evening (P25 S2)
|--------------------------------------------------------------------------
|
| After its one extension a cup with 2 to 5 players is not called off but
| switched: 2 play one match (chess: three games, a round robin of two),
| 3 to 5 a round robin. It runs as one evening from 20:00 Berlin the day
| after the close, rounds back to back after a short break, every game
| started by the league; the switch is the one new 31923 version.
|
*/

beforeEach(function () {
    Queue::fake();
    config(['esports.league.nsec' => (new TestSigner)->secret, 'esports.casual_cups.enabled' => ['chess'], 'esports.bitcoin.confirmations' => 1]);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00:00', 'UTC'));
});

/** The tournament's 31923 versions, oldest first. */
function eveningVersions(Tournament $cup): array
{
    return NostrEvent::query()->where('kind', Tournament::CALENDAR_EVENT)->where('d', $cup->slug)->orderBy('id')->get()->all();
}

/** @return array<string, string> the first value of each tag */
function eveningTags(NostrEvent $event): array
{
    $tags = [];

    foreach ($event->payload()['tags'] as $tag) {
        $tags[$tag[0]] ??= $tag[1];
    }

    return $tags;
}

/** A Bitcoin API whose tip the test moves; block 900001 is mined ten minutes after now. */
function eveningBlocks(int &$tip): void
{
    $hash = hash('sha256', 'block 900001');
    Http::fake(function ($request) use (&$tip, $hash) {
        return match (true) {
            str_ends_with($request->url(), '/blocks/tip/height') => Http::response((string) $tip),
            str_ends_with($request->url(), '/block-height/900001') => Http::response($hash),
            str_ends_with($request->url(), '/block/'.$hash) => Http::response(['timestamp' => now()->addMinutes(10)->getTimestamp()]),
            default => Http::response('', 404),
        };
    });
}

/** The result of the match between two participants, winner by participant (null = draw). */
function eveningResult(Tournament $cup, TournamentParticipant $a, TournamentParticipant $b, ?TournamentParticipant $winner): void
{
    $match = TournamentMatch::query()->where('tournament_id', $cup->id)->with('slots')->get()
        ->first(fn (TournamentMatch $match) => $match->slots->pluck('tournament_participant_id')->sort()->values()->all() === collect([$a->id, $b->id])->sort()->values()->all());
    $slot = $winner === null ? null : ($match->slots[0]->tournament_participant_id === $winner->id ? 0 : 1);

    app(TournamentRunner::class)->store($match, ['winner' => $slot, 'games_won' => $slot === null ? [0.5, 0.5] : ($slot === 0 ? [1.0, 0.0] : [0.0, 1.0]), 'points' => [], 'forfeit' => false, 'label' => '-', 'by' => 'players']);
}

/* ---------- The format -------------------------------------------------------------------------------------- */

test('the format follows the players at the last close', function (int $players, ?TournamentFormat $format, ?int $iterations) {
    $cup = Tournament::factory()->make(['cup_series' => 'chess', 'format' => TournamentFormat::DoubleElimination]);
    $chosen = CasualCups::formatFor($cup, $players);

    expect($chosen['format'] ?? null)->toBe($format)
        ->and(($chosen['format'] ?? null) === TournamentFormat::RoundRobin ? $chosen['options']['iterations'] : null)->toBe($iterations);
})->with([
    '0: called off' => [0, null, null],
    '1: called off' => [1, null, null],
    '2: three games' => [2, TournamentFormat::RoundRobin, 3],
    '3: round robin' => [3, TournamentFormat::RoundRobin, 1],
    '5: round robin' => [5, TournamentFormat::RoundRobin, 1],
    '6: round robin, not a bracket of byes' => [6, TournamentFormat::RoundRobin, 1],
    '8: round robin' => [8, TournamentFormat::RoundRobin, 1],
    '9: double elimination' => [9, TournamentFormat::DoubleElimination, null],
]);

test('six players at the close play a round robin that starts at the close as planned, not a double elimination full of byes', function () {
    $tip = 900000;
    eveningBlocks($tip);
    cupTick();
    $cup = openCup();
    cupSignups($cup, 6);
    $start = $cup->starts_at->getTimestamp();

    $this->travelTo($cup->signup_closes_at);
    cupTick();
    $cup->refresh();

    expect($cup->format)->toBe(TournamentFormat::RoundRobin)
        ->and($cup->status)->toBe(TournamentStatus::Drawing)
        ->and($cup->starts_at->getTimestamp())->toBe($start);

    $tip = 900001;
    cupTick();

    expect($cup->refresh()->status)->toBe(TournamentStatus::Running)
        ->and(TournamentMatch::query()->where('tournament_id', $cup->id)->count())->toBe(15);
});

test('two players of a series game play one best of three', function (string $game, int $bestOf) {
    $cup = Tournament::factory()->make(['cup_series' => $game, 'game' => $game, 'mode' => '1v1', 'format' => TournamentFormat::DoubleElimination]);
    $chosen = CasualCups::formatFor($cup, 2);

    expect($chosen['format'])->toBe(TournamentFormat::SingleElimination)
        ->and($chosen['options']['finalBestOf'])->toBe($bestOf);
})->with([['rocket-league', 3], ['ea-sports-fc-26', 3]]);

test('every small format stays within the play budget per player, computed from the config', function (int $players, int $rounds, int $games, int $play) {
    $cup = Tournament::factory()->make(['cup_series' => 'chess', 'format' => TournamentFormat::DoubleElimination]);
    $chosen = CasualCups::formatFor($cup, $players);
    $cup->forceFill(['format' => $chosen['format'], 'options' => $chosen['options']]);
    $plan = CasualCups::eveningPlan($cup, $players);

    // Chess blitz is planned at 14 min a game (GameProfile), 3 min break between rounds.
    expect([$plan['rounds'], $plan['games_per_player'], $plan['play_minutes']])->toBe([$rounds, $games, $play])
        ->and($plan['span_minutes'])->toBe($rounds * 14 + ($rounds - 1) * 3)
        ->and($plan['play_minutes'])->toBeLessThanOrEqual((int) config('esports.casual_cups.evening.max_play_minutes'));
})->with([
    '2 players' => [2, 3, 3, 42],
    '3 players' => [3, 3, 2, 28],
    '4 players' => [4, 3, 3, 42],
    '5 players' => [5, 5, 4, 56],
]);

/* ---------- The switch and the evening ---------------------------------------------------------------------- */

test('at the close three players switch to a round robin evening at 20:00 Berlin the next day, published once', function () {
    $tip = 900000;
    eveningBlocks($tip);
    cupTick();
    $cup = openCup();
    cupSignups($cup, 3);
    $beforeSwitch = count(eveningVersions($cup));

    // P27: 2 to 5 at the close (the EU slot, Saturday 20:00) play their evening the next day; the extension is for fewer than 2.
    $this->travelTo($cup->signup_closes_at);
    $done = cupTick();
    $cup->refresh();
    $versions = eveningVersions($cup);
    $tags = eveningTags(end($versions));
    $plan = CasualCups::planOf($cup);
    $player = $cup->signups()->firstOrFail()->members[0];

    expect($done['cups']['evenings'])->toBe(1)
        ->and($cup->status)->toBe(TournamentStatus::Drawing)
        ->and($cup->format)->toBe(TournamentFormat::RoundRobin)
        ->and($cup->starts_at->setTimezone('Europe/Berlin')->format('Y-m-d H:i'))->toBe('2026-10-11 20:00')
        ->and(count($versions))->toBe($beforeSwitch + 1)
        ->and((int) $tags['start'])->toBe($cup->starts_at->getTimestamp())
        ->and((int) $tags['end'])->toBe($cup->starts_at->getTimestamp() + $plan['span_minutes'] * 60)
        ->and(User::query()->findOrFail($player)->notifications()->get()->pluck('data.title')->last())->toBe('Chess Casual Cup EU #1: live evening Sun 11 Oct, 20:00 CEST');

    // The block comes: the round robin is drawn, and nothing starts before the evening; no further version.
    $tip = 900001;
    cupTick();

    expect($cup->refresh()->status)->toBe(TournamentStatus::Running)
        ->and(TournamentMatch::query()->where('tournament_id', $cup->id)->count())->toBe(3)
        ->and(TournamentRound::query()->whereNotNull('window_ends_at')->count())->toBe(0);

    $this->travelTo($cup->starts_at);
    cupTick();

    expect(TournamentRound::query()->whereNotNull('window_ends_at')->count())->toBe(1)
        ->and(ChessGame::query()->whereNotNull('tournament_match_id')->count())->toBe(1)
        ->and(count(eveningVersions($cup)))->toBe($beforeSwitch + 1);
});

test('a chess duel: three games back to back after a short break, the colours alternating, the league starting each', function () {
    $cup = runningCup(2, TournamentFormat::RoundRobin, ['iterations' => 3, 'rankBy' => 'points', 'roundRobinTieBreaks' => ['head-to-head', 'match-wins']]);
    $cup->forceFill(['starts_at' => now()->addHour()])->save();
    $service = app(ChessGameService::class);
    $whites = [];

    cupTick();
    expect(ChessGame::query()->count())->toBe(0);

    $this->travelTo($cup->starts_at);

    foreach (range(1, 3) as $number) {
        cupTick();
        $round = TournamentRound::query()->whereNotNull('window_ends_at')->orderByDesc('id')->firstOrFail();
        $game = ChessGame::query()->where('status', ChessGameStatus::Active)->sole();
        $whites[] = $game->white_id;

        // Planned length (14 min) plus the grace (15 min).
        expect($round->number)->toBe($number)
            ->and($round->window_ends_at->equalTo(now()->addMinutes(29)))->toBeTrue();

        $service->resign($game, $game->black);
        cupTick();

        // The next round waits for the break.
        expect(ChessGame::query()->where('status', ChessGameStatus::Active)->count())->toBe(0);
        $this->travel(3)->minutes();
    }

    $champion = app(TournamentChampion::class)->of($cup->refresh());

    expect($cup->status)->toBe(TournamentStatus::Finished)
        ->and($whites[0])->not->toBe($whites[1])
        ->and($whites[2])->toBe($whites[0])
        // White won every game: the player with White twice has 2 of 3.
        ->and($champion->user_id)->toBe($whites[0]);
});

test('a Rocket League duel is one best-of-3 series the league starts at the evening start', function () {
    $cup = runningCup(2, TournamentFormat::SingleElimination, ['bestOf' => 3, 'finalBestOf' => 3], 'rocket-league', '1v1');
    $cup->forceFill(['starts_at' => now()->addHour()])->save();

    cupTick();
    expect(SeriesMatch::query()->count())->toBe(0);

    $this->travelTo($cup->starts_at);
    cupTick();

    expect(SeriesMatch::query()->sole()->best_of)->toBe(3);
});

/* ---------- The table ----------------------------------------------------------------------------------------- */

test('round robin table: draws count half, head-to-head splits a tie, a full circle falls to the drawn seed order', function () {
    $four = runningCup(4, TournamentFormat::RoundRobin, ['rankBy' => 'points', 'roundRobinTieBreaks' => ['head-to-head', 'match-wins']]);
    [$a, $b, $c, $d] = TournamentParticipant::query()->where('tournament_id', $four->id)->orderBy('seed')->get()->all();

    foreach ([[$a, $b, null], [$a, $c, $a], [$a, $d, $d], [$b, $c, $b], [$b, $d, $b], [$c, $d, null]] as [$x, $y, $winner]) {
        eveningResult($four, $x, $y, $winner);
    }

    $options = $four->formatOptions();
    $table = Standings::table([$a->id, $b->id, $c->id, $d->id], app(TournamentRunner::class)->games($four), rankBy: $options->rankBy, tieBreaks: $options->roundRobinTieBreaks);

    // B 2½; A and D 1½ each, D beat A; C ½.
    expect(array_map(fn ($row) => [$row->entrant, $row->points], $table))->toBe([[$b->id, 2.5], [$d->id, 1.5], [$a->id, 1.5], [$c->id, 0.5]]);

    $four->forceFill(['cup_open_series' => null, 'cup_number' => 2])->save();

    $three = runningCup(3, TournamentFormat::RoundRobin, ['rankBy' => 'points', 'roundRobinTieBreaks' => ['head-to-head', 'match-wins']]);
    [$x, $y, $z] = TournamentParticipant::query()->where('tournament_id', $three->id)->orderBy('seed')->get()->all();

    foreach ([[$x, $y, $x], [$y, $z, $y], [$z, $x, $z]] as [$p, $q, $winner]) {
        eveningResult($three, $p, $q, $winner);
    }

    app(TournamentRunner::class)->sync($three);
    // As the public table reads it (TournamentView): entrants by seed, the seed order drawn from the block hash.
    $circle = Standings::table([$x->id, $y->id, $z->id], app(TournamentRunner::class)->games($three), rankBy: 'points', tieBreaks: ['head-to-head', 'match-wins']);

    expect($three->refresh()->status)->toBe(TournamentStatus::Finished)
        ->and(array_map(fn ($row) => [$row->entrant, $row->points], $circle))->toBe([[$x->id, 1.0], [$y->id, 1.0], [$z->id, 1.0]]);
});

test('in a round robin a game nobody tried to play is lost by both', function () {
    $cup = runningCup(3, TournamentFormat::RoundRobin, ['rankBy' => 'points']);
    $match = TournamentMatch::query()->where('tournament_id', $cup->id)->where('status', 'ready')->with('slots.participant', 'round.stage')->firstOrFail();

    expect(CasualCups::decision($cup, $match))->toMatchArray(['winner' => null, 'double_loss' => true, 'by' => 'league']);
});
