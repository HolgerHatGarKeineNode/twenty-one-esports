<?php

use App\Enums\Platform;
use App\Enums\ReportStatus;
use App\Enums\SeriesStatus;
use App\Enums\TournamentFormat;
use App\Enums\TournamentStatus;
use App\Games\GameRegistry;
use App\Models\Clan;
use App\Models\Lineup;
use App\Models\SeriesMatch;
use App\Models\SeriesReport;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentParticipant;
use App\Models\TournamentSignup;
use App\Models\User;
use App\Support\Dock\DockItem;
use App\Support\Dock\OpenMatches;
use App\Support\Dock\UpcomingEvents;
use App\Support\Series\CasualChallenges;
use App\Support\Series\CasualMatches;
use App\Support\Tournaments\TournamentRunner;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\TestSigner;

/** The card variant of <livewire:upcoming-events> as `$user` sees it (the start page carried it until the revamp). */
function upcomingCard(User $user): string
{
    return Livewire::actingAs($user)->test('upcoming-events', ['variant' => 'card'])->html();
}

/*
 * A player's upcoming events (2026-10-02): open match rooms and registered
 * tournaments, on the match dock, home's "Your next match" card, the top of
 * /matches, /tournaments and the game page, and the count on the account
 * menu. App\Support\Dock\UpcomingEvents is the definition.
 */

beforeEach(function () {
    Queue::fake();
    config(['esports.league.nsec' => (new TestSigner)->secret]);
    // A Wednesday noon in Berlin, so "later today" and "tomorrow" are unambiguous.
    $this->travelTo(now()->setTimezone('Europe/Berlin')->setDate(2026, 10, 7)->setTime(12, 0)->utc());
});

/** A clan owner (captain) with a ready lineup. */
function upcomingCaptain(): array
{
    $me = User::factory()->create(['locale' => 'en']);
    $lineup = Lineup::factory()->ready()->create(['clan_id' => Clan::factory()->create(['owner_id' => $me->id])->id]);

    return [$me, $lineup->load('seats')];
}

/**
 * @param  array<string, mixed>  $attributes
 */
function upcomingSeries(Lineup $mine, array $attributes): SeriesMatch
{
    return SeriesMatch::factory()->create([
        'challenger_lineup_id' => $mine->id,
        'challenged_lineup_id' => Lineup::factory()->ready()->create()->id,
        ...$attributes,
    ]);
}

/** A scheduled casual 1v1 at `$at`, accepted. */
function upcomingCasual(User $anna, User $bert, DateTimeInterface $at): SeriesMatch
{
    $match = app(CasualChallenges::class)->challenge($anna, $bert, 'rocket-league', Platform::Pc, true, [$at->getTimestamp()], min(now()->addHours(2)->getTimestamp(), $at->getTimestamp() - 60), '');

    return app(CasualChallenges::class)->accept($match, $bert, $at->getTimestamp(), Platform::Pc, true)->refresh();
}

/**
 * A solo sign-up for a published chess tournament starting at `$start`;
 * sign-up closes a day from now, or at the start when that is sooner.
 */
function upcomingTournament(User $user, DateTimeInterface $start): Tournament
{
    $tournament = openTournament(['starts_at' => $start > now()->addDay() ? $start : now()->addDays(2)]);
    $tournament->forceFill(['starts_at' => $start, 'signup_closes_at' => min($start, $tournament->signup_closes_at)])->save();
    TournamentSignup::query()->create(['tournament_id' => $tournament->id, 'user_id' => $user->id, 'name' => $user->displayName(), 'members' => [$user->id]]);

    return $tournament->refresh();
}

/**
 * @param  Collection<int, DockItem>  $items
 * @return list<string>
 */
function upcomingKeys($items): array
{
    return $items->map(fn (DockItem $item): string => $item->key)->values()->all();
}

