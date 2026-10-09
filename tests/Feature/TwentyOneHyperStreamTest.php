<?php

/*
|--------------------------------------------------------------------------
| Hyperbitcoinization on the stream (plan "Hyperbitcoinization", P6, Ansatz 9)
|--------------------------------------------------------------------------
|
| The slide of a running match (h1, HyperScene): its slot in the rotation (60 s, up to 180 s while tense, one
| match a round, matches taking turns, gone with the match or the switch), what it shows (map, factions,
| chronicle, tension), that it follows the match while it stands, at a readable pace, and that it names
| players as the other slides do.
|
*/

use App\Enums\HyperMatchStatus;
use App\Models\ChessGame;
use App\Models\HyperAction;
use App\Models\HyperMatch;
use App\Models\User;
use App\Support\Hyper\HyperGame;
use App\Support\Hyper\HyperMap;
use App\Support\TwentyOne\Stream\BoardScene;
use App\Support\TwentyOne\Stream\HyperScene;
use App\Support\TwentyOne\Stream\RotationPlanner;
use App\Support\TwentyOne\Stream\SceneRenderer;
use App\Support\TwentyOne\Stream\SceneSource;
use App\Support\TwentyOne\Stream\StreamImages;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Tests\Support\HyperOn;

beforeEach(function () {
    Queue::fake();
});

/**
 * The rotation over `$seconds` as "start kind scene[:id]" lines, one per slot; `$hyper` gives the reported
 * matches at second t.
 *
 * @param  Closure(float): list<array{id: int, tense: bool}>  $hyper
 * @param  list<array{id: int, blitz: bool}>  $games
 * @return list<string>
 */
function hyperRotation(RotationPlanner $planner, float $seconds, Closure $hyper, array $games = [], string $boards = BoardScene::OFF): array
{
    $log = [];
    $last = null;

    for ($t = 0.0; $t < $seconds; $t += 0.5) {
        $slot = $planner->at($t, $games, [], $boards, [], 'off', null, $hyper($t));
        $key = $slot['kind'].' '.$slot['scene'].' '.$slot['gameId'];

        if ($key !== $last) {
            $log[] = sprintf('%g %s %s', $t, $slot['kind'], $slot['scene'] ?? '-').($slot['kind'] === RotationPlanner::HYPER ? ':'.$slot['gameId'] : '');
            $last = $key;
        }
    }

    return $log;
}

/** match_seconds 45, blitz 60, gallery 20, teaser 12, 3 teasers, loop every 3rd round of 30 s, tournament 15; Hyper 60..180. */
function hyperPlanner(): RotationPlanner
{
    return new RotationPlanner(45, 60, 20, 12, 3, 3, 30, 15, false, false, 90, 120, 60, 180);
}

/**
 * A running match of two players and `$bots` bots, its territories dealt round-robin (every seventh neutral).
 */
function hyperStreamMatch(User $a, User $b, int $bots = 2): HyperMatch
{
    $match = HyperOn::versus($a, $b, bots: $bots);
    $state = HyperGame::fromArray($match->state)->toArray();
    $seats = count($state['seats']);

    foreach (HyperMap::IDS as $i => $id) {
        $state['territories'][$id]['owner'] = $i % 7 === 6 ? null : $i % $seats;
    }

    $match->forceFill(['state' => HyperGame::fromArray($state)->toArray()])->save();

    return $match->refresh();
}

/** One stored action whose events conquer `$territory` for `$seat` (and topple its bank, if it has one). */
function hyperConquest(HyperMatch $match, int $seat, string $territory, ?int $from): void
{
    $events = [['type' => 'territory_conquered', 'seat' => $seat, 'territory' => $territory, 'from' => 'x', 'previous_owner' => $from, 'units' => 2]];
    $index = array_search($territory, HyperMap::IDS, true);

    if (HyperMap::BANK[$index]) {
        $events[] = ['type' => 'bank_fallen', 'seat' => $seat, 'territory' => $territory, 'zone' => HyperMap::ZONE_KEYS[HyperMap::ZONE[$index]]];
    }

    $ply = $match->ply + 1;
    HyperAction::query()->create(['hyper_match_id' => $match->id, 'ply' => $ply, 'seat' => $seat, 'source' => HyperAction::PLAYER, 'action' => ['type' => 'attack'], 'events' => $events]);
    $state = $match->state;
    $state['territories'][$territory]['owner'] = $seat;
    $match->forceFill(['state' => $state, 'ply' => $ply])->save();
}

