<?php

namespace App\Support\Payouts;

use App\Enums\PayoutStatus;
use App\Enums\TournamentStatus;
use App\Models\Tournament;
use App\Support\Prizes\PrizeChips;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The overview of the admin payouts page: every tournament with a pot, each
 * with one payout state read from its real data, so an admin sees where
 * money has to go out. Read-only: nothing here approves, pays or books.
 *
 * The state of a tournament, the first rule that applies:
 *
 * - `unchecked`: finished, the admin check is still open (`payouts_approved_at`
 *   null; the only status {@see PayoutApproval::blocker()} lets through);
 * - `not_due`: not checked and not finished (draft, sign-up, draw, running,
 *   called off): nothing can be paid;
 * - `needs_check`: a payout is `paying` with reason `needs_check`: its outcome
 *   is unclear, an admin has to look into the wallet (PayoutRunner);
 * - `failed`: a payout failed and can be retried;
 * - `ready`: a payout is `pending`, ready to pay;
 * - `open`: a payout is `open`: the winner has no checked Lightning address;
 * - `paying`: a payment is on its way;
 * - `paid`: every payout is paid or passed on (or the check wrote none).
 *
 * Waiting for an admin: `needs_check`, `failed`, `ready`, `unchecked` and
 * `open`, listed first in that order. The state is one SQL expression of
 * plain CASE and EXISTS over existing columns, the same on PostgreSQL and
 * SQLite; counts group it in a derived table, never by an alias.
 */
final class PayoutOverview
{
    public const PER_PAGE = 12;

    public const UNCHECKED = 'unchecked';

    public const NOT_DUE = 'not_due';

    public const NEEDS_CHECK = 'needs_check';

    public const FAILED = 'failed';

    public const READY = 'ready';

    public const OPEN = 'open';

    public const PAYING = 'paying';

    public const PAID = 'paid';

    /**
     * Every state, most urgent first: the order of the list.
     *
     * @var list<string>
     */
    public const STATES = [self::NEEDS_CHECK, self::FAILED, self::READY, self::UNCHECKED, self::OPEN, self::PAYING, self::NOT_DUE, self::PAID];

    /**
     * The states that wait for an admin.
     *
     * @var list<string>
     */
    public const WAITING = [self::NEEDS_CHECK, self::FAILED, self::READY, self::UNCHECKED, self::OPEN];

    /** The filter that shows every tournament, and the one that shows what waits. */
    public const FILTER_ALL = 'all';

    public const FILTER_WAITING = 'waiting';

    public function __construct(private string $search = '', private string $game = '') {}

    /**
     * Tournaments with a pot (the league wallet's, or a legacy own wallet's).
     *
     * @return Builder<Tournament>
     */
    public static function base(): Builder
    {
        return Tournament::query()->whereNotNull('tournaments.pool_opened_at')->whereIn('tournaments.pot_source', [Tournament::POT_LEAGUE, Tournament::POT_WALLET]);
    }

    /** Whether `$filter` is a filter of the overview. */
    public static function isFilter(string $filter): bool
    {
        return in_array($filter, [self::FILTER_ALL, self::FILTER_WAITING, ...self::STATES], true);
    }

    /** Whether a tournament in this state waits for an admin. */
    public static function waits(string $state): bool
    {
        return in_array($state, self::WAITING, true);
    }

    public static function label(string $state): string
    {
        return match ($state) {
            self::FILTER_ALL => __('All'),
            self::FILTER_WAITING => __('Waiting'),
            self::UNCHECKED => __('Check open'),
            self::NOT_DUE => __('Not due'),
            self::NEEDS_CHECK => __('Look into the wallet'),
            self::FAILED => __('Failed'),
            self::READY => __('Ready to pay'),
            self::OPEN => __('Address missing'),
            self::PAYING => __('Paying'),
            default => __('Paid'),
        };
    }

    /** The tag's tone (`rv-tag` modifier): red for what went wrong, orange for what waits, green for done. */
    public static function tone(string $state): string
    {
        return match ($state) {
            self::NEEDS_CHECK, self::FAILED => 'is-bad',
            self::READY, self::UNCHECKED, self::OPEN, self::PAYING => 'is-btc',
            self::PAID => 'is-ok',
            default => '',
        };
    }

