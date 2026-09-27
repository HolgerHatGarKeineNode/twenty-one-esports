<?php

use Illuminate\Support\Facades\Blade;

test('the cover of every registered game is a local WebP with a JPEG fallback, 16:9, lazy and with its size', function (string $game, string $alt, int $width) {
    $html = Blade::render('<x-game-cover :game="$game" size="card" class="w-40" />', ['game' => $game]);

    expect($html)
        ->toContain('<source type="image/webp" srcset="'.asset("images/games/{$game}-{$width}.webp").' '.$width.'w')
        ->toContain('src="'.asset("images/games/{$game}-{$width}.jpg").'"')
        ->toContain('alt="'.$alt.' cover"')
        ->toContain('loading="lazy"')
        ->toContain('width="'.$width.'" height="'.(int) round($width * 9 / 16).'"')
        ->toContain('aspect-video')
        ->toContain('w-40')
        ->not->toContain('data-game-cover-fallback');

    // Local files only: every URL in the markup points into public/images/games.
    preg_match_all('/(?:src|srcset)="([^"]+)"/', $html, $found);

    foreach (preg_split('/,\s*/', implode(',', $found[1])) as $candidate) {
        expect(strtok($candidate, ' '))->toStartWith(asset('images/games/').'/');
    }
})->with([
    'chess' => ['chess', 'Chess', 480],
    'rocket league' => ['rocket-league', 'Rocket League', 382],
    'fc 27' => ['ea-sports-fc-27', 'EA Sports FC 27', 480],
    'fc 26' => ['ea-sports-fc-26', 'EA Sports FC 26', 480],
]);

test('a game with a 1280 px file offers both widths to the browser', function () {
    $html = Blade::render('<x-game-cover game="ea-sports-fc-27" size="hero" />');

    expect($html)->toContain(asset('images/games/ea-sports-fc-27-1280.webp').' 1280w')
        ->toContain('sizes="100vw"');
});

test('an unknown game gets a neutral tile with its name, never a broken image', function () {
    $html = Blade::render('<x-game-cover game="tetris" size="card" class="w-40" />');

    expect($html)->toContain('data-game-cover-fallback')
        ->toContain('role="img"')
        ->toContain('aria-label="tetris cover"')
        ->toContain('aspect-video')
        ->toContain('w-40')
        ->not->toContain('<img');
});

test('the German page names the cover in German', function () {
    app()->setLocale('de');

    expect(Blade::render('<x-game-cover game="chess" />'))->toContain('alt="Cover von Schach"');
});
