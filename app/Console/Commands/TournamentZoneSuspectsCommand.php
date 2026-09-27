<?php

namespace App\Console\Commands;

use App\Models\Tournament;
use App\Models\TournamentModerationEntry;
use App\Support\LeagueTime;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Lists tournaments whose start may have been stored under the old create
 * page bug (fixed in 8db880c, 2026-09-27 09:18 UTC): the page read the typed
 * date and time as UTC, so "19:00" meant for Berlin was stored as 19:00 UTC
 * and shows as 21:00 MESZ. Read-only: it changes nothing, and says per
 * tournament what the start would be if the typed digits were meant for the
 * league's zone. Deciding and correcting is left to a person.
 *
 * A suspect was created before the fix went live and its start was never
 * saved again through the edit page (which always read the league's zone).
 * A round stored UTC hour is the typical trace of a typed round local hour.
 */
#[Signature('tournaments:zone-suspects
    {--fixed-at=2026-09-27 09:18:06 : When the fix went live, UTC (default: the commit time; pass the deploy time)}')]
#[Description('List tournaments whose start may have been read as UTC by the old create page (read-only)')]
class TournamentZoneSuspectsCommand extends Command
{
    public function handle(): int
    {
        $fixedAt = CarbonImmutable::parse((string) $this->option('fixed-at'), 'UTC');
        $rows = [];

        foreach (Tournament::query()->where('created_at', '<', $fixedAt)->orderBy('id')->get() as $tournament) {
            $reSaved = TournamentModerationEntry::query()->where('tournament_id', $tournament->id)->where('action', 'edited')->get()
                ->contains(fn (TournamentModerationEntry $entry): bool => array_key_exists('starts_at', $entry->details ?? []));

            if ($reSaved) {
                continue;
            }

            $stored = $tournament->starts_at->toImmutable()->utc();
            // The digits the organizer typed, had the page read them as UTC, and their meaning in the league's zone.
            $typed = $stored->format(LeagueTime::INPUT);
            $meant = LeagueTime::candidates($typed)[0] ?? null;

            $rows[] = [
                $tournament->id,
                mb_strimwidth($tournament->name, 0, 32, '…'),
                $tournament->status->value,
                $tournament->published_at === null ? 'no' : 'yes',
                $tournament->created_at?->utc()->format('Y-m-d H:i'),
                $stored->format('Y-m-d H:i').' UTC',
                LeagueTime::stamp($stored),
                $stored->minute === 0 ? 'round hour' : 'odd minute',
                $meant === null ? '—' : $meant->format('Y-m-d H:i').' UTC',
            ];
        }

        $this->line('Read-only. Nothing is changed. Fix live since '.$fixedAt->format('Y-m-d H:i').' UTC; zone '.LeagueTime::zone().'.');

        if ($rows === []) {
            $this->info('No suspect: every tournament was created after the fix or had its start saved again on the edit page.');

            return self::SUCCESS;
        }

        $this->table(['id', 'name', 'status', 'published', 'created (UTC)', 'stored start', 'shown now', 'trace', 'if typed for '.LeagueTime::city()], $rows);
        $this->line(count($rows).' suspect(s). A published one also carries its start in the Nostr calendar event: correcting it means saving it on the edit page, which republishes.');

        return self::SUCCESS;
    }
}
