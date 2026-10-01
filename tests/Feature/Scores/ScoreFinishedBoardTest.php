<?php

/*
| Score games after the round-4 audit (plan "AoE2 und Trackmania", P4): a
| finished leaderboard keeps its final standings whatever is decided about an
| account later (F1), a switched-off game's stand-in is logged once a day,
| not per page view (F2), a poll's whole transfer has a hard time and size
| cap (F3), an open board's stakes are read like a running one's (F4), an id
| saved while the game was off still holds the end (F5), and the account
| review pages in the database while strangers' old finishes are pruned
| (F6). The case names are the auditor's probes.
*/

use App\Enums\ClanRole;
use App\Enums\TournamentStatus;
use App\Games\GameRegistry;
use App\Models\Admin;
use App\Models\Clan;
use App\Models\ClanMember;
use App\Models\ScoreRun;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\TournamentSignup;
use App\Models\User;
use App\Support\Scores\ManualSubmissions;
use App\Support\Scores\ScoreAccount;
use App\Support\Scores\ScoreAccounts;
use App\Support\Scores\ScoreCourse;
use App\Support\Scores\ScoreLeaderboards;
use App\Support\Scores\ScoreRuns;
use App\Support\Scores\ScoreServers;
use App\Support\Scores\ScoreSourceUnavailable;
use App\Support\Scores\ScoreWindow;
use App\Support\Tournaments\TournamentRuleViolation;
use Carbon\CarbonImmutable;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use Tests\Support\FixtureScorePoller;
use Tests\Support\ScoreDemoOn;

function finishedAdmin(): User
{
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    return $admin;
}

function finishedStore(User $player, string $id): void
{
    Livewire::actingAs($player)->test('pages::settings.gaming')->set('gamerTags.score-demo', $id)->call('save')->assertHasNoErrors();
    auth()->logout();
}

/**
 * @param  array<int, string>  $names
 * @return list<array{0: string, 1: int|null, 2: int|null}>
 */
function finishedStandings(Tournament $tournament, array $names): array
{
    return array_map(fn ($standing): array => [$names[$standing->participant->user_id], $standing->place, $standing->value], app(ScoreRuns::class)->standings($tournament->refresh()));
}

beforeEach(function () {
    $this->fake = ScoreDemoOn::play();
    $this->travelTo(CarbonImmutable::parse('2026-10-06 12:00:00'));
    $this->game = app(GameRegistry::class)->get('score-demo');
    ['token' => $this->token] = ScoreServers::issue('box', 'score-demo');
    $this->finish = fn (string $id, string $account, int $value) => $this->postJson(route('scores.ingest'), ['events' => [['id' => $id, 'mode' => 'time-trial',
        'course' => 'demo-1', 'account' => $account, 'value' => $value, 'achieved_at' => now()->getTimestamp()]]], ['Authorization' => 'Bearer '.$this->token])->assertOk()->json();
});

test('n1b F1 path A: a confirm after a left-out end maps the finish and leaves the final standings', function () {
    [$tournament, [$owner, $other]] = runningScoreBoard(2);
    $names = [$owner->id => 'owner', $other->id => 'other'];
    $this->fake->record($other->id, 'demo-1', 58_000, now());
    app(ScoreLeaderboards::class)->snapshot($tournament);
    finishedStore($owner, 'acct-legit');
    ($this->finish)('l1', 'acct-legit', 41_000);
    $this->travelTo(ScoreWindow::of($tournament)->end->addHours(24));
    app(ScoreLeaderboards::class)->tick();
    $final = finishedStandings($tournament, $names);

    expect($tournament->refresh()->status)->toBe(TournamentStatus::Finished)
        ->and($final)->toBe([['other', 1, 58_000], ['owner', null, null]]);

    ScoreAccounts::confirm($this->game, 'acct-legit', $owner, finishedAdmin(), 'Owner logged in with the game.');

    expect(finishedStandings($tournament, $names))->toBe($final)
        ->and(ScoreRun::query()->where('account_id', 'acct-legit')->sole()->user_id)->toBe($owner->id);
});

