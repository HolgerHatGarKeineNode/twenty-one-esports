<?php

use App\Enums\ChessGameStatus;
use App\Enums\TournamentFormat;
use App\Enums\TournamentStatus;
use App\Games\GameRegistry;
use App\Models\Admin;
use App\Models\ChessGame;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\User;
use App\Support\Tournaments\TournamentControl;
use App\Support\Tournaments\TournamentRunner;
use App\Support\TwentyOne\Stream\RotationKit;
use App\Support\TwentyOne\Stream\RotationPlanner;
use App\Support\TwentyOne\Stream\SceneRenderer;
use App\Support\TwentyOne\Stream\SceneSource;
use App\Support\TwentyOne\Stream\StreamStats;
use App\Support\TwentyOne\Stream\TournamentLiveSlides;
use App\Support\TwentyOne\Stream\TournamentSlides;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TestSigner;

/*
 * The tournaments past sign-up the stream's live tournament slides show
 * (TournamentLiveSlides): each phase with its data, read from the stored
 * bracket as the tournament page reads it; nothing invented.
 */

beforeEach(function () {
    config(['esports.league.nsec' => (new TestSigner)->secret]);
});

/**
 * Play `$rounds` rounds as the director: the better seed wins, except in the
 * matches `$upset` names by round and position (1-based), where the weaker
 * seed wins; `$draws` names matches that end drawn.
 *
 * @param  list<string>  $upset  "round.position"
 * @param  list<string>  $draws  "round.position"
 */
function liveRounds(Tournament $tournament, int $rounds, array $upset = [], array $draws = []): void
{
    $runner = app(TournamentRunner::class);

    for ($i = 1; $i <= $rounds; $i++) {
        $round = TournamentRunner::currentRound($tournament->refresh());

        if ($round === null || $tournament->status !== TournamentStatus::Running) {
            return;
        }

        foreach (TournamentMatch::query()->where('tournament_round_id', $round->id)->where('status', 'ready')->where('bracket', '!=', 'bye')->with('slots.participant')->orderBy('position')->get() as $position => $match) {
            $better = $match->slots[0]->participant->seed < $match->slots[1]->participant->seed ? 0 : 1;
            $winner = in_array($i.'.'.($position + 1), $upset, true) ? 1 - $better : $better;
            $result = in_array($i.'.'.($position + 1), $draws, true) ? '1/2-1/2' : ($winner === 0 ? '1-0' : '0-1');
            $runner->enterResult($match, $tournament->creator, ['result' => $result]);
        }

        $runner->closeRound($round, $tournament->creator);
    }
}

function liveFrame(Tournament $tournament): array
{
    return app(TournamentLiveSlides::class)->data($tournament->refresh(), (int) now()->getTimestampMs());
}

test('a running 64-player double elimination: the bracket cropped to the live block, the same part of the tree after it, the upset, who is still standing', function () {
    $tournament = runningChess(TournamentFormat::DoubleElimination, 64);
    // Round 1, match 3: the weaker seed wins.
    liveRounds($tournament, 1, ['1.3']);
    $frame = liveFrame($tournament);
    $board = $frame['board'];

    expect($frame)->toMatchArray(['phase' => 'running', 'status' => 'Live now', 'now' => 'Round 2, upper bracket', 'progress' => ['played' => 32, 'total' => 127]])
        ->and($board['kind'])->toBe('bracket')
        ->and($board['title'])->toBe('Upper bracket')
        ->and(array_column($board['columns'], 'label'))->toBe(['Round 2', 'Round 3', 'Round 4'])
        ->and(array_map(fn (array $c): int => count($c['matches']), $board['columns']))->toBe([TournamentLiveSlides::WINDOW, 2, 1])
        ->and(array_column($board['columns'], 'total'))->toBe([16, 8, 4])
        ->and($board['columns'][0]['current'])->toBeTrue()
        ->and(array_column($board['columns'][0]['matches'], 'state'))->toBe(['live', 'live', 'live', 'live'])
        // Round 3's first box is fed by the first two shown: the same part of the tree.
        ->and($board['columns'][1]['matches'][0]['from'])->toBe([$board['columns'][0]['matches'][0]['key'], $board['columns'][0]['matches'][1]['key']])
        ->and($frame['standing']['count'])->toBe(64)
        ->and($frame['standing']['of'])->toBe(64)
        ->and(count($frame['standing']['faces']))->toBe(TournamentLiveSlides::STANDING)
        ->and($frame['upset']['winner']['seed'] - $frame['upset']['loser']['seed'])->toBeGreaterThanOrEqual(TournamentLiveSlides::UPSET_GAP)
        ->and($frame['results'][0]['label'])->toBe('1–0')
        // The favourite winning is no upset.
        ->and(array_values(array_unique(array_column(array_filter($frame['results'], fn (array $r): bool => $r['winner']['seed'] < $r['loser']['seed']), 'upset'))))->toBe([false])
        ->and(count($frame['live']))->toBe(2)
        ->and($frame['howItRuns']['steps'][0])->toBe(['title' => 'Upper bracket', 'line' => '64 players. Win and you stay up.'])
        ->and($frame['cover'])->toStartWith('data:image/jpeg;base64,');
});

