<?php

/*
 * The readers of `season-chain-v2`: a season has no era of its own, every
 * game with a share halves by what it mined. With chess in its era 2 and the
 * board games in their era 1 every page a player reads shows each game its
 * own reward: /mining, the season card of the start page and the chip of the
 * chess lobby, in English and German. A halving has no date: a page says how
 * many sats and wins it is away, and "mined out" once a game is closed. The
 * admin season page offers no share change in a live season, and the season
 * review names the rest nobody mined.
 */

use App\Models\Season;
use App\Models\User;
use App\Support\Chess\RatedChess;
use App\Support\Engagement\HomeBoard;
use App\Support\Nostr\NostrKeys;
use App\Support\PreSeason;
use App\Support\SeasonChain\ChainOverview;
use App\Support\SeasonChain\SeasonChains;
use App\Support\SeasonChain\Seasons;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\BlockliOn;
use Tests\Support\TestSigner;

require_once __DIR__.'/../../Support/season_settlement.php';
require_once __DIR__.'/../../Support/key_eras.php';

beforeEach(function () {
    Queue::fake();
    BlockliOn::play();
    config(['esports.chess.rated_queue' => true, 'esports.board_games.rated_queue' => true]);
});

/** The text of the first element with this hook and `data-key`, tags and runs of whitespace taken out. */
function keyText(string $html, string $hook, string $key): string
{
    $start = strpos($html, 'data-test="'.$hook.'" data-key="'.$key.'"');

    if ($start === false) {
        return '';
    }

    $end = strpos($html, 'data-test="'.$hook.'"', $start + 1);

    return trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags('<'.substr($html, $start, ($end === false ? strlen($html) : $end) - $start)))));
}

test('with chess in era 2 and the board games in era 1, /mining, the home card and the chess lobby show each game its own reward', function (string $locale, array $sats, array $words) {
    $season = keySeason();
    // Three chess blitz wins: 6 300 sats, past the 5 250 of era 1. One Blockli blitz win: 2 100 of 3 150.
    $chain = keyWins($season, ['chess/blitz', 'chess/blitz', 'chess/blitz', 'blockli/blitz']);

    expect($chain->mined())->toBe(8_400);

    // Mutation: one era for the whole season (every key read in the lowest era a key stands in) turns chess to 2,100
    // on all three pages.
    $mining = (string) $this->get(route('mining', ['lang' => $locale]))->assertOk()->getContent();
    $chess = keyText($mining, 'key-halving', 'chess');
    $boards = keyText($mining, 'key-halving', 'board-games');

    expect($mining)->toContain('data-test="key-halving" data-key="chess" data-era="2"')
        ->toContain('data-test="key-halving" data-key="board-games" data-era="1"')
        ->and($chess)->toContain($words['era2'])->toContain($words['blitz'].' '.$sats['chess-blitz'])->toContain($words['rapid'].' '.$sats['chess-rapid'])
        ->not->toContain($sats['era1-blitz'])
        ->and($boards)->toContain($words['era1'])->toContain(ChainOverview::keyLabel('blockli/blitz').' '.$sats['era1-blitz'])->toContain(ChainOverview::keyLabel('blockli/rapid').' '.$sats['era1-rapid']);

    // The pay table marks each game in its own row: chess blitz in era 2, Blockli blitz in era 1.
    preg_match_all('/data-test="pays-now" data-key="([^"]+)"\s*>\s*([\d.,]+)/', $mining, $now);

    $marked = array_combine($now[1], $now[2]);
    ksort($marked);

    expect($marked)->toBe(['blockli/blitz' => $sats['era1-blitz'], 'blockli/rapid' => $sats['era1-rapid'], 'chess/blitz' => $sats['chess-blitz'], 'chess/rapid' => $sats['chess-rapid']]);

    $home = (string) $this->get(route('home', ['lang' => $locale]))->assertOk()->getContent();

    expect(keyText($home, 'season-key', 'chess'))->toContain($words['blitz'])->toContain(' 2 '.$sats['chess-blitz'])
        ->and(keyText($home, 'season-key', 'board-games'))->toContain(ChainOverview::keyLabel('blockli/blitz'))->toContain(' 1 '.$sats['era1-blitz'])
        ->and(keyText($home, 'season-phone-key', 'board-games'))->toContain($sats['era1-blitz'])
        ->and($home)->toContain(str_replace(':sats', $sats['chess-blitz'], $words['mines']));

    $lobby = html_entity_decode((string) $this->get(route('chess.lobby', ['lang' => $locale]))->assertOk()->getContent());

    expect(RatedChess::rewardsNow())->toBe(['rapid' => 1575, 'blitz' => 1050])
        ->and($lobby)->toContain(str_replace(':sats', $sats['chess-blitz'], $words['mines']))->toContain(str_replace(':sats', $sats['chess-rapid'], $words['mines']))
        ->not->toContain(str_replace(':sats', $sats['era1-blitz'], $words['mines']));
})->with([
    'English' => ['en', ['chess-blitz' => '1,050', 'chess-rapid' => '1,575', 'era1-blitz' => '2,100', 'era1-rapid' => '3,150'], ['era2' => 'Era 2 of 6', 'era1' => 'Era 1 of 6', 'blitz' => 'Chess blitz', 'rapid' => 'Chess rapid', 'mines' => 'A win mines :sats sats']],
    'German' => ['de', ['chess-blitz' => '1.050', 'chess-rapid' => '1.575', 'era1-blitz' => '2.100', 'era1-rapid' => '3.150'], ['era2' => 'Epoche 2 von 6', 'era1' => 'Epoche 1 von 6', 'blitz' => 'Schach Blitz', 'rapid' => 'Schach Rapid', 'mines' => 'Ein Sieg schürft :sats Sats']],
]);

