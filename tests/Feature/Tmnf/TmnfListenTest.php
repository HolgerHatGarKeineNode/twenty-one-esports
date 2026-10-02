<?php

use App\Games\GameRegistry;
use App\Models\ScoreAccountChange;
use App\Models\ScoreAccountClaim;
use App\Models\ScoreRun;
use App\Models\ScoreServer;
use App\Models\User;
use App\Support\Tmnf\GbxRemote;
use App\Support\Tmnf\GbxUnavailable;
use App\Support\Tmnf\TmnfCallback;
use App\Support\Tmnf\TmnfConnector;
use App\Support\Tmnf\TmnfLinks;
use App\Support\Tmnf\TmnfListener;
use App\Support\Tmnf\TmnfServer;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Sleep;

/*
|--------------------------------------------------------------------------
| tmnf:listen: a finish becomes a run, a chat code links a login
|--------------------------------------------------------------------------
|
| The command runs against a session over a socket pair that plays the
| server's side from tests/Fixtures/tmnf (plan "Trackmania und Restposten",
| P1): GetCurrentChallengeInfo as recorded from the real server, then the
| callbacks of the test. When the frames run out the fake server closes,
| the session breaks and `--attempts=1` ends the command.
|
*/

beforeEach(function () {
    tmnfOn();
    $this->travelTo(now()->setDate(2026, 10, 7)->setTime(20, 0));
});

/**
 * Binds a connector whose sessions play `$sessions` (one list of frames each, after the track answer); a string entry
 * makes that connection fail. Returns the server ends, so a test can read what the listener sent.
 *
 * @param  list<list<string>|string>  $sessions
 * @return ArrayObject<int, resource>
 */