test('a two stage tournament in its groups, a Swiss table with a draw and its round, a round robin', function () {
    $twoStage = runningChess(TournamentFormat::TwoStage, 8);
    liveRounds($twoStage, 2);
    $swiss = runningChess(TournamentFormat::Swiss, 6);
    liveRounds($swiss, 1, draws: ['1.1']);
    $roundRobin = runningChess(TournamentFormat::RoundRobin, 4);

    $groups = liveFrame($twoStage);
    $table = liveFrame($swiss);
    $all = liveFrame($roundRobin);

    expect($groups['board']['kind'])->toBe('groups')
        ->and(array_column($groups['board']['groups'], 'title'))->toBe(['Group A', 'Group B'])
        ->and(array_column($groups['board']['groups'][0]['rows'], 'through'))->toBe([true, true, false, false])
        ->and($groups['now'])->toBe('Group stage, round 3 of 3')
        ->and($table['board'])->toMatchArray(['kind' => 'table', 'round' => 2, 'of' => 3])
        // A half point as the fonts set it, never "½" (it would read "1/2").
        ->and(array_column($table['board']['rows'], 'points'))->toContain('0.5')
        ->and(implode('', array_column($table['board']['rows'], 'points')))->not->toContain('½')
        ->and(count($table['board']['pairings']))->toBe(3)
        ->and($table['now'])->toBe('Round 2 of 3')
        ->and($all['board']['kind'])->toBe('table')
        ->and($all['now'])->toBe('Round 1 of 3');
});

test('a finished tournament with a bye: its champion, the podium, the path, and it leaves the stream after the window', function () {
    $tournament = runningChess(TournamentFormat::SingleElimination, 6);
    User::query()->whereKey($tournament->participants()->where('seed', 1)->value('user_id'))->update(['name' => "Hal\u{202E} <b>Finney</b>"]);
    liveRounds($tournament, 5);
    $slides = app(TournamentLiveSlides::class);
    $frame = liveFrame($tournament);
    $finishedMs = Carbon::parse((string) TournamentMatch::query()->where('tournament_id', $tournament->id)->max('updated_at'))->getTimestampMs();

    expect($tournament->refresh()->status)->toBe(TournamentStatus::Finished)
        ->and($frame['phase'])->toBe('finished')
        ->and($frame['champion']['name'])->toBe('Hal <b>Finney</b>')
        ->and($frame['champion']['seed'])->toBe(1)
        ->and(array_column($frame['podium'], 'place'))->toBe([1, 2, 3, 3])
        // Seed 1 had a bye in round 1: its path starts in the semifinal.
        ->and(array_column($frame['path'], 'round'))->toBe(['Semifinal', 'Final'])
        ->and($frame['path'][1])->toMatchArray(['won' => true, 'draw' => false, 'label' => '1–0'])
        ->and($frame['board']['columns'][count($frame['board']['columns']) - 1]['label'])->toBe('Final')
        ->and($frame['standing'])->toBeNull()
        ->and($slides->frames($slides->snapshots(), $finishedMs + 47 * 3_600_000))->toHaveCount(1)
        ->and($slides->frames($slides->snapshots(), $finishedMs + 121 * 3_600_000))->toBe([])
        ->and($slides->frames($slides->snapshots(), $finishedMs + 119 * 3_600_000))->not->toBe([]);
});

