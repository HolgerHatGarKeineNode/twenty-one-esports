<?php

namespace App\Models;

use App\Enums\Platform;
use App\Support\Board;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A player, identified by a Nostr key. There is no email and no password:
 * the only way in is a signed login event (see App\Support\Nostr\NostrLogin).
 *
 * @property int $id
 * @property string $pubkey
 * @property string $npub
 * @property string|null $name
 * @property string|null $picture
 * @property Carbon|null $profile_event_at
 * @property string|null $locale
 * @property bool $is_member
 * @property Carbon|null $member_checked_at
 * @property string|null $avatar_path
 * @property Platform|null $platform
 * @property array<string, string>|null $gamer_tags
 * @property string|null $timezone
 * @property string|null $looking_to_play `<game>/<mode>` the player is up for, null = not looking
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ClanMember|null $clanMember
 */
#[Fillable(['pubkey', 'npub', 'locale', 'avatar_path', 'platform', 'gamer_tags', 'timezone', 'looking_to_play'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * No remember-me: the users table has no remember_token column.
     *
     * @var string
     */
    protected $rememberTokenName = '';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'profile_event_at' => 'datetime',
            'is_member' => 'boolean',
            'member_checked_at' => 'datetime',
            'platform' => Platform::class,
            'gamer_tags' => 'array',
        ];
    }

    /**
     * The player's clan membership; one player, one clan (unique user_id).
     *
     * @return HasOne<ClanMember, $this>
     */
    public function clanMember(): HasOne
    {
        return $this->hasOne(ClanMember::class);
    }

    /**
     * Board npub from config, or granted in the admin UI.
     */
    public function isAdmin(): bool
    {
        return Board::contains($this->pubkey)
            || Admin::query()->where('pubkey', $this->pubkey)->exists();
    }

    /**
     * The kind-0 name, or a shortened npub when the profile is unknown.
     */
    public function displayName(): string
    {
        return filled($this->name) ? (string) $this->name : Str::limit($this->npub, 12, '…');
    }

    /**
     * The npub as the designs shorten it: `npub1…k7q2`.
     */
    public function shortNpub(): string
    {
        return 'npub1…'.Str::substr($this->npub, -4);
    }

    /**
     * Our own uploaded avatar wins over the kind-0 picture.
     */
    public function avatarUrl(): ?string
    {
        if ($this->avatar_path !== null) {
            return Storage::disk('public')->url($this->avatar_path);
        }

        return $this->picture;
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->displayName(), true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }
}
