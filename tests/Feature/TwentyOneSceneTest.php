<?php

use App\Enums\ChessEndReason;
use App\Enums\TournamentFormat;
use App\Models\ChessGame;
use App\Models\User;
use App\Support\Chess\ChessGameService;
use App\Support\TwentyOne\Stream\RotationPlanner;
use App\Support\TwentyOne\Stream\SceneRenderer;
use App\Support\TwentyOne\Stream\SceneSource;
use App\Support\TwentyOne\Stream\StreamStats;
use App\Support\TwentyOne\Stream\StreamTexts;
use App\Support\TwentyOne\Stream\TournamentSlides;
use Illuminate\Support\Facades\Blade;
use Tests\Support\TestSigner;

test('the scene shows the oldest live game with escaped, shortened public names', function () {
    $older = ChessGame::factory()->create([
        'white_id' => User::factory()->create(['name' => 'Pleb <script>alert(1)</script>']),
        'black_id' => User::factory()->create(['name' => str_repeat('Satoshi', 6)]),
    ]);
    ChessGame::factory()->create();
    ChessGame::factory()->daily()->create();
    $source = app(SceneSource::class);

    $game = $source->liveGame();
    $scene = $source->scene($game, (int) now()->getTimestampMs());
    $svg = SceneRenderer::fromConfig()->svg($scene);

    expect($game->id)->toBe($older->id)
        ->and($scene['mode'])->toBe('LIVE · CHESS BLITZ 5+3 · CASUAL')
        ->and($scene['result'])->toBeNull()
        ->and($svg)->not->toContain('<script>')
        // 25 characters at most: 24 and an ellipsis, escaped as text.
        ->and($svg)->toContain('>Pleb &lt;script&gt;alert(1)&lt;/s…<')
        ->and($svg)->toContain('>'.str_repeat('Satoshi', 3).'Sat…<')
        // Daily games count as live too.
        ->and($svg)->toContain('>0 games played · 3 live · 0 clans<');
});

test('after the game the scene shows the result, and it is gone after the hysteresis', function () {
    $game = ChessGame::factory()->finished('0-1', ChessEndReason::Timeout)->create();
    $source = app(SceneSource::class);

    expect($source->liveGame())->toBeNull()
        ->and($source->scene($source->endedGame(60), (int) now()->getTimestampMs())['result'])->toBe('0-1 · White ran out of time');

    $this->travel(61)->seconds();

    expect($source->endedGame(60))->toBeNull()
        ->and($game->id)->toBeInt();
});

test('the scene shows a name with line breaks and bidi controls as plain text', function () {
    $game = ChessGame::factory()->create(['white_id' => User::factory()->create(['name' => "Mallory\u{202E}gnp\nline two"])]);
    $source = app(SceneSource::class);

    $svg = SceneRenderer::fromConfig()->svg($source->scene($game, (int) now()->getTimestampMs()));

    expect($svg)->toContain('>Mallory gnp line two<');
});

test('a blitz game has priority, otherwise the daily game with the most recent move', function () {
    $nowMs = (int) now()->getTimestampMs();
    ChessGame::factory()->daily()->create(['turn_started_ms' => $nowMs - 3_600_000]);
    $recent = ChessGame::factory()->daily()->create(['turn_started_ms' => $nowMs - 60_000]);
    ChessGame::factory()->daily()->create(['turn_started_ms' => $nowMs - 7_200_000]);
    $source = app(SceneSource::class);

    expect($source->liveGame()->id)->toBe($recent->id);

    $blitz = ChessGame::factory()->create();

    expect($source->liveGame()->id)->toBe($blitz->id);
});

test('a daily game renders as correspondence with the time left for the move', function () {
    $nowMs = (int) now()->getTimestampMs();
    // White to move with 23:59:59 left (the widest daily clock); Black's stored time stays as it is.
    $game = ChessGame::factory()->daily()->create(['deadline_ms' => $nowMs + 86_399_000, 'black_ms' => 3_000]);
    $source = app(SceneSource::class);

    $scene = $source->scene($source->liveGame(), $nowMs);
    $svg = SceneRenderer::fromConfig()->svg($scene);
    preg_match_all('/<text x="([\d.]+)" y="\d+" font-family="Unbounded" font-weight="800" font-size="([\d.]+)" fill="#\w+" text-anchor="middle">([\d:])</', $svg, $digits);

    expect($scene['mode'])->toBe('LIVE · CHESS CORRESPONDENCE · CASUAL')
        ->and(mb_strlen($scene['mode']))->toBeLessThanOrEqual(40)
        ->and($scene['white'])->toMatchArray(['clockMs' => 86_399_000, 'toMove' => true])
        ->and($scene['black'])->toMatchArray(['clockMs' => 3_000, 'toMove' => false])
        // One <text> per clock character, black's card first; from 1 h up h:mm, no seconds.
        ->and(implode('', $digits[3]))->toBe('0:03'.'23:59')
        // Right edge of the last digit (75 px advance at 80 px) inside the card's inner edge.
        ->and((float) end($digits[1]) + 37.5 * (float) end($digits[2]) / 80)->toBeLessThanOrEqual(1220.5)
        ->and(StreamTexts::for($game)['title'])->toEndWith(' · Chess Correspondence');
});

