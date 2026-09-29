<?php

namespace App\Http\Controllers;

use App\Support\Nostr\Nip05Names;
use App\Support\Nostr\NostrKeys;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The NIP-05 document of the league's domain: the TWENTY ONE Esports identity
 * (`esports@esports.einundzwanzig.space`) and, since P47, every name a
 * player claimed in the settings ({@see Nip05Names}).
 *
 * One name per request (`?name=`), matched case-insensitively and answered
 * lowercased; no name, an unknown one or an invalid one answers with no
 * names (fail closed), so the document never lists every player. The
 * league's name answers only while `twentyone.nostr.npub` decodes to a
 * pubkey. `relays`: for the league its public relays; for a player the
 * league's relays, where it sends the player's signed league events (the
 * player's own NIP-65 relays are not stored). No session: the route runs
 * outside the web middleware group.
 */
class NostrJsonController extends Controller
{
    public function __invoke(Request $request, Nip05Names $names): JsonResponse
    {
        $name = $request->query('name');

        if (! is_string($name) || $name === '') {
            return $this->respond(['names' => (object) []]);
        }

        $name = strtolower($name);
        $npub = config('twentyone.nostr.npub');
        $league = is_string($npub) ? NostrKeys::npubToHex($npub) : null;
        $leagueName = Str::before((string) config('twentyone.profile.nip05'), '@');

        if ($leagueName !== '' && $name === $leagueName) {
            return $league === null
                ? $this->respond(['names' => (object) []])
                : $this->respond(['names' => [$leagueName => $league], 'relays' => [$league => config('twentyone.relays.public')]]);
        }

        $player = $names->lookup($name);

        if ($player === null || ! NostrKeys::isHexPubkey($player->pubkey)) {
            return $this->respond(['names' => (object) []]);
        }

        $relays = array_values(array_filter((array) config('esports.relays', []), fn (mixed $url): bool => is_string($url) && str_starts_with($url, 'wss://')));
        $document = ['names' => [(string) $player->nip05_name => $player->pubkey]];

        if ($relays !== []) {
            $document['relays'] = [$player->pubkey => $relays];
        }

        return $this->respond($document);
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
