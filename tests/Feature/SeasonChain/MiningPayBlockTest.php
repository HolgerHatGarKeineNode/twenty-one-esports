<?php

/*
 * "What a win pays" on /mining in a live season (plan "mining-page-clarity" P3): one card per game with a share, a chip
 * per mode with what a win pays now, the full era table closed below. The chips and the table read the same
 * ChainOverview rows, so every figure a chip shows is the figure the table marks for that mode now.
 */

use App\Support\SeasonChain\ChainOverview;
use Illuminate\Support\Facades\Queue;
use Tests\Support\BlockliOn;

require_once __DIR__.'/../../Support/key_eras.php';

beforeEach(function () {
    Queue::fake();
    BlockliOn::play();
    config(['esports.chess.rated_queue' => true, 'esports.board_games.rated_queue' => true]);
});

test('every pay chip shows the figure the era table marks for its modes now, and every mode that pays has a chip', function () {
    $season = keySeason();
    // Chess past its first halving (era 2), the board games still in era 1: the two cards differ.
    keyWins($season, ['chess/blitz', 'chess/blitz', 'chess/blitz', 'blockli/blitz']);

    $html = (string) $this->get(route('mining'))->assertOk()->getContent();
    preg_match_all('/data-test="pay-chip" data-keys="([^"]+)" data-pays="(\d+)"/', $html, $chips, PREG_SET_ORDER);
    preg_match_all('/data-test="pays-now" data-key="([^"]+)"\s*>\s*([\d,]+)/', $html, $cells, PREG_SET_ORDER);

    $chipPays = [];
    foreach ($chips as [, $keys, $pays]) {
        foreach (explode(',', $keys) as $key) {
            $chipPays[$key] = (int) $pays;
        }
    }
    $tablePays = array_combine(array_column($cells, 1), array_map(fn (array $cell): int => (int) str_replace(',', '', $cell[2]), $cells));
    ksort($chipPays);
    ksort($tablePays);

    expect($chipPays)->toBe($tablePays)
        ->and($chipPays)->toBe(['blockli/blitz' => 2_100, 'blockli/rapid' => 3_150, 'chess/blitz' => 1_050, 'chess/rapid' => 1_575])
        // The same figures as the chain's own rows.
        ->and($chipPays['chess/blitz'])->toBe(app(ChainOverview::class)->live($season)['keys']['chess']['modes']['chess/blitz']['pays']);
});

test('live, the halving bar chart and the weights row are gone, the era table is closed, and the games that mine are named apart from those that do not', function () {
    keySeason();

    $html = (string) $this->get(route('mining'))->assertOk()->getContent();

    expect(substr_count($html, 'Advanced statistics'))->toBe(0)
        ->and($html)->not->toContain('Game weights, per winning player')
        ->not->toContain('Sats per win per era')
        ->toMatch('/<details class="rv-adv min-w-0" data-test="mining-eras">/')
        ->toContain('data-test="games-mine"')
        ->and(trim((string) preg_replace('/\s+/', ' ', strip_tags(str($html)->after('data-test="games-mine"')->before('</div>')->toString()))))
        ->toBe('> Mine blocks now Chess Blockli');
});