test('n1c F1 path B: a playing admin cannot revoke the winner after the end, and another admin\'s revoke leaves the final standings', function () {
    [$tournament, [$winner, $seat]] = runningScoreBoard(2);
    $playing = finishedAdmin();
    TournamentParticipant::query()->where('user_id', $seat->id)->update(['user_id' => $playing->id, 'members' => [$playing->id]]);
    $names = [$winner->id => 'winner', $playing->id => 'playing_admin'];
    $this->fake->record($playing->id, 'demo-1', 58_000, now());
    app(ScoreLeaderboards::class)->snapshot($tournament);
    finishedStore($winner, 'acct-win');
    ScoreAccounts::confirm($this->game, 'acct-win', $winner, finishedAdmin(), 'Winner showed his account page.');
    ($this->finish)('w1', 'acct-win', 41_000);
    $this->travelTo(ManualSubmissions::closesAt($tournament)->addMinute());
    app(ScoreLeaderboards::class)->finalize($tournament->refresh(), finishedAdmin());
    $final = finishedStandings($tournament, $names);

    expect($final)->toBe([['winner', 1, 41_000], ['playing_admin', 2, 58_000]])
        ->and(fn () => ScoreAccounts::revoke($this->game, 'acct-win', $playing, 'Doubtful.'))->toThrow(TournamentRuleViolation::class);

    ScoreAccounts::revoke($this->game, 'acct-win', finishedAdmin(), 'Shared account after all.');

    expect(finishedStandings($tournament, $names))->toBe($final)
        ->and(ScoreRun::query()->where('account_id', 'acct-win')->sole()->user_id)->toBeNull();
});

test('n6b F2: five guest views of a switched-off tournament log the stand-in once, without a report', function () {
    $off = Tournament::factory()->scoreDemo()->signup()->create(['starts_at' => now()->addDays(14), 'signup_closes_at' => now()->addDays(13), 'published_at' => now()->subDay(), 'name' => 'Off one']);
    config(['esports.score_games.demo' => false]);
    app()->forgetInstance(GameRegistry::class);
    $this->withoutVite();
    Exceptions::fake();
    $lines = 0;
    Event::listen(MessageLogged::class, function (MessageLogged $message) use (&$lines): void {
        $lines++;
    });

    foreach (range(1, 5) as $view) {
        $this->get(route('tournaments.show', $off))->assertOk();
    }

    expect($lines)->toBe(1);
    Exceptions::assertNothingReported();
});

test('F3: a poll is one bounded transfer: total timeout, no streaming, cURL stops past the cap', function () {
    Sleep::fake();
    config(['esports.score_games.poller.min_interval_ms' => 0, 'esports.score_games.poller.retries' => 0, 'esports.score_games.poller.timeout_seconds' => 5,
        'esports.score_games.poller.max_body_bytes' => 10_000]);
    $course = new ScoreCourse($this->game, $this->game->mode('time-trial'), 'demo-1');
    $start = CarbonImmutable::parse('2026-10-05 17:00:00');
    $ask = fn () => app(FixtureScorePoller::class)->bestFor(new ScoreAccount(1, 'acct-1'), $course, $start, $start->addDays(7));
    $seen = [];
    Http::fake(function ($request, array $options) use (&$seen) {
        $seen[] = $options;

        if (count($seen) === 1) {
            return Http::response(['records' => []]);
        }

        throw new ConnectException('cURL error 63: Maximum file size exceeded', $request->toPsrRequest());
    });

    expect($ask())->toBeNull()
        ->and($seen[0]['stream'] ?? false)->toBeFalse()
        ->and($seen[0]['timeout'])->toBe(5)
        ->and($seen[0]['curl'][CURLOPT_MAXFILESIZE_LARGE] ?? null)->toBe(10_000)
        ->and($ask)->toThrow(ScoreSourceUnavailable::class, 'larger than 10000 bytes');
});

test('p07 F4: an admin whose clanmate signed up for an open leaderboard decides nothing about a rival signed up to it', function () {
    $gapAdmin = finishedAdmin();
    $mate = User::factory()->create();
    $clan = Clan::factory()->create(['owner_id' => $gapAdmin->id]);
    ClanMember::query()->create(['clan_id' => $clan->id, 'user_id' => $mate->id, 'role' => ClanRole::Member, 'joined_at' => now()]);
    $rival = User::factory()->create();
    $upcoming = Tournament::factory()->scoreDemo()->signup()->create(['starts_at' => now()->addDays(14), 'signup_closes_at' => now()->addDays(13)]);

    foreach ([$mate, $rival] as $user) {
        TournamentSignup::query()->create(['tournament_id' => $upcoming->id, 'user_id' => $user->id, 'name' => 'S '.$user->id, 'members' => [$user->id]]);
    }

    finishedStore($rival, 'acct-rival');
    ($this->finish)('r1', 'acct-rival', 38_000);
    ScoreAccounts::confirm($this->game, 'acct-rival', $rival, finishedAdmin(), 'Rival showed his account page.');

    expect(fn () => ScoreAccounts::revoke($this->game, 'acct-rival', $gapAdmin, 'Doubtful.'))->toThrow(TournamentRuleViolation::class)
        ->and(fn () => ScoreAccounts::dismiss($this->game, 'acct-rival', $rival, $gapAdmin, 'Not his.'))->toThrow(TournamentRuleViolation::class);
});

