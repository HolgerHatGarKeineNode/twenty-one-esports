<?php

namespace App\Http\Controllers;

use App\Games\GameRegistry;
use App\Games\ScoreGame;
use App\Support\Scores\ScoreServers;
use App\Support\Scores\ServerIngest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Finishes from our own dedicated servers (plan "AoE2 und Trackmania", P4):
 * `POST /api/scores/ingest` with the server's token as a bearer token and the
 * payload of its game's adapter (App\Support\Scores\ServerIngest). Answers
 * what was stored, what was a duplicate, what waits for a player to store
 * the account id, and which entries were refused (by index, with a code).
 * Routed only while a score game is registered (routes/score.php).
 */
final class ScoreIngestController
{
    public function __invoke(Request $request): JsonResponse
    {
        $server = ScoreServers::authenticate($request->bearerToken());

        if ($server === null) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $game = app(GameRegistry::class)->find($server->game);
        $adapter = $game instanceof ScoreGame ? $game->serverIngest() : null;

        if ($adapter === null) {
            return response()->json(['error' => 'game'], 422);
        }

        $ingest = app($adapter);

        if (! $ingest instanceof ServerIngest) {
            return response()->json(['error' => 'payload'], 422);
        }

        return response()->json($ingest->ingest($server, $request->json()->all()));
    }
}
