<?php

/*
 * The stream rotation with a series game as new as Age of Empires II (813bf8f2): its name without the edition on
 * every slide, its wins on the streak slide, its players on the mempool, its cups on the weekend board (d2) and a
 * slide of its own (d6, GameSpotlight).
 */

use App\Enums\SeriesResolution;
use App\Enums\SeriesStatus;
use App\Enums\TournamentFormat;
use App\Models\Rating;
use App\Models\SeriesMatch;
use App\Models\User;
use App\Support\SeasonChain\Seasons;
use App\Support\Series\CasualMatches;
use App\Support\Tournaments\CasualCups;
use App\Support\TwentyOne\Stream\GameSpotlight;
use App\Support\TwentyOne\Stream\MempoolSlides;
use App\Support\TwentyOne\Stream\PrideSlides;
use App\Support\TwentyOne\Stream\RotationPlanner;
use App\Support\TwentyOne\Stream\SceneRenderer;
use App\Support\TwentyOne\Stream\SceneSource;
use App\Support\TwentyOne\Stream\StreamStats;
use App\Support\TwentyOne\Stream\TournamentPlaybook;
use App\Support\TwentyOne\Stream\TournamentSlides;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\CheckersGame;
use Tests\Support\NineMensMorrisOn;
use Tests\Support\TestSigner;

beforeEach(function () {
    Cache::flush();
});

/** A decided casual 1v1 of Age of Empires II that `$winner` won, finished `$minutesAgo` minutes ago. */
function aoeWin(User $winner, User $loser, int $minutesAgo, ?SeriesResolution $resolution = SeriesResolution::Confirmed): SeriesMatch
{
    $match = app(CasualMatches::class)->create($winner, $loser, 'age-of-empires-2', SeriesMatch::ORIGIN_QUEUE, []);
    $match->forceFill(['status' => $resolution === SeriesResolution::Forfeit ? SeriesStatus::Resolved : SeriesStatus::Confirmed, 'resolution' => $resolution, 'winner' => 'challenger',
        'finished_at' => now()->subMinutes($minutesAgo), 'result_games' => $resolution === SeriesResolution::Forfeit ? [] : [['winner' => 'challenger', 'challenger' => null, 'challenged' => null]]])->save();

    return $match;
}

/** A rotation scene rendered as the stream renders it. */
function seriesScene(string $scene, array $upcoming = []): string
{
    return SceneRenderer::fromConfig()->svg([...app(SceneSource::class)->rotation($scene, null, [], 0, (int) now()->getTimestampMs(), app(StreamStats::class)->all(), null, $upcoming), 'viewers' => null], RotationPlanner::VIEWS[$scene]);
}

/** The visible text of a scene: every <text> element's content, the markup's comments and definitions left out. */
function seriesSceneText(string $svg): string
{
    preg_match_all('#<text[^>]*>(.*?)</text>#s', (string) preg_replace('#<defs>.*?</defs>#s', '', $svg), $texts);

    return html_entity_decode(strip_tags(implode(' | ', $texts[1])));
}

test('a series game\'s wins make a streak, a forfeit counts neither way, and the game is named without its edition', function () {
    [$joan, $ben, $hal] = [User::factory()->create(['name' => 'Joan of Arc']), User::factory()->create(['name' => 'Ben']), User::factory()->create(['name' => 'Hal'])];
    foreach ([40, 30, 20] as $minutes) {
        aoeWin($joan, $ben, $minutes);
    }
    // The newest result is a forfeit Joan lost: it neither ends her streak nor starts Hal's.
    aoeWin($hal, $joan, 5, SeriesResolution::Forfeit);

    $pride = app(PrideSlides::class)->read();
    $e8 = seriesSceneText(seriesScene('e8'));

    expect($pride['streaks'])->toHaveCount(1)
        ->and($pride['streaks'][0])->toMatchArray(['name' => 'Joan of Arc', 'wins' => 3, 'games' => ['Age of Empires II']])
        ->and($e8)->toContain('Joan of Arc', 'wins in a row', 'in Age of Empires II')
        ->and($e8)->not->toContain('Definitive');
});

