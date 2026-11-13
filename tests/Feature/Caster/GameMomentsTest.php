<?php

use App\Models\ChessGame;
use App\Models\ChessMove;
use App\Models\HyperAction;
use App\Models\HyperMatch;
use App\Models\User;
use App\Support\Board\BoardGameService;
use App\Support\Board\GoRules;
use App\Support\Board\NineMensMorrisRules;
use App\Support\Caster\CasterVoice;
use App\Support\Caster\GameMoments\BoardMoments;
use App\Support\Caster\GameMoments\ChessMoments;
use App\Support\Caster\GameMoments\GameMoments;
use App\Support\Caster\GameMoments\HyperMatchMoments;
use App\Support\Caster\GameMoments\Mood;
use App\Support\Caster\GameMoments\PongMoments;
use App\Support\Caster\GameMoments\ScoreMoments;
use App\Support\Caster\GameMoments\SeriesMoments;
use App\Support\Caster\GameMoments\TournamentMoments;
use App\Support\Caster\GameMoments\Words;
use App\Support\Caster\PatternScripts;
use App\Support\Caster\SceneFacts;
use App\Support\Caster\SceneTopic;
use App\Support\Caster\ScriptGuard;
use App\Support\Chess\ChessRules;
use App\Support\Hyper\HyperMap;
use PChess\Chess\Chess;
use Tests\Support\GoOn;

/*
|--------------------------------------------------------------------------
| TWENTY ONE TV: game moments, so the caster can call the game
|--------------------------------------------------------------------------
|
| User 2026-10-11 (his own voice on the stream): "Er kann den Spielverlauf
| noch gar nicht kommentieren, sondern sagt immer nur plump welcher Zug
| gerade ist, aber nichts Emotionales, wer gerade am Gewinnen ist, ob
| jemand schon viele Figuren verloren hat usw." Each moment is derived from
| the game's own state by a fixed rule; these tests pin the rules.
|
*/

/**
 * A running chess game with `$ucis` played from the start, each move stored with its FEN and SAN.
 *
 * @param  list<string>  $ucis
 * @param  array<string, mixed>  $attributes
 */
function momentsChessGame(array $ucis, array $attributes = []): ChessGame
{
    $chess = new Chess;
    $rows = [];

    foreach ($ucis as $index => $uci) {
        $move = ChessRules::play($chess, $uci);
        $rows[] = ['ply' => $index + 1, 'uci' => $uci, 'san' => $move->san, 'fen' => $chess->fen()];
    }

    $game = ChessGame::factory()->create(['fen' => $chess->fen(), 'ply' => count($ucis), ...$attributes]);

    foreach ($rows as $row) {
        ChessMove::query()->create(['chess_game_id' => $game->id, ...$row, 'spent_ms' => 1000, 'clock_ms' => 300_000]);
    }

    return $game;
}

/**
 * The moments of a kind.
 *
 * @param  list<array<string, string|int>>  $moments
 * @return list<array<string, string|int>>
 */
function momentsOfKind(array $moments, string $kind): array
{
    return array_values(array_filter($moments, fn (array $moment): bool => $moment['kind'] === $kind));
}

/* ---------- Chess --------------------------------------------------------------------------------- */

it('counts chess material from the FEN and says the edge as the pieces when one kind differs', function () {
    $start = ChessMoments::pieces(ChessGame::START_FEN);

    expect(ChessMoments::material($start['w']))->toBe(39)
        ->and(ChessMoments::material($start['b']))->toBe(39);

    $edge = fn (string $fen): string => ChessMoments::edge(ChessMoments::pieces($fen)['w'], ChessMoments::pieces($fen)['b']);

    // White a rook, Black a knight in their place: the exchange.
    expect($edge('1nbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/R1BQKBNR w - - 0 1'))->toBe('eine Qualität')
        ->and($edge('rnbqkbnr/pppppp2/8/8/8/8/PPPPPPPP/RNBQKBNR w - - 0 1'))->toBe('zwei Bauern')
        ->and($edge('rnbqkbnr/ppppppp1/8/8/8/8/PPPPPPPP/RNBQKBNR w - - 0 1'))->toBe('einen Bauern')
        ->and($edge('rnb1kbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w - - 0 1'))->toBe('die Dame')
        ->and($edge('rnbqkb1r/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w - - 0 1'))->toBe('eine Figur')
        ->and($edge('1nbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w - - 0 1'))->toBe('einen Turm')
        // A knight and a pawn more: no single kind, so the points.
        ->and($edge('rnbqkb1r/ppppppp1/8/8/8/8/PPPPPPPP/RNBQKBNR w - - 0 1'))->toBe('vier Punkte Material');
});

it('reads the chess phase by the material on the board', function () {
    expect(ChessMoments::phase(ChessMoments::pieces(ChessGame::START_FEN), 0))->toBe('Eröffnung')
        ->and(ChessMoments::phase(ChessMoments::pieces(ChessGame::START_FEN), 30))->toBe('Mittelspiel')
        // Rooks and a bishop each: 2 x 13 = 26 non-pawn points, an endgame.
        ->and(ChessMoments::phase(ChessMoments::pieces('2r1r1k1/b4ppp/8/8/8/8/5PPP/2R1RBK1 w - - 0 30'), 60))->toBe('Endspiel')
        ->and(ChessMoments::phase(ChessMoments::pieces('2r1rbk1/b4ppp/8/8/8/8/5PPP/2R1RBK1 w - - 0 30'), 60))->toBe('Mittelspiel');
});

