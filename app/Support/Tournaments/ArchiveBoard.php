<?php

namespace App\Support\Tournaments;

use App\Enums\PayoutStatus;
use App\Enums\TournamentStatus;
use App\Games\Blockfill;
use App\Games\GameRegistry;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\TournamentPayout;
use App\Models\User;
use App\Support\Engagement\HomeBoard;
use App\Support\GameNames;
use App\Support\LeagueTime;
use App\Support\Prizes\PrizeChips;
use App\Support\Scores\ScoreWindow;
use App\Support\Stacker\BlockfillRules;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Route;

/**
 * The tournament archive (Archive.dc.html, Archive-Phone.dc.html; plan "Refactor und Design-Revamp", P6): finished
 * and called-off tournaments, which the tournaments page no longer shows.
 *
 * - Hall of Fame: the three latest finished tournaments with a champion, those with a pot or an organizer first;
 *   each with its champion and the prize the league paid to place 1 (never the wallet's balance).
 * - Finished: every finished tournament, the league's weekly leaderboards included, newest first, paginated; a row
 *   names the champion by the entry's public name (the one the bracket shows, never the account name beside it).
 * - Called off: receded rows, newest first.
 *
 * `game` narrows every part. Read per request, never cached (rows hold models).
 *
 * @phpstan-type Champion array{name: string, user: User|null}
 * @phpstan-type Row array{tournament: Tournament, name: string, sub: string, date: string, places: string|null, pot: int|null, prize: int|null, champion: Champion|null, featured: bool, week: bool, href: string, results: string}
 */
final class ArchiveBoard
{
    public const PER_PAGE = 30;

    public const CANCELLED = 10;

    public function __construct(private ?string $game = null, private ?string $viewerZone = null) {}

    /**
     * @return list<Row>
     */
    public function hallOfFame(): array
    {
        $rows = [];
        $candidates = $this->finishedQuery()->exceptLeagueWeeks()->orderByRaw('case when cup_series is null then 0 else 1 end')
            ->orderByDesc('starts_at')->orderByDesc('id')->limit(12)->get();
        PrizeChips::prime($candidates);

        foreach ($candidates as $tournament) {
            $row = $this->row($tournament);

            if ($row['champion'] !== null) {
                $rows[] = $row;
            }

            if (count($rows) === 3) {
                break;
            }
        }

        // The pot and organizer tournaments lead; then the newest.
        usort($rows, fn (array $a, array $b): int => [$a['featured'] ? 0 : 1, -$a['tournament']->starts_at->getTimestamp()] <=> [$b['featured'] ? 0 : 1, -$b['tournament']->starts_at->getTimestamp()]);

        return $rows;
    }

    /**
     * @return LengthAwarePaginator<int, Row>
     */
    public function finished(int $page = 1): LengthAwarePaginator
    {
        $query = $this->finishedQuery()->orderByRaw('coalesce(cup_ended_at, starts_at) desc')->orderByDesc('id');
        $total = (clone $query)->count();
        $tournaments = $query->forPage($page, self::PER_PAGE)->get();
        PrizeChips::prime($tournaments);

        return new LengthAwarePaginator(array_map(fn (Tournament $tournament): array => $this->row($tournament), $tournaments->all()), $total, self::PER_PAGE, $page,
            ['path' => route('tournaments.archive'), 'query' => array_filter(['game' => $this->game])]);
    }

    /**
     * @return array{rows: list<array{tournament: Tournament, name: string, date: string, href: string}>, total: int}
     */
    public function cancelled(): array
    {
        $query = $this->base()->where('status', TournamentStatus::Cancelled);
        $total = (clone $query)->count();
        $rows = $query->orderByRaw('coalesce(cup_ended_at, starts_at) desc')->orderByDesc('id')->limit(self::CANCELLED)->get()
            ->map(fn (Tournament $tournament): array => [
                'tournament' => $tournament,
                'name' => $tournament->title(),
                'date' => $this->day($tournament->cup_ended_at ?? $tournament->starts_at),
                'href' => route('tournaments.show', $tournament),
            ])->all();

        return ['rows' => array_values($rows), 'total' => $total];
    }

