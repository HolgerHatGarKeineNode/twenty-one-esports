<?php

use App\Enums\ChessEndReason;
use App\Models\ChessGame;
use App\Models\User;
use App\Support\Chess\ChessGameService;
use App\Support\Chess\ChessPgn;
use PChess\Chess\Chess;
use PHPUnit\Framework\ExpectationFailedException;

/**
 * NIP-64 asks publishers for PGN "export format". These tests read the PGN
 * the way a strict reader would (PGN standard 3.2, 4.2, 8.1, 8.2), without
 * reusing the builder, and replay the movetext on an independent board.
 */

/**
 * A game with frozen tag values, as `pgn_headers` holds them.
 *
 * @param  array<string, string>  $headers
 */
function pgnGame(array $headers, ?string $startFen = null): ChessGame
{
    return (new ChessGame)->forceFill(['pgn_headers' => $headers, 'start_fen' => $startFen]);
}

/**
 * Check a PGN against the export format and replay its moves. Returns the
 * tag pairs and the SAN list it read.
 *
 * @return array{tags: array<string, string>, sans: list<string>}
 */
function readExportPgn(string $pgn): array
{
    expect(str_ends_with($pgn, "\n"))->toBeTrue()
        ->and(str_contains($pgn, "\t"))->toBeFalse('a tab in export format')
        ->and(str_contains($pgn, "\r"))->toBeFalse('a carriage return in export format');

    $lines = explode("\n", substr($pgn, 0, -1));
    $tags = [];

    // Tag pairs: each left-justified on a line by itself, exactly `[Name "value"]`.
    while ($lines !== [] && $lines[0] !== '') {
        $line = array_shift($lines);
        if (preg_match('/^\[([A-Za-z0-9_]+) "((?:[^"\\\\]|\\\\.)*)"\]$/', $line, $match) !== 1) {
            throw new ExpectationFailedException("not an export-format tag pair: {$line}");
        }

        expect($tags)->not->toHaveKey($match[1]);
        $tags[$match[1]] = stripcslashes($match[2]);
    }

    // "a single empty line follows the last tag pair"
    expect(array_shift($lines))->toBe('')
        ->and($lines)->not->toBeEmpty()
        ->and($lines)->not->toContain('');

    $names = array_keys($tags);
    $roster = ['Event', 'Site', 'Date', 'Round', 'White', 'Black', 'Result'];
    $rest = array_slice($names, 7);
    $sorted = $rest;
    sort($sorted, SORT_STRING);

    // The Seven Tag Roster first and in order, every further tag in ASCII order by name.
    expect(array_slice($names, 0, 7))->toBe($roster)
        ->and($rest)->toBe($sorted, 'supplemental tags not in ASCII order');

    foreach ($lines as $line) {
        // "less than 80 printing characters", neither first nor last character a space.
        expect(mb_strlen($line))->toBeLessThan(80, "movetext line too long: {$line}")
            ->and($line)->toBe(trim($line));
    }

    $tokens = explode(' ', implode(' ', $lines));
    $result = array_pop($tokens);
    expect($result)->toBe($tags['Result']);

    $board = new Chess(($tags['SetUp'] ?? '0') === '1' ? $tags['FEN'] : null);
    $sans = [];

    foreach ($tokens as $index => $token) {
        if (preg_match('/^(\d+)(\.|\.\.\.)$/', $token, $number) === 1) {
            $fen = explode(' ', $board->fen());
            // A move number names the move that follows it: the right number, three dots only before Black.
            expect($number[1])->toBe($fen[5])
                ->and($number[2])->toBe($fen[1] === 'w' ? '.' : '...');

            continue;
        }

        expect($board->move($token))->not->toBeNull("illegal or non-SAN move {$token} at token {$index}");
        $sans[] = $token;
    }

    return ['tags' => $tags, 'sans' => $sans];
}

test('the casual daily game #41 that Amethyst showed is rebuilt in strict export format, byte for byte', function () {
    // Game #41, event a2c3cec2bc5a408c5e789d5c189b3525d338e9b9fb2744f7756ec367abf4ba8f (published 2026-10-05).
    $game = pgnGame([
        'Event' => 'TWENTY ONE esports, casual daily chess',
        'Site' => 'https://esports.einundzwanzig.space/games/40',
        'Date' => '2026.09.29',
        'Round' => '-',
        'White' => 'M.W.',
        'Black' => 'El Presidento Ben',
        'TimeControl' => '1/86400',
    ]);
    $sans = explode(' ', 'f4 f5 e3 g6 Bc4 e6 g3 Bd6 Qf3 Nf6 Nh3 Ng4 Ng5 h6 Nh3 O-O e4 Qf6 d3 Nc6 e5 Nd4 exf6 Nxf3+ Ke2 Nd4+ Ke1 Bb4+ Bd2 a5 c3 Nc2+ Ke2 d5 cxb4 Nxa1 bxa5 dxc4 dxc4 b6 Bb4 c5 Rd1 cxb4 a3 Ba6 Nd2 Rad8 Nf1 Bxc4+ Ke1 Nc2#');

    $pgn = ChessPgn::build($game, $sans, '0-1', ChessEndReason::Checkmate);

    // Published with TimeControl before Termination and three 80-character movetext lines.
    expect($pgn)->toBe(<<<'PGN'
        [Event "TWENTY ONE esports, casual daily chess"]
        [Site "https://esports.einundzwanzig.space/games/40"]
        [Date "2026.09.29"]
        [Round "-"]
        [White "M.W."]
        [Black "El Presidento Ben"]
        [Result "0-1"]
        [Termination "normal"]
        [TimeControl "1/86400"]

        1. f4 f5 2. e3 g6 3. Bc4 e6 4. g3 Bd6 5. Qf3 Nf6 6. Nh3 Ng4 7. Ng5 h6 8. Nh3
        O-O 9. e4 Qf6 10. d3 Nc6 11. e5 Nd4 12. exf6 Nxf3+ 13. Ke2 Nd4+ 14. Ke1 Bb4+
        15. Bd2 a5 16. c3 Nc2+ 17. Ke2 d5 18. cxb4 Nxa1 19. bxa5 dxc4 20. dxc4 b6 21.
        Bb4 c5 22. Rd1 cxb4 23. a3 Ba6 24. Nd2 Rad8 25. Nf1 Bxc4+ 26. Ke1 Nc2# 0-1
        PGN."\n")
        ->and(readExportPgn($pgn)['sans'])->toBe($sans);
});