test('an open room is an undecided series the viewer plays or captains, casual or between lineups, and nobody else\'s', function () {
    [$me, $lineup] = upcomingCaptain();
    $later = upcomingSeries($lineup, ['status' => SeriesStatus::Accepted, 'start_at' => now()->addDays(3)]);
    $live = upcomingSeries($lineup, ['status' => SeriesStatus::Accepted, 'start_at' => now()->subMinutes(20)]);
    $reported = upcomingSeries($lineup, ['status' => SeriesStatus::Reported, 'start_at' => now()->subHour()]);
    SeriesReport::query()->create(['series_match_id' => $reported->id, 'side' => 'challenger', 'games' => [['winner' => 'challenger', 'challenger' => 3, 'challenged' => 1]], 'roster' => [], 'status' => ReportStatus::Open]);
    $disputed = upcomingSeries($lineup, ['status' => SeriesStatus::Disputed, 'start_at' => now()->subHour()]);

    // Not a room: a challenge nobody accepted yet, and every decided or dead series.
    upcomingSeries($lineup, ['status' => SeriesStatus::Open]);
    foreach ([SeriesStatus::Confirmed, SeriesStatus::Resolved, SeriesStatus::Expired, SeriesStatus::Declined, SeriesStatus::Withdrawn] as $status) {
        upcomingSeries($lineup, ['status' => $status, 'start_at' => now()->subDay()]);
    }

    // A casual 1v1 of roster sides, tomorrow evening: a room for both players.
    $friend = User::factory()->create();
    $casual = upcomingCasual($me, $friend, now()->addDay()->setTime(18, 0));

    $rooms = app(OpenMatches::class)->rooms($me);
    $seated = $lineup->load('seats.user')->seats->first(fn ($seat) => $seat->user_id !== $me->id)->user;
    $stranger = User::factory()->create();

    expect(upcomingKeys($rooms))->toEqualCanonicalizing(['series-'.$later->number, 'series-'.$live->number, 'series-'.$reported->number, 'series-'.$disputed->number, 'series-'.$casual->number])
        // A seated player of the lineup has the lineup's rooms, not the captain's casual 1v1.
        ->and(upcomingKeys(app(OpenMatches::class)->rooms($seated)))->toEqualCanonicalizing(['series-'.$later->number, 'series-'.$live->number, 'series-'.$reported->number, 'series-'.$disputed->number])
        ->and(upcomingKeys(app(OpenMatches::class)->rooms($friend)))->toBe(['series-'.$casual->number])
        ->and(app(OpenMatches::class)->rooms($stranger))->toBeEmpty();
});

test('every open room is on the match dock with a countdown, a far one waiting at the end', function () {
    [$me, $lineup] = upcomingCaptain();
    $live = upcomingSeries($lineup, ['status' => SeriesStatus::Accepted, 'start_at' => now()->subMinutes(20)]);
    $soon = upcomingSeries($lineup, ['status' => SeriesStatus::Accepted, 'start_at' => now()->addMinutes(30)]);
    $later = upcomingSeries($lineup, ['status' => SeriesStatus::Accepted, 'start_at' => now()->addDays(3)]);

    $items = app(OpenMatches::class)->for($me)->keyBy('key');
    $far = $items['series-'.$later->number];

    expect($items->keys()->all())->toBe(['series-'.$live->number, 'series-'.$soon->number, 'series-'.$later->number])
        ->and([$far->group, $far->phase, $far->needsYou, $far->state, $far->trailing])->toBe(['wait', 'scheduled', false, 'Starts', '72 h 00'])
        ->and($far->tick['endsAt'])->toBe((int) $later->start_at->getTimestampMs())
        ->and([$items['series-'.$soon->number]->phase, $items['series-'.$soon->number]->trailing, $items['series-'.$soon->number]->tick['endsAt']])
        ->toBe(['starts', '30 min', (int) $soon->start_at->getTimestampMs()]);

    Livewire::actingAs($me)->test('match-dock')
        ->assertSeeHtml('data-dock-tab="series-'.$later->number.'"')
        ->assertSeeHtml('data-dock-tab="series-'.$live->number.'"');
});

test('a scheduled casual 1v1 counts down to its check-in, asks to check in now inside the window, then wants the result', function () {
    [$anna, $bert] = User::factory()->count(2)->create();
    $match = upcomingCasual($anna, $bert, now()->addDay()->setTime(20, 0));
    $opens = $match->checkInOpensAt();
    $dock = app(OpenMatches::class);

    $item = $dock->for($anna)->sole();
    expect([$item->group, $item->phase, $item->state, $item->needsYou, $item->tick['endsAt']])->toBe(['wait', 'scheduled', 'Check-in', false, (int) $opens->getTimestampMs()]);

    $this->travelTo($opens->copy()->subMinutes(30));
    expect($dock->for($anna)->sole()->group)->toBe('need');

    $this->travelTo($opens->copy()->addMinute());
    $item = $dock->for($anna)->sole();
    expect([$item->group, $item->phase, $item->state, $item->needsYou, $item->tick['endsAt']])->toBe(['live', 'checkin', 'Check in now', true, (int) $match->ready_by->getTimestampMs()]);

    app(CasualMatches::class)->checkIn($match->refresh(), $anna);
    expect([$dock->for($anna)->sole()->state, $dock->for($anna)->sole()->needsYou])->toBe(['Checked in', false]);

    $match = app(CasualMatches::class)->checkIn($match->refresh(), $bert);
    $match->forceFill(['lobby_shared_at' => now(), 'joined_at' => now()])->save();
    expect($dock->for($anna)->sole()->state)->toBe('Report result');
});

