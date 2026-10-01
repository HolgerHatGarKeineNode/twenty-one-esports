<?php

namespace App\Http\Controllers;

use App\Models\Tournament;
use App\Models\TournamentMatch;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The end-screen screenshot of a lobby report (P10,
 * App\Support\Tournaments\LobbyResults), for the tournament's directors and
 * admins only: the one waiting for confirmation, or the one a confirmed
 * result was decided on. The file lives on the private disk; there is no
 * public URL for it.
 */
class LobbyScreenshotController extends Controller
{
    public function __invoke(Tournament $tournament, TournamentMatch $match): StreamedResponse
    {
        abort_unless($match->tournament_id === $tournament->id && $match->lobby !== null, 404);
        abort_unless(Gate::allows('direct-tournament', $tournament), 403);

        $path = $match->lobby_report['screenshot'] ?? $match->result['reported']['screenshot'] ?? null;
        abort_unless(is_string($path) && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, 'lobby-'.$match->position.'.'.pathinfo($path, PATHINFO_EXTENSION), [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
