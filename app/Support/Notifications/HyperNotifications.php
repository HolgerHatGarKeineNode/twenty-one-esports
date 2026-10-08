<?php

namespace App\Support\Notifications;

use App\Enums\NotificationKind;
use App\Models\HyperMatch;
use App\Models\HyperSeat;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;

/**
 * The notification of a Hyperbitcoinization correspondence match (plan "Hyperbitcoinization", P3), in the
 * recipient's language, over the same kind and switches as daily chess and correspondence board games
 * (NotificationKind::YourMove: a push off the site, at most once an hour per match, YourMoveThrottle), so a
 * player's settings hold for every game.
 *
 * Only the seat whose turn starts hears about it, and only a player: HyperMatches never calls this for a
 * bot seat, a seat its player left, or a spectator. Live matches notify nothing: their players are at the
 * table.
 */
final class HyperNotifications
{
    public function __construct(private Notifier $notifier) {}

    public function yourMove(HyperMatch $match, HyperSeat $seat): void
    {
        $player = $seat->user_id === null ? null : User::query()->find($seat->user_id);

        if (! $match->isCorrespondence() || ! $match->isActive() || $player === null) {
            return;
        }

        $locale = $player->locale ?? (string) config('app.locale');

        $this->notifier->send($player, NotificationKind::YourMove, new Notice(
            __('Your turn in Hyperbitcoinization', [], $locale),
            __('Round :round. You have until :deadline.', [
                'round' => (int) ($match->state['round'] ?? 1),
                'deadline' => $this->deadline($match, $player, $locale),
            ], $locale),
            self::matchUrl($match),
            null,
            __('Play your turn', [], $locale),
        ), $match);
    }

    /** The match page (`hyperbitcoinization/m/{ulid}`), registered or not (routes/hyper.php needs the switch at boot). */
    public static function matchUrl(HyperMatch $match): string
    {
        return Route::has('hyper.match') ? route('hyper.match', $match) : url('hyperbitcoinization/m/'.$match->ulid);
    }

    private function deadline(HyperMatch $match, User $user, string $locale): string
    {
        $deadline = Carbon::createFromTimestampMs((int) $match->deadline_ms)->setTimezone($user->timezone ?? (string) config('app.timezone'));
        $deadline->locale($locale);

        return $deadline->isoFormat('ddd YYYY-MM-DD HH:mm');
    }
}