test('n3b F5: an id saved while the game was switched off still holds the end until it is decided', function () {
    [$tournament, [$owner, $other]] = runningScoreBoard(2);
    $this->fake->record($other->id, 'demo-1', 58_000, now());
    app(ScoreLeaderboards::class)->snapshot($tournament);
    config(['esports.score_games.demo' => false]);
    app()->forgetInstance(GameRegistry::class);
    $owner->forceFill(['gamer_tags' => ['score-demo' => 'acct-off']])->save();
    $this->fake = ScoreDemoOn::play();
    ($this->finish)('f1', 'acct-off', 41_000);
    $this->travelTo(ManualSubmissions::closesAt($tournament)->addMinute());

    expect(DB::table('score_account_tags')->where('user_id', $owner->id)->count())->toBe(0)
        ->and(fn () => app(ScoreLeaderboards::class)->finalize($tournament->refresh(), finishedAdmin()))->toThrow(TournamentRuleViolation::class, __('Finishes of an account a player stored wait for an admin to confirm whose it is. End the leaderboard once they are decided.'));
});

test('n4b F6: the account review pages in the database, and old finishes of ids nobody stored are pruned', function () {
    $events = [];

    foreach (range(0, 29) as $i) {
        $events[] = ['id' => "st{$i}", 'mode' => 'time-trial', 'course' => 'demo-1', 'account' => sprintf('acct-%03d', $i), 'value' => 60_000 + $i, 'achieved_at' => now()->getTimestamp()];
    }

    $this->postJson(route('scores.ingest'), ['events' => $events], ['Authorization' => 'Bearer '.$this->token])->assertOk();
    DB::enableQueryLog();
    $total = 0;
    $page = ScoreAccounts::pending(5, $total);
    $grouped = collect(DB::getQueryLog())->pluck('query')->first(fn (string $sql): bool => str_contains($sql, 'group by') && str_contains($sql, 'order by'));

    expect($page)->toHaveCount(5)
        ->and($total)->toBe(30)
        ->and($grouped)->toContain('limit 5');

    // Stored by a player, or confirmed: kept. Nobody's and older than prune_days: gone.
    $stored = User::factory()->create();
    finishedStore($stored, 'acct-000');
    $this->travel(31)->days();
    ($this->finish)('fresh', 'acct-fresh', 50_000);
    $this->artisan('model:prune', ['--model' => [ScoreRun::class]])->assertSuccessful();

    expect(ScoreRun::query()->whereNull('user_id')->pluck('account_id')->sort()->values()->all())->toBe(['acct-000', 'acct-fresh']);
});

test('note: an admin cannot dismiss an id for a player who never stored it', function () {
    ($this->finish)('x1', 'acct-x', 40_000);
    finishedStore(User::factory()->create(), 'acct-x');

    expect(fn () => ScoreAccounts::dismiss($this->game, 'acct-x', User::factory()->create(), finishedAdmin(), 'Not his.'))->toThrow(TournamentRuleViolation::class, __('This player has not stored that account id.'));
});

test('g1 G: a late confirm maps the finish into a running board on the same course, while the finished sibling keeps its standings', function () {
    [$monthly, [$p, $q]] = runningScoreBoard(2, 'time-trial', ['name' => 'B monthly', 'times' => ['game' => 30]]);
    [$weekly, [$x, $y]] = runningScoreBoard(2, 'time-trial', ['name' => 'A weekly']);
    $this->fake->record($x->id, 'demo-1', 50_000, now());
    $this->fake->record($y->id, 'demo-1', 52_000, now());
    $this->fake->record($q->id, 'demo-1', 58_000, now());
    finishedStore($p, 'acct-p');
    ($this->finish)('p1', 'acct-p', 41_000);
    app(ScoreLeaderboards::class)->tick();
    $this->travelTo(ManualSubmissions::closesAt($weekly)->addMinute());
    app(ScoreLeaderboards::class)->finalize($weekly->refresh(), finishedAdmin());
    $weeklyFinal = finishedStandings($weekly, [$x->id => 'X', $y->id => 'Y']);

    expect(ScoreAccounts::confirm($this->game, 'acct-p', $p, finishedAdmin(), 'P showed his account page.'))->toBe(1)
        ->and(finishedStandings($monthly, [$p->id => 'P', $q->id => 'Q']))->toBe([['P', 1, 41_000], ['Q', 2, 58_000]])
        ->and(finishedStandings($weekly, [$x->id => 'X', $y->id => 'Y']))->toBe($weeklyFinal);
});