test('tags after the Seven Tag Roster come in ASCII order, and a set-up game replays from its FEN', function () {
    // Black to move on move 3: the movetext opens with "3...".
    $fen = 'rnbqkbnr/pppp1ppp/8/4p3/4P3/5N2/PPPP1PPP/RNBQKB1R b KQkq - 1 2';
    $game = pgnGame([
        'Event' => 'Casual Game', 'Site' => '?', 'Date' => '2026.10.05', 'Round' => '-', 'White' => 'anna', 'Black' => 'bert',
        'TimeControl' => '300+3', 'SetUp' => '1', 'FEN' => $fen,
    ], $fen);

    $pgn = ChessPgn::build($game, ['Nc6', 'Bb5', 'a6'], '*', null);
    $read = readExportPgn($pgn);

    expect(array_slice(array_keys($read['tags']), 7))->toBe(['FEN', 'SetUp', 'Termination', 'TimeControl'])
        ->and($read['tags']['Termination'])->toBe('unterminated')
        ->and($pgn)->toEndWith("\n\n2... Nc6 3. Bb5 a6 *\n");
});

test('a player name with a newline, tab or quote stays one tag line and the PGN still reads', function () {
    $game = pgnGame([
        'Event' => "Casual\tGame", 'Site' => '?', 'Date' => '2026.10.05', 'Round' => '-',
        'White' => "anna\n[Result \"1-0\"]", 'Black' => "  \r\n\u{2028} ", 'TimeControl' => '-',
    ]);

    $pgn = ChessPgn::build($game, ['e4', 'e5'], '*', null);
    $read = readExportPgn($pgn);

    expect($read['tags']['White'])->toBe('anna [Result "1-0"]')
        ->and($read['tags']['Black'])->toBe('?')
        ->and($read['tags']['Event'])->toBe('Casual Game')
        ->and($pgn)->toContain('[White "anna [Result \"1-0\"]"]');
});

test('a game played through the server exports a PGN a strict reader replays to the stored moves', function () {
    $white = User::factory()->create(['name' => "anna\nmaria"]);
    $black = User::factory()->create(['name' => 'bert']);
    $games = app(ChessGameService::class);
    $game = $games->start($white, $black, ChessGame::CORRESPONDENCE);

    foreach (['e2e4', 'e7e5', 'g1f3', 'b8c6', 'f1b5', 'a7a6', 'b5a4', 'g8f6', 'e1g1', 'f8e7', 'f1e1', 'b7b5', 'a4b3', 'd7d6', 'c2c3', 'e8g8', 'h2h3', 'c6b8', 'd2d4', 'b8d7'] as $uci) {
        $game = $games->move($game->refresh(), $game->turn() === 'w' ? $white : $black, $uci);
    }

    $game->refresh()->load('moves');
    $read = readExportPgn(ChessPgn::of($game));

    expect($read['sans'])->toBe($game->moves->pluck('san')->all())
        ->and($read['tags']['White'])->toBe('anna maria')
        ->and($read['tags']['Result'])->toBe('*');
});

test('the strict reader rejects what game #41 was published with: tag order and 80-character lines', function () {
    $published = "[Event \"TWENTY ONE esports, casual daily chess\"]\n[Site \"https://esports.einundzwanzig.space/games/40\"]\n[Date \"2026.09.29\"]\n[Round \"-\"]\n[White \"M.W.\"]\n[Black \"El Presidento Ben\"]\n[Result \"0-1\"]\n[TimeControl \"1/86400\"]\n[Termination \"normal\"]\n\n1. f4 f5 2. e3 g6 3. Bc4 e6 4. g3 Bd6 5. Qf3 Nf6 6. Nh3 Ng4 7. Ng5 h6 8. Nh3 O-O\n9. e4 Qf6 10. d3 Nc6 11. e5 Nd4 12. exf6 Nxf3+ 13. Ke2 Nd4+ 14. Ke1 Bb4+ 15. Bd2\na5 16. c3 Nc2+ 17. Ke2 d5 18. cxb4 Nxa1 19. bxa5 dxc4 20. dxc4 b6 21. Bb4 c5 22.\nRd1 cxb4 23. a3 Ba6 24. Nd2 Rad8 25. Nf1 Bxc4+ 26. Ke1 Nc2# 0-1\n";
    $reordered = str_replace("[TimeControl \"1/86400\"]\n[Termination \"normal\"]", "[Termination \"normal\"]\n[TimeControl \"1/86400\"]", $published);

    expect(fn () => readExportPgn($published))->toThrow(ExpectationFailedException::class, 'supplemental tags not in ASCII order')
        ->and(fn () => readExportPgn($reordered))->toThrow(ExpectationFailedException::class, 'movetext line too long');
});
