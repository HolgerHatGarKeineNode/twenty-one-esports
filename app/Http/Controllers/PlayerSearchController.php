<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Nostr\NostrKeys;
use App\Support\Nostr\PlayerProfile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Suggestions for the player picker (<x-player-picker>): existing players
 * only, found by display name, stored NIP-05, a full npub (or hex key) or an
 * npub prefix of at least NPUB_PREFIX characters after "npub1". Logged-in
 * only and throttled (routes/web.php); each row carries only what the public
 * player page shows anyway: name, picture and the shortened npub.
 *
 * Cost: a full key is a unique-index lookup and an npub prefix a range on
 * the unique npub index. A name or NIP-05 is a LIKE over the players, capped
 * at LIMIT rows, and needs MIN_TERM characters.
 */
class PlayerSearchController extends Controller
{
    public const LIMIT = 8;

    public const MIN_TERM = 2;

    public const NPUB_PREFIX = 8;

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'exclude' => ['nullable', 'array', 'max:100'],
            'exclude.*' => ['integer'],
        ]);

        $term = trim((string) ($validated['q'] ?? ''));
        $query = $this->matching($term);

        if ($query === null) {
            return response()->json([]);
        }

        $players = $query
            ->whereKeyNot(array_map(intval(...), $validated['exclude'] ?? []))
            ->limit(self::LIMIT)
            ->get(['id', 'pubkey', 'npub', 'name', 'picture', 'avatar_path']);

        return response()->json($players->map(fn (User $user): array => [
            'id' => $user->id,
            'name' => $user->displayName(),
            'npub' => $user->shortNpub(),
            'avatar' => $user->avatarUrl() ?? PlayerProfile::generatedAvatarUrl($user->pubkey),
            'fallback' => PlayerProfile::generatedAvatarUrl($user->pubkey),
        ])->values());
    }

    /**
     * The players the term can mean, or null when it is too short to search.
     *
     * @return Builder<User>|null
     */
    private function matching(string $term): ?Builder
    {
        $pubkey = NostrKeys::toHex($term);

        if ($pubkey !== null) {
            return User::query()->where('pubkey', $pubkey);
        }

        $lower = mb_strtolower($term);

        if (str_starts_with($lower, 'npub1')) {
            // bech32 only: '~' sorts after every bech32 character, so the range is exactly the prefix.
            return strlen($lower) - 5 >= self::NPUB_PREFIX && preg_match('/^npub1[02-9ac-hj-np-z]+$/', $lower) === 1
                ? User::query()->where('npub', '>=', $lower)->where('npub', '<', $lower.'~')->orderBy('npub')
                : null;
        }

        if (mb_strlen($term) < self::MIN_TERM) {
            return null;
        }

        $like = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $lower);

        return User::query()
            ->where(fn (Builder $query) => $query
                ->whereRaw("lower(name) like ? escape '!'", ['%'.$like.'%'])
                ->orWhereRaw("nip05 like ? escape '!'", [$like.'%']))
            ->orderByRaw("case when lower(name) like ? escape '!' then 0 else 1 end", [$like.'%'])
            ->orderBy('name');
    }
}
