<?php

use App\Enums\NotificationKind;
use App\Enums\TournamentStatus;
use App\Models\NostrEvent;
use App\Models\Tournament;
use App\Models\TournamentModerationEntry;
use App\Models\TournamentSignup;
use App\Models\User;
use App\Support\LeagueTime;
use App\Support\Tournaments\TournamentDraws;
use App\Support\Tournaments\TournamentEditor;
use App\Support\Tournaments\TournamentPublisher;
use App\Support\Tournaments\TournamentRuleViolation;
use App\Support\Tournaments\TournamentSignups;
use App\Support\Tournaments\TournamentStartVote;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\TestSigner;

/*
|--------------------------------------------------------------------------
| The start vote of a one-day tournament
|--------------------------------------------------------------------------
|
| DerCaddy, 2026-11-09: Blitz and Rapid tournaments whose players vote on the
| day and time until a deadline, in the lean form: the organizer offers two to
| four starts, the entries tick the ones they can make until the vote closes
| (at least an hour before sign-up), then the start most entries can make wins
| (a tie goes to the earlier one), is published, logged and told to every
| player; whoever did not tick it hears they can still pull out. An entry that
| does not vote counts for the earliest start (user decision 2026-11-10).
|
*/

beforeEach(function () {
    Queue::fake();
    $this->league = new TestSigner;
    config(['esports.league.nsec' => $this->league->secret, 'esports.notifications.nsec' => bin2hex(random_bytes(32))]);
    // November: Berlin is UTC+1, so 20:00 Berlin is 19:00 UTC.
    $this->travelTo(CarbonImmutable::parse('2026-11-09 12:00', 'UTC'));
});

/** A draft blitz tournament whose start is put to a vote: Fri 20 19:00, Sat 21 18:00, Sun 22 16:00 UTC, the vote until Wed 18 19:00 UTC. */
function votingDraft(array $attributes = []): Tournament
{
    $tournament = Tournament::factory()->create(['created_by_id' => organizer()->id, 'starts_at' => CarbonImmutable::parse('2026-11-20 19:00', 'UTC'), ...$attributes]);

    app(TournamentEditor::class)->update($tournament, $tournament->creator, [
        'start_options' => ['2026-11-20 19:00', '2026-11-21 18:00', '2026-11-22 16:00'],
        'start_vote_until' => CarbonImmutable::parse('2026-11-18 19:00', 'UTC'),
    ]);

    return $tournament->refresh();
}

/** The same, published: sign-up until Thu 19 19:00 UTC. */
function votingTournament(): Tournament
{
    $tournament = votingDraft();

    return app(TournamentPublisher::class)->publish($tournament, $tournament->creator, CarbonImmutable::parse('2026-11-19 19:00', 'UTC'));
}

/** @return array{0: User, 1: TournamentSignup} */
function voter(Tournament $tournament, string $name): array
{
    [$user, $key] = keyedPlayer();
    $user->forceFill(['name' => $name])->save();

    return [$user, soloSignup($tournament, $user, $key)];
}

test('the organizer offers starts on the edit page: the start becomes the earliest, the others stand below it', function () {
    $tournament = Tournament::factory()->create(['created_by_id' => organizer()->id, 'starts_at' => CarbonImmutable::parse('2026-11-21 18:00', 'UTC')]);

    Livewire::actingAs($tournament->creator)->test('pages::admin.tournament-edit', ['tournament' => $tournament])
        ->assertSee(__('Let the entries vote on the start'))
        ->set('voteStart', true)
        // Typed in Berlin time: 20 Nov 20:00 and 22 Nov 17:00 are 19:00 and 16:00 UTC.
        ->set('startOptions', ['2026-11-22T17:00', '2026-11-20T20:00', ''])
        ->set('voteUntil', '2026-11-18T20:00')
        ->call('save')
        ->assertSet('error', '')
        ->assertSet('startsAt', '2026-11-20T20:00')
        ->assertSet('startOptions', ['2026-11-21T19:00', '2026-11-22T17:00', ''])
        ->assertSet('voteStart', true);

    $tournament->refresh();

    expect($tournament->start_options)->toBe(['2026-11-20 19:00', '2026-11-21 18:00', '2026-11-22 16:00'])
        ->and(TournamentStartVote::key($tournament->starts_at))->toBe('2026-11-20 19:00')
        ->and(TournamentStartVote::key($tournament->start_vote_until))->toBe('2026-11-18 19:00')
        ->and($tournament->hasOpenStartVote())->toBeTrue();

    // Switched off again: no vote, the start stays where it is (one save every 2 s per tournament).
    $this->travel(3)->seconds();
    Livewire::actingAs($tournament->creator)->test('pages::admin.tournament-edit', ['tournament' => $tournament])
        ->set('voteStart', false)
        ->call('save')
        ->assertSet('error', '');

    expect($tournament->refresh()->start_options)->toBeNull()
        ->and($tournament->start_vote_until)->toBeNull()
        ->and(TournamentStartVote::key($tournament->starts_at))->toBe('2026-11-20 19:00');
});