function tmnfServerPlays(array $sessions): ArrayObject
{
    $ends = new ArrayObject;

    app()->instance(TmnfConnector::class, new class($sessions, $ends) extends TmnfConnector
    {
        /**
         * @param  list<list<string>|string>  $sessions
         * @param  ArrayObject<int, resource>  $ends
         */
        public function __construct(private array $sessions, private ArrayObject $ends) {}

        public function open(): TmnfServer
        {
            $frames = array_shift($this->sessions);

            if (! is_array($frames)) {
                throw new GbxUnavailable('Cannot reach the TMNF server (test).');
            }

            [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            fwrite($server, tmnfFrame('greeting').tmnfFrame('response-current-challenge', 0x80000000).implode('', $frames));
            // The fake server sends nothing more: once the frames are read, the session reads a closed connection.
            stream_socket_shutdown($server, STREAM_SHUT_WR);
            $this->ends[] = $server;

            return TmnfServer::over(GbxRemote::over($client, 2.0));
        }
    });

    return $ends;
}

test('a finish of a linked login becomes a verified server run on the current track, in milliseconds', function () {
    $player = tmnfPlayer('satoshi_drives', linked: true);
    tmnfServerPlays([[tmnfFrame('constructed-player-checkpoint'), tmnfFrame('constructed-player-finish')]]);

    $this->artisan('tmnf:listen', ['--attempts' => 1])
        ->expectsOutputToContain('Connected to the TMNF server, track A01-Race.')
        ->expectsOutputToContain('finish 25912 ms on A01-Race: stored')
        ->assertSuccessful();

    $run = ScoreRun::query()->sole();

    expect($run->only(['user_id', 'game', 'mode', 'course', 'value', 'unit', 'source', 'account_id']))->toBe([
        'user_id' => $player->id, 'game' => 'tmnf', 'mode' => 'time-attack', 'course' => TMNF_A01, 'value' => 25_912, 'unit' => 'ms', 'source' => 'server', 'account_id' => 'satoshi_drives',
    ])->and($run->verified_at)->not->toBeNull()
        ->and($run->achieved_at->toDateTimeString())->toBe('2026-10-07 20:00:00')
        ->and($run->score_server_id)->toBe(ScoreServer::query()->where('game', 'tmnf')->sole()->id)
        ->and($run->raw)->toMatchArray(['player_uid' => 236, 'track' => 'A01-Race', 'author_ms' => 24_540]);
});

test('a finish of a login nobody linked stays pending: no player, the login kept privately', function () {
    tmnfPlayer('satoshi_drives');
    tmnfServerPlays([[tmnfFrame('constructed-player-finish')]]);

    $this->artisan('tmnf:listen', ['--attempts' => 1])->expectsOutputToContain('pending (login not linked)')->assertSuccessful();

    expect(ScoreRun::query()->sole()->only(['user_id', 'account_id', 'value']))->toBe(['user_id' => null, 'account_id' => 'satoshi_drives', 'value' => 25_912]);
});

test('a finish with time 0 (the player retired) and a checkpoint store nothing', function () {
    tmnfPlayer('satoshi_drives', linked: true);
    tmnfServerPlays([[tmnfFrame('constructed-player-checkpoint'), tmnfFrame('constructed-player-finish-retire')]]);

    $this->artisan('tmnf:listen', ['--attempts' => 1])->assertSuccessful();

    expect(ScoreRun::query()->count())->toBe(0);
});

test('a finish counts for the track the server began last (BeginChallenge)', function () {
    tmnfPlayer('satoshi_drives', linked: true);
    $other = tmnfCallbackFrame('TrackMania.BeginChallenge', [['UId' => 'OtherTrackUid0123456789ab', 'Name' => 'A02-Race', 'FileName' => 'x', 'Author' => 'Nadeo', 'Environnement' => 'Stadium', 'AuthorTime' => 22_000, 'GoldTime' => 23_000, 'NbCheckpoints' => 2], false, false]);
    tmnfServerPlays([[$other, tmnfFrame('constructed-player-finish')]]);

    $this->artisan('tmnf:listen', ['--attempts' => 1])->expectsOutputToContain('track A02-Race (OtherTrackUid0123456789ab)')->assertSuccessful();

    expect(ScoreRun::query()->sole()->course)->toBe('OtherTrackUid0123456789ab');
});

test('the server\'s own chat lines and ordinary chat link nothing', function () {
    $player = tmnfPlayer('satoshi_drives');
    TmnfLinks::codeFor($player);
    tmnfServerPlays([[tmnfFrame('callback-player-chat-server'), tmnfCallbackFrame('TrackMania.PlayerChat', [236, 'satoshi_drives', 'gg wp', false])]]);

    $this->artisan('tmnf:listen', ['--attempts' => 1])->assertSuccessful();

    expect(ScoreAccountClaim::query()->count())->toBe(0);
});

test('the code typed by the stored login links it and hands its pending finishes over, even when the reply fails', function () {
    $player = tmnfPlayer('Satoshi_Drives');
    ScoreRun::query()->create(['game' => 'tmnf', 'mode' => 'time-attack', 'course' => TMNF_A01, 'value' => 26_100, 'unit' => 'ms', 'source' => 'server',
        'achieved_at' => now()->subHour(), 'verified_at' => now()->subHour(), 'account_id' => 'satoshi_drives']);
    $code = TmnfLinks::codeFor($player);
    // A canned server cannot answer after a request: the session ends once the chat line is read, so the reply to the
    // player fails. The link is made before the reply, so it holds; the reply itself is the next test.
    tmnfServerPlays([[tmnfCallbackFrame('TrackMania.PlayerChat', [236, 'satoshi_drives', "/link {$code}", false])]]);

    $this->artisan('tmnf:listen', ['--attempts' => 1])->expectsOutputToContain('TMNF server unavailable')->assertSuccessful();

    expect(ScoreAccountClaim::query()->sole()->only(['game', 'account_id', 'user_id', 'confirmed_by_id']))->toBe(['game' => 'tmnf', 'account_id' => 'satoshi_drives', 'user_id' => $player->id, 'confirmed_by_id' => null])
        ->and($player->refresh()->gamer_tags['tmnf'])->toBe('satoshi_drives')
        ->and(ScoreRun::query()->sole()->user_id)->toBe($player->id)
        ->and(ScoreAccountChange::query()->sole()->only(['action', 'to_user_id', 'admin_id', 'runs_moved']))->toBe(['action' => 'link', 'to_user_id' => $player->id, 'admin_id' => null, 'runs_moved' => 1])
        ->and(TmnfLinks::codeFor($player->refresh()))->toBeNull();
});

test('a linking chat line is answered in the chat of that login only', function (string $text, string $reply) {
    $player = tmnfPlayer('satoshi_drives');
    $code = TmnfLinks::codeFor($player);
    [$remote, $server] = tmnfClient(tmnfFrame('response-chat-send', 0x80000000));

    $line = app(TmnfListener::class)->handle(new TmnfCallback('TrackMania.PlayerChat', [236, 'satoshi_drives', str_replace('CODE', (string) $code, $text), false]), TmnfServer::over($remote));

    expect($line)->toStartWith('link by chat: ')
        ->and(tmnfSent($server))->toBe([[0x80000000, 'ChatSendServerMessageToLogin', [$reply, 'satoshi_drives']]]);
})->with([
    'linked' => ['link CODE', '$0f0Linked: your times on this server now count for your league account.'],
    'unknown' => ['link ZZZZZZ', '$f80Unknown or expired code. Get a new one on the site.'],
]);

test('a code typed by another login, an unknown code and a login linked to somebody else link nothing', function (string $typedBy, string $code, string $result) {
    $player = tmnfPlayer('satoshi_drives');
    $issued = TmnfLinks::codeFor($player);
    $other = tmnfPlayer('hal_finney', linked: true);
    // The same login stored by a second player, already linked to the first one in the "taken" case.
    if ($result === 'taken') {
        ScoreAccountClaim::query()->where('user_id', $other->id)->update(['account_id' => 'satoshi_drives']);
    }

    expect(TmnfLinks::fromChat($typedBy, 'link '.($code === 'issued' ? $issued : $code)))->toBe($result)
        ->and(ScoreAccountClaim::query()->where('user_id', $player->id)->exists())->toBeFalse();
})->with([
    'another login' => ['hal_finney', 'issued', 'login'],
    'unknown code' => ['satoshi_drives', 'ZZZZZZ', 'unknown'],
    'linked to somebody else' => ['satoshi_drives', 'issued', 'taken'],
]);

test('the code runs out after its minutes', function () {
    $player = tmnfPlayer('satoshi_drives');
    $code = TmnfLinks::codeFor($player);

    $this->travel((int) config('esports.tmnf.link.code_minutes') + 1)->minutes();

    expect(TmnfLinks::fromChat('satoshi_drives', "link {$code}"))->toBe('unknown');
});

test('no code without a stored login, and a stored login gets one code while it runs', function () {
    $without = User::factory()->create();
    $player = tmnfPlayer('satoshi_drives');

    expect(TmnfLinks::codeFor($without))->toBeNull()
        ->and(TmnfLinks::codeFor($player))->toMatch('/^[A-Z2-9]{6}$/')
        ->and(TmnfLinks::codeFor($player))->toBe(TmnfLinks::codeFor($player));
});

test('the listener reconnects after a backoff when the server is away', function () {
    Sleep::fake();
    tmnfPlayer('satoshi_drives', linked: true);
    tmnfServerPlays(['down', [tmnfFrame('constructed-player-finish')]]);

    $this->artisan('tmnf:listen', ['--attempts' => 2])
        ->expectsOutputToContain('TMNF server unavailable (Cannot reach the TMNF server (test).); trying again in 1 s.')
        ->expectsOutputToContain('finish 25912 ms on A01-Race: stored')
        ->assertSuccessful();

    Sleep::assertSequence([Sleep::for(1)->seconds()]);
});

test('tmnf:restart during a session stops the listener after the callback at hand, before it connects again', function () {
    tmnfPlayer('satoshi_drives', linked: true);
    $ends = tmnfServerPlays([[tmnfFrame('constructed-player-finish')], [tmnfFrame('constructed-player-finish')]]);
    // The deploy runs tmnf:restart while the listener handles this finish.
    ScoreRun::created(fn () => Artisan::call('tmnf:restart'));

    $this->artisan('tmnf:listen', ['--attempts' => 2])
        ->expectsOutputToContain('finish 25912 ms on A01-Race: stored')
        ->expectsOutputToContain('Restart requested (tmnf:restart): stopping after the callback at hand.')
        ->assertSuccessful();

    expect($ends)->toHaveCount(1)
        ->and(ScoreRun::query()->count())->toBe(1);
});

test('tmnf:restart while the server is away stops the listener instead of connecting again', function () {
    Sleep::fake();
    Sleep::whenFakingSleep(fn () => Artisan::call('tmnf:restart'));
    $ends = tmnfServerPlays(['down', [tmnfFrame('constructed-player-finish')]]);

    $this->artisan('tmnf:listen', ['--attempts' => 2])
        ->expectsOutputToContain('Restart requested (tmnf:restart)')
        ->assertSuccessful();

    expect($ends)->toHaveCount(0);
});

test('a restart requested before the listener started does not stop it', function () {
    $this->artisan('tmnf:restart')->expectsOutputToContain('The TMNF listener stops after its current callback')->assertSuccessful();
    tmnfPlayer('satoshi_drives', linked: true);
    tmnfServerPlays([[tmnfFrame('constructed-player-finish')]]);

    $this->artisan('tmnf:listen', ['--attempts' => 1])
        ->expectsOutputToContain('finish 25912 ms on A01-Race: stored')
        ->doesntExpectOutputToContain('Restart requested')
        ->assertSuccessful();
});

test('switched off, the listener exits at once and stores nothing', function () {
    config(['esports.tmnf.enabled' => false]);
    app()->forgetInstance(GameRegistry::class);
    tmnfServerPlays([[tmnfFrame('constructed-player-finish')]]);

    $this->artisan('tmnf:listen', ['--attempts' => 1])->expectsOutputToContain('TMNF is switched off')->assertSuccessful();

    expect(ScoreRun::query()->count())->toBe(0)
        ->and(ScoreServer::query()->count())->toBe(0)
        ->and(TmnfLinks::fromChat('satoshi_drives', 'link ABCDEF'))->toBe('off');
});
