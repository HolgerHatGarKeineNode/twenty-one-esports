<?php

namespace App\Support\SeasonChain;

use App\Enums\ChessGameStatus;
use App\Enums\NotificationKind;
use App\Enums\SeriesStatus;
use App\Models\ChessGame;
use App\Models\NostrEvent;
use App\Models\OpponentRequest;
use App\Models\SeriesMatch;
use App\Models\TrustRank;
use App\Models\User;
use App\Support\Notifications\Notice;
use App\Support\Notifications\Notifier;
use App\Support\Series\CasualMatches;
use Carbon\CarbonInterface;

/**
 * Opponent requests (P57): a player whose newest opponent list names you
 * while yours does not name them has asked you for rated games. The list is
 * theirs and public (NIP "Opponent list"); what the league keeps here is
 * yours: whether you were told, and whether you declined.
 *
 * - Told once: a new list version that ADDS you notifies you
 *   ({@see NotificationKind::OpponentRequest}), unless you list them already
 *   (that makes it mutual, nothing to answer), you declined them, or you
 *   were told about them before. Re-publishing, removing and versions older
 *   than a day (a list first read from the relays) never notify. At most
 *   `esports.opponents.requests_per_day` notifications per requester and
 *   UTC day; the rest still show on the page.
 * - Accept is the ordinary signed add ({@see Opponents::add()}); it also
 *   lifts an earlier decline.
 * - Decline hides the request and stops notifications from that player;
 *   "show declined" undoes it. Their list still names you: no rated game
 *   happens between you unless you add them back.
 */
final class OpponentRequests
{
    /** A version signed longer ago than this adds nobody "now" (backfill from the relays). */
    private const FRESH_SECONDS = 86_400;

    /** An account this young with no finished game here is flagged on its card. */
    private const NEW_ACCOUNT_DAYS = 7;

    public function __construct(private Notifier $notifier, private Opponents $opponents) {}

    /**
     * Called when $version became its author's newest list; $before are the
     * entries of the version it replaced ([] for a first one).
     *
     * @param  list<string>  $before
     */
    public function listChanged(NostrEvent $version, array $before): void
    {
        if ($version->signed_at < now()->getTimestamp() - self::FRESH_SECONDS) {
            return;
        }

        $added = array_values(array_diff(OpponentLists::entries($version), $before));

        if ($added === []) {
            return;
        }

        $requester = User::query()->where('pubkey', $version->pubkey)->first();

        if ($requester === null) {
            return;
        }

        $lists = OpponentLists::forLeague();
        $limit = max(0, (int) config('esports.opponents.requests_per_day'));

        foreach (User::query()->whereIn('pubkey', $added)->orderBy('id')->get() as $player) {
            $theirs = $lists?->newest($player->pubkey);

            // Already on the player's list: the addition makes it mutual, there is nothing to answer.
            if ($theirs !== null && in_array($requester->pubkey, OpponentLists::entries($theirs), true)) {
                continue;
            }

            $request = OpponentRequest::query()->firstOrCreate(['user_id' => $player->id, 'requester_pubkey' => $requester->pubkey]);

            if ($request->declined_at !== null || $request->notified_at !== null) {
                continue;
            }

            $today = OpponentRequest::query()->where('requester_pubkey', $requester->pubkey)
                ->where('notified_at', '>=', now()->utc()->startOfDay())->count();

            if ($today >= $limit) {
                continue;
            }

            $request->forceFill(['notified_at' => now()])->save();
            $this->notify($player, $requester);
        }
    }

    /**
     * The players who list $player without being on $player's list: open
     * requests first, then the declined ones, each oldest request first.
     *
     * @return array{open: list<string>, declined: list<string>}
     */
    public function of(User $player): array
    {
        $waiting = array_values(array_diff($this->opponents->listedBy($player), $this->opponents->entries($player)));
        $declined = array_flip(OpponentRequest::query()->where('user_id', $player->id)->whereNotNull('declined_at')
            ->whereIn('requester_pubkey', $waiting)->pluck('requester_pubkey')->all());

        return [
            'open' => array_values(array_filter($waiting, fn (string $pubkey): bool => ! isset($declined[$pubkey]))),
            'declined' => array_values(array_filter($waiting, fn (string $pubkey): bool => isset($declined[$pubkey]))),
        ];
    }

