<?php

namespace App\Support\Tournaments;

use App\Enums\TournamentStatus;
use App\Models\Tournament;
use App\Models\TournamentSignup;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The casual cups on the board (<x-tournaments.cup-mentions>, P53): open for
 * sign-up or running, grouped by game in the league's game order, each game's
 * regions in config order (EU, then US). Every cup carries what its row shows:
 * the region, the places as numbers for the seat bar, and its start as a day,
 * a clock and a city, so the start reads at a glance (user, 2026-09-28: the
 * cups were "zu textlich" and lay "lose durcheinander").
 *
 * The start is in the viewer's own zone when they set one. Without one it is in
 * the cup's region zone (Berlin for EU, New York for US): the evening the cup
 * was planned for, named by its city, never a bare UTC offset. The browser then
 * rewrites it to its own zone, unless that zone is one a privacy browser
 * reports instead of the real one (resources/js/tournamentLanding.js).
 *
 * P3 of plan mempool-streifen (user, 2026-09-29: the cups "gehen ... total
 * unter" and nobody reads them as tournaments): each row also carries the
 * faces of who signed up and whether the viewer is in, read for every cup in
 * one query (the page's query count stays flat however many cups and players),
 * and the board names the next cup to sign up for and the last cup's winner.
 */
final class CupBoard
{
    /** Per-locale patterns of the day and the clock (Carbon isoFormat); English is the fallback. */
    private const PATTERNS = [
        'de' => ['day' => 'dd, D. MMM', 'clock' => 'HH:mm'],
        'en' => ['day' => 'ddd, MMM D', 'clock' => 'h:mm A'],
    ];

    /**
     * @return list<array{game: string, cups: list<array{tournament: Tournament, region: ?string, regionLabel: ?string, taken: int, places: int, free: bool, faces: list<User>, entered: bool, zone: string, fixedZone: bool, day: string, clock: string, city: string}>}>
     */
    public function groups(?string $game = null, ?int $except = null, ?string $viewerZone = null, ?int $viewerId = null): array
    {
        $cups = Tournament::query()->casualCup()
            ->whereIn('status', [TournamentStatus::Signup, TournamentStatus::Running])
            ->when($game !== null, fn ($query) => $query->where('game', $game))
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except))
            ->orderBy('starts_at')->orderBy('id')
            ->limit(64)->get();

        // Every cup's active sign-ups in one query, with their players: the places and the faces come from here.
        $signups = $cups->isEmpty() ? new Collection
            : TournamentSignup::query()->whereIn('tournament_id', $cups->modelKeys())->active()->with('user')->orderBy('id')->get()->groupBy('tournament_id');
        $gameOrder = array_flip(CasualCups::enabledGames());
        $regionOrder = array_flip(array_keys(CasualCups::regions()));

        return array_values($cups->groupBy('game')
            ->sortBy(fn (Collection $group, string $slug): string => sprintf('%03d%s', $gameOrder[$slug] ?? 999, $slug))
            ->map(fn (Collection $group, string $slug): array => [
                'game' => $slug,
                'cups' => array_values($group
                    ->sortBy(fn (Tournament $cup): string => sprintf('%03d%012d', $regionOrder[CasualCups::regionOf($cup) ?? ''] ?? 999, $cup->starts_at->getTimestamp()))
                    ->map(fn (Tournament $cup): array => $this->row($cup, $viewerZone, array_values($signups->get($cup->id, new Collection)->all()), $viewerId))
                    ->all()),
            ])
            ->all());
    }

    /**
     * One cup as its row shows it, from its active sign-ups with their players (read for every cup at once).
     *
     * @param  list<TournamentSignup>  $signups
     * @return array{tournament: Tournament, region: ?string, regionLabel: ?string, taken: int, places: int, free: bool, faces: list<User>, entered: bool, zone: string, fixedZone: bool, day: string, clock: string, city: string}
     */
    private function row(Tournament $cup, ?string $viewerZone, array $signups, ?int $viewerId): array
    {
        $size = $cup->teamSize();
        $lineups = count(array_filter($signups, fn (TournamentSignup $signup): bool => $signup->lineup_id !== null));
        $taken = $lineups * $size + (count($signups) - $lineups);
        $places = $cup->capacity * $size;
        $zone = $viewerZone ?? CasualCups::timezoneOf($cup);
        $start = self::start($cup->starts_at, $zone);

        return [
            'tournament' => $cup,
            'region' => CasualCups::regionOf($cup),
            'regionLabel' => CasualCups::regionLabel($cup),
            'taken' => $taken,
            'places' => $places,
            'free' => $cup->status === TournamentStatus::Signup && $taken < $places,
            // The players who signed up solo, first come first; a lineup has no single face here.
            'faces' => array_values(array_filter(array_map(fn (TournamentSignup $signup): ?User => $signup->lineup_id === null ? $signup->user : null, $signups))),
            'entered' => $viewerId !== null && array_any($signups, fn (TournamentSignup $signup): bool => $signup->user_id === $viewerId || in_array($viewerId, $signup->members ?? [], true)),
            'zone' => $zone,
            'fixedZone' => $viewerZone !== null,
            ...$start,
        ];
    }

    /**
     * The cup to sign up for next: of the board's rows, the one open for sign-up with a free place that starts
     * first (the board's order breaks a tie). Not one whose start has passed: sign-up closes at the start, even
     * before the league's clock moves it on. Null when no cup takes players now.
     *
     * @param  list<array{game: string, cups: list<array<string, mixed>>}>  $groups
     * @return array<string, mixed>|null
     */
    public static function next(array $groups): ?array
    {
        $next = null;

        foreach ($groups as $group) {
            foreach ($group['cups'] as $cup) {
                if ($cup['free'] && $cup['tournament']->starts_at->isFuture() && ($next === null || $cup['tournament']->starts_at->lt($next['tournament']->starts_at))) {
                    $next = $cup;
                }
            }
        }

        return $next;
    }

    /**
     * The winner of the casual cup that finished last, for the board's proud moment: the cup and its champion
     * (TournamentChampion reads it from the bracket). Null before the first cup ends, or when the last one has no
     * single winner; never an older cup instead, so the query count stays the same.
     *
     * @return array{cup: Tournament, user: User|null, name: string}|null
     */
    public function lastWinner(): ?array
    {
        $cup = Tournament::query()->casualCup()->where('status', TournamentStatus::Finished)
            ->orderByDesc('cup_ended_at')->orderByDesc('id')->first();
        $champion = $cup === null ? null : app(TournamentChampion::class)->of($cup);

        if ($cup === null || $champion === null) {
            return null;
        }

        $user = $champion->lineup_id === null && $champion->user_id !== null ? User::query()->find($champion->user_id) : null;

        return ['cup' => $cup, 'user' => $user, 'name' => $user?->displayName() ?? $champion->name];
    }

    /**
     * A moment as the board shows it: "Sat, Oct 10", "8:00 PM", "New York".
     *
     * @return array{day: string, clock: string, city: string}
     */
    public static function start(CarbonInterface $at, string $zone): array
    {
        $locale = app()->getLocale();
        $pattern = self::PATTERNS[$locale] ?? self::PATTERNS['en'];
        $local = $at->toImmutable()->setTimezone($zone)->settings(['locale' => $locale]);

        return ['day' => $local->isoFormat($pattern['day']), 'clock' => $local->isoFormat($pattern['clock']), 'city' => self::city($zone)];
    }

    /** "New York" for America/New_York, translated where the app has the city; the zone itself when it names no city. */
    public static function city(string $zone): string
    {
        $city = str_contains($zone, '/') ? substr($zone, (int) strrpos($zone, '/') + 1) : $zone;

        return __(str_replace('_', ' ', $city));
    }
}
