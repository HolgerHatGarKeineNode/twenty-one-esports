<?php

/*
 * The chain block on /mining in a live season (plan "mining-page-clarity" P4): one line while no block exists, no
 * supply chart and no empty top-miner list; from the first block on the newest blocks as cubes and rows, the supply
 * chart and the top miners.
 */

use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../../Support/key_eras.php';

beforeEach(function () {
    Queue::fake();
    config(['esports.chess.rated_queue' => true]);
});

test('a live season without a block shows one empty line in the chain block, and no supply chart or top miners', function () {
    keySeason();

    $html = (string) $this->get(route('mining'))->assertOk()->getContent();

    expect(substr_count($html, 'data-test="chain-empty"'))->toBe(1)
        ->and($html)->toContain('data-state="live"')
        ->not->toContain('data-test="mining-supply"')
        ->not->toContain('data-test="mining-strongest"')
        ->not->toContain('data-test="chain-cube"')
        ->not->toContain('data-test="mining-rejected"');
});

test('from the first block on, the chain block shows the newest blocks as cubes with their anchors, the supply chart and the top miners', function () {
    $season = keySeason();
    keyWins($season, ['chess/blitz', 'chess/rapid']);

    $html = (string) $this->get(route('mining'))->assertOk()->getContent();

    expect(substr_count($html, 'data-test="chain-cube"'))->toBe(2)
        ->and($html)->not->toContain('data-test="chain-empty"')
        ->toContain('id="block-2"')->toContain('href="#block-2"')
        ->toContain('data-test="mining-supply"')
        ->toContain('data-test="supply-chart"')
        ->toContain('data-test="mining-strongest"')
        ->toContain('2 blocks mined, pending the season review');
});