it('counts a deficit for a comeback only when it lasted beyond a capture and its recapture', function () {
    // A trade: -3 for one ply, then level again.
    expect(ChessMoments::sustainedDeficit([0, -3, 0, 0, -3, 0]))->toBe(0)
        // Three points down for four plies in a row.
        ->and(ChessMoments::sustainedDeficit([0, -3, -3, -3, -3, 0]))->toBe(3)
        ->and(ChessMoments::sustainedDeficit([0, -9, -5, -5, -5, -5, 1]))->toBe(5);
});

it('calls the check, the trade and what each side lost in a running chess game', function () {
    // 1. e4 d5 2. exd5 Qxd5 3. Nc3 Qe5+
    $game = momentsChessGame(['e2e4', 'd7d5', 'e4d5', 'd8d5', 'b1c3', 'd5e5']);
    $moments = app(ChessMoments::class)->of($game, 'Ada', 'Bob', (int) now()->getTimestampMs());

    expect(momentsOfKind($moments, 'check'))->toHaveCount(1)
        ->and(momentsOfKind($moments, 'check')[0])->toMatchArray(['check_by' => 'Bob', 'check_to' => 'Ada', 'mood' => Mood::TENSION, 'lead' => 'Bob'])
        ->and(momentsOfKind($moments, 'level')[0])->toMatchArray(['level_off' => 'zwei Steine', 'mood' => Mood::CLOSE])
        ->and(momentsOfKind($moments, 'phase')[0])->toMatchArray(['phase' => 'Eröffnung', 'phase_in' => 'in der Eröffnung', 'move' => 4])
        ->and(momentsOfKind($moments, 'losses')[0])->toMatchArray(['white_lost' => 'einen Bauern', 'black_lost' => 'einen Bauern'])
        ->and(momentsOfKind($moments, 'material'))->toBe([])
        ->and($moments[0])->toMatchArray(['game' => 'Schach', 'white' => 'Ada', 'black' => 'Bob']);
});

it('calls captures in a row an exchange and a single capture with the piece taken', function () {
    // 1. e4 d5 2. exd5 Qxd5: two captures in a row.
    $exchange = app(ChessMoments::class)->of(momentsChessGame(['e2e4', 'd7d5', 'e4d5', 'd8d5']), 'Ada', 'Bob', (int) now()->getTimestampMs());
    // 1. e4 d5 2. exd5: one capture, White a pawn up.
    $capture = app(ChessMoments::class)->of(momentsChessGame(['e2e4', 'd7d5', 'e4d5']), 'Ada', 'Bob', (int) now()->getTimestampMs());

    expect(momentsOfKind($exchange, 'exchange')[0])->toMatchArray(['exchange' => 'zwei Schlagzüge', 'exchange_captures' => 2])
        ->and(momentsOfKind($exchange, 'capture'))->toBe([])
        ->and(momentsOfKind($capture, 'capture')[0])->toMatchArray(['capture_by' => 'Ada', 'captured' => 'einen Bauern'])
        ->and(momentsOfKind($capture, 'material')[0])->toMatchArray(['ahead' => 'Ada', 'ahead_color' => 'Weiß', 'behind' => 'Bob', 'edge' => 'einen Bauern', 'edge_points' => 1, 'mood' => Mood::CLOSE]);
});

it('gives the material edge its mood by size', function () {
    $mood = fn (string $fen): string|int => momentsOfKind(app(ChessMoments::class)->of(ChessGame::factory()->create(['fen' => $fen, 'ply' => 40]), 'Ada', 'Bob', (int) now()->getTimestampMs()), 'material')[0]['mood'];

    expect($mood('rnb1kbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w - - 0 21'))->toBe(Mood::DOMINANCE)
        ->and($mood('rnbqkb1r/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w - - 0 21'))->toBe(Mood::TENSION)
        ->and($mood('rnbqkbnr/ppppppp1/8/8/8/8/PPPPPPPP/RNBQKBNR w - - 0 21'))->toBe(Mood::CLOSE);
});

it('says who is low on time: a live clock under thirty seconds, a correspondence day under two hours', function () {
    $now = (int) now()->getTimestampMs();
    // White to move with 20 seconds left.
    $live = ChessGame::factory()->create(['fen' => 'rnbqkbnr/pppp1ppp/8/4p3/4P3/8/PPPP1PPP/RNBQKBNR w KQkq e6 0 2', 'ply' => 2, 'white_ms' => 20_000, 'black_ms' => 200_000, 'turn_started_ms' => $now]);
    $relaxed = ChessGame::factory()->create(['fen' => 'rnbqkbnr/pppp1ppp/8/4p3/4P3/8/PPPP1PPP/RNBQKBNR w KQkq e6 0 2', 'ply' => 2, 'white_ms' => 200_000, 'black_ms' => 200_000, 'turn_started_ms' => $now]);
    $daily = ChessGame::factory()->daily()->create(['fen' => 'rnbqkbnr/pppp1ppp/8/4p3/4P3/8/PPPP1PPP/RNBQKBNR w KQkq e6 0 2', 'ply' => 2, 'deadline_ms' => $now + 3_600_000]);

    expect(momentsOfKind(app(ChessMoments::class)->of($live, 'Ada', 'Bob', $now), 'clock')[0])->toMatchArray(['clock_low' => 'Ada', 'mood' => Mood::TENSION])
        ->and(momentsOfKind(app(ChessMoments::class)->of($relaxed, 'Ada', 'Bob', $now), 'clock'))->toBe([])
        ->and(momentsOfKind(app(ChessMoments::class)->of($daily, 'Ada', 'Bob', $now), 'clock')[0])->toMatchArray(['clock_low' => 'Ada']);
});