test('rooms and tournaments come in urgency order: check in now, live, starting soon, today, then later by start', function () {
    [$me, $lineup] = upcomingCaptain();
    $friend = User::factory()->create();
    $later = upcomingSeries($lineup, ['status' => SeriesStatus::Accepted, 'start_at' => now()->addDays(3)]);
    $live = upcomingSeries($lineup, ['status' => SeriesStatus::Accepted, 'start_at' => now()->subMinutes(20)]);
    $soon = upcomingSeries($lineup, ['status' => SeriesStatus::Accepted, 'start_at' => now()->addMinutes(40)]);
    $tonight = upcomingTournament($me, now()->setTime(20, 0));
    $nextWeek = upcomingTournament($me, now()->addWeek());
    $checkin = upcomingCasual($me, $friend, now()->addMinutes(5));

    expect(upcomingKeys(app(UpcomingEvents::class)->for($me)))->toBe([
        'series-'.$checkin->number,
        'series-'.$live->number,
        'series-'.$soon->number,
        'tournament-'.$tonight->id,
        'series-'.$later->number,
        'tournament-'.$nextWeek->id,
    ])
        // The dock takes the same order, with the tournament of the day only.
        ->and(upcomingKeys(app(OpenMatches::class)->for($me)))->toBe([
            'series-'.$checkin->number,
            'series-'.$live->number,
            'series-'.$soon->number,
            'tournament-'.$tonight->id,
            'series-'.$later->number,
        ]);
});

test('a tournament on its day is on the dock and counts down to its start; an earlier one waits in the list with the way out', function () {
    $me = User::factory()->create(['locale' => 'en']);
    $tonight = upcomingTournament($me, now()->setTime(20, 0));
    $nextWeek = upcomingTournament($me, now()->addWeek());

    $events = app(UpcomingEvents::class)->for($me)->keyBy('key');
    $today = $events['tournament-'.$tonight->id];
    $later = $events['tournament-'.$nextWeek->id];

    expect([$today->group, $today->phase, $today->needsYou, $today->tick['endsAt']])->toBe(['need', 'scheduled', false, (int) $tonight->starts_at->getTimestampMs()])
        ->and([$later->group, $later->phase, $later->needsYou])->toBe(['wait', 'scheduled', false])
        ->and($later->href)->toBe(route('tournaments.show', $nextWeek))
        ->and(upcomingKeys(app(OpenMatches::class)->for($me)))->toBe(['tournament-'.$tonight->id]);

    // In its last hour it is on the player.
    $this->travelTo($tonight->starts_at->copy()->subMinutes(30));
    expect(app(UpcomingEvents::class)->tournaments($me, todayOnly: true)->sole()->needsYou)->toBeTrue();
});

test('the way to pull out is there while sign-up is open and the player may, and gone after the deadline', function () {
    $me = User::factory()->create(['locale' => 'en']);
    $tournament = upcomingTournament($me, now()->addWeek());

    $item = app(UpcomingEvents::class)->tournaments($me)->sole();
    expect($item->withdraw)->toBe(route('tournaments.signup', $tournament).'#withdraw');

    expect(upcomingCard($me))->toContain('data-test="upcoming-withdraw"')
        ->toContain(e(route('tournaments.signup', $tournament).'#withdraw'));

    // Sign-up closed: still registered and listed, no way out any more.
    $tournament->forceFill(['signup_closes_at' => now()->subMinute()])->save();
    expect(app(UpcomingEvents::class)->tournaments($me)->sole()->withdraw)->toBeNull();

    expect(upcomingCard($me))->toContain('data-test="upcoming-card"')
        ->not->toContain('data-test="upcoming-withdraw"');
});

test('a lineup\'s tournament offers the way out to its captain only', function () {
    [$captain, $lineup] = upcomingCaptain();
    $tournament = openTournament(['starts_at' => now()->addWeek()], rocketLeague: true);
    $members = $lineup->seats->pluck('user_id')->all();
    TournamentSignup::query()->create(['tournament_id' => $tournament->id, 'lineup_id' => $lineup->id, 'name' => 'L', 'members' => $members]);
    $player = User::query()->find(collect($members)->first(fn (int $id): bool => $id !== $captain->id));

    expect(app(UpcomingEvents::class)->tournaments($captain)->sole()->withdraw)->not->toBeNull()
        ->and(app(UpcomingEvents::class)->tournaments($player)->sole()->withdraw)->toBeNull();
});

