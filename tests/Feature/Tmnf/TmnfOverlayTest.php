<?php

use App\Games\TrackmaniaNationsForever;
use App\Models\ScoreRun;
use App\Support\Tmnf\TmnfCallback;
use App\Support\Tmnf\TmnfListener;
use App\Support\Tmnf\TmnfManialinks;
use App\Support\Tmnf\TmnfOverlay;
use App\Support\Tmnf\TmnfServer;
use App\Support\Tmnf\XmlRpc;
use Carbon\CarbonImmutable;

/*
|--------------------------------------------------------------------------
| The in-game overlay: when the league sends what, to whom
|--------------------------------------------------------------------------
|
| The listener handles constructed callbacks (PlayerConnect, BeginChallenge,
| PlayerFinish in the layout of ListCallbacks.html) and marks the overlay;
| tick() sends it over a socket pair whose server end holds exactly the
| answers the test expects (true as recorded from the real server, a player
| list, a fault). tmnfSent() reads back what the league sent.
|
*/

beforeEach(function () {
    tmnfOn();
    $this->travelTo(CarbonImmutable::parse('2026-10-07 20:00:00'));
    // Weeks open only once an admin approved them (LeagueWeekDrafts).
    leagueWeeksApproved(TrackmaniaNationsForever::SLUG);
    $this->listener = app(TmnfListener::class);
});

/**
 * A GetPlayerList answer with these logins (Forever PlayerInfo structs).
 */
function tmnfPlayersAnswer(int $handle, string ...$logins): string
{
    $call = XmlRpc::encodeCall('x', [array_map(fn (string $login): array => ['Login' => $login, 'NickName' => '$f00'.$login, 'PlayerId' => 236, 'TeamId' => -1, 'SpectatorStatus' => 0, 'LadderRanking' => 0, 'Flags' => 0], $logins)]);
    $xml = str_replace(['<methodCall><methodName>x</methodName>', '</methodCall>'], ['<methodResponse>', '</methodResponse>'], $call);

    return pack('VV', strlen($xml), $handle).$xml;
}

/**
 * Ticks the overlay against a server end that answers `$answers` in turn ('true', 'fault' or a list of logins for
 * GetPlayerList); returns what the league sent: [method, params] each.
 *
 * @param  list<string|list<string>>  $answers
 * @return list<array{0: string, 1: list<mixed>}>
 */
function tmnfOverlayTick(TmnfListener $listener, array $answers = []): array
{
    $frames = [];

    foreach ($answers as $index => $answer) {
        $handle = 0x80000000 + $index;
        $frames[] = match (true) {
            is_array($answer) => tmnfPlayersAnswer($handle, ...$answer),
            $answer === 'fault' => tmnfFrame('response-fault-permission', $handle),
            default => tmnfFrame('response-chat-send', $handle),
        };
    }

    [$remote, $server] = tmnfClient(...$frames);
    $listener->tick(TmnfServer::over($remote), CarbonImmutable::now());

    return array_map(fn (array $call): array => [$call[1], $call[2]], tmnfSent($server));
}

function tmnfOverlayHandle(TmnfListener $listener, string $method, array $params): void
{
    [$remote] = tmnfClient();
    $listener->handle(new TmnfCallback($method, $params), TmnfServer::over($remote), CarbonImmutable::now());
}

/**
 * A session on A01-Race whose first showing (board for everyone, an empty server) went out long ago.
 */
function tmnfOverlaySession(TmnfListener $listener): void
{
    $listener->start(TmnfServer::over(tmnfClient(tmnfFrame('response-current-challenge', 0x80000000))[0]));
    expect(array_column(tmnfOverlayTick($listener, ['true', []]), 0))->toBe(['SendDisplayManialinkPage', 'GetPlayerList']);
    test()->travel(10)->seconds();
}