/* ---------- Board games --------------------------------------------------------------------------- */

it('reads the Blockli race: leader, steps, blocks and the mood by margin', function () {
    $names = ['w' => 'Ada', 'b' => 'Bob'];
    $base = ['game' => 'Blockli', 'a' => 'Ada', 'b' => 'Bob'];
    $close = BoardMoments::race(['w' => ['steps' => 5, 'blocks' => 7, 'score' => -5.5], 'b' => ['steps' => 6, 'blocks' => 7, 'score' => -4.5], 'margin' => 1.0, 'lead' => 'w', 'rate' => 1.5], $names, $base);
    $far = BoardMoments::race(['w' => ['steps' => 8, 'blocks' => 10, 'score' => -7.0], 'b' => ['steps' => 1, 'blocks' => 4, 'score' => -5.0], 'margin' => -2.0, 'lead' => 'w', 'rate' => 1.5], $names, $base);
    $level = BoardMoments::race(['w' => ['steps' => 6, 'blocks' => 9, 'score' => -7.5], 'b' => ['steps' => 9, 'blocks' => 11, 'score' => -7.5], 'margin' => 0.0, 'lead' => null, 'rate' => 1.5], $names, $base);

    expect(momentsOfKind($close, 'race')[0])->toMatchArray(['race_leader' => 'Ada', 'race_trailer' => 'Bob', 'leader_steps' => 'fünf Schritte', 'trailer_steps' => 'sechs Schritte',
        'leader_blocks_left' => 'sieben Blöcke', 'leader_blocks_used' => 'drei Blöcke', 'mood' => Mood::CLOSE])
        // The leader by score may be the one further from the goal: blocks count too. Bob is one step away.
        ->and(momentsOfKind($far, 'race')[0])->not->toHaveKey('leader_blocks_used')
        ->and(momentsOfKind($far, 'race')[0])->not->toHaveKey('mood')
        ->and(momentsOfKind($far, 'race_goal')[0])->toMatchArray(['one_step' => 'Bob', 'mood' => Mood::TENSION])
        ->and(momentsOfKind($level, 'race_level')[0])->toMatchArray(['level_a_steps' => 'sechs Schritte', 'level_b_steps' => 'neun Schritte', 'mood' => Mood::CLOSE]);

    $dominant = BoardMoments::race(['w' => ['steps' => 2, 'blocks' => 6, 'score' => -7.0], 'b' => ['steps' => 8, 'blocks' => 5, 'score' => 0.5], 'margin' => 7.5, 'lead' => 'w', 'rate' => 1.5], $names, $base);
    expect(momentsOfKind($dominant, 'race')[0]['mood'])->toBe(Mood::DOMINANCE);
});

it('counts men left and mills in nine men\'s morris and pieces and kings in checkers', function () {
    $names = ['w' => 'Ada', 'b' => 'Bob'];
    $board = array_fill_keys(NineMensMorrisRules::POINTS, null);
    // White: a mill on the top row and two more men; Black: three men, none in hand: it flies.
    foreach (['a7', 'd7', 'g7', 'a1', 'd1'] as $point) {
        $board[$point] = 'w';
    }
    foreach (['b2', 'f2', 'c3'] as $point) {
        $board[$point] = 'b';
    }
    $morris = BoardMoments::morris(['board' => $board, 'turn' => 'b', 'hand' => ['w' => 0, 'b' => 0], 'quiet' => 0], $names, ['game' => 'Mühle', 'a' => 'Ada', 'b' => 'Bob']);

    expect(momentsOfKind($morris, 'morris')[0])->toMatchArray(['men_leader' => 'Ada', 'leader_men' => 'fünf Steine', 'men_trailer' => 'Bob', 'trailer_men' => 'drei Steine', 'trailer_men_lost' => 'sechs Steine'])
        ->and(momentsOfKind($morris, 'morris')[0])->not->toHaveKey('mood')
        ->and(momentsOfKind($morris, 'mills'))->toHaveCount(1)
        ->and(momentsOfKind($morris, 'mills')[0])->toMatchArray(['mill_owner' => 'Ada', 'mills' => 'eine Mühle'])
        ->and(momentsOfKind($morris, 'flying')[0])->toMatchArray(['flying' => 'Bob', 'mood' => Mood::TENSION]);

    $squares = ['a1' => 'w', 'c1' => 'w', 'e1' => 'W', 'g1' => 'w', 'b8' => 'b'];
    $checkers = BoardMoments::checkers(['board' => $squares, 'turn' => 'w', 'quiet' => 0], $names, ['game' => 'Dame', 'a' => 'Ada', 'b' => 'Bob']);

    expect(momentsOfKind($checkers, 'checkers')[0])->toMatchArray(['pieces_leader' => 'Ada', 'leader_pieces' => 'vier Steine', 'trailer_pieces' => 'einen Stein', 'mood' => Mood::DOMINANCE])
        ->and(momentsOfKind($checkers, 'kings')[0])->toMatchArray(['king_owner' => 'Ada', 'kings' => 'eine Dame']);
});

