<?php

/*
| Game accounts of the score games after the re-audit (plan "AoE2 und
| Trackmania", P4, F4 decided 2026-10-01): a stored id is a claim and maps
| nothing until an admin confirms it; an admin with a stake decides nothing;
| a confirmation is reassigned or revoked with its runs and a log line; a
| leaderboard does not end while a finish of a stored id waits. Plus the
| bounded poller (N3) and a switched-off game's tournaments (item 3). The
| cases p04, p07, p12, p13, p14 are the auditor's measured attacks.
*/

use App\Enums\TournamentStatus;
use App\Games\GameRegistry;
use App\Models\Admin;
use App\Models\ScoreAccountChange;
use App\Models\ScoreRun;
use App\Models\SeriesMatch;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\Navigation\AdminNavigation;
use App\Support\Scores\ScoreAccount;
use App\Support\Scores\ScoreAccounts;
use App\Support\Scores\ScoreCourse;
use App\Support\Scores\ScoreLeaderboards;
use App\Support\Scores\ScoreRuns;
use App\Support\Scores\ScoreServers;
use App\Support\Scores\ScoreSourceUnavailable;
use App\Support\Tournaments\TournamentDraws;
use App\Support\Tournaments\TournamentRuleViolation;
use App\Support\Tournaments\TournamentWaits;
use Carbon\CarbonImmutable;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\PumpStream;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use Tests\Support\FixtureScorePoller;
use Tests\Support\ScoreDemoOn;

function accountsAdmin(): User
{
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    return $admin;
}

function storeAccount(User $player, string $id): void
{
    Livewire::actingAs($player)->test('pages::settings.gaming')->set('gamerTags.score-demo', $id)->call('save')->assertHasNoErrors();
    auth()->logout();
}

beforeEach(function () {
    $this->fake = ScoreDemoOn::play();
    $this->travelTo(CarbonImmutable::parse('2026-10-06 12:00:00'));
    $this->game = app(GameRegistry::class)->get('score-demo');
    ['token' => $this->token] = ScoreServers::issue('box', 'score-demo');
    $this->finish = fn (string $id, string $account, int $value) => $this->postJson(route('scores.ingest'), ['events' => [['id' => $id, 'mode' => 'time-trial',
        'course' => 'demo-1', 'account' => $account, 'value' => $value, 'achieved_at' => now()->getTimestamp()]]], ['Authorization' => 'Bearer '.$this->token])->assertOk()->json();
});

test('p04: a claim nobody confirmed maps no finish, not even a future one, and the leaderboard does not end over it', function () {
    [$tournament, [$attacker, $other]] = runningScoreBoard(2);
    $this->fake->record($other->id, 'demo-1', 58_000, now());
    app(ScoreLeaderboards::class)->snapshot($tournament);

    expect(($this->finish)('o1', 'acct-owner', 44_000)['pending'])->toBe(1);

    storeAccount($attacker, 'acct-owner');

    expect(($this->finish)('o2', 'acct-owner', 44_000)['pending'])->toBe(1)
        ->and(ScoreRun::query()->where('account_id', 'acct-owner')->pluck('user_id')->filter()->all())->toBe([])
        ->and(collect(app(ScoreRuns::class)->standings($tournament))->firstWhere('participant.user_id', $attacker->id)->value)->toBeNull();

    // The admin badge counts the account that waits, and the league does not end the leaderboard over it.
    $this->actingAs(accountsAdmin());
    $badge = collect(AdminNavigation::forCurrentUser()->groups())->flatMap(fn (array $group) => $group['items'])->firstWhere('key', 'scores')['count'];
    // Inside the review time (window end + review_hours); after it the end leaves the id out (round-3 S1, p04b).
    $this->travelTo($tournament->starts_at->addDays(7)->addHours(2));

    expect($badge)->toBe(1)
        ->and(app(ScoreLeaderboards::class)->tick()['finalized'])->toBe(0)
        ->and($tournament->refresh()->status)->toBe(TournamentStatus::Running)
        ->and(fn () => app(ScoreLeaderboards::class)->finalize($tournament, accountsAdmin()))->toThrow(TournamentRuleViolation::class, __('Finishes of an account a player stored wait for an admin to confirm whose it is. End the leaderboard once they are decided.'));
});