    /** The icon beside the label, so the colour is never the only sign. */
    public static function icon(string $state): ?string
    {
        return match ($state) {
            self::NEEDS_CHECK, self::FAILED => 'warn',
            self::READY => 'bolt',
            self::UNCHECKED => 'eye',
            self::OPEN => 'user',
            self::PAYING => 'clock',
            self::PAID => 'check',
            default => null,
        };
    }

    /** How many tournaments wait for an admin, whatever the page filters. */
    public static function waitingTotal(): int
    {
        return self::whereState(self::base(), self::FILTER_WAITING)->count();
    }

    /** The payout state of one tournament with a pot, null without one. */
    public static function stateOf(int $tournamentId): ?string
    {
        $state = self::base()->whereKey($tournamentId)->selectRaw(self::stateSql().' as payout_state')->value('payout_state');

        return is_string($state) ? $state : null;
    }

    /** The most urgent waiting tournament other than `$except`, for "next" on the detail. */
    public static function nextWaiting(?int $except = null): ?Tournament
    {
        return self::ordered(self::whereState(self::base(), self::FILTER_WAITING))
            ->when($except !== null, fn (Builder $query) => $query->whereKeyNot($except))->first();
    }

    /**
     * How many tournaments each state filter shows, under the search and the game: `all`, `waiting` and every state.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        $inner = $this->narrowed()->selectRaw(self::stateSql().' as payout_state');
        /** @var array<string, int> $byState */
        $byState = DB::query()->fromSub($inner, 'overview')->groupBy('payout_state')->selectRaw('payout_state, count(*) as total')
            ->pluck('total', 'payout_state')->map(fn (mixed $total): int => (int) $total)->all();

        $counts = [self::FILTER_ALL => array_sum($byState), self::FILTER_WAITING => 0];

        foreach (self::STATES as $state) {
            $counts[$state] = $byState[$state] ?? 0;
            $counts[self::FILTER_WAITING] += self::waits($state) ? $counts[$state] : 0;
        }

