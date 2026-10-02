<?php

use App\Enums\NotificationKind;
use App\Games\TrackmaniaNationsForever;
use App\Models\Admin;
use App\Models\LeagueWeek;
use App\Models\User;
use App\Support\Stacker\BlockfillWeeks;
use App\Support\Tmnf\GbxRemote;
use App\Support\Tmnf\GbxUnavailable;
use App\Support\Tmnf\TmnfConnector;
use App\Support\Tmnf\TmnfServer;
use App\Support\Tmnf\TmnfTrackSwitch;
use App\Support\Tmnf\TmnfWeeks;
use Carbon\CarbonImmutable;
use Illuminate\Support\Sleep;

/*
|--------------------------------------------------------------------------
| The listener puts the server on the approved week's track
|--------------------------------------------------------------------------
|
| TmnfTrackSwitch against the answers Nadeo's dedicated server gave on
| 2026-10-02 (tests/Fixtures/tmnf/: GetChallengeList, SetTimeAttackLimit,
| InsertChallenge, ChooseNextChallenge, NextChallenge, then
| GetCurrentChallengeInfo and GetTimeAttackLimit on A02-Race, RemoveChallenge,
| and the "Challenge already added." fault). What the league sends is read
| back from the server end of the socket pair. The real server switching to
| A02-Race: TmnfServerIntegrationTest (group `tmnf`).
|
| Week 42 of 2026 starts Monday 2026-10-12 00:00 Berlin (2026-10-11 22:00
| UTC); its plan, approved on Friday, puts it on A02-Race at 10 minutes a round.
|
*/

const SWITCH_A02 = 'JwKdDsOUh4L9_eYyRsdiA2o1fW1';
const SWITCH_A02_FILE = 'Campaigns\Nations\White\A02-Race.Challenge.Gbx';

beforeEach(function () {
    tmnfOn();
    $this->admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $this->admin->pubkey]);
    $this->plan = LeagueWeek::query()->forceCreate([
        'game' => TrackmaniaNationsForever::SLUG,
        'starts_at' => CarbonImmutable::parse('2026-10-11 22:00:00'),
        'settings' => ['track' => SWITCH_A02, 'time_limit_minutes' => 10],
        'approved_at' => CarbonImmutable::parse('2026-10-09 15:00:00'),
        'notified_at' => CarbonImmutable::parse('2026-10-08 10:00:00'),
    ]);
    // Monday 00:00:05 Berlin: the week is due.
    $this->travelTo(CarbonImmutable::parse('2026-10-11 22:00:05'));
});

/**
 * A session whose server end answers with `$answers` (recorded frame names), in order, from the second call handle on
 * (the first is GetCurrentChallengeInfo, answered by `$current`).
 *
 * @param  list<string>  $answers
 * @return array{0: TmnfServer, 1: resource}
 */
function switchSession(string $current, array $answers): array
{
    $handle = 0x80000000;
    $frames = [tmnfFrame($current, $handle++)];

    foreach ($answers as $answer) {
        $frames[] = tmnfFrame($answer, $handle++);
    }

    [$remote, $server] = tmnfClient(...$frames);

    return [TmnfServer::over($remote), $server];
}

/**
 * The calls the league sent: [method, params] each.
 *
 * @param  resource  $server
 * @return list<array{0: string, 1: list<mixed>}>
 */
function switchCalls($server): array
{
    return array_map(fn (array $call): array => [$call[1], $call[2]], tmnfSent($server));
}

test('the week is due on another track: the switch goes out with the stock path of A02-Race, and the week does not open before the server is on it', function () {
    [$session, $end] = switchSession('response-current-challenge', [
        'response-set-time-attack-limit', 'response-challenge-list', 'response-insert-challenge', 'response-choose-next-challenge', 'response-next-challenge',
    ]);

    $line = app(TmnfTrackSwitch::class)->sync($session);

    expect(switchCalls($end))->toBe([
        ['GetCurrentChallengeInfo', []],
        ['SetTimeAttackLimit', [600_000]],
        ['GetChallengeList', [200, 0]],
        ['InsertChallenge', [SWITCH_A02_FILE]],
        ['ChooseNextChallenge', [SWITCH_A02_FILE]],
        ['NextChallenge', []],
    ])->and($line)->toBe('switching the server to A02-Race ('.SWITCH_A02.', '.SWITCH_A02_FILE.'), 10 min a round')
        // Approved and due, but not seen on its track yet: the week does not open, not even when asked to.
        ->and(app(TmnfWeeks::class)->open())->toBeNull()
        ->and($this->plan->refresh()->track_ready_at)->toBeNull();
});

test('on the week\'s track with its limit: the other track leaves the selection and the week opens on A02-Race', function () {
    [$session, $end] = switchSession('response-current-challenge-a02', ['response-time-attack-limit-a02', 'response-challenge-list-two', 'response-remove-challenge']);

    $line = app(TmnfTrackSwitch::class)->sync($session);
    $week = app(TmnfWeeks::class)->current();

    expect(switchCalls($end))->toBe([
        ['GetCurrentChallengeInfo', []],
        ['GetTimeAttackLimit', []],
        ['GetChallengeList', [200, 0]],
        // The A01 the server played before, by the file name the server itself gave.
        ['RemoveChallenge', ['Campaigns/Nations/White/A01-Race.Challenge.Gbx']],
    ])->and($line)->toBe('on A02-Race ('.SWITCH_A02.'): TMNF Week 42, 2026 is open')
        ->and($week->score_course)->toBe(SWITCH_A02)
        ->and($week->starts_at->toDateTimeString())->toBe('2026-10-11 22:01:00')
        ->and($this->plan->refresh()->tournament_id)->toBe($week->id)
        ->and($this->plan->track_ready_at->toDateTimeString())->toBe('2026-10-11 22:00:05');
});

