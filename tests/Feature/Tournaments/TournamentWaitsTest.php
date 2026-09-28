<?php

use App\Enums\NotificationKind;
use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Events\TournamentChanged;
use App\Models\Admin;
use App\Models\SeriesMatch;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentModerationEntry;
use App\Models\TournamentParticipant;
use App\Models\TournamentReminder;
use App\Models\User;
use App\Support\Series\SeriesService;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use App\Support\Tournaments\MatchWait;
use App\Support\Tournaments\TournamentBrackets;
use App\Support\Tournaments\TournamentControl;
use App\Support\Tournaments\TournamentReminders;
use App\Support\Tournaments\TournamentRuleViolation;
use App\Support\Tournaments\TournamentRunner;
use App\Support\Tournaments\TournamentScheduler;
use App\Support\Tournaments\TournamentWaits;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Helper tools for organizers and admins (P18, slice 5)
|--------------------------------------------------------------------------
|
| "Who blocks what": every open match with what it waits for, on whom and
| the countdown to the league's automatic decision, most urgent first, for
| admins and the tournament's organizer only. Reminders go to the waited-on
| players at the configured points, once each; by hand once per window,
| logged. The players see the same countdown on the tournament page and in
| the match room.
|
*/

beforeEach(function () {
    Queue::fake();
    Event::fake([TournamentChanged::class]);
    $this->freezeTime();
    config([
        'esports.series.noshow_minutes' => 15, 'esports.tournaments.report_hours' => 2, 'esports.tournaments.response_minutes' => 30,
        'esports.tournaments.reminders' => [30, 5], 'esports.tournaments.remind_every_minutes' => 10,
    ]);
});

/**
 * A running players-mode RL 1v1 knockout of four solo players, on site so
 * the config deadlines apply; both first-round series paired now.
 */
function waitsKnockout(): Tournament
{
    $tournament = Tournament::factory()->create([
        'game' => 'rocket-league', 'mode' => '1v1', 'format' => TournamentFormat::SingleElimination, 'capacity' => 4,
        'options' => FormatOptions::fromArray(['thirdPlace' => false], GameProfile::for('rocket-league', '1v1'))->toArray(),
        'results_mode' => TournamentResultsMode::Players, 'status' => TournamentStatus::Running,
        'slug' => 'waits-'.fake()->unique()->numberBetween(1, 1_000_000), 'on_site' => true, 'stations' => 4, 'created_by_id' => organizer()->id,
    ]);

    foreach (range(1, 4) as $index) {
        $player = User::factory()->create(['name' => "Player {$index}"]);
        TournamentParticipant::query()->create(['tournament_id' => $tournament->id, 'user_id' => $player->id, 'name' => "Player {$index}", 'rating' => 1200 - $index, 'members' => [$player->id]]);
    }

    app(TournamentBrackets::class)->generate($tournament, str_repeat('ab', 32));
    app(TournamentRunner::class)->sync($tournament);

    return $tournament->refresh();
}

/** @return list<SeriesMatch> the first round's series, in bracket order */
function waitsSeries(Tournament $tournament): array
{
    return TournamentMatch::query()->where('tournament_id', $tournament->id)->where('bracket', '!=', 'bye')->whereHas('seriesMatch')
        ->orderBy('position')->get()->map(fn (TournamentMatch $match): SeriesMatch => SeriesMatch::query()->where('tournament_match_id', $match->id)->sole())->all();
}

/** @return array{0: User, 1: User} the challenger's and the challenged player */
function waitsPlayers(SeriesMatch $series): array
{
    return [User::query()->findOrFail($series->rosterSide('challenger')[0]), User::query()->findOrFail($series->rosterSide('challenged')[0])];
}

/** `$winner` enters a clean series win and reports it (casual: nothing to sign). */
function waitsReport(SeriesMatch $series, User $winner): SeriesMatch
{
    $service = app(SeriesService::class);
    $challenger = $series->refresh()->captainSideOf($winner) === 'challenger';

    foreach (range(0, intdiv($series->best_of, 2)) as $index) {
        $service->saveLiveGame($series, $winner, $index, $challenger ? 3 : 1, $challenger ? 1 : 3, null);
    }

    $service->report($series, $winner, []);

    return $series->refresh();
}

function waitsAdmin(): User
{
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    return $admin;
}

function waitsReminders(User $user): int
{
    return $user->notifications()->where('data->kind', NotificationKind::TournamentReminder->value)->count();
}

/* ---------- Who blocks what ---------------------------------------------------------------------------------- */

