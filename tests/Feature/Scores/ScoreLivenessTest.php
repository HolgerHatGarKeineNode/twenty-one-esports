<?php

/*
| Score games after the round-3 audit (plan "AoE2 und Trackmania", P4): a
| leaderboard's end never depends on anybody (S1), the admin review lists
| every account and puts those that hold a leaderboard first (S2), one entry
| a source refuses skips only that entry (S3), a streamed answer has a
| wall-clock deadline (S4), the account log keeps who decided (S5), an open
| leaderboard counts for the interest check (S6), and the stand-in profile
| is only for a game the registry does not know. The case names are the
| auditor's probes.
*/

use App\Enums\TournamentStatus;
use App\Games\GameRegistry;
use App\Livewire\Actions\DeleteAccount;
use App\Models\Admin;
use App\Models\ScoreAccountChange;
use App\Models\ScoreAccountClaim;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentParticipant;
use App\Models\TournamentSignup;
use App\Models\User;
use App\Support\Navigation\AdminNavigation;
use App\Support\Scores\ScoreAccount;
use App\Support\Scores\ScoreAccounts;
use App\Support\Scores\ScoreCourse;
use App\Support\Scores\ScoreLeaderboards;
use App\Support\Scores\ScoreServers;
use App\Support\Scores\ScoreSourceUnavailable;
use App\Support\Scores\Sources\FakeScoreSource;
use App\Support\Tournaments\GameProfile;
use App\Support\Tournaments\TournamentControl;
use App\Support\Tournaments\TournamentRuleViolation;
use Carbon\CarbonImmutable;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\PumpStream;
use GuzzleHttp\Psr7\Response as PsrResponse;
use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use Psr\Http\Message\StreamInterface;
use Tests\Support\FixtureScorePoller;
use Tests\Support\ScoreDemoOn;

function livenessAdmin(): User
{
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    return $admin;
}

function livenessStore(User $player, string $id): void
{
    Livewire::actingAs($player)->test('pages::settings.gaming')->set('gamerTags.score-demo', $id)->call('save')->assertHasNoErrors();
    auth()->logout();
}

function livenessBadge(User $admin): int
{
    test()->actingAs($admin);
    $count = collect(AdminNavigation::forCurrentUser()->groups())->flatMap(fn (array $group) => $group['items'])->firstWhere('key', 'scores')['count'];
    auth()->logout();

    return (int) $count;
}

beforeEach(function () {
    $this->fake = ScoreDemoOn::play();
    $this->travelTo(CarbonImmutable::parse('2026-10-06 12:00:00'));
    $this->game = app(GameRegistry::class)->get('score-demo');
    ['token' => $this->token] = ScoreServers::issue('box', 'score-demo');
    $this->finish = fn (string $id, string $account, int $value) => $this->postJson(route('scores.ingest'), ['events' => [['id' => $id, 'mode' => 'time-trial',
        'course' => 'demo-1', 'account' => $account, 'value' => $value, 'achieved_at' => now()->getTimestamp()]]], ['Authorization' => 'Bearer '.$this->token])->assertOk()->json();
});

test('p04 S1: a stranger id stored by an entrant holds the leaderboard only until its review time is over, then the end leaves it out with a log line', function () {
    [$tournament, [$attacker, $other]] = runningScoreBoard(2);
    $this->fake->record($other->id, 'demo-1', 58_000, now());
    $this->fake->record($attacker->id, 'demo-1', 70_000, now());
    app(ScoreLeaderboards::class)->snapshot($tournament);
    ($this->finish)('o1', 'acct-owner', 44_000);
    livenessStore($attacker, 'acct-owner');

    // Inside the review time it waits (an admin decides first) ...
    $this->travelTo($tournament->starts_at->addDays(7)->addHours(2));
    expect(app(ScoreLeaderboards::class)->tick()['finalized'])->toBe(0);

    // ... once window end + review_hours passed, nobody has to act: the league ends it and logs what it left out.
    $this->travelTo($tournament->starts_at->addDays(8)->addMinute());

    expect(app(ScoreLeaderboards::class)->tick()['finalized'])->toBe(1)
        ->and($tournament->refresh()->status)->toBe(TournamentStatus::Finished)
        ->and(ScoreAccountChange::query()->where(['action' => 'left_out', 'account_id' => 'acct-owner', 'from_user_id' => $attacker->id])->whereNull('admin_id')->sole()->reason)->toContain('left out: pending');
});

