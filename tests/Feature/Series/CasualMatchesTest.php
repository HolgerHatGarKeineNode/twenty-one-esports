<?php

use App\Enums\Platform;
use App\Enums\SeriesResolution;
use App\Enums\SeriesStatus;
use App\Events\SeriesMatchChanged;
use App\Events\UserNotified;
use App\Jobs\PublishNostrEvent;
use App\Jobs\SendNostrDm;
use App\Models\Admin;
use App\Models\SeriesMatch;
use App\Models\SeriesQueueEntry;
use App\Models\User;
use App\Support\Series\CasualMatches;
use App\Support\Series\CasualQueue;
use App\Support\Series\CasualScheduler;
use App\Support\Series\SeriesService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

/*
 * A casual 1v1 after the pairing (P23, slice S1): the ready check, the
 * lobby and join flags, no-show claims, and the deadlines `casual:tick`
 * applies (void, forfeit, auto-confirm), each with its notification.
 */

beforeEach(fn () => $this->freezeTime());

test('the match starts once both players are ready, and every step is announced', function () {
    Event::fake([SeriesMatchChanged::class]);
    [$match, $anna, $bert] = casualPairing();
    $matches = app(CasualMatches::class);

    $match = $matches->ready($match, $anna);
    $matches->ready($match, $anna);

    expect($match->start_at)->toBeNull()
        ->and($match->casualNextDeadline())->toMatchArray(['kind' => 'ready', 'side' => null]);

    $this->travel(30)->seconds();
    $match = $matches->ready($match, $bert);

    expect($match->start_at?->getTimestamp())->toBe(now()->getTimestamp())
        ->and($match->casualNextDeadline())->toMatchArray(['kind' => 'lobby', 'side' => $match->host_side])
        ->and(casualRefusal(fn () => $matches->ready($match, User::factory()->create())))->toBe('not_player');

    // Pairing, the first ready, the start: three changes, the repeated ready none.
    Event::assertDispatchedTimes(SeriesMatchChanged::class, 3);
});

test('a missed ready check voids the match and puts the ready player first in the queue', function () {
    [$match, $anna, $bert] = casualPairing('rocket-league');
    $carl = User::factory()->create();
    app(CasualQueue::class)->join($carl, 'rocket-league', Platform::Switch, crossplay: false);
    app(CasualMatches::class)->ready($match, $bert);

    $this->travel(59)->seconds();
    expect(app(CasualScheduler::class)->tick()['unready'])->toBe(0);

    $this->travel(1)->seconds();
    expect(casualRefusal(fn () => app(CasualMatches::class)->ready($match, $anna)))->toBe('ready_missed')
        ->and(app(CasualScheduler::class)->tick()['unready'])->toBe(1);

    $match->refresh();
    $queue = app(CasualQueue::class);

    expect($match->status)->toBe(SeriesStatus::Resolved)
        ->and($match->resolution)->toBe(SeriesResolution::Void)
        ->and($queue->entryOf($anna))->toBeNull()
        ->and($queue->entryOf($bert)?->joined_at->lt($queue->entryOf($carl)->joined_at))->toBeTrue()
        // A missed ready check is not a no-show: it never locks.
        ->and(app(CasualMatches::class)->lockedUntil($anna))->toBeNull();
});

test('the guest may claim a no-show once the host let the lobby deadline pass, and the host can contest it', function () {
    Event::fake([UserNotified::class]);
    [$match, $host, $guest] = casualStarted();
    $matches = app(CasualMatches::class);

    $this->travel(4)->minutes();
    expect(casualRefusal(fn () => $matches->claimNoShow($match, $guest)))->toBe('noshow_early');

    $this->travel(1)->minutes();
    expect(casualRefusal(fn () => $matches->claimNoShow($match, $host)))->toBe('noshow_early');

    $match = $matches->claimNoShow($match, $guest);

    expect(casualAlerts())->toContain([$host->id, 'casual_noshow'])
        ->and($match->casualNextDeadline())->toMatchArray(['kind' => 'contest', 'side' => $match->host_side])
        ->and(casualRefusal(fn () => $matches->contestNoShow($match, $guest)))->toBe('no_claim')
        // The host answers the claim before sharing anything.
        ->and(casualRefusal(fn () => $matches->shareLobby($match, $host)))->toBe('noshow_pending');

    $this->travel(4)->minutes();
    $match = $matches->contestNoShow($match, $host);

    $this->travel(2)->minutes();

    expect($match->noshow_reported_at)->toBeNull()
        ->and(app(CasualScheduler::class)->tick()['forfeited'])->toBe(0)
        ->and($match->refresh()->status)->toBe(SeriesStatus::Accepted)
        ->and(casualRefusal(fn () => $matches->claimNoShow($match, $guest)))->toBe('noshow_once');
});

