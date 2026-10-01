<?php

/*
| The sources a score game is read from (plan "AoE2 und Trackmania", P4):
| the contract with the fake and the manual source, the polite HTTP poller
| base (user agent, spacing, backoff, "could not ask" is no "no record") and
| our own servers' ingest (token hashed and revocable, validated, idempotent,
| unknown accounts kept pending and never shown).
*/

use App\Games\GameRegistry;
use App\Models\ScoreRun;
use App\Models\ScoreServer;
use App\Models\User;
use App\Support\Scores\ScoreAccount;
use App\Support\Scores\ScoreAccounts;
use App\Support\Scores\ScoreCourse;
use App\Support\Scores\ScoreServers;
use App\Support\Scores\ScoreSourceUnavailable;
use App\Support\Scores\Sources\ManualScoreSource;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\FixtureScorePoller;
use Tests\Support\ScoreDemoOn;

beforeEach(function () {
    $this->fake = ScoreDemoOn::play();
    $game = app(GameRegistry::class)->get('score-demo');
    $this->course = new ScoreCourse($game, $game->mode('time-trial'), 'demo-1');
    $this->start = CarbonImmutable::parse('2026-10-05 17:00:00');
    $this->end = $this->start->addDays(7);
});

test('the fake source answers the best inside the window, a tie by the earlier record, and can be down', function () {
    $this->fake->record(1, 'demo-1', 60_000, $this->start->addHours(5))
        ->record(1, 'demo-1', 60_000, $this->start->addHours(2))
        ->record(1, 'demo-1', 59_000, $this->end)
        ->record(1, 'demo-1', 58_000, $this->start->subSecond());

    $best = $this->fake->bestFor(new ScoreAccount(1), $this->course, $this->start, $this->end);

    expect([$best?->value, $best?->achievedAt->equalTo($this->start->addHours(2))])->toBe([60_000, true])
        ->and($this->fake->bestFor(new ScoreAccount(2), $this->course, $this->start, $this->end))->toBeNull()
        ->and(fn () => $this->fake->down()->bestFor(new ScoreAccount(1), $this->course, $this->start, $this->end))->toThrow(ScoreSourceUnavailable::class);
});

test('the manual source answers only what an admin verified, inside the window', function () {
    $user = User::factory()->create();
    $base = ['user_id' => $user->id, 'game' => 'score-demo', 'mode' => 'time-trial', 'course' => 'demo-1', 'unit' => 'ms', 'source' => ScoreRun::MANUAL, 'proof_url' => 'https://example.org/p'];
    ScoreRun::query()->create([...$base, 'value' => 50_000, 'achieved_at' => $this->start->addHour()]);
    ScoreRun::query()->create([...$base, 'value' => 55_000, 'achieved_at' => $this->start->addHour(), 'verified_at' => now()]);
    ScoreRun::query()->create([...$base, 'value' => 45_000, 'achieved_at' => $this->start->addHour(), 'verified_at' => now(), 'rejected_at' => now()]);
    ScoreRun::query()->create([...$base, 'value' => 40_000, 'achieved_at' => $this->end->addSecond(), 'verified_at' => now()]);

    expect(app(ManualScoreSource::class)->bestFor(new ScoreAccount($user->id), $this->course, $this->start, $this->end)?->value)->toBe(55_000);
});

test('the poller base sends its user agent, waits its turn, backs off on 429 and 5xx, and reads the best', function () {
    Sleep::fake();
    config(['esports.score_games.poller' => ['user_agent' => 'league-test (+https://example.org)', 'min_interval_ms' => 1000, 'timeout_seconds' => 5, 'retries' => 3, 'backoff_ms' => 2000]]);
    Http::fake(['scores.example.test/*' => Http::sequence()
        ->push('', 429, ['Retry-After' => '7'])
        ->push('', 503)
        ->push(['records' => [
            ['time' => 61_000, 'at' => $this->start->addHour()->getTimestamp()],
            ['time' => 59_000, 'at' => $this->start->addHours(3)->getTimestamp(), 'replay' => 'https://scores.example.test/replay/1'],
            ['time' => 40_000, 'at' => $this->end->addHour()->getTimestamp()],
        ]])]);

    $best = app(FixtureScorePoller::class)->bestFor(new ScoreAccount(1, 'acct-1'), $this->course, $this->start, $this->end);

    expect([$best?->value, $best?->proofUrl, $best?->source])->toBe([59_000, 'https://scores.example.test/replay/1', 'fixture-api']);
    Http::assertSentCount(3);
    Http::assertSent(fn (Request $request): bool => $request->header('User-Agent') === ['league-test (+https://example.org)'] && str_contains($request->url(), 'course=demo-1'));
    // Retry-After 7 s, then the doubled backoff 4 s, and the spacing between the three requests in between.
    Sleep::assertSlept(fn ($duration): bool => (int) $duration->totalMilliseconds === 7000, 1);
    Sleep::assertSlept(fn ($duration): bool => (int) $duration->totalMilliseconds === 4000, 1);
});