/** @return array{0: array<string, mixed>, 1: string} the slide's data and SVG at `$nowMs` */
function hyperSlide(?int $matchId, ?int $nowMs = null): array
{
    $data = app(SceneSource::class)->rotation(HyperScene::SCENE, $matchId, [], 0, $nowMs ?? (int) now()->getTimestampMs(), []);

    return [$data, SceneRenderer::fromConfig()->svg([...$data, 'viewers' => 7], RotationPlanner::VIEWS[HyperScene::SCENE])];
}

/* ---------- The slot in the rotation ------------------------------------------------------------------------ */

test('a running match stands 60 s once a round after the board slot; calm, it gives way on time', function () {
    $calm = fn (float $t): array => [['id' => 7, 'tense' => false]];

    expect(array_slice(hyperRotation(hyperPlanner(), 600, $calm, [['id' => 1, 'blitz' => true]], BoardScene::LIVE), 0, 9))->toBe([
        '0 match a1', '60 board d5', '105 hyper h1:7', '165 teaser d1', '177 teaser d2', '189 teaser e1', '201 teaser a3', '213 teaser a4', '225 teaser a5',
    ])
        // Without other games: the loop first, then the match opens the next round.
        ->and(array_slice(hyperRotation(hyperPlanner(), 200, $calm), 0, 3))->toBe(['0 loop -', '30 hyper h1:7', '90 teaser d1']);
});

test('a tense match stands longer, 20 s at a time, never past 180 s; calm again, it gives way at the next step', function () {
    $tense = fn (float $t): array => [['id' => 7, 'tense' => true]];
    // Tense until second 125 of the stream (the slot started at 30): extended at 90 and 110, then calm at 130.
    $cooling = fn (float $t): array => [['id' => 7, 'tense' => $t < 125]];

    expect(array_slice(hyperRotation(hyperPlanner(), 400, $tense), 0, 3))->toBe(['0 loop -', '30 hyper h1:7', '210 teaser d1'])
        ->and(array_slice(hyperRotation(hyperPlanner(), 400, $cooling), 0, 3))->toBe(['0 loop -', '30 hyper h1:7', '130 teaser d1']);
});

test('several running matches take turns round by round, one a round', function () {
    $two = fn (float $t): array => [['id' => 7, 'tense' => false], ['id' => 9, 'tense' => false]];
    $hyperSlots = array_values(array_filter(hyperRotation(hyperPlanner(), 1200, $two, [['id' => 1, 'blitz' => true]]), fn (string $line): bool => str_contains($line, 'hyper')));

    // Rounds of 192 s: match 60, Hyperbitcoinization 60, three every-round teasers and three teasers of 12 s.
    expect(array_slice($hyperSlots, 0, 4))->toBe(['60 hyper h1:7', '252 hyper h1:9', '444 hyper h1:7', '636 hyper h1:9']);
});

test('the slot ends at once when its match ends or the game is switched off, and never shows while none runs', function () {
    // The match is over at second 50 of its slot (which started at 30).
    $ending = fn (float $t): array => $t < 80 ? [['id' => 7, 'tense' => true]] : [];

    expect(array_slice(hyperRotation(hyperPlanner(), 300, $ending), 0, 3))->toBe(['0 loop -', '30 hyper h1:7', '80 teaser d1'])
        ->and(implode(' ', hyperRotation(hyperPlanner(), 900, fn (float $t): array => [], [['id' => 1, 'blitz' => true]])))->not->toContain('hyper');
});

test('a running tournament holds the stream: no Hyperbitcoinization slot in between', function () {
    $planner = hyperPlanner();
    $running = [['id' => 5, 'phase' => 'running', 'fomo' => false]];
    $kinds = [];

    for ($t = 0.0; $t < 600; $t += 1) {
        $kinds[$planner->at($t, [], [], BoardScene::OFF, $running, 'off', null, [['id' => 7, 'tense' => true]])['kind']] = true;
    }

    expect(array_keys($kinds))->toBe([RotationPlanner::TOURNAMENT]);
});

