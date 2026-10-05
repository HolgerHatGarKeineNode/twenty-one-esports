<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Moderation\SiteModeration;
use App\Support\Moderation\SiteModerationRefused;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The moderation menu on a chat message (components/chat-moderation,
 * resources/js/siteModeration.js): an admin mutes the author for everyone
 * or bans them from the site, with a reason. Admins only (routes/admin.php,
 * middleware `admin`); the page itself hides the key at once and every other
 * open chat hears of it by push (SiteModerationChanged).
 */
final class SiteModerationController extends Controller
{
    public function __invoke(Request $request, SiteModeration $moderation): JsonResponse
    {
        $validated = $request->validate([
            'pubkey' => ['required', 'string', 'max:100'],
            'action' => ['required', 'string', 'in:mute,ban'],
            'reason' => ['required', 'string', 'max:500'],
        ]);
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        try {
            $row = $validated['action'] === 'ban'
                ? $moderation->ban($actor, $validated['pubkey'], $validated['reason'])
                : $moderation->mute($actor, $validated['pubkey'], $validated['reason']);
        } catch (SiteModerationRefused $refused) {
            return response()->json(['message' => $refused->getMessage(), 'reason' => $refused->reason], 422);
        }

        return response()->json(['pubkey' => $row->pubkey, 'hidden' => true]);
    }
}