test('the poller base gives up with "could not ask", never "no record"', function () {
    Sleep::fake();
    config(['esports.score_games.poller.retries' => 1]);
    Http::fake(['scores.example.test/*' => Http::response('', 500)]);

    expect(fn () => app(FixtureScorePoller::class)->bestFor(new ScoreAccount(1, 'acct-1'), $this->course, $this->start, $this->end))
        ->toThrow(ScoreSourceUnavailable::class);
    Http::assertSentCount(2);

    Http::fake(['scores.example.test/*' => Http::response('', 404)]);

    expect(fn () => app(FixtureScorePoller::class)->bestFor(new ScoreAccount(1, 'acct-1'), $this->course, $this->start, $this->end))
        ->toThrow(ScoreSourceUnavailable::class);
});

test('a server reports finishes with its token: stored once, mapped by the private account id, unknown ones pending', function () {
    $player = User::factory()->create(['gamer_tags' => ['score-demo' => 'acct-known']]);
    ['server' => $server, 'token' => $token] = ScoreServers::issue('demo box', 'score-demo');
    $payload = ['events' => [
        ['id' => 'e1', 'mode' => 'time-trial', 'course' => 'demo-1', 'account' => 'acct-known', 'value' => 61_000, 'achieved_at' => now()->subMinute()->getTimestamp(), 'raw' => ['lap' => 1]],
        ['id' => 'e2', 'mode' => 'time-trial', 'course' => 'demo-1', 'account' => 'acct-stranger', 'value' => 58_000, 'achieved_at' => now()->subMinute()->toIso8601String()],
        ['id' => 'e3', 'mode' => 'no-such-mode', 'course' => 'demo-1', 'account' => 'acct-known', 'value' => 1, 'achieved_at' => now()->getTimestamp()],
        ['id' => 'e4', 'mode' => 'time-trial', 'course' => 'demo-1', 'account' => 'acct-known', 'value' => 1, 'achieved_at' => now()->addHour()->getTimestamp()],
        ['id' => 'e5', 'mode' => 'time-trial', 'course' => 'demo-1', 'account' => 'acct-known', 'value' => 'fast', 'achieved_at' => now()->getTimestamp()],
    ]];

    expect($server->token_hash)->toBe(hash('sha256', $token))->not->toBe($token);

    $this->postJson(route('scores.ingest'), $payload, ['Authorization' => 'Bearer '.$token])->assertOk()
        ->assertExactJson(['accepted' => 1, 'duplicate' => 0, 'pending' => 1, 'refused' => ['2' => 'mode', '3' => 'achieved_at', '4' => 'value']]);
    // The same batch again: nothing new.
    $this->postJson(route('scores.ingest'), $payload, ['Authorization' => 'Bearer '.$token])->assertOk()->assertJsonPath('duplicate', 2);

    expect(ScoreRun::query()->count())->toBe(2)
        ->and(ScoreRun::query()->where('external_id', 'e1')->value('user_id'))->toBe($player->id)
        ->and(ScoreRun::query()->where('external_id', 'e2')->value('user_id'))->toBeNull();

    // The stranger stores the id later: the pending finish becomes theirs.
    $stranger = User::factory()->create(['gamer_tags' => ['score-demo' => 'acct-stranger']]);

    expect(ScoreAccounts::claim($stranger))->toBe(1)
        ->and(ScoreRun::query()->where('external_id', 'e2')->value('user_id'))->toBe($stranger->id);
});

test('a missing, wrong or revoked token is refused, and an id two players claim maps to nobody', function () {
    ['server' => $server, 'token' => $token] = ScoreServers::issue('demo box', 'score-demo');
    $event = ['events' => [['id' => 'x1', 'mode' => 'time-trial', 'course' => 'demo-1', 'account' => 'acct-twice', 'value' => 1000, 'achieved_at' => now()->getTimestamp()]]];

    $this->postJson(route('scores.ingest'), $event)->assertUnauthorized();
    $this->postJson(route('scores.ingest'), $event, ['Authorization' => 'Bearer sst_wrong'])->assertUnauthorized();

    User::factory()->count(2)->create(['gamer_tags' => ['score-demo' => 'acct-twice']]);
    $this->postJson(route('scores.ingest'), $event, ['Authorization' => 'Bearer '.$token])->assertOk()->assertJsonPath('pending', 1);

    ScoreServers::revoke($server);

    $this->postJson(route('scores.ingest'), $event, ['Authorization' => 'Bearer '.$token])->assertUnauthorized();
    expect(ScoreServer::query()->sole()->isRevoked())->toBeTrue();
});
