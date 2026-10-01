<?php

/*
| The gates of the score games (plan "AoE2 und Trackmania", P4) after the
| review and the security audit: no wait for a leaderboard's board (M1),
| the window bounds of the standings (M2), the conflict of interest (F1), no
| place and no pay without a value with two entries (F2), no early end (F3),
| unproven account claims (F4) and a polite poller that cannot be held (F5).
*/

use App\Enums\ClanRole;
use App\Enums\TournamentStatus;
use App\Games\GameRegistry;
use App\Models\Admin;
use App\Models\Clan;
use App\Models\ClanMember;
use App\Models\ScoreRun;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\Payouts\PayoutPlan;
use App\Support\Payouts\TournamentPlacements;
use App\Support\Scores\ManualSubmissions;
use App\Support\Scores\ScoreAccount;
use App\Support\Scores\ScoreAccounts;
use App\Support\Scores\ScoreCourse;
use App\Support\Scores\ScoreLeaderboards;
use App\Support\Scores\ScoreRuns;
use App\Support\Scores\ScoreServers;
use App\Support\Scores\ScoreSourceUnavailable;
use App\Support\Tournaments\TournamentRuleViolation;
use App\Support\Tournaments\TournamentWaits;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use Tests\Support\FixtureScorePoller;
use Tests\Support\ScoreDemoOn;

/** The reason a rule violation gives, or null when the call went through. */
function scoreRefusal(Closure $call): ?string
{
    try {
        $call();
    } catch (TournamentRuleViolation $violation) {
        return $violation->reason;
    }

    return null;
}

function scoreAdmin(): User
{
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    return $admin;
}

beforeEach(function () {
    $this->fake = ScoreDemoOn::play();
    $this->travelTo(CarbonImmutable::parse('2026-10-06 12:00:00'));
});

test('M1: a leaderboard of two is no duel anybody waits for', function () {
    [$tournament] = runningScoreBoard(2);

    expect(TournamentWaits::of($tournament))->toBe([])
        ->and(TournamentWaits::forMatch($tournament, $tournament->matches()->with('slots.participant')->sole()))->toBeNull();
});

test('M2: a server finish counts from the window\'s start up to just before its end', function () {
    [$tournament, $players] = runningScoreBoard(4);
    $start = $tournament->starts_at->toImmutable();
    $end = $start->addDays(7);
    ['token' => $token] = ScoreServers::issue('box', 'score-demo');
    $times = [$start->subSecond(), $start, $end->subSecond(), $end];

    foreach ($players as $index => $player) {
        $player->forceFill(['gamer_tags' => ['score-demo' => "acct-{$index}"]])->save();
    }

    $this->travelTo($end->addHour());
    $this->postJson(route('scores.ingest'), ['events' => array_map(fn (int $index): array => ['id' => "w{$index}", 'mode' => 'time-trial', 'course' => 'demo-1',
        'account' => "acct-{$index}", 'value' => 50_000 + $index, 'achieved_at' => $times[$index]->getTimestamp()], array_keys($times))],
        ['Authorization' => 'Bearer '.$token])->assertOk()->assertJsonPath('accepted', 4);

    $values = collect(app(ScoreRuns::class)->standings($tournament))->mapWithKeys(fn ($row) => [$row->participant->user_id => $row->value])->all();

    expect([$values[$players[0]->id], $values[$players[1]->id], $values[$players[2]->id], $values[$players[3]->id]])->toBe([null, 50_001, 50_002, null]);
});

test('F1: a director who plays, a clanmate and an alt neither correct nor end a leaderboard', function () {
    [$tournament, [$a, $b, $c]] = runningScoreBoard(3);
    $organizer = $tournament->creator;
    $leaderboards = app(ScoreLeaderboards::class);

    // The organizer plays too.
    TournamentParticipant::query()->where('user_id', $c->id)->update(['user_id' => $organizer->id, 'members' => [$organizer->id]]);
    $alt = User::factory()->create();
    $tournament->directors()->attach($alt->id, ['added_by_id' => $organizer->id]);

    expect(scoreRefusal(fn () => $leaderboards->correct($tournament, $organizer, $b->id, 1_000, 'My rival was slow.')))->toBe('interested')
        ->and(scoreRefusal(fn () => $leaderboards->correct($tournament, $alt, $b->id, 1_000, 'Named by the organizer.')))->toBe('interested');

    // A clanmate of a player, named by nobody with a stake.
    $clan = Clan::factory()->create(['owner_id' => $a->id]);
    $mate = User::factory()->create();
    ClanMember::query()->create(['clan_id' => $clan->id, 'user_id' => $mate->id, 'role' => ClanRole::Member, 'joined_at' => now()]);
    $neutral = User::factory()->create();
    $tournament->directors()->attach([$mate->id => ['added_by_id' => $neutral->id], $neutral->id => ['added_by_id' => null]]);

    expect(scoreRefusal(fn () => $leaderboards->correct($tournament, $mate, $b->id, 1_000, 'Clan help.')))->toBe('interested');

    $this->travelTo($tournament->starts_at->addDays(9));

    expect(scoreRefusal(fn () => $leaderboards->finalize($tournament, $organizer)))->toBe('interested');
});

