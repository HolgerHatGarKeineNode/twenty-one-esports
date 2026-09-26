<?php

use App\Enums\ChessEndReason;
use App\Models\ChessGame;
use App\Models\User;
use App\Support\TwentyOne\Stream\SceneRenderer;
use App\Support\TwentyOne\Stream\SceneSource;
use App\Support\TwentyOne\Stream\StreamTexts;

test('the scene shows the oldest live game with escaped, shortened public names', function () {
    $older = ChessGame::factory()->create([
        'white_id' => User::factory()->create(['name' => 'Pleb <script>alert(1)</script>']),
        'black_id' => User::factory()->create(['name' => str_repeat('Satoshi', 6)]),
    ]);
    ChessGame::factory()->create();
    ChessGame::factory()->daily()->create();
    $source = app(SceneSource::class);

    $game = $source->liveGame();
    $scene = $source->scene($game, (int) now()->getTimestampMs());
    $svg = SceneRenderer::fromConfig()->svg($scene);

    expect($game->id)->toBe($older->id)
        ->and($scene['mode'])->toBe('LIVE · CHESS BLITZ 5+3 · CASUAL')
        ->and($scene['result'])->toBeNull()
        ->and($svg)->not->toContain('<script>')
        // 25 characters at most: 24 and an ellipsis, escaped as text.
        ->and($svg)->toContain('>Pleb &lt;script&gt;alert(1)&lt;/s…<')
        ->and($svg)->toContain('>'.str_repeat('Satoshi', 3).'Sat…<')
        // Daily games count as live too.
        ->and($svg)->toContain('>0 games played · 3 live · 0 clans<');
});

test('after the game the scene shows the result, and it is gone after the hysteresis', function () {
    $game = ChessGame::factory()->finished('0-1', ChessEndReason::Timeout)->create();
    $source = app(SceneSource::class);

    expect($source->liveGame())->toBeNull()
        ->and($source->scene($source->endedGame(60), (int) now()->getTimestampMs())['result'])->toBe('0-1 · White ran out of time');

    $this->travel(61)->seconds();

    expect($source->endedGame(60))->toBeNull()
        ->and($game->id)->toBeInt();
});

test('the scene shows a name with line breaks and bidi controls as plain text', function () {
    $game = ChessGame::factory()->create(['white_id' => User::factory()->create(['name' => "Mallory\u{202E}gnp\nline two"])]);
    $source = app(SceneSource::class);

    $svg = SceneRenderer::fromConfig()->svg($source->scene($game, (int) now()->getTimestampMs()));

    expect($svg)->toContain('>Mallory gnp line two<');
});

test('a blitz game has priority, otherwise the daily game with the most recent move', function () {
    $nowMs = (int) now()->getTimestampMs();
    ChessGame::factory()->daily()->create(['turn_started_ms' => $nowMs - 3_600_000]);
    $recent = ChessGame::factory()->daily()->create(['turn_started_ms' => $nowMs - 60_000]);
    ChessGame::factory()->daily()->create(['turn_started_ms' => $nowMs - 7_200_000]);
    $source = app(SceneSource::class);

    expect($source->liveGame()->id)->toBe($recent->id);

    $blitz = ChessGame::factory()->create();

    expect($source->liveGame()->id)->toBe($blitz->id);
});

test('a daily game renders as correspondence with the time left for the move', function () {
    $nowMs = (int) now()->getTimestampMs();
    // White to move with 23:59:59 left (the widest daily clock); Black's stored time stays as it is.
    $game = ChessGame::factory()->daily()->create(['deadline_ms' => $nowMs + 86_399_000, 'black_ms' => 3_000]);
    $source = app(SceneSource::class);

    $scene = $source->scene($source->liveGame(), $nowMs);
    $svg = SceneRenderer::fromConfig()->svg($scene);
    preg_match_all('/<text x="([\d.]+)" y="\d+" font-family="Unbounded" font-weight="800" font-size="([\d.]+)" fill="#\w+" text-anchor="middle">([\d:])</', $svg, $digits);

    expect($scene['mode'])->toBe('LIVE · CHESS CORRESPONDENCE · CASUAL')
        ->and(mb_strlen($scene['mode']))->toBeLessThanOrEqual(40)
        ->and($scene['white'])->toMatchArray(['clockMs' => 86_399_000, 'toMove' => true])
        ->and($scene['black'])->toMatchArray(['clockMs' => 3_000, 'toMove' => false])
        // One <text> per clock character, black's card first; h:mm:ss scaled into the card.
        ->and(implode('', $digits[3]))->toBe('0:03'.'23:59:59')
        // Right edge of the last digit (75 px advance at 80 px) inside the card's inner edge.
        ->and((float) end($digits[1]) + 37.5 * (float) end($digits[2]) / 80)->toBeLessThanOrEqual(1220.5)
        ->and(StreamTexts::for($game)['title'])->toEndWith(' · Chess Correspondence');
});

test('a daily game that just ended shows its result', function () {
    ChessGame::factory()->daily()->finished('1-0', ChessEndReason::Resignation)->create();
    $source = app(SceneSource::class);

    expect($source->liveGame())->toBeNull()
        ->and($source->scene($source->endedGame(60), (int) now()->getTimestampMs())['result'])->toBe('1-0 · Black resigned');
});
