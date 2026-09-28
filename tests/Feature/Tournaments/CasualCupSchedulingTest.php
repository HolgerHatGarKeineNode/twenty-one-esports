<?php

use App\Enums\SeriesResolution;
use App\Enums\SeriesStatus;
use App\Enums\TournamentFormat;
use App\Models\SeriesMatch;
use App\Models\TournamentMatch;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\LeagueTime;
use App\Support\Series\CasualMatches;
use App\Support\Series\CasualScheduler;
use App\Support\Series\SeriesRuleViolation;
use App\Support\Tournaments\CasualCups;
use App\Support\Tournaments\CupSchedules;
use App\Support\Tournaments\TournamentWaits;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\TestSigner;

/*
|--------------------------------------------------------------------------
| Rocket League and EA Sports FC cups: agreed times (P25 S3)
|--------------------------------------------------------------------------
|
| In a cup match's window either player proposes one to three times, the
| other accepts one; the league starts the match's series when the check-in
| for that time opens (else for the auto slot), as a scheduled casual 1v1
| with origin `cup`: check-in, then the casual flow. A side that does not
| check in forfeits the cup match without a casual lock; nobody checking in
| is decided by the cup's rule, never replayed.
|
*/

beforeEach(function () {
    Queue::fake();
    config(['esports.league.nsec' => (new TestSigner)->secret]);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00:00', 'UTC'));
});

/** A running Rocket League 1v1 cup of four with round 1 open, and its first match with its two players (slot 0 first). */
function rlCupMatch(): array
{
    $cup = runningCup(4, TournamentFormat::DoubleElimination, ['grandFinal' => 'single', 'bestOf' => 3, 'finalBestOf' => 3], 'rocket-league', '1v1');
    cupTick();
    $match = TournamentMatch::query()->where('tournament_id', $cup->id)->where('status', 'ready')->where('bracket', '!=', 'bye')
        ->with(['slots.participant', 'round'])->orderBy('id')->firstOrFail();

    return [$cup, $match, User::query()->findOrFail($match->slots[0]->participant->user_id), User::query()->findOrFail($match->slots[1]->participant->user_id)];
}

function cupSeriesOf(TournamentMatch $match): ?SeriesMatch
{
    return SeriesMatch::query()->where('tournament_match_id', $match->id)->first();
}

/** The reason of the SeriesRuleViolation the action throws. */
function cupScheduleRefusal(Closure $action): ?string
{
    try {
        $action();
    } catch (SeriesRuleViolation $refused) {
        return $refused->reason;
    }

    return null;
}

test('a proposed and accepted time starts the cup series when its check-in opens, as a scheduled casual 1v1', function () {
    [$cup, $match, $a, $b] = rlCupMatch();
    $schedules = app(CupSchedules::class);
    [$early, $late] = [now()->addHours(2)->getTimestamp(), now()->addHours(3)->getTimestamp()];

    $schedules->propose($match, $a, [$late, $early]);
    $schedules->accept($match, $b, $late);
    $agreed = CarbonImmutable::createFromTimestamp($late);

    expect($match->refresh()->schedule)->toMatchArray(['by' => $a->id, 'proposals' => [$early, $late], 'agreed_at' => $late, 'accepted_by' => $b->id])
        ->and($b->notifications()->get()->pluck('data.title')->all())->toContain($a->displayName().' suggests times for your cup match')
        ->and($a->notifications()->get()->pluck('data.title')->last())->toStartWith($b->displayName().' accepted ');

    $this->travelTo($agreed->subMinutes(11));
    cupTick();
    expect(cupSeriesOf($match))->toBeNull();

    $this->travelTo($agreed->subMinutes(10));
    cupTick();
    $series = cupSeriesOf($match);

    expect($series->origin)->toBe(SeriesMatch::ORIGIN_CUP)
        ->and($series->status)->toBe(SeriesStatus::Accepted)
        ->and($series->best_of)->toBe(3)
        ->and($series->rated)->toBeFalse()
        ->and($series->deadlines)->toBeNull()
        ->and($series->start_at->equalTo($agreed))->toBeTrue()
        ->and($series->ready_by->equalTo($agreed->addMinutes(10)))->toBeTrue()
        ->and($series->scheduledAt()?->getTimestamp())->toBe($late)
        ->and($series->rosterSide('challenger'))->toBe([$a->id])
        ->and($series->awaitsCheckIn())->toBeTrue();
});

