<?php

namespace App\Support\Tournaments;

use App\Enums\TournamentFormat;
use App\Enums\TournamentStatus;
use App\Games\Blockfill;
use App\Games\GameRegistry;
use App\Games\TrackmaniaNationsForever;
use App\Models\Tournament;
use App\Support\Engagement\HomeBoard;
use App\Support\GameNames;
use App\Support\LeagueTime;
use App\Support\Prizes\PrizeChips;
use App\Support\Scores\ScoreWindow;
use App\Support\Stacker\BlockfillRules;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Route;

/**
 * The tournaments page as the board Tournaments.dc.html draws it (plan "Refactor und Design-Revamp", P6, rules R8
 * and R9): the featured tournament (an organizer's or one with a pot, open for sign-up, the soonest to close), the
 * table of everything open for sign-up (tournaments with a pot or an organizer first, then the casual cups by start)
 * and the table of what runs now (brackets, draws and the league's weekly leaderboards). Finished and called-off
 * tournaments live on the archive (ArchiveBoard), never here.
 *
 * Filters: one game, one cup region (EU/US; a tournament without a region stays, it is open to every region) and
 * free places only. The game chips count what this page lists per game, before the game filter.
 *
 * Read per request, never cached: the rows hold models (the prod cache unserializes no objects).
 *
 * @phpstan-type Row array{tournament: Tournament, key: string, name: string, sub: string, game: string, featured: bool, pot: int|null, region: string|null, regionKey: string|null, start: string, taken: int, places: int, free: bool, href: string, status: string|null, kind: string}
 */
final class TournamentsBoard
{
    /** @var array{open: list<Row>, running: list<Row>}|null */
    private ?array $rows = null;

    public function __construct(private ?string $viewerZone = null) {}

    /** The zone every time on the page is shown in: the viewer's own, else the league's. */
    public function zone(): string
    {
        return $this->viewerZone ?? LeagueTime::zone();
    }

    /** "CEST", "EDT": the abbreviation of that zone now, for "Zeiten in CEST" and the column head. */
    public function zoneLabel(): string
    {
        return now()->setTimezone($this->zone())->format('T');
    }

    /**
     * The featured tournament: of the open rows with a pot or an organizer, the one whose sign-up closes first.
     *
     * @return Row|null
     */
    public function featured(?string $game = null): ?array
    {
        $games = $game === null ? null : app(GameRegistry::class)->covered($game);
        $rows = array_values(array_filter($this->rows()['open'], fn (array $row): bool => $row['featured'] && ($games === null || in_array($row['game'], $games, true))));
        usort($rows, fn (array $a, array $b): int => [$a['tournament']->signup_closes_at?->getTimestamp() ?? PHP_INT_MAX, $a['tournament']->id]
            <=> [$b['tournament']->signup_closes_at?->getTimestamp() ?? PHP_INT_MAX, $b['tournament']->id]);

        return $rows[0] ?? null;
    }

    /**
     * @return list<Row>
     */
    public function open(?string $game = null, ?string $region = null, bool $freeOnly = false): array
    {
        return self::filter($this->rows()['open'], $game, $region, $freeOnly);
    }

    /**
     * @return list<Row>
     */
    public function running(?string $game = null, ?string $region = null): array
    {
        return self::filter($this->rows()['running'], $game, $region, false);
    }

    /**
     * The game chips: "Alle" with every listed tournament, then each registered game with its count, the biggest
     * first (registry order breaks a tie), zero counts last. A group of games is one chip, its home's
     * (GameRegistry::listed()): "Go" counts the tournaments of all three sizes.
     *
     * @return list<array{key: string, label: string, count: int}>
     */
    public function gameChips(): array
    {
        $rows = [...$this->rows()['open'], ...$this->rows()['running']];
        $registry = app(GameRegistry::class);
        $order = array_flip(array_keys($registry->all()));
        $chips = [];

        foreach (array_keys($registry->listed()) as $slug) {
            $covered = $registry->covered($slug);
            $chips[] = ['key' => $slug, 'label' => self::entryLabel($slug), 'count' => count(array_filter($rows, fn (array $row): bool => in_array($row['game'], $covered, true)))];
        }

        usort($chips, fn (array $a, array $b): int => [$b['count'], $order[$a['key']]] <=> [$a['count'], $order[$b['key']]]);

        return [['key' => 'all', 'label' => __('All'), 'count' => count($rows)], ...$chips];
    }

