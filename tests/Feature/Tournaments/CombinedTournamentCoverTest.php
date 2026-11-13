<?php

use App\Enums\TournamentFormat;
use App\Enums\TournamentStatus;
use App\Games\GameRegistry;
use App\Models\Tournament;
use App\Support\Cards\Canvas;
use App\Support\Cards\CoverStack;
use App\Support\Cards\PageCard;
use App\Support\Cards\ShareCard;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Lottery;
use Tests\Support\BlockliOn;
use Tests\Support\BlockspaceOn;
use Tests\Support\CheckersGame;
use Tests\Support\NineMensMorrisOn;

/*
|--------------------------------------------------------------------------
| The picture of a combined tournament
|--------------------------------------------------------------------------
|
| DerCaddy, 2026-11-12: "Wäre es für kombinierte Turniere möglich, als Vorschaubild eine Kombination aus den Bildern
| beider Spiele zu nehmen?" A tournament whose pairings play a sequence of games shows the covers of its games as cards
| laid over one another, each whole (CoverStack): on its pages (<x-game-cover :games>) and on its link preview and
| invite card (Canvas::covers()). A tournament of one game keeps its one cover.
|
*/

beforeEach(function () {
    Queue::fake();
    BlockliOn::play();
    BlockspaceOn::play();
    Lottery::alwaysLose();
});

afterEach(function () {
    Lottery::determineResultNormally();
});

test('a combined tournament stands for each of its games once, in the order they first come; one game stands for itself', function () {
    expect(blockliTournament(TournamentFormat::SingleElimination, ['games' => ['blockli', 'chess', 'blockli', 'blockspace']])->coverGames())->toBe(['blockli', 'chess', 'blockspace'])
        ->and(blockliTournament(TournamentFormat::SingleElimination, ['pairGame' => 'blockspace'])->coverGames())->toBe(['blockli', 'blockspace'])
        ->and(blockliTournament()->coverGames())->toBe(['blockli']);

    // A tournament of one game stores a `games` key too (FormatOptions::toArray()): its cover costs no query, so a
    // list of tournaments pays nothing for it. A running lobby game's options ask the database when they are parsed.
    $single = Tournament::factory()->create([
        'game' => 'age-of-empires-2', 'mode' => '1v1', 'status' => TournamentStatus::Running,
        'options' => FormatOptions::defaults(GameProfile::for('age-of-empires-2', '1v1'))->toArray(),
    ])->refresh();
    DB::enableQueryLog();

    expect(array_key_exists('games', $single->options))->toBeTrue()
        ->and($single->coverGames())->toBe(['age-of-empires-2'])
        ->and(DB::getQueryLog())->toBe([]);

    DB::disableQueryLog();
    // More games than the stack draws: the page still renders.
    expect(CoverStack::style(5, 6))->toBe(CoverStack::style(4, 5));
});

/**
 * Where each cover's name stands on its art, as fractions of the cover: left, right, top, bottom. Measured on the
 * 1280×720 files (the white letters), 2026-11-13.
 */
const COVER_NAMES = [
    'chess' => [0.069, 0.453, 0.283, 0.404],
    'blockli' => [0.071, 0.509, 0.296, 0.406],
    'blockspace' => [0.072, 0.612, 0.322, 0.406],
    'nine-mens-morris' => [0.073, 0.481, 0.365, 0.556],
    'checkers' => [0.072, 0.635, 0.411, 0.522],
];

/**
 * Every order of `count` of the games.
 *
 * @param  list<string>  $games
 * @return list<list<string>>
 */
function coverOrders(array $games, int $count): array
{
    if ($count === 0) {
        return [[]];
    }

    $orders = [];

    foreach ($games as $game) {
        foreach (coverOrders(array_values(array_diff($games, [$game])), $count - 1) as $rest) {
            $orders[] = [$game, ...$rest];
        }
    }

    return $orders;
}

test('every game a combined tournament can play has its name measured', function () {
    NineMensMorrisOn::play();
    CheckersGame::play();

    $combinable = array_values(array_filter(
        array_map(fn ($game): string => $game->slug(), app(GameRegistry::class)->all()),
        fn (string $slug): bool => FormatOptions::combinable($slug) && app(GameRegistry::class)->coverPath($slug) !== null,
    ));

    // A new game's cover: measure where its name stands, add it here and check CoverStack::NAME_BOTTOM / NAME_RIGHT.
    expect(array_diff($combinable, array_keys(COVER_NAMES)))->toBe([]);

    foreach (COVER_NAMES as [, $right, , $bottom]) {
        expect($right)->toBeLessThan(CoverStack::NAME_RIGHT)->and($bottom)->toBeLessThan(CoverStack::NAME_BOTTOM);
    }
});