test('a vote needs two to four starts ahead, a close ahead and an hour before sign-up, and a tournament played on one evening', function () {
    $tournament = Tournament::factory()->create(['created_by_id' => organizer()->id, 'starts_at' => CarbonImmutable::parse('2026-11-20 19:00', 'UTC')]);
    $editor = app(TournamentEditor::class);
    $until = CarbonImmutable::parse('2026-11-18 19:00', 'UTC');
    $try = fn (array $changes) => fn () => $editor->update($tournament->refresh(), $tournament->creator, $changes);

    expect($try(['start_options' => ['2026-11-20 19:00'], 'start_vote_until' => $until]))
        ->toThrow(TournamentRuleViolation::class, __('Offer two to four different starts for the vote.'))
        ->and($try(['start_options' => ['2026-11-20 19:00', '2026-11-21 19:00', '2026-11-22 19:00', '2026-11-23 19:00', '2026-11-24 19:00'], 'start_vote_until' => $until]))
        ->toThrow(TournamentRuleViolation::class, __('Offer two to four different starts for the vote.'))
        ->and($try(['starts_at' => CarbonImmutable::parse('2026-11-08 19:00', 'UTC'), 'start_options' => ['2026-11-08 19:00', '2026-11-21 19:00'], 'start_vote_until' => $until]))
        ->toThrow(TournamentRuleViolation::class)
        ->and($try(['start_options' => ['2026-11-20 19:00', '2026-11-21 19:00'], 'start_vote_until' => CarbonImmutable::parse('2026-11-09 11:00', 'UTC')]))
        ->toThrow(TournamentRuleViolation::class, __('The vote has to close in the future.'))
        // In a draft the vote closes an hour before the earliest start at the latest.
        ->and($try(['start_options' => ['2026-11-20 19:00', '2026-11-21 19:00'], 'start_vote_until' => CarbonImmutable::parse('2026-11-20 18:30', 'UTC')]))
        ->toThrow(TournamentRuleViolation::class, __('The vote has to close at least an hour before sign-up closes, so whoever cannot make the chosen start can still pull out.'))
        // The start is the earliest offered start.
        ->and($try(['start_options' => ['2026-11-19 19:00', '2026-11-20 19:00'], 'start_vote_until' => $until]))
        ->toThrow(TournamentRuleViolation::class, __('While the start is put to a vote, the start is the earliest start you offer.'))
        ->and($tournament->refresh()->start_options)->toBeNull();

    // Correspondence chess and a tournament on site have no vote; the edit page does not offer it there.
    $daily = Tournament::factory()->create(['created_by_id' => organizer()->id, 'mode' => 'correspondence', 'starts_at' => CarbonImmutable::parse('2026-11-20 19:00', 'UTC')]);

    expect(fn () => $editor->update($daily, $daily->creator, ['start_options' => ['2026-11-20 19:00', '2026-11-21 19:00'], 'start_vote_until' => $until]))
        ->toThrow(TournamentRuleViolation::class, __('Only an online tournament of a game played on one evening can put its start to a vote.'));
    Livewire::actingAs($daily->creator)->test('pages::admin.tournament-edit', ['tournament' => $daily])
        ->assertDontSee(__('Let the entries vote on the start'));
});

test('publishing checks the vote against the sign-up close; afterwards the close keeps its hour', function () {
    $tournament = votingDraft();
    $publisher = app(TournamentPublisher::class);

    expect(fn () => $publisher->publish($tournament, $tournament->creator, CarbonImmutable::parse('2026-11-18 19:30', 'UTC')))
        ->toThrow(TournamentRuleViolation::class, __('The vote has to close at least an hour before sign-up closes, so whoever cannot make the chosen start can still pull out.'))
        ->and($tournament->refresh()->status)->toBe(TournamentStatus::Draft);

    $publisher->publish($tournament, $tournament->creator, CarbonImmutable::parse('2026-11-19 19:00', 'UTC'));

    expect(fn () => app(TournamentEditor::class)->update($tournament->refresh(), $tournament->creator, ['signup_closes_at' => CarbonImmutable::parse('2026-11-18 19:30', 'UTC')]))
        ->toThrow(TournamentRuleViolation::class, __('The vote has to close at least an hour before sign-up closes, so whoever cannot make the chosen start can still pull out.'));
});