function tmnfOverlayBegin(TmnfListener $listener): void
{
    tmnfOverlayHandle($listener, 'TrackMania.BeginChallenge', [['UId' => TMNF_A01, 'Name' => 'A01-Race', 'FileName' => 'Challenges/League/A01-Race.Challenge.Gbx', 'Author' => 'Nadeo',
        'Environnement' => 'Stadium', 'AuthorTime' => 24_540, 'GoldTime' => 25_870, 'NbCheckpoints' => 3], false, false]);
}

/**
 * The texts of every label in a page, in order.
 *
 * @return list<string>
 */
function tmnfOverlayTexts(string $xml): array
{
    $document = new DOMDocument;
    expect(@$document->loadXML($xml))->toBeTrue();

    return array_map(fn (DOMElement $label): string => $label->getAttribute('text'), iterator_to_array($document->getElementsByTagName('label'), false));
}

/**
 * @return list<string>
 */
function tmnfOverlayIds(string $xml): array
{
    $document = new DOMDocument;
    $document->loadXML($xml);

    return array_map(fn (DOMElement $node): string => $node->getAttribute('id'), iterator_to_array($document->getElementsByTagName('manialink'), false));
}

test('a player who connects gets the whole widget, the footer and their own line, to their login only', function () {
    tmnfPlayer('satoshi_drives', linked: true, attributes: ['name' => 'Satoshi']);
    tmnfOverlayHandle($this->listener, 'TrackMania.PlayerConnect', ['satoshi_drives', false]);

    $sent = tmnfOverlayTick($this->listener, ['true']);

    expect($sent)->toHaveCount(1)
        ->and($sent[0][0])->toBe('SendDisplayManialinkPageToLogin')
        ->and([$sent[0][1][0], $sent[0][1][2], $sent[0][1][3]])->toBe(['satoshi_drives', 0, false])
        ->and(tmnfOverlayIds($sent[0][1][1]))->toBe([TmnfManialinks::ID_BOARD, TmnfManialinks::ID_OWN, TmnfManialinks::ID_FOOTER])
        ->and(tmnfOverlayTexts($sent[0][1][1]))->toContain('TWENTY ONE · Week 41', 'No times yet this week', 'no time this week', 'esports.einundzwanzig.space')
        ->and(tmnfOverlayTick($this->listener))->toBe([]);
});

test('a new track shows board and footer to everyone and each player their own line', function () {
    tmnfOverlayBegin($this->listener);

    $sent = tmnfOverlayTick($this->listener, ['true', ['satoshi_drives', 'hal_finney'], 'true', 'true']);

    expect(array_column($sent, 0))->toBe(['SendDisplayManialinkPage', 'GetPlayerList', 'SendDisplayManialinkPageToLogin', 'SendDisplayManialinkPageToLogin'])
        ->and(array_slice($sent[0][1], 1))->toBe([0, false])
        ->and(tmnfOverlayIds($sent[0][1][0]))->toBe([TmnfManialinks::ID_BOARD, TmnfManialinks::ID_FOOTER])
        ->and($sent[1][1])->toBe([255, 0, 1])
        ->and([$sent[2][1][0], $sent[3][1][0]])->toBe(['satoshi_drives', 'hal_finney'])
        ->and(tmnfOverlayIds($sent[2][1][1]))->toBe([TmnfManialinks::ID_OWN])
        ->and(tmnfOverlayTexts($sent[3][1][1]))->toContain('not linked yet');
});

