<?php

namespace Database\Factories;

use App\Enums\ChessEndReason;
use App\Enums\ChessGameStatus;
use App\Models\ChessGame;
use App\Models\User;
use App\Support\SeasonChain\GatePin;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A casual blitz 5+3 game in its start position, White to move. Games with
 * moves are played through App\Support\Chess\ChessGameService, never
 * written here by hand.
 *
 * @extends Factory<ChessGame>
 */
class ChessGameFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $now = (int) now()->getTimestampMs();

        return [
            'mode' => 'blitz',
            'rated' => false,
            'white_id' => User::factory(),
            'black_id' => User::factory(),
            'status' => ChessGameStatus::Active,
            'fen' => ChessGame::START_FEN,
            'ply' => 0,
            'initial_ms' => 300_000,
            'increment_ms' => 3_000,
            'white_ms' => 300_000,
            'black_ms' => 300_000,
            'turn_started_ms' => $now,
            'deadline_ms' => $now + 30_000,
        ];
    }

    /**
     * A daily game (one move per day) in its start position: White has a day
     * for the first move. Real daily games start through App\Support\Chess\DailyChallenges.
     */
    public function daily(): static
    {
        return $this->state(fn (array $attributes) => [
            'mode' => ChessGame::CORRESPONDENCE,
            'initial_ms' => 86_400_000,
            'increment_ms' => 0,
            'white_ms' => 86_400_000,
            'black_ms' => 86_400_000,
            'deadline_ms' => (int) now()->getTimestampMs() + 86_400_000,
        ]);
    }

    /**
     * A rated game whose trust gate the league pinned at the pairing: both
     * players at rank `$rank`, listing each other. Real rated games are
     * paired and pinned by App\Support\Chess\ChessQueue.
     */
    public function rated(int $rank = 100): static
    {
        return $this->state(['rated' => true])->afterCreating(function (ChessGame $game) use ($rank): void {
            $players = [$game->white->pubkey, $game->black->pubkey];
            $facts = ['trust' => array_fill_keys($players, $rank), 'anchors' => [], 'connected' => true];

            $game->forceFill(['gate_at_accept' => GatePin::fromFacts($players, [$players[0], $players[1]], $facts, (int) config('season.trust_minimum'))->toArray()])->save();
        });
    }

    /**
     * Over with no moves played (e.g. a resignation), for pages that only need a finished game.
     */
    public function finished(string $result = '1-0', ChessEndReason $reason = ChessEndReason::Resignation): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ChessGameStatus::Finished,
            'result' => $result,
            'end_reason' => $reason,
            'deadline_ms' => null,
            'ended_at' => now(),
        ]);
    }
}
