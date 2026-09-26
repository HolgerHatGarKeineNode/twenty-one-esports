<?php

namespace App\Support\Engagement;

use App\Models\SlotEvent;
use App\Models\WeeklySlot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Recurring weekly events (P10): an admin defines a slot ("Blitz night,
 * Wednesday 20:00"), the scheduler makes its dated events.
 *
 * Idempotent: every event is keyed by (slot, start) with a unique index and
 * written with insertOrIgnore, so a second run, or two runs at once, make
 * nothing new. The wall-clock time is kept in the slot's own time zone, so
 * "20:00 in Berlin" stays 20:00 across daylight saving time.
 */
final class WeeklySlots
{
    /** How far ahead the scheduler dates events. */
    public const HORIZON_DAYS = 7;

    /**
     * The start (UTC) of the first occurrence of the slot that has not ended
     * at `$from`, so a running event is still found.
     */
    public function nextStart(WeeklySlot $slot, CarbonImmutable $from): CarbonImmutable
    {
        [$hour, $minute] = array_map(intval(...), explode(':', $slot->time));
        $local = $from->setTimezone($slot->timezone);
        $start = $local->setISODate($local->isoWeekYear, $local->isoWeek, $slot->weekday)->setTime($hour, $minute);

        if ($start->addMinutes($slot->duration_minutes)->lte($local)) {
            $start = $start->addWeek()->setTime($hour, $minute);
        }

        return $start->utc();
    }

    /**
     * Makes the events of every active slot that start within the horizon.
     *
     * @return int the number of new events
     */
    public function schedule(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $until = $now->addDays(self::HORIZON_DAYS);
        $created = 0;

        foreach (WeeklySlot::query()->where('active', true)->get() as $slot) {
            $start = $this->nextStart($slot, $now);

            while ($start->lt($until)) {
                $created += SlotEvent::query()->insertOrIgnore([
                    'weekly_slot_id' => $slot->id,
                    'starts_at' => $start,
                    'ends_at' => $start->addMinutes($slot->duration_minutes),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                [$hour, $minute] = array_map(intval(...), explode(':', $slot->time));
                $start = $start->setTimezone($slot->timezone)->addWeek()->setTime($hour, $minute)->utc();
            }
        }

        return $created;
    }

    /**
     * Events that have not ended yet, soonest first, of slots still active.
     *
     * @return Collection<int, SlotEvent>
     */
    public function upcoming(int $limit = 4): Collection
    {
        return SlotEvent::query()
            ->with('slot')
            ->whereHas('slot', fn ($query) => $query->where('active', true))
            ->where('ends_at', '>', now())
            ->orderBy('starts_at')
            ->limit($limit)
            ->get();
    }
}
