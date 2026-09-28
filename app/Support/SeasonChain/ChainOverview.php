<?php

namespace App\Support\SeasonChain;

use App\Enums\ChessGameStatus;
use App\Enums\IncomingPaymentStatus;
use App\Enums\SeriesStatus;
use App\Games\GameRegistry;
use App\Models\ChessGame;
use App\Models\IncomingPayment;
use App\Models\Season;
use App\Models\SeasonAttestation;
use App\Models\SeriesMatch;
use App\Models\User;
use App\Support\Chess\RatedChess;
use Carbon\CarbonImmutable;

/**
 * What /mining and AdminSeason show about a chain (P7c): the tip, the supply
 * mined and left, the era schedule with the reward of every game and mode,
 * the latest blocks, the top miners, why wins did not mine, the public
 * change log, and the estimator.
 *
 * The estimator forecasts from real data of the last `window_days`: in a
 * live season from the blocks mined (Estimator::fromChain, the same
 * arithmetic as ledger-src), before Block 0 from the finished wins of every
 * game and mode, casual included, because nothing is rated yet.
 */
final class ChainOverview
{
    public function __construct(private SeasonChains $chains, private GameRegistry $games) {}

    /** "Chess blitz", "Chess daily", "Rocket League 3v3", "EA Sports FC 27 1v1". */
    public static function keyLabel(string $key): string
    {
        [$game, $mode] = array_pad(explode('/', $key, 2), 2, null);

        return match ($key) {
            'chess/blitz' => __('Chess blitz'),
            'chess/correspondence' => __('Chess daily'),
            default => $mode !== null && app(GameRegistry::class)->find($game) !== null ? app(GameRegistry::class)->name($game).' '.$mode : $key,
        };
    }

    /**
     * Whether wins of this `<game>/<mode>` can mine now, so a view may show
     * its reward as achievable: chess only while rated chess is offered
     * (RatedChess::offered()), every other game while it has rated play.
     */
    public static function mines(string $key): bool
    {
        return ! str_starts_with($key, 'chess/') || RatedChess::offered();
    }

    /** A game, or a share group ("EA Sports FC" for both editions). */
    public static function gameLabel(string $game): string
    {
        return ChainDraft::shareLabel($game);
    }