/* ---------- Pong ---------------------------------------------------------------------------------- */

it('reads a Pong match from its log: comeback, match points saved, lead changes, the longest rally', function () {
    // A goal entry names the side that missed. Bob (right) leads 0:5, Ada (left) wins 11:10; to 11, two clear: at 9:10
    // Bob's next point would have won (a match point), Ada scores.
    $log = [];
    $rally = 0;
    $goal = function (int $scorer) use (&$log, &$rally): void {
        $rally++;
        $log[] = ['serve', $rally, 0, null];
        $log[] = ['goal', $rally, 10, 1 - $scorer, 0, null, 'miss'];
    };
    foreach ([1, 1, 1, 1, 1] as $s) {
        $goal($s);
    }
    foreach ([0, 0, 0, 0, 0, 0, 0, 0, 0] as $s) {
        $goal($s);
    }
    foreach ([1, 1, 1, 1, 1] as $s) {
        $goal($s);
    }
    $goal(0);
    $goal(0);
    // A rally of twelve hits.
    $log = [...array_map(fn (int $i): array => ['hit', 3, $i, $i % 2, 0, 0, 0], range(1, 12)), ...$log];

    $moments = PongMoments::fromLog([11, 10], $log, 0, ['Ada', 'Bob'], 11, 2);

    expect(momentsOfKind($moments, 'pong_score')[0])->toMatchArray(['pong_winner' => 'Ada', 'pong_loser' => 'Bob', 'pong_score' => '11 zu 10', 'mood' => Mood::CLOSE])
        ->and(momentsOfKind($moments, 'pong_comeback')[0])->toMatchArray(['comeback_by' => 'Ada', 'deficit' => 'fünf Punkte', 'mood' => Mood::COMEBACK])
        ->and(momentsOfKind($moments, 'pong_match_points')[0])->toMatchArray(['saver' => 'Ada', 'match_points_saved' => 'einen Matchball'])
        // Ada ahead at 6:5, Bob at 9:10, Ada at 11:10 (the tie at 10:10 changes nothing).
        ->and(momentsOfKind($moments, 'pong_lead_changes')[0])->toMatchArray(['lead_changes' => 'drei Führungswechsel', 'lead_change_count' => 3])
        ->and(momentsOfKind($moments, 'pong_rally')[0])->toMatchArray(['rally_hits' => 'zwölf Schläge']);
});

it('says only the Pong score when the log does not add up to it', function () {
    $log = [['goal', 1, 10, 1, 0, null, 'miss']];

    expect(PongMoments::fromLog([21, 3], $log, 0, ['Ada', 'Bob'], 21, 2))->toHaveCount(1)
        ->and(PongMoments::fromLog([21, 3], $log, 0, ['Ada', 'Bob'], 21, 2)[0])->toMatchArray(['kind' => 'pong_score', 'mood' => Mood::DOMINANCE]);
});

/* ---------- Hyperbitcoinization ------------------------------------------------------------------- */

it('names the Hyperbitcoinization leader with territories and banks, a run of conquests and a toppled bank', function () {
    $match = HyperMatch::factory()->create();
    $state = $match->state;
    $ids = HyperMap::IDS;

    foreach ($ids as $index => $id) {
        $state['territories'][$id]['owner'] = $index < 30 ? 0 : 1;
    }
    $match->update(['state' => $state]);
    HyperAction::query()->create(['hyper_match_id' => $match->id, 'ply' => 1, 'seat' => 0, 'source' => HyperAction::PLAYER, 'action' => [], 'events' => [
        ['type' => 'territory_conquered', 'seat' => 0, 'territory' => $ids[0], 'previous_owner' => 1],
        ['type' => 'territory_conquered', 'seat' => 0, 'territory' => $ids[1], 'previous_owner' => 1],
        ['type' => 'bank_fallen', 'seat' => 0, 'territory' => $ids[1], 'zone' => 'euro'],
    ]]);

    $moments = app(HyperMatchMoments::class)->of($match->fresh(), (int) now()->getTimestampMs());
    $banks = count(array_filter(array_slice(HyperMap::BANK, 0, 30)));

    // 30 of the map's territories are well over half of all owned ones: dominance.
    expect(momentsOfKind($moments, 'hyper_leader')[0])->toMatchArray(['hyper_leader' => 'Bitcoiner bot', 'leader_territories' => Words::count(30, 'ein Gebiet', 'Gebiete'),
        'leader_banks' => Words::count($banks, 'eine Zentralbank', 'Zentralbanken'), 'mood' => Mood::DOMINANCE])
        ->and(momentsOfKind($moments, 'hyper_conquests')[0])->toMatchArray(['conqueror' => 'Bitcoiner bot', 'conquests' => 'zwei Gebiete'])
        ->and(momentsOfKind($moments, 'hyper_bank')[0])->toMatchArray(['bank_by' => 'Bitcoiner bot', 'bank_name' => 'EZB']);
});

