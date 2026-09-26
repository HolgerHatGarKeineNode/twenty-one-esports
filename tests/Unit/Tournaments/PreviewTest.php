<?php

use App\Enums\TournamentFormat;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\Preview;

/*
| The mini preview of the chooser draws what the engine builds: a box per
| match, byes as dashed boxes, and every box lit in its round.
*/

test('single elimination of 12: 4 byes dashed, 11 matches lit round by round', function () {
    $preview = Preview::for(TournamentFormat::SingleElimination, 12, new FormatOptions);
    $ghosts = array_filter($preview['rects'], fn (array $rect) => $rect['ghost']);

    expect($ghosts)->toHaveCount(4)
        ->and(count($preview['rects']) - count($ghosts))->toBe(11)
        ->and(array_values(array_unique(array_column($preview['rects'], 'delay'))))->toBe([0.0, 0.32, 0.64, 0.96]);
});

test('round robin of 5: one square per pairing and side, lit over 5 rounds', function () {
    $preview = Preview::for(TournamentFormat::RoundRobin, 5, new FormatOptions);

    expect($preview['rects'])->toHaveCount(20)
        ->and(count(array_unique(array_column($preview['rects'], 'delay'))))->toBe(5);
});

test('double elimination draws the lower rounds the engine keeps, and the reset only with the option', function () {
    $reset = Preview::for(TournamentFormat::DoubleElimination, 12, new FormatOptions(grandFinal: 'reset'));
    $single = Preview::for(TournamentFormat::DoubleElimination, 12, new FormatOptions(grandFinal: 'single'));

    expect(array_filter($reset['rects'], fn (array $rect) => $rect['ghost'] && $rect['w'] === 48.0))->toHaveCount(1)
        ->and(count($reset['rects']) - count($single['rects']))->toBe(1)
        ->and(array_column($reset['texts'], 'text'))->toContain('if needed');
});
