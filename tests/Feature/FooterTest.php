<?php

use App\Enums\SeriesResolution;
use App\Enums\SeriesStatus;
use App\Models\BoardGame;
use App\Models\ChessGame;
use App\Models\SeriesMatch;
use App\Models\User;
use App\Support\TwentyOne\Stream\StreamStats;
use Tests\Support\CheckersGame;

/*
 * The footer links the public source code on every page, desktop and mobile row,
 * and counts the games played as the stream does.
 */
test('the footer links the open source repository in a new tab', function () {
    $html = $this->get(route('rules'))->assertOk()->getContent();

    expect(substr_count($html, 'href="https://github.com/HolgerHatGarKeineNode/twenty-one-esports" target="_blank" rel="noopener"'))->toBe(2)
        ->and($html)->toContain('Open source');
});

test('the footer counts the games of every game as the stream does, never a voided series', function () {
    CheckersGame::play();
    ChessGame::factory()->finished()->create();
    BoardGame::query()->create(['game' => 'checkers', 'mode' => 'blitz', 'white_id' => User::factory()->create()->id, 'black_id' => User::factory()->create()->id, 'status' => 'finished', 'result' => '1-0',
        'position' => '-', 'turn' => 'w', 'ply' => 9, 'initial_ms' => 300000, 'increment_ms' => 3000, 'white_ms' => 1, 'black_ms' => 1, 'turn_started_ms' => 0, 'ended_at' => now()]);
    SeriesMatch::factory()->create(['status' => SeriesStatus::Confirmed, 'winner' => 'challenger', 'finished_at' => now()]);
    SeriesMatch::factory()->create(['status' => SeriesStatus::Resolved, 'resolution' => SeriesResolution::Void, 'winner' => 'challenger', 'finished_at' => now()]);

    expect($this->get(route('rules'))->assertOk()->getContent())->toContain(__('Games played').' <b class="text-ink">3</b>')
        ->and(app(StreamStats::class)->count()['gamesPlayed'])->toBe(3);
});