/* ---------- The running matches and their tension ------------------------------------------------------------ */

test('the rotation gets the running matches with a player, live first, and none while the game is off', function () {
    [$anna, $bert] = User::factory()->count(2)->create();
    config(['esports.hyper.enabled' => false]);

    expect(app(HyperScene::class)->entries(0))->toBe([]);

    HyperOn::play();
    $live = hyperStreamMatch($anna, $bert);
    $daily = hyperStreamMatch($anna, $bert);
    $daily->forceFill(['mode' => HyperMatch::CORRESPONDENCE])->save();
    // A bots-only table and a finished match are no show.
    $botsOnly = hyperStreamMatch($anna, $bert);
    $botsOnly->seats()->update(['user_id' => null, 'bot' => true]);
    hyperStreamMatch($anna, $bert)->forceFill(['status' => HyperMatchStatus::Finished, 'ended_at' => now()])->save();

    expect(array_column(app(HyperScene::class)->entries((int) now()->getTimestampMs()), 'id'))->toBe([$live->id, $daily->id]);

    // Switched off while they run: gone from the rotation, and the slide shows no match.
    config(['esports.hyper.enabled' => false]);

    expect(app(HyperScene::class)->entries(0))->toBe([])
        ->and(hyperSlide($live->id)[0]['hyper'])->toBeNull()
        ->and(hyperSlide($live->id)[1])->toContain('No match on right now.')->not->toContain('data-territory');
});

test('a central bank that just fell makes it tense for 90 s; a close duel of the last two sides too', function () {
    HyperOn::play();
    [$anna, $bert] = User::factory()->count(2)->create();
    $match = hyperStreamMatch($anna, $bert, bots: 2);
    $scene = app(HyperScene::class);

    expect($scene->tension($match, 0))->toMatchArray(['tense' => false, 'reason' => null]);

    hyperConquest($match, 0, 'frankfurt', 1);
    expect($scene->tension($match->refresh(), 0))->toMatchArray(['tense' => true, 'reason' => 'bank', 'bank' => ['seat' => 0, 'territory' => 'frankfurt']]);

    $this->travel(91)->seconds();
    expect($scene->tension($match, 0)['tense'])->toBeFalse();

    // The two bots are out: Anna holds 20 territories, Bert 14 (41 %), a close duel.
    $state = $match->state;
    $state['seats'][2]['out'] = true;
    $state['seats'][3]['out'] = true;
    $owners = [...array_fill(0, 20, 0), ...array_fill(0, 14, 1)];
    foreach (HyperMap::IDS as $i => $id) {
        $state['territories'][$id]['owner'] = $owners[$i] ?? null;
    }
    $match->forceFill(['state' => $state])->save();

    expect($scene->tension($match->refresh(), 0))->toMatchArray(['tense' => true, 'reason' => 'duel', 'duel' => [0, 1], 'leader' => 0]);

    // 22 against 12 (35 %) is no close duel.
    $state['territories'][HyperMap::IDS[20]]['owner'] = 0;
    $state['territories'][HyperMap::IDS[21]]['owner'] = 0;
    $state['territories'][HyperMap::IDS[22]]['owner'] = null;
    $state['territories'][HyperMap::IDS[23]]['owner'] = null;
    $match->forceFill(['state' => $state])->save();

    expect($scene->tension($match->refresh(), 0)['tense'])->toBeFalse();
});

/* ---------- What the slide shows ------------------------------------------------------------------------------ */

