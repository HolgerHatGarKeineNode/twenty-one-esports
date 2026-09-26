<?php

use App\Models\ChessGame;
use App\Support\Chess\SanNotation;
use Illuminate\Support\Facades\Process;

/*
 * German readers see K D T L S (`Lg2` for `Bg2`); stored moves stay English SAN.
 * The client twin is resources/js/sanNotation.js: tests/js/sanNotation.test.mjs under Node.
 */
test('the client shows German piece letters and reads moves typed in either language', function () {
    $run = Process::path(base_path())->timeout(60)->run(['node', '--test', 'tests/js/sanNotation.test.mjs']);

    expect($run->successful())->toBeTrue($run->output().$run->errorOutput())
        ->and($run->output())->toContain('ℹ pass 2')->toContain('ℹ skipped 0');
});

test('a move is shown with the piece letters of the reader\'s language', function (string $san, string $locale, string $shown) {
    expect(SanNotation::display($san, $locale))->toBe($shown);
})->with([
    ['Bg2', 'de', 'Lg2'],
    ['Nxf3+', 'de', 'Sxf3+'],
    ['exd8=Q#', 'de', 'exd8=D#'],
    ['O-O', 'de', 'O-O'],
    ['Bg2', 'en', 'Bg2'],
]);

test('a finished game lists its moves in German for a German reader', function () {
    $game = ChessGame::factory()->finished()->create();
    $game->moves()->create(['ply' => 1, 'uci' => 'g1f3', 'san' => 'Nf3', 'fen' => 'rnbqkbnr/pppppppp/8/8/8/5N2/PPPPPPPP/RNBQKB1R b KQkq - 1 1', 'spent_ms' => 0, 'clock_ms' => 0]);
    $game->white->forceFill(['locale' => 'de'])->save();

    $this->actingAs($game->white)->get(route('games.show', $game))->assertOk()->assertSee('Sf3')->assertDontSee('>Nf3<', false);
    expect($game->moves()->value('san'))->toBe('Nf3');
});