test('an entry ticks the starts it can make; nobody else votes, and only while the vote is open', function () {
    $tournament = votingTournament();
    [$ana, $anaEntry] = voter($tournament, 'Ana');

    Livewire::actingAs($ana)->test('tournament-start-vote', ['tournament' => $tournament->id])
        ->assertSee(__('When can you play?'))
        ->assertSeeHtml('data-state="open"')
        ->call('toggle', '2026-11-21 18:00')
        ->call('toggle', '2026-11-22 16:00')
        ->call('toggle', '2026-11-22 16:00')
        ->assertSet('error', '')
        ->assertSee(trans_choice(':voted of :count entry has voted.|:voted of :count entries have voted.', 1, ['voted' => 1]));

    expect($anaEntry->refresh()->start_fits)->toBe(['2026-11-21 18:00'])
        ->and(TournamentStartVote::tally($tournament)['counts'])->toBe(['2026-11-20 19:00' => 0, '2026-11-21 18:00' => 1, '2026-11-22 16:00' => 0]);

    Livewire::actingAs($ana)->test('tournament-start-vote', ['tournament' => $tournament->id])
        ->call('toggle', '2026-11-23 18:00')
        ->assertSet('error', __('This start is not offered (any more). Reload the page.'));

    // A spectator sees the starts and the counts, without a tick to set.
    Livewire::actingAs(User::factory()->create())->test('tournament-start-vote', ['tournament' => $tournament->id])
        ->assertSee(__('The start is put to a vote'))
        ->assertDontSeeHtml('data-test="start-vote-tick"')
        ->call('toggle', '2026-11-20 19:00')
        ->assertSet('error', __('Only entries vote on the start: the player of a solo entry, the captain for a lineup.'));

    // A withdrawn entry's voice drops.
    $signups = app(TournamentSignups::class);
    [$bob, $bobEntry] = voter($tournament, 'Bob');
    app(TournamentStartVote::class)->toggle($tournament, $bob, '2026-11-20 19:00');
    expect(TournamentStartVote::tally($tournament)['counts']['2026-11-20 19:00'])->toBe(1);
    $bobEntry->forceFill(['withdrawn_at' => now()])->save();
    expect(TournamentStartVote::tally($tournament)['counts']['2026-11-20 19:00'])->toBe(0)
        ->and($signups->entryOf($tournament, $bob))->toBeNull();

    $this->travelTo(CarbonImmutable::parse('2026-11-18 19:00', 'UTC'));

    Livewire::actingAs($ana)->test('tournament-start-vote', ['tournament' => $tournament->id])
        ->assertSeeHtml('data-state="closing"')
        ->call('toggle', '2026-11-20 19:00')
        ->assertSet('error', __('The vote on the start has closed.'));
});