test('an uncontested no-show claim is forfeited to the claimer after five minutes, with no DM to either', function () {
    Queue::fake([SendNostrDm::class, PublishNostrEvent::class]);
    config(['esports.notifications.nsec' => bin2hex(random_bytes(32))]);
    [$match, $host, $guest] = casualStarted();
    $matches = app(CasualMatches::class);

    $this->travel(5)->minutes();
    $matches->claimNoShow($match, $guest);

    $this->travel(4)->minutes();
    expect(app(CasualScheduler::class)->tick()['forfeited'])->toBe(0);

    $this->travel(1)->minutes();
    expect(casualRefusal(fn () => $matches->contestNoShow($match, $host)))->toBe('contest_late')
        ->and(app(CasualScheduler::class)->tick()['forfeited'])->toBe(1);

    $match->refresh();

    expect($match->status)->toBe(SeriesStatus::Resolved)
        ->and($match->resolution)->toBe(SeriesResolution::Forfeit)
        ->and($match->winner)->toBe(casualSideOf($match, $guest))
        ->and(app(CasualScheduler::class)->tick()['forfeited'])->toBe(0);

    // Five minutes to contest is too short for a DM (NotificationKind::dmAllowed()): push and the bell only; the result is news.
    $dmsTo = fn (User $user) => Queue::pushed(SendNostrDm::class)->filter(fn (SendNostrDm $job) => $job->user->is($user))->count();
    expect($dmsTo($host))->toBe(0)
        ->and($dmsTo($guest))->toBe(0);
});

test('the host may claim a no-show only after the guest let the join deadline pass', function () {
    Event::fake([UserNotified::class]);
    [$match, $host, $guest] = casualStarted('rocket-league');
    $matches = app(CasualMatches::class);

    expect(casualRefusal(fn () => $matches->shareLobby($match, $guest)))->toBe('not_host')
        ->and(casualRefusal(fn () => $matches->markJoined($match, $guest)))->toBe('no_lobby_yet');

    $this->travel(3)->minutes();
    $match = $matches->shareLobby($match, $host);

    expect(casualAlerts())->toContain([$guest->id, 'casual_lobby_shared'])
        ->and($match->casualNextDeadline())->toMatchArray(['kind' => 'join', 'side' => casualSideOf($match, $guest)]);

    $this->travel(9)->minutes();
    expect(casualRefusal(fn () => $matches->claimNoShow($match, $host)))->toBe('noshow_early');

    $this->travel(1)->minutes();
    $matches->markJoined($match, $guest);

    expect(casualRefusal(fn () => $matches->claimNoShow($match, $host)))->toBe('noshow_early')
        ->and($match->refresh()->joined_at)->not->toBeNull();
});

test('a started match nobody reported is void 60 minutes after the start', function () {
    [$match] = casualStarted();

    $this->travel(59)->minutes();
    expect(app(CasualScheduler::class)->tick()['unreported'])->toBe(0);

    $this->travel(1)->minutes();
    $this->artisan('casual:tick')->assertSuccessful();

    expect($match->refresh()->status)->toBe(SeriesStatus::Resolved)
        ->and($match->resolution)->toBe(SeriesResolution::Void)
        ->and($match->winner)->toBe('none');
});