/* ---------- Score games, tournaments, series ------------------------------------------------------ */

it('reads the score board: leader, gap to second, the mood by the gap, a tie at the top', function () {
    $close = ScoreMoments::board('Blockfill', [['place' => 1, 'name' => 'Ada', 'ms' => 40_000], ['place' => 2, 'name' => 'Bob', 'ms' => 40_120]]);
    $far = ScoreMoments::board('Blockfill', [['place' => 1, 'name' => 'Ada', 'ms' => 40_000], ['place' => 2, 'name' => 'Bob', 'ms' => 45_000]]);
    $middle = ScoreMoments::board('Blockfill', [['place' => 1, 'name' => 'Ada', 'ms' => 40_000], ['place' => 2, 'name' => 'Bob', 'ms' => 41_000]]);
    $tie = ScoreMoments::board('Trackmania', [['place' => 1, 'name' => 'Ada', 'ms' => 40_000], ['place' => 1, 'name' => 'Bob', 'ms' => 40_000]]);

    expect($close[0])->toMatchArray(['kind' => 'score_leader', 'score_leader' => 'Ada', 'score_second' => 'Bob', 'score_gap' => '0,12 Sekunden', 'gap_ms' => 120, 'mood' => Mood::CLOSE])
        ->and($far[0])->toMatchArray(['score_gap' => 'fünf Sekunden', 'mood' => Mood::DOMINANCE])
        ->and($middle[0])->not->toHaveKey('mood')
        ->and($tie[0])->toMatchArray(['kind' => 'score_tie', 'tied_a' => 'Ada', 'tied_b' => 'Bob', 'mood' => Mood::CLOSE])
        ->and(ScoreMoments::milliseconds('1:02.5'))->toBe(62_500)
        ->and(ScoreMoments::milliseconds('0:16.130'))->toBe(16_130)
        ->and(ScoreMoments::milliseconds('n/a'))->toBeNull();
});

it('reads a tournament frame: the field still in, the final, the upset by seed (none in a casual cup)', function () {
    $running = ['name' => 'Chess Cup #2', 'phase' => 'running', 'board' => ['kind' => 'bracket'], 'standing' => ['count' => 4, 'of' => 16, 'faces' => []],
        'upset' => ['winner' => ['name' => 'Cleo', 'seed' => 7], 'loser' => ['name' => 'Ada', 'seed' => 2]], 'cup' => false];
    $final = [...$running, 'standing' => ['count' => 2, 'of' => 16, 'faces' => [['name' => 'Cleo'], ['name' => 'Bob']]], 'upset' => null];

    expect(momentsOfKind(TournamentMoments::fromFrame($running), 'tournament_field')[0])->toMatchArray(['tournament' => 'Chess Cup Nummer 2', 'still_in' => 'vier von 16', 'mood' => Mood::TENSION])
        ->and(momentsOfKind(TournamentMoments::fromFrame($running), 'tournament_upset')[0])->toMatchArray(['upset_winner' => 'Cleo', 'upset_loser' => 'Ada', 'winner_seed' => 7, 'loser_seed' => 2, 'mood' => Mood::UPSET])
        ->and(momentsOfKind(TournamentMoments::fromFrame([...$running, 'cup' => true]), 'tournament_upset'))->toBe([])
        ->and(momentsOfKind(TournamentMoments::fromFrame([...$running, 'board' => ['kind' => 'table']]), 'tournament_field'))->toBe([])
        ->and(momentsOfKind(TournamentMoments::fromFrame($final), 'tournament_final')[0])->toMatchArray(['finalist_a' => 'Cleo', 'finalist_b' => 'Bob']);
});

it('reads a series score, a comeback from two games down and a series point', function () {
    $names = ['challenger' => 'Bern', 'challenged' => 'Wien'];
    $comeback = SeriesMoments::fromWinners(['challenged', 'challenged', 'challenger', 'challenger'], 5, $names, 'Rocket League');
    $lead = SeriesMoments::fromWinners(['challenger'], 3, $names, 'Rocket League');

    expect(momentsOfKind($comeback, 'series_score')[0])->toMatchArray(['series_score' => '2 zu 2', 'series_level' => '2 zu 2', 'mood' => Mood::CLOSE])
        ->and(momentsOfKind($comeback, 'series_comeback')[0])->toMatchArray(['comeback_by' => 'Bern', 'mood' => Mood::COMEBACK])
        ->and(momentsOfKind($comeback, 'series_point'))->toHaveCount(2)
        ->and(momentsOfKind($lead, 'series_score')[0])->toMatchArray(['series_leader' => 'Bern', 'series_score' => '1 zu 0'])
        ->and(momentsOfKind($lead, 'series_score')[0])->not->toHaveKey('series_level')
        ->and(momentsOfKind($lead, 'series_point')[0])->toMatchArray(['series_point' => 'Bern'])
        ->and(momentsOfKind($lead, 'series_comeback'))->toBe([]);
});

