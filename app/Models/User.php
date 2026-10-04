<?php

namespace App\Models;

use App\Enums\Platform;
use App\Support\Board;
use App\Support\Chess\ChessSettings;
use App\Support\Engagement\HomeHub;
use App\Support\Nostr\Nip05Names;
use App\Support\Nostr\PlayerProfile;
use App\Support\Scores\ScoreAccounts;
use App\Support\Stacker\StackerSettings;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
 * @property string|null $about
 * @property string|null $banner https only
 * @property string|null $website https only
 * @property string|null $lud16 Lightning address of the cached profile; payouts pay the copy an admin approved, never this field directly
 * @property Carbon|null $lud16_changed_at when $lud16 last changed after the first cached profile (season payout freeze, P37)
 * @property string|null $nip05 as the profile names it, lowercased
 * @property Carbon|null $nip05_verified_at set when the domain's nostr.json named this key
 * @property Carbon|null $nip05_checked_at last NIP-05 check, successful or not
 * @property string|null $nip05_name the player's own name on the league's domain (P47, {@see Nip05Names}), opt-in
 * @property Carbon|null $nip05_changed_at last claim, change or release of that name (change limit)
 * @property Carbon|null $nip05_revoked_at when an admin took the name back
 * @property string|null $nip05_revoked_name the name an admin took back; nobody claims it while this account exists
 * @property Carbon|null $profile_event_at
 * @property Carbon|null $profile_checked_at last time a browser handed in this profile, new or unchanged
 * @property string|null $locale
 * @property bool $is_member
 * @property Carbon|null $member_checked_at
 * @property string|null $avatar_path
 * @property Platform|null $platform
 * @property array<string, string>|null $gamer_tags
 * @property string|null $timezone
 * @property string|null $looking_to_play `<game>/<mode>` the player is up for, null = not looking
 * @property Carbon|null $notify_block0_at when the player asked to be told about Block 0, null = not asked
 * @property Carbon|null $block0_notified_at when the player was told that Block 0 is released
 * @property Carbon|null $block0_heads_up_for the planned Block 0 date the player was last told about
 * @property array<string, mixed>|null $chess_settings see {@see ChessSettings}; null = all defaults
 * @property array<string, array{platform: string, crossplay: bool}>|null $casual_settings last casual 1v1 choice per game (App\Support\Series\CasualLobby)
 * @property array<string, mixed>|null $stacker_settings Blockfill handling and keys, see {@see StackerSettings}; null = defaults
 * @property array<string, mixed>|null $stacker_sound Blockfill effects and music, see {@see StackerSettings::sound()}; null = defaults
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ClanMember|null $clanMember
 * @property-read Collection<int, PushSubscription> $pushSubscriptions
 */
#[Fillable(['pubkey', 'npub', 'locale', 'avatar_path', 'platform', 'gamer_tags', 'timezone', 'looking_to_play', 'chess_settings'])]
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
     * A deleted account's NIP-05 name is held for the change period (P47
     * security audit F2): the key may take it back after logging in again,
     * nobody else meanwhile.
     */
    protected static function booted(): void
    {
        static::deleting(function (User $user): void {
            app(Nip05Names::class)->releaseForDeletion($user);
        });

        // When a score game's account id was stored, on every write path: a leaderboard waits only for ids stored
        // before its window closed (plan "AoE2 und Trackmania", P4, round-3 S1).
        static::saved(function (User $user): void {
            if ($user->wasRecentlyCreated || $user->wasChanged('gamer_tags')) {
                ScoreAccounts::recordStored($user);
            }
        });

        // Home's newcomers (HomeHub, cached).
        static::created(fn () => HomeHub::forgetNewcomers());
        static::deleted(fn () => HomeHub::forgetNewcomers());
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'profile_event_at' => 'datetime',
            'lud16_changed_at' => 'datetime',
            'profile_checked_at' => 'datetime',
            'nip05_verified_at' => 'datetime',
            'nip05_checked_at' => 'datetime',
            'nip05_changed_at' => 'datetime',
            'nip05_revoked_at' => 'datetime',
            'is_member' => 'boolean',
            'member_checked_at' => 'datetime',
            'platform' => Platform::class,
            'gamer_tags' => 'array',
            'notify_block0_at' => 'datetime',
            'block0_notified_at' => 'datetime',
            'block0_heads_up_for' => 'datetime',
            'chess_settings' => 'array',
            'casual_settings' => 'array',
            'stacker_settings' => 'array',
            'stacker_sound' => 'array',
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
     * @return HasMany<ChessGame, $this>
     */
    public function whiteGames(): HasMany
    {
        return $this->hasMany(ChessGame::class, 'white_id');
    }

    /**
     * @return HasMany<ChessGame, $this>
     */
    public function blackGames(): HasMany
    {
        return $this->hasMany(ChessGame::class, 'black_id');
    }

    /**
     * @return HasMany<PushSubscription, $this>
     */
    public function pushSubscriptions(): HasMany
    {
        return $this->hasMany(PushSubscription::class);
    }

    /**
     * Chess and notification preferences (ChessSettings), defaults filled in.
     */
    public function chessSettings(): ChessSettings
    {
        return ChessSettings::fromArray($this->chess_settings ?? []);
    }

    /**
     * Pubkeys this player muted in game chats.
     *
     * @return list<string>
     */
    public function mutedPubkeys(): array
    {
        return array_values(ChatMute::query()->where('user_id', $this->id)->orderBy('id')->pluck('muted_pubkey')->all());
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
     * Unlocked by an admin to create tournaments (P8). Admins create them anyway.
     */
    public function isTournamentOrganizer(): bool
    {
        return TournamentOrganizer::query()->where('pubkey', $this->pubkey)->exists();
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
     * Our own uploaded avatar wins over the kind-0 picture, which is only
     * ever loaded over https. Null: draw the Blockpile ({@see PlayerProfile}).
     */
    public function avatarUrl(): ?string
    {
        if ($this->avatar_path !== null) {
            return Storage::disk('public')->url($this->avatar_path);
        }

        return is_string($this->picture) && str_starts_with($this->picture, 'https://') ? $this->picture : null;
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