test('p04 S1: a disqualified entry holds nothing', function () {
    [$tournament, [$attacker, $other]] = runningScoreBoard(2);
    $this->fake->record($other->id, 'demo-1', 58_000, now());
    app(ScoreLeaderboards::class)->snapshot($tournament);
    ($this->finish)('o1', 'acct-owner', 44_000);
    livenessStore($attacker, 'acct-owner');
    $participant = TournamentParticipant::query()->where('tournament_id', $tournament->id)->where('user_id', $attacker->id)->sole();
    app(TournamentControl::class)->disqualify($tournament, livenessAdmin(), $participant->id, 'Stored a stranger account id to block the end.');

    expect(ScoreAccounts::waitsFor($tournament->refresh(), $this->game))->toBeFalse();
});

test('p04b S1: an id stored after the window closed holds nothing', function () {
    [$tournament, [$attacker, $other]] = runningScoreBoard(2);
    $this->fake->record($other->id, 'demo-1', 58_000, now());
    $this->fake->record($attacker->id, 'demo-1', 70_000, now());
    app(ScoreLeaderboards::class)->snapshot($tournament);
    ($this->finish)('s1', 'acct-stranger', 50_000);

    $this->travelTo($tournament->starts_at->addDays(7)->addHours(2));
    livenessStore($attacker, 'acct-stranger');

    expect(ScoreAccounts::waitsFor($tournament, $this->game))->toBeFalse()
        ->and(fn () => app(ScoreLeaderboards::class)->finalize($tournament, livenessAdmin()))->not->toThrow(TournamentRuleViolation::class)
        ->and($tournament->refresh()->status)->toBe(TournamentStatus::Finished);
});

test('p04 S1: an admin says "not this player\'s": the wait ends, nothing is mapped, a stake refuses, the log keeps it', function () {
    [$tournament, [$attacker, $other, $seat]] = runningScoreBoard(3);
    ($this->finish)('o1', 'acct-owner', 44_000);
    livenessStore($attacker, 'acct-owner');
    $playingAdmin = livenessAdmin();
    TournamentParticipant::query()->where('user_id', $seat->id)->update(['user_id' => $playingAdmin->id, 'members' => [$playingAdmin->id]]);
    $this->travelTo($tournament->starts_at->addDays(7)->addHours(2));

    expect(ScoreAccounts::waitsFor($tournament, $this->game))->toBeTrue()
        ->and(fn () => ScoreAccounts::dismiss($this->game, 'acct-owner', $attacker, $playingAdmin, 'Not his, he admits it.'))->toThrow(TournamentRuleViolation::class);

    $admin = livenessAdmin();
    Livewire::actingAs($admin)->test('pages::admin.scores')
        ->set('accountReasons.'.md5('score-demo|acct-owner'), 'The owner wrote to us from the account.')
        ->call('dismissAccount', 'score-demo', 'acct-owner', $attacker->id)->assertSet('error', '');

    expect(ScoreAccounts::waitsFor($tournament, $this->game))->toBeFalse()
        ->and(ScoreAccounts::userFor($this->game, 'acct-owner'))->toBeNull()
        ->and(ScoreAccountChange::query()->where('action', 'dismiss')->sole()->only(['from_user_id', 'admin_id', 'admin_pubkey']))->toBe(['from_user_id' => $attacker->id, 'admin_id' => $admin->id, 'admin_pubkey' => $admin->pubkey]);
});

