<?php

namespace App\Support\Notifications;

use App\Jobs\SendNostrDm;
use App\Models\NotificationDigestItem;
use App\Models\User;
use App\Support\Chess\ChessSettings;
use Illuminate\Support\Collection;
use Throwable;

/**
 * The daily DM digest (P45): every notification a player chose to get "once
 * a day" (ChessSettings::digestFor) waits as a NotificationDigestItem, and
 * once a day each player with waiting items gets ONE notification DM that
 * lists them: the newest MAX_ITEMS (oldest of them first) and a count of the
 * rest. At most MAX_ITEMS rows are loaded per player, and one player's failure
 * does not stop the run (P45 audit).
 *
 * Rechecked when it goes out: an item whose kind the player has switched off
 * since, or whose DM they no longer want, is dropped, not sent (one switched
 * back to "at once" meanwhile still goes in this last digest). Items are
 * deleted by id after the DM is queued, so a notification that arrives while
 * the digest runs waits for the next one. Without a notification key nothing
 * is sent and items older than STALE_HOURS are dropped.
 */
final class DmDigest
{
    public const MAX_ITEMS = 15;

    public const STALE_HOURS = 48;

    /**
     * @return int the number of digests queued
     */
    public function run(): int
    {
        if (! NotificationDm::fromConfig()->isConfigured()) {
            NotificationDigestItem::query()->where('created_at', '<', now()->subHours(self::STALE_HOURS))->delete();

            return 0;
        }

        $sent = 0;

        foreach (NotificationDigestItem::query()->distinct()->pluck('user_id')->map(intval(...)) as $userId) {
            // One player's failure never stops the others' digests; their items wait for the next run.
            try {
                $sent += $this->digestFor($userId) ? 1 : 0;
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $sent;
    }

    /**
     * One player's digest. At most MAX_ITEMS rows are loaded (the newest, shown
     * oldest first); the rest is only counted, in the database. Everything up
     * to the newest row seen is deleted afterwards, a row that arrives
     * meanwhile waits for the next digest.
     */
    private function digestFor(int $userId): bool
    {
        $upTo = NotificationDigestItem::query()->where('user_id', $userId)->max('id');

        if ($upTo === null) {
            return false;
        }

        $pending = NotificationDigestItem::query()->where('user_id', $userId)->where('id', '<=', $upTo);
        $user = User::query()->find($userId);
        $queued = false;

        if ($user !== null) {
            $settings = $user->chessSettings();
            $kinds = array_values(array_filter(ChessSettings::triggers(), fn (string $kind): bool => $settings->wants($kind) && $settings->dmFor($kind)));
            $wanted = (clone $pending)->whereIn('kind', $kinds);
            $total = (clone $wanted)->count();

            if ($total > 0) {
                $shown = (clone $wanted)->orderByDesc('id')->limit(self::MAX_ITEMS)->get()->reverse()->values();
                SendNostrDm::dispatch($user, self::text($user, $shown, $total));
                $queued = true;
            }
        }

        $pending->delete();

        return $queued;
    }

    /**
     * The digest DM: a heading with the count, one block per notification
     * (title and body as one plain line, the link on its own line), the rest
     * as a count, and the opt-out line last, in the player's language.
     *
     * @param  Collection<int, NotificationDigestItem>  $shown  at most MAX_ITEMS, oldest first
     * @param  int  $total  every notification of this digest, shown or not
     */
    public static function text(User $user, Collection $shown, int $total): string
    {
        $locale = $user->locale ?? (string) config('app.locale');
        $lines = [trans_choice('Your TWENTY ONE esports digest: :count notification|Your TWENTY ONE esports digest: :count notifications', $total, [], $locale)];

        foreach ($shown->take(self::MAX_ITEMS) as $item) {
            $lines[] = '';
            $lines[] = '• '.PlainText::line($item->title.': '.$item->body, names: true);
            $lines[] = Notice::onApp($item->url);
        }

        if ($total > $shown->take(self::MAX_ITEMS)->count()) {
            $lines[] = '';
            $lines[] = __('… and :count more in the bell on the site.', ['count' => $total - $shown->take(self::MAX_ITEMS)->count()], $locale);
        }

        return implode("\n", $lines)."\n\n".NotificationDmOptOut::line($user);
    }
}
