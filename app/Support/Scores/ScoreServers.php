<?php

namespace App\Support\Scores;

use App\Games\GameRegistry;
use App\Games\ScoreGame;
use App\Models\ScoreServer;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The dedicated servers that report finishes (plan "AoE2 und Trackmania",
 * P4): a token per server, shown once when it is issued and stored only as
 * its SHA-256, and revoked by setting `revoked_at`.
 */
final class ScoreServers
{
    public const TOKEN_PREFIX = 'sst_';

    /**
     * @return array{server: ScoreServer, token: string} the token in plain text, this once
     */
    public static function issue(string $name, string $game, ?int $createdBy = null): array
    {
        $score = app(GameRegistry::class)->find($game);

        if (! $score instanceof ScoreGame || $score->serverIngest() === null) {
            throw new InvalidArgumentException("[{$game}] is no score game that takes server finishes.");
        }

        $token = self::TOKEN_PREFIX.Str::random(48);
        $server = ScoreServer::query()->create([
            'name' => mb_substr(trim($name), 0, 80),
            'game' => $game,
            'token_hash' => ScoreServer::hashToken($token),
            'created_by_id' => $createdBy,
        ]);

        return ['server' => $server, 'token' => $token];
    }

    public static function revoke(ScoreServer $server): void
    {
        if ($server->revoked_at === null) {
            $server->forceFill(['revoked_at' => now()])->save();
        }
    }

    /**
     * The server a bearer token belongs to, or null (unknown or revoked).
     */
    public static function authenticate(?string $token): ?ScoreServer
    {
        if ($token === null || ! str_starts_with($token, self::TOKEN_PREFIX)) {
            return null;
        }

        $server = ScoreServer::query()->where('token_hash', ScoreServer::hashToken($token))->first();

        return $server === null || $server->isRevoked() ? null : $server;
    }
}
