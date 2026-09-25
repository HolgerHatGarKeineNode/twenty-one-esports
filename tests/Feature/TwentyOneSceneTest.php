<?php

use App\Enums\ChessEndReason;
use App\Models\ChessGame;
use App\Models\User;
use App\Support\TwentyOne\Stream\SceneRenderer;
use App\Support\TwentyOne\Stream\SceneSource;

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
        ->and($svg)->toContain('>0 games played · 2 live · 0 clans<');
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