test('proposals follow the rules: inside the window, not your own, only before an agreement, series games only', function () {
    [$cup, $match, $a, $b] = rlCupMatch();
    $schedules = app(CupSchedules::class);
    $inWindow = now()->addHours(5)->getTimestamp();

    expect(cupScheduleRefusal(fn () => $schedules->propose($match, $a, [$match->round->window_ends_at->addHour()->getTimestamp()])))->toBe('cup_window')
        ->and(cupScheduleRefusal(fn () => $schedules->propose($match, $a, [$inWindow, $inWindow + 60, $inWindow + 120, $inWindow + 180])))->toBe('proposals_count')
        ->and(cupScheduleRefusal(fn () => $schedules->propose($match, User::factory()->create(), [$inWindow])))->toBe('not_your_match');

    $schedules->propose($match, $a, [$inWindow]);

    expect(cupScheduleRefusal(fn () => $schedules->accept($match, $a, $inWindow)))->toBe('cup_no_proposal')
        ->and(cupScheduleRefusal(fn () => $schedules->accept($match, $b, $inWindow + 60)))->toBe('cup_proposal_closed');

    $schedules->accept($match, $b, $inWindow);

    expect(cupScheduleRefusal(fn () => $schedules->propose($match, $b, [$inWindow + 600])))->toBe('cup_agreed');

    $chess = runningCup(4);
    $cup->forceFill(['cup_open_series' => null])->save();
    $chessMatch = TournamentMatch::query()->where('tournament_id', $chess->id)->where('status', 'ready')->with('slots.participant')->firstOrFail();
    $chessPlayer = User::query()->findOrFail($chessMatch->slots[0]->participant->user_id);

    expect(cupScheduleRefusal(fn () => $schedules->propose($chessMatch, $chessPlayer, [$inWindow])))->toBe('cup_not_schedulable');
});

test('without an agreement the series starts for the auto slot', function () {
    [$cup, $match] = rlCupMatch();
    $slot = CasualCups::autoSlot($match->round->window_ends_at);

    $this->travelTo($slot->subMinutes(11));
    cupTick();
    expect(cupSeriesOf($match))->toBeNull();

    $this->travelTo($slot->subMinutes(10));
    cupTick();

    expect(cupSeriesOf($match)->start_at->equalTo($slot))->toBeTrue();
});

test('a side that does not check in loses the cup match by forfeit, and it does not lock the casual queue', function () {
    [$cup, $match, $a, $b] = rlCupMatch();
    $schedules = app(CupSchedules::class);
    $at = now()->addHours(2)->getTimestamp();
    $schedules->propose($match, $a, [$at]);
    $schedules->accept($match, $b, $at);

    $this->travelTo(CarbonImmutable::createFromTimestamp($at));
    cupTick();
    app(CasualMatches::class)->checkIn(cupSeriesOf($match), $a);

    // A casual no-show of $b the day before: with the cup forfeit it would be two, the lock.
    casualNoShowLoss($b, now()->subHour());

    $this->travel(11)->minutes();
    app(CasualScheduler::class)->tick();

    expect(cupSeriesOf($match)->resolution)->toBe(SeriesResolution::Forfeit)
        ->and($match->refresh()->result['winner'])->toBe(0)
        ->and($match->result['forfeit'])->toBeTrue()
        ->and(app(CasualMatches::class)->lockedUntil($b))->toBeNull();
});

test('nobody checked in: not replayed, the cup rule decides (the proposer tried to play)', function () {
    [$cup, $match, $a, $b] = rlCupMatch();
    app(CupSchedules::class)->propose($match, $b, [now()->addHours(2)->getTimestamp()]);
    $slot = CasualCups::autoSlot($match->round->window_ends_at);

    $this->travelTo($slot->subMinutes(10));
    cupTick();
    $this->travelTo($slot->addMinutes(11));
    app(CasualScheduler::class)->tick();
    cupTick();

    expect(cupSeriesOf($match)->resolution)->toBe(SeriesResolution::Void)
        ->and(SeriesMatch::query()->where('tournament_match_id', $match->id)->count())->toBe(1)
        ->and($match->refresh()->result['decided'])->toBe('acted')
        ->and($match->result['winner'])->toBe(1);
});

test('Rocket League and EA Sports FC cups open with their own series lengths', function (string $game, string $name, int $bestOf, int $finalBestOf) {
    $cup = app(CasualCups::class)->ensure($game);

    expect($cup->name)->toBe("{$name} Casual Cup #1")
        ->and($cup->mode)->toBe('1v1')
        ->and($cup->capacity)->toBe(4)
        ->and($cup->formatOptions()->bestOf)->toBe($bestOf)
        ->and($cup->formatOptions()->finalBestOf)->toBe($finalBestOf);
})->with([
    ['rocket-league', 'Rocket League', 3, 3],
    ['ea-sports-fc-26', 'EA FC 26', 1, 3],
    ['ea-sports-fc-27', 'EA FC 27', 1, 3],
]);