test('g2 G: a reassign from a thief to the owner moves the finish in a running board on the same course after a sibling finished', function () {
    [$monthly, [$thief, $owner, $third]] = runningScoreBoard(3, 'time-trial', ['name' => 'B monthly', 'times' => ['game' => 30]]);
    [$weekly, [$x]] = runningScoreBoard(2, 'time-trial', ['name' => 'A weekly']);
    $this->fake->record($x->id, 'demo-1', 50_000, now());
    $this->fake->record($third->id, 'demo-1', 45_000, now());
    finishedStore($thief, 'acct-o');
    ScoreAccounts::confirm($this->game, 'acct-o', $thief, finishedAdmin(), 'Looked right at the time.');
    ($this->finish)('o1', 'acct-o', 39_000);
    app(ScoreLeaderboards::class)->tick();
    $this->travelTo(ManualSubmissions::closesAt($weekly)->addMinute());
    app(ScoreLeaderboards::class)->finalize($weekly->refresh(), finishedAdmin());
    finishedStore($owner, 'acct-o');

    expect(ScoreAccounts::reassign($this->game, 'acct-o', $owner, finishedAdmin(), 'Owner proved it with a login.'))->toBe(1)
        ->and(finishedStandings($monthly, [$thief->id => 'thief', $owner->id => 'owner', $third->id => 'third']))->toBe([['owner', 1, 39_000], ['third', 2, 45_000], ['thief', null, null]]);
});

test('h1 H: prune keeps a finish inside a running board whose window is longer than prune_days, and a later confirm maps it', function () {
    [$long, [$p, $q]] = runningScoreBoard(2, 'time-trial', ['name' => 'Long', 'times' => ['game' => 45]]);
    $this->fake->record($q->id, 'demo-1', 58_000, now());
    app(ScoreLeaderboards::class)->tick();
    ($this->finish)('h1', 'acct-h', 41_000);
    $this->travelTo(CarbonImmutable::parse('2026-11-07 12:00:00'));
    $this->artisan('model:prune', ['--model' => [ScoreRun::class]])->assertSuccessful();

    expect(ScoreRun::query()->where('account_id', 'acct-h')->count())->toBe(1);

    $this->travelTo(CarbonImmutable::parse('2026-11-10 12:00:00'));
    finishedStore($p, 'acct-h');

    expect(ScoreAccounts::confirm($this->game, 'acct-h', $p, finishedAdmin(), 'P showed his account page.'))->toBe(1)
        ->and(finishedStandings($long, [$p->id => 'P', $q->id => 'Q']))->toBe([['P', 1, 41_000], ['Q', 2, 58_000]]);
});

test('h2 H: prune keeps the finish of an id a player has in their gamer tags without a stored-at row, and the board still waits for it', function () {
    [$long, [$p]] = runningScoreBoard(2, 'time-trial', ['name' => 'Long F5', 'times' => ['game' => 45]]);
    config(['esports.score_games.demo' => false]);
    app()->forgetInstance(GameRegistry::class);
    $p->forceFill(['gamer_tags' => ['score-demo' => 'acct-off']])->save();
    $this->fake = ScoreDemoOn::play();
    ($this->finish)('off1', 'acct-off', 41_000);
    $this->travelTo(CarbonImmutable::parse('2026-11-07 12:00:00'));
    $this->artisan('model:prune', ['--model' => [ScoreRun::class]])->assertSuccessful();

    expect(DB::table('score_account_tags')->where('user_id', $p->id)->count())->toBe(0)
        ->and(ScoreRun::query()->where('account_id', 'acct-off')->count())->toBe(1)
        ->and(ScoreAccounts::blockingFor($long->refresh(), $this->game))->toBe([['account' => 'acct-off', 'user_id' => $p->id]]);

    // Without any open board over the run, the gamer tag alone keeps it.
    $long->forceFill(['status' => TournamentStatus::Cancelled])->save();
    $this->artisan('model:prune', ['--model' => [ScoreRun::class]])->assertSuccessful();

    expect(ScoreRun::query()->where('account_id', 'acct-off')->count())->toBe(1);
});
