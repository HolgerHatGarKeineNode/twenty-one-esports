<?php

use App\Enums\Platform;
use App\Enums\SeriesResolution;
use App\Enums\SeriesStatus;
use App\Events\ChessGameStarted;
use App\Events\UserNotified;
use App\Jobs\PublishNostrEvent;
use App\Jobs\SendNostrDm;
use App\Models\ChessGame;
use App\Models\SeriesMatch;
use App\Models\User;
use App\Support\Chess\ChessGameService;
use App\Support\Series\CasualChallenges;
use App\Support\Series\CasualMatches;
use App\Support\Series\CasualQueue;
use App\Support\Series\CasualScheduler;
use App\Support\Series\SeriesService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/*
 * Scheduled casual 1v1 (P23 S4): a challenge with 1 to 3 times, the
 * answer, the reminder, the check-in window and what a missed check-in
 * costs; after both checked in the instant flow takes over.
 */

beforeEach(fn () => $this->freezeTime());

/**
 * An open challenge from Anna to Bert, two times tomorrow evening, answer by tomorrow noon.
 *
 * @return array{0: SeriesMatch, 1: User, 2: User, 3: list<int>}
 */
function scheduledChallenge(string $game = 'rocket-league'): array
{
    [$anna, $bert] = User::factory()->count(2)->create();
    $times = [now()->addDay()->setTime(20, 0)->getTimestamp(), now()->addDay()->setTime(21, 0)->getTimestamp()];
    $match = app(CasualChallenges::class)->challenge($anna, $bert, $game, Platform::Pc, true, $times, now()->addDay()->setTime(12, 0)->getTimestamp(), 'gl hf');

    return [$match, $anna, $bert, $times];
}

/**
 * The challenge accepted for its first time, the clock standing at `$before` minutes before it.
 *
 * @return array{0: SeriesMatch, 1: User, 2: User}
 */
function scheduledAccepted(int $before = 60): array
{
    [$match, $anna, $bert, $times] = scheduledChallenge();
    $match = app(CasualChallenges::class)->accept($match, $bert, $times[0], Platform::PlayStation, true);
    test()->travelTo(now()->setTimestamp($times[0])->subMinutes($before));

    return [$match->refresh(), $anna, $bert];
}

test('a challenge is answered with one of its times and the platform, and both hear of it', function () {
    Event::fake([UserNotified::class]);
    [$match, $anna, $bert, $times] = scheduledChallenge();

    expect($match->status)->toBe(SeriesStatus::Open)
        ->and($match->origin)->toBe('challenge')
        ->and($match->proposals)->toBe($times)
        ->and($match->message)->toBe('gl hf')
        ->and($match->sides)->toBe(['challenger' => [$anna->id], 'challenged' => [$bert->id]])
        ->and(casualAlerts())->toBe([[$bert->id, 'casual_challenge']]);

    $match = app(CasualChallenges::class)->accept($match, $bert, $times[1], Platform::Xbox, true);

    expect($match->status)->toBe(SeriesStatus::Accepted)
        ->and($match->start_at->getTimestamp())->toBe($times[1])
        ->and($match->ready_by->getTimestamp())->toBe($times[1] + 600)
        ->and($match->casual['scheduled_at'])->toBe($times[1])
        ->and($match->casual['queue']['challenged'])->toBe(['platform' => 'xbox', 'crossplay' => true])
        ->and($match->casualNextDeadline())->toMatchArray(['kind' => 'checkin'])
        ->and(casualAlerts())->toContain([$anna->id, 'casual_challenge_answer']);
});

test('a challenge keeps to the schedule rules of a lineup challenge and to fitting platforms', function () {
    [$match, $anna, $bert, $times] = scheduledChallenge('ea-sports-fc-26');
    $challenges = app(CasualChallenges::class);
    $later = now()->addDays(2)->getTimestamp();

    expect(casualRefusal(fn () => $challenges->challenge($anna, $bert, 'rocket-league', Platform::Pc, true, [$later, $later + 60, $later + 120, $later + 180], $later - 60)))->toBe('proposals_count')
        ->and(casualRefusal(fn () => $challenges->challenge($anna, $bert, 'rocket-league', Platform::Pc, true, [$later], $later + 60)))->toBe('respond_by')
        ->and(casualRefusal(fn () => $challenges->challenge($anna, $bert, 'chess', Platform::Pc, true, [$later], $later - 60)))->toBe('unknown_game')
        ->and(casualRefusal(fn () => $challenges->accept($match, $bert, $times[0] + 60, Platform::Pc, true)))->toBe('start_not_proposed')
        ->and(casualRefusal(fn () => $challenges->accept($match, $bert, $times[0], Platform::Switch, true)))->toBe('platforms_incompatible')
        ->and(casualRefusal(fn () => $challenges->accept($match, $anna, $times[0], Platform::Pc, true)))->toBe('not_challenged')
        // The lineup answer is not the way to answer a casual challenge.
        ->and(casualRefusal(fn () => app(SeriesService::class)->answer($match, $bert, 'accepted', $times[0], [])))->toBe('casual_match')
        ->and($match->refresh()->status)->toBe(SeriesStatus::Open);
});