test('p07: an admin who plays a running leaderboard of the game confirms no account a player of it claims', function () {
    [$tournament, [$rival, $clanmate, $seat]] = runningScoreBoard(3);
    $playingAdmin = accountsAdmin();
    TournamentParticipant::query()->where('user_id', $seat->id)->update(['user_id' => $playingAdmin->id, 'members' => [$playingAdmin->id]]);
    ($this->finish)('r1', 'acct-rival', 45_000);
    storeAccount($clanmate, 'acct-rival');

    expect(fn () => ScoreAccounts::confirm($this->game, 'acct-rival', $clanmate, $playingAdmin, 'He says it is his.'))->toThrow(TournamentRuleViolation::class)
        ->and(ScoreRun::query()->sole()->user_id)->toBeNull()
        ->and(ScoreAccounts::confirm($this->game, 'acct-rival', $clanmate, accountsAdmin(), 'Logged in with the game.'))->toBe(1);
});

test('p12: a contested id neither voids nor maps, and a confirmed one stays its owner\'s whoever stores it too', function () {
    [$tournament, [$rival, $attacker]] = runningScoreBoard(2);
    $this->fake->record($attacker->id, 'demo-1', 70_000, now());
    app(ScoreLeaderboards::class)->snapshot($tournament);
    storeAccount($rival, 'acct-rival');
    ($this->finish)('r1', 'acct-rival', 45_000);
    storeAccount($attacker, 'acct-rival');
    ($this->finish)('r2', 'acct-rival', 44_000);

    // Nobody confirmed: the attacker does not end the leaderboard as its leader (inside the review time).
    $this->travelTo($tournament->starts_at->addDays(7)->addHours(2));

    expect(fn () => app(ScoreLeaderboards::class)->finalize($tournament, accountsAdmin()))->toThrow(TournamentRuleViolation::class)
        ->and($tournament->refresh()->status)->toBe(TournamentStatus::Running);

    ScoreAccounts::confirm($this->game, 'acct-rival', $rival, accountsAdmin(), 'Rival showed his account page on stream.');
    storeAccount($attacker, 'acct-rival');

    expect(ScoreRun::query()->where('account_id', 'acct-rival')->pluck('user_id')->unique()->values()->all())->toBe([$rival->id])
        ->and(collect(app(ScoreRuns::class)->standings($tournament))->first()->participant->user_id)->toBe($rival->id);
});

test('p13: a poller reads an account only for the player it was confirmed for', function () {
    $attacker = User::factory()->create(['gamer_tags' => ['score-demo' => 'acct-pro']]);

    expect(ScoreAccount::of($attacker, $this->game)->accountId)->toBeNull();

    $pro = User::factory()->create(['gamer_tags' => ['score-demo' => 'acct-pro']]);
    ScoreAccounts::confirm($this->game, 'acct-pro', $pro, accountsAdmin(), 'Verified with the game login.');

    expect(ScoreAccount::of($attacker->refresh(), $this->game)->accountId)->toBeNull()
        ->and(ScoreAccount::of($pro->refresh(), $this->game)->accountId)->toBe('acct-pro');
});

