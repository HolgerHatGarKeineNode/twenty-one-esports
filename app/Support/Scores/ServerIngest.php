<?php

namespace App\Support\Scores;

use App\Games\Contracts\Game;
use App\Games\GameRegistry;
use App\Games\ScoreGame;
use App\Models\ScoreRun;
use App\Models\ScoreServer;
use App\Models\Tournament;
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
 * - Mapped only by an account id an admin confirmed for a player
 *   (ScoreAccounts::userFor(), re-audit F4): any other finish stays pending
 *   (`user_id` null) with its account id, which no public page ever shows,
 *   until an admin confirms the claim on /admin/scores.
 * - Trusted as read: our own server's record is verified at once (source
 *   `server`); admins still correct a leaderboard through its directors. An
 *   adapter may flag a mapped finish and hold it for an admin (review()).
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
        return $this->ingestEvents($server, $this->parse($payload));
    }

    /**
     * Stores events a server reported, read already (a listener on the
     * server's own protocol, plan "Trackmania und Restposten", P1): the same
     * checks, idempotency and mapping as ingest().
     *
     * @param  list<FinishEvent|string>  $events
     * @return array{accepted: int, duplicate: int, pending: int, refused: array<int, string>}
     */
    public function ingestEvents(ScoreServer $server, array $events): array
    {
        $game = app(GameRegistry::class)->find($server->game);
        $summary = ['accepted' => 0, 'duplicate' => 0, 'pending' => 0, 'refused' => []];

        foreach ($events as $index => $event) {
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
            // A mapped finish the adapter flags (a time no human drives) carries its hint; one it holds waits for an admin.
            $review = $userId === null ? null : $this->review($game, $event, $userId);

            try {
                ScoreRun::query()->create([
                    'tournament_id' => $review['hold']->id ?? null,
                    'user_id' => $userId,
                    'game' => $game->slug(),
                    'mode' => $event->mode,
                    'course' => $event->course,
                    'value' => $event->value,
                    'unit' => $game->metric($game->mode($event->mode) ?? throw new LogicException('The mode was checked above.'))->unit,
                    'source' => self::SOURCE,
                    'achieved_at' => $event->achievedAt,
                    'verified_at' => isset($review['hold']) ? null : now(),
                    'raw' => $review === null ? $event->raw : [...($event->raw ?? []), 'hint' => $review['hint']],
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

    /**
     * The adapter's look at a finish mapped to a player, before it is
     * stored: null when it counts as read; else its hint (kept in `raw`,
     * shown to admins only) and, when it must wait for an admin, the
     * leaderboard it is held for (the run gets that tournament and no
     * `verified_at`, so ScoreRun::pendingReview() lists it and the board does
     * not end before it is decided). None by default.
     *
     * @return array{hint: array<string, mixed>, hold: Tournament|null}|null
     */
    protected function review(ScoreGame $game, FinishEvent $event, int $userId): ?array
    {
        return null;
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