    /** A game's chip label: its short label with an edition number ("RL", "AoE2", "FC 27"), "Schach" for chess. */
    public static function chipLabel(string $slug): string
    {
        $name = GameNames::game($slug);
        $short = app(GameRegistry::class)->find($slug)?->assets()->shortLabel;

        if ($short === null || $short === '') {
            return $name;
        }

        // "FC27" reads "FC 27" as on the board; "AoE2" keeps its own spelling.
        $translated = __($short);
        $short = (string) preg_replace('/^([A-Z]{2,})(\d{2})$/', '$1 $2', is_string($translated) ? $translated : $short);

        return preg_match('/\s(\d+)$/', $name, $edition) === 1 && ! str_ends_with($short, $edition[1]) ? $short.' '.$edition[1] : $short;
    }

    /** The chip label of a game's list entry (GameRegistry::listed()): its group's name ("Go"), else chipLabel(). */
    public static function entryLabel(string $slug): string
    {
        return app(GameRegistry::class)->groupOf($slug) === null ? self::chipLabel($slug) : GameNames::listed($slug);
    }

    /**
     * A size of a group filters to the whole group (GameRegistry::covered()): the chip says "Go".
     *
     * @param  list<Row>  $rows
     * @return list<Row>
     */
    private static function filter(array $rows, ?string $game, ?string $region, bool $freeOnly): array
    {
        $games = $game === null ? null : app(GameRegistry::class)->covered($game);

        return array_values(array_filter($rows, fn (array $row): bool => ($games === null || in_array($row['game'], $games, true))
            && ($region === null || $row['regionKey'] === null || $row['regionKey'] === $region)
            && (! $freeOnly || $row['free'])));
    }

    /**
     * @return array{open: list<Row>, running: list<Row>}
     */
    private function rows(): array
    {
        if ($this->rows !== null) {
            return $this->rows;
        }

        $zone = $this->zone();
        $organizer = app(OrganizerBoard::class)->groups(viewerZone: $zone);
        $cups = array_merge(...array_map(fn (array $group): array => $group['cups'], app(CupBoard::class)->groups(null, null, $zone) ?: [['cups' => []]]));
        PrizeChips::prime([...array_column($organizer['open'], 'tournament'), ...array_column($organizer['progress'], 'tournament')]);

        $open = [];
        $running = [];

        foreach ([...$organizer['open'], ...$organizer['progress']] as $card) {
            /** @var Tournament $tournament */
            $tournament = $card['tournament'];
            $row = $this->row($tournament, 'special', null, null, (int) $card['taken'], (int) $card['places']);

            if ($card['state'] === 'open') {
                $open[] = $row;
            } else {
                $running[] = [...$row, 'status' => self::statusLabel($card['state'])];
            }
        }

        foreach ($cups as $cup) {
            /** @var Tournament $tournament */
            $tournament = $cup['tournament'];
            $row = $this->row($tournament, 'cup', $cup['regionLabel'], $cup['region'], (int) $cup['taken'], (int) $cup['places']);

            if ($tournament->status === TournamentStatus::Signup && $tournament->isSignupOpen()) {
                $open[] = $row;
            } else {
                $running[] = [...$row, 'status' => self::statusLabel(OrganizerBoard::state($tournament))];
            }
        }

        foreach ($this->leagueWeeks() as $week) {
            $running[] = $this->weekRow($week);
        }

        // Tournaments with a pot or an organizer first (the pot's size, then the start), then the cups by start.
        usort($open, fn (array $a, array $b): int => [$a['featured'] ? 0 : 1, -($a['pot'] ?? 0), $a['tournament']->starts_at->getTimestamp(), $a['tournament']->id]
            <=> [$b['featured'] ? 0 : 1, -($b['pot'] ?? 0), $b['tournament']->starts_at->getTimestamp(), $b['tournament']->id]);

        return $this->rows = ['open' => $open, 'running' => $running];
    }

    /**
     * @return Row
     */
    private function row(Tournament $tournament, string $kind, ?string $region, ?string $regionKey, int $taken, int $places): array
    {
        $pot = PrizeChips::of($tournament)['sats'] ?? null;

        return [
            'tournament' => $tournament,
            'key' => 't-'.$tournament->id,
            'name' => HomeBoard::shortName($tournament),
            'sub' => self::sub($tournament),
            'game' => $tournament->game,
            'featured' => $kind === 'special' || ($pot ?? 0) > 0,
            'pot' => $pot !== null && $pot > 0 ? $pot : null,
            'region' => $region,
            'regionKey' => $regionKey,
            'start' => TournamentStartVote::cardStart($tournament, self::dayClock($tournament->starts_at, $this->zone())),
            'taken' => $taken,
            'places' => $places,
            'free' => $taken < $places,
            'href' => route('tournaments.show', $tournament),
            'status' => null,
            'kind' => $kind,
        ];
    }

