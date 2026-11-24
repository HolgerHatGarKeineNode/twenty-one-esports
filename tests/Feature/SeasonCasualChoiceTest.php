<?php

/*
|--------------------------------------------------------------------------
| Season 1 or Casual where a match starts (plan "Season vs. Casual", P3, P4)
|--------------------------------------------------------------------------
|
| Every lobby shows the choice before any click (it sat inside the panel a
| tile opens), each mode tags whether it plays Season 1, the rated option's
| badge names the kind of reason it is closed (it read "not open yet" exactly
| while the ladder was open), and the range box quotes the rating of the pool
| being searched. A correspondence challenge preselects rated for a pair that
| can play rated now. /mining's primary button names and links the open
| ladder game the player played most.
*/

use App\Games\Blockli;
use App\Games\NineMensMorris;
use App\Models\BoardGame;
use App\Models\ChessGame;
use App\Models\Rating;
use App\Models\User;
use App\Support\SeasonChain\Opponents;
use App\Support\SeasonChain\TrustFacts;
use App\Support\Series\Ladders;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\BlockliOn;
use Tests\Support\NineMensMorrisOn;
use Tests\Support\TestSigner;
use Tests\Support\TrustedFacts;

beforeEach(function () {
    Queue::fake();
    $this->freezeSecond();
    BlockliOn::play();
    NineMensMorrisOn::play();
    config(['esports.board_games.rated_queue' => true, 'esports.chess.rated_queue' => true]);
    app()->bind(TrustFacts::class, TrustedFacts::class);
});

/** @return array{0: User, 1: TestSigner} */
function choicePlayer(string $name): array
{
    $signer = new TestSigner;

    return [User::factory()->withPubkey($signer->pubkey)->create(['name' => $name]), $signer];
}

/** The two add each other, signed as the browser would. */
function choiceListEachOther(array $a, array $b): void
{
    $opponents = app(Opponents::class);

    foreach ([[$a, $b], [$b, $a]] as [[$player, $signer], [$opponent]]) {
        $opponents->add($player, $opponent, $signer->signTemplates($opponents->prepareAdd($player, $opponent)));
        test()->travel(1)->seconds();
    }
}

/** `$count` finished board games of `$game` between the two. */
function choiceBoardGames(int $count, string $game, string $mode, User $white, User $black): void
{
    foreach (range(1, $count) as $index) {
        BoardGame::query()->create(['game' => $game, 'mode' => $mode, 'white_id' => $white->id, 'black_id' => $black->id, 'status' => 'finished', 'result' => '1-0',
            'position' => '-', 'turn' => 'w', 'ply' => 9, 'initial_ms' => 300000, 'increment_ms' => 3000, 'white_ms' => 1, 'black_ms' => 1, 'turn_started_ms' => 0, 'ended_at' => now()]);
    }
}

/** Trust ranks computed, every player at `$rank`, every pair connected. */
function choiceRanks(int $rank): void
{
    app()->bind(TrustFacts::class, fn () => new class($rank) implements TrustFacts
    {
        public function __construct(private int $rank) {}

        public function available(): bool
        {
            return true;
        }

        public function at(array $players, array $gatekeepers): array
        {
            return ['trust' => array_fill_keys($players, $this->rank), 'anchors' => [], 'connected' => true];
        }
    });
}

test('the board lobby shows Season 1 and Casual before any click, outside the panel a tile opens, with each mode tagged', function () {
    openSeason();
    $anna = choicePlayer('Anna');
    choiceListEachOther($anna, choicePlayer('Bert'));

    $html = Livewire::actingAs($anna[0])->test('pages::board.lobby', ['board' => Blockli::SLUG])->html();
    $choice = strpos($html, 'data-test="kind-choice"');

    expect($choice)->not->toBeFalse()
        ->and($choice)->toBeLessThan(strpos($html, 'id="lobby-quick"'))
        ->and(str($html)->between('data-test="kind-choice"', 'id="lobby-quick"')->toString())
        ->toContain('data-test="kind-casual"')->toContain('data-test="kind-rated"')->toContain('Season 1 (rated)')->toContain('For fun, own Elo')
        ->and($html)->toContain('data-test="play-blitz-kinds">Season 1<')->toContain('data-test="play-rapid-kinds">Season 1<');
});

test('the chess lobby shows Season 1 and Casual before any click, outside the panel a tile opens', function () {
    openSeason();
    [$anna] = choicePlayer('Anna');

    $html = Livewire::actingAs($anna)->test('pages::chess.lobby')->html();
    $choice = strpos($html, 'data-test="kind-choice"');

    expect($choice)->not->toBeFalse()
        ->and($choice)->toBeLessThan(strpos($html, 'id="lobby-quick"'))
        ->and(str($html)->between('data-test="kind-choice"', 'id="lobby-quick"')->toString())->toContain('data-test="kind-rated"')->toContain('Season 1 (rated)');
});