test('the slide shows the map by owner, the factions, the chronicle and the tension, names as the other slides do', function () {
    HyperOn::play();
    $anna = User::factory()->create(['name' => 'Anna <script>alert(1)</script>', 'lud16' => 'anna@getalby.com']);
    $bert = User::factory()->create(['name' => 'Bert']);
    $match = hyperStreamMatch($anna, $bert, bots: 2);
    hyperConquest($match, 0, 'ny', 1);

    [$data, $svg] = hyperSlide($match->id);
    $hyper = $data['hyper'];

    expect($svg)->toContain('width="1280" height="720"')
        ->and(substr_count($svg, 'data-territory="'))->toBe(HyperMap::COUNT)
        ->and($svg)->toContain('data-territory="ny" data-owner="0"')
        ->and(substr_count($svg, 'data-bank="'))->toBe(count(array_filter(HyperMap::BANK)))
        ->and(array_slice(array_column($hyper['seats'], 'name'), 0, 2))->toBe(['Anna <script>alert(1)</script>', 'Bert'])
        ->and(array_slice(array_column($hyper['seats'], 'name'), 2))->each->toEndWith(' (bot)')
        ->and($svg)->toContain('Anna &lt;script&gt;alert(1)')->not->toContain('<script>')
        // A player's face as on every slide (a Blockpile without a picture), a bot has its faction portrait only.
        ->and($hyper['seats'][0]['avatar'])->toStartWith('data:image/svg+xml;base64,')
        ->and($hyper['seats'][2]['avatar'])->toBeNull()
        // A long name is cut in the row (HyperScene::NAME_CHARS), so the news stays readable.
        ->and($hyper['chronicle'][0]['text'])->toBe('Anna <script>alert(1)… took New York from Bert, toppled the Fed')
        ->and($hyper['tension'])->toMatchArray(['tense' => true, 'reason' => 'bank'])
        ->and($svg)->toContain('TENSION: HIGH')->toContain('LATEST BATTLES')->toContain('watching')
        // Nothing that links the profile: no key, no npub, no address, no hashtag.
        ->and($svg)->not->toContain($anna->pubkey)->not->toContain('npub1')->not->toContain('getalby')->not->toContain('#hyper');
});

test('the slide follows the match while it stands, one new chronicle row every 3 s at most', function () {
    HyperOn::play();
    [$anna, $bert] = User::factory()->count(2)->create();
    $match = hyperStreamMatch($anna, $bert, bots: 2);
    $planner = hyperPlanner();
    $t0 = (int) now()->getTimestampMs();
    $entries = fn (): array => app(HyperScene::class)->entries((int) now()->getTimestampMs());

    $planner->at(0, [], [], BoardScene::OFF, [], 'off', null, $entries());
    $slot = $planner->at(31, [], [], BoardScene::OFF, [], 'off', null, $entries());
    [$first, $firstSvg] = hyperSlide($match->id, $t0);

    expect($slot)->toMatchArray(['kind' => RotationPlanner::HYPER, 'gameId' => $match->id])
        ->and($first['hyper']['chronicle'])->toBe([])
        ->and($first['hyper']['owners']['texas'])->not->toBe(0);

    // A bot chain of three conquests in one second: the map shows all at once, the chronicle one row at a time.
    hyperConquest($match, 0, 'texas', $first['hyper']['owners']['texas']);
    hyperConquest($match, 0, 'mexiko', $first['hyper']['owners']['mexiko']);
    hyperConquest($match, 0, 'kanada', $first['hyper']['owners']['kanada']);

    [$second, $secondSvg] = hyperSlide($match->id, $t0 + 1000);
    expect($second['hyper']['owners'])->toMatchArray(['texas' => 0, 'mexiko' => 0, 'kanada' => 0])
        ->and($secondSvg)->not->toBe($firstSvg)->toContain('data-territory="texas" data-owner="0"')
        // 1 s after the slide came up: too soon for a row.
        ->and($second['hyper']['chronicle'])->toBe([]);

    $rows = fn (int $ms): array => array_column(hyperSlide($match->id, $t0 + $ms)[0]['hyper']['chronicle'], 'text');

    expect($rows(3000))->toHaveCount(1)->and($rows(3000)[0])->toContain('took Texas')
        ->and($rows(5000))->toHaveCount(1)
        ->and($rows(6000))->toHaveCount(2)->and($rows(6000)[0])->toContain('took Mexico')
        ->and($rows(9000))->toHaveCount(3)->and($rows(9000)[0])->toContain('took Canada');

    // The slot still stands (60 s from second 30) while the slide changed under it.
    expect($planner->at(45, [], [], BoardScene::OFF, [], 'off', null, $entries()))->toBe($slot);
});

