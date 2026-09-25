<?php

namespace Database\Seeders;

use App\Enums\ChessEndReason;
use App\Enums\ChessGameStatus;
use App\Models\ChessGame;
use App\Models\ChessMove;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PChess\Chess\Chess;

/**
 * Finished blitz games from the sample ledger (SAMPLE-LEDGER.md 4.6), so
 * `composer dev` shows a game history, for local development only.
 *
 * Every move is played through the rules engine, so a typo in a line fails
 * the seeder instead of storing an impossible game. The ledger gives result,
 * ending and players; where it has no move list (#400, #375) a real line of
 * the same kind stands in. All are casual (P5a has no Elo). Thinking time is
 * spread evenly so the stored clocks end on the ledger's "time left".
 * Idempotent: a game already seeded (same players, same start) is skipped.
 */
class ChessGameSeeder extends Seeder
{
    /**
     * [ledger #, white, black, start, end, SAN moves, result, ending, white ms left, black ms left, status]
     */
    private const GAMES = [
        ['#404/2', 'satsjäger', 'feebump', '2026-09-25 19:46', '2026-09-25 19:58',
            'e4 e5 Nf3 Nc6 Bc4 Bc5 c3 Nf6 d4 exd4 cxd4 Bb4+ Nc3 Nxe4 O-O Bxc3 d5 Bf6 Re1 Ne7 Rxe4 d6 Bg5 Bxg5 Nxg5 O-O Nxh7 Kxh7 Qh5+ Kg8 Rh4 Nf5 Qh8#',
            '1-0', ChessEndReason::Checkmate, 19_000, 91_000, ChessGameStatus::Finished],
        ['#400', 'difficulty_dan', 'hashhodler', '2026-09-24 20:10', '2026-09-24 20:16',
            'e4 e5 Nf3 Nc6 Bc4 Nd4 Nxe5 Qg5 Nxf7 Qxg2 Rf1 Qxe4+ Be2 Nf3#',
            '0-1', ChessEndReason::Checkmate, 228_000, 262_000, ChessGameStatus::Finished],
        ['#375', 'satsjäger', 'hodlqueen', '2026-09-21 19:02', '2026-09-21 19:14',
            'd4 Nf6 c4 g6 Nc3 Bg7 e4 d6 Nf3 O-O Be2 e5 O-O Nc6 d5 Ne7 Ne1 Nd7 Nd3 f5 Bd2 Nf6 f3 f4 c5 g5 cxd6 cxd6 Nf2 h5 h3 Ng6 Qc2 Rf7 Rfc1 Bf8 Nb5 a6 Na3 g4 fxg4',
            '1-0', ChessEndReason::Timeout, 47_000, 0, ChessGameStatus::Finished],
        ['#385', 'rbf_rita', 'pillpusher', '2026-09-22 18:30', '2026-09-22 18:31',
            'e4', null, ChessEndReason::Aborted, 300_000, 300_000, ChessGameStatus::Aborted],
    ];

    public function run(): void
    {
        foreach (self::GAMES as [$number, $whiteName, $blackName, $start, $end, $san, $result, $reason, $whiteLeft, $blackLeft, $status]) {
            $white = $this->player($whiteName);
            $black = $this->player($blackName);
            $startedAt = Carbon::parse($start);

            if (ChessGame::query()->where('white_id', $white->id)->where('black_id', $black->id)->where('created_at', $startedAt)->exists()) {
                continue;
            }

            $sans = explode(' ', $san);
            $spent = ['w' => $this->spentPerMove($sans, 0, $whiteLeft), 'b' => $this->spentPerMove($sans, 1, $blackLeft)];
            $clock = ['w' => 300_000, 'b' => 300_000];
            $chess = new Chess;

            DB::transaction(function () use ($number, $white, $black, $startedAt, $end, $sans, $spent, $clock, $chess, $result, $reason, $whiteLeft, $blackLeft, $status): void {
                $game = ChessGame::query()->create([
                    'mode' => 'blitz',
                    'rated' => false,
                    'white_id' => $white->id,
                    'black_id' => $black->id,
                    'status' => $status,
                    'result' => $result,
                    'end_reason' => $reason,
                    'fen' => ChessGame::START_FEN,
                    'ply' => count($sans),
                    'initial_ms' => 300_000,
                    'increment_ms' => 3_000,
                    'white_ms' => $whiteLeft,
                    'black_ms' => $blackLeft,
                    'turn_started_ms' => $startedAt->getTimestampMs(),
                    'ended_at' => Carbon::parse($end),
                ]);

                foreach ($sans as $index => $move) {
                    $color = $index % 2 === 0 ? 'w' : 'b';
                    $played = $chess->move($move) ?? throw new \UnexpectedValueException("{$number}: {$move} is illegal in {$chess->fen()}.");
                    $thinking = $index < 2 ? 0 : $spent[$color];

                    if ($index >= 2) {
                        $clock[$color] += 3_000 - $thinking;
                    }

                    ChessMove::query()->create([
                        'chess_game_id' => $game->id,
                        'ply' => $index + 1,
                        'uci' => $played->from.$played->to.($played->promotion ?? ''),
                        'san' => (string) $played->san,
                        'fen' => $chess->fen(),
                        'spent_ms' => $thinking,
                        'clock_ms' => $clock[$color],
                    ]);
                }

                $game->forceFill(['fen' => $chess->fen(), 'created_at' => $startedAt, 'updated_at' => Carbon::parse($end)])->save();
            });
        }
    }

    /**
     * Even thinking time per clocked move (every move after a side's first),
     * so that side ends on `left`: 5 min plus increments, minus what it spent.
     *
     * @param  list<string>  $sans
     */
    private function spentPerMove(array $sans, int $first, int $left): int
    {
        $clocked = max(1, intdiv(count($sans) - $first + 1, 2) - 1);

        return max(0, intdiv(300_000 + ($clocked * 3_000) - $left, $clocked));
    }

    private function player(string $name): User
    {
        return User::query()->where('name', $name)->first() ?? User::factory()->create(['name' => $name]);
    }
}