    /** Why a win did not mine, in plain words (the reason codes of ConsensusRules). */
    public static function reasonLabel(string $reason): string
    {
        return match ($reason) {
            'outside-season' => __('outside the season'),
            'game-does-not-mine' => __('this game and mode do not mine'),
            'supply-exhausted' => __('the supply is mined out'),
            'not-trusted' => __('a player is not Trusted yet'),
            'not-connected' => __('the two sides have not added each other'),
            'forfeit' => __('a forfeit'),
            'too-few-moves' => __('too few moves'),
            'same-clan' => __('same clan'),
            'pairing-daily-limit' => __('pairing limit for the day'),
            'player-daily-limit' => __('daily limit of a player'),
            'same-subtree' => __('same trust circle'),
            'pairing-season-limit' => __('pairing limit for the season'),
            'share-cap' => __('the game used up its share of the era'),
            default => $reason,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function live(Season $season, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $chain = $this->chains->chain($season);
        $parameters = $chain->season;
        $era = $parameters->contains($now) ? $parameters->eraAt($now) : null;
        $blocks = $season->attestations()->whereNotNull('height')->orderByDesc('height')->get();
        $today = $now->utc()->startOfDay();

        return [
            'season' => $season,
            'now' => $now,
            'era' => $era,
            'next_halving' => $era !== null && $era < $parameters->eras() ? $parameters->eraStart($era + 1) : null,
            'supply' => $parameters->supply,
            'mined' => $chain->mined(),
            'remaining' => $chain->remaining(),
            'blocks' => $blocks->count(),
            'blocks_today' => $blocks->filter(fn (SeasonAttestation $block): bool => $block->attested_at->gte($today))->count(),
            'tip' => $this->chains->tip($season),
            'last_block_at' => $blocks->first()?->attested_at,
            'rewards_now' => $this->rewards($parameters, $parameters->inForceAt($now), $era ?? 1),
            'schedule' => $this->schedule($parameters, $now),
            'mined_by_game_and_era' => $chain->minedByGameAndEra(),
            'latest' => $this->latest($blocks->take(10)->values()->all()),
            'miners' => $this->miners($blocks->values()->all()),
            'miner_count' => $blocks->flatMap(fn (SeasonAttestation $block): array => $block->winners())->unique()->count(),
            'rejected' => $season->attestations()->whereNotNull('candidate')->whereNull('height')->selectRaw('reason, count(*) as total')->groupBy('reason')->pluck('total', 'reason')->map(fn (mixed $n): int => (int) $n)->all(),
            'changes' => $season->parameterChanges()->with('changedBy')->orderByDesc('effective_at')->orderByDesc('id')->get(),
            'estimate' => $estimate = Estimator::fromConfig()->fromChain($chain, $now),
            'curve' => $this->supplyCurve($season, $chain, $now, $estimate['end_mined']),
            'in_force' => $parameters->inForceAt($now),
        ];
    }

    /**
     * A season that has ended, as it stood at its last second: mining has
     * stopped, no era is current and nothing is forecast any more.
     *
     * @return array<string, mixed>
     */
    public function ended(Season $season): array
    {
        $last = CarbonImmutable::instance($season->ends_at)->subSecond();
        $chain = $this->live($season, $last);

        return [
            ...$chain,
            'era' => null,
            'next_halving' => null,
            'blocks_today' => 0,
            'schedule' => array_map(fn (array $row): array => [...$row, 'current' => false], $chain['schedule']),
            'estimate' => [...$chain['estimate'], 'end_mined' => $chain['mined'], 'end_mined_percent' => round($chain['mined'] / max(1, $chain['supply']) * 100, 1)],
            'curve' => [...$chain['curve'], 'now' => CarbonImmutable::instance($season->ends_at), 'forecast' => null],
        ];
    }

    /**
     * Sats mined over time, one point per UTC day with blocks: the supply
     * chart of /mining. Read from the replayed chain, the same blocks the
     * "Mined" figure sums, so the line ends where that figure says; no query
     * of its own, and at most one point per day of the season. `forecast` is
     * the estimator's total at the season end, null once it has ended.
     *
     * @return array{from: CarbonImmutable, to: CarbonImmutable, now: CarbonImmutable, supply: int, halvings: list<array{era: int, from: CarbonImmutable}>, forecast: int|null, days: list<array{day: string, at: CarbonImmutable, blocks: int, sats: int, total: int}>}
     */
    public function supplyCurve(Season $season, BlockChain $chain, CarbonImmutable $now, ?int $forecast): array
    {
        $from = CarbonImmutable::instance($season->genesis_at);
        $to = CarbonImmutable::instance($season->ends_at);
        $total = 0;
        $days = [];

        foreach ($chain->blocks() as $block) {
            $at = $block->candidate->attestedAt->utc();
            $day = $at->format('Y-m-d');
            $total += $block->reward;
            $last = array_key_last($days);

            if ($last !== null && $days[$last]['day'] === $day) {
                $days[$last] = ['day' => $day, 'at' => $at, 'blocks' => $days[$last]['blocks'] + 1, 'sats' => $days[$last]['sats'] + $block->reward, 'total' => $total];
            } else {
                $days[] = ['day' => $day, 'at' => $at, 'blocks' => 1, 'sats' => $block->reward, 'total' => $total];
            }
        }

        // Era starts as SeasonParameters::eraStart() counts them, without replaying the change log.
        $halvings = [];

        for ($era = 2; $from->addSeconds(($era - 1) * max(1, $season->halving_seconds))->lt($to); $era++) {
            $halvings[] = ['era' => $era, 'from' => $from->addSeconds(($era - 1) * $season->halving_seconds)];
        }

        return [
            'from' => $from,
            'to' => $to,
            'now' => $now->lt($to) ? $now : $to,
            'supply' => $season->supply,
            'halvings' => $halvings,
            'forecast' => $now->lt($to) ? $forecast : null,
            'days' => $days,
        ];
    }

    /**
     * The latest settled zaps and payments into the league reserve, and how
     * many there are. Amounts per receipt only: the reserve's total is a
     * balance, and a public page never shows a balance as a pot.
     *
     * @return array{count: int, latest: list<array{name: string, sats: int, at: CarbonImmutable}>}
     */
    public function reserveZaps(int $limit = 5): array
    {
        $settled = IncomingPayment::query()->where('pot', IncomingPayment::RESERVE)->where('status', IncomingPaymentStatus::Settled);
        $rows = (clone $settled)->orderByDesc('settled_at')->orderByDesc('id')->limit($limit)->get(['source', 'payer_pubkey', 'amount_sats', 'settled_at']);
        $payers = [];

        foreach ($rows as $payment) {
            if ($payment->source === 'zap' && $payment->payer_pubkey !== null) {
                $payers[] = $payment->payer_pubkey;
            }
        }

        $names = $this->names($payers);
        $latest = [];

        foreach ($rows as $payment) {
            $latest[] = [
                'name' => match (true) {
                    $payment->source === 'zap' && $payment->payer_pubkey !== null => $names[$payment->payer_pubkey] ?? substr($payment->payer_pubkey, 0, 8),
                    $payment->source === 'sponsor' => __('a sponsor'),
                    $payment->source === 'lnurl' => __('a Lightning payment'),
                    default => __('anonymous'),
                },
                'sats' => $payment->amount_sats,
                'at' => CarbonImmutable::instance($payment->settled_at ?? now()),
            ];
        }

        return ['count' => $settled->count(), 'latest' => $latest];
    }

    /**
     * The saved chain draft (ChainDraft) as if Block 0 were now, for the
     * planned length, with the forecast from the finished wins of the last
     * window.
     *
     * @return array<string, mixed>
     */
    public function draft(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::createFromTimestamp(now()->getTimestamp());
        $chain = ChainDraft::current();
        $parameters = SeasonParameters::fromDraft([...$chain, 'weeks' => ChainDraft::weeks($chain)], SeasonRelease::draft()['slug'], $now);
        $estimator = Estimator::fromConfig();

        return [
            'season' => null,
            'now' => $now,
            'parameters' => $parameters,
            'supply' => $parameters->supply,
            'rewards_now' => $this->rewards($parameters, $parameters->genesis, 1),
            'schedule' => $this->schedule($parameters, $now),
            // Only games that can mine feed the forecast: casual chess wins say nothing about chess blocks while rated chess is off.
            'streams' => $streams = array_values(array_filter($this->recentWins($now->subDays($estimator->windowDays), $now, $estimator->windowDays / 7), fn (array $stream): bool => self::mines($stream['weight_key']))),
            'estimate' => $estimator->forecast($parameters, $now, $streams, 0, []),
            'rewarded_wins' => $this->rewardedWins($parameters),
            'in_force' => $parameters->genesis,
        ];
    }

    /**
     * How many wins of each game and mode the share cap of an era pays, if
     * that game and mode alone mined it: floor(cap / block reward), the
     * block reward for a full team of winners (the estimator's "wins the
     * caps allow", for every game and mode that mines, not only those with
     * recent wins). A share group's cap is shared by all its games.
     *
     * @return list<array<string, int>> one row per era, `<game>/<mode>` => wins
     */
    private function rewardedWins(SeasonParameters $season): array
    {
        $parameters = $season->genesis;
        $rows = [];

        for ($era = 1; $era <= $season->eras(); $era++) {
            $row = [];

            foreach ($parameters->weights as $key => $milli) {
                [$game, $mode] = explode('/', $key, 2);
                $reward = $season->rewardPerPlayer($milli, $era) * ($this->games->mode($game, $mode)->teamSize ?? 1);
                $row[$key] = $reward > 0 ? intdiv($season->shareCap($parameters->shareFor($game), $era), $reward) : 0;
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Reward per winning player of every game and mode that mines, in an era.
     *
     * @return array<string, int>
     */
    private function rewards(SeasonParameters $season, ConsensusParameters $parameters, int $era): array
    {
        $rewards = [];

        foreach ($parameters->weights as $key => $milli) {
            $rewards[$key] = $season->rewardPerPlayer($milli, $era);
        }

        return $rewards;
    }

    /**
     * @return list<array{era: int, from: CarbonImmutable, budget: int, caps: array<string, int>, rewards: array<string, int>, current: bool}>
     */
    private function schedule(SeasonParameters $season, CarbonImmutable $now): array
    {
        $rows = [];
        $parameters = $season->inForceAt($now);

        for ($era = 1; $era <= $season->eras(); $era++) {
            $caps = [];

            foreach ($parameters->shares + array_fill_keys(array_keys($parameters->daily), 100) as $game => $share) {
                $caps[$game] = $season->shareCap($parameters->shareFor($game), $era);
            }

            $rows[] = [
                'era' => $era,
                'from' => $season->eraStart($era),
                'budget' => $season->eraBudget($era),
                'caps' => $caps,
                'rewards' => $this->rewards($season, $parameters, $era),
                'current' => $season->contains($now) && $season->eraAt($now) === $era,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<int, SeasonAttestation>  $blocks
     * @return list<array{height: int, label: string, key: string, winners: list<string>, reward: int, per_player: int, at: CarbonImmutable, event_id: string}>
     */
    private function latest(array $blocks): array
    {
        $names = $this->names(array_merge([], ...array_values(array_map(fn (SeasonAttestation $block): array => $block->winners(), $blocks))));

        return array_values(array_map(fn (SeasonAttestation $block): array => [
            'height' => (int) $block->height,
            'label' => $block->label,
            'key' => $block->game.'/'.$block->mode,
            'winners' => array_map(fn (string $pubkey): string => $names[$pubkey] ?? substr($pubkey, 0, 8), $block->winners()),
            'reward' => $block->reward,
            'per_player' => $block->reward_per_player,
            'at' => CarbonImmutable::instance($block->attested_at),
            'event_id' => $block->event_id,
        ], $blocks));
    }

    /**
     * Blocks and sats per winning player, most sats first.
     *
     * @param  array<int, SeasonAttestation>  $blocks
     * @return list<array{name: string, blocks: int, sats: int}>
     */
    private function miners(array $blocks): array
    {
        $totals = [];

        foreach ($blocks as $block) {
            foreach ($block->winners() as $pubkey) {
                $totals[$pubkey] ??= ['blocks' => 0, 'sats' => 0];
                $totals[$pubkey]['blocks']++;
                $totals[$pubkey]['sats'] += $block->reward_per_player;
            }
        }

        uasort($totals, fn (array $a, array $b): int => [$b['sats'], $b['blocks']] <=> [$a['sats'], $a['blocks']]);
        $names = $this->names(array_keys($totals));
        $rows = [];

        foreach (array_slice($totals, 0, 10, true) as $pubkey => $row) {
            $rows[] = ['name' => $names[$pubkey] ?? substr((string) $pubkey, 0, 8), ...$row];
        }

        return $rows;
    }

    /**
     * @param  list<string>  $pubkeys
     * @return array<string, string>
     */
    private function names(array $pubkeys): array
    {
        return User::query()->whereIn('pubkey', array_unique($pubkeys))->get()
            ->mapWithKeys(fn (User $user): array => [$user->pubkey => $user->displayName()])
            ->all();
    }

    /**
     * Finished wins per game and mode in [from, to): the activity before
     * Block 0, when nothing is rated yet.
     *
     * @return list<array{weight_key: string, game: string, winners: int, per_week: float}>
     */
    private function recentWins(CarbonImmutable $from, CarbonImmutable $to, float $weeks): array
    {
        $counts = ChessGame::query()->where('status', ChessGameStatus::Finished)->whereIn('result', ['1-0', '0-1'])
            ->where('updated_at', '>=', $from)->where('updated_at', '<', $to)
            ->selectRaw('mode, count(*) as total')->groupBy('mode')->pluck('total', 'mode')
            ->mapWithKeys(fn (mixed $total, string $mode): array => ['chess/'.$mode => (int) $total])->all();

        $series = SeriesMatch::query()->whereIn('status', [SeriesStatus::Confirmed, SeriesStatus::Resolved])->whereIn('winner', SeriesMatch::SIDES)
            ->where('finished_at', '>=', $from)->where('finished_at', '<', $to)
            ->selectRaw('game, mode, count(*) as total')->groupBy('game', 'mode')->get();

        foreach ($series as $row) {
            $counts[$row->game.'/'.$row->mode] = (int) $row->getAttribute('total');
        }

        $streams = [];

        foreach ($counts as $key => $total) {
            [$game, $mode] = explode('/', $key, 2);
            $streams[] = [
                'weight_key' => $key,
                'game' => $game,
                'winners' => $this->games->mode($game, $mode)->teamSize ?? 1,
                'per_week' => $total / $weeks,
            ];
        }

        return $streams;
    }
}
