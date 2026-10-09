<?php

namespace Database\Factories;

use App\Enums\PongEndReason;
use App\Enums\PongMatchStatus;
use App\Models\PongMatch;
use App\Models\User;
use App\Support\Pong\PongReferee;
use App\Support\Pong\PongRules;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A live Proof of Pong match between two new players, waiting for both pages. Matches that people play are made by
 * App\Support\Pong\PongMatches::create(); this is for tests and seeding.
 *
 * @extends Factory<PongMatch>
 */
class PongMatchFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'left_id' => User::factory(),
            'right_id' => User::factory(),
            'seed' => random_int(0, 0xFFFFFFFF),
            'status' => PongMatchStatus::Waiting,
            'state' => ['ref' => null, 'speed' => 1, 'seen' => [null, null], 'rematch' => [false, false], 'next' => null, 'version' => 1],
            'log' => [],
            'rated' => true,
        ];
    }

    /** In play, both players just seen, the first rally served a moment from now. */
    public function active(): static
    {
        return $this->state(function (array $attributes): array {
            $now = (int) now()->getTimestampMs();
            $referee = PongReferee::start((int) $attributes['seed'], new PongRules, $now);

            return [
                'status' => PongMatchStatus::Active,
                'started_at' => now(),
                'state' => ['ref' => $referee->toArray(), 'speed' => 1, 'seen' => [$now, $now], 'rematch' => [false, false], 'next' => null, 'version' => 2],
            ];
        });
    }

    /**
     * Over: side `$winner` won with `$score` (left, right).
     *
     * @param  array{int, int}  $score
     */
    public function finished(int $winner = 0, array $score = [21, 15], PongEndReason $reason = PongEndReason::Score): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PongMatchStatus::Finished,
            'score_left' => $score[0],
            'score_right' => $score[1],
            'winner_id' => $winner === 0 ? $attributes['left_id'] : $attributes['right_id'],
            'end_reason' => $reason,
            'started_at' => now()->subMinutes(5),
            'ended_at' => now(),
        ]);
    }

    /** Never started: no winner, never rated. */
    public function aborted(): static
    {
        return $this->state(fn (): array => ['status' => PongMatchStatus::Aborted, 'end_reason' => PongEndReason::Abort, 'ended_at' => now()]);
    }
}