test('a backlog beyond the rows on show goes at once; the tension caption stands 3 s before it changes', function () {
    HyperOn::play();
    [$anna, $bert] = User::factory()->count(2)->create();
    $match = hyperStreamMatch($anna, $bert, bots: 2);
    $t0 = (int) now()->getTimestampMs();
    $caption = fn (int $ms): string => hyperSlide($match->id, $t0 + $ms)[0]['hyper']['tension']['caption'];

    expect($caption(0))->toStartWith('In the lead: ');

    foreach (['alaska', 'westk', 'kolumbien', 'paraguay', 'island', 'irland'] as $territory) {
        hyperConquest($match, 1, $territory, 0);
    }
    hyperConquest($match, 1, 'london', 0);

    $rows = array_column(hyperSlide($match->id, $t0 + 3000)[0]['hyper']['chronicle'], 'text');

    // Seven new rows: the three oldest beyond the four rows go at once, of the newest four the oldest comes now, the
    // other three one by one after it.
    expect($rows)->toHaveCount(HyperScene::CHRONICLE)
        ->and($rows[0])->toContain('took Paraguay')
        ->and($rows[3])->toContain('took Alaska')
        ->and(array_column(hyperSlide($match->id, $t0 + 6000)[0]['hyper']['chronicle'], 'text')[0])->toContain('took Iceland')
        ->and(array_column(hyperSlide($match->id, $t0 + 9000)[0]['hyper']['chronicle'], 'text')[0])->toContain('took Ireland')
        ->and(array_column(hyperSlide($match->id, $t0 + 12000)[0]['hyper']['chronicle'], 'text')[0])->toContain('took London')
        // The bank of London fell: the caption says so 3 s after the last one came.
        ->and($caption(3000))->toStartWith('Central bank down: ')
        ->and($caption(4000))->toStartWith('Central bank down: ');
});

test('the thinned map covers every territory of the rules core with its English name', function () {
    $map = app(HyperScene::class)->map();

    expect(array_keys($map['territories']))->toBe(HyperMap::IDS)
        ->and($map['territories']['kanada']['name'])->toBe('Canada')
        ->and(array_keys(array_filter(array_column($map['territories'], 'bank', null))))->toHaveCount(count(array_filter(HyperMap::BANK)))
        ->and(collect($map['territories'])->every(fn (array $t): bool => str_starts_with($t['d'], 'M') && $t['cx'] >= 0 && $t['cx'] <= $map['width'] && $t['cy'] >= 0 && $t['cy'] <= $map['height']))->toBeTrue();
});

/* ---------- In the daemon ------------------------------------------------------------------------------------- */

describe('the stream daemon', function () {
    beforeEach(fn () => twentyOneStreamUp($this));

    afterEach(fn () => twentyOneStreamDown($this));

    test('it shows a running match on its slide and renders it', function () {
        HyperOn::play();
        File::put(config('twentyone.stream.prepared'), 'fake');
        fakeEncoder($this->dir);
        fakeRenderer($this->dir);
        shortRotation();
        config(['twentyone.stream.rotation.hyper_min_seconds' => 2, 'twentyone.stream.rotation.hyper_max_seconds' => 3]);
        [$anna, $bert] = User::factory()->count(2)->create();
        $match = hyperStreamMatch($anna, $bert);

        $exitCode = Artisan::call('twentyone:stream', ['--no-publish' => true, '--stop-after' => 4.5]);
        $output = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('rotation: h1 hyper game '.$match->id.', rendered in')
            ->and($output)->not->toContain('not built')->not->toContain('failed');
    });

    test('a failing read drops only its slide, logged once, and is no poll failure', function () {
        HyperOn::play();
        File::put(config('twentyone.stream.prepared'), 'fake');
        fakeEncoder($this->dir);
        fakeRenderer($this->dir);
        shortRotation();
        app()->instance(HyperScene::class, new class(app(StreamImages::class)) extends HyperScene
        {
            public function entries(int $nowMs): array
            {
                throw new PDOException('SQLSTATE[HY000]: General error: 1 no such table: hyper_matches');
            }
        });
        $blitz = ChessGame::factory()->create();

        $exitCode = Artisan::call('twentyone:stream', ['--no-publish' => true, '--stop-after' => 4.5]);
        $output = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('rotation: a1 match game '.$blitz->id.', rendered in')
            ->and(substr_count($output, 'hyper poll failed, showing no Hyperbitcoinization slide: PDOException'))->toBe(1)
            ->and($output)->not->toContain('database poll failed')
            ->and($output)->not->toContain('h1 hyper');
    });
});