test('the start most entries can make wins at the close: set, published, logged and told, once', function () {
    $tournament = votingTournament();
    $versions = fn (): int => NostrEvent::query()->where('kind', Tournament::CALENDAR_EVENT)->where('d', $tournament->slug)->count();
    [$ana] = voter($tournament, 'Ana');
    [$bob] = voter($tournament, 'Bob');
    [$cleo] = voter($tournament, 'Cleo');
    [$dan] = voter($tournament, 'Dan');
    [$eve] = voter($tournament, 'Eve');
    $vote = app(TournamentStartVote::class);

    // Friday: Ana, and Eve, who never votes (she signed up for the earliest start); Saturday: Bob, Cleo and Dan;
    // Sunday: Cleo. Saturday wins with three to two.
    $vote->toggle($tournament, $ana, '2026-11-20 19:00');
    $vote->toggle($tournament, $bob, '2026-11-21 18:00');
    $vote->toggle($tournament, $cleo, '2026-11-21 18:00');
    $vote->toggle($tournament, $cleo, '2026-11-22 16:00');
    $vote->toggle($tournament, $dan, '2026-11-21 18:00');

    $this->travelTo(CarbonImmutable::parse('2026-11-18 18:59', 'UTC'));
    expect($vote->settleDue())->toBe(0);

    // The tournament clock settles it before it closes sign-ups (which stay open until Thursday).
    $this->travelTo(CarbonImmutable::parse('2026-11-18 19:00', 'UTC'));
    $done = app(TournamentDraws::class)->advanceDue();
    $tournament->refresh();
    $start = collect($tournament->event->payload()['tags'])->firstWhere(0, 'start');

    expect(TournamentStartVote::key($tournament->starts_at))->toBe('2026-11-21 18:00')
        ->and($tournament->start_decided_at)->not->toBeNull()
        ->and($tournament->hasOpenStartVote())->toBeFalse()
        ->and($tournament->status)->toBe(TournamentStatus::Signup)
        ->and($done['closed'])->toBe(0)
        // A change of time is a new version of the same address (NIP "Tournaments").
        ->and($versions())->toBe(2)
        ->and($start[1])->toBe((string) CarbonImmutable::parse('2026-11-21 18:00', 'UTC')->getTimestamp());

    $log = TournamentModerationEntry::query()->where('action', 'start_voted')->sole();

    expect($log->user_name)->toBe('League')
        ->and($log->reason)->toBe(TournamentStartVote::SETTLED)
        ->and($log->details['starts_at'])->toBe(['2026-11-20 19:00', '2026-11-21 18:00'])
        ->and($log->details['start_votes'][1])->toBe(['2026-11-20 19:00 UTC: 2', '2026-11-21 18:00 UTC: 3', '2026-11-22 16:00 UTC: 1']);

    // Every player hears the real count. Ana did not tick Saturday and Eve never voted (her start moved without her yes):
    // both hear they can still withdraw (the sign-up page).
    $told = trans_choice('The vote on the start is over: :count of :entries entries can make this start.|The vote on the start is over: :count of :entries entries can make this start.', 3, ['count' => 3, 'entries' => 5]);

    foreach ([$ana, $eve] as $player) {
        $notice = $player->notifications()->sole();

        expect($notice->data['kind'])->toBe(NotificationKind::TournamentNews->value)
            ->and($notice->data['url'])->toBe(route('tournaments.signup', $tournament))
            ->and($notice->data['body'])->toStartWith($told.' ')->toContain('withdraw it by');
    }

    foreach ([$bob, $cleo, $dan] as $player) {
        expect($player->notifications()->sole()->data['body'])->toBe($told)
            ->and($player->notifications()->sole()->data['url'])->toBe(route('tournaments.show', $tournament));
    }

    // Once: a second tick changes nothing, and the vote is locked now.
    expect($vote->settleDue())->toBe(0)
        ->and($versions())->toBe(2)
        ->and(fn () => app(TournamentEditor::class)->update($tournament->refresh(), $tournament->creator, ['start_vote_until' => CarbonImmutable::parse('2026-11-18 20:00', 'UTC')]))
        ->toThrow(TournamentRuleViolation::class, __('The vote has decided the start already. Change it under Starts if you have to.'));

    Livewire::actingAs($ana)->test('tournament-start-vote', ['tournament' => $tournament->id])
        ->assertSeeHtml('data-test="start-vote-settled"')
        // The settled line names the start it set and its real count.
        ->assertSee(trans_choice('The vote set :time: :count entry can make it.|The vote set :time: :count entries can make it.', 3, ['time' => LeagueTime::stamp($tournament->starts_at)]))
        ->assertSeeHtml('data-test="start-vote-cannot"');
    Livewire::actingAs($eve)->test('tournament-start-vote', ['tournament' => $tournament->id])
        ->assertSeeHtml('data-test="start-vote-cannot"');
    Livewire::actingAs($bob)->test('tournament-start-vote', ['tournament' => $tournament->id])
        ->assertDontSeeHtml('data-test="start-vote-cannot"');
});

test('a tie goes to the earlier start, and without a vote the earliest stays', function () {
    $tie = votingTournament();
    [$ana] = voter($tie, 'Ana');
    [$bob] = voter($tie, 'Bob');
    $vote = app(TournamentStartVote::class);
    $vote->toggle($tie, $ana, '2026-11-22 16:00');
    $vote->toggle($tie, $bob, '2026-11-21 18:00');

    $quiet = votingTournament();
    voter($quiet, 'Cleo');

    $this->travelTo(CarbonImmutable::parse('2026-11-18 19:05', 'UTC'));

    expect($vote->settleDue())->toBe(2)
        ->and(TournamentStartVote::key($tie->refresh()->starts_at))->toBe('2026-11-21 18:00')
        ->and(TournamentStartVote::key($quiet->refresh()->starts_at))->toBe('2026-11-20 19:00')
        ->and($quiet->start_decided_at)->not->toBeNull()
        // The start did not move: no new version of the calendar event.
        ->and(NostrEvent::query()->where('kind', Tournament::CALENDAR_EVENT)->where('d', $quiet->slug)->count())->toBe(1);
});