/* ---------- Into the facts and the pattern lines -------------------------------------------------- */

it('puts the moments of the live games into the facts of their topic, none at night', function () {
    $game = momentsChessGame(['e2e4', 'd7d5', 'e4d5']);
    $whiteName = SceneFacts::spoken($game->white->displayName());

    $facts = app(SceneFacts::class)->forTopic(SceneTopic::LIVE_GAMES);

    expect($facts['moments'] ?? [])->not->toBe([])
        ->and(momentsOfKind($facts['moments'], 'capture')[0]['capture_by'])->toBe($whiteName)
        ->and(app(GameMoments::class)->forTopic(SceneTopic::LIVE_GAMES, true))->toBe([])
        ->and(app(SceneFacts::class)->forTopic(SceneTopic::LEAGUE))->not->toHaveKey('moments');
});

it('takes the moments of several games in turn, at most the cap', function () {
    $a = [['kind' => 'a1'], ['kind' => 'a2'], ['kind' => 'a3']];
    $b = [['kind' => 'b1']];

    expect(array_column(GameMoments::interleave([$a, $b], 3), 'kind'))->toBe(['a1', 'b1', 'a2'])
        ->and(array_column(GameMoments::interleave([$a, $b], 12), 'kind'))->toBe(['a1', 'b1', 'a2', 'a3']);
});

it('counts the stones taken in Go and, once a side has passed, who is ahead by how much', function () {
    $names = ['w' => 'Ada', 'b' => 'Bob'];
    $base = ['game' => 'Go', 'a' => 'Ada', 'b' => 'Bob'];
    $rules = new GoRules(9);
    // Black (Ada) has five stones on the board, White (Bob) two.
    $count = fn (int $passes): array => $rules->areaCount($rules->deserialize("9/9/9/3xx4/3xo4/3xx4/9/9/8o x - {$passes}"));

    // Ada set five and has five; Bob set five and has two left: Ada took three.
    $playing = BoardMoments::go($count(0), 0, ['w' => 5, 'b' => 5], $names, $base);
    $passed = BoardMoments::go($count(1), 1, ['w' => 5, 'b' => 3], $names, $base);

    expect(momentsOfKind($playing, 'go_captures'))->toHaveCount(1)
        ->and(momentsOfKind($playing, 'go_captures')[0])->toMatchArray(['game' => 'Go', 'captor' => 'Ada', 'lead' => 'Ada', 'stones_taken' => 'drei Steine'])
        ->and(momentsOfKind($playing, 'go_count'))->toBe([])
        ->and(momentsOfKind($passed, 'go_captures')[0])->toMatchArray(['captor' => 'Ada', 'stones_taken' => 'einen Stein'])
        ->and(momentsOfKind($passed, 'go_count')[0])->toMatchArray(['count_leader' => 'Bob', 'lead' => 'Bob', 'count_margin' => '4,5 Punkte', 'mood' => Mood::TENSION]);
});

it('calls every Go size "Go" and reads the stones taken from the game played', function (string $slug) {
    GoOn::play();
    $boards = app(BoardGameService::class);
    $game = $boards->start($slug, User::factory()->create(), User::factory()->create());

    // Black takes the white stone on a1: b1, a1, a2.
    foreach (['b1', 'a1', 'a2'] as $move) {
        $game = $boards->move($game, $game->player($game->turn), $move, $game->ply + 1);
    }

    $moments = app(BoardMoments::class)->of($game, 'Ada', 'Bob');

    expect($moments)->toHaveCount(1)
        ->and($moments[0])->toMatchArray(['kind' => 'go_captures', 'game' => 'Go', 'captor' => 'Ada', 'stones_taken' => 'einen Stein'])
        ->and($moments[0]['match'])->toStartWith('Gopartie ');
})->with(['go-9', 'go-13', 'go-19']);

it('offers every moment as a slot set with its mood slot naming the player the moment is about', function () {
    $slots = PatternScripts::slots(['moments' => [['game' => 'Schach', 'kind' => 'material', 'mood' => Mood::DOMINANCE, 'lead' => 'Ada', 'ahead' => 'Ada', 'edge' => 'die Dame', 'edge_points' => 9]]]);

    expect($slots['items'])->toContain(['game' => 'Schach', 'lead' => 'Ada', 'ahead' => 'Ada', 'edge' => 'die Dame', 'edge_points' => '9', 'mood_dominance' => 'Ada']);
});