test('every open match shows what it waits for, on whom, and the countdown, most urgent first', function () {
    $tournament = waitsKnockout();
    [$first, $second] = waitsSeries($tournament);
    [$a, $b] = waitsPlayers($second);

    // The second series is reported 10 minutes in: its answer is due in 30 minutes, before the first one's report (2 h).
    $this->travel(10)->minutes();
    waitsReport($second, $a);

    $waits = TournamentWaits::of($tournament);

    expect(array_map(fn (MatchWait $wait): string => $wait->state, $waits))->toBe(['response', 'report'])
        ->and($waits[0]->label)->toBe($second->label())
        ->and(array_column($waits[0]->waitingOn, 'user_id'))->toBe([$b->id])
        ->and($waits[0]->decidesAt?->getTimestamp())->toBe(now()->addMinutes(30)->getTimestamp())
        ->and($waits[0]->since?->getTimestamp())->toBe(now()->getTimestamp())
        ->and(array_column($waits[1]->waitingOn, 'user_id'))->toEqualCanonicalizing(array_map(fn (User $user): int => $user->id, waitsPlayers($first)))
        ->and($waits[1]->decidesAt?->getTimestamp())->toBe($first->start_at->copy()->addHours(2)->getTimestamp());

    // A dispute has no automatic decision: it needs an admin and goes first.
    app(SeriesService::class)->respond($second, $b, 'disputed', 'That was 2:3.', []);

    expect(array_map(fn (MatchWait $wait): string => $wait->state, TournamentWaits::of($tournament)))->toBe(['disputed', 'report']);

    Livewire::actingAs($tournament->creator)->test('tournament-waits', ['tournament' => $tournament])
        ->assertOk()->assertSeeInOrder(['Disputed', 'Needs you', $first->label(), 'No report', 'Auto-decision in', '1:50:00', 'The series goes to the admins']);
});

test('only admins and the tournament organizer see the panel and remind', function () {
    $tournament = waitsKnockout();
    [$series] = waitsSeries($tournament);
    [$a] = waitsPlayers($series);
    $match = $series->tournament_match_id;

    Livewire::actingAs($a)->test('tournament-waits', ['tournament' => $tournament])->assertForbidden();
    Livewire::actingAs(organizer())->test('tournament-waits', ['tournament' => $tournament])->assertForbidden();
    Livewire::actingAs($tournament->creator)->test('tournament-waits', ['tournament' => $tournament])->assertOk()->assertSee('Who blocks what');
    Livewire::actingAs(waitsAdmin())->test('tournament-waits', ['tournament' => $tournament])->assertOk();

    // The action checks again: a player or a foreign organizer calling it directly is refused.
    foreach ([$a, organizer()] as $intruder) {
        expect(fn () => app(TournamentReminders::class)->remind($tournament, $intruder, $match, $a->id))
            ->toThrow(TournamentRuleViolation::class, 'Only the organizer of this tournament or an admin can do this.');
    }

    expect(waitsReminders($a))->toBe(0)
        ->and(TournamentModerationEntry::query()->where('action', 'reminded')->count())->toBe(0);
});

test('a reminder by hand reaches the waited-on player, is logged and rate-limited', function () {
    $tournament = waitsKnockout();
    [$series, $other] = waitsSeries($tournament);
    [$a, $b] = waitsPlayers($series);
    $admin = waitsAdmin();

    Livewire::actingAs($admin)->test('tournament-waits', ['tournament' => $tournament])
        ->call('remind', $series->tournament_match_id, $a->id)
        ->assertSet('error', '')->assertSee('Reminder sent.')->assertDispatched('tournament-reminded')
        // Again within ten minutes: refused, nothing sent.
        ->call('remind', $series->tournament_match_id, $a->id)
        ->assertSet('error', 'You reminded this player a moment ago. Try again in 10 min.')
        // A player of another match is not waited on here.
        ->call('remind', $series->tournament_match_id, waitsPlayers($other)[0]->id)
        ->assertSet('error', 'This match no longer waits for this player.');

    $entry = TournamentModerationEntry::query()->where('tournament_id', $tournament->id)->where('action', 'reminded')->sole();

    expect(waitsReminders($a))->toBe(1)
        ->and(waitsReminders($b))->toBe(0)
        ->and($entry->user_id)->toBe($admin->id)
        ->and($entry->subject)->toBe($a->displayName().' ('.$series->label().')')
        ->and($entry->reason)->toBe('No report');

    // The window is over: the same player can be reminded again.
    $this->travel(10)->minutes();
    app(TournamentReminders::class)->remind($tournament, $admin, $series->tournament_match_id, $a->id);

    expect(waitsReminders($a))->toBe(2);
});

/* ---------- Automatic reminders ------------------------------------------------------------------------------ */

