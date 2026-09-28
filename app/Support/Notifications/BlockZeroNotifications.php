<?php

namespace App\Support\Notifications;

use App\Enums\NotificationKind;
use App\Models\Season;
use App\Models\User;
use App\Support\PreSeason;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Delivery of "Notify me at Block 0" (home, pre-launch;
 * NotifyAtBlockZeroController) through the Notifier (the bell, push, and a
 * Nostr DM by default, NotificationKind::BlockZero), run by the queued job
 * App\Jobs\NotifyBlockZero:
 *
 * 1. **Released** ({@see released()}): after the board released Block 0
 *    (SeasonRelease), every player who asked hears it once
 *    (`block0_notified_at`).
 * 2. **Dated** ({@see dated()}): while the planned Block 0
 *    (the chain draft, ChainDraft) lies ahead and no season was released,
 *    every player who asked hears the date once (`block0_heads_up_for`).
 *    A moved date is a new date and is told again; a player who asked while
 *    the date was already on the page is not told it again.
 *
 * Players are read in chunks by id. Each player is claimed with a
 * conditional update before the notification goes out, so a second or
 * concurrent run sends nothing twice (at most once: a delivery that throws
 * after the claim is reported, not retried). One player whose delivery
 * fails does not stop the others.
 */
final class BlockZeroNotifications
{
    public const CHUNK = 200;

    public function __construct(private Notifier $notifier) {}

    /**
     * @return int the players told
     */
    public function released(): int
    {
        if (! Season::query()->exists()) {
            return 0;
        }

        $pending = fn (): Builder => User::query()->whereNotNull('notify_block0_at')->whereNull('block0_notified_at');

        return $this->each($pending, fn (int $id): bool => User::query()->whereKey($id)->whereNull('block0_notified_at')->update(['block0_notified_at' => now()]) === 1,
            function (User $user): void {
                $locale = $user->locale ?? (string) config('app.locale');

                $this->notifier->send($user, NotificationKind::BlockZero, new Notice(
                    __('Block 0 is released', [], $locale),
                    __('The Pre-Season has started: rated play and mining are open.', [], $locale),
                    route('mining'),
                    null,
                    __('View', [], $locale),
                ));
            });
    }

    /**
     * @return int the players told
     */
    public function dated(): int
    {
        $at = self::plannedDate();

        if ($at === null) {
            return 0;
        }

        $stamp = self::stamp($at);
        $pending = fn (): Builder => User::query()->whereNotNull('notify_block0_at')->whereNull('block0_notified_at')
            ->where(fn (Builder $query) => $query->whereNull('block0_heads_up_for')->orWhere('block0_heads_up_for', '!=', $stamp));

        return $this->each($pending, fn (int $id): bool => $pending()->whereKey($id)->update(['block0_heads_up_for' => $stamp]) === 1,
            function (User $user) use ($at): void {
                $locale = $user->locale ?? (string) config('app.locale');
                $local = $at->setTimezone(PreSeason::timezoneFor($user))->settings(['locale' => $locale]);

                $this->notifier->send($user, NotificationKind::BlockZero, new Notice(
                    __('Block 0 is on :date', ['date' => $local->translatedFormat('D j M, H:i T')], $locale),
                    __('Rated play and mining start when the board releases it. You hear from us again then.', [], $locale),
                    route('home').'#block0',
                    null,
                    __('View', [], $locale),
                ));
            });
    }

    /** Anyone waiting for a heads-up about the planned date? A cheap check for the schedule. */
    public function datedPending(): bool
    {
        $at = self::plannedDate();

        return $at !== null && User::query()->whereNotNull('notify_block0_at')->whereNull('block0_notified_at')
            ->where(fn (Builder $query) => $query->whereNull('block0_heads_up_for')->orWhere('block0_heads_up_for', '!=', self::stamp($at)))
            ->exists();
    }

    /** The planned Block 0 while it lies ahead and nothing was released, else null. */
    public static function plannedDate(): ?CarbonImmutable
    {
        $at = PreSeason::block0At();

        return $at !== null && $at->isFuture() && ! Season::query()->exists() ? $at : null;
    }

    /** How a date is stored and compared: in the app's zone, to the second. */
    public static function stamp(CarbonImmutable $at): string
    {
        return $at->setTimezone((string) config('app.timezone'))->toDateTimeString();
    }

    /**
     * @param  Closure(): Builder<User>  $pending
     * @param  Closure(int): bool  $claim
     * @param  Closure(User): void  $send
     */
    private function each(Closure $pending, Closure $claim, Closure $send): int
    {
        $told = 0;

        $pending()->select('id')->chunkById(self::CHUNK, function (Collection $chunk) use ($claim, $send, &$told): void {
            foreach ($chunk as $row) {
                $id = (int) $row->getKey();

                if (! $claim($id)) {
                    continue;
                }

                try {
                    $send(User::query()->findOrFail($id));
                    $told++;
                } catch (Throwable $exception) {
                    report($exception);
                }
            }
        });

        return $told;
    }
}
