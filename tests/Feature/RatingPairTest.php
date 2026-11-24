<?php

/*
 * Season vs. Casual (plan "Season vs. Casual", P1 and P2): every view shows
 * both ratings and labels both. Since Block 0 almost every ladder is open
 * while the rated pool is empty, so a pool picked by the ladder state alone
 * showed the start value 1000 and hid the casual Elo. The live season goes
 * by its number ("Season 1" for the slug `pre-season`), casual history is
 * "Casual", never "Season 0".
 */

use App\Models\Rating;
use App\Models\User;
use App\Support\Badges\BadgeCopy;
use App\Support\Rating\Ratings;

/** A chess rating row of `$player` in `$pool` with `$results` results (blitz unless named). */
function ratingPairRow(User $player, string $pool, int $rating, int $results, string $season = '', string $mode = 'blitz'): Rating
{
    return Rating::query()->create(['pool' => $pool, 'season' => $season, 'game' => 'chess', 'mode' => $mode, 'subject' => 'user:'.$player->id, 'user_id' => $player->id,
        'rating' => $rating, 'results' => $results, 'wins' => $results, 'draws' => 0, 'losses' => 0]);
}

test('an open ladder with an empty rated pool shows the casual rating, labelled Casual', function () {
    openSeason();
    $player = User::factory()->create();
    ratingPairRow($player, Rating::CASUAL, 1137, 7);

    $pair = Ratings::pair($player->id, 'chess', 'blitz');

    expect(Ratings::headline($player->id, 'chess', 'blitz'))->toMatchArray(['pool' => Rating::CASUAL, 'rating' => 1137, 'results' => 7])
        ->and($pair['season'])->toMatchArray(['pool' => Rating::RATED, 'results' => 0])
        ->and($pair['casual']['rating'])->toBe(1137)
        ->and(Ratings::label($pair['shown']['pool']))->toBe(__('Casual'));
});

test('a Season rating with a result is the one shown, labelled with the season name', function () {
    openSeason();
    $player = User::factory()->create();
    ratingPairRow($player, Rating::CASUAL, 1137, 7);
    ratingPairRow($player, Rating::RATED, 1042, 2, 'pre-season');

    expect(Ratings::headline($player->id, 'chess', 'blitz'))->toMatchArray(['pool' => Rating::RATED, 'rating' => 1042])
        ->and(Ratings::pair($player->id, 'chess', 'blitz')['casual']['rating'])->toBe(1137)
        ->and(Ratings::label(Rating::RATED))->toBe('Season 1');
});

test('a list falls back per player: Season with a result, else casual', function () {
    openSeason();
    [$seasoned, $casual] = User::factory()->count(2)->create()->all();
    ratingPairRow($seasoned, Rating::RATED, 1042, 2, 'pre-season');
    ratingPairRow($casual, Rating::CASUAL, 1137, 7);

    $shown = Ratings::shownForUsers([$seasoned->id, $casual->id], 'chess', 'blitz');

    expect($shown[$seasoned->id])->toMatchArray(['pool' => Rating::RATED, 'rating' => 1042])
        ->and($shown[$casual->id])->toMatchArray(['pool' => Rating::CASUAL, 'rating' => 1137]);
});

test('the profile lists the casual ladder and chip while the Season ladder is open and empty, and no Season 0', function () {
    openSeason();
    $player = User::factory()->create();
    ratingPairRow($player, Rating::CASUAL, 1137, 7);

    $html = $this->get(route('players.show', $player->npub))->assertOk()->getContent();
    $card = (string) str($html)->after('data-test="player-ladder"')->before('</li>');

    expect($card)->toContain('data-pool="casual"')->toContain('>1,137<')
        ->and((string) str($card)->after('data-test="player-ladder-pool">')->before('<'))->toBe(__('Casual'))
        ->and((string) str($html)->after('data-test="header-rating"')->before('</span></span>'))->toContain('>1,137<')
        ->and($html)->toContain('data-test="season-casual-legend"')
        ->and((string) str($html)->after('data-season="casual"')->before('</li>'))->toContain('<b class="truncate">'.__('Casual').'</b>')
        ->and($html)->not->toContain('Season 0')->not->toContain('Pre-Season');
});

test('the hub lists the casual rating next to an empty open Season ladder', function () {
    openSeason();
    $player = User::factory()->create();
    ratingPairRow($player, Rating::CASUAL, 1137, 7);

    $this->actingAs($player)->get(route('dashboard'))->assertOk()
        ->assertSee('data-test="me-rating" data-pool="casual"', false)
        ->assertSee('1,137');
});

test('the lobby ladder preview names its pool: Casual while the Season ladder has no result', function () {
    openSeason();
    // The chess lobby opens its ladder on rapid.
    ratingPairRow(User::factory()->create(), Rating::CASUAL, 1137, 7, mode: 'rapid');

    $html = $this->get(route('chess.lobby'))->assertOk()->getContent();

    expect($html)->toContain('data-test="lobby-ladder-pool">'.__('Casual').'<')
        ->and($html)->toContain('data-test="lobby-ladder-row"');
});

test('while a season is live no page names it "Pre-Season": rules and the chess lobby say Season 1', function () {
    openSeason();

    foreach ([route('rules'), route('chess.lobby')] as $url) {
        $this->get($url)->assertOk()->assertSee('Season 1 is live')->assertDontSee('Pre-Season')->assertDontSee('pre-season is live');
    }
});

test('a season is named by its order: the first, signed as pre-season, is Season 1', function (string $slug, string $name) {
    expect(BadgeCopy::season($slug))->toBe($name);
})->with([
    'the first season' => ['pre-season', 'Season 1'],
    'the second' => ['season-1', 'Season 2'],
    'the fifth' => ['season-4', 'Season 5'],
]);

test('no caller picks a rating pool by the ladder state alone', function () {
    $root = dirname(__DIR__, 2);
    $hits = [];

    foreach (['app', 'resources/views'] as $dir) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/{$dir}", FilesystemIterator::SKIP_DOTS)) as $file) {
            if (str_ends_with($file->getFilename(), '.php') && str_contains((string) file_get_contents($file->getPathname()), 'pool(Ladders::isOpen')) {
                $hits[] = $file->getPathname();
            }
        }
    }

    expect($hits)->toBe([]);
});