test('p14: a confirmation for the wrong claimer is moved to the owner with its finishes, and a revoke sends them back to pending, each logged', function () {
    $attacker = User::factory()->create();
    $owner = User::factory()->create();
    $admin = accountsAdmin();
    ($this->finish)('o1', 'acct-owner', 44_000);
    storeAccount($attacker, 'acct-owner');

    // The admin page lists every claimer side by side and asks why.
    Livewire::actingAs($admin)->test('pages::admin.scores')
        ->assertSee('acct-owner')->assertSeeHtml('data-test="score-account-claimer"')
        ->call('confirmAccount', 'score-demo', 'acct-owner', $attacker->id)
        ->assertNotSet('error', '');
    auth()->logout();

    expect(ScoreRun::query()->sole()->user_id)->toBeNull();

    ScoreAccounts::confirm($this->game, 'acct-owner', $attacker, $admin, 'Said it is his.');
    ($this->finish)('o2', 'acct-owner', 43_000);
    storeAccount($owner, 'acct-owner');

    expect(ScoreAccounts::reassign($this->game, 'acct-owner', $owner, accountsAdmin(), 'The owner logged in with the game.'))->toBe(2)
        ->and(ScoreRun::query()->pluck('user_id')->unique()->values()->all())->toBe([$owner->id])
        ->and(ScoreAccount::of($owner->refresh(), $this->game)->accountId)->toBe('acct-owner');

    expect(ScoreAccounts::revoke($this->game, 'acct-owner', accountsAdmin(), 'Shared account, nobody owns it alone.'))->toBe(2)
        ->and(ScoreRun::query()->pluck('user_id')->filter()->all())->toBe([])
        ->and(($this->finish)('o3', 'acct-owner', 42_000)['pending'])->toBe(1)
        ->and(ScoreAccountChange::query()->pluck('action')->all())->toBe(['confirm', 'reassign', 'revoke']);
});

test('an admin decides no account they stored themselves, and only for a player who stored it', function () {
    $admin = accountsAdmin();
    $admin->forceFill(['gamer_tags' => ['score-demo' => 'acct-mine']])->save();
    $other = User::factory()->create(['gamer_tags' => ['score-demo' => 'acct-mine']]);

    expect(fn () => ScoreAccounts::confirm($this->game, 'acct-mine', $other, $admin, 'Looks right.'))->toThrow(TournamentRuleViolation::class)
        ->and(fn () => ScoreAccounts::confirm($this->game, 'acct-mine', User::factory()->create(), accountsAdmin(), 'Looks right.'))->toThrow(TournamentRuleViolation::class);
});

test('N3: the poller stops reading past the cap, and refuses a compressed answer', function () {
    Sleep::fake();
    $course = new ScoreCourse($this->game, $this->game->mode('time-trial'), 'demo-1');
    $start = CarbonImmutable::parse('2026-10-05 17:00:00');
    $ask = fn () => app(FixtureScorePoller::class)->bestFor(new ScoreAccount(1, 'acct-1'), $course, $start, $start->addDays(7));
    config(['esports.score_games.poller.max_body_bytes' => 10_000]);
    $read = 0;
    $endless = new PumpStream(function (int $length) use (&$read): string {
        $read += $length;

        return str_repeat('x', $length);
    });
    $answers = [new PsrResponse(200, [], $endless), new PsrResponse(200, ['Content-Encoding' => 'gzip'], gzencode(str_repeat('x', 100_000)))];
    Http::fake(function () use (&$answers) {
        return new FulfilledPromise(array_shift($answers));
    });

    expect($ask)->toThrow(ScoreSourceUnavailable::class)
        ->and($read)->toBeLessThan(10_000 + 2 * 8192)
        ->and($ask)->toThrow(ScoreSourceUnavailable::class);

    Http::assertSent(fn ($request): bool => $request->header('Accept-Encoding') === ['identity']);
});

test('item 3: a score game switched off keeps its tournaments readable, with no wait and nothing started', function () {
    [$tournament, [$player]] = runningScoreBoard(2, 'time-trial', ['published_at' => now()->subDay(), 'slug' => 'score-off']);
    $admin = accountsAdmin();
    config(['esports.score_games.demo' => false]);
    app()->forgetInstance(GameRegistry::class);
    $tournament = Tournament::query()->findOrFail($tournament->id);

    expect($tournament->profile()->isUnknown())->toBeTrue()
        ->and(TournamentWaits::of($tournament))->toBe([]);

    app(TournamentDraws::class)->advanceDue();

    expect(SeriesMatch::query()->count())->toBe(0);

    $this->get(route('tournaments.index'))->assertOk();
    $this->get(route('tournaments.show', $tournament))->assertOk();
    $this->get(route('tournaments.calendar', $tournament))->assertOk();
    $this->actingAs($player)->get(route('dashboard'))->assertOk();
    $this->actingAs($admin)->get(route('admin.tournaments'))->assertOk();
});