test('a tournament leaves the list once finished, called off, pulled out of, or the player is knocked out', function () {
    $me = User::factory()->create();
    $withdrawn = upcomingTournament($me, now()->addDays(2));
    TournamentSignup::query()->where('tournament_id', $withdrawn->id)->update(['withdrawn_at' => now()]);
    $finished = upcomingTournament($me, now()->subDay());
    $finished->forceFill(['status' => TournamentStatus::Finished])->save();
    $cancelled = upcomingTournament($me, now()->addDays(2));
    $cancelled->forceFill(['status' => TournamentStatus::Cancelled])->save();

    expect(app(UpcomingEvents::class)->tournaments($me))->toBeEmpty();

    // A running single elimination: the loser of round 1 is out, the winner stays.
    $tournament = runningChess(TournamentFormat::SingleElimination, 4);
    $match = TournamentMatch::query()->where('tournament_id', $tournament->id)->where('status', 'ready')->with('slots.participant')->firstOrFail();
    [$winner, $loser] = matchPlayers($match);
    foreach ([$winner, $loser] as $player) {
        $participant = TournamentParticipant::query()->where('tournament_id', $tournament->id)->where('user_id', $player->id)->firstOrFail();
        $signup = TournamentSignup::query()->create(['tournament_id' => $tournament->id, 'user_id' => $player->id, 'name' => 'x', 'members' => [$player->id]]);
        $participant->forceFill(['tournament_signup_id' => $signup->id])->save();
    }

    expect(app(UpcomingEvents::class)->tournaments($loser)->sole()->phase)->toBe('live');

    app(TournamentRunner::class)->enterResult($match, $tournament->creator, ['result' => '1-0']);

    expect(app(UpcomingEvents::class)->tournaments($loser))->toBeEmpty()
        ->and(upcomingKeys(app(UpcomingEvents::class)->tournaments($winner)))->toBe(['tournament-'.$tournament->id]);
});

test('whether the player is still in costs the same queries for one running tournament as for five', function () {
    $me = User::factory()->create();
    // In each running single elimination $me takes over a seat of round 1; the draw has ready matches for every seat.
    $enter = function () use ($me): Tournament {
        $tournament = runningChess(TournamentFormat::SingleElimination, 4);
        $participant = TournamentParticipant::query()->where('tournament_id', $tournament->id)->orderBy('id')->firstOrFail();
        $signup = TournamentSignup::query()->create(['tournament_id' => $tournament->id, 'user_id' => $me->id, 'name' => 'x', 'members' => [$me->id]]);
        $participant->forceFill(['user_id' => $me->id, 'tournament_signup_id' => $signup->id])->save();

        return $tournament;
    };
    $count = function () use ($me): array {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $keys = upcomingKeys(app(UpcomingEvents::class)->tournaments($me));
        DB::disableQueryLog();

        return [count(DB::getQueryLog()), count($keys)];
    };

    $enter();
    [$one, $listedOne] = $count();
    $tournaments = collect(range(2, 5))->map(fn () => $enter());
    [$five, $listedFive] = $count();

    // Out, each in its own way: disqualified, not drawn in (no participant row), no match left in the bracket.
    TournamentParticipant::query()->where('tournament_id', $tournaments[0]->id)->where('user_id', $me->id)->update(['disqualified_at' => now()]);
    TournamentParticipant::query()->where('tournament_id', $tournaments[1]->id)->where('user_id', $me->id)->update(['tournament_signup_id' => null]);
    TournamentMatch::query()->where('tournament_id', $tournaments[2]->id)->update(['status' => 'done']);

    expect([$listedOne, $listedFive])->toBe([1, 5])
        ->and($five)->toBe($one)
        ->and(upcomingKeys(app(UpcomingEvents::class)->tournaments($me)))->toHaveCount(2);
});

