<?php

namespace App\Support\Moderation;

use App\Enums\BoardInviteStatus;
use App\Enums\ChessInviteStatus;
use App\Enums\ModerationAction;
use App\Events\SiteModerationChanged;
use App\Jobs\PublishLeagueMuteList;
use App\Models\Admin;
use App\Models\BoardChallenge;
use App\Models\BoardInvite;
use App\Models\BoardQueueEntry;
use App\Models\ChessChallenge;
use App\Models\ChessInvite;
use App\Models\ChessQueueEntry;
use App\Models\PubkeyModeration;
use App\Models\SeriesInvite;
use App\Models\SeriesQueueEntry;
use App\Models\User;
use App\Support\Board;
use App\Support\Chess\Broadcasts;
use App\Support\Nostr\NostrKeys;
use App\Support\Tournaments\TournamentModeration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Site-wide moderation of a Nostr key by an admin (user, 2026-10-05: „im
 * Chat Moderations-Tools, wie globales Muten für alle im Chat und auf der
 * Seite und komplettes Ban von der Seite eines npubs"). The chats read
 * straight from relays in the browser, so nothing is deleted anywhere: the
 * site hides what a key wrote.
 *
 * - Mute: the key's messages, comments, RSVPs and its place on a zap wall
 *   are left out for everyone on this site, old and new ones. The browser
 *   gets the hidden keys with each chat's config and hears of a change on
 *   the public `moderation` channel (SiteModerationChanged); server lists
 *   filter with isHidden(). The muted key still logs in and plays, and
 *   still sees its own messages.
 * - Ban: the mute plus no participation. Login is refused (Nostr, Google
 *   through nostr-mill, bunker: all of them sign the same login event), an
 *   open session ends with its next request (EndBannedSessions), its entries
 *   before a draw are withdrawn with a neutral reason, its queue places go
 *   and its open challenges and invites are withdrawn; player pickers no
 *   longer suggest it.
 *
 * Every step is a row of `pubkey_moderations` with the admin and the
 * reason; undo keeps the row and sets who lifted it. Undoing a ban lets the
 * key log in and be seen again; withdrawn entries and invites stay withdrawn.
 * The keys themselves are public (user, 2026-10-05): after every change the
 * league key publishes them as its NIP-51 mute list ({@see LeagueMuteList}).
 * Reasons, admins and whether a key is muted or banned never leave the
 * admin page; neither that list nor the browser's says mute or ban. Admins
 * and board members cannot be muted or banned.
 */
final class SiteModeration
{
    public const CACHE_KEY = 'site-moderation:v1';

    /** The neutral reason on a withdrawn tournament entry (an English key, translated for each reader). */
    public const ENTRY_REASON = 'Withdrawn by the league.';

    public function __construct(private TournamentModeration $tournaments) {}

    /**
     * The keys in force, from the cache; rebuilt from the table after every change.
     *
     * @return array{hidden: list<string>, banned: list<string>}
     */
    public static function state(): array
    {
        try {
            /** @var array{hidden: list<string>, banned: list<string>} */
            return Cache::rememberForever(self::CACHE_KEY, fn (): array => self::read());
        } catch (Throwable $exception) {
            // An unreadable cache never lets a banned key through and never blocks anyone else: the table is the truth.
            report($exception);

            return self::read();
        }
    }

    /**
     * @return array{hidden: list<string>, banned: list<string>}
     */
    private static function read(): array
    {
        $rows = PubkeyModeration::query()->active()->get(['pubkey', 'action']);

        return [
            'hidden' => array_values(array_unique($rows->pluck('pubkey')->all())),
            'banned' => array_values(array_unique($rows->where('action', ModerationAction::Ban)->pluck('pubkey')->all())),
        ];
    }

    /**
     * Muted or banned keys: what the site hides.
     *
     * @return list<string>
     */
    public static function hiddenPubkeys(): array
    {
        return self::state()['hidden'];
    }

    public static function isHidden(?string $pubkey): bool
    {
        return $pubkey !== null && in_array($pubkey, self::state()['hidden'], true);
    }

    public static function isBanned(?string $pubkey): bool
    {
        return $pubkey !== null && in_array($pubkey, self::state()['banned'], true);
    }

    /** By account id, for a finish a game server reports (no query while nobody is banned). */
    public static function isBannedUser(int $userId): bool
    {
        $banned = self::state()['banned'];

        return $banned !== [] && User::query()->whereKey($userId)->whereIn('pubkey', $banned)->exists();
    }

    /**
     * Straight from the table, for the login: a cache that has not caught up never lets a banned key in.
     */
    public static function isBannedNow(string $pubkey): bool
    {
        return PubkeyModeration::query()->active()->where('pubkey', $pubkey)->where('action', ModerationAction::Ban)->exists();
    }

    /**
     * The hidden keys a viewer's browser gets with a chat's config: without
     * the viewer's own key, so a muted player still sees what they wrote.
     *
     * @return list<string>
     */
    public static function hiddenFor(?User $viewer): array
    {
        return array_values(array_filter(self::hiddenPubkeys(), fn (string $pubkey): bool => $pubkey !== $viewer?->pubkey));
    }

    /**
     * The viewer's own mutes plus the keys hidden site-wide, for the lists
     * that leave both out entirely (comments and RSVPs on Nostr).
     *
     * @return list<string>
     */
    public static function leftOutFor(?User $viewer): array
    {
        return array_values(array_unique([...($viewer?->mutedPubkeys() ?? []), ...self::hiddenFor($viewer)]));
    }

    /**
     * @throws SiteModerationRefused
     */
    public function mute(User $actor, string $pubkey, string $reason): PubkeyModeration
    {
        return $this->add($actor, ModerationAction::Mute, $pubkey, $reason);
    }

    /**
     * @throws SiteModerationRefused
     */
    public function ban(User $actor, string $pubkey, string $reason): PubkeyModeration
    {
        $row = $this->add($actor, ModerationAction::Ban, $pubkey, $reason);
        $player = User::query()->where('pubkey', $row->pubkey)->first();

        if ($player instanceof User) {
            $this->endParticipation($actor, $player);
        }

        return $row;
    }

    /**
     * Undo a mute or a ban: the row stays, with who lifted it and when.
     *
     * @throws SiteModerationRefused
     */
    public function lift(User $actor, int $id): PubkeyModeration
    {
        $this->authorize($actor);

        $row = DB::transaction(function () use ($actor, $id): PubkeyModeration {
            $row = PubkeyModeration::query()->active()->lockForUpdate()->find($id)
                ?? throw new SiteModerationRefused('not_active', __('This is already undone.'));

            $row->forceFill(['lifted_at' => now(), 'lifted_by_pubkey' => $actor->pubkey])->save();
            $this->changed($row->pubkey);

            return $row;
        });

        return $row;
    }

    /**
     * @throws SiteModerationRefused
     */
    private function add(User $actor, ModerationAction $action, string $pubkey, string $reason): PubkeyModeration
    {
        $this->authorize($actor);
        $hex = NostrKeys::toHex(trim($pubkey)) ?? throw new SiteModerationRefused('key', __('Enter an npub or a 64-character hex public key.'));
        $reason = trim($reason);

        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 500) {
            throw new SiteModerationRefused('reason', __('Give a reason of 3 to 500 characters. Only admins read it.'));
        }

        if ($hex === $actor->pubkey || Board::contains($hex) || Admin::query()->where('pubkey', $hex)->exists()) {
            throw new SiteModerationRefused('admin', __('Admins cannot be muted or banned.'));
        }

        return DB::transaction(function () use ($actor, $action, $hex, $reason): PubkeyModeration {
            if (PubkeyModeration::query()->active()->where('pubkey', $hex)->where('action', $action)->lockForUpdate()->exists()) {
                throw new SiteModerationRefused('already', $action === ModerationAction::Ban ? __('This key is already banned.') : __('This key is already muted for everyone.'));
            }

            $row = PubkeyModeration::query()->create([
                'pubkey' => $hex,
                'action' => $action,
                'reason' => $reason,
                'actor_pubkey' => $actor->pubkey,
            ]);
            $this->changed($hex);

            return $row;
        });
    }

    /**
     * A banned player's open places: entries before a draw, queue places, and the challenges and invites they sent.
     */
    private function endParticipation(User $actor, User $player): void
    {
        $this->tournaments->withdrawBanned($actor, $player, self::ENTRY_REASON);

        ChessQueueEntry::query()->where('user_id', $player->id)->delete();
        BoardQueueEntry::query()->where('user_id', $player->id)->delete();
        SeriesQueueEntry::query()->where('user_id', $player->id)->delete();

        ChessChallenge::query()->where('challenger_id', $player->id)->where('status', ChessInviteStatus::Pending)->update(['status' => ChessInviteStatus::Withdrawn]);
        ChessInvite::query()->where('inviter_id', $player->id)->where('status', ChessInviteStatus::Pending)->update(['status' => ChessInviteStatus::Withdrawn]);
        SeriesInvite::query()->where('inviter_id', $player->id)->where('status', ChessInviteStatus::Pending)->update(['status' => ChessInviteStatus::Withdrawn]);
        BoardChallenge::query()->where('challenger_id', $player->id)->where('status', BoardInviteStatus::Pending)->update(['status' => BoardInviteStatus::Withdrawn]);
        BoardInvite::query()->where('inviter_id', $player->id)->where('status', BoardInviteStatus::Pending)->update(['status' => BoardInviteStatus::Withdrawn]);
    }

    /**
     * After the commit: the cache is rebuilt and every open chat hears whether the key is hidden now.
     */
    private function changed(string $pubkey): void
    {
        DB::afterCommit(function () use ($pubkey): void {
            Cache::forget(self::CACHE_KEY);
            Broadcasts::send(new SiteModerationChanged($pubkey, self::isHidden($pubkey)));

            // The public mute list follows; a queue that cannot take the job never undoes the committed step.
            try {
                PublishLeagueMuteList::dispatch();
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }

    /**
     * @throws SiteModerationRefused
     */
    private function authorize(User $actor): void
    {
        if (! $actor->can('admin')) {
            throw new SiteModerationRefused('not_admin', __('Only admins can do this.'));
        }
    }
}