test('F1: an admin who plays reviews no submission of that leaderboard, an admin who does not does', function () {
    [$tournament, [$a, $b]] = runningScoreBoard(2);
    $playing = scoreAdmin();
    TournamentParticipant::query()->where('user_id', $b->id)->update(['user_id' => $playing->id, 'members' => [$playing->id]]);
    $run = app(ManualSubmissions::class)->submit($tournament, $a, '1:00.000', now(), 'https://example.org/p');

    expect(scoreRefusal(fn () => app(ManualSubmissions::class)->reject($run, $playing, 'Too fast to be true.')))->toBe('interested')
        ->and(scoreRefusal(fn () => app(ManualSubmissions::class)->approve($run, scoreAdmin())))->toBeNull();
});

test('F2: with two entries an entry without a value, taken off or disqualified is neither placed nor paid', function (string $case) {
    [$tournament, [$a, $b]] = runningScoreBoard(2, 'time-trial', ['prize_split' => [70, 30]]);
    $start = $tournament->starts_at->toImmutable();
    $this->fake->record($a->id, 'demo-1', 60_000, $start->addHour());

    match ($case) {
        'one without a value' => null,
        'taken off' => $this->fake->record($b->id, 'demo-1', 50_000, $start->addHour()),
        'disqualified' => $this->fake->record($b->id, 'demo-1', 50_000, $start->addHour()),
        'both without a value' => $this->fake = ScoreDemoOn::play(),
    };

    app(ScoreLeaderboards::class)->snapshot($tournament);

    if ($case === 'taken off') {
        app(ScoreLeaderboards::class)->correct($tournament, scoreAdmin(), $b->id, null, 'Slow-motion run.');
    }

    if ($case === 'disqualified') {
        TournamentParticipant::query()->where('user_id', $b->id)->update(['disqualified_at' => now(), 'disqualification_reason' => 'Cheating.']);
    }

    $this->travelTo($start->addDays(9));
    app(ScoreLeaderboards::class)->finalize($tournament->refresh());
    $tournament->refresh();
    $paid = array_map(fn (array $row): int => $row['user']->id, app(PayoutPlan::class)->compute($tournament, 10_000)['rows'] ?? []);

    expect($tournament->status)->toBe(TournamentStatus::Finished)
        ->and($paid)->toBe($case === 'both without a value' ? [] : [$a->id])
        ->and(count(app(TournamentPlacements::class)->of($tournament) ?? []))->toBe($case === 'both without a value' ? 0 : 1);
})->with(['one without a value', 'taken off', 'disqualified', 'both without a value']);

test('F3: a director ends a leaderboard neither inside the submission grace nor while a submission waits', function () {
    [$tournament, [$a]] = runningScoreBoard(2);
    $director = $tournament->creator;
    $end = $tournament->starts_at->toImmutable()->addDays(7);
    $this->travelTo($end->addMinutes(30));
    app(ManualSubmissions::class)->submit($tournament, $a, '1:00.000', $end->subHour(), 'https://example.org/p');

    expect(scoreRefusal(fn () => app(ScoreLeaderboards::class)->finalize($tournament, $director)))->toBe('grace');

    $this->travelTo($end->addMinutes(61));

    expect(scoreRefusal(fn () => app(ScoreLeaderboards::class)->finalize($tournament, $director)))->toBe('pending');

    app(ManualSubmissions::class)->approve(ScoreRun::query()->sole(), scoreAdmin());

    expect(scoreRefusal(fn () => app(ScoreLeaderboards::class)->finalize($tournament, $director)))->toBeNull()
        ->and($tournament->refresh()->status)->toBe(TournamentStatus::Finished);
});

