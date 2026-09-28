<?php

namespace App\Support\Tournaments;

use App\Enums\TournamentStatus;
use App\Models\Tournament;
use App\Models\TournamentSignup;
use App\Support\LeagueTime;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The tournaments an organizer or an admin set up by hand (every one but the
 * casual cups), as the cards at the top of the tournaments page (user,
 * 2026-09-28: "alle manuell angelegten Turniere sind die WICHTIGSTEN. Die
 * müssen alle mit Bildern oben groß und nicht unten klein Listen!").
 *
 * Three groups, in this order, none of them capped below the page's own
 * limit: open for sign-up (the soonest start first), in progress (sign-up
 * closed, the draw pending or running; the soonest start first) and past
 * (finished or called off; the latest start first). Drafts never show.
 * `except` leaves out the tournament the page already shows as its hero.
 *
 * Each card carries its places and its start as day, clock and city, in the
 * viewer's own zone when they set one, else in the league's zone for the
 * browser to rewrite (the cup board's rule, {@see CupBoard}). A start in
 * another year than now names the year.
 *
 * @phpstan-type Card array{tournament: Tournament, state: string, taken: int, places: int, zone: string, fixedZone: bool, day: string, clock: string, city: string}
 */
final class OrganizerBoard
{
    public const LIMIT = 100;

    /** The groups in page order. */
    public const GROUPS = ['open', 'progress', 'past'];

    /**
     * @return array{open: list<Card>, progress: list<Card>, past: list<Card>}
     */
    public function groups(?int $except = null, ?string $viewerZone = null): array
    {
        $tournaments = Tournament::query()->special()
            ->where('status', '!=', TournamentStatus::Draft)
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except))
            ->orderByRaw("case status when 'signup' then 0 when 'drawing' then 1 when 'running' then 1 else 2 end")
            ->orderByDesc('starts_at')->orderByDesc('id')
            ->limit(self::LIMIT)->get();

        $counts = $this->signupCounts(array_values($tournaments->modelKeys()));
        $groups = ['open' => [], 'progress' => [], 'past' => []];

        foreach ($tournaments as $tournament) {
            $state = self::state($tournament);
            $count = $counts[$tournament->id] ?? ['lineups' => 0, 'solos' => 0];
            $size = $tournament->teamSize();
            $zone = $viewerZone ?? LeagueTime::zone();

            $groups[self::group($state)][] = [
                'tournament' => $tournament,
                'state' => $state,
                'taken' => $count['lineups'] * $size + $count['solos'],
                'places' => $tournament->capacity * $size,
                'zone' => $zone,
                'fixedZone' => $viewerZone !== null,
                ...self::start($tournament->starts_at, $zone),
            ];
        }

        $byStart = fn (array $a, array $b): int => [$a['tournament']->starts_at->getTimestamp(), $a['tournament']->id] <=> [$b['tournament']->starts_at->getTimestamp(), $b['tournament']->id];
        usort($groups['open'], $byStart);
        usort($groups['progress'], $byStart);
        usort($groups['past'], fn (array $a, array $b): int => $byStart($b, $a));

        return $groups;
    }

    /**
     * What the card says about where the tournament stands: `open` (sign-up
     * open), `closed` (sign-up over, not drawn yet), `drawing`, `running`,
     * `paused`, `finished` or `cancelled`.
     */
    public static function state(Tournament $tournament): string
    {
        return match ($tournament->status) {
            TournamentStatus::Signup => $tournament->isSignupOpen() ? 'open' : 'closed',
            TournamentStatus::Drawing => 'drawing',
            TournamentStatus::Running => $tournament->isPaused() ? 'paused' : 'running',
            TournamentStatus::Cancelled => 'cancelled',
            default => 'finished',
        };
    }

    public static function group(string $state): string
    {
        return match ($state) {
            'open' => 'open',
            'finished', 'cancelled' => 'past',
            default => 'progress',
        };
    }

    /**
     * "Sat, Oct 3", "6:00 PM", "Berlin"; "Sat, Oct 4, 2025" in another year.
     *
     * @return array{day: string, clock: string, city: string}
     */
    public static function start(CarbonInterface $at, string $zone): array
    {
        $start = CupBoard::start($at, $zone);
        $local = $at->toImmutable()->setTimezone($zone);

        if ($local->year !== now()->setTimezone($zone)->year) {
            $start['day'] .= app()->getLocale() === 'de' ? ' '.$local->year : ', '.$local->year;
        }

        return $start;
    }

    /**
     * Active sign-ups per tournament in one query: lineups and solo players.
     *
     * @param  list<int|string>  $ids
     * @return array<int, array{lineups: int, solos: int}>
     */
    private function signupCounts(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return TournamentSignup::query()->active()->whereIn('tournament_id', $ids)
            ->groupBy('tournament_id')
            ->select('tournament_id', DB::raw('sum(case when lineup_id is null then 0 else 1 end) as lineups'), DB::raw('sum(case when lineup_id is null then 1 else 0 end) as solos'))
            ->get()
            ->mapWithKeys(fn (TournamentSignup $row): array => [(int) $row->getAttribute('tournament_id') => ['lineups' => (int) $row->getAttribute('lineups'), 'solos' => (int) $row->getAttribute('solos')]])
            ->all();
    }
}