test('a drawing tournament shows its field, the block and the time to its start; called off, draft and switched-off games never', function () {
    $drawing = openTournament(['name' => 'Blocktime Cup', 'format' => TournamentFormat::SingleElimination, 'capacity' => 8]);
    foreach (['Adam', 'Hal'] as $name) {
        [$user, $signer] = keyedPlayer();
        $user->forceFill(['name' => $name])->save();
        soloSignup($drawing, $user, $signer);
    }
    $drawing->forceFill(['status' => TournamentStatus::Drawing, 'draw_height' => 869120, 'signup_closes_at' => now()->subMinute(), 'starts_at' => now()->addMinutes(40)])->save();
    $running = runningChess(TournamentFormat::SingleElimination, 4);
    runningChess(TournamentFormat::SingleElimination, 4)->forceFill(['status' => TournamentStatus::Cancelled])->save();
    openTournament(['status' => TournamentStatus::Draft]);
    $board = runningChess(TournamentFormat::SingleElimination, 4);
    $board->forceFill(['game' => 'checkers', 'mode' => 'blitz'])->save();
    config(['esports.board_games.games.checkers.enabled' => false]);
    app()->forgetInstance(GameRegistry::class);
    app()->forgetInstance(TournamentLiveSlides::class);

    $frames = app(TournamentLiveSlides::class)->all((int) $drawing->refresh()->starts_at->getTimestampMs() - 40 * 60_000);
    // Open for sign-up, but its game is off: no hero slide either.
    $boardSignup = openTournament(['game' => 'checkers', 'mode' => 'blitz']);
    app()->forgetInstance(TournamentSlides::class);

    expect(array_column($frames, 'id'))->toBe([$running->id, $drawing->id])
        ->and(app(TournamentSlides::class)->upcoming()->pluck('id')->all())->not->toContain($boardSignup->id)
        ->and($frames[1])->toMatchArray(['phase' => 'drawing', 'status' => 'Draw pending', 'drawBlock' => 869120, 'board' => null, 'countdown' => '00:40:00'])
        ->and(array_column($frames[1]['field'], 'name'))->toEqualCanonicalizing(['Adam', 'Hal']);
});

test('a running casual cup is a tournament on the stream too: its region, and the round window instead of a first-move clock', function () {
    $cup = runningCup(4);
    $frame = liveFrame($cup);

    expect($frame)->toMatchArray(['phase' => 'running', 'cup' => true, 'region' => 'EU'])
        ->and($frame['howItRuns']['showUp'])->toBe(config('esports.casual_cups.window_hours', 48).' hours to play each round')
        ->and($frame['board']['kind'])->toBe('bracket');
});

test('the next tournament to sign up for: the same game first, else the soonest to close; a full one never', function () {
    $frame = ['id' => 1, 'game' => 'Chess'];
    $upcoming = [
        ['id' => 2, 'game' => 'EA Sports FC 26', 'spotsLeft' => 3],
        ['id' => 3, 'game' => 'Chess', 'spotsLeft' => 0],
        ['id' => 4, 'game' => 'Chess', 'spotsLeft' => 5],
    ];

    expect(TournamentLiveSlides::next($frame, $upcoming)['id'])->toBe(4)
        ->and(TournamentLiveSlides::next(['id' => 1, 'game' => 'Rocket League'], $upcoming)['id'])->toBe(2)
        ->and(TournamentLiveSlides::next($frame, [$upcoming[1]]))->toBeNull()
        ->and(TournamentLiveSlides::entries([['id' => 1, 'phase' => 'running', 'game' => 'Chess']], $upcoming))->toBe([['id' => 1, 'phase' => 'running', 'fomo' => true, 'takeover' => true, 'moment' => false, 'parts' => [1, 3]]])
        ->and(TournamentLiveSlides::entries([['id' => 1, 'phase' => 'running', 'game' => 'Chess']], []))->toBe([['id' => 1, 'phase' => 'running', 'fomo' => false, 'takeover' => true, 'moment' => false, 'parts' => [1, 3]]]);
});