test('home, /matches, /tournaments, the game page of that game and the account menu show a player\'s open room, and nobody else\'s', function () {
    [$me, $lineup] = upcomingCaptain();
    $room = upcomingSeries($lineup, ['status' => SeriesStatus::Accepted, 'start_at' => now()->addDays(2)]);
    $tournament = upcomingTournament($me, now()->addDays(4));
    $game = $room->game;

    // The card: the room first (the sooner), its button at the top.
    $card = upcomingCard($me);
    expect(strpos($card, 'data-test="upcoming-card"'))->toBeLessThan(strpos($card, 'href="'.e(route('matches.room', $room)).'"'))
        ->and($card)->toContain('data-test="upcoming-open"')->toContain('data-test="upcoming-more"');
    $this->actingAs($me);
    $this->get(route('matches.index'))->assertOk()
        ->assertSeeInOrder(['data-test="matches"', 'data-test="upcoming-list"', 'data-test="upcoming-row" data-key="series-'.$room->number.'"', 'data-test="game-filter-select"'], false)
        ->assertDontSee('data-test="upcoming-row" data-key="tournament-', false);
    $this->get(route('tournaments.index'))->assertOk()
        ->assertSee('data-test="upcoming-list"', false)
        ->assertSee('data-test="upcoming-row" data-key="tournament-'.$tournament->id.'"', false);
    $this->get(route('games.series', ['slug' => $game]))->assertOk()
        ->assertSee('data-test="upcoming-row" data-key="series-'.$room->number.'"', false);
    // Another game's page lists its own events only.
    $other = collect(array_keys(app(GameRegistry::class)->series()))->first(fn (string $slug): bool => $slug !== $game);
    $this->get(route('games.series', ['slug' => $other]))->assertOk()
        ->assertDontSee('data-test="upcoming-row" data-key="series-'.$room->number.'"', false);
    $this->get(route('clans.index'))->assertOk()
        ->assertSeeInOrder(['data-test="account-chip"', 'data-test="upcoming-count"', '2'], false)
        ->assertSee('data-test="account-upcoming"', false)
        ->assertSee('data-test="mobile-upcoming"', false);

    // Another player and a guest see none of it.
    $this->actingAs(User::factory()->create());
    foreach ([route('home'), route('matches.index'), route('tournaments.index'), route('games.series', ['slug' => $game])] as $url) {
        $this->get($url)->assertOk()->assertDontSee('data-test="upcoming-', false);
    }
    auth()->logout();
    $this->get(route('home'))->assertOk()->assertDontSee('data-test="upcoming-', false);
});

test('the upcoming card lists every event on the day of the first one, always visible; only later days wait behind "+N more"', function () {
    $me = User::factory()->create(['locale' => 'en', 'timezone' => 'Europe/Berlin']);
    // Wednesday noon in Berlin: two tonight, one tomorrow, one next week.
    $first = upcomingTournament($me, now()->setTime(18, 0));
    $tonight = upcomingTournament($me, now()->setTime(21, 30));
    $tomorrow = upcomingTournament($me, now()->addDay()->setTime(19, 0));
    $nextWeek = upcomingTournament($me, now()->addWeek());

    $html = upcomingCard($me);
    $card = substr($html, strpos($html, 'data-test="upcoming-card"'));
    $card = substr($card, 0, strpos($card, '</section>'));
    $sameDay = substr($card, strpos($card, 'data-test="upcoming-same-day"'));
    $sameDay = substr($sameDay, 0, strpos($sameDay, '</ul>'));
    $later = substr($card, strpos($card, 'id="upcoming-more-list"'));

    expect(substr($card, 0, strpos($card, 'data-test="upcoming-same-day"')))->toContain('data-key="tournament-'.$first->id.'"')
        // The second one tonight: in the always-visible list, not behind the toggle.
        ->and($sameDay)->toContain('data-key="tournament-'.$tonight->id.'"')
        ->and($sameDay)->not->toContain('x-show')
        ->and($sameDay)->not->toContain('data-key="tournament-'.$tomorrow->id.'"')
        // Later days behind "+2 more".
        ->and($card)->toContain('+2 more')
        ->and($later)->toContain('x-show="more"')
        ->and($later)->toContain('data-key="tournament-'.$tomorrow->id.'"')
        ->and($later)->toContain('data-key="tournament-'.$nextWeek->id.'"')
        ->and($later)->not->toContain('data-key="tournament-'.$tonight->id.'"');
});

test('the control: with nothing else on the first event\'s day there is no same-day list, and every other event waits behind the toggle', function () {
    $me = User::factory()->create(['locale' => 'en', 'timezone' => 'Europe/Berlin']);
    upcomingTournament($me, now()->setTime(18, 0));
    $tomorrow = upcomingTournament($me, now()->addDay()->setTime(19, 0));

    $html = upcomingCard($me);

    expect($html)->not->toContain('data-test="upcoming-same-day"')
        ->and($html)->toContain('+1 more')
        ->and(substr($html, strpos($html, 'id="upcoming-more-list"')))->toContain('data-key="tournament-'.$tomorrow->id.'"');
});