test('p21 S2: past 100 unclaimed ids the entrant\'s id that holds a leaderboard is listed first with its buttons, and the badge counts only that one', function () {
    [$tournament, [$member]] = runningScoreBoard(2);
    $admin = livenessAdmin();
    $events = [];

    foreach (range(0, 104) as $i) {
        $events[] = ['id' => "st{$i}", 'mode' => 'time-trial', 'course' => 'demo-1', 'account' => sprintf('acct-%03d', $i), 'value' => 60_000 + $i, 'achieved_at' => now()->getTimestamp()];
    }

    foreach (array_chunk($events, 50) as $batch) {
        $this->postJson(route('scores.ingest'), ['events' => $batch], ['Authorization' => 'Bearer '.$this->token])->assertOk();
    }

    livenessStore($member, 'zz-member');
    ($this->finish)('m1', 'zz-member', 40_000);
    $page = Livewire::actingAs($admin)->test('pages::admin.scores');
    $html = $page->html();

    expect(ScoreAccounts::pending(1)[0]['account'])->toBe('zz-member')
        ->and($html)->toContain('zz-member')
        ->and(substr_count($html, 'data-test="score-account-confirm"'))->toBe(1)
        ->and($html)->toContain('data-test="score-accounts-more"')
        ->and(str_contains($html, 'acct-104'))->toBeFalse();

    expect($page->call('showMoreAccounts')->call('showMoreAccounts')->call('showMoreAccounts')->call('showMoreAccounts')->call('showMoreAccounts')->html())->toContain('acct-104');

    // An unclaimed id can be dismissed off the list.
    $page->set('accountReasons.'.md5('score-demo|acct-104'), 'Test runs of the server owner.')->call('dismissAccount', 'score-demo', 'acct-104')->assertSet('error', '');
    $total = 0;
    ScoreAccounts::pending(500, $total);
    auth()->logout();

    expect($total)->toBe(105)
        ->and(livenessBadge($admin))->toBe(1);
});

test('p23 S2: the confirmed list pages on, so an older wrong confirmation still has its move button', function () {
    $admin = livenessAdmin();
    $attacker = User::factory()->create(['gamer_tags' => ['score-demo' => 'acct-old']]);
    ScoreAccountClaim::query()->create(['game' => 'score-demo', 'account_id' => 'acct-old', 'user_id' => $attacker->id, 'confirmed_by_id' => $admin->id]);
    $this->travel(1)->minutes();

    foreach (range(1, 100) as $i) {
        $user = User::factory()->create(['gamer_tags' => ['score-demo' => "acct-m{$i}"]]);
        ScoreAccountClaim::query()->create(['game' => 'score-demo', 'account_id' => "acct-m{$i}", 'user_id' => $user->id, 'confirmed_by_id' => $admin->id]);
    }

    User::factory()->create(['gamer_tags' => ['score-demo' => 'acct-old']]);
    $page = Livewire::actingAs($admin)->test('pages::admin.scores');

    foreach (range(1, 5) as $step) {
        $page->call('showMoreClaims');
    }

    $html = $page->html();

    expect($html)->toContain('acct-old')
        ->and(substr_count($html, 'data-test="score-claim-reassign"'))->toBe(1);
});

