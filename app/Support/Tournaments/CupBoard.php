<?php

namespace App\Support\Tournaments;

use App\Enums\TournamentStatus;
use App\Models\Tournament;
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
 */
final class CupBoard
{
    /** Per-locale patterns of the day and the clock (Carbon isoFormat); English is the fallback. */
    private const PATTERNS = [
        'de' => ['day' => 'dd, D. MMM', 'clock' => 'HH:mm'],
        'en' => ['day' => 'ddd, MMM D', 'clock' => 'h:mm A'],
    ];

    public function __construct(private TournamentSignups $signups) {}

    /**
     * @return list<array{game: string, cups: list<array{tournament: Tournament, region: ?string, regionLabel: ?string, taken: int, places: int, free: bool, zone: string, fixedZone: bool, day: string, clock: string, city: string}>}>
     */
    public function groups(?string $game = null, ?int $except = null, ?string $viewerZone = null): array
    {
        $cups = Tournament::query()->casualCup()
            ->whereIn('status', [TournamentStatus::Signup, TournamentStatus::Running])
            ->when($game !== null, fn ($query) => $query->where('game', $game))
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except))
            ->orderBy('starts_at')->orderBy('id')
            ->limit(64)->get();

        $gameOrder = array_flip(CasualCups::enabledGames());
        $regionOrder = array_flip(array_keys(CasualCups::regions()));

        return array_values($cups->groupBy('game')
            ->sortBy(fn (Collection $group, string $slug): string => sprintf('%03d%s', $gameOrder[$slug] ?? 999, $slug))
            ->map(fn (Collection $group, string $slug): array => [
                'game' => $slug,
                'cups' => array_values($group
                    ->sortBy(fn (Tournament $cup): string => sprintf('%03d%012d', $regionOrder[CasualCups::regionOf($cup) ?? ''] ?? 999, $cup->starts_at->getTimestamp()))
                    ->map(fn (Tournament $cup): array => $this->row($cup, $viewerZone))
                    ->all()),
            ])
            ->all());
    }

    /**
     * @return array{tournament: Tournament, region: ?string, regionLabel: ?string, taken: int, places: int, free: bool, zone: string, fixedZone: bool, day: string, clock: string, city: string}
     */
    public function row(Tournament $cup, ?string $viewerZone = null): array
    {
        $places = $this->signups->places($cup);
        $zone = $viewerZone ?? CasualCups::timezoneOf($cup);
        $start = self::start($cup->starts_at, $zone);

        return [
            'tournament' => $cup,
            'region' => CasualCups::regionOf($cup),
            'regionLabel' => CasualCups::regionLabel($cup),
            'taken' => $places['taken'],
            'places' => $places['places'],
            'free' => $cup->status === TournamentStatus::Signup && $places['taken'] < $places['places'],
            'zone' => $zone,
            'fixedZone' => $viewerZone !== null,
            ...$start,
        ];
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
