<?php

namespace App\Support\Nostr;

use App\Enums\LineupRole;
use App\Models\ChessGame;
use App\Models\Clan;
use App\Models\NostrEvent;
use App\Models\Season;
use App\Models\SeriesMatch;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Badges\ProfileBadges;
use App\Support\Lightning\Lnurl;
use App\Support\QrCode;
use App\Support\StreamBot\StreamCoordinates;
use App\Support\TwentyOne\EventBuilder;
use Illuminate\Support\Facades\Auth;

/**
 * What the Nostr bar of one page shows (P45,
 * resources/views/components/nostr-bar.blade.php): the Nostr object the page
 * is about (an `npub`, `naddr` or `nevent`, NIP-19), the page's own link, who
 * a message and a follow go to, and a zap QR code where the page takes zaps.
 *
 * One factory per kind of page, so every page names the same things the same
 * way. Message and follow are offered only to a signed-in viewer and never
 * for the viewer's own key ({@see forViewer()}). A zap is only ever a QR code
 * of an LNURL: a Lightning address is never shown as text.
 */
final readonly class NostrBar
{
    /**
     * @param  string|null  $entity  NIP-19 `npub`/`naddr`/`nevent`; null: the page has no Nostr object yet
     * @param  array{pubkey: string, name: string}|null  $person  who "Message" and "Follow" go to
     * @param  string|null  $zapQr  a ready QR code (SVG markup, or an image data URL)
     */
    public function __construct(
        public string $context,
        public ?string $entity,
        public string $url,
        public ?array $person = null,
        public ?string $zapQr = null,
        public bool $message = true,
        public bool $follow = true,
    ) {}

    public static function player(User $user, ?string $lud16 = null): self
    {
        $lnurl = Lnurl::fromAddress($lud16);

        return new self(
            'player',
            NostrKeys::hexToNpub($user->pubkey),
            route('players.show', $user->npub),
            ['pubkey' => $user->pubkey, 'name' => $user->displayName()],
            $lnurl === null ? null : QrCode::svg('lightning:'.strtoupper($lnurl), label: __('QR code to zap :name', ['name' => $user->displayName()])),
        );
    }

    public static function clan(Clan $clan): self
    {
        $owner = $clan->owner;

        return new self('clan', $clan->naddr(), route('clans.show', $clan), $owner === null ? null : ['pubkey' => $owner->pubkey, 'name' => $owner->displayName()]);
    }

    public static function tournament(Tournament $tournament): self
    {
        $organizer = $tournament->creator;

        return new self('tournament', $tournament->naddr(), route('tournaments.show', $tournament), $organizer === null ? null : ['pubkey' => $organizer->pubkey, 'name' => $organizer->displayName()]);
    }

    /**
     * A chess game: the league's NIP-64 record once there is one; message and
     * follow go to the viewer's opponent (for a spectator: nobody).
     */
    public static function game(ChessGame $game, ?NostrEvent $record = null): self
    {
        $viewer = Auth::user();
        $opponent = $viewer instanceof User ? $game->opponentOf($viewer) : null;

        return new self(
            'game',
            $record === null ? null : NostrKeys::nevent($record->event_id, $record->pubkey, 64),
            route('games.show', $game),
            $opponent === null ? null : ['pubkey' => $opponent->pubkey, 'name' => $opponent->displayName()],
        );
    }

    /**
     * A series match (the public page and the match room): the challenge
     * (`2150`); message and follow go to the other side's contact, for a
     * viewer who plays a side ({@see opponentContact()}).
     */
    public static function match(SeriesMatch $match, string $context = 'match'): self
    {
        $challenge = $match->challengeEvent;
        $viewer = Auth::user();
        $contact = $viewer instanceof User ? self::opponentContact($match, $viewer) : null;

        return new self(
            $context,
            $challenge === null ? null : NostrKeys::nevent($challenge->event_id, $challenge->pubkey, $challenge->kind),
            route('matches.show', $match->number),
            $contact === null ? null : ['pubkey' => $contact->pubkey, 'name' => $contact->displayName()],
        );
    }

    /**
     * Who speaks for the other side: the first player of a roster side (a
     * casual 1v1 or mix team), else the other lineup's accepted captain, else
     * its clan's owner. Null for a viewer who plays no side.
     */
    public static function opponentContact(SeriesMatch $match, User $viewer): ?User
    {
        $side = $match->participantSideOf($viewer);

        if ($side === null) {
            return null;
        }

        $other = SeriesMatch::otherSide($side);
        $roster = $match->rosterSide($other);

        if ($roster !== []) {
            return User::query()->find($roster[0]);
        }

        $lineup = $match->lineup($other);
        $captain = $lineup?->seats->first(fn ($seat): bool => $seat->role === LineupRole::Captain && $seat->accepted_at !== null);

        return $captain?->loadMissing('user')->user ?? $lineup?->clan?->owner;
    }

    /**
     * The season chain (/mining): its genesis, the reserve's zap QR, and a
     * follow of the league key. No message: the league reads none.
     */
    public static function season(?Season $season, ?string $zapQr = null): self
    {
        $genesis = $season?->genesis_event_id === null ? null : NostrEvent::query()->find($season->genesis_event_id);
        $league = $season?->league_pubkey;

        return new self(
            'season',
            $genesis === null ? null : NostrKeys::nevent($genesis->event_id, $genesis->pubkey, $genesis->kind),
            route('mining'),
            $league === null ? null : ['pubkey' => $league, 'name' => 'TWENTY ONE esports'],
            $zapQr,
            message: false,
        );
    }

    /**
     * The 24/7 stream (/live): its NIP-53 live event (`30311`) and a follow of
     * the stream key. No message (the stream reads none) and no zap here:
     * /live shows its zap QR right under the stage.
     */
    public static function stream(?StreamCoordinates $stream): self
    {
        return new self(
            'stream',
            $stream === null ? null : NostrKeys::naddr(EventBuilder::KIND_LIVE_ACTIVITY, $stream->pubkey, $stream->d, $stream->relays[0] ?? null),
            route('live'),
            $stream === null ? null : ['pubkey' => $stream->pubkey, 'name' => 'TWENTY ONE'],
            message: false,
        );
    }

    /**
     * Who message and follow go to for this viewer: nobody for a guest, and
     * nobody when it is the viewer's own key.
     *
     * @return array{pubkey: string, name: string}|null
     */
    public function personForViewer(): ?array
    {
        $viewer = Auth::user();

        if (! $viewer instanceof User || $this->person === null || ! NostrKeys::isHexPubkey($this->person['pubkey']) || $this->person['pubkey'] === $viewer->pubkey) {
            return null;
        }

        return $this->person;
    }

    /**
     * Relays the browser reads the viewer's lists from and publishes to: the
     * profile and league relays (as the share posts do) and the chat relays.
     *
     * @return list<string>
     */
    public static function browserRelays(): array
    {
        return array_values(array_unique([...ProfileBadges::browserRelays(), ...(array) config('esports.chat.relays', [])]));
    }
}
