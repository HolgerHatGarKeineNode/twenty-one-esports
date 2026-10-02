<?php

namespace App\Support\Tmnf;

use App\Games\GameRegistry;
use App\Games\TrackmaniaNationsForever;
use App\Models\ScoreAccountClaim;
use App\Models\User;
use App\Support\Scores\ScoreAccounts;
use App\Support\Tournaments\TournamentRuleViolation;
use Illuminate\Support\Facades\Cache;

/**
 * Linking a player's TMNF login to their league account (plan "Trackmania und
 * Restposten", P1), privately:
 *
 * 1. The player stores their login in the private gamer tags (settings,
 *    `tmnf`), like the EA ID.
 * 2. The settings page shows a one-time code (codeFor()), valid for
 *    `esports.tmnf.link.code_minutes`, one per player at a time.
 * 3. The player types `link <code>` into the chat of our server; the
 *    listener hands every chat line here (fromChat()). The code links only
 *    when the login that typed it is the login the player stored (letter
 *    case aside): the server says who typed it, so the login proves itself.
 *
 * The link is a ScoreAccountClaim without an admin (ScoreAccounts::confirmByProof()),
 * so finishes of that login map to the player; pending ones are handed over.
 * A login confirmed for somebody else already stays theirs: an admin
 * reassigns it on /admin/scores. Nothing here is ever shown in public: the
 * login stays on the settings page and in the admin review.
 *
 * Codes live in the cache (shared by the web app and the listener), never in
 * a page that anybody but the player sees.
 */
final class TmnfLinks
{
    /** Letters and digits nobody confuses (no 0/O, 1/I/L). */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public const CODE_LENGTH = 6;

    /** What a linking chat line looks like: `link K7QX9P`, a leading slash allowed. */
    public const CHAT_PATTERN = '/^\s*\/?link\s+([A-Za-z0-9]{6})\s*$/i';

    /**
     * The player's current code, issued now when there is none; null while
     * TMNF is off, no login is stored, or the stored login is linked to them already.
     */
    public static function codeFor(User $user): ?string
    {
        $login = self::storedLogin($user);

        if (self::game() === null || $login === null || self::isLinked($user)) {
            return null;
        }

        $current = Cache::get(self::userKey($user->id));

        $pending = is_string($current) ? self::pending($current) : [];

        // The same code while it runs, unless the player stored another login since.
        if (is_string($current) && ($pending['user'] ?? null) === $user->id && ($pending['login'] ?? null) === $login) {
            return $current;
        }

        $code = self::newCode();
        $ttl = now()->addMinutes(max(1, (int) config('esports.tmnf.link.code_minutes', 30)));
        Cache::put(self::codeKey($code), ['user' => $user->id, 'login' => $login], $ttl);
        Cache::put(self::userKey($user->id), $code, $ttl);

        return $code;
    }

    /**
     * The login linked to this player, if any (for their own settings page only).
     */
    public static function linkedLogin(User $user): ?string
    {
        $login = self::storedLogin($user);

        if ($login === null) {
            return null;
        }

        $claim = ScoreAccountClaim::query()->where(['game' => TrackmaniaNationsForever::SLUG, 'user_id' => $user->id])->get()
            ->first(fn (ScoreAccountClaim $claim): bool => mb_strtolower($claim->account_id) === mb_strtolower($login));

        return $claim?->account_id;
    }

    public static function isLinked(User $user): bool
    {
        return self::linkedLogin($user) !== null;
    }

    /**
     * A chat line from our server. Returns what happened: null for a line
     * that is no link command (ordinary chat), else `linked`, `unknown` (no
     * such code, or it ran out), `login` (another login than the one the
     * code's player stored), `taken` (the login is linked to somebody else)
     * or `off` (TMNF switched off).
     */
    public static function fromChat(string $login, string $text): ?string
    {
        if (preg_match(self::CHAT_PATTERN, $text, $match) !== 1) {
            return null;
        }

        $game = self::game();

        if ($game === null) {
            return 'off';
        }

        $code = strtoupper($match[1]);
        $pending = self::pending($code);
        $user = isset($pending['user']) ? User::query()->find($pending['user']) : null;
        $stored = $user === null ? null : self::storedLogin($user);

        if ($user === null || $stored === null) {
            return 'unknown';
        }

        // The login that typed the code must be the one the player stored, and still is.
        if (mb_strtolower(trim($login)) !== mb_strtolower($stored) || mb_strtolower((string) ($pending['login'] ?? '')) !== mb_strtolower($stored)) {
            return 'login';
        }

        // Stored with the server's spelling, so the server's finishes of this login map exactly.
        if ($stored !== $login) {
            $user->forceFill(['gamer_tags' => [...($user->gamer_tags ?? []), TrackmaniaNationsForever::SERVICE => $login]])->save();
            ScoreAccounts::recordStored($user);
        }

        try {
            ScoreAccounts::confirmByProof($game, $login, $user, 'linked with a one-time code typed into the league server chat');
        } catch (TournamentRuleViolation) {
            return self::isLinked($user->refresh()) ? 'linked' : 'taken';
        }

        Cache::forget(self::codeKey($code));
        Cache::forget(self::userKey($user->id));

        return 'linked';
    }

    private static function storedLogin(User $user): ?string
    {
        $login = trim((string) ($user->gamer_tags[TrackmaniaNationsForever::SERVICE] ?? ''));

        return $login === '' ? null : $login;
    }

    /**
     * @return array{user?: int, login?: string}
     */
    private static function pending(string $code): array
    {
        $pending = Cache::get(self::codeKey($code));

        return is_array($pending) ? $pending : [];
    }

    private static function newCode(): string
    {
        do {
            $code = '';

            for ($i = 0; $i < self::CODE_LENGTH; $i++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
        } while (Cache::has(self::codeKey($code)));

        return $code;
    }

    private static function codeKey(string $code): string
    {
        return 'tmnf-link:code:'.$code;
    }

    private static function userKey(int $userId): string
    {
        return 'tmnf-link:user:'.$userId;
    }

    private static function game(): ?TrackmaniaNationsForever
    {
        $game = app(GameRegistry::class)->find(TrackmaniaNationsForever::SLUG);

        return $game instanceof TrackmaniaNationsForever ? $game : null;
    }
}
