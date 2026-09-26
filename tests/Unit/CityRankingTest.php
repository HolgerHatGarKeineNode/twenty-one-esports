<?php

use App\Support\Engagement\CityRanking;

/*
 * Meetup against meetup (P10): clan hashrate summed per meetup city.
 */

test('the hashrate of all clans of a city is summed, highest city first', function () {
    expect(CityRanking::rank([
        ['city' => 'Kempten', 'hashrate' => 12],
        ['city' => 'München', 'hashrate' => 20],
        ['city' => 'Kempten', 'hashrate' => 15],
        ['city' => 'Zürich', 'hashrate' => 0],
    ]))->toBe([
        ['city' => 'Kempten', 'hashrate' => 27, 'clans' => 2],
        ['city' => 'München', 'hashrate' => 20, 'clans' => 1],
        ['city' => 'Zürich', 'hashrate' => 0, 'clans' => 1],
    ]);
});

test('a city is one city whatever its case and spacing, clans without a city are left out, ties go by name', function () {
    expect(CityRanking::rank([
        ['city' => '  münchen ', 'hashrate' => 5],
        ['city' => 'MÜNCHEN', 'hashrate' => 4],
        ['city' => 'Bad  Tölz', 'hashrate' => 9],
        ['city' => 'Augsburg', 'hashrate' => 9],
        ['city' => null, 'hashrate' => 100],
        ['city' => '   ', 'hashrate' => 100],
    ]))->toBe([
        ['city' => 'Augsburg', 'hashrate' => 9, 'clans' => 1],
        ['city' => 'Bad Tölz', 'hashrate' => 9, 'clans' => 1],
        ['city' => 'münchen', 'hashrate' => 9, 'clans' => 2],
    ]);
});

test('no clans, no cities', function () {
    expect(CityRanking::rank([]))->toBe([]);
});