test('the tournament page shows the offered starts instead of one start while the vote is open', function () {
    $tournament = votingTournament();
    [$ana] = voter($tournament, 'Ana');

    $this->actingAs($ana)->get(route('tournaments.show', $tournament))->assertOk()
        ->assertSeeHtml('data-test="start-vote"')
        ->assertSee(__('start by vote'))
        ->assertSeeHtml('data-test="when-vote-options"')
        ->assertDontSeeHtml('data-test="hero-open-end"')
        ->assertSee(explode(':time', __('by vote, at the earliest :time'))[0]);

    // A tournament without a vote is unchanged.
    $plain = openTournament();

    $this->get(route('tournaments.show', $plain))->assertOk()
        ->assertDontSeeHtml('data-test="start-vote"')
        ->assertDontSeeHtml('data-test="when-vote-options"');
});

test('an entry that does not vote counts for the earliest start: one of eight voting later only does not move it', function () {
    $tournament = votingTournament();
    $vote = app(TournamentStartVote::class);
    $entries = collect(range(1, 8))->map(fn (int $n): array => voter($tournament, 'Player '.$n));
    [$later] = $entries->first();
    // Ticked and unticked again: no tick left, so it counts like the seven that never voted (start_fits = []).
    [$undecided, $undecidedEntry] = $entries->get(1);
    $vote->toggle($tournament, $later, '2026-11-22 16:00');
    $vote->toggle($tournament, $undecided, '2026-11-21 18:00');
    $vote->toggle($tournament, $undecided, '2026-11-21 18:00');

    expect($undecidedEntry->refresh()->start_fits)->toBe([])
        ->and(TournamentStartVote::tally($tournament))->toBe(['counts' => ['2026-11-20 19:00' => 7, '2026-11-21 18:00' => 0, '2026-11-22 16:00' => 1], 'voted' => 1, 'entries' => 8]);

    $this->travelTo(CarbonImmutable::parse('2026-11-18 19:00', 'UTC'));
    expect($vote->settleDue())->toBe(1)
        ->and(TournamentStartVote::key($tournament->refresh()->starts_at))->toBe('2026-11-20 19:00');

    // The real count, never "most"; only the one entry whose ticks miss Friday hears it can withdraw.
    $told = trans_choice('The vote on the start is over: :count of :entries entries can make this start.|The vote on the start is over: :count of :entries entries can make this start.', 7, ['count' => 7, 'entries' => 8]);

    expect($later->notifications()->sole()->data['body'])->toStartWith($told.' ')->toContain('withdraw it by');

    foreach ($entries->skip(1) as [$player]) {
        expect($player->notifications()->sole()->data['body'])->toBe($told);
    }
});

test('a vote still open when sign-up closes is settled before the close, without a withdraw hint', function () {
    Http::fake(fn ($request) => str_ends_with($request->url(), '/blocks/tip/height') ? Http::response('900000') : Http::response('', 404));
    $tournament = votingTournament();
    [$ana] = voter($tournament, 'Ana');
    [$bob] = voter($tournament, 'Bob');
    $vote = app(TournamentStartVote::class);
    $vote->toggle($tournament, $ana, '2026-11-21 18:00');
    $vote->toggle($tournament, $bob, '2026-11-21 18:00');

    // The tick never settled it (the scheduler was down): sign-up closes on Thursday, the vote is still open.
    $this->travelTo(CarbonImmutable::parse('2026-11-19 19:00', 'UTC'));
    expect($tournament->refresh()->hasOpenStartVote())->toBeTrue()
        ->and(app(TournamentDraws::class)->close($tournament))->toBeTrue();

    $tournament->refresh();

    expect($tournament->start_decided_at)->not->toBeNull()
        ->and(TournamentStartVote::key($tournament->starts_at))->toBe('2026-11-21 18:00')
        ->and($tournament->status)->toBe(TournamentStatus::Drawing);

    // Nobody can withdraw any more: the notice names the start and the count, nothing else.
    foreach ([$ana, $bob] as $player) {
        expect($player->notifications()->sole()->data['body'])->not->toContain('withdraw')
            ->and($player->notifications()->sole()->data['url'])->toBe(route('tournaments.show', $tournament));
    }
});