test('F4: a claim taken first is no theft: the owner\'s claim sends the finishes to an admin, who confirms the owner', function () {
    [$tournament, [$attacker, $owner]] = runningScoreBoard(2);
    ['token' => $token] = ScoreServers::issue('box', 'score-demo');
    $send = fn (string $id) => $this->postJson(route('scores.ingest'), ['events' => [['id' => $id, 'mode' => 'time-trial', 'course' => 'demo-1', 'account' => 'acct-owner',
        'value' => 45_000, 'achieved_at' => now()->getTimestamp()]]], ['Authorization' => 'Bearer '.$token])->assertOk();

    Livewire::actingAs($attacker)->test('pages::settings.gaming')->set('gamerTags.score-demo', 'acct-owner')->call('save');
    $send('f1');

    expect(ScoreRun::query()->sole()->user_id)->toBe($attacker->id);

    Livewire::actingAs($owner)->test('pages::settings.gaming')->set('gamerTags.score-demo', 'acct-owner')->call('save');
    $send('f2');

    expect(ScoreRun::query()->pluck('user_id')->all())->toBe([null, null])
        ->and(collect(app(ScoreRuns::class)->standings($tournament))->pluck('value')->filter()->all())->toBe([])
        ->and(array_map(fn ($user) => $user->id, ScoreAccounts::pending()[0]['claimers']))->toBe([$attacker->id, $owner->id]);

    ScoreAccounts::confirm(app(GameRegistry::class)->get('score-demo'), 'acct-owner', $owner, scoreAdmin());
    $send('f3');

    expect(ScoreRun::query()->pluck('user_id')->unique()->values()->all())->toBe([$owner->id])
        ->and(ScoreAccount::of($attacker->refresh(), app(GameRegistry::class)->get('score-demo'))->accountId)->toBeNull()
        ->and(ScoreAccount::of($owner->refresh(), app(GameRegistry::class)->get('score-demo'))->accountId)->toBe('acct-owner');
});

test('F4: a pending finish is never handed over by the player\'s own claim, only by an admin', function () {
    ['token' => $token] = ScoreServers::issue('box', 'score-demo');
    $player = User::factory()->create();
    $this->postJson(route('scores.ingest'), ['events' => [['id' => 'p1', 'mode' => 'time-trial', 'course' => 'demo-1', 'account' => 'acct-late',
        'value' => 45_000, 'achieved_at' => now()->getTimestamp()]]], ['Authorization' => 'Bearer '.$token])->assertOk()->assertJsonPath('pending', 1);

    Livewire::actingAs($player)->test('pages::settings.gaming')->set('gamerTags.score-demo', 'acct-late')->call('save');

    expect(ScoreRun::query()->sole()->user_id)->toBeNull();

    Livewire::actingAs(scoreAdmin())->test('pages::admin.scores')
        ->assertSee('acct-late')
        ->call('confirmAccount', 'score-demo', 'acct-late', $player->id)
        ->assertSet('error', '');

    expect(ScoreRun::query()->sole()->user_id)->toBe($player->id)
        ->and(scoreRefusal(fn () => ScoreAccounts::confirm(app(GameRegistry::class)->get('score-demo'), 'acct-late', User::factory()->create(), scoreAdmin())))->toBe('not_claimed');
});

test('F4: a player who stores another player\'s id gets no records of it from a poller, nor does the owner until it is settled', function () {
    $game = app(GameRegistry::class)->get('score-demo');
    $honest = User::factory()->create(['gamer_tags' => ['score-demo' => 'acct-honest']]);

    expect(ScoreAccount::of($honest, $game)->accountId)->toBe('acct-honest');

    $attacker = User::factory()->create(['gamer_tags' => ['score-demo' => 'acct-honest']]);

    expect(ScoreAccount::of($attacker, $game)->accountId)->toBeNull()
        ->and(ScoreAccount::of($honest, $game)->accountId)->toBeNull();
});

test('F5: the poller follows no redirect, waits no Retry-After over the cap, and reads no answer over the size cap', function () {
    Sleep::fake();
    $game = app(GameRegistry::class)->get('score-demo');
    $course = new ScoreCourse($game, $game->mode('time-trial'), 'demo-1');
    $start = CarbonImmutable::parse('2026-10-05 17:00:00');
    $ask = fn () => app(FixtureScorePoller::class)->bestFor(new ScoreAccount(1, 'acct-1'), $course, $start, $start->addDays(7));
    config(['esports.score_games.poller.max_retry_after_seconds' => 60, 'esports.score_games.poller.max_body_bytes' => 1_000]);

    // One fake for the three answers: a later Http::fake() would only add stubs behind the first one.
    Http::fake(['scores.example.test/*' => Http::sequence()
        ->push('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data'])
        ->push('', 429, ['Retry-After' => '31536000'])
        ->push(['records' => [], 'padding' => str_repeat('x', 2_000)])]);

    expect($ask)->toThrow(ScoreSourceUnavailable::class);
    Http::assertSentCount(1);

    expect($ask)->toThrow(ScoreSourceUnavailable::class);
    Http::assertSentCount(2);
    Sleep::assertSlept(fn ($duration): bool => $duration->totalMilliseconds > 60_000, 0);

    expect($ask)->toThrow(ScoreSourceUnavailable::class);
    Http::assertSentCount(3);
});