test('a daily game that just ended shows its result', function () {
    ChessGame::factory()->daily()->finished('1-0', ChessEndReason::Resignation)->create();
    $source = app(SceneSource::class);

    expect($source->liveGame())->toBeNull()
        ->and($source->scene($source->endedGame(60), (int) now()->getTimestampMs())['result'])->toBe('1-0 · Black resigned');
});

test('the gallery takes every live game, blitz first, and counts what does not fit', function () {
    $nowMs = (int) now()->getTimestampMs();
    $daily = collect([3, 1, 2])->map(fn (int $minutesAgo) => ChessGame::factory()->daily()->create(['turn_started_ms' => $nowMs - $minutesAgo * 60_000]));
    $blitz = ChessGame::factory()->count(5)->create();
    $source = app(SceneSource::class);

    $selection = $source->sceneGames(60);

    // 5 blitz by age, then the daily game with the most recent move; two daily games left over.
    expect(array_map(fn (ChessGame $game): int => $game->id, $selection['games']))->toBe([...$blitz->pluck('id')->all(), $daily[1]->id])
        ->and($selection['more'])->toBe(2);
});

test('a game that just ended keeps its card while others run, and gives way first', function () {
    $ended = ChessGame::factory()->finished('0-1', ChessEndReason::Timeout)->create();
    $live = ChessGame::factory()->create();
    $source = app(SceneSource::class);

    $scene = $source->gallery(...[...array_values($source->sceneGames(60)), (int) now()->getTimestampMs()]);

    expect(array_column($scene['games'], 'result'))->toBe(['0-1 · White ran out of time', null])
        ->and($scene['more'])->toBe(0)
        ->and($scene)->toHaveKeys(['stats', 'url'])
        ->and(SceneRenderer::fromConfig()->svg($scene))->toContain('>1 GAME LIVE<');

    ChessGame::factory()->count(5)->create();

    expect(collect($source->sceneGames(60)['games'])->contains(fn (ChessGame $game): bool => $game->is($ended)))->toBeFalse()
        ->and(collect($source->sceneGames(60)['games'])->contains(fn (ChessGame $game): bool => $game->is($live)))->toBeTrue();
});

test('the gallery renders 2, 4 and 6 games on the 1280x720 still, names escaped', function (int $count) {
    ChessGame::factory()->count($count - 1)->create();
    ChessGame::factory()->create(['white_id' => User::factory()->create(['name' => 'Pleb <script>alert(1)</script>'])]);
    $source = app(SceneSource::class);
    ['games' => $games, 'more' => $more] = $source->sceneGames(60);

    $svg = SceneRenderer::fromConfig()->svg($source->gallery($games, $more, (int) now()->getTimestampMs()));

    expect($svg)->toContain('width="1280" height="720" viewBox="0 0 1280 720"')
        ->and($svg)->toContain($count.' GAMES LIVE')
        ->and($svg)->not->toContain('<script>')
        ->and($svg)->toContain('>Pleb &lt;')
        ->and(substr_count($svg, 'href="#p-wk"'))->toBe($count * 4);
})->with([2, 4, 6]);

test('the 30311 texts name one game, or count several and list three pairings', function () {
    $games = collect(['Alice', 'Carol', 'Erin', 'Grace'])->map(fn (string $name) => ChessGame::factory()->create([
        'white_id' => User::factory()->create(['name' => $name]),
        'black_id' => User::factory()->create(['name' => $name."\u{202E}x"]),
    ]));

    $one = StreamTexts::forGames([$games[0]]);
    $four = StreamTexts::forGames($games->all(), 2);

    expect($one['title'])->toBe('Live now: Alice vs Alice x · Chess Blitz')
        ->and($four['title'])->toBe('Live now: 6 chess games')
        ->and($four['summary'])->toStartWith('Alice vs Alice x, Carol vs Carol x, Erin vs Erin x and 3 more: live chess on TWENTY ONE Esports');
});

