<?php

use App\Enums\TournamentFormat;
use App\Games\GameRegistry;
use App\Support\Cards\Canvas;
use App\Support\Cards\CoverStack;
use App\Support\Cards\PageCard;
use App\Support\Cards\ShareCard;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Lottery;
use Tests\Support\BlockliOn;
use Tests\Support\BlockspaceOn;

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
});

test('the cards go from the top left to the bottom right, each whole and inside the box, none hiding the name of the one before', function () {
    foreach ([2, 3, 4, 5] as $count) {
        foreach ([16 / 9, 448 / 210, 496 / 232] as $ratio) {
            $cards = CoverStack::of($count, $ratio);

            expect($cards)->toHaveCount($count)
                ->and([$cards[0]['x'], $cards[0]['y']])->toBe([0.0, 0.0])
                ->and(round($cards[$count - 1]['x'] + $cards[$count - 1]['w'], 6))->toBe(1.0)
                ->and(round($cards[$count - 1]['y'] + $cards[$count - 1]['h'], 6))->toBe(1.0)
                // 16:9 cards, whatever the box.
                ->and(round($cards[0]['w'] * $ratio / $cards[0]['h'], 6))->toBe(round(16 / 9, 6));

            foreach (range(1, $count - 1) as $index) {
                // A cover's name stands in its top left, about the left half of its width and the top 40 % of its height:
                // the next card starts below it or right of it.
                $below = $cards[$index]['y'] - $cards[$index - 1]['y'] >= CoverStack::NAME * $cards[0]['h'] - 1e-9;
                $beside = $cards[$index]['x'] - $cards[$index - 1]['x'] > 0.5 * $cards[0]['w'];
                expect($below || $beside)->toBeTrue("{$count} covers in {$ratio}: card {$index} hides a name")
                    ->and($cards[$index]['x'])->toBeGreaterThan($cards[$index - 1]['x']);
            }
        }
    }

    expect(CoverStack::style(0, 2))->toBe('left:0%;top:0%;width:62%')
        ->and(CoverStack::style(1, 2))->toBe('left:38%;top:'.round((1 - 0.62) * 100, 3).'%;width:62%');
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
        // A new cover of the second game is a new card.
        ->and($card->fingerprint())->not->toBe(PageCard::tournament(blockliTournament(TournamentFormat::SingleElimination, ['games' => ['blockli', 'chess']]))->fingerprint());

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