test('the halving line of a game counts the sats and the wins to its next halving, and says mined out for a closed game', function (string $locale, array $lines) {
    $season = keySeason();
    keyWins($season, ['chess/blitz', 'chess/blitz', 'chess/blitz', 'blockli/blitz']);
    $keys = app(ChainOverview::class)->live($season)['keys'];

    // Chess: 6 300 of 10 500 mined, era 2 ends at 7 875: 1 575 sats, two blitz wins of 1 050 (the second crosses).
    // Board games: 2 100 of 6 300, era 1 ends at 3 150: 1 050 sats, one Blockli blitz win of 2 100.
    // Mutation: `bound($shareKey, $era + 1)` in keys() turns both lines red; `intdiv($toHalving, $block)` turns the wins red.
    expect([$keys['chess']['era'], $keys['chess']['to_halving'], $keys['chess']['wins'], $keys['chess']['wins_key']])->toBe([2, 1575, 2, 'chess/blitz'])
        ->and([$keys['board-games']['era'], $keys['board-games']['to_halving'], $keys['board-games']['wins'], $keys['board-games']['wins_key']])->toBe([1, 1050, 1, 'blockli/blitz'])
        ->and($keys['chess']['modes'])->toBe(['chess/blitz' => ['pays' => 1050, 'wins' => 2], 'chess/rapid' => ['pays' => 1575, 'wins' => 1]]);

    $mining = (string) $this->get(route('mining', ['lang' => $locale]))->assertOk()->getContent();
    $home = (string) $this->get(route('home', ['lang' => $locale]))->assertOk()->getContent();

    expect(keyText($mining, 'key-halving', 'chess'))->toContain($lines['chess'])
        ->and(keyText($mining, 'key-halving', 'board-games'))->toContain(str_replace(':mode', ChainOverview::keyLabel('blockli/blitz'), $lines['boards']))
        ->and(keyText($home, 'season-key', 'chess'))->toContain($lines['chess']);

    // A second season of one era: chess closes once it mined half its pot (5 250), with its third win.
    Season::query()->delete();
    Seasons::forget();
    cache()->flush();
    $closed = keySeason(['eras' => 1, 'slug' => 'season-closed']);
    keyWins($closed, ['chess/blitz', 'chess/blitz', 'chess/blitz', 'chess/blitz']);
    $keys = app(ChainOverview::class)->live($closed)['keys'];

    expect([$keys['chess']['closed'], $keys['chess']['era'], $keys['chess']['mined'], $keys['chess']['to_halving']])->toBe([true, null, 6300, 0])
        ->and($keys['chess']['modes']['chess/blitz'])->toBe(['pays' => 0, 'wins' => 0])
        // The one era of the board games is their last: no halving follows, the key closes.
        ->and($keys['board-games']['last'])->toBeTrue();

    $mining = (string) $this->get(route('mining', ['lang' => $locale]))->assertOk()->getContent();

    expect($mining)->toContain('data-test="key-halving" data-key="chess" data-era="closed"')
        ->and(keyText($mining, 'key-halving', 'chess'))->toContain($lines['closed'])->not->toContain($lines['next'])
        ->and(keyText($mining, 'key-halving', 'board-games'))->toContain($lines['last'])
        ->and(RatedChess::rewardsNow())->toBe([]);
})->with([
    'English' => ['en', ['chess' => 'next halving after 1,575 more sats (about 2 Chess blitz wins)', 'boards' => 'next halving after 1,050 more sats (about 1 :mode win)', 'closed' => 'mined out', 'next' => 'next halving', 'last' => 'mined out after 3,150 more sats']],
    'German' => ['de', ['chess' => 'nächstes Halving nach 1.575 weiteren Sats (etwa 2 Siege in Schach Blitz)', 'boards' => 'nächstes Halving nach 1.050 weiteren Sats (etwa 1 Sieg in :mode)', 'closed' => 'ausgeschürft', 'next' => 'nächstes Halving', 'last' => 'ausgeschürft nach 3.150 weiteren Sats']],
]);

