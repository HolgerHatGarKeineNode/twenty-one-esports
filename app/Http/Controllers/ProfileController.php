<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Nostr\ProfileCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Takes the kind-0 profiles a page's browser read from the profile relays
 * (resources/js/profiles.js) and caches those that are signed, known and new.
 *
 * Open to guests: the events authenticate themselves, and the route is
 * throttled per client. Refused events are dropped without a word, so a
 * page never shows an error for a profile that did not load.
 */
class ProfileController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        // Raw body: TrimStrings and ConvertEmptyStringsToNull would rewrite
        // the event content and break its signature (see NostrLoginController).
        $payload = json_decode($request->getContent(), true);
        $events = is_array($payload) ? ($payload['events'] ?? null) : null;

        if (! is_array($events) || ! array_is_list($events) || count($events) > ProfileCache::MAX_BATCH) {
            return response()->json(['message' => 'Send a list of at most '.ProfileCache::MAX_BATCH.' signed kind-0 events.'], 422);
        }

        $updated = ProfileCache::acceptBatch($events);

        return response()->json([
            'updated' => $updated->mapWithKeys(fn (User $user): array => [$user->pubkey => [
                'name' => $user->displayName(),
                'avatar' => $user->avatarUrl(),
            ]])->all(),
        ]);
    }
}
