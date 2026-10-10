<?php

namespace App\Http\Controllers;

use App\Enums\TournamentStatus;
use App\Models\Tournament;
use App\Support\Broadcast\OverlaySnapshot;
use App\Support\Tournaments\TournamentTv;
use App\Support\TwentyOne\Stream\PublicName;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * The bracket of a tournament as data for the tournament page's 3D view (plan "OBS-Broadcast-Overlays", P6,
 * resources/js/broadcast/bracket/page.js): the stages as the TV reads them (TournamentTv::stages(): every match with
 * the matches that feed it, seeds, scores, who won), cleaned like an overlay snapshot (OverlaySnapshot::clean(): no
 * keys, no accounts, no objects), plus the champion. Fetched only once a viewer picks 3D, and again on a push on
 * `tournament.{id}`; cached per version of the bracket (TournamentTv::version()) and language.
 *
 * Only what the page shows: a published tournament that is running or finished; anything else answers 404.
 */
class TournamentBracketController extends Controller
{
    public function __invoke(Tournament $tournament): JsonResponse
    {
        abort_if($tournament->published_at === null || $tournament->isLeagueWeek() || ! in_array($tournament->status, [TournamentStatus::Running, TournamentStatus::Finished], true), 404);

        $tv = new TournamentTv($tournament);
        $data = Cache::remember('bracket3d.'.$tournament->id.'.'.$tv->version().'.'.app()->getLocale(), 300, function () use ($tournament, $tv): array {
            $champion = $tournament->status === TournamentStatus::Finished ? $tv->champion() : null;

            return [
                'name' => PublicName::clean($tournament->name),
                'status' => $tournament->status->value,
                'champion' => $champion === null ? null : PublicName::clean((string) $champion['name']),
                'stages' => OverlaySnapshot::clean($tv->stages()),
            ];
        });

        return response()->json($data)->header('Cache-Control', 'no-store, private');
    }
}