test('every live tournament slide renders a running and a finished tournament with escaped names, and points to the next one', function () {
    $running = runningChess(TournamentFormat::DoubleElimination, 8);
    $running->forceFill(['name' => 'Cup <script>alert(1)</script>'])->save();
    $finished = runningChess(TournamentFormat::SingleElimination, 4);
    // Fixed names: a factory name may hold the words checked below ("isabel.feeney" once failed the fee check, and
    // a faker city such as "Feestview" in the tournament name would too).
    $finished->forceFill(['name' => 'Blitz Night Finished'])->save();
    foreach (User::query()->orderBy('id')->pluck('id') as $i => $id) {
        User::query()->whereKey($id)->update(['name' => 'Player '.($i + 1)]);
    }
    User::query()->whereKey($finished->participants()->where('seed', 1)->value('user_id'))->update(['name' => 'Seed <b>one</b>']);
    liveRounds($running, 1);
    liveRounds($finished, 2);
    $next = openTournament(['name' => 'Next <i>Open</i>']);
    $now = (int) now()->getTimestampMs();
    $upcoming = app(TournamentSlides::class)->all($now);
    $live = app(TournamentLiveSlides::class);
    $source = app(SceneSource::class);
    $stats = app(StreamStats::class)->all();
    $renderer = SceneRenderer::fromConfig();

    $svgs = [];
    foreach (['running' => $running, 'finished' => $finished] as $key => $tournament) {
        $frame = $live->data($tournament->refresh(), $now);
        foreach (RotationPlanner::LIVE_TOURNAMENT_SCENES as $scene) {
            $svgs[$key.'-'.$scene] = $renderer->svg([...$source->rotation($scene, null, [], 0, $now, $stats, $frame, $upcoming), 'viewers' => 1234], RotationPlanner::VIEWS[$scene]);
        }
    }
    $all = implode('', $svgs);
    $upcomingHow = '';
    foreach (['ta3', 'tb3', 'tc3'] as $scene) {
        $upcomingHow .= $renderer->svg([...$source->rotation($scene, null, [], 0, $now, $stats, $upcoming[0], $upcoming), 'viewers' => null], RotationPlanner::VIEWS[$scene]);
    }

    expect($svgs)->toHaveCount(2 * count(RotationPlanner::LIVE_TOURNAMENT_SCENES))
        ->and(array_filter($svgs, fn (string $svg): bool => ! str_contains($svg, 'width="1280" height="720"')))->toBe([])
        ->and(array_keys(array_filter($svgs, fn (string $svg): bool => ! str_contains($svg, '1,234') || ! str_contains($svg, 'watching'))))->toBe([])
        ->and($all)->not->toContain('<script>')->not->toContain('<b>one')->not->toContain('<i>Open')
        // The running tournament's name on its slides (the next-one slides name it in their context line).
        ->and(array_keys(array_filter($svgs, fn (string $svg, string $key): bool => str_starts_with($key, 'running') && ! str_contains($svg, '&lt;script&gt;alert'), ARRAY_FILTER_USE_BOTH)))->toBe([])
        ->and($svgs['finished-ta6'].$svgs['finished-tb6'].$svgs['finished-tc6'])->toContain('Seed &lt;b&gt;one')
        ->and($svgs['running-ta7'].$svgs['running-tb7'].$svgs['running-tc7'])->toContain('Next &lt;i&gt;Open', "Don't watch", 'Play it.', 'esports.einundzwanzig.space/tournaments/'.$next->id)
        ->and($svgs['running-ta3'])->toContain('How it runs', 'Upper bracket')
        ->and($svgs['running-ta4'])->toContain('Still standing')
        // How it runs in a sign-up round: where to sign up and the time to the close, never a round in play.
        ->and($upcomingHow)->toContain('How it runs', 'Sign up', 'esports.einundzwanzig.space/tournaments/'.$next->id, $upcoming[0]['countdown'])->not->toContain('Now:')
        // Never a fee, a hashtag or a Lightning address.
        ->and(mb_strtolower($all))->not->toContain('fee')->not->toContain('lnurl')->not->toContain('#bitcoin');
});

/** An admin who may disqualify, pause and link accounts. */
function liveAdmin(): User
{
    $admin = User::factory()->create(['name' => 'Referee']);
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    return $admin;
}

/** The text of every data-unit of a live slide. */
function liveUnits(array $frame, string $scene, ?int $viewers = null): array
{
    $svg = SceneRenderer::fromConfig()->svg([...app(SceneSource::class)->rotation($scene, null, [], 0, (int) now()->getTimestampMs(), [], $frame, []), 'viewers' => $viewers], RotationPlanner::VIEWS[$scene]);
    preg_match_all('/data-unit="([^"]+)"[^>]*>([^<]+)</', $svg, $m);

    return array_combine($m[1], array_map('html_entity_decode', $m[2]));
}