test('a week not approved yet, or not due yet, leaves the server as it is', function () {
    $this->plan->forceFill(['approved_at' => null])->save();
    [$session, $end] = switchSession('response-current-challenge', []);
    $unapproved = app(TmnfTrackSwitch::class)->sync($session);

    $this->plan->forceFill(['approved_at' => now()->subDay()])->save();
    $this->travelTo(CarbonImmutable::parse('2026-10-11 21:59:59'));
    $early = app(TmnfTrackSwitch::class)->sync($session);

    expect([$unapproved, $early])->toBe([null, null])
        ->and(switchCalls($end))->toBe([])
        ->and(LeagueWeek::query()->whereNotNull('tournament_id')->count())->toBe(0);
});

test('the server refuses the switch: no week opens, and the admins hear it once in the bell after two minutes', function () {
    $refused = function (): ?string {
        [$session] = switchSession('response-current-challenge', ['response-set-time-attack-limit', 'response-challenge-list', 'response-fault-insert-twice']);

        return app(TmnfTrackSwitch::class)->sync($session);
    };

    $first = $refused();
    $quietBell = $this->admin->notifications()->count();
    $this->travelTo(CarbonImmutable::parse('2026-10-11 22:02:05'));
    $second = $refused();
    $refused();

    expect($first)->toBe('switch to A02-Race refused: Challenge already added.')
        ->and($second)->toBe($first)
        ->and($quietBell)->toBe(0)
        ->and($this->admin->notifications()->where('type', NotificationKind::LeagueWeekApproval->value)->pluck('data')->map(fn (array $data): string => $data['title'].' / '.$data['body'])->all())
        ->toBe(['The TMNF server is not on the track of week 42 / The week starts only once the server runs its track; the listener keeps trying. Reason: Challenge already added.'])
        ->and(app(TmnfWeeks::class)->current())->toBeNull()
        ->and($this->plan->refresh()->warned_at?->toDateTimeString())->toBe('2026-10-11 22:02:05');
});

test('tmnf:listen switches the track right after it connected; a session that breaks and a server it cannot reach warn the admins once, and it tries again with the backoff', function () {
    Sleep::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-11 22:05:00'));
    $handle = 0x80000001;
    $switch = array_map(function (string $name) use (&$handle): string {
        return tmnfFrame($name, $handle++);
    }, ['response-current-challenge', 'response-set-time-attack-limit', 'response-challenge-list', 'response-insert-challenge', 'response-choose-next-challenge', 'response-next-challenge']);
    $sessions = [$switch, 'down'];

    app()->instance(TmnfConnector::class, new class($sessions) extends TmnfConnector
    {
        /** @param list<list<string>|string> $sessions */
        public function __construct(private array $sessions) {}

        public function open(): TmnfServer
        {
            $frames = array_shift($this->sessions);

            if (! is_array($frames)) {
                throw new GbxUnavailable('Cannot reach the TMNF server (test).');
            }

            [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            // The answer to start(), then to the track check; after that the session meets a closed connection.
            fwrite($server, tmnfFrame('greeting').tmnfFrame('response-current-challenge', 0x80000000).implode('', $frames));
            stream_socket_shutdown($server, STREAM_SHUT_WR);
            $GLOBALS['tmnfServerEnds'][] = $server;

            return TmnfServer::over(GbxRemote::over($client, 2.0));
        }
    });

    $this->artisan('tmnf:listen', ['--attempts' => 2])
        ->expectsOutputToContain('Connected to the TMNF server, track A01-Race.')
        ->expectsOutputToContain('switching the server to A02-Race ('.SWITCH_A02.', '.SWITCH_A02_FILE.'), 10 min a round')
        ->expectsOutputToContain('TMNF server unavailable')
        ->assertSuccessful();

    expect($this->admin->notifications()->where('type', NotificationKind::LeagueWeekApproval->value)->count())->toBe(1)
        ->and($this->admin->notifications()->sole()->data['title'])->toBe('The TMNF server is not on the track of week 42')
        ->and(app(TmnfWeeks::class)->current())->toBeNull();
});

test('the weeks command tells the admins when the week waits for a server nobody switched (the listener does not run)', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-11 23:00:00'));
    $this->artisan('tmnf:weeks')->assertSuccessful();
    $this->artisan('tmnf:weeks')->assertSuccessful();

    expect($this->admin->notifications()->where('type', NotificationKind::LeagueWeekApproval->value)->pluck('data')->map(fn (array $data): string => $data['body'])->all())
        ->toBe(['The week starts only once the server runs its track; the listener keeps trying. Reason: the listener (tmnf:listen) has not put the server on the track'])
        ->and(BlockfillWeeks::startOf(now())->toDateTimeString())->toBe('2026-10-11 22:00:00')
        ->and(app(TmnfWeeks::class)->current())->toBeNull();
});