test('a lineup votes through its captain; a member who is not the captain is refused', function () {
    $tournament = Tournament::factory()->rocketLeague()->create(['created_by_id' => organizer()->id, 'starts_at' => CarbonImmutable::parse('2026-11-20 19:00', 'UTC')]);
    app(TournamentEditor::class)->update($tournament, $tournament->creator, [
        'start_options' => ['2026-11-20 19:00', '2026-11-21 18:00'],
        'start_vote_until' => CarbonImmutable::parse('2026-11-18 19:00', 'UTC'),
    ]);
    $tournament = app(TournamentPublisher::class)->publish($tournament->refresh(), $tournament->creator, CarbonImmutable::parse('2026-11-19 19:00', 'UTC'));
    [$lineup, $captain, $signer] = keyedLineup();
    $entry = lineupSignup($tournament, $lineup, $captain, $signer);
    $member = collect($lineup->activeSeats())->map(fn ($seat) => $seat->user)->first(fn (User $user): bool => $user->id !== $captain->id);
    $vote = app(TournamentStartVote::class);

    expect($vote->toggle($tournament, $captain, '2026-11-21 18:00'))->toBe(['2026-11-21 18:00'])
        ->and($entry->refresh()->start_fits)->toBe(['2026-11-21 18:00'])
        ->and(fn () => $vote->toggle($tournament, $member, '2026-11-20 19:00'))
        ->toThrow(TournamentRuleViolation::class, __('Only entries vote on the start: the player of a solo entry, the captain for a lineup.'))
        ->and($entry->refresh()->start_fits)->toBe(['2026-11-21 18:00']);
});

test('settling twice, the second time on a stale model, logs once and tells each player once', function () {
    $tournament = votingTournament();
    [$ana] = voter($tournament, 'Ana');
    $stale = Tournament::query()->findOrFail($tournament->id);
    $vote = app(TournamentStartVote::class);

    $this->travelTo(CarbonImmutable::parse('2026-11-18 19:00', 'UTC'));

    expect($vote->settle($tournament))->toBeTrue()
        // The stale copy still says the vote is open; the locked row does not.
        ->and($stale->hasOpenStartVote())->toBeTrue()
        ->and($vote->settle($stale))->toBeFalse()
        ->and(TournamentModerationEntry::query()->where('action', 'start_voted')->count())->toBe(1)
        ->and($ana->notifications()->count())->toBe(1);
});

test('no tick once sign-up has closed or the tournament was called off; neither says its start is put to a vote', function () {
    $closed = votingTournament();
    [$ana] = voter($closed, 'Ana');
    $cancelled = votingTournament();
    [$bob] = voter($cancelled, 'Bob');
    $vote = app(TournamentStartVote::class);

    $closed->forceFill(['status' => TournamentStatus::Drawing])->save();
    $cancelled->forceFill(['status' => TournamentStatus::Cancelled])->save();

    expect(fn () => $vote->toggle($closed, $ana, '2026-11-21 18:00'))->toThrow(TournamentRuleViolation::class, __('The vote on the start has closed.'))
        ->and(fn () => $vote->toggle($cancelled, $bob, '2026-11-21 18:00'))->toThrow(TournamentRuleViolation::class, __('The vote on the start has closed.'))
        ->and($closed->hasOpenStartVote())->toBeFalse()
        ->and($cancelled->hasOpenStartVote())->toBeFalse();

    $this->get(route('tournaments.show', $cancelled))->assertOk()
        ->assertDontSee(__('start by vote'))
        ->assertDontSeeHtml('data-test="when-vote-options"')
        ->assertDontSeeHtml('data-test="start-vote"');
});

test('the tick keeps keyboard focus while it saves: aria-disabled, never disabled', function () {
    $tournament = votingTournament();
    [$ana] = voter($tournament, 'Ana');

    Livewire::actingAs($ana)->test('tournament-start-vote', ['tournament' => $tournament->id])
        ->assertSeeHtml('wire:loading.attr="aria-disabled"')
        ->assertSeeHtml('wire:loading.class="pointer-events-none"')
        ->assertDontSeeHtml('wire:loading.attr="disabled"');
});