test('a clock shows m:ss below one hour and h:mm from one hour up', function () {
    $game = fn (int $ms): array => ['name' => 'P', 'clockMs' => $ms, 'toMove' => false];
    $svg = SceneRenderer::fromConfig()->svg([
        'white' => $game(3_599_999), 'black' => $game(3_600_000 + 59_000), 'fen' => ChessGame::START_FEN, 'lastMove' => null,
        'mode' => 'LIVE · CHESS BLITZ 5+3 · CASUAL', 'result' => null, 'stats' => 's', 'url' => 'u',
    ]);
    preg_match_all('/font-family="Unbounded" font-weight="800" font-size="[\d.]+" fill="#\w+" text-anchor="middle">([\d:])</', $svg, $digits);

    // Black's card first: 1 h 0 min 59 s, then White's 59:59.
    expect(implode('', $digits[1]))->toBe('1:00'.'59:59');
});

test('the stream views define no closures, which leak on every render without the CLI opcache', function () {
    $views = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views/stream'), FilesystemIterator::SKIP_DOTS));
    $closures = [];

    foreach ($views as $view) {
        $tokens = token_get_all(Blade::compileString(file_get_contents($view->getPathname())));

        foreach ($tokens as $token) {
            if (is_array($token) && in_array($token[0], [T_FUNCTION, T_FN], true)) {
                $closures[] = $view->getFilename().':'.$token[2];
            }
        }
    }

    expect($closures)->toBe([]);
});

test('every rotation scene renders from the real data: moves in SAN, the QR codes, escaped names', function () {
    config(['esports.league.nsec' => (new TestSigner)->secret]);
    $tournament = openTournament(['name' => 'Cup <script>alert(2)</script>', 'format' => TournamentFormat::SingleElimination, 'capacity' => 4]);
    [$entrant, $entrantSigner] = keyedPlayer();
    $entrant->forceFill(['name' => 'Seed <b>one</b>'])->save();
    soloSignup($tournament, $entrant, $entrantSigner);
    $chess = app(ChessGameService::class);
    $white = User::factory()->create(['name' => 'Pleb <script>alert(1)</script>']);
    $black = User::factory()->create(['name' => 'Satoshi']);
    $game = $chess->start($white, $black);
    foreach (['e2e4', 'e7e5', 'g1f3'] as $i => $uci) {
        $game = $chess->move($game->refresh(), $i % 2 === 0 ? $white : $black, $uci);
    }
    ChessGame::factory()->daily()->create();
    $source = app(SceneSource::class);
    ['games' => $games, 'more' => $more] = $source->sceneGames(60);
    $stats = app(StreamStats::class)->all();
    $renderer = SceneRenderer::fromConfig();
    $slide = app(TournamentSlides::class)->data($tournament->refresh(), (int) now()->getTimestampMs());

    $svgs = [];
    foreach (RotationPlanner::VIEWS as $scene => $view) {
        $svgs[$scene] = $renderer->svg($source->rotation($scene, $game->id, $games, $more, (int) now()->getTimestampMs(), $stats, $slide), $view);
    }

    expect($source->moves($game->refresh()))->toBe(['e4', 'e5', 'Nf3'])
        ->and(array_filter($svgs, fn (string $svg): bool => ! str_contains($svg, 'width="1280" height="720"')))->toBe([])
        ->and(array_filter($svgs, fn (string $svg): bool => str_contains($svg, '<script>')))->toBe([])
        ->and($svgs['b1'])->toContain('Nf3')
        ->and($svgs['c1'])->toContain('Nf3')
        ->and($svgs['a1'])->toContain('Pleb &lt;script')
        ->and(array_filter(array_intersect_key($svgs, array_flip(RotationPlanner::TOURNAMENT_SCENES)), fn (string $svg): bool => ! str_contains($svg, 'Cup &lt;script&gt;')))->toBe([])
        ->and($svgs['ta1'].$svgs['tb1'].$svgs['tc1'])->toContain($slide['countdown'])
        ->and($svgs['ta2'].$svgs['tb2'].$svgs['tc2'])->toContain('Seed &lt;b&gt;one')
        ->and(implode('', $svgs))->not->toContain('<b>one')
        // The QR codes are drawn (their module path), never the address as text.
        ->and($svgs['a5'])->toContain('<path style="stroke:#17120a"')
        ->and($svgs['c5'])->toContain('<path style="stroke:#17120a"')
        ->and($svgs['c4'])->toContain('<path style="stroke:#17120a"')
        ->and(implode('', $svgs))->not->toContain('getalby')
        ->and(implode('', $svgs))->not->toContain('LNURL');
});
