<?php

namespace App\Support\TwentyOne\Stream;

use App\Models\Clan;
use App\Models\User;
use App\Support\Clans\ClanLogos;
use App\Support\Nostr\Blockpile;
use Closure;
use Throwable;

/**
 * The pictures of the stream scenes as data URIs, read from local files
 * only: the avatars, backdrops, cover tiles and 128 px clan logos StreamImageBuilder wrote
 * (`twentyone.stream.images.dir`; a logo only from a clan's own redrawn
 * ClanLogos file, never a foreign picture). Nothing here touches the network or the database; a caller
 * hands in the users and clans it already loaded, or the plain refs
 * (avatarRef(), logoRef()) its cached snapshot kept.
 *
 * A player without a cached picture gets their Blockpile as an SVG data URI,
 * so every name on a scene has a face. The data URIs stay in memory, at
 * most `memory_entries` of them for `memory_seconds` each (a bound map,
 * oldest first out), so a frame costs no file read and a refreshed file
 * shows up after the TTL. Bound as a singleton: the daemon keeps one.
 */
class StreamImages
{
    /** Backdrop slug of the brand cover (public/images/twentyone/cover.png). */
    public const BRAND = 'brand';

    /** Backdrop slug of the match, gallery and fallback scenes. */
    public const CHESS = 'chess';

    /** @var array<string, array{0: string|null, 1: int}> key => [data URI or null, expires at] */
    private array $memory = [];

    /**
     * The directory the builder writes to and the daemon reads from.
     */
    public static function dir(): string
    {
        return rtrim((string) config('twentyone.stream.images.dir'), '/');
    }

    /**
     * Where a player's picture comes from: our upload (`public:<path>`) wins
     * over the kind-0 picture (https only), as User::avatarUrl() has it;
     * null without either.
     */
    public static function source(User $user): ?string
    {
        if (is_string($user->avatar_path) && $user->avatar_path !== '') {
            return 'public:'.$user->avatar_path;
        }

        return is_string($user->picture) && str_starts_with($user->picture, 'https://') ? $user->picture : null;
    }

    /**
     * The cached JPEG of one picture: a new source is a new file.
     */
    public static function avatarFile(int $userId, string $source): string
    {
        return self::dir().'/avatars/'.sha1($userId.'|'.$source).'.jpg';
    }

    /**
     * The small copy of a redrawn clan logo (`clan-logos/<sha256>.png` on the public disk).
     */
    public static function logoFile(string $ref): string
    {
        return self::dir().'/logos/'.basename($ref);
    }

    public static function backdropFile(string $slug): string
    {
        return self::dir().'/backdrops/'.$slug.'.jpg';
    }

    /** A game cover at the size of d2's tile (StreamImageBuilder::coverTileJpeg). */
    public static function coverTileFile(string $slug): string
    {
        return self::dir().'/tiles/'.$slug.'.jpg';
    }

    /**
     * What avatar() needs of a player, as plain scalars a cache can hold.
     *
     * @return array{id: int, pubkey: string, source: string|null}|null
     */
    public static function avatarRef(?User $user): ?array
    {
        return $user === null ? null : ['id' => (int) $user->id, 'pubkey' => (string) $user->pubkey, 'source' => self::source($user)];
    }

    /**
     * The public-disk path of a clan's redrawn logo; null for a foreign
     * `picture` (never rendered) or no logo.
     */
    public static function logoRef(?Clan $clan): ?string
    {
        return $clan === null ? null : app(ClanLogos::class)->pathOf($clan->picture);
    }

    /**
     * The player's cached picture as a JPEG data URI, else their Blockpile
     * as an SVG data URI; null only without a player (an open spot).
     *
     * @param  array{id: int, pubkey: string, source: string|null}|null  $ref
     */
    public function avatar(?array $ref): ?string
    {
        if ($ref === null) {
            return null;
        }

        if ($ref['source'] !== null) {
            $path = self::avatarFile($ref['id'], $ref['source']);
            $jpeg = $this->remember('file:'.$path, fn (): ?string => self::fileUri($path, 'image/jpeg'));

            if ($jpeg !== null) {
                return $jpeg;
            }
        }

        return $this->remember('blockpile:'.$ref['pubkey'], function () use ($ref): ?string {
            try {
                return 'data:image/svg+xml;base64,'.base64_encode(Blockpile::svg($ref['pubkey']));
            } catch (Throwable) {
                return null;
            }
        });
    }

    public function forUser(?User $user): ?string
    {
        return $this->avatar(self::avatarRef($user));
    }

    /**
     * A clan's logo as its 128 px PNG copy (StreamImageBuilder), or null
     * while there is none: the redrawn original (~100 KB) is too heavy for a
     * frame and is never embedded.
     */
    public function logo(?string $ref): ?string
    {
        if ($ref === null) {
            return null;
        }

        $path = self::logoFile($ref);

        return $this->remember('file:'.$path, fn (): ?string => self::fileUri($path, 'image/png'));
    }

    public function forClan(?Clan $clan): ?string
    {
        return $this->logo(self::logoRef($clan));
    }

    /**
     * A precomputed backdrop (StreamImageBuilder) as a JPEG data URI, or null
     * while it has not been built.
     */
    public function backdrop(string $slug): ?string
    {
        $path = self::backdropFile($slug);

        return $this->remember('file:'.$path, fn (): ?string => self::fileUri($path, 'image/jpeg'));
    }

    /**
     * A game's cover tile (StreamImageBuilder) as a JPEG data URI, or null
     * while it has not been built.
     */
    public function coverTile(string $slug): ?string
    {
        $path = self::coverTileFile($slug);

        return $this->remember('file:'.$path, fn (): ?string => self::fileUri($path, 'image/jpeg'));
    }

    /**
     * Entries held in memory now (for the bound's test and the log).
     */
    public function held(): int
    {
        return count($this->memory);
    }

    /**
     * The value under `$key`, read again once its TTL passed; a miss is kept
     * too (a file that is not there yet is not looked for every frame). Past
     * the bound the oldest entry goes.
     *
     * @param  Closure(): (string|null)  $read
     */
    private function remember(string $key, Closure $read): ?string
    {
        $now = now()->getTimestamp();

        if (isset($this->memory[$key]) && $this->memory[$key][1] > $now) {
            return $this->memory[$key][0];
        }

        unset($this->memory[$key]);
        $value = $read();
        $this->memory[$key] = [$value, $now + max(1, (int) config('twentyone.stream.images.memory_seconds', 600))];
        $max = max(1, (int) config('twentyone.stream.images.memory_entries', 500));

        while (count($this->memory) > $max) {
            unset($this->memory[array_key_first($this->memory)]);
        }

        return $value;
    }

    /**
     * A file as a data URI; null when it is missing, empty or unreadable.
     */
    private static function fileUri(string $path, string $mime): ?string
    {
        if (! is_file($path)) {
            return null;
        }

        try {
            $bytes = file_get_contents($path);
        } catch (Throwable) {
            return null;
        }

        return $bytes === false || $bytes === '' ? null : 'data:'.$mime.';base64,'.base64_encode($bytes);
    }
}
