<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Membership;
use App\Support\Nostr\LoginChallenges;
use App\Support\Nostr\NostrKeys;
use App\Support\Nostr\NostrLogin;
use App\Support\Nostr\ProfileCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

use function Illuminate\Support\defer;

/**
 * Login with a Nostr key: the browser asks for a challenge, signs a kind-27235
 * event over it (via NIP-07, NIP-46 or Google through nostr-mill) and posts
 * it back. Plain JSON endpoints instead of a Livewire listener: no wire:poll,
 * no snapshot, so the session-id rotation on login cannot race another
 * Livewire round-trip (the 419s the portal had to work around).
 */
class NostrLoginController extends Controller
{
    public function challenge(Request $request, LoginChallenges $challenges): JsonResponse
    {
        return response()->json([
            'challenge' => $challenges->issue($request->session()),
            'url' => route('auth.nostr.login'),
            'method' => 'POST',
            'kind' => NostrLogin::KIND,
            'profile_relays' => config('esports.profile_relays'),
        ]);
    }

    public function login(Request $request, NostrLogin $nostrLogin, Membership $membership): JsonResponse
    {
        // Read the raw body: the global TrimStrings and ConvertEmptyStringsToNull
        // middleware rewrite parsed input, and a signed event must arrive
        // byte-for-byte (an empty content would become null, a trailing space
        // in a profile would vanish and break the signature).
        $payload = json_decode($request->getContent(), true);
        $payload = is_array($payload) ? $payload : [];

        $event = $nostrLogin->verify(
            $payload['event'] ?? null,
            $request->fullUrl(),
            $request->method(),
            $request->session(),
        );

        if ($event === null) {
            return response()->json(['message' => __('Login failed. Please try again.')], 401);
        }

        $user = User::query()->firstOrCreate(
            ['pubkey' => $event->pubkey],
            ['npub' => NostrKeys::hexToNpub($event->pubkey), 'locale' => app()->getLocale()],
        );

        ProfileCache::apply($user, $payload['profile'] ?? null);

        // After the response is sent: a slow or unreachable Verein API must
        // never delay the login. A failed check keeps the last known state.
        defer(fn () => $membership->refresh($user));

        Auth::login($user);
        $request->session()->regenerate();

        return response()->json([
            'redirect' => $request->session()->pull('url.intended', route('home')),
        ]);
    }
}
