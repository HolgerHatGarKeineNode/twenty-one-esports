<?php

namespace App\Support\Nostr;

use App\Enums\ChessGameStatus;
use App\Models\ChessGame;
use App\Models\Clan;
use App\Models\LineupSeat;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * What the player card (ProfileHovercard) and the player page header
 * (PlayerHeader) show about one player: the cached Nostr profile, plus the
 * league's own facts (clan, games).
 *
 * Every empty field is null, so a view leaves the row out ("never a dash").
 * Ratings and trust are not shown: the league has neither before P7.
 */
final readonly class PlayerProfile
{
    /**
     * @param  'verified'|'unverified'|'unchecked'|null  $nip05State
     */
    private function __construct(
        public User $user,
        public string $name,
        public ?string $picture,
        public string $generatedAvatar,
        public ?string $banner,
        public ?string $about,
        public ?string $website,
        public ?string $websiteLabel,
        public ?string $lud16,
        public ?string $nip05,
        public ?string $nip05State,
        public bool $hasProfile,
        public ?Clan $clan,
        public ?string $clanRole,
        public int $chessGames,
        public bool $playsRocketLeague,
    ) {}

    public static function for(User $user): self
    {
        $membership = $user->clanMember;
        $nip05 = $user->nip05;

        return new self(
            user: $user,
            name: $user->displayName(),
            picture: $user->avatarUrl(),
            generatedAvatar: self::generatedAvatarUrl($user->pubkey),
            banner: $user->banner,
            about: $user->about,
            website: $user->website,
            websiteLabel: $user->website === null ? null : Str::limit((string) preg_replace('#^https://(www\.)?#i', '', rtrim($user->website, '/')), 48, '…'),
            lud16: $user->lud16,
            nip05: $nip05 === null ? null : Nip05Verifier::display($nip05),
            nip05State: match (true) {
                $nip05 === null => null,
                $user->nip05_verified_at !== null => 'verified',
                $user->nip05_checked_at !== null => 'unverified',
                default => 'unchecked',
            },
            hasProfile: $user->profile_event_at !== null,
            clan: $membership?->clan,
            clanRole: $membership?->role->label(),
            chessGames: ChessGame::query()
                ->where('status', ChessGameStatus::Finished)
                ->where(fn ($query) => $query->where('white_id', $user->id)->orWhere('black_id', $user->id))
                ->count(),
            playsRocketLeague: LineupSeat::query()->where('user_id', $user->id)->exists(),
        );
    }

    /**
     * Same-origin URL of the Blockpile avatar; `v` changes if the drawing does.
     */
    public static function generatedAvatarUrl(string $pubkey): string
    {
        return route('avatars.generated', ['pubkey' => $pubkey, 'v' => 1]);
    }

    public function isMember(): bool
    {
        return $this->user->is_member;
    }

    /**
     * "chess and Rocket League", "chess", or null.
     */
    public function games(): ?string
    {
        $games = array_values(array_filter([
            $this->chessGames > 0 ? __('chess') : null,
            $this->playsRocketLeague ? __('Rocket League') : null,
        ]));

        return match (count($games)) {
            0 => null,
            1 => $games[0],
            default => __(':a and :b', ['a' => $games[0], 'b' => $games[1]]),
        };
    }

    /**
     * Pale dots in the pile's own hue: the banner of a player without one.
     */
    public function bannerPlaceholderStyle(): string
    {
        $colors = Blockpile::colors($this->user->pubkey);

        return "background-color: {$colors['ground']}; background-image: radial-gradient({$colors['ghost']} 1.5px, transparent 1.5px); background-size: 14px 12px";
    }
}
