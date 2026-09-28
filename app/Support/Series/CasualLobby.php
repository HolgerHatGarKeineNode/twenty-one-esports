<?php

namespace App\Support\Series;

use App\Enums\ChessInviteStatus;
use App\Enums\Platform;
use App\Enums\SeriesResolution;
use App\Models\SeriesInvite;
use App\Models\SeriesMatch;
use App\Models\SeriesQueueEntry;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What the casual 1v1 screens read (P23 S3): the module on the game pages
 * (components/⚡casual-play) and the ready prompt of every page
 * (components/⚡casual-ready). The rules stay in CasualQueue, CasualInvites
 * and CasualMatches; this class only reads and remembers.
 */
final class CasualLobby
{
    /** A player counts as online this long after their last request (sessions table). */
    public const ONLINE_MINUTES = 10;

    /** At most this many looking players are listed. */
    public const LIST_LIMIT = 20;

    /** The void of a missed ready check is shown this long afterwards. */
    public const MISSED_SECONDS = 180;

    public function __construct(private CasualMatches $matches) {}

    /**
     * Casual 1v1 needs the end-to-end encrypted match chat: the lobby travels
     * only there (S2). No chat relay, no casual 1v1.
     */
    public static function chatOn(): bool
    {
        return array_values(array_filter((array) config('esports.chat.relays', []))) !== [];
    }

    /**
     * @return list<string> the games with casual 1v1, in config order
     */
    public static function games(): array
    {
        return array_values(array_map(strval(...), (array) config('esports.casual.games', [])));
    }

    public static function offers(string $game): bool
    {
        return in_array($game, self::games(), true);
    }

    /**
     * The platform and crossplay this player picked last for the game;
     * before the first pick their profile platform (else PC) and crossplay on.
     *
     * @return array{platform: Platform, crossplay: bool}
     */
    public function settings(User $user, string $game): array
    {
        $saved = (array) (($user->casual_settings ?? [])[$game] ?? []);
        $platform = Platform::tryFrom((string) ($saved['platform'] ?? '')) ?? $user->platform ?? Platform::Pc;

        return ['platform' => $platform, 'crossplay' => (bool) ($saved['crossplay'] ?? true)];
    }

    public function remember(User $user, string $game, Platform $platform, bool $crossplay): void
    {
        $settings = $user->casual_settings ?? [];
        $settings[$game] = ['platform' => $platform->value, 'crossplay' => $crossplay];
        $user->forceFill(['casual_settings' => $settings])->save();
    }

    /**
     * Players who switched "Looking to play" on for this game and made a
     * request in the last ONLINE_MINUTES, most recently active first. The
     * activity is the session table's; with another session driver (the
     * array driver of the tests) nobody can be told apart, and everyone
     * looking is listed.
     *
     * @return Collection<int, User>
     */
    public function lookingPlayers(string $game, ?User $viewer): Collection
    {
        $query = User::query()->where('looking_to_play', $game.'/'.CasualMatches::mode())
            ->when($viewer !== null, fn ($query) => $query->whereKeyNot($viewer?->id));

        if (config('session.driver') !== 'database') {
            return $query->orderBy('id')->limit(self::LIST_LIMIT)->get();
        }

        $table = (string) config('session.table', 'sessions');
        $active = DB::table($table)->whereNotNull('user_id')->where('last_activity', '>=', now()->subMinutes(self::ONLINE_MINUTES)->getTimestamp())
            ->groupBy('user_id')->selectRaw('user_id, max(last_activity) as seen');

        return $query->joinSub($active, 'seen', 'seen.user_id', '=', 'users.id')
            ->orderByDesc('seen.seen')->orderBy('users.id')
            ->limit(self::LIST_LIMIT)->get(['users.*']);
    }

    /** How many players search this game right now (the tile's count). */
    public function searching(string $game): int
    {
        return SeriesQueueEntry::query()->where('game', $game)->where('mode', CasualMatches::mode())->count();
    }

    /**
     * The casual match of this player in the ready check, if any.
     */
    public function readyCheckOf(User $user): ?SeriesMatch
    {
        $match = $this->matches->activeMatchOf($user);

        return $match?->awaitsReady() === true ? $match : null;
    }

    /**
     * The latest ready check this player was in that ran out in the last
     * MISSED_SECONDS, for the prompt's "missed" message; null otherwise.
     */
    public function missedReadyCheckOf(User $user, ?int $after = null): ?SeriesMatch
    {
        return CasualMatches::playedBy(SeriesMatch::query()->whereNotNull('origin'), $user)
            ->where('resolution', SeriesResolution::Void)
            ->whereNull('start_at')
            ->where('finished_at', '>=', now()->subSeconds(self::MISSED_SECONDS))
            ->when($after !== null, fn ($query) => $query->where('number', '>=', $after))
            ->latest('id')
            ->first();
    }

    /**
     * The open invite between these two players, sent by `$from`, if any
     * (the room's rematch).
     */
    public function openInvite(User $from, User $to, string $game): ?SeriesInvite
    {
        return SeriesInvite::query()->where('inviter_id', $from->id)->where('invitee_id', $to->id)->where('game', $game)
            ->where('status', ChessInviteStatus::Pending)->where('expires_at', '>', now())->latest('id')->first();
    }
}
