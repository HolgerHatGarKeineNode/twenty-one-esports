<?php

namespace App\Models;

use App\Enums\InviteLinkType;
use Database\Factories\InviteLinkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A shareable invite `/i/{code}` (P6b): a game challenge anyone may take, or
 * a clan link that sends a join request. League data only.
 *
 * @property int $id
 * @property string $code
 * @property InviteLinkType $type
 * @property int $inviter_id
 * @property int|null $clan_id
 * @property array<string, mixed>|null $options
 * @property int|null $max_uses 1 = one-time, null = several times
 * @property int $uses
 * @property Carbon $expires_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $inviter
 * @property-read Clan|null $clan
 */
#[Fillable(['code', 'type', 'inviter_id', 'clan_id', 'options', 'max_uses', 'uses', 'expires_at', 'revoked_at'])]
class InviteLink extends Model
{
    /** @use HasFactory<InviteLinkFactory> */
    use HasFactory;

    /**
     * 22 characters of base62 (Str::random draws from random_bytes): about
     * 131 bits, far beyond guessing even without the rate limit.
     */
    public const CODE_LENGTH = 22;

    public const CODE_PATTERN = '[A-Za-z0-9]{22}';

    protected function casts(): array
    {
        return [
            'type' => InviteLinkType::class,
            'options' => 'array',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public static function newCode(): string
    {
        return Str::random(self::CODE_LENGTH);
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inviter_id');
    }

    /**
     * @return BelongsTo<Clan, $this>
     */
    public function clan(): BelongsTo
    {
        return $this->belongsTo(Clan::class);
    }

    /**
     * @return HasMany<InviteLinkUse, $this>
     */
    public function linkUses(): HasMany
    {
        return $this->hasMany(InviteLinkUse::class);
    }

    public function option(string $key, mixed $default = null): mixed
    {
        return $this->options[$key] ?? $default;
    }

    public function isExpired(): bool
    {
        return ! $this->expires_at->isFuture();
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isUsedUp(): bool
    {
        return $this->max_uses !== null && $this->uses >= $this->max_uses;
    }

    public function isOneTime(): bool
    {
        return $this->max_uses === 1;
    }

    public function url(): string
    {
        return route('invites.link', $this);
    }
}