    /**
     * @return Row
     */
    private function weekRow(Tournament $week): array
    {
        $window = ScoreWindow::of($week);

        return [
            'tournament' => $week,
            'key' => 'w-'.$week->id,
            'name' => __(':game week :week', ['game' => GameNames::cube($week->game), 'week' => (int) $week->starts_at->toImmutable()->setTimezone($this->zone())->format('W')]),
            'sub' => $week->game === Blockfill::SLUG
                ? __('Best time over :blocks', ['blocks' => BlockfillRules::weekBlocks($week)])
                : __('Best time on the track of the week'),
            'game' => $week->game,
            'featured' => false,
            'pot' => null,
            'region' => null,
            'regionKey' => null,
            'start' => self::span($window->start, $window->end->subSecond(), $this->zone()),
            'taken' => 0,
            'places' => 0,
            'free' => false,
            'href' => route('scores.show', $week->game),
            'status' => __('Running'),
            'kind' => 'week',
        ];
    }

    /** The data line under a name: the mode and the format ("Fernpartie, Round Robin", "Blitz 5+3", "1v1"). */
    private static function sub(Tournament $tournament): string
    {
        // A lobby tournament names its game and "One lobby match", never "1v1" (Lobbies::gameLine()).
        if (Lobbies::isLobby($tournament)) {
            return Lobbies::gameLine($tournament).', '.Lobbies::formatLabel($tournament);
        }

        $mode = GameNames::mode($tournament->game, $tournament->mode);

        return $tournament->isCasualCup() && $tournament->status === TournamentStatus::Signup
            ? $mode
            : implode(', ', array_filter([$mode, Lobbies::formatLabel($tournament)]));
    }

    private static function statusLabel(string $state): string
    {
        return match ($state) {
            'closed' => __('Sign-up closed'),
            'drawing' => __('Drawing the bracket'),
            'paused' => __('Paused'),
            default => __('Running'),
        };
    }

    /** "Mi 14.10., 19:00" / "Wed 14 Oct, 7:00 PM" in the page's zone. */
    public static function dayClock(CarbonInterface $at, string $zone): string
    {
        $german = app()->getLocale() === 'de';

        return self::local($at, $zone)->isoFormat($german ? 'dd D.M., HH:mm' : 'ddd, MMM D, h:mm A');
    }

    /** The moment in the page's zone, speaking the app's language. */
    public static function local(CarbonInterface $at, string $zone): CarbonInterface
    {
        return self::localized($at->toImmutable()->setTimezone($zone));
    }

    private static function localized(CarbonInterface $at): CarbonInterface
    {
        return $at->locale(app()->getLocale());
    }

    /** "5. bis 11.10." / "5 to 11 Oct": a week's days. */
    public static function span(CarbonInterface $from, CarbonInterface $to, string $zone): string
    {
        $german = app()->getLocale() === 'de';
        $a = self::local($from, $zone);
        $b = self::local($to, $zone);
        $sameMonth = $a->month === $b->month;

        return __(':from to :to', [
            'from' => $german ? ($sameMonth ? $a->isoFormat('D.') : $a->isoFormat('D.M.')) : ($sameMonth ? $a->isoFormat('D') : $a->isoFormat('D MMM')),
            'to' => $german ? $b->isoFormat('D.M.') : $b->isoFormat('D MMM'),
        ]);
    }

    /**
     * The league weeks running now (Blockfill, TMNF), as the start page reads them (HomeBoard): a week whose window
     * holds the present, one per game.
     *
     * @return list<Tournament>
     */
    private function leagueWeeks(): array
    {
        $games = array_values(array_filter([Blockfill::SLUG, TrackmaniaNationsForever::SLUG], fn (string $slug): bool => app(GameRegistry::class)->find($slug) !== null));

        if ($games === [] || ! Route::has('scores.show')) {
            return [];
        }

        return array_values(Tournament::query()->whereNotNull('published_at')
            ->where(['format' => TournamentFormat::Leaderboard, 'status' => TournamentStatus::Running])
            ->whereIn('game', $games)->where('starts_at', '<=', now())->orderBy('starts_at')->limit(4)->get()
            ->filter(fn (Tournament $week): bool => $week->isLeagueWeek() && ScoreWindow::of($week)->contains(now()))
            ->unique('game')->all());
    }
}