test('p19 S3: an entry the source does not know skips only that entry, and an unconfirmed one is never asked for', function () {
    Sleep::fake();
    config(['esports.score_games.poller.min_interval_ms' => 0, 'esports.score_games.poller.retries' => 0]);
    app()->instance(FakeScoreSource::class, app(FixtureScorePoller::class));
    Http::fake(function ($request) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);

        return match (true) {
            (bool) preg_match('#/records/acct-[ab]-\w+$#', $path) => Http::response(['records' => [['time' => 50_000, 'at' => now()->getTimestamp()]]]),
            default => Http::response(['error' => 'unknown account'], 404),
        };
    });

    foreach (['gone' => 'acct-gone', 'unconfirmed' => null] as $case => $middleId) {
        [$tournament, [$a, $middle, $b]] = runningScoreBoard(3, 'time-trial', ['name' => "p19 {$case}"]);
        $a->forceFill(['gamer_tags' => ['score-demo' => "acct-a-{$case}"]])->save();
        $b->forceFill(['gamer_tags' => ['score-demo' => "acct-b-{$case}"]])->save();
        ScoreAccounts::confirm($this->game, "acct-a-{$case}", $a, livenessAdmin(), 'Verified.');
        ScoreAccounts::confirm($this->game, "acct-b-{$case}", $b, livenessAdmin(), 'Verified.');

        if ($middleId !== null) {
            $middle->forceFill(['gamer_tags' => ['score-demo' => "{$middleId}-{$case}"]])->save();
            ScoreAccounts::confirm($this->game, "{$middleId}-{$case}", $middle, livenessAdmin(), 'Verified.');
        }

        $before = count(Http::recorded());
        $snapshot = app(ScoreLeaderboards::class)->snapshot($tournament);
        $paths = collect(Http::recorded())->slice($before)->map(fn (array $pair) => (string) parse_url($pair[0]->url(), PHP_URL_PATH))->values()->all();

        expect($snapshot)->toBe(['stored' => 2, 'failed' => $middleId === null ? 0 : 1])
            ->and($paths)->not->toContain('/records/');
    }
});

test('S3: a 5xx still counts the source as down for the rest of the snapshot', function () {
    Sleep::fake();
    config(['esports.score_games.poller.min_interval_ms' => 0, 'esports.score_games.poller.retries' => 0]);
    app()->instance(FakeScoreSource::class, app(FixtureScorePoller::class));
    Http::fake(fn () => Http::response('down', 503));
    [$tournament, [$a, $b]] = runningScoreBoard(2);

    foreach ([$a, $b] as $index => $player) {
        $player->forceFill(['gamer_tags' => ['score-demo' => "acct-{$index}"]])->save();
        ScoreAccounts::confirm($this->game, "acct-{$index}", $player, livenessAdmin(), 'Verified.');
    }

    expect(app(ScoreLeaderboards::class)->snapshot($tournament))->toBe(['stored' => 0, 'failed' => 1])
        ->and(Http::recorded())->toHaveCount(1);
});

test('S4: an answer that drips is refused within the read deadline, and a stall mid-body means the source is down', function () {
    Sleep::fake();
    config(['esports.score_games.poller.min_interval_ms' => 0, 'esports.score_games.poller.retries' => 0, 'esports.score_games.poller.read_deadline_seconds' => 1]);
    $course = new ScoreCourse($this->game, $this->game->mode('time-trial'), 'demo-1');
    $start = CarbonImmutable::parse('2026-10-05 17:00:00');
    $ask = fn () => app(FixtureScorePoller::class)->bestFor(new ScoreAccount(1, 'acct-1'), $course, $start, $start->addDays(7));
    $json = json_encode(['records' => [['time' => 41_000, 'at' => $start->addHour()->getTimestamp()]]]);
    // As a socket that sends one byte every 100 ms: each read returns what arrived, the whole answer would take ~5 s.
    $drip = new class(Utils::streamFor($json)) implements StreamInterface
    {
        use StreamDecoratorTrait;

        public function read($length): string
        {
            usleep(100_000);

            return $this->stream->read(1);
        }
    };
    $stall = new PumpStream(fn (): never => throw new RuntimeException('Unable to read from stream'));
    $answers = [new PsrResponse(200, [], $drip), new PsrResponse(200, [], $stall)];
    Http::fake(function () use (&$answers) {
        return new FulfilledPromise(array_shift($answers));
    });
    $began = microtime(true);

    expect($ask)->toThrow(ScoreSourceUnavailable::class, 'within 1 s')
        ->and(microtime(true) - $began)->toBeLessThan(2.0)
        ->and($ask)->toThrow(fn (ScoreSourceUnavailable $e) => expect($e->sourceDown)->toBeTrue());
});