test('a challenge can be declined or withdrawn while open, and expires unanswered with word to the challenger', function () {
    Event::fake([UserNotified::class]);
    $challenges = app(CasualChallenges::class);

    [$declined, $anna, $bert] = scheduledChallenge();
    $challenges->decline($declined, $bert);
    [$withdrawn, $carl] = scheduledChallenge();
    $challenges->withdraw($withdrawn, $carl);
    [$expiring, $dora] = scheduledChallenge();

    expect($declined->refresh()->status)->toBe(SeriesStatus::Declined)
        ->and($withdrawn->refresh()->status)->toBe(SeriesStatus::Withdrawn)
        ->and(casualRefusal(fn () => $challenges->decline($declined, $bert)))->toBe('challenge_closed');

    $this->travelTo(now()->addDay()->setTime(12, 0));

    // The lineup expiry leaves it to casual:tick, which tells the challenger.
    expect(app(SeriesService::class)->expireDue())->toBe(0)
        ->and(app(CasualScheduler::class)->tick()['expired'])->toBe(1)
        ->and($expiring->refresh()->status)->toBe(SeriesStatus::Expired)
        ->and(casualAlerts())->toContain([$anna->id, 'casual_challenge_answer'], [$dora->id, 'casual_challenge_answer'])
        ->and(casualAlerts())->not->toContain([$carl->id, 'casual_challenge_answer']);
});

test('a player sends at most so many challenges a day, and fewer to the same player', function () {
    config(['esports.casual.challenges_per_day' => 4, 'esports.casual.challenges_per_recipient_per_day' => 2]);
    [$anna, $bert] = User::factory()->count(2)->create();
    $challenges = app(CasualChallenges::class);
    $send = fn (User $to) => casualRefusal(fn () => $challenges->challenge($anna, $to, 'rocket-league', Platform::Pc, true, [now()->addDay()->getTimestamp()], now()->addHours(2)->getTimestamp()));

    expect([$send($bert), $send($bert), $send($bert)])->toBe([null, null, 'challenge_limit'])
        ->and([$send(User::factory()->create()), $send(User::factory()->create()), $send(User::factory()->create())])->toBe([null, null, 'challenge_limit']);

    $this->travel(1)->day();

    expect($send($bert))->toBeNull();
});

test('both players get the reminder 15 minutes before and the check-in call 10 minutes before, once each', function () {
    Event::fake([UserNotified::class]);
    Queue::fake([SendNostrDm::class, PublishNostrEvent::class]);
    config(['esports.notifications.nsec' => bin2hex(random_bytes(32))]);
    [$match, $anna, $bert] = scheduledAccepted(before: 16);
    $scheduler = app(CasualScheduler::class);

    expect($scheduler->tick()['reminded'])->toBe(0);

    $this->travel(1)->minutes();
    expect($scheduler->tick()['reminded'])->toBe(1)
        ->and($scheduler->tick()['reminded'])->toBe(0)
        ->and(casualAlerts())->toContain([$anna->id, 'casual_reminder'], [$bert->id, 'casual_reminder'])
        // The reminder goes out as a DM by default: it is for a player who is away.
        ->and(Queue::pushed(SendNostrDm::class)->filter(fn (SendNostrDm $job) => $job->user->is($anna))->count())->toBeGreaterThanOrEqual(1);

    $this->travel(5)->minutes();
    expect($scheduler->tick())->toMatchArray(['checkin_opened' => 1])
        ->and($scheduler->tick()['checkin_opened'])->toBe(0)
        ->and(casualAlerts())->toContain([$anna->id, 'casual_checkin'], [$bert->id, 'casual_checkin']);
});

test('the check-in opens 10 minutes before, and the match starts once both are in', function () {
    [$match, $anna, $bert] = scheduledAccepted(before: 11);
    $matches = app(CasualMatches::class);

    expect(casualRefusal(fn () => $matches->checkIn($match, $anna)))->toBe('checkin_not_open')
        ->and(casualRefusal(fn () => $matches->ready($match, $anna)))->toBe('not_in_ready_check');

    $this->travel(1)->minutes();
    $match = $matches->checkIn($match, $anna);

    expect($match->casualUnderWay())->toBeFalse()
        ->and(casualRefusal(fn () => app(SeriesService::class)->saveLiveGame($match, $anna, 0, 3, 0, null)))->toBe('not_checked_in')
        ->and(casualRefusal(fn () => $matches->shareLobby($match, $anna)))->toBe('not_checked_in');

    $this->travel(3)->minutes();
    $match = $matches->checkIn($match, $bert);

    // The casual flow runs from the second check-in on, as after a ready check.
    expect($match->casualUnderWay())->toBeTrue()
        ->and($match->start_at->getTimestamp())->toBe(now()->getTimestamp())
        ->and($match->casualNextDeadline())->toMatchArray(['kind' => 'lobby'])
        ->and($match->casualLobbyDueAt()->getTimestamp())->toBe(now()->addMinutes(5)->getTimestamp())
        ->and(app(CasualScheduler::class)->tick()['checkin_missed'])->toBe(0);
});