test('a result decided without a game is told as that, never as a played win, an upset or a step of the path; a double no-show never as a draw', function () {
    Queue::fake();
    // A disqualification: seed 1 is out, seed 8 advances without a game.
    $forfeit = runningChess(TournamentFormat::SingleElimination, 8);
    app(TournamentControl::class)->disqualify($forfeit, liveAdmin(), $forfeit->participants()->where('seed', 1)->value('id'), 'Borrowed account');
    // A director's no-show.
    $noShow = runningChess(TournamentFormat::SingleElimination, 8);
    $match = TournamentMatch::query()->where('tournament_round_id', TournamentRunner::currentRound($noShow)->id)->where('status', 'ready')->where('bracket', '!=', 'bye')->orderBy('position')->first();
    app(TournamentRunner::class)->enterResult($match, $noShow->creator, ['result' => 'noshow-1']);
    // A double no-show, as TournamentRunner::doubleNoShow() stores it in a Swiss round.
    $double = runningChess(TournamentFormat::Swiss, 4);
    TournamentMatch::query()->where('tournament_round_id', TournamentRunner::currentRound($double)->id)->where('bracket', '!=', 'bye')->orderBy('position')->first()
        ->forceFill(['status' => 'done', 'result' => ['winner' => null, 'double_loss' => true, 'games_won' => [0.0, 0.0], 'points' => [], 'forfeit' => true, 'decided' => 'noshow', 'label' => 'double no-show', 'by' => 'league']])->save();

    $f = liveFrame($forfeit);
    $n = liveFrame($noShow);
    $d = liveFrame($double);
    $text = implode(' | ', liveUnits($f, 'ta5')).' | '.implode(' | ', liveUnits($n, 'tb5')).' | '.implode(' | ', liveUnits($d, 'tc5'));

    expect($f['upset'])->toBeNull()
        ->and($f['results'][0])->toMatchArray(['how' => ' by forfeit', 'label' => null, 'upset' => false])
        ->and($n['results'][0])->toMatchArray(['how' => ', no-show', 'label' => null])
        ->and($d['results'])->toBe([])
        ->and($text)->toContain(' advances by forfeit', ' advances, no-show')->not->toContain(' drew ')->not->toMatch('/ beat [^|]* 1-0/')
        // In the bracket the winner advances without a played score.
        ->and(collect($f['board']['columns'][0]['matches'])->flatMap(fn (array $m): array => $m['sides'])->whereNotNull('score')->all())->toBe([]);
});

test('a voided match keeps its place but feeds no result, upset or path; the voided final stays out of the champion\'s path', function () {
    Queue::fake();
    $t = runningChess(TournamentFormat::SingleElimination, 4);
    // Round 1: the weaker seed wins the first match (an upset), then the final; both are then voided as linked accounts.
    liveRounds($t, 2, ['1.1']);
    TournamentMatch::query()->where('tournament_id', $t->id)->where('status', 'done')->get()
        ->each(fn (TournamentMatch $m) => $m->forceFill(['result' => ['void' => 'linked_accounts', 'unrated' => true] + $m->result])->save());
    $frame = liveFrame($t);

    expect($frame['phase'])->toBe('finished')
        ->and($frame['results'])->toBe([])
        ->and($frame['upset'])->toBeNull()
        ->and($frame['path'])->toBe([])
        ->and(array_values(array_unique(array_column(array_merge(...array_column($frame['board']['columns'], 'matches')), 'state'))))->toBe(['void'])
        ->and(collect($frame['board']['columns'])->flatMap(fn (array $c): array => $c['matches'])->flatMap(fn (array $m): array => $m['sides'])->where('won', true)->all())->toBe([])
        // Drawn as voided (dimmed, marked), never as a match still to play; the count says decided, not played.
        ->and(collect(RotationKit::bracketLayout($frame['board'], 40, 180, 872, 470)['columns'])->flatMap(fn (array $c): array => $c['boxes'])->pluck('state')->unique()->values()->all())->toBe(['void'])
        ->and(array_values(array_filter(liveUnits($frame, 'tc4'), fn (string $key): bool => str_ends_with($key, '-void'), ARRAY_FILTER_USE_KEY)))->toBe(['voided', 'voided', 'voided'])
        ->and(liveUnits($frame, 'ta4')['played-label'])->toBe('Matches decided');
});

