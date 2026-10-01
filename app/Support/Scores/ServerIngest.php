<?php

namespace App\Support\Scores;

use App\Games\Contracts\Game;
use App\Games\GameRegistry;
use App\Games\ScoreGame;
use App\Models\ScoreRun;
use App\Models\ScoreServer;
use Illuminate\Database\UniqueConstraintViolationException;
use LogicException;

/**
 * The base of every adapter for our own dedicated servers (plan "AoE2 und
 * Trackmania", P4): a server reports finishes (a TMNF `PlayerFinish`, an ACC
 * results file, a sim server's lap) and the league stores them as runs. No
 * concrete game here: an adapter turns its server's payload into
 * FinishEvents (parse()); this base checks and stores them.
 *
 * - Authenticated per server: the controller finds the ScoreServer by its
 *   hashed bearer token; a revoked server is refused.
 * - Validated per event: the server's game is a registered score game, the
 *   mode is one of its modes, the course has its shape, the account id and
 *   event id are set, the value is not negative, `achieved_at` is not in the
 *   future (five minutes of clock skew allowed). A bad event is refused on
 *   its own; the others of the batch are stored.
 * - Idempotent by (server, event id): the same event sent twice is stored once.
 * - Mapped by the privately stored account id (users.gamer_tags under the
 *   game's account service): exactly one player with that id takes the run;
 *   none, or more than one, leaves it pending (`user_id` null), which no page
 *   ever shows. Only an admin hands pending runs to a player
 *   (ScoreAccounts::confirm(), security gate F4).
 * - Trusted as read: our own server's record is verified at once (source
 *   `server`); admins still correct a leaderboard through its directors.
 */
abstract class ServerIngest
{
    public const SOURCE = 'server';

    /** Clock skew between a server and the league that is still no finish "in the future". */
    public const SKEW_MINUTES = 5;

    /**
     * The finish events of one payload, as the server sent them.
     *
     * @param  array<string, mixed>  $payload
     * @return list<FinishEvent|string> an event, or the error code of an entry that could not be read
     */
    abstract public function parse(array $payload): array;

    /**
     * @param  array<string, mixed>  $payload
     * @return array{accepted: int, duplicate: int, pending: int, refused: array<int, string>}
     */
    public function ingest(ScoreServer $server, array $payload): array
    {
        $game = app(GameRegistry::class)->find($server->game);
        $summary = ['accepted' => 0, 'duplicate' => 0, 'pending' => 0, 'refused' => []];

        foreach ($this->parse($payload) as $index => $event) {
            $error = is_string($event) ? $event : $this->refusal($game, $event);

            if (is_string($event) || $error !== null || ! $game instanceof ScoreGame) {
                $summary['refused'][$index] = $error ?? 'game';

                continue;
            }

            if (ScoreRun::query()->where(['score_server_id' => $server->id, 'external_id' => $event->id])->exists()) {
                $summary['duplicate']++;

                continue;
            }

            $userId = ScoreAccounts::userFor($game, $event->accountId);

            try {
                ScoreRun::query()->create([
                    'user_id' => $userId,
                    'game' => $game->slug(),
                    'mode' => $event->mode,
                    'course' => $event->course,
                    'value' => $event->value,
                    'unit' => $game->metric($game->mode($event->mode) ?? throw new LogicException('The mode was checked above.'))->unit,
                    'source' => self::SOURCE,
                    'achieved_at' => $event->achievedAt,
                    'verified_at' => now(),
                    'raw' => $event->raw,
                    'account_id' => $event->accountId,
                    'score_server_id' => $server->id,
                    'external_id' => $event->id,
                ]);
            } catch (UniqueConstraintViolationException) {
                $summary['duplicate']++;

                continue;
            }

            $summary[$userId === null ? 'pending' : 'accepted']++;
        }

        $server->forceFill(['last_seen_at' => now()])->save();

        return $summary;
    }

    private function refusal(?Game $game, FinishEvent $event): ?string
    {
        return match (true) {
            ! $game instanceof ScoreGame => 'game',
            $event->id === '' || mb_strlen($event->id) > 128 => 'id',
            $game->mode($event->mode) === null => 'mode',
            ! $game->isCourse($event->course) => 'course',
            $event->accountId === '' || mb_strlen($event->accountId) > 128 => 'account',
            $event->value < 0 => 'value',
            $event->achievedAt->greaterThan(now()->addMinutes(self::SKEW_MINUTES)) => 'achieved_at',
            $event->raw !== null && strlen((string) json_encode($event->raw)) > 16_384 => 'raw',
            default => null,
        };
    }
}