it('has moment lines that pass the script and fact guards when filled from a moment', function () {
    $data = json_decode((string) file_get_contents(resource_path('data/voice/caster.json')), true, flags: JSON_THROW_ON_ERROR);
    // One value per slot, as the moment classes write them.
    $sample = [
        'game' => 'Schach', 'white' => 'Ada', 'black' => 'Bob', 'a' => 'Ada', 'b' => 'Bob', 'lead' => 'Ada', 'move' => '14', 'round' => '3',
        'check_by' => 'Ada', 'check_to' => 'Bob', 'check_color' => 'Weiß', 'capture_color' => 'Schwarz', 'comeback_color' => 'Weiß', 'clock_color' => 'Schwarz', 'capture_by' => 'Ada', 'captured' => 'einen Springer', 'exchange' => 'drei Schlagzüge',
        'comeback_by' => 'Ada', 'comeback_from' => 'drei Punkte', 'ahead' => 'Ada', 'ahead_color' => 'Weiß', 'behind' => 'Bob', 'edge' => 'eine Qualität',
        'level_off' => 'sechs Steine', 'clock_low' => 'Bob', 'phase_in' => 'im Endspiel', 'white_lost' => 'zwei Figuren und einen Bauern', 'black_lost' => 'eine Figur',
        'race_leader' => 'Ada', 'race_trailer' => 'Bob', 'leader_steps' => 'drei Schritte', 'trailer_steps' => 'fünf Schritte', 'leader_blocks_left' => 'vier Blöcke',
        'trailer_blocks_left' => 'zwei Blöcke', 'leader_blocks_used' => 'sechs Blöcke', 'level_a_steps' => 'drei Schritte', 'level_b_steps' => 'vier Schritte', 'one_step' => 'Bob',
        'men_leader' => 'Ada', 'men_trailer' => 'Bob', 'leader_men' => 'sieben Steine', 'trailer_men' => 'vier Steine', 'trailer_men_lost' => 'fünf Steine', 'men_each' => 'sechs Steine',
        'mill_owner' => 'Ada', 'mills' => 'zwei Mühlen', 'flying' => 'Bob', 'pieces_leader' => 'Ada', 'pieces_trailer' => 'Bob', 'leader_pieces' => 'acht Steine',
        'trailer_pieces' => 'fünf Steine', 'pieces_each' => 'sieben Steine', 'king_owner' => 'Ada', 'kings' => 'eine Dame',
        'captor' => 'Ada', 'stones_taken' => 'drei Steine', 'count_leader' => 'Bob', 'count_margin' => '7,5 Punkte',
        'hyper_leader' => 'Ada', 'leader_territories' => '13 Gebiete', 'leader_banks' => 'zwei Zentralbanken', 'duel_a' => 'Ada', 'duel_b' => 'Bob', 'bank_by' => 'Ada',
        'bank_name' => 'EZB', 'conqueror' => 'Ada', 'conquests' => 'drei Gebiete', 'still_in' => 'drei Spieler',
        'series_leader' => 'Bern', 'series_score' => '2 zu 1', 'series_level' => '2 zu 2', 'series_point' => 'Bern',
        'pong_score' => '21 zu 19', 'pong_winner' => 'Ada', 'pong_loser' => 'Bob', 'deficit' => 'sieben Punkte', 'saver' => 'Ada', 'match_points_saved' => 'zwei Matchbälle',
        'lead_changes' => 'drei Führungswechsel', 'rally_hits' => '23 Schläge',
        'tournament' => 'Chess Cup', 'finalist_a' => 'Ada', 'finalist_b' => 'Bob', 'upset_winner' => 'Cleo', 'upset_loser' => 'Ada', 'winner_seed' => '7', 'loser_seed' => '2',
        'score_leader' => 'Ada', 'score_second' => 'Bob', 'score_gap' => '0,12 Sekunden', 'tied_a' => 'Ada', 'tied_b' => 'Bob', 'new_top' => 'Ada', 'dethroned' => 'Bob',
        'best_by' => 'Ada', 'bests_today' => 'zwei neue Bestzeiten', 'attempts_today' => '17 Versuche',
        'mood_dominance' => 'Ada', 'mood_close' => 'Ada', 'mood_tension' => 'Ada', 'mood_comeback' => 'Ada', 'mood_upset' => 'Cleo',
    ];

    expect($data['moment_lines'])->not->toBe([]);

    foreach ($data['moment_lines'] as $row) {
        $line = PatternScripts::fill($row['text'], $sample);
        preg_match_all('/\{([a-z0-9_]+)\}/', $row['text'], $used);
        $facts = ['sender' => 'TWENTY ONE TV', 'moments' => [array_intersect_key($sample, array_flip($used[1]))]];

        expect($line)->not->toBeNull("{$row['id']}: a slot without a sample")
            ->and(array_diff($row['topics'], SceneTopic::ALL))->toBe([], "{$row['id']}: unknown topic")
            ->and(app(ScriptGuard::class)->check((string) $line, 1, 3, (int) config('tv.commentary.scripts.max_words_per_sentence', 16)))->toBe([], "{$row['id']}: {$line}")
            ->and(app(CasterVoice::class)->guard()->check(ScriptGuard::withoutMarkers((string) $line), $facts, 900, null))->toBe([], "{$row['id']}: {$line}");
    }
});

