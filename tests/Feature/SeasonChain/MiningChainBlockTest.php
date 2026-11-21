<?php

/*
 * The chain block on /mining in a live season (plan "mining-page-clarity" P4): one line while no block exists, no
 * supply chart and no empty top-miner list; from the first block on the newest blocks as cubes and rows, the supply
 * chart and the top miners.
 */

use App\Models\Season;
use App\Models\SeasonAttestation;
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

test('live, how it works, the rules in force, the change log and the season review sit in one closed disclosure at the end, and the ladders are one chip row', function () {
    keySeason();

    $html = (string) $this->get(route('mining'))->assertOk()->getContent();
    $reference = str($html)->after('<details id="season-reference"')->toString();

    expect(substr_count($html, 'id="season-reference"'))->toBe(1)
        ->and($html)->toMatch('/<details id="season-reference" class="[^"]*" data-test="mining-reference">/')
        ->and($reference)->toContain('data-test="season-rules"')->toContain('data-test="mining-rules"')->toContain('data-test="mining-changes"')->toContain('data-test="mining-review"')
        // Nothing of the page follows the reference but the footer.
        ->and(str($reference)->after('</details>')->contains('data-test="mining-'))->toBeFalse()
        ->and($html)->toContain('data-test="mining-ladder-chess"')
        ->not->toContain('data-test="mining-ladder-link"');
});

test('live, the top miners beyond the shown rows fold into a disclosure instead of being dropped', function () {
    // Chess alone, with room for seven blocks a day: seven winners, so the list runs past the five shown rows.
    $season = keySeason(['parameters' => [...Season::factory()->make()->parameters, 'weights' => ['chess/blitz' => 1000, 'chess/rapid' => 1500], 'shares' => ['chess' => 100], 'daily' => ['chess' => 10]]]);
    keyWins($season, ['chess/blitz', 'chess/rapid', 'chess/blitz', 'chess/rapid', 'chess/blitz', 'chess/rapid', 'chess/blitz']);
    // Every win has its own winner, so each mined block is one miner.
    $miners = SeasonAttestation::query()->whereNotNull('height')->count();

    $html = (string) $this->get(route('mining'))->assertOk()->getContent();
    $rest = str($html)->after('data-test="mining-miners-rest"')->before('</details>')->toString();

    expect($miners)->toBeGreaterThan(5)
        ->and(substr_count($html, 'data-test="mining-miner"'))->toBe(5)
        ->and(substr_count($rest, 'data-test="mining-miner-rest"'))->toBe($miners - 3)
        ->and($rest)->toContain('and '.($miners - 3).' more players mined')->toContain('and '.($miners - 5).' more player');
});

test('live, the phone keeps the win range in the pay caption while its stat tile is hidden there', function () {
    keySeason();

    $html = (string) $this->get(route('mining'))->assertOk()->getContent();
    $range = trim((string) preg_replace('/\s+/', ' ', strip_tags(str($html)->after('data-test="stat-win-range"')->after('<b')->after('>')->before('</b>')->toString())));

    expect($range)->not->toBe('')
        ->and($html)->toMatch('/data-test="pay-range-phone">'.preg_quote($range, '/').' sats per winner, now</')
        ->and($html)->toMatch('/class="[^"]*max-sm:hidden[^"]*"\s+data-test="stat-win-range"/');
});