test('the automatic reminders go out at each point before the decision, exactly once', function () {
    $tournament = waitsKnockout();
    [$series] = waitsSeries($tournament);
    [$a, $b] = waitsPlayers($series);
    $tick = fn (): int => app(TournamentScheduler::class)->tick()['reminded'];

    // Report due 2 h after the start. 31 minutes before it: nothing yet.
    $this->travel(89)->minutes();
    expect($tick())->toBe(0);

    // 30 minutes before: both players (either may report), once each; the other series too.
    $this->travel(1)->minute();
    expect($tick())->toBe(4)
        ->and($tick())->toBe(0)
        ->and(waitsReminders($a))->toBe(1)
        ->and(waitsReminders($b))->toBe(1);

    $this->travel(20)->minutes();
    expect($tick())->toBe(0);

    // 5 minutes before: the second point, once.
    $this->travel(5)->minutes();
    expect($tick())->toBe(4)
        ->and($tick())->toBe(0)
        ->and(waitsReminders($a))->toBe(2)
        ->and(TournamentReminder::query()->where('subject', 'series:'.$series->id)->pluck('minutes_before')->sort()->values()->all())->toBe([5, 5, 30, 30]);

    $notice = $a->notifications()->where('data->kind', NotificationKind::TournamentReminder->value)->latest('created_at')->first();

    expect($notice->data['body'])->toContain('The league decides in 5 min.')->toContain('Report the result, or the series goes to the admins.');
});

test('a reminder point inside the wait is skipped, and a paused tournament reminds nobody', function () {
    $tournament = waitsKnockout();
    [$series] = waitsSeries($tournament);
    [$a, $b] = waitsPlayers($series);

    // A report opens a 30-minute answer window: its 30-minute point lies at the wait's own start, so only the 5-minute one is due.
    $this->travel(10)->minutes();
    waitsReport($series, $a);
    $this->travel(1)->minute();

    expect(app(TournamentReminders::class)->tick())->toBe(0);

    app(TournamentControl::class)->pause($tournament, $tournament->creator, 'Server trouble');
    $this->travel(25)->minutes();

    expect(app(TournamentReminders::class)->tick())->toBe(0)
        ->and(waitsReminders($b))->toBe(0);
});

test('a chess game waits on its first move until the league decides, and reminds the side to move', function () {
    $tournament = runningChess(TournamentFormat::SingleElimination, 2, TournamentResultsMode::Players, chessMode: 'correspondence');
    $game = TournamentMatch::query()->where('tournament_id', $tournament->id)->sole()->chessGame;
    [$white, $black] = [$game->white, $game->black];

    [$wait] = TournamentWaits::of($tournament);

    // Nobody opened the board yet: both missed it so far, and the game would be aborted.
    expect($wait->state)->toBe('first_move')
        ->and(array_column($wait->waitingOn, 'user_id'))->toEqualCanonicalizing([$white->id, $black->id])
        ->and($wait->consequenceText())->toBe('The game is aborted: both missed it')
        ->and($wait->decidesAt?->getTimestamp())->toBe(intdiv((int) $game->deadline_ms, 1000));

    $game->forceFill(['black_seen_at' => now()])->save();
    [$wait] = TournamentWaits::of($tournament);

    expect(array_column($wait->waitingOn, 'user_id'))->toBe([$white->id])
        ->and($wait->consequenceText())->toBe(TournamentParticipant::query()->where('user_id', $white->id)->value('name').' loses by forfeit');

    // 30 minutes before the day runs out: White only, once.
    $this->travel(24 * 60 - 30)->minutes();

    expect(app(TournamentReminders::class)->tick())->toBe(1)
        ->and(app(TournamentReminders::class)->tick())->toBe(0)
        ->and(waitsReminders($white))->toBe(1)
        ->and(waitsReminders($black))->toBe(0);

    // The board says what the first-move deadline decides (its countdown runs on the server's clock, chess.js).
    $this->actingAs($white)->get(route('games.show', $game))->assertOk()->assertSee('Auto-decision in :s s: :side loses by forfeit');
});

/* ---------- The players' countdown --------------------------------------------------------------------------- */

test('a player sees the countdown to the automatic decision on the tournament page and in the match room', function () {
    $tournament = waitsKnockout();
    [$series, $other] = waitsSeries($tournament);
    [$a, $b] = waitsPlayers($series);

    $this->travel(10)->minutes();
    waitsReport($series, $a);

    $this->actingAs($b)->get(route('tournaments.show', $tournament))->assertOk()
        ->assertSee('data-test="my-wait"', false)
        ->assertSeeInOrder(['Auto-decision in', '30:00', 'The league confirms the reported result, unrated', 'Confirm or dispute the reported result, or the league confirms it.']);

    $this->actingAs($b)->get(route('matches.room', $series))->assertOk()
        ->assertSee('data-test="room-auto-decision"', false)
        ->assertSee('Confirm or dispute the reported result, or the league confirms it.');

    // The reporting side sees the countdown, not the call to act; a spectator sees none.
    $this->actingAs($a)->get(route('matches.room', $series))->assertOk()
        ->assertSee('data-test="room-auto-decision"', false)
        ->assertDontSee('Confirm or dispute the reported result, or the league confirms it.');
    $this->actingAs(User::factory()->create())->get(route('tournaments.show', $tournament))->assertOk()
        ->assertDontSee('data-test="my-wait"', false);
});