        return $counts;
    }

    /**
     * How many tournaments each game has, under the search and the state filter, the most first.
     *
     * @return array<string, int>
     */
    public function games(string $filter): array
    {
        /** @var array<string, int> $games */
        $games = self::whereState($this->searched(), $filter)->groupBy('tournaments.game')->selectRaw('tournaments.game as game, count(*) as total')
            ->pluck('total', 'game')->map(fn (mixed $total): int => (int) $total)->all();
        arsort($games);

        return $games;
    }

    /** Every game a tournament with a pot is played in, whatever the page filters. */
    public static function gameCount(): int
    {
        return self::base()->distinct()->count('tournaments.game');
    }

    /**
     * One page of the list, what waits first: each tournament with `payout_state`, `payouts_count`,
     * `unpaid_count`, `unpaid_sats`, `paid_count`, `forwarded_count` and `matches_max_updated_at`, its pot primed
     * for {@see PrizeChips::of()}. A handful of queries for any number of rows.
     *
     * @return LengthAwarePaginator<int, Tournament>
     */
    public function page(string $filter, int $page): LengthAwarePaginator
    {
        $query = self::whereState($this->narrowed(), $filter);
        $total = (clone $query)->count();
        $page = max(1, min($page, (int) ceil($total / self::PER_PAGE)));
        $unpaid = [PayoutStatus::Open, PayoutStatus::Pending, PayoutStatus::Paying, PayoutStatus::Failed];

        $tournaments = self::ordered($query)->select('tournaments.*')->selectRaw(self::stateSql().' as payout_state')
            ->withCount([
                'payouts',
                'payouts as unpaid_count' => fn (Builder $payouts) => $payouts->whereIn('status', $unpaid),
                'payouts as paid_count' => fn (Builder $payouts) => $payouts->where('status', PayoutStatus::Paid),
                'payouts as forwarded_count' => fn (Builder $payouts) => $payouts->where('status', PayoutStatus::Forwarded),
            ])
            ->withSum(['payouts as unpaid_sats' => fn (Builder $payouts) => $payouts->whereIn('status', $unpaid)], 'amount_sats')
            ->withMax('matches', 'updated_at')
            ->forPage($page, self::PER_PAGE)->get();

        PrizeChips::prime($tournaments);

        return new LengthAwarePaginator($tournaments, $total, self::PER_PAGE, $page);
    }

    /**
     * When a finished tournament of {@see page()} ended: its last result, else a casual cup's end or its last update
     * (TournamentLiveSlides::finishedAt(), read with the page instead of once per row). Null while it is not finished.
     */
    public static function finishedAt(Tournament $tournament): ?CarbonInterface
    {
        if ($tournament->status !== TournamentStatus::Finished) {
            return null;
        }

        $last = $tournament->getAttribute('matches_max_updated_at');

        return $last === null ? ($tournament->cup_ended_at ?? $tournament->updated_at) : Carbon::parse((string) $last);
    }

    /** A payout of the tournament in the status that follows; closed by `')'`. */
    private const HAS = "exists (select 1 from tournament_payouts where tournament_payouts.tournament_id = tournaments.id and tournament_payouts.status = '";

    /**
     * The payout state as one SQL expression over `tournaments` (see the class comment). Only enum values and
     * constants are written into it, never input.
     *
     * @return literal-string
     */
    public static function stateSql(): string
    {
        return '(case'
            ." when tournaments.payouts_approved_at is null and tournaments.status = '".TournamentStatus::Finished->value."' then '".self::UNCHECKED."'"
            ." when tournaments.payouts_approved_at is null then '".self::NOT_DUE."'"
            .' when '.self::HAS.PayoutStatus::Paying->value."' and tournament_payouts.reason = 'needs_check') then '".self::NEEDS_CHECK."'"
            .' when '.self::HAS.PayoutStatus::Failed->value."') then '".self::FAILED."'"
            .' when '.self::HAS.PayoutStatus::Pending->value."') then '".self::READY."'"
            .' when '.self::HAS.PayoutStatus::Open->value."') then '".self::OPEN."'"
            .' when '.self::HAS.PayoutStatus::Paying->value."') then '".self::PAYING."'"
            ." else '".self::PAID."' end)";
    }

    /**
     * @param  Builder<Tournament>  $query
     * @return Builder<Tournament>
     */
    private static function whereState(Builder $query, string $filter): Builder
    {
        $states = match (true) {
            $filter === self::FILTER_WAITING => self::WAITING,
            in_array($filter, self::STATES, true) => [$filter],
            default => null,
        };

        return $states === null ? $query : $query->whereIn(DB::raw(self::stateSql()), $states);
    }

    /**
     * What waits first, most urgent state first, then the newest tournament.
     *
     * @param  Builder<Tournament>  $query
     * @return Builder<Tournament>
     */
    private static function ordered(Builder $query): Builder
    {
        // One `when` per state, bound in the order of STATES (a test pins that the list has eight).
        return $query->orderByRaw('case '.self::stateSql().' when ? then 0 when ? then 1 when ? then 2 when ? then 3 when ? then 4 when ? then 5 when ? then 6 when ? then 7 else 8 end', self::STATES)
            ->orderByDesc('tournaments.id');
    }

    /**
     * The base narrowed by the name search (case-insensitive; SQLite folds ASCII only, PostgreSQL by the database locale; `!` escapes the wildcards).
     *
     * @return Builder<Tournament>
     */
    private function searched(): Builder
    {
        $term = mb_strtolower(trim($this->search));

        return self::base()->when($term !== '', fn (Builder $query) => $query->whereRaw("lower(tournaments.name) like ? escape '!'", ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%']));
    }

    /**
     * The base narrowed by the search and the game.
     *
     * @return Builder<Tournament>
     */
    private function narrowed(): Builder
    {
        return $this->searched()->when($this->game !== '', fn (Builder $query) => $query->where('tournaments.game', $this->game));
    }
}
