<?php

namespace App\Models;

use App\Enums\ClanRole;
use App\Support\Clans\ClanLogos;
use App\Support\Nostr\NostrKeys;
use Database\Factories\ClanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A clan, projected from its clan event (NIP kind 32150, `d` = slug, signed
 * by the owner). Members are the rows of `clan_members`: players the clan
 * lists whose own Clan Membership (kind 12150) points here.
 *
 * @property int $id
 * @property string $slug
 * @property int|null $owner_id
 * @property string $owner_pubkey
 * @property string $name
 * @property string $clantag
 * @property string|null $description
 * @property string|null $picture
 * @property string|null $meetup_name
 * @property string|null $meetup_city
 * @property string|null $meetup_url
 * @property string|null $meetup_latitude
 * @property string|null $meetup_longitude
 * @property string|null $event_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'slug', 'owner_id', 'owner_pubkey', 'name', 'clantag', 'description', 'picture',
    'meetup_name', 'meetup_city', 'meetup_url', 'meetup_latitude', 'meetup_longitude', 'event_id',
])]
class Clan extends Model
{
    /** @use HasFactory<ClanFactory> */
    use HasFactory;

    public const KIND = 32150;

    /** Clan slug, NIP "Identifiers". */
    public const SLUG_PATTERN = '/^[a-z0-9][a-z0-9-]{1,47}$/';

    /** Clan tag, NIP tag `clantag`. */
    public const TAG_PATTERN = '/^[A-Z0-9]{2,4}$/';

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * `/clans/{clan}` takes the slug, and also the clan tag in any case, so
     * links built from a tag (home page, ladders) resolve too.
     */
    public function resolveRouteBinding($value, $field = null): ?self
    {
        $value = (string) $value;

        return self::query()
            ->where(fn (Builder $query) => $query->where('slug', $value)->orWhere('clantag', strtoupper($value)))
            ->first();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return HasMany<ClanMember, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(ClanMember::class);
    }

    /**
     * @return HasMany<Lineup, $this>
     */
    public function lineups(): HasMany
    {
        return $this->hasMany(Lineup::class);
    }

    /**
     * @return HasMany<ClanInvite, $this>
     */
    public function invites(): HasMany
    {
        return $this->hasMany(ClanInvite::class);
    }

    /**
     * @return HasMany<ClanDeparture, $this>
     */
    public function departures(): HasMany
    {
        return $this->hasMany(ClanDeparture::class);
    }

    /**
     * NIP-01 address of the clan event, `32150:<owner pubkey>:<slug>`.
     */
    public function address(): string
    {
        return self::KIND.':'.$this->owner_pubkey.':'.$this->slug;
    }

    public function naddr(): string
    {
        return NostrKeys::naddr(self::KIND, $this->owner_pubkey, $this->slug);
    }

    public function memberOf(User $user): ?ClanMember
    {
        return $this->members->firstWhere('user_id', $user->id);
    }

    public function isOwner(User $user): bool
    {
        return $this->owner_id === $user->id && $this->memberOf($user) !== null;
    }

    public function isCaptain(User $user): bool
    {
        return $this->memberOf($user)?->role === ClanRole::Captain;
    }

    /**
     * The logo URL when it is one this league redrew and stored itself
     * (`clan-logos/<sha256>.png` on the public disk), else null. A portal
     * logo or any other foreign `picture` is never rendered: it would
     * hotlink a third party on every page that shows the clan.
     */
    public function localLogoUrl(): ?string
    {
        return app(ClanLogos::class)->pathOf($this->picture) !== null ? $this->picture : null;
    }

    /**
     * More than half of the players are paid EINUNDZWANZIG members.
     */
    public function isMemberClan(): bool
    {
        $total = $this->members->count();

        return $total > 0 && $this->members->filter(fn (ClanMember $member) => $member->user->is_member)->count() * 2 > $total;
    }
}
