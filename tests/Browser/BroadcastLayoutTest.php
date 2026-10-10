<?php

use App\Enums\OverlayVariant;
use App\Enums\TournamentFormat;
use App\Models\ChessGame;
use App\Models\OverlayPreset;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserWait;
use Tests\Support\BrowserWebGL;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| The broadcast layout holds at any source size (plan "OBS-Broadcast-Overlays", live defect of 2026-10-10)
|--------------------------------------------------------------------------
|
| Every variant opens as OBS opens it (software WebGL) on factory data with long real names, once at 1920x1080 and
| once resized to 1879x909 (a browser window, not 16:9). Read from the running engine (window.broadcast.layout():
| every visible text, drawn plane and 21 mark as a box in viewport px), with every element on air held:
|
| - the frame is the largest 16:9 box in the viewport, centred, and every box lies inside it;
| - no two texts overlap, the 21 mark covers no text;
| - nothing is clipped: a text's drawn width fits its box (the clock's eight characters included);
| - a clock always shows HH:MM:SS, and no text stands twice in the frame (the banner and the hero said the same).
|
| BROADCAST_LAYOUT_SHOTS=<dir> additionally writes each measured state's screenshot and boxes there.
|
| The two reported cases are in the set: the bracket scene before the draw (the mark over the banner's name) and the
| break scene's clock (the last digit cut at the column's edge).
|
*/

beforeEach(fn () => Http::fake(fn () => Http::response([])));

afterAll(fn () => BrowserWebGL::off());

/** Eight names of 32 characters, the longest a player may pick. */
const LAYOUT_NAMES = [
    'Satoshis_Very_Long_Gamer_Tag_032', 'Hodlqueen_from_the_Alps_and_more', 'El Presidento Ben of Twentyone21', 'Markus_Turm_the_unbreakable_wall',
    'WWWWWWWWWWWWWWWWWWWWWWWWWWWWWWWW', 'Stacking_sats_every_single_day21', 'Lightning_Larry_from_Leipzig_DE1', 'Proof_of_Frag_and_Proof_of_Pong2',
];

function layoutPage(string $token): Page
{
    BrowserWebGL::on();
    $page = visit('/broadcast/'.$token)->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize(1920, 1080);
    $page->goto(ComputeUrl::from('/broadcast/'.$token.'?tier=low'));
    BrowserWait::until($page, '() => ["running", "no-webgl"].includes(document.body.dataset.broadcast)', 20_000);
    expect($page->evaluate('() => window.broadcast.webgl'))->toBeTrue();

    return $page;
}

/** Wait until the stinger is gone and every element on air holds (nothing builds in or out while measured). */
function layoutSettled(Page $page, int $fromMs): void
{
    BrowserWait::until($page, "() => { const s = window.broadcast.timeline(); return s.now >= {$fromMs} && s.segments.filter((g) => g.kind !== 'ticker' && g.start <= s.now && s.end > s.now).every((g) => g.kind !== 'stinger' && g.observed.holdStart !== null && s.now < g.start + g.introMs + g.holdMs - 300); }", $fromMs + 40_000);
}

/** The layout at the viewport's size now, after the stage followed a resize and redrew its text. */
function layoutAt(Page $page, int $w, int $h): array
{
    $page->setViewportSize($w, $h);
    BrowserWait::until($page, "() => innerWidth === {$w} && innerHeight === {$h}", 5_000);
    // A few frames: the ResizeObserver resizes the stage and redraws the text, the next frame draws it.
    $page->evaluate('() => new Promise((r) => { let n = 0; const f = () => (++n >= 4 ? r() : requestAnimationFrame(f)); requestAnimationFrame(f); })');

    return $page->evaluate('() => window.broadcast.layout()');
}