test('every slide names Age of Empires II without its edition, while the pride note keeps the full name', function () {
    config(['esports.league.nsec' => (new TestSigner)->secret]);
    [$saladin, $joan] = [User::factory()->create(['name' => 'Saladin']), User::factory()->create(['name' => 'Joan of Arc'])];
    aoeWin($saladin, $joan, 10);
    Rating::query()->create(['pool' => Rating::CASUAL, 'season' => '', 'game' => 'age-of-empires-2', 'mode' => '1v1', 'subject' => 'user:'.$saladin->id, 'user_id' => $saladin->id, 'rating' => 1080, 'results' => 3, 'wins' => 3]);
    $open = openTournament(['name' => 'Castle Age Open', 'game' => 'age-of-empires-2', 'mode' => '1v1', 'format' => TournamentFormat::SingleElimination, 'capacity' => 8]);

    $pride = app(PrideSlides::class)->read();
    $board = collect(app(StreamStats::class)->count()['boards'])->firstWhere('game', 'age-of-empires-2');
    $frame = collect(app(TournamentSlides::class)->all((int) now()->getTimestampMs()))->firstWhere('id', $open->id);
    $scenes = ['e1' => seriesSceneText(seriesScene('e1')), 'a3' => seriesSceneText(seriesScene('a3')), 'c3' => seriesSceneText(seriesScene('c3'))];

    expect($pride['win'])->toMatchArray(['kind' => 'series', 'mode' => 'Age of Empires II: Definitive Edition 1v1', 'shownMode' => 'Age of Empires II 1v1'])
        ->and($board['gameName'])->toBe('Age of Empires II')
        ->and($frame['game'])->toBe('Age of Empires II')
        ->and($scenes['e1'])->toContain('Age of Empires II 1v1')
        ->and($scenes['a3'].$scenes['c3'])->toContain('Age of Empires II')
        ->and(implode(' ', $scenes))->not->toContain('Definitive');
});

test('a best of 1 is one game a match, and a series asks to be there at the start, not at kickoff', function () {
    config(['esports.league.nsec' => (new TestSigner)->secret]);
    $open = openTournament(['game' => 'age-of-empires-2', 'mode' => '1v1', 'format' => TournamentFormat::SingleElimination, 'capacity' => 8]);

    expect(TournamentPlaybook::matches(true, 1, 3, false))->toBe('One game a match, the final best of 3')
        ->and(TournamentPlaybook::matches(true, 1, 1, false))->toBe('One game a match')
        ->and(TournamentPlaybook::matches(true, 3, 5, false))->toBe('Best of 3, the final best of 5')
        ->and(TournamentPlaybook::of($open, 8, 'Europe/Berlin')['showUp'])->toStartWith('Be there at the start, ');
});

test('the casual cups board gives every open cup game its row under its weekday, the newest game included', function () {
    config(['esports.league.nsec' => (new TestSigner)->secret]);
    NineMensMorrisOn::play();
    CheckersGame::play();
    config(['esports.casual_cups.enabled' => ['chess', 'rocket-league', 'ea-sports-fc-26', 'ea-sports-fc-27', 'age-of-empires-2', 'nine-mens-morris', 'checkers']]);
    foreach (CasualCups::enabledGames() as $game) {
        foreach (array_keys(CasualCups::regions()) as $region) {
            app(CasualCups::class)->ensure($game, $region);
        }
    }

    $svg = seriesScene('d2', app(TournamentSlides::class)->all((int) now()->getTimestampMs()));
    $text = seriesSceneText($svg);

    expect(substr_count($svg, 'data-unit="cup-game-'))->toBe(7)
        ->and($text)->toContain('Friday', 'Saturday', 'Sunday', 'EA Sports FC 26', 'Rocket League', "Nine Men's Morris", 'Age of Empires II', 'EU · 0 / 4 signed up', 'US · 0 / 4 signed up')
        // The Age of Empires II cups are one lobby match (P10) that opens small like every cup, and the pitch says so.
        ->and($text)->toContain('One per region. Age of Empires II: one 2 h diplomacy lobby, 2 to 8, wins shared.')->not->toContain('0 / 40 signed up')
        // Sunday: Checkers in the afternoon, then Age of Empires II at its evening slot, on each region's clock.
        ->and(strpos($text, 'Sunday'))->toBeLessThan(strpos($text, 'Checkers'))
        ->and(strpos($text, 'Checkers'))->toBeLessThan(strrpos($text, 'Age of Empires II'))
        ->and(substr($text, strpos($text, 'Checkers') - 8, 5))->toBe('15:00')
        ->and(substr($text, strrpos($text, 'Age of Empires II') - 8, 5))->toBe('20:00')
        ->and($text)->not->toContain('Definitive');
});

