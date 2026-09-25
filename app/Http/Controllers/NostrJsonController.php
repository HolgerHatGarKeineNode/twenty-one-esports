<?php

namespace App\Http\Controllers;

use App\Support\Nostr\NostrKeys;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * NIP-05 document for the TWENTY ONE Esports identity
 * (`esports@esports.einundzwanzig.space`).
 *
 * Only the local part of `twentyone.profile.nip05` is known, and only while
 * `twentyone.nostr.npub` decodes to a pubkey; anything else answers with no
 * names (fail closed). No database, no session: the route runs outside the
 * web middleware group.
 */
class NostrJsonController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $name = $request->query('name');
        $npub = config('twentyone.nostr.npub');
        $pubkey = is_string($npub) ? NostrKeys::npubToHex($npub) : null;
        $knownName = Str::before((string) config('twentyone.profile.nip05'), '@');

        if ($pubkey === null || ! is_string($name) || $knownName === '' || strtolower($name) !== $knownName) {
            return $this->respond(['names' => (object) []]);
        }

        return $this->respond([
            'names' => [$knownName => $pubkey],
            'relays' => [$pubkey => config('twentyone.relays.public')],
        ]);
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function respond(array $document): JsonResponse
    {
        return response()
            ->json($document, options: JSON_UNESCAPED_SLASHES)
            ->header('Access-Control-Allow-Origin', '*');
    }
}