test('the halvings that happened are listed under the supply chart: a game that mined its whole share with its day, and a win that passes two halvings with the era the game entered', function (string $locale, array $lines) {
    // One era: chess closes once it mined half its pot (5 250), with its third blitz win.
    $closed = keySeason(['eras' => 1]);
    keyWins($closed, ['chess/blitz', 'chess/blitz', 'chess/blitz', 'chess/blitz']);
    $day = fn (Season $season, int $minutes): string => CarbonImmutable::instance($season->genesis_at)->addMinutes($minutes)->setTimezone(PreSeason::timezoneFor(null))->locale($locale)->translatedFormat('D j M');
    $done = fn (): string => trim((string) preg_replace('/\s+/', ' ', strip_tags(str(html_entity_decode((string) $this->get(route('mining', ['lang' => $locale]))->assertOk()->getContent()))
        ->after('data-test="mining-halvings-done">')->before('</ul>')->toString())));

    expect(array_map(fn (array $halving): array => [$halving['key'], $halving['era'], $halving['entered'], $halving['closed']], app(ChainOverview::class)->live($closed)['curve']['halvings']))
        ->toBe([['chess', 1, null, true]])
        ->and($done())->toBe(str_replace(':when', $day($closed, 3), $lines['whole']));

    // Three eras and a chess pot of 4 200 (bounds 2 100, 3 150 and 3 675): one rapid win of 3 150 takes chess from era 1 into era 3.
    Season::query()->delete();
    Seasons::forget();
    cache()->flush();
    $parameters = Season::factory()->make()->parameters;
    $jump = keySeason(['eras' => 3, 'slug' => 'season-jump', 'parameters' => [...$parameters, 'weights' => ['chess/blitz' => 1000, 'chess/rapid' => 1500], 'shares' => ['chess' => 20], 'daily' => ['chess' => 5]]]);
    keyWins($jump, ['chess/rapid']);
    $chain = app(ChainOverview::class)->live($jump);

    // Mutation: `'era' => $halving['era'] + 1` in the list names era 2, which chess never stood in.
    expect($chain['keys']['chess']['era'])->toBe(3)
        ->and(array_map(fn (array $halving): array => [$halving['key'], $halving['era'], $halving['entered'], $halving['closed']], $chain['curve']['halvings']))->toBe([['chess', 1, 3, false]])
        ->and($done())->toBe(str_replace(':when', $day($jump, 1), $lines['entered']))
        // The chart's hairline names the same era (the block moved a week on: a mark in the first minutes of the season is not drawn).
        ->and(html_entity_decode((string) $this->blade('<x-supply-chart :curve="$curve" zone="UTC" />', ['curve' => [...$chain['curve'], 'halvings' => [[...$chain['curve']['halvings'][0], 'at' => $chain['curve']['from']->addWeek()]]]])))
        ->toContain($lines['chart']);
})->with([
    'English' => ['en', ['whole' => 'Chess mined its whole share on :when', 'entered' => 'Chess halved on :when and entered era 3', 'chart' => 'Chess: era 3']],
    'German' => ['de', ['whole' => 'Schach hat am :when seinen ganzen Anteil geschürft', 'entered' => 'Schach hatte am :when sein Halving und ist in Epoche 3', 'chart' => 'Schach: Epoche 3']],
]);

test('a season card cached before the games had their own eras is read again: the start page answers with the rows of the games and no error', function () {
    $season = keySeason();
    keyWins($season, ['chess/blitz', 'chess/blitz', 'chess/blitz', 'blockli/blitz']);
    // The card as the calendar rule cached it (scalars only): `eras` of the season, no `keys`.
    $stale = ['state' => 'live', 'slug' => $season->slug, 'live' => true, 'height' => 4, 'eras' => [['era' => 1, 'from' => $season->genesis_at->getTimestamp(), 'sats' => 2100]], 'payKey' => 'chess/blitz', 'pays' => 2100];
    Cache::put(HomeBoard::SEASON_CARD.'en', $stale, 60);
    Exceptions::fake();

    $card = HomeBoard::season();

    // Mutation: without the second read in HomeBoard::season() the card has no `keys` and the page throws on them.
    expect(array_column($card['keys'], 'era', 'key'))->toBe(['chess' => 2, 'board-games' => 1])
        ->and($card['pays'])->toBe(1050)
        ->and(serialize($card))->not->toContain('O:');

    $home = html_entity_decode((string) $this->get(route('home'))->assertOk()->getContent());

    expect($home)->toContain('data-test="season-key" data-key="chess"')->toContain('data-test="season-key" data-key="board-games"');
    Exceptions::assertNothingReported();
});

