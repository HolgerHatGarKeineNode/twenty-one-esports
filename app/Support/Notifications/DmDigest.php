<?php

namespace App\Support\Notifications;

use App\Jobs\SendNostrDm;
use App\Models\NotificationDigestItem;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The daily DM digest (P45): every notification a player chose to get "once
 * a day" (ChessSettings::digestFor) waits as a NotificationDigestItem, and
 * once a day each player with waiting items gets ONE notification DM that
 * lists them, newest last, at most MAX_ITEMS with a count of the rest.
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
            $items = NotificationDigestItem::query()->where('user_id', $userId)->orderBy('id')->get();
            $user = User::query()->find($userId);

            if ($user !== null) {
                $settings = $user->chessSettings();
                $wanted = $items->filter(fn (NotificationDigestItem $item): bool => $settings->wants($item->kind) && $settings->dmFor($item->kind))->values();

                if ($wanted->isNotEmpty()) {
                    SendNostrDm::dispatch($user, self::text($user, $wanted));
                    $sent++;
                }
            }

            NotificationDigestItem::query()->whereIn('id', $items->pluck('id'))->delete();
        }

        return $sent;
    }

    /**
     * The digest DM: a heading with the count, one block per notification
     * (title and body as one plain line, the link on its own line), the rest
     * as a count, and the opt-out line last, in the player's language.
     *
     * @param  Collection<int, NotificationDigestItem>  $items
     */
    public static function text(User $user, Collection $items): string
    {
        $locale = $user->locale ?? (string) config('app.locale');
        $lines = [trans_choice('Your TWENTY ONE esports digest: :count notification|Your TWENTY ONE esports digest: :count notifications', $items->count(), [], $locale)];

        foreach ($items->take(self::MAX_ITEMS) as $item) {
            $lines[] = '';
            $lines[] = '• '.PlainText::line($item->title.': '.$item->body);
            $lines[] = Notice::onApp($item->url);
        }

        if ($items->count() > self::MAX_ITEMS) {
            $lines[] = '';
            $lines[] = __('… and :count more in the bell on the site.', ['count' => $items->count() - self::MAX_ITEMS], $locale);
        }

        return implode("\n", $lines)."\n\n".NotificationDmOptOut::line($user);
    }
}