test('a report the other player did not answer is confirmed by the league after 30 minutes', function () {
    Event::fake([UserNotified::class]);
    [$match, $host, $guest] = casualStarted();
    $series = app(SeriesService::class);

    $series->saveLiveGame($match, $guest, 0, 3, 1, null);
    $series->report($match, $guest, []);

    expect(casualAlerts())->toContain([$host->id, 'casual_report'])
        ->and(casualAlerts())->not->toContain([$guest->id, 'casual_report']);

    $this->travel(29)->minutes();
    expect(app(CasualScheduler::class)->tick()['confirmed'])->toBe(0);

    $this->travel(1)->minutes();
    expect(app(CasualScheduler::class)->tick()['confirmed'])->toBe(1);

    $match->refresh();

    expect($match->status)->toBe(SeriesStatus::Resolved)
        ->and($match->resolution)->toBe(SeriesResolution::Admin)
        ->and($match->winner)->toBe('challenger')
        ->and($match->rated)->toBeFalse()
        ->and(casualAlerts())->toContain([$host->id, 'casual_result'], [$guest->id, 'casual_result']);
});

test('a casual match keeps its lobby off the server and its room renders in every phase', function () {
    [$match, $anna, $bert] = casualPairing('rocket-league');

    $this->actingAs($anna)->get(route('matches.room', $match))->assertOk();

    $matches = app(CasualMatches::class);
    $matches->ready($match, $anna);
    $match = $matches->ready($match, $bert);

    $this->actingAs($bert)->get(route('matches.room', $match))->assertOk();
    $this->get(route('matches.index'))->assertOk();

    expect(casualRefusal(fn () => app(SeriesService::class)->setLobby($match, $anna, 'lobby', 'secret', null)))->toBe('casual_match')
        ->and(casualRefusal(fn () => app(SeriesService::class)->reportNoShow($match, $anna)))->toBe('casual_match')
        ->and($match->refresh()->lobby_name)->toBeNull()
        ->and(SeriesQueueEntry::query()->count())->toBe(0);
});

test('a casual no-show claim never reaches the admin queue, while a series no-show report does', function () {
    [$match, , $guest] = casualStarted();
    $this->travel(5)->minutes();
    $match = app(CasualMatches::class)->claimNoShow($match, $guest);
    $series = SeriesMatch::factory()->accepted()->create(['noshow_side' => 'challenger', 'noshow_reported_at' => now()]);

    expect(SeriesMatch::query()->openCase()->pluck('id')->all())->toBe([$series->id])
        ->and(SeriesService::isOpenCase($match))->toBeFalse()
        ->and(SeriesService::isOpenCase($series))->toBeTrue();

    // Uncontested, the clock decides it; still no case for an admin.
    $this->travel(5)->minutes();
    app(CasualScheduler::class)->tick();

    expect(SeriesMatch::query()->openCase()->pluck('id')->all())->toBe([$series->id])
        ->and($match->refresh()->resolution)->toBe(SeriesResolution::Forfeit);
});

test('a pending no-show claim is answered by contesting it, not by scoring or reporting around it', function () {
    [$match, $host, $guest] = casualStarted();
    $matches = app(CasualMatches::class);
    $series = app(SeriesService::class);

    // The reviewer's repro: the guest claims, the accused host reports a win instead of contesting.
    $this->travel(5)->minutes();
    $matches->claimNoShow($match, $guest);

    expect(casualRefusal(fn () => $series->saveLiveGame($match, $host, 0, 3, 0, null)))->toBe('noshow_pending')
        ->and(casualRefusal(fn () => $series->report($match, $host, [])))->toBe('noshow_pending')
        ->and($match->refresh()->status)->toBe(SeriesStatus::Accepted)
        ->and($match->live_games)->toBeNull();

    $matches->contestNoShow($match, $host);
    $series->saveLiveGame($match, $host, 0, 3, 0, null);
    $series->report($match, $host, []);

    expect($match->refresh()->status)->toBe(SeriesStatus::Reported);
});