test('a paused tournament says so and lists no match as on now; a double elimination names its finals', function () {
    Queue::fake();
    $paused = runningChess(TournamentFormat::SingleElimination, 8);
    app(TournamentControl::class)->pause($paused, liveAdmin(), 'Server trouble');
    $de = runningChess(TournamentFormat::DoubleElimination, 4);
    liveRounds($de, 3);

    $p = liveFrame($paused);
    $d = liveFrame($de);
    $units = liveUnits($p, 'ta4');

    expect($p['status'])->toBe('Paused')
        ->and($p['live'])->toBe([])
        ->and(collect($p['board']['columns'][0]['matches'])->pluck('state')->unique()->all())->not->toContain('live')
        ->and($units['status'])->toBe('Paused')
        ->and($units)->not->toHaveKey('live-label')
        ->and(array_column($d['board']['columns'], 'label'))->toBe(['Finals', 'Grand final', 'Grand final reset'])
        ->and($d['now'])->toBe('Grand final');
});

test('the next-one slide on the desk fits its sentence once, next to the viewer badge: only the name is shortened', function () {
    $t = runningChess(TournamentFormat::SingleElimination, 4);
    $t->forceFill(['name' => 'The Very Long Winter Blitz Championship of the Whole League 2026'])->save();
    $units = liveUnits(liveFrame($t), 'tb7', 12345);

    expect($units['bug-note'])->toEndWith('… is live.');
});

test('a casual cup over days takes the whole stream only while one of its matches is played; a special tournament always', function () {
    $cup = runningCup(4);
    $special = runningChess(TournamentFormat::SingleElimination, 4);

    expect(liveFrame($cup)['takeover'])->toBeFalse()
        ->and(liveFrame($special)['takeover'])->toBeTrue();

    $match = TournamentMatch::query()->where('tournament_id', $cup->id)->where('status', 'ready')->firstOrFail();
    ChessGame::factory()->create(['tournament_match_id' => $match->id, 'status' => ChessGameStatus::Active]);
    Cache::flush();

    expect(liveFrame($cup->fresh())['takeover'])->toBeTrue();
});

test('a running tournament holds the stream in four slides drawn like its tournament TV: bracket, up now, standings, pot', function () {
    $t = runningChess(TournamentFormat::TwoStage, 6, options: ['groupSize' => 3, 'advance' => 2]);
    $t->forceFill(['name' => 'Friday <b>Cup</b>', 'pot_source' => Tournament::POT_LEAGUE, 'prize_target_sats' => 21000, 'pool_opened_at' => now()])->save();
    liveRounds($t, 1);
    $frame = liveFrame($t);
    $units = fn (string $scene): string => implode(' | ', liveUnits($frame, $scene, 1234));

    expect(TournamentLiveSlides::entries([$frame], []))->toBe([['id' => $t->id, 'phase' => 'running', 'fomo' => false, 'takeover' => true, 'moment' => false, 'parts' => [1, 2, 3, 4]]])
        ->and(array_map(fn (int $part): string => RotationPlanner::VIEWS['t'.RotationPlanner::RUNNING_LOOK.$part], RotationPlanner::RUNNING_PARTS))->toBe(['stream.rotation.tv1-bracket', 'stream.rotation.tv2-up-now', 'stream.rotation.tv3-standings', 'stream.rotation.tv4-pot'])
        // The TV's header and footer on every slide: the name, the address, the latest result, the tabs.
        ->and($units('tv1'))->toContain('Friday <b>Cup</b>', 'Follow on your phone', 'tournaments/'.$t->id, 'Latest', 'Bracket', 'Up now', 'Standings', 'Prize pool', 'Group A', 'Round 2')
        ->and($units('tv2'))->toContain('vs', 'Seed ')
        ->and($units('tv3'))->toContain('Group A', 'Group B', 'Through', 'Wins, draws, losses and points')
        ->and($units('tv4'))->toContain('In the pot', '21,000', '1st place', 'can still win it')
        ->and(mb_strtolower($units('tv1').$units('tv2').$units('tv3').$units('tv4')))->not->toContain('face')->not->toContain('#');

    // Without a pot the pot slide is left out, as the TV leaves its pot scene out.
    $t->forceFill(['pot_source' => null])->save();
    expect(TournamentLiveSlides::runningParts(liveFrame($t)))->toBe([1, 2, 3]);
});