/** BROADCAST_LAYOUT_SHOTS=<dir>: the screenshot and the probe's boxes of every measured state go there as well. */
function layoutShot(Page $page, array $layout, int $w, int $h): void
{
    $dir = getenv('BROADCAST_LAYOUT_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    File::ensureDirectoryExists($dir);
    $name = Str::slug(Str::limit(test()->name(), 60, '')).'-'.$w.'x'.$h;
    $page->screenshot(false, $name);
    File::move(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
    File::put($dir.'/'.$name.'.json', (string) json_encode($layout, JSON_PRETTY_PRINT));
}

/** Every rule this file holds the layout to, as readable defects (empty: the layout is clean). */
function layoutDefects(array $layout): array
{
    $defects = [];
    $f = $layout['frame'];
    $vw = $layout['viewport']['w'];
    $vh = $layout['viewport']['h'];
    $fw = min($vw, $vh * 16 / 9);
    $fh = $fw * 9 / 16;

    if (abs(($f['x1'] - $f['x0']) - $fw) > 1.5 || abs(($f['y1'] - $f['y0']) - $fh) > 1.5 || abs($f['x0'] - ($vw - $fw) / 2) > 1.5 || abs($f['y0'] - ($vh - $fh) / 2) > 1.5) {
        $defects[] = sprintf('frame %.0fx%.0f at %.0f,%.0f is not the centred 16:9 fit %.0fx%.0f of %dx%d', $f['x1'] - $f['x0'], $f['y1'] - $f['y0'], $f['x0'], $f['y0'], $fw, $fh, $vw, $vh);
    }

    $boxes = collect($layout['boxes'])->filter(fn (array $b): bool => ! ($b['clipped'] ?? false) && ($b['layer'] === 'frame' || ($b['onPage'] ?? null) === true))->values();
    $label = fn (array $b): string => $b['kind'] === 'mark' ? '21 mark' : '"'.$b['shown'].'"';

    foreach ($boxes as $b) {
        if ($b['x0'] < $f['x0'] - 1 || $b['y0'] < $f['y0'] - 1 || $b['x1'] > $f['x1'] + 1 || $b['y1'] > $f['y1'] + 1) {
            $defects[] = sprintf('%s leaves the frame (%.0f,%.0f-%.0f,%.0f)', $label($b), $b['x0'], $b['y0'], $b['x1'], $b['y1']);
        }

        if ($b['kind'] === 'text' && $b['ink'] > $b['room'] + 0.5) {
            $defects[] = sprintf('%s is clipped: %.0f px drawn in %.0f px', $label($b), $b['ink'], $b['room']);
        }

        if ($b['kind'] === 'text' && $b['clock'] && strlen($b['shown']) !== 8) {
            $defects[] = sprintf('clock shows "%s", not HH:MM:SS', $b['shown']);
        }
    }

    $count = $boxes->count();

    for ($i = 0; $i < $count; $i++) {
        for ($j = $i + 1; $j < $count; $j++) {
            [$a, $b] = [$boxes[$i], $boxes[$j]];

            if ($a['kind'] === 'mark' && $b['kind'] === 'mark') {
                continue;
            }

            $dx = min($a['x1'], $b['x1']) - max($a['x0'], $b['x0']);
            $dy = min($a['y1'], $b['y1']) - max($a['y0'], $b['y0']);

            // Two pixels of touch are anti-aliasing at a 1080p edge, not an overlap anyone sees.
            if ($dx > 2 && $dy > 2) {
                $defects[] = sprintf('%s overlaps %s by %.0fx%.0f px', $label($a), $label($b), $dx, $dy);
            }
        }
    }

    $twice = $boxes->where('layer', 'frame')->where('kind', 'text')->where('plane', false)->pluck('shown')->filter(fn (string $s): bool => mb_strlen($s) > 3)->countBy()->filter(fn (int $n): bool => $n > 1);

    foreach ($twice as $text => $n) {
        $defects[] = sprintf('"%s" stands %d times in the frame', $text, $n);
    }

    return $defects;
}

/** The scene's layout at 1920x1080 and at 1879x909, with what each must contain (the probe's positive control). */
function layoutHolds(Page $page, array $expect): void
{
    foreach ([[1920, 1080], [1879, 909]] as [$w, $h]) {
        $layout = layoutAt($page, $w, $h);
        layoutShot($page, $layout, $w, $h);
        $texts = collect($layout['boxes'])->where('kind', 'text')->pluck('shown')->implode(' | ');

        foreach ($expect as $needle) {
            expect(collect($layout['boxes'])->contains(fn (array $b): bool => $needle === 'mark' ? $b['kind'] === 'mark' : ($needle === 'clock' ? ($b['clock'] ?? false) : str_contains($b['shown'] ?? '', $needle))))
                ->toBeTrue("{$w}x{$h}: {$needle} is not on screen; texts: {$texts}");
        }

        expect(layoutDefects($layout))->toBe([], "{$w}x{$h}");
    }

    expect([...$page->evaluate('() => window.__errors ?? ["collector missing"]'), ...$page->evaluate(BrowserConsole::BAD_RESPONSES)])->toBe([]);
}

function layoutCup(string $name, int $startsInMinutes, string $game = 'chess'): Tournament
{
    $factory = Tournament::factory()->signup();

    if ($game === 'rocket-league') {
        $factory = $factory->rocketLeague();
    }

    return $factory->create(['name' => $name, 'published_at' => now(), 'signup_closes_at' => now()->addMinutes($startsInMinutes - 10), 'starts_at' => now()->addMinutes($startsInMinutes)]);
}

function layoutRunning(TournamentFormat $format, int $n): Tournament
{
    $tournament = runningChess($format, $n);
    $tournament->forceFill(['name' => 'Chess Casual Cup EU #2', 'published_at' => now()->subHour()])->save();

    foreach ($tournament->participants()->orderBy('seed')->get() as $index => $participant) {
        $participant->forceFill(['name' => LAYOUT_NAMES[$index % 8]])->save();
    }

    return $tournament->refresh();
}

test('bracket scene before the draw: the tournament named once, under the 21 mark, never covered', function () {
    $cup = layoutCup('Rocket League Casual Cup EU #2', 630, 'rocket-league');
    OverlayPreset::factory()->withToken($token = str_repeat('r', 48))->create(['locale' => 'de', 'variant' => OverlayVariant::Bracket, 'tournament_id' => $cup->id]);

    $page = layoutPage($token);
    layoutSettled($page, 5_000);
    layoutHolds($page, ['mark', 'clock', 'Rocket League Casual Cup EU #2']);
});

test('break scene, starting soon: the clock shows all eight characters inside its column', function () {
    $cup = layoutCup('Chess Casual Cup EU #2', 629);
    OverlayPreset::factory()->withToken($token = str_repeat('s', 48))->create(['locale' => 'de', 'variant' => OverlayVariant::Break, 'tournament_id' => $cup->id, 'modules' => ['music' => false] + OverlayPreset::MODULES]);

    $page = layoutPage($token);
    layoutSettled($page, 5_000);
    layoutHolds($page, ['mark', 'clock', 'Chess Casual Cup EU #2']);
});

test('break scene, the end without a tournament: hero and column clear of each other', function () {
    OverlayPreset::factory()->withToken($token = str_repeat('t', 48))->create(['locale' => 'de', 'variant' => OverlayVariant::Break, 'scene' => 'end', 'modules' => ['music' => false] + OverlayPreset::MODULES]);

    $page = layoutPage($token);
    layoutSettled($page, 5_000);
    layoutHolds($page, ['mark']);
});

test('bracket scene, running: the page of long names stands inside the frame without overlaps', function () {
    $tournament = layoutRunning(TournamentFormat::SingleElimination, 8);
    OverlayPreset::factory()->withToken($token = str_repeat('u', 48))->create(['locale' => 'de', 'variant' => OverlayVariant::Bracket, 'tournament_id' => $tournament->id]);

    $page = layoutPage($token);
    layoutSettled($page, 6_000);
    layoutHolds($page, ['Chess Casual Cup EU #2', 'Satoshis_']);
});

test('tournament overlay, running: banner, board and ticker clear of each other with long names', function () {
    $tournament = layoutRunning(TournamentFormat::SingleElimination, 8);
    OverlayPreset::factory()->withToken($token = str_repeat('v', 48))->create(['locale' => 'de', 'variant' => OverlayVariant::Tournament, 'tournament_id' => $tournament->id]);

    $page = layoutPage($token);
    layoutSettled($page, 5_000);
    layoutHolds($page, ['Chess Casual Cup EU #2']);
});

test('tournament overlay before the start: the countdown board with its long name', function () {
    $cup = layoutCup('Rocket League Casual Cup EU #2', 630, 'rocket-league');
    OverlayPreset::factory()->withToken($token = str_repeat('w', 48))->create(['locale' => 'de', 'variant' => OverlayVariant::Tournament, 'tournament_id' => $cup->id]);

    $page = layoutPage($token);
    layoutSettled($page, 5_000);
    layoutHolds($page, ['Rocket League Casual Cup EU #2']);
});

test('league live: the corner moment, ticker and camera frame clear of each other', function () {
    $winner = User::factory()->create(['name' => LAYOUT_NAMES[0]]);
    $loser = User::factory()->create(['name' => LAYOUT_NAMES[1]]);
    ChessGame::factory()->finished('1-0')->create(['white_id' => $winner->id, 'black_id' => $loser->id]);
    layoutCup('Rocket League Casual Cup EU #2', 600);
    OverlayPreset::factory()->withToken($token = str_repeat('x', 48))->create(['locale' => 'de', 'modules' => ['cam-frame' => true] + OverlayPreset::MODULES]);

    $page = layoutPage($token);
    layoutSettled($page, 5_000);
    layoutHolds($page, [LAYOUT_NAMES[0]]);
});