test('an admin decides a casual match only once it is disputed', function () {
    [$match, $host, $guest] = casualStarted();
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    $series = app(SeriesService::class);

    $series->saveLiveGame($match, $guest, 0, 2, 1, null);
    $series->report($match, $guest, []);

    expect(SeriesService::isDecidable($match->refresh()))->toBeFalse()
        ->and(casualRefusal(fn () => $series->decide($match, $admin, ['type' => 'void'], 'Not needed.')))->toBe('not_open_case');

    $series->respond($match, $host, 'disputed', 'The score was 1:2.', []);

    expect(SeriesService::isDecidable($match->refresh()))->toBeTrue();

    $series->decide($match, $admin, ['type' => 'void'], 'Both sides disagree on the score.');

    expect($match->refresh()->status)->toBe(SeriesStatus::Resolved)
        ->and($match->resolution)->toBe(SeriesResolution::Void);
});

test('the host hears when the guest joined the lobby, in the app only by default', function () {
    Event::fake([UserNotified::class]);
    Queue::fake([SendNostrDm::class, PublishNostrEvent::class]);
    config(['esports.notifications.nsec' => bin2hex(random_bytes(32))]);
    [$match, $host, $guest] = casualStarted();
    $matches = app(CasualMatches::class);

    $matches->shareLobby($match, $host);
    $matches->markJoined($match, $guest);

    expect(casualAlerts())->toContain([$host->id, 'casual_opponent_joined'])
        ->and(casualAlerts())->not->toContain([$guest->id, 'casual_opponent_joined'])
        ->and(Queue::pushed(SendNostrDm::class)->filter(fn (SendNostrDm $job) => $job->user->is($host))->count())->toBe(0);
});

test('the result reads from the winner\'s side: a challenged player who won 2 of 3 sees 2 : 1 next to their name', function () {
    [$match, $host, $guest] = casualStarted();
    $match->forceFill(['best_of' => 3])->save();
    $series = app(SeriesService::class);
    $series->saveLiveGame($match, $guest, 0, 5, 1, null);
    $series->saveLiveGame($match, $guest, 1, 0, 2, null);
    $series->saveLiveGame($match, $guest, 2, 5, 6, null);
    $series->report($match, $guest, []);
    $this->travel(30)->minutes();
    app(CasualScheduler::class)->tick();

    expect($match->refresh()->winner)->toBe('challenged');
    $html = $this->actingAs($guest)->get(route('matches.room', $match))->assertOk()->getContent();

    expect($html)->toMatch('/data-test="win-score">\s*2 : 1\s*</');
});

test('a solo side shows its player\'s live name: a player who renamed is never shown under the old name next to the new one', function () {
    [$match, $host] = casualStarted();
    $host->forceFill(['name' => 'Renamed Player'])->save();

    // The host's side is drawn at random: read it from the match.
    expect($match->fresh()->sideName($match->participantSideOf($host)))->toBe('Renamed Player');
});

test('the host confirms a guest who is in the lobby but forgot to say so, and the score can be sent either way', function () {
    Event::fake([UserNotified::class]);
    [$match, $host, $guest] = casualStarted('rocket-league');
    $matches = app(CasualMatches::class);

    expect(casualRefusal(fn () => $matches->markJoined($match, $host)))->toBe('no_lobby_yet');

    // Before anyone confirmed the join, both already have the submit under the games (user, 2026-10-05: "kein Button da").
    $this->actingAs($guest)->get(route('matches.room', $match))->assertOk()->assertSee('data-test="open-submit"', false);

    $match = $matches->shareLobby($match, $host);
    $this->actingAs($host)->get(route('matches.room', $match))->assertOk()
        ->assertSee('data-test="casual-host-joined"', false)
        ->assertSee('data-test="open-submit"', false);

    $match = $matches->markJoined($match, $host);

    expect($match->joined_at)->not->toBeNull()
        ->and(casualAlerts())->not->toContain([$host->id, 'casual_opponent_joined']);

    $this->actingAs($host)->get(route('matches.room', $match))->assertOk()->assertDontSee('data-test="casual-host-joined"', false);
});