test('a side that did not check in loses by forfeit, counted for the lock; neither: void', function () {
    $challenges = app(CasualChallenges::class);
    [$match, $anna, $bert, $times] = scheduledChallenge();
    [$empty, , $dora] = scheduledChallenge();
    $challenges->accept($match, $bert, $times[0], Platform::Pc, true);
    $challenges->accept($empty, $dora, $times[0], Platform::Pc, true);
    $this->travelTo(now()->setTimestamp($times[0])->subMinutes(5));
    app(CasualMatches::class)->checkIn($match, $bert);
    $scheduler = app(CasualScheduler::class);

    $this->travel(14)->minutes();
    expect($scheduler->tick()['checkin_missed'])->toBe(0);

    $this->travel(1)->minutes();
    expect(casualRefusal(fn () => app(CasualMatches::class)->checkIn($match, $anna)))->toBe('checkin_closed')
        ->and($scheduler->tick()['checkin_missed'])->toBe(2);

    expect($match->refresh()->status)->toBe(SeriesStatus::Resolved)
        ->and($match->resolution)->toBe(SeriesResolution::Forfeit)
        ->and($match->winner)->toBe('challenged')
        ->and($empty->refresh()->resolution)->toBe(SeriesResolution::Void);

    // One more no-show within the day locks Anna, as a no-show in an instant match would.
    casualNoShowLoss($anna, now());

    expect(app(CasualMatches::class)->lockedUntil($anna))->not->toBeNull();
});

test('a scheduled match keeps its players free until its check-in window opens', function () {
    Event::fake([ChessGameStarted::class]);
    [$match, $anna, $bert] = scheduledAccepted(before: 11);
    $queue = app(CasualQueue::class);

    expect(casualRefusal(fn () => $queue->join($anna, 'ea-sports-fc-26', Platform::Pc)))->toBeNull();
    $queue->leave($anna);

    $this->travel(1)->minutes();

    expect(casualRefusal(fn () => $queue->join($anna, 'ea-sports-fc-26', Platform::Pc)))->toBe('already_playing')
        ->and(casualChessRefusal(fn () => app(ChessGameService::class)->start($bert, User::factory()->create(), 'blitz')))->toBe('casual_playing')
        ->and(ChessGame::query()->count())->toBe(0);
});

test('a player in another running casual 1v1 finishes it before checking in', function () {
    [$match, $anna, $bert] = scheduledAccepted(before: 11);
    $other = app(CasualMatches::class)->create($anna, User::factory()->create(), 'ea-sports-fc-26', SeriesMatch::ORIGIN_QUEUE, []);

    $this->travel(1)->minutes();

    expect(casualRefusal(fn () => app(CasualMatches::class)->checkIn($match, $anna)))->toBe('already_playing')
        ->and(casualRefusal(fn () => app(CasualMatches::class)->checkIn($match, $bert)))->toBeNull()
        ->and($other->refresh()->status)->toBe(SeriesStatus::Accepted);
});

test('the room answers an open challenge and checks in, and the challenge page sends one', function () {
    [$match, $anna, $bert, $times] = scheduledChallenge();

    $this->actingAs($bert)->get(route('matches.room', $match))->assertOk()->assertSee('data-test="casual-challenge"', false);

    Livewire::actingAs($bert)->test('pages::matches.room', ['match' => $match])
        ->set('pickedStart', $times[0])->set('casualPlatform', 'playstation')
        ->call('casualAccept')->assertSet('error', '');

    expect($match->refresh()->status)->toBe(SeriesStatus::Accepted);

    $this->travelTo(now()->setTimestamp($times[0])->subMinutes(5));
    $this->actingAs($anna)->get(route('matches.room', $match))->assertOk()->assertSee('data-test="casual-checkin"', false);
    Livewire::actingAs($anna)->test('pages::matches.room', ['match' => $match])->call('casualCheckIn')->assertSet('error', '');

    expect($match->refresh()->ready_at_challenger)->not->toBeNull();

    $carl = User::factory()->create();
    $this->actingAs($anna)->get(route('challenges.casual', ['to' => $carl->id]))->assertOk()->assertSee($carl->displayName());

    Livewire::actingAs($anna)->test('pages::challenges.casual', ['opponentId' => $carl->id])
        ->set('game', 'ea-sports-fc-27')
        ->call('send')
        ->assertRedirect();

    expect(SeriesMatch::query()->where('origin', 'challenge')->whereJsonContains('sides->challenged', $carl->id)->sole()->game)->toBe('ea-sports-fc-27');
});