test('a finish that counted: the board for everyone, the finisher\'s own line and the note, removed after three seconds', function () {
    tmnfOverlaySession($this->listener);
    tmnfPlayer('satoshi_drives', linked: true, attributes: ['name' => '$o$f00Satoshi']);
    tmnfOverlayHandle($this->listener, 'TrackMania.PlayerFinish', [236, 'satoshi_drives', 25_912]);

    expect(ScoreRun::query()->sole()->value)->toBe(25_912);

    $sent = tmnfOverlayTick($this->listener, ['true', ['satoshi_drives'], 'true', 'true']);

    expect(array_column($sent, 0))->toBe(['SendDisplayManialinkPage', 'GetPlayerList', 'SendDisplayManialinkPageToLogin', 'SendDisplayManialinkPageToLogin'])
        ->and(tmnfOverlayTexts($sent[0][1][0]))->toContain('1.', 'Satoshi', '0:25.912')
        ->and(tmnfOverlayTexts($sent[2][1][1]))->toBe(['You', '#1', '0:25.912'])
        ->and($sent[3][1][0])->toBe('satoshi_drives')
        ->and(tmnfOverlayIds($sent[3][1][1]))->toBe([TmnfManialinks::ID_NOTE])
        ->and(tmnfOverlayTexts($sent[3][1][1]))->toBe([TmnfOverlay::NOTE_BEST]);

    $this->travel(2)->seconds();
    expect(tmnfOverlayTick($this->listener))->toBe([]);

    $this->travel(1)->seconds();
    $removed = tmnfOverlayTick($this->listener, ['true']);

    expect($removed)->toHaveCount(1)
        ->and($removed[0][1][0])->toBe('satoshi_drives')
        ->and($removed[0][1][1])->toContain('<manialink id="'.TmnfManialinks::ID_NOTE.'"></manialink>');
});

test('a burst of finishes updates the board for everyone at most once in the debounce time, the rest trailing', function () {
    tmnfOverlaySession($this->listener);
    tmnfPlayer('satoshi_drives', linked: true, attributes: ['name' => 'Satoshi']);
    tmnfPlayer('hal_finney', linked: true, attributes: ['name' => 'Hal']);
    tmnfOverlayHandle($this->listener, 'TrackMania.PlayerFinish', [236, 'satoshi_drives', 26_000]);
    tmnfOverlayTick($this->listener, ['true', [], 'true', 'true']);

    $this->travel(1)->seconds();
    tmnfOverlayHandle($this->listener, 'TrackMania.PlayerFinish', [237, 'hal_finney', 25_500]);
    tmnfOverlayHandle($this->listener, 'TrackMania.PlayerFinish', [236, 'satoshi_drives', 26_400]);
    $burst = tmnfOverlayTick($this->listener, ['true', 'true', 'true']);

    $this->travel(2)->seconds();
    tmnfOverlayHandle($this->listener, 'TrackMania.PlayerFinish', [237, 'hal_finney', 25_400]);
    $later = tmnfOverlayTick($this->listener, ['true', 'true', 'true']);

    $this->travel(1)->seconds();
    $quiet = tmnfOverlayTick($this->listener);

    $this->travel(1)->seconds();
    $trailing = tmnfOverlayTick($this->listener, ['true', ['satoshi_drives', 'hal_finney'], 'true', 'true']);

    $this->travel(10)->seconds();
    $removed = tmnfOverlayTick($this->listener, ['true']);

    // Only own lines and notes inside the debounce time: Hal's best and own line, Satoshi's slower run (own line, no note).
    expect(array_column($burst, 0))->not->toContain('SendDisplayManialinkPage')
        ->and(array_map(fn (array $call): array => [$call[1][0], tmnfOverlayIds($call[1][1])], $burst))->toBe([
            ['hal_finney', [TmnfManialinks::ID_OWN]], ['satoshi_drives', [TmnfManialinks::ID_OWN]], ['hal_finney', [TmnfManialinks::ID_NOTE]],
        ])
        ->and(array_map(fn (array $call): array => [$call[1][0], tmnfOverlayIds($call[1][1])], $later))->toBe([
            ['hal_finney', [TmnfManialinks::ID_OWN]], ['hal_finney', [TmnfManialinks::ID_NOTE]], ['satoshi_drives', [TmnfManialinks::ID_NOTE]],
        ])
        ->and($quiet)->toBe([])
        ->and(array_column($trailing, 0))->toBe(['SendDisplayManialinkPage', 'GetPlayerList', 'SendDisplayManialinkPageToLogin', 'SendDisplayManialinkPageToLogin'])
        ->and(array_values(array_filter(tmnfOverlayTexts($trailing[0][1][0]), fn (string $text): bool => in_array($text, ['Hal', 'Satoshi', '0:25.400', '0:26.000'], true))))
        ->toBe(['Hal', '0:25.400', 'Satoshi', '0:26.000'])
        ->and(array_map(fn (array $call): string => $call[1][0], $removed))->toBe(['hal_finney'])
        ->and(tmnfOverlayTick($this->listener))->toBe([]);
});