    /** Whether $player declined the request of $requester. */
    public function declined(User $player, string $requester): bool
    {
        return OpponentRequest::query()->where('user_id', $player->id)->where('requester_pubkey', $requester)->whereNotNull('declined_at')->exists();
    }

    /**
     * Hide the request of a player who lists $player, and stop notifications
     * from them.
     *
     * @throws OpponentListRefused
     */
    public function decline(User $player, string $requester): void
    {
        if (! in_array($requester, $this->opponents->listedBy($player), true)) {
            throw new OpponentListRefused(__('That player does not list you as an opponent.'));
        }

        OpponentRequest::query()->updateOrCreate(['user_id' => $player->id, 'requester_pubkey' => $requester], ['declined_at' => now()]);
    }

    /** Undo a decline: the request shows again. Nobody is notified. */
    public function restore(User $player, string $requester): void
    {
        OpponentRequest::query()->where('user_id', $player->id)->where('requester_pubkey', $requester)->update(['declined_at' => null]);
    }

    /**
     * What the league knows about a requester, for judging a fake or a bot:
     * only league records, nothing guessed.
     *
     * @return array{joined: CarbonInterface|null, games: int, trusted: bool|null, member: bool, clan: string|null, same_clan: bool, meetup: string|null, vouched: int, new: bool}
     */
    public function signals(User $viewer, User $requester): array
    {
        $games = ChessGame::query()->where('status', ChessGameStatus::Finished)
            ->where(fn ($query) => $query->where('white_id', $requester->id)->orWhere('black_id', $requester->id))->count()
            + SeriesMatch::query()->whereIn('id', CasualMatches::onSide($requester))
                ->whereIn('status', [SeriesStatus::Confirmed, SeriesStatus::Resolved])->count();

        $gate = app(RatedTrustGate::class);
        $trusted = $gate->isAvailable()
            ? (int) TrustRank::query()->where('pubkey', $requester->pubkey)->value('rank') >= RatedTrustGate::minimum()
            : null;

        $theirClan = $requester->clanMember?->clan;
        $myClan = $viewer->clanMember?->clan;
        $sameClan = $theirClan !== null && $myClan !== null && $theirClan->is($myClan);
        $meetup = ! $sameClan && $theirClan?->meetup_name !== null && $theirClan->meetup_name === $myClan?->meetup_name
            ? $theirClan->meetup_name : null;

        // Players on the viewer's own list whose newest list names the requester too.
        $newest = OpponentLists::forLeague()?->newestEntries() ?? [];
        $vouched = count(array_filter($this->opponents->entries($viewer),
            fn (string $entry): bool => $entry !== $requester->pubkey && in_array($requester->pubkey, $newest[$entry] ?? [], true)));

        $joined = $requester->created_at;

        return [
            'joined' => $joined,
            'games' => $games,
            'trusted' => $trusted,
            'member' => $requester->is_member,
            'clan' => $theirClan?->name,
            'same_clan' => $sameClan,
            'meetup' => $meetup,
            'vouched' => $vouched,
            'new' => $games === 0 && $joined !== null && $joined->greaterThan(now()->subDays(self::NEW_ACCOUNT_DAYS)),
        ];
    }

    private function notify(User $player, User $requester): void
    {
        $locale = $player->locale ?? (string) config('app.locale');

        $this->notifier->send($player, NotificationKind::OpponentRequest, new Notice(
            __(':name added you as an opponent', ['name' => $requester->displayName()], $locale),
            __('Accept to allow rated games between you, or decline if you do not know them.', [], $locale),
            route('settings.opponents').'#requests',
            null,
            __('Review', [], $locale),
        ), sender: $requester);
    }
}