test('p20 S5: the account log keeps the deciding admin after that admin deleted the account', function () {
    $owner = User::factory()->create();
    $admin = livenessAdmin();
    ($this->finish)('d1', 'acct-del', 44_000);
    livenessStore($owner, 'acct-del');
    ScoreAccounts::confirm($this->game, 'acct-del', $owner, $admin, 'Checked by DM.');
    $pubkey = $admin->pubkey;
    app(DeleteAccount::class)($admin);
    $change = ScoreAccountChange::query()->sole();
    $html = Livewire::actingAs(livenessAdmin())->test('pages::admin.scores')->html();

    expect($change->admin_id)->toBeNull()
        ->and($change->admin_pubkey)->toBe($pubkey)
        ->and($change->to_pubkey)->toBe($owner->pubkey)
        ->and($html)->not->toContain(__('by :name', ['name' => '?']));
});

test('p07 S6: an admin signed up for an open leaderboard of the game decides no id a fellow sign-up claims', function () {
    $rival = User::factory()->create();
    $clanmate = User::factory()->create();
    $signedAdmin = livenessAdmin();
    $upcoming = Tournament::factory()->scoreDemo()->signup()->create(['starts_at' => now()->addDays(14), 'signup_closes_at' => now()->addDays(13)]);

    foreach ([$signedAdmin, $rival, $clanmate] as $user) {
        TournamentSignup::query()->create(['tournament_id' => $upcoming->id, 'user_id' => $user->id, 'name' => 'S '.$user->id, 'members' => [$user->id]]);
    }

    ($this->finish)('x1', 'acct-x', 40_000);
    livenessStore($clanmate, 'acct-x');
    livenessStore($rival, 'acct-x');

    expect(fn () => ScoreAccounts::confirm($this->game, 'acct-x', $clanmate, $signedAdmin, 'Clanmate says so.'))->toThrow(TournamentRuleViolation::class)
        ->and(ScoreAccounts::confirm($this->game, 'acct-x', $clanmate, livenessAdmin(), 'Showed the account page on stream.'))->toBe(1);
});

test('stand-in: a mode missing from a registered game still fails loudly; an unregistered game gets the stand-in and a report', function () {
    Exceptions::fake();

    expect(fn () => GameProfile::ofTournament('score-demo', 'no-such-mode'))->toThrow(InvalidArgumentException::class)
        ->and(GameProfile::ofTournament('gone-game', 'any')->isUnknown())->toBeTrue();

    Exceptions::assertReported(InvalidArgumentException::class);
});

test('S1: a board ends with the left-out line even when only an admin ends it after the review time', function () {
    [$tournament, [$attacker, $other]] = runningScoreBoard(2);
    $this->fake->record($other->id, 'demo-1', 58_000, now());
    app(ScoreLeaderboards::class)->snapshot($tournament);
    ($this->finish)('o1', 'acct-owner', 44_000);
    livenessStore($attacker, 'acct-owner');
    $this->travelTo($tournament->starts_at->addDays(8)->addMinute());

    app(ScoreLeaderboards::class)->finalize($tournament, livenessAdmin());

    expect(TournamentMatch::query()->where('tournament_id', $tournament->id)->where('bracket', 'board')->sole()->result['by'])->toBe('scores')
        ->and(ScoreAccountChange::query()->where('action', 'left_out')->count())->toBe(1);
});

test('S2: an id an entrant of an open leaderboard stored is listed before ids nobody of the league plays with', function () {
    $upcoming = Tournament::factory()->scoreDemo()->signup()->create(['starts_at' => now()->addDays(14), 'signup_closes_at' => now()->addDays(13)]);
    $entrant = User::factory()->create();
    TournamentSignup::query()->create(['tournament_id' => $upcoming->id, 'user_id' => $entrant->id, 'name' => 'Entrant', 'members' => [$entrant->id]]);
    ($this->finish)('a1', 'acct-aaa', 50_000);
    ($this->finish)('z1', 'zz-entrant', 40_000);
    livenessStore($entrant, 'zz-entrant');

    expect(array_column(ScoreAccounts::pending(5), 'account'))->toBe(['zz-entrant', 'acct-aaa']);
});