test('the cards start in the top left and end in the bottom right, each whole and inside the box, and no card or its frame touches the name of a card below it', function () {
    // The boxes the picture is drawn in and the frame of a card over the box's width: the page (16:9, 2 px on its
    // smallest stack of 240 px), the link preview, the invite card wide and story (3 units each).
    $boxes = [[16 / 9, 2 / 240], [448 / 210, 3 / 448], [496 / 232, 3 / 496], [936 / 438, 3 / 936]];
    $checked = 0;

    foreach ([2, 3, 4, 5] as $count) {
        foreach ($boxes as [$ratio, $frame]) {
            $cards = CoverStack::of($count, $ratio);
            $last = $cards[$count - 1];

            expect($cards)->toHaveCount($count)
                ->and([$cards[0]['x'], $cards[0]['y']])->toBe([0.0, 0.0])
                ->and(round($last['x'] + $last['w'], 6))->toBe(1.0)
                ->and(round($last['y'] + $last['h'], 6))->toBe(1.0);

            foreach ($cards as $card) {
                expect($card['x'])->toBeGreaterThanOrEqual(0.0)->and($card['y'])->toBeGreaterThanOrEqual(0.0)
                    ->and($card['x'] + $card['w'])->toBeLessThanOrEqual(1 + 1e-9)
                    ->and($card['y'] + $card['h'])->toBeLessThanOrEqual(1 + 1e-9)
                    // 16:9 cards, whatever the box.
                    ->and(round($card['w'] * $ratio / $card['h'], 6))->toBe(round(16 / 9, 6));
            }

            foreach (coverOrders(array_keys(COVER_NAMES), $count) as $games) {
                foreach ($games as $below => $game) {
                    [$left, $right, $top, $bottom] = COVER_NAMES[$game];
                    $card = $cards[$below];
                    // The name's box in the picture, in fractions of the box.
                    $name = [$card['x'] + $left * $card['w'], $card['x'] + $right * $card['w'], $card['y'] + $top * $card['h'], $card['y'] + $bottom * $card['h']];

                    foreach (array_slice($cards, $below + 1) as $above) {
                        // The frame is as many pixels high as wide: over the box's height it is `ratio` times as much.
                        $apart = $name[1] <= $above['x'] - $frame || $name[0] >= $above['x'] + $above['w'] + $frame
                            || $name[3] <= $above['y'] - $frame * $ratio || $name[2] >= $above['y'] + $above['h'] + $frame * $ratio;
                        $checked++;

                        if (! $apart) {
                            $this->fail("{$count} covers in ".round($ratio, 3).': '.implode(' + ', $games)." hides the name of {$game}");
                        }
                    }
                }
            }
        }
    }

    // 20 + 60 + 120 + 120 orders, 1 + 3 + 6 + 10 pairs of cards each, in four boxes.
    expect($checked)->toBe(4 * (20 * 1 + 60 * 3 + 120 * 6 + 120 * 10))
        ->and(CoverStack::style(0, 2))->toBe('left:0%;top:0%;width:62%')
        ->and(CoverStack::style(1, 2))->toBe('left:38%;top:'.round((1 - 0.62) * 100, 3).'%;width:62%');
});

test('a small box shows the first cover whole and how many more there are; a wide one the stack; every name in the label', function () {
    $html = Blade::render('<x-game-cover game="blockli" :games="$games" size="thumb" class="w-16" />', ['games' => ['blockli', 'blockspace', 'chess']]);

    expect($html)->toContain('aria-label="Blockli + Blockspace + Chess covers"')
        // The box asks itself how wide it is, the caller is not asked.
        ->toContain('@container')
        ->toMatch('/data-cover-first.*data-game-cover="blockli".*data-cover-more>\+2<.*data-cover-stack/s')
        ->toMatch('/<span class="block @min-\[240px\]:hidden" data-cover-first>/')
        ->toMatch('/<span class="hidden @min-\[240px\]:block" data-cover-stack>/')
        ->and(substr_count($html, 'data-game-cover="blockspace"'))->toBe(1);
});