test('a player\'s series in the mempool shows the player\'s name and avatar, not the tag, at a flat query count', function () {
    [$anna, $bert] = [User::factory()->create(['name' => 'Anna Castellan']), User::factory()->create(['name' => 'Bert'])];
    $match = app(CasualMatches::class)->create($anna, $bert, 'age-of-empires-2', SeriesMatch::ORIGIN_QUEUE, []);
    $match->forceFill(['start_at' => now()->subMinutes(3)])->save();
    $queries = function (): int {
        Cache::forget(MempoolSlides::CACHE_KEY);
        // The daemon keeps no live season between reads (Seasons::live() asks in every console process).
        Seasons::forget();
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(MempoolSlides::class)->read();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    $one = $queries();
    $cube = collect(app(MempoolSlides::class)->read()['running'])->firstWhere('slug', 'age-of-empires-2');
    $svg = seriesSceneText(seriesScene('m1'));
    foreach (range(1, 3) as $ignored) {
        app(CasualMatches::class)->create(User::factory()->create(), User::factory()->create(), 'age-of-empires-2', SeriesMatch::ORIGIN_QUEUE, [])->forceFill(['start_at' => now()->subMinute()])->save();
    }

    expect(array_column($cube['sides'], 'name'))->toBe(['Anna Castellan', 'Bert'])
        ->and(array_column($cube['sides'], 'tag'))->toBe(['', ''])
        ->and(array_column($cube['sides'], 'ref'))->each->not->toBeNull()
        // The cube is narrow: the name fitted, never the tag.
        ->and($svg)->toContain('Anna Castel', 'vs Bert', 'Age of Empires II')
        ->and($svg)->not->toContain($match->challenger_tag)
        ->and($queries())->toBe($one);
});

test('the spotlight slide shows the newest series game, how it is played here and who holds its top spot', function () {
    config(['esports.casual_cups.enabled' => ['chess', 'age-of-empires-2']]);
    $empty = seriesScene('d6');
    $saladin = User::factory()->create(['name' => 'Saladin <b>']);
    Rating::query()->create(['pool' => Rating::CASUAL, 'season' => '', 'game' => 'age-of-empires-2', 'mode' => '1v1', 'subject' => 'user:'.$saladin->id, 'user_id' => $saladin->id, 'rating' => 1210, 'results' => 4, 'wins' => 4]);
    Cache::flush();
    $stats = app(StreamStats::class)->all();
    DB::flushQueryLog();
    DB::enableQueryLog();
    $data = app(SceneSource::class)->rotation('d6', null, [], 0, 0, $stats);
    $sceneQueries = count(DB::getQueryLog());
    DB::disableQueryLog();
    $svg = SceneRenderer::fromConfig()->svg([...$data, 'viewers' => 12], RotationPlanner::VIEWS['d6']);
    $text = seriesSceneText($svg);

    expect(RotationPlanner::TEASERS)->toContain('d6')
        ->and(RotationPlanner::FEATURE_SCENES)->toContain('d6')
        // Its tournaments and cups are one lobby match (P10): the claim sells that, not a best of.
        ->and($data['spotlight'])->toMatchArray(['slug' => 'age-of-empires-2', 'name' => 'Age of Empires II', 'claim' => 'Lobbies of 2 to 8. Diplomacy, 2 h, shared wins.'])
        ->and(array_column($data['spotlight']['facts'], 'label'))->toBe(['Casual 1v1', 'Lobby', 'Cups', 'Ladder'])
        ->and(array_column($data['spotlight']['facts'], 'line'))->toContain('Sundays at 20:00 local time, EU and US: one lobby match.', '1v1 for you, 2v2 and 3v3 for your clan.')
        ->and($data['spotlight']['leader'])->toMatchArray(['name' => 'Saladin <b>', 'elo' => 1210, 'ladder' => '1v1 casual ladder'])
        // Its leader comes out of the stats the stream already read: the slide costs no query of its own.
        ->and($sceneQueries)->toBe(0)
        ->and($text)->toContain('Age of Empires II', 'Saladin <b>', '#1 on the 1v1 casual ladder, 1210 Elo', 'Play', 'esports.einundzwanzig.space/games/age-of-empires-2')
        ->and($svg)->not->toContain('<b>')
        ->and(seriesSceneText($empty))->toContain('The top spot is open.')
        ->and($text.seriesSceneText($empty))->not->toMatch('/\b(face|faces|fee|fees|Definitive)\b|#[a-z]/i');

    // Another series game in the spotlight, and an unknown one falls back to the newest (the last registered).
    config(['twentyone.stream.rotation.spotlight' => 'rocket-league']);
    $rocket = app(GameSpotlight::class)->data($stats);
    config(['twentyone.stream.rotation.spotlight' => 'no-such-game']);

    expect($rocket['name'])->toBe('Rocket League')
        ->and(array_column($rocket['facts'], 'label'))->toBe(['Casual 1v1', 'Lobby', 'Ladder'])
        ->and(app(GameSpotlight::class)->game()?->slug())->toBe('age-of-empires-2');
});