test('on an open ladder the rated badge names why rated is closed, never "not open yet"', function () {
    openSeason();
    choiceRanks(12);
    [$anna] = choicePlayer('Anna');

    expect(Ladders::isOpen(Blockli::SLUG, 'blitz'))->toBeTrue();

    $html = Livewire::actingAs($anna)->test('pages::board.lobby', ['board' => Blockli::SLUG])->html();
    preg_match('/data-kind="([a-z]*)" data-test="kind-rated-badge">([^<]*)</', $html, $badge);

    expect([$badge[1] ?? null, $badge[2] ?? null])->toBe(['trust', 'Trusted needed'])
        ->and($html)->not->toContain('not open yet');
});

test('the range box quotes the rating of the pool being searched: casual for Casual, the season rating for Season 1', function () {
    $season = openSeason();
    [$anna] = choicePlayer('Anna');
    Rating::query()->create(['game' => Blockli::SLUG, 'mode' => 'blitz', 'pool' => Rating::CASUAL, 'season' => '', 'subject' => 'user:'.$anna->id, 'user_id' => $anna->id, 'rating' => 1111, 'results' => 3]);
    Rating::query()->create(['game' => Blockli::SLUG, 'mode' => 'blitz', 'pool' => Rating::RATED, 'season' => $season->slug, 'subject' => 'user:'.$anna->id, 'user_id' => $anna->id, 'rating' => 1777, 'results' => 3]);

    // @js() hands the ratings to Alpine as JSON with its quotes escaped.
    $html = str_replace('\\u0022', '"', html_entity_decode(Livewire::actingAs($anna)->test('pages::board.lobby', ['board' => Blockli::SLUG])->html()));

    expect($html)->toContain('{"casual":{"blitz":1111,"rapid":1000},"rated":{"blitz":1777,"rapid":1000}}')
        ->and($html)->toContain('ratings[rated ? \'rated\' : \'casual\'][liveMode]');
});

test('a correspondence challenge preselects rated for a pair that can play rated now, casual with the reason otherwise', function () {
    openSeason();
    $anna = choicePlayer('Anna');
    $bert = choicePlayer('Bert');
    [$cleo] = choicePlayer('Cleo');
    choiceListEachOther($anna, $bert);
    // Connected only where the two list each other (the real lists, as the trust job reads them).
    app()->bind(TrustFacts::class, fn () => new class implements TrustFacts
    {
        public function available(): bool
        {
            return true;
        }

        public function at(array $players, array $gatekeepers): array
        {
            [$a, $b] = User::query()->whereIn('pubkey', $gatekeepers)->get()->all() + [null, null];

            return ['trust' => array_fill_keys($players, 100), 'anchors' => [], 'connected' => $a !== null && $b !== null && app(Opponents::class)->listEachOther($a, $b)];
        }
    });

    Livewire::actingAs($anna[0])->test('pages::board.correspondence', ['board' => NineMensMorris::SLUG, 'to' => $bert[0]->npub])
        ->assertSet('rated', true)
        ->assertSee('You and Bert can play rated', false);

    Livewire::actingAs($anna[0])->test('pages::board.correspondence', ['board' => NineMensMorris::SLUG, 'to' => $cleo->npub])
        ->assertSet('rated', false)
        // The path card under the send button names the missing step; the line under the choice would repeat it (design-lead review, finding 3).
        ->assertSee('data-key="opponent" data-done="false"', false)
        ->assertDontSee('data-test="rated-why"', false)
        ->assertDontSee('You and Cleo can play rated', false);
});

test('the primary button of /mining names and links the open ladder game the player played most', function () {
    openSeason();
    // Eligible (Trusted, a mutual opponent): the game is the primary action, rated.
    choiceRanks(80);
    [$anna, $annaSigner] = choicePlayer('Anna');
    [$bert, $bertSigner] = choicePlayer('Bert');
    choiceListEachOther([$anna, $annaSigner], [$bert, $bertSigner]);
    choiceBoardGames(3, NineMensMorris::SLUG, 'correspondence', $anna, $bert);
    ChessGame::factory()->create(['white_id' => $anna->id, 'black_id' => $bert->id]);

    $html = html_entity_decode((string) $this->actingAs($anna)->get(route('mining'))->assertOk()->getContent());
    preg_match('/<a href="([^"]+)" class="rv-b h-\[52px\] px-6" data-test="mining-primary" data-variant="rated" data-game="([a-z-]+)">([^<]+)</', $html, $primary);

    expect(array_slice($primary, 1))->toBe([route('board.lobby', NineMensMorris::SLUG), NineMensMorris::SLUG, "Play rated Nine Men's Morris"]);
});

test('without a game of their own the button leads to the game the league played most in the last 14 days', function () {
    openSeason();
    [$anna] = choicePlayer('Anna');
    [$bert] = choicePlayer('Bert');
    [$cleo] = choicePlayer('Cleo');
    choiceBoardGames(2, Blockli::SLUG, 'blitz', $bert, $cleo);
    // Older than 14 days: does not count.
    ChessGame::factory()->count(5)->create(['white_id' => $bert->id, 'black_id' => $cleo->id, 'created_at' => now()->subDays(20)]);

    $html = (string) $this->actingAs($anna)->get(route('mining'))->assertOk()->getContent();

    // Anna cannot play rated yet: her first open step is the primary action, the game the casual secondary one (design-lead review, finding 4).
    expect($html)->toContain('data-test="mining-secondary" data-game="'.Blockli::SLUG.'"');
});