test('no page of the season names a date for a halving to come: the schedule has no start dates, the chart marks only halvings that happened', function () {
    $season = keySeason();
    keyWins($season, ['chess/blitz', 'chess/blitz', 'chess/blitz', 'blockli/blitz']);
    $chain = app(ChainOverview::class)->live($season);

    expect($chain)->not->toHaveKey('era')->not->toHaveKey('next_halving')->not->toHaveKey('key_eras')
        ->and(array_keys($chain['schedule'][0]))->toBe(['era', 'budget', 'caps', 'rewards', 'keys'])
        ->and(array_column($chain['schedule'], 'keys'))->toBe([['board-games'], ['chess'], [], [], [], []])
        // The one halving that happened: chess left era 1 with its third block.
        ->and(array_map(fn (array $halving): array => [$halving['key'], $halving['era'], $halving['closed'], $halving['at']->toIso8601String()], $chain['curve']['halvings']))
        ->toBe([['chess', 1, false, CarbonImmutable::instance($season->genesis_at)->addMinutes(3)->utc()->toIso8601String()]]);

    $board = User::factory()->withPubkey((new TestSigner)->pubkey)->create();
    config(['esports.board' => [NostrKeys::hexToNpub($board->pubkey)]]);

    $mining = html_entity_decode((string) $this->get(route('mining'))->assertOk()->getContent());
    $admin = html_entity_decode(Livewire::actingAs($board)->test('pages::admin.season')->html());
    $home = html_entity_decode((string) $this->get(route('home'))->assertOk()->getContent());

    // Mutation: the "From" column put back into the pay table turns the first line red.
    foreach (['/mining' => $mining, 'admin' => $admin, 'home' => $home] as $where => $html) {
        expect($html)->not->toContain('next halving on', $where)->not->toContain('last era', $where)->not->toContain('>From<', $where)->not->toContain('Era ends', $where)
            ->not->toContain('Share cap', $where)->not->toContain('halving each era', $where);
    }

    expect($mining)->toContain('Chess halved on')->toContain('and entered era 2')
        ->and($admin)->toContain('data-test="key-halving" data-key="chess" data-era="2"')->toContain('next halving after 1,575 more sats (about 2 Chess blitz wins)');
});

test('the admin season page offers no share change in a live season, and the draft still refuses a weight that pays nothing in the last era in plain words', function () {
    $board = User::factory()->withPubkey((new TestSigner)->pubkey)->create(['timezone' => 'UTC']);
    config(['esports.board' => [NostrKeys::hexToNpub($board->pubkey)], 'esports.league.nsec' => (new TestSigner)->secret, 'esports.trust.nsec' => (new TestSigner)->secret]);

    // Before Block 0: the draft offers the shares, and point 6 answers in words.
    $draft = Livewire::actingAs($board)->test('pages::admin.season')
        ->assertSee('wire:model="draftShares.chess"', false)
        ->set('draftWeights.chess/blitz', '0.01')
        ->call('saveDraft');

    expect($draft->get('draftError'))->toBe('Chess blitz would pay nothing in era 6. Raise its weight or the base subsidy, or set fewer eras.');

    keySeason();

    // Mutation: the share inputs put back into the change form turn `change-share-` red.
    $live = Livewire::actingAs($board)->test('pages::admin.season')
        ->assertDontSee('data-test="change-share-', false)
        ->assertDontSee('wire:model="shares.', false)
        ->assertDontSee('data-test="hyperbitcoinization-change-proposal"', false)
        ->assertSee('data-test="shares-fixed"', false)
        ->assertSee('Shares of the supply, fixed at Block 0: Chess 50 %, Board games 30 %.')
        ->assertSee('data-test="change-daily-chess"', false);

    expect(array_key_exists('shares', $live->instance()->all()))->toBeFalse();
});

test('the season review shows the rest of the supply that no block mined, and says it is paid to nobody', function () {
    $board = User::factory()->withPubkey((new TestSigner)->pubkey)->create(['timezone' => 'UTC']);
    config(['esports.board' => [NostrKeys::hexToNpub($board->pubkey)]]);
    $season = settledSeason([[settlementPlayer('anna'), 1], [settlementPlayer('bert'), 2]], ['supply' => 2_100_000]);
    $mined = app(SeasonChains::class)->chain($season)->mined();

    expect($mined)->toBeGreaterThan(0);

    // Mutation: `$review['mined']` in the line instead of `$review['rest']` turns it red.
    Livewire::actingAs($board)->test('season-settlement', ['season' => $season])
        ->assertSee('data-test="settlement-rest"', false)
        ->assertSee('Rest of the supply that no block mined: '.number_format(2_100_000 - $mined).' sats. It is paid to nobody and stays in the league reserve.');
});