test('the cup card lets one player propose times and the other accept one', function () {
    [$cup, $match, $a, $b] = rlCupMatch();
    $at = now()->addHours(4)->setTimezone('Europe/Berlin')->format('Y-m-d\TH:i');

    Livewire::actingAs($a)->test('pages::tournaments.show', ['tournament' => $cup])
        ->assertSeeHtml('data-test="cup-schedule-form"')
        ->set('cupTimes.0', $at)
        ->call('proposeCupTimes')
        ->assertSet('cupError', '')
        ->assertSeeHtml('data-test="cup-schedule-waiting"');

    $proposed = $match->refresh()->schedule['proposals'][0];

    Livewire::actingAs($b)->test('pages::tournaments.show', ['tournament' => $cup])
        ->assertSeeHtml('data-test="cup-schedule-accept"')
        ->call('acceptCupTime', $proposed)
        ->assertSet('cupError', '')
        ->assertSeeHtml('data-test="cup-schedule-agreed"');

    expect(CupSchedules::agreedAt($match->refresh())?->getTimestamp())->toBe($proposed);
});

/** The wait of a cup match as "Who blocks what" and the reminders read it, with the user ids it waits on. */
function cupWait(TournamentMatch $match): array
{
    $match = TournamentMatch::query()->with(['tournament', 'round', 'slots.participant', 'seriesMatch.latestReport', 'chessGame'])->findOrFail($match->id);
    $wait = TournamentWaits::forMatch($match->tournament, $match);

    return [$wait, array_column($wait->waitingOn, 'user_id')];
}

test('a cup match without an agreed time waits on both players for a time, then on the one who has to answer, until the auto slot', function () {
    [$cup, $match, $a, $b] = rlCupMatch();
    $slot = CasualCups::autoSlot($match->round->window_ends_at);

    [$wait, $on] = cupWait($match);
    expect($wait->state)->toBe('cup_time')
        ->and($on)->toEqualCanonicalizing([$a->id, $b->id])
        ->and($wait->decidesAt?->equalTo($slot))->toBeTrue()
        ->and($wait->consequenceText())->toBe('The league starts it at '.LeagueTime::stamp($slot))
        ->and($wait->remindable)->toBeTrue();

    // $a proposed: only $b has to answer.
    $proposed = now()->addHours(3)->getTimestamp();
    app(CupSchedules::class)->propose($match, $a, [$proposed]);
    [$wait, $on] = cupWait($match);
    expect($wait->state)->toBe('cup_time')->and($on)->toBe([$b->id]);

    // The proposal ran out unanswered (answer by the first proposed time at the latest): both again.
    $this->travelTo(CarbonImmutable::createFromTimestamp($proposed)->addMinute());
    [$wait, $on] = cupWait($match);
    expect($wait->state)->toBe('cup_time')->and($on)->toEqualCanonicalizing([$a->id, $b->id]);
});

test('a started cup series waits on the host to share the lobby, then on the guest to join it', function () {
    [$cup, $match, $a, $b] = rlCupMatch();
    $schedules = app(CupSchedules::class);
    $at = now()->addHours(2)->getTimestamp();
    $schedules->propose($match, $a, [$at]);
    $schedules->accept($match, $b, $at);

    $this->travelTo(CarbonImmutable::createFromTimestamp($at));
    cupTick();
    $series = cupSeriesOf($match);
    app(CasualMatches::class)->checkIn($series, $a);
    app(CasualMatches::class)->checkIn($series, $b);
    $series->refresh();
    [$host, $guest] = $series->host_side === 'challenger' ? [$a, $b] : [$b, $a];
    $entry = fn (User $player): string => (string) TournamentParticipant::query()->where('tournament_id', $cup->id)->where('user_id', $player->id)->value('name');

    [$wait, $on] = cupWait($match);
    expect($wait->state)->toBe('lobby')
        ->and($on)->toBe([$host->id])
        ->and($wait->decidesAt?->equalTo($series->casualLobbyDueAt()))->toBeTrue()
        ->and($wait->consequenceText())->toBe($entry($guest).' may claim a no-show')
        ->and($wait->url)->toBe(route('matches.room', $series));

    $this->travel(2)->minutes();
    app(CasualMatches::class)->shareLobby($series, $host);
    $series->refresh();

    [$wait, $on] = cupWait($match);
    expect($wait->state)->toBe('join')
        ->and($on)->toBe([$guest->id])
        ->and($wait->decidesAt?->equalTo($series->casualJoinDueAt()))->toBeTrue()
        ->and($wait->since?->equalTo($series->lobby_shared_at))->toBeTrue()
        ->and($wait->consequenceText())->toBe($entry($host).' may claim a no-show');

    app(CasualMatches::class)->markJoined($series, $guest);
    expect(cupWait($match)[0]->state)->not->toBeIn(['lobby', 'join']);
});
