<?php

namespace App\Models;

use App\Enums\OverlayVariant;
use Database\Factories\OverlayPresetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * An OBS overlay preset (plan "OBS-Broadcast-Overlays", P2): what one browser source shows, set up once by an admin
 * (pages::admin.overlays) and then running on its own. Its URL `/broadcast/{token}` is the only key: the token is
 * shown once, when the preset is created or rotated, and only its SHA-256 is stored, so a leaked database gives no
 * overlay away. Rotating issues a new token; the old URL answers 404 from then on.
 *
 * @property int $id
 * @property string $name
 * @property OverlayVariant $variant
 * @property int|null $tournament_id the tournament of a tournament or bracket overlay
 * @property string $locale de|en
 * @property array<string, bool> $modules module => on, see MODULES
 * @property string $token_hash
 * @property Carbon|null $rotated_at
 * @property int|null $created_by_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tournament|null $tournament
 * @property-read User|null $creator
 */
#[Fillable(['name', 'variant', 'tournament_id', 'locale', 'modules', 'token_hash', 'rotated_at', 'created_by_id'])]
#[Hidden(['token_hash'])]
class OverlayPreset extends Model
{
    /** @use HasFactory<OverlayPresetFactory> */
    use HasFactory;

    /** The languages an overlay speaks. */
    public const LOCALES = ['de', 'en'];

    /** Every module an overlay can switch on or off, with its default. */
    public const MODULES = [
        'ticker' => true,
        'pride' => true,
        'ads' => true,
        'stats' => true,
        'qr' => true,
        'pots' => true,
        'sound' => true,
        'cam-frame' => false,
    ];

    /** The characters of a token after its prefix; the route accepts exactly this shape. */
    public const TOKEN_LENGTH = 48;

    public const TOKEN_PATTERN = '[A-Za-z0-9]{48}';

    protected function casts(): array
    {
        return ['variant' => OverlayVariant::class, 'modules' => 'array', 'rotated_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Tournament, $this>
     */
    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function newToken(): string
    {
        return Str::random(self::TOKEN_LENGTH);
    }

    /** The preset behind a URL's token; null for a wrong or rotated one. */
    public static function findByToken(string $token): ?self
    {
        return preg_match('/^'.self::TOKEN_PATTERN.'$/', $token) === 1
            ? self::query()->where('token_hash', self::hashToken($token))->first()
            : null;
    }

    /** A new token for this preset: the old URL stops working. Returns the token, the only time it is readable. */
    public function rotate(): string
    {
        $token = self::newToken();
        $this->forceFill(['token_hash' => self::hashToken($token), 'rotated_at' => now()])->save();

        return $token;
    }

    /** Whether a module is on; a module the preset predates takes its default. */
    public function hasModule(string $module): bool
    {
        return (bool) ($this->modules[$module] ?? self::MODULES[$module] ?? false);
    }

    /**
     * Every module with its state, defaults filled in.
     *
     * @return array<string, bool>
     */
    public function moduleStates(): array
    {
        return array_map(fn (string $module): bool => $this->hasModule($module), array_combine(array_keys(self::MODULES), array_keys(self::MODULES)));
    }

    public static function url(string $token): string
    {
        return route('broadcast.overlay', ['token' => $token]);
    }
}