test('the tournament page shows the combined picture with every game\'s cover; a tournament of one game its one cover', function () {
    $combined = blockliTournament(TournamentFormat::SingleElimination, ['games' => ['blockli', 'blockspace']]);
    $combined->forceFill(['published_at' => now()])->save();
    $single = blockliTournament();
    $single->forceFill(['published_at' => now()])->save();

    $page = $this->get(route('tournaments.show', $combined))->assertOk()->getContent();

    expect($page)->toContain('data-combined-cover')
        ->toContain('data-game-cover="blockli+blockspace"')
        ->toContain('aria-label="Blockli + Blockspace covers"')
        ->toContain('images/games/blockli-')
        ->toContain('images/games/blockspace-')
        ->and($this->get(route('tournaments.show', $single))->assertOk()->getContent())->not->toContain('data-combined-cover');
});

test('the link preview and the invite card draw the covers side by side, Blockli on the left, Blockspace on the right', function () {
    $tournament = blockliTournament(TournamentFormat::SingleElimination, ['games' => ['blockli', 'blockspace']]);
    $tournament->forceFill(['published_at' => now()])->save();
    $card = PageCard::tournament($tournament->refresh());

    expect($card->facts['games'])->toBe(['blockli', 'blockspace'])
        ->and(PageCard::tournament(blockliTournament())->facts['games'])->toBe([])
        ->and($card->fingerprint())->not->toBe(PageCard::tournament(blockliTournament(TournamentFormat::SingleElimination, ['games' => ['blockli', 'chess']]))->fingerprint());

    // A new cover file of the second game is a new card, on the link preview and on the invite: same tournament, only
    // that file's version changes.
    $share = ShareCard::tournamentInvite($tournament);
    $before = [$card->fingerprint(), $share->fingerprint('wide')];
    $versions = new ReflectionProperty(GameRegistry::class, 'coverVersions');
    $known = $versions->getValue();
    $versions->setValue(null, [...$known, app(GameRegistry::class)->coverPath('blockspace') => 'a-new-file']);

    try {
        expect($card->fingerprint())->not->toBe($before[0])
            ->and($share->fingerprint('wide'))->not->toBe($before[1]);
    } finally {
        $versions->setValue(null, $known);
    }

    // Each card holds its own game's cover: the PageCard's cover box is 688,48 448×210, Blockli at the back top left,
    // Blockspace on top at the bottom right.
    $image = imagecreatefromstring($card->render());
    $scale = imagesx($image) / PageCard::WIDTH;
    $registry = app(GameRegistry::class);
    [$back, $front] = CoverStack::of(2, 448 / 210);
    $alone = function (string $game, array $at) use ($registry): GdImage {
        $canvas = new Canvas(PageCard::WIDTH, PageCard::HEIGHT);
        $canvas->cover($registry->coverPath($game), 688 + $at['x'] * 448, 48 + $at['y'] * 210, $at['w'] * 448, $at['h'] * 210);

        return imagecreatefromstring($canvas->png());
    };
    $pixel = fn (GdImage $picture, int|float $x, int|float $y): int => imagecolorat($picture, (int) round($x * $scale), (int) round($y * $scale));

    expect($pixel($image, 688 + 40, 48 + 30))->toBe($pixel($alone('blockli', $back), 688 + 40, 48 + 30))
        ->and($pixel($image, 688 + 448 - 40, 48 + 210 - 30))->toBe($pixel($alone('blockspace', $front), 688 + 448 - 40, 48 + 210 - 30))
        // The front card's frame, just left of it on its middle row, is the ground.
        ->and(sprintf('#%06X', $pixel($image, 688 + $front['x'] * 448 - 2, 48 + ($front['y'] + $front['h'] / 2) * 210)))->toBe(Canvas::GROUND);

    // The invite card (wide and story) draws them too, and says so in its facts.
    $invite = ShareCard::tournamentInvite($tournament);

    expect($invite->facts['games'])->toBe(['blockli', 'blockspace'])
        ->and(imagecreatefromstring($invite->render('wide')))->toBeInstanceOf(GdImage::class)
        ->and(imagecreatefromstring($invite->render('story')))->toBeInstanceOf(GdImage::class);
});