it('ranks the live chess games by what they give the commentary, the newest first on a tie', function () {
    $quietOld = momentsChessGame(['e2e4', 'e7e5']);
    // 1. e4 d5 2. exd5 Qxd5 3. Nc3 Qe5+: a check, a level trade.
    $check = momentsChessGame(['e2e4', 'd7d5', 'e4d5', 'd8d5', 'b1c3', 'd5e5']);
    $quietA = momentsChessGame(['d2d4', 'd7d5']);
    $quietB = momentsChessGame(['c2c4', 'c7c5']);

    $ranked = array_map(fn (array $entry): int => $entry['game']->id, app(GameMoments::class)->rankedChess());

    expect($ranked)->toBe([$check->id, $quietB->id, $quietA->id])
        ->and($ranked)->not->toContain($quietOld->id)
        ->and(GameMoments::worth([['kind' => 'check'], ['kind' => 'material', 'mood' => Mood::DOMINANCE], ['kind' => 'phase', 'phase' => 'Endspiel'], ['kind' => 'losses']]))->toBe(5 + 4 + 3)
        ->and(GameMoments::worth([['kind' => 'phase', 'phase' => 'Eröffnung']]))->toBe(0)
        // The live games the facts name are the ranked ones, in that order.
        ->and(array_slice(array_column(app(SceneFacts::class)->forTopic(SceneTopic::LIVE_GAMES)['live_games'], 'move'), 0, 3))->toBe([4, 2, 2]);
});

it('writes live pattern scripts that call the game from the moments without naming the players', function () {
    config(['tv.commentary.enabled' => true]);
    $facts = ['sender' => 'TWENTY ONE TV', 'website_spoken' => 'esports punkt einundzwanzig punkt space', 'players' => 34, 'clans' => 8,
        'live_games' => [['game' => 'Schach', 'white' => 'Ada', 'black' => 'Bob', 'mode' => 'Fernschach', 'move' => 22]],
        'moments' => [
            ['game' => 'Schach', 'white' => 'Ada', 'black' => 'Bob', 'kind' => 'material', 'mood' => Mood::DOMINANCE, 'lead' => 'Ada', 'ahead' => 'Ada', 'ahead_color' => 'Weiß', 'behind' => 'Bob', 'edge' => 'die Dame', 'edge_points' => 9],
            ['game' => 'Schach', 'white' => 'Ada', 'black' => 'Bob', 'kind' => 'check', 'mood' => Mood::TENSION, 'lead' => 'Bob', 'check_by' => 'Bob', 'check_color' => 'Schwarz', 'check_to' => 'Ada'],
            ['game' => 'Schach', 'white' => 'Ada', 'black' => 'Bob', 'kind' => 'phase', 'mood' => Mood::TENSION, 'phase' => 'Endspiel', 'phase_in' => 'im Endspiel', 'move' => 22],
        ]];
    $lines = [];

    for ($i = 0; $i < 10; $i++) {
        $script = app(PatternScripts::class)->write(SceneTopic::LIVE_GAMES, 'live_match', $facts);

        if ($script !== null) {
            $lines = [...$lines, ...$script['lines']];
            expect($script['text'])->not->toContain('Ada')->not->toContain('Bob');
        }
    }

    expect(array_filter($lines, fn (string $id): bool => str_starts_with($id, 'ml-')))->not->toBe([]);
});

it('ties every moment line to a slot of its own kind, so a mood of one game never fills a line about another', function () {
    $data = json_decode((string) file_get_contents(resource_path('data/voice/caster.json')), true, flags: JSON_THROW_ON_ERROR);
    // Slots every kind may carry: the game, the players, the mood and lead of any moment.
    $shared = ['game', 'white', 'black', 'a', 'b', 'lead', 'move', 'round', 'tournament'];

    foreach ($data['moment_lines'] as $row) {
        preg_match_all('/\{\??([a-z0-9_]+)\}/', $row['text'], $slots);
        $own = array_filter($slots[1], fn (string $slot): bool => ! in_array($slot, $shared, true) && ! str_starts_with($slot, 'mood_'));

        expect($own)->not->toBe([], "{$row['id']}: {$row['text']}");
    }

    // Seen on the snapshot (2026-10-11): a chess game's dominance filled "Da zieht jemand im Rennen richtig davon."
    $chess = PatternScripts::slots(['moments' => [['game' => 'Schach', 'kind' => 'material', 'mood' => Mood::DOMINANCE, 'lead' => 'Ada', 'ahead' => 'Ada', 'ahead_color' => 'Weiß', 'edge' => 'die Dame']]])['items'][0];
    $race = collect($data['moment_lines'])->firstWhere('id', 'ml-race-10');

    expect(PatternScripts::fill($race['text'], $chess))->toBeNull();
});

it('never names the player of a moment outside a result, also where the facts list no players (Hyperbitcoinization)', function () {
    config(['tv.commentary.enabled' => true]);
    $facts = ['sender' => 'TWENTY ONE TV', 'website_spoken' => 'esports punkt einundzwanzig punkt space', 'players' => 34, 'clans' => 8,
        'moments' => [['game' => 'Hyperbitcoinization', 'round' => 7, 'kind' => 'hyper_leader', 'mood' => Mood::DOMINANCE, 'lead' => 'Zorro', 'hyper_leader' => 'Zorro', 'leader_territories' => '30 Gebiete', 'leader_banks' => 'drei Zentralbanken']]];
    $texts = [];

    for ($i = 0; $i < 10; $i++) {
        $texts[] = app(PatternScripts::class)->write(SceneTopic::LIVE_GAMES, 'live_match', $facts)['text'] ?? '';
    }

    expect(implode("\n", $texts))->not->toContain('Zorro')
        ->and(implode("\n", $texts))->toContain('Gebiete');
});