test('a finish of a login nobody linked gets the note only: no board, and the driver is on none', function () {
    tmnfOverlaySession($this->listener);
    tmnfPlayer('satoshi_drives', linked: true, attributes: ['name' => 'Satoshi']);
    tmnfOverlayHandle($this->listener, 'TrackMania.PlayerFinish', [236, 'satoshi_drives', 26_000]);
    tmnfOverlayTick($this->listener, ['true', [], 'true', 'true']);

    $this->travel(10)->seconds();
    tmnfOverlayTick($this->listener, ['true']);
    tmnfOverlayHandle($this->listener, 'TrackMania.PlayerFinish', [240, 'anon_driver', 24_900]);
    $sent = tmnfOverlayTick($this->listener, ['true']);

    expect($sent)->toHaveCount(1)
        ->and($sent[0][1][0])->toBe('anon_driver')
        ->and(tmnfOverlayTexts($sent[0][1][1]))->toBe([TmnfOverlay::NOTE_NOT_LINKED]);

    tmnfOverlayHandle($this->listener, 'TrackMania.PlayerConnect', ['anon_driver', false]);
    $widget = tmnfOverlayTick($this->listener, ['true']);

    expect(tmnfOverlayTexts($widget[0][1][1]))->toContain('Satoshi', 'not linked yet')
        ->not->toContain('anon_driver', '0:24.900');
});

test('a linked player without a profile name is "Player" on the board, never their npub', function () {
    tmnfOverlaySession($this->listener);
    $player = tmnfPlayer('satoshi_drives', linked: true, attributes: ['name' => '']);
    tmnfOverlayHandle($this->listener, 'TrackMania.PlayerFinish', [236, 'satoshi_drives', 26_000]);

    $board = tmnfOverlayTick($this->listener, ['true', [], 'true', 'true'])[0][1][0];

    expect(tmnfOverlayTexts($board))->toContain('Player')
        ->and($board)->not->toContain('npub1')->not->toContain(substr($player->npub, 0, 12));
});

test('a fault for one login (it just left) is reported and the next page is still sent', function () {
    tmnfOverlayHandle($this->listener, 'TrackMania.PlayerConnect', ['gone_driver', false]);
    tmnfOverlayHandle($this->listener, 'TrackMania.PlayerConnect', ['satoshi_drives', false]);

    $sent = tmnfOverlayTick($this->listener, ['fault', 'true']);

    expect(array_map(fn (array $call): string => $call[1][0], $sent))->toBe(['gone_driver', 'satoshi_drives']);
});

test('switched off, the overlay sends nothing at all', function (string $switch) {
    config([$switch => false]);
    tmnfPlayer('satoshi_drives', linked: true, attributes: ['name' => 'Satoshi']);

    $this->listener->start(TmnfServer::over(tmnfClient(tmnfFrame('response-current-challenge', 0x80000000))[0]));
    tmnfOverlayBegin($this->listener);
    tmnfOverlayHandle($this->listener, 'TrackMania.PlayerConnect', ['satoshi_drives', false]);
    tmnfOverlayHandle($this->listener, 'TrackMania.PlayerFinish', [236, 'satoshi_drives', 26_000]);
    tmnfOverlayHandle($this->listener, 'TrackMania.PlayerFinish', [240, 'anon_driver', 24_900]);
    $sent = tmnfOverlayTick($this->listener);
    $this->travel(10)->seconds();

    expect($sent)->toBe([])
        ->and(tmnfOverlayTick($this->listener))->toBe([])
        ->and(ScoreRun::query()->count())->toBe(2);
})->with(['the overlay switch' => ['esports.tmnf.overlay.enabled']]);