    /**
     * Finished and called-off tournaments per game, before the game filter: "Alle" first, then by count.
     * A group of games is one chip, as on the tournaments page: "Go" counts all three sizes.
     *
     * @return list<array{key: string, label: string, count: int}>
     */
    public function gameChips(): array
    {
        $counts = Tournament::query()->where('status', '!=', TournamentStatus::Draft)->whereNotNull('published_at')
            ->whereIn('status', [TournamentStatus::Finished, TournamentStatus::Cancelled])
            ->groupBy('game')->selectRaw('game, count(*) as total')->pluck('total', 'game')->map(fn (mixed $count): int => (int) $count)->all();
        $registry = app(GameRegistry::class);
        $order = array_flip(array_keys($registry->all()));
        $chips = [];

        foreach (array_keys($registry->listed()) as $slug) {
            $label = $registry->groupOf($slug) === null ? TournamentsBoard::chipLabel($slug) : GameNames::listed($slug);
            $chips[] = ['key' => $slug, 'label' => $label, 'count' => array_sum(array_intersect_key($counts, array_flip($registry->covered($slug))))];
        }

        usort($chips, fn (array $a, array $b): int => [$b['count'], $order[$a['key']]] <=> [$a['count'], $order[$b['key']]]);

        return [['key' => 'all', 'label' => __('All'), 'count' => array_sum(array_intersect_key($counts, $order))], ...$chips];
    }

    /**
     * @return Builder<Tournament>
     */
    private function base(): Builder
    {
        return Tournament::query()->whereNotNull('published_at')
            ->when($this->game !== null, fn (Builder $query) => $query->whereIn('game', app(GameRegistry::class)->covered($this->game)));
    }

    /**
     * @return Builder<Tournament>
     */
    private function finishedQuery(): Builder
    {
        return $this->base()->where('status', TournamentStatus::Finished);
    }

    /**
     * @return Row
     */
    private function row(Tournament $tournament): array
    {
        $week = $tournament->isLeagueWeek();
        $pot = $week ? null : (PrizeChips::of($tournament)['sats'] ?? null);
        $champion = $week ? null : self::champion($tournament);
        $prize = $champion === null ? null : (int) TournamentPayout::query()->where('tournament_id', $tournament->id)->where('place', 1)->where('status', PayoutStatus::Paid)->sum('amount_sats');
        $window = $week ? ScoreWindow::of($tournament) : null;
        $entries = $week ? null : TournamentParticipant::query()->where('tournament_id', $tournament->id)->count();

        return [
            'tournament' => $tournament,
            'name' => $week ? __(':game week :week', ['game' => GameNames::cube($tournament->game), 'week' => (int) $tournament->starts_at->toImmutable()->setTimezone($this->zone())->format('W')]) : $tournament->name,
            'sub' => match (true) {
                $week && $tournament->game === Blockfill::SLUG => __('Best time, :blocks', ['blocks' => BlockfillRules::weekBlocks($tournament)]),
                $week => __('Best time, track of the week'),
                default => implode(', ', array_filter([Lobbies::gameLine($tournament), Lobbies::formatLabel($tournament)])),
            },
            'date' => $window !== null ? TournamentsBoard::span($window->start, $window->end->subSecond(), $this->zone()) : $this->day($tournament->cup_ended_at ?? $tournament->starts_at),
            'places' => $entries === null || $entries === 0 ? null : $entries.'/'.max($entries, $tournament->capacity),
            'pot' => $pot !== null && $pot > 0 ? $pot : null,
            'prize' => $prize !== null && $prize > 0 ? $prize : null,
            'champion' => $champion,
            'featured' => ! $tournament->isCasualCup() && ! $week || ($pot ?? 0) > 0,
            'week' => $week,
            'href' => route('tournaments.show', $tournament),
            'results' => $week && Route::has('tournaments.scores') ? route('tournaments.scores', $tournament) : route('tournaments.show', $tournament),
        ];
    }

    /**
     * The champion by the entry's public name: the name the bracket shows. The account is only for the picture,
     * and only for a solo entry (a lineup or mix team has no single face).
     *
     * @return Champion|null
     */
    private static function champion(Tournament $tournament): ?array
    {
        $winner = app(TournamentChampion::class)->of($tournament);

        if ($winner === null) {
            return null;
        }

        $user = $winner->lineup_id === null && ! $winner->isMixTeam() && $winner->user_id !== null ? User::query()->find($winner->user_id) : null;

        return ['name' => $winner->name, 'user' => $user];
    }

    private function zone(): string
    {
        return $this->viewerZone ?? LeagueTime::zone();
    }

    /** "So 4.10." / "Sun 4 Oct"; with the year when it is not this year's. */
    private function day(CarbonInterface $at): string
    {
        $local = TournamentsBoard::local($at, $this->zone());
        $german = app()->getLocale() === 'de';
        $thisYear = $local->year === now()->setTimezone($this->zone())->year;

        return $local->isoFormat($german ? ($thisYear ? 'dd D.M.' : 'dd D.M.YYYY') : ($thisYear ? 'ddd D MMM' : 'ddd D MMM YYYY'));
    }

    /** "14.568" / "14,568". */
    public static function sats(int $value): string
    {
        return HomeBoard::sats($value);
    }
}
