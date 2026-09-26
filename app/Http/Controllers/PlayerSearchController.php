<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Nostr\NostrKeys;
use App\Support\Nostr\PlayerProfile;
use App\Support\Search\PlayerMatches;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Suggestions for the player picker (<x-player-picker>): existing players
 * only, found by display name, stored NIP-05, a full npub (or hex key) or an
 * npub prefix of at least NPUB_PREFIX characters after "npub1". Logged-in
 * only and throttled (routes/web.php); each row carries only what the public
 * player page shows anyway: name, picture and the shortened npub.
 *
 * With `keys=1` (<x-player-picker allow-npub>) each row also carries the hex
 * pubkey, and a fully valid npub or hex key that no player here has yet comes
 * back as one row marked `unregistered`: roles and invites that are granted
 * to a key before its owner ever logs in. A name never becomes a key.
 *
 * The matching itself is App\Support\Search\PlayerMatches, shared with the
 * site search (SearchController); here it is capped at LIMIT rows.
 */
class PlayerSearchController extends Controller
{
    public const LIMIT = 8;

    public const MIN_TERM = PlayerMatches::MIN_TERM;

    public const NPUB_PREFIX = PlayerMatches::NPUB_PREFIX;

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'keys' => ['nullable', 'boolean'],
            'exclude' => ['nullable', 'array', 'max:100'],
            'exclude.*' => ['integer'],
        ]);

        $keys = (bool) ($validated['keys'] ?? false);
        $term = PlayerMatches::normalize((string) ($validated['q'] ?? ''));
        $query = PlayerMatches::query($term);

        if ($query === null) {
            return response()->json([]);
        }

        $rows = $query
            ->whereKeyNot(array_map(intval(...), $validated['exclude'] ?? []))
            ->limit(self::LIMIT)
            ->get(['id', 'pubkey', 'npub', 'name', 'picture', 'avatar_path'])
            ->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->displayName(),
                'npub' => $user->shortNpub(),
                'avatar' => $user->avatarUrl() ?? PlayerProfile::generatedAvatarUrl($user->pubkey),
                'fallback' => PlayerProfile::generatedAvatarUrl($user->pubkey),
                ...($keys ? ['key' => $user->pubkey, 'unregistered' => false] : []),
            ])
            ->values()
            ->all();

        // `keys` mode (<x-player-picker allow-npub>): a valid key nobody here has yet is offered as itself.
        $pubkey = NostrKeys::toHex($term);

        if ($keys && $pubkey !== null && $rows === [] && ! User::query()->where('pubkey', $pubkey)->exists()) {
            $rows = [[
                'id' => null,
                'name' => 'npub1…'.Str::substr(NostrKeys::hexToNpub($pubkey), -4),
                'npub' => '',
                'avatar' => PlayerProfile::generatedAvatarUrl($pubkey),
                'fallback' => PlayerProfile::generatedAvatarUrl($pubkey),
                'key' => $pubkey,
                'unregistered' => true,
            ]];
        }

        return response()->json($rows);
    }
}
