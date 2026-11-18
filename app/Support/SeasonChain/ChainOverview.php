<?php

namespace App\Support\SeasonChain;

use App\Enums\BoardGameStatus;
use App\Enums\ChessGameStatus;
use App\Enums\IncomingPaymentStatus;
use App\Enums\SeriesStatus;
use App\Enums\TournamentFormat;
use App\Enums\TournamentStatus;
use App\Games\BoardGame;
use App\Games\GameRegistry;
use App\Models\BoardGame as BoardGameModel;
use App\Models\ChessGame;
use App\Models\IncomingPayment;
use App\Models\Season;
use App\Models\SeasonAttestation;
use App\Models\SeriesMatch;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Board\RatedBoard;
use App\Support\Chess\RatedChess;
use App\Support\Hyper\HyperMining;
use App\Support\PreSeason;
use App\Support\Scores\ScoreRuns;
use App\Support\Scores\ScoreStanding;
use App\Support\Scores\ScoreWindow;
use Carbon\CarbonImmutable;

/**
 * What /mining and AdminSeason show about a chain (P7c): the tip, the supply
 * mined and left, where every share key stands (`keys`: its own era, what a
 * win pays now, how far its next halving is), the era schedule with the
 * reward of every game and mode, the latest blocks, the top miners, why wins
 * did not mine, the public change log, and the estimator.
 *
 * A season has no era of its own (`season-chain-v2`): every share key halves
 * by what it mined, so every figure a player reads names its game.
 *
 * The estimator forecasts from real data of the last `window_days`: in a
 * live season from the blocks mined (Estimator::fromChain, the same
 * arithmetic as ledger-src), before Block 0 from the finished wins of every
 * game and mode, casual included, because nothing is rated yet.
 */
final class ChainOverview
{
    public function __construct(private SeasonChains $chains, private GameRegistry $games) {}

    /** "Chess blitz", "Chess rapid", "Chess daily", "Rocket League 3v3", "EA Sports FC 27 1v1". */
    public static function keyLabel(string $key): string
    {
        [$game, $mode] = array_pad(explode('/', $key, 2), 2, null);

        return match (true) {
            $key === 'chess/blitz' => __('Chess blitz'),
            $key === 'chess/rapid' => __('Chess rapid'),
            $key === 'chess/correspondence' => __('Chess daily'),
            // A board game's correspondence mode (plan "Mühle und Dame", P8), as the board game's pages name it: every
            // board game, switched on or kept for later.
            $mode === 'correspondence' && (app(GameRegistry::class)->isBoard((string) $game) || in_array($game, BoardGame::RESERVED_SLUGS, true)) => __(':game correspondence', ['game' => __(app(GameRegistry::class)->name((string) $game))]),
            default => $mode !== null && app(GameRegistry::class)->find($game) !== null ? app(GameRegistry::class)->name($game).' '.$mode : $key,
        };
    }

    /**
     * Whether wins of this `<game>/<mode>` can mine now, so a view may show
     * its reward as achievable: chess only while rated chess is offered
     * (RatedChess::offered()), a board game other than chess only while it
     * is switched on and its rated queue is offered (RatedBoard::offered(),
     * plan "Mühle und Dame", P6), a score game while it is switched on (plan
     * "AoE2 und Trackmania", P7: the windows the league opens itself mine one
     * solo block each), Hyperbitcoinization while it is switched on (NIP
     * rev. 9.26, draft), every other game while it has rated play.
     */
    public static function mines(string $key): bool
    {
        $game = explode('/', $key, 2)[0];

        if (app(GameRegistry::class)->isScore($game)) {
            return true;
        }

        if (in_array($game, BoardGame::RESERVED_SLUGS, true) || app(GameRegistry::class)->isBoard($game)) {
            return app(GameRegistry::class)->isBoard($game) && RatedBoard::offered();
        }

        if ($game === HyperMining::GAME) {
            return app(GameRegistry::class)->find($game) !== null;
        }

        return ! str_starts_with($key, 'chess/') || RatedChess::offered();
    }

    /** A game, or a share group ("EA Sports FC" for both editions). */
    public static function gameLabel(string $game): string
    {
        return ChainDraft::shareLabel($game);
    }

    /**
     * The shares of a season in one line: "Chess 50 %, Board games 30 %".
     *
     * @param  array<string, int>  $shares
     */
    public static function sharesLine(array $shares): string
    {
        $parts = [];

        foreach ($shares as $game => $share) {
            $parts[] = self::gameLabel((string) $game).' '.$share.' %';
        }

        return implode(', ', $parts);
    }

    /** Why a win did not mine, in plain words (the reason codes of ConsensusRules). */
    public static function reasonLabel(string $reason): string
    {
        return match ($reason) {
            'outside-season' => __('outside the season'),
            'game-does-not-mine' => __('this game and mode do not mine'),
            'supply-exhausted' => __('the supply is mined out'),
            'not-trusted' => __('a player is not Trusted yet'),
            'not-pinned' => __('the match started before this game mined'),
            'not-connected' => __('the two sides have not added each other'),
            'forfeit' => __('a forfeit'),
            'no-conquest' => __('the winner conquered nothing'),
            'too-few-moves' => __('too few moves'),
            'same-clan' => __('same clan'),
            'pairing-daily-limit' => __('pairing limit for the day'),
            'player-daily-limit' => __('daily limit of a player'),
            'same-subtree' => __('same trust circle'),
            'pairing-season-limit' => __('pairing limit for the season'),
            'key-closed' => __('the game has mined its whole share of the season'),
            // The solo rules of a score window (NIP rev. 9.18).
            'window-outside-season' => __('the window began before Block 0'),
            'too-few-entrants' => __('too few trusted players in the window'),
            'unverified' => __('the winning value is not verified'),
            'outside-window' => __('the winning value lies outside the window'),
            'not-reviewed' => __('the review time of the window is not over'),
            'window-block' => __('this window already mined a block'),
            'window-wins-limit' => __('the winner reached the window wins of the season'),
            default => $reason,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function live(Season $season, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        // The replay's figures come cached (SeasonChains::readout()); the parameters are built fresh, one query.
        $readout = $this->chains->readout($season);
        $parameters = $season->chainParameters();
        $inForce = $parameters->inForceAt($now);
        $keys = $this->keys($parameters, $inForce, array_map(array_sum(...), $readout['mined_by_game_and_era']));
        // The ten newest blocks only: the counts and the top miners are in the readout (P2, S3).
        $latest = $season->attestations()->whereNotNull('height')->orderByDesc('height')->limit(10)->get();
        $today = $now->utc()->startOfDay();

        return [
            'season' => $season,
            'now' => $now,
            'keys' => $keys,
            'supply' => $parameters->supply,
            'mined' => $readout['mined'],
            'remaining' => $parameters->supply - $readout['mined'],
            'blocks' => count($readout['stored']['times']),
            'blocks_today' => count(array_filter($readout['stored']['times'], fn (int $at): bool => $at >= $today->getTimestamp())),
            'tip' => $this->chains->tip($season),
            'last_block_at' => $latest->first()?->attested_at,
            'rewards_now' => $this->rewardsNow($inForce, $keys),
            'schedule' => $this->schedule($parameters, $now, $keys),
            'mined_by_game_and_era' => $readout['mined_by_game_and_era'],
            'latest' => $this->latest($latest->all()),
            'miners' => $this->miners($readout['stored']['miners']),
            'miner_count' => $readout['stored']['miner_count'],
            'rejected' => $season->attestations()->whereNotNull('candidate')->whereNull('height')->selectRaw('reason, count(*) as total')->groupBy('reason')->pluck('total', 'reason')->map(fn (mixed $n): int => (int) $n)->all(),
            'changes' => $season->parameterChanges()->with('changedBy')->orderByDesc('effective_at')->orderByDesc('id')->get(),
            'estimate' => $estimate = Estimator::fromConfig()->fromBlocks($parameters, $readout['blocks'], $readout['mined'], $readout['mined_by_game_and_era'], $now),
            'curve' => $this->curve($season, $parameters, $readout['blocks'], $now, $estimate['end_mined']),
            'in_force' => $parameters->inForceAt($now),
        ];
    }

    /**
     * A season that has ended, as it stood at its last second: mining has
     * stopped and nothing is forecast any more; every key keeps the era it
     * ended in.
     *
     * @return array<string, mixed>
     */
    public function ended(Season $season): array
    {
        $last = CarbonImmutable::instance($season->ends_at)->subSecond();
        $chain = $this->live($season, $last);

        return [
            ...$chain,
            'blocks_today' => 0,
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
     * `halvings` are the halvings that happened: the block with which a share
     * key reached the bound of its era (`era` is the era it left, `closed`
     * when that was its last). A halving to come has no date.
     *
     * @return array{from: CarbonImmutable, to: CarbonImmutable, now: CarbonImmutable, supply: int, halvings: list<array{key: string, era: int, closed: bool, at: CarbonImmutable}>, forecast: int|null, days: list<array{day: string, at: CarbonImmutable, blocks: int, sats: int, total: int}>}
     */
    public function supplyCurve(Season $season, BlockChain $chain, CarbonImmutable $now, ?int $forecast): array
    {
        return $this->curve($season, $chain->season, $chain->blockRows(), $now, $forecast);
    }

    /**
     * {@see supplyCurve()} from the blocks as rows (BlockChain::blockRows(), SeasonChains::readout()).
     *
     * @param  list<array{at: CarbonImmutable, reward: int, weight_key: string, game: string, winners: int}>  $blocks
     * @return array{from: CarbonImmutable, to: CarbonImmutable, now: CarbonImmutable, supply: int, halvings: list<array{key: string, era: int, closed: bool, at: CarbonImmutable}>, forecast: int|null, days: list<array{day: string, at: CarbonImmutable, blocks: int, sats: int, total: int}>}
     */
    private function curve(Season $season, SeasonParameters $parameters, array $blocks, CarbonImmutable $now, ?int $forecast): array
    {
        $from = CarbonImmutable::instance($season->genesis_at);
        $to = CarbonImmutable::instance($season->ends_at);
        $total = 0;
        $days = [];
        $minedByKey = [];
        $halvings = [];

        foreach ($blocks as $block) {
            $at = $block['at']->utc();
            $day = $at->format('Y-m-d');
            $total += $block['reward'];
            $shareKey = $parameters->genesis->shareKey($block['game']);
            $before = $parameters->eraOf($shareKey, $minedByKey[$shareKey] ?? 0);
            $minedByKey[$shareKey] = ($minedByKey[$shareKey] ?? 0) + $block['reward'];
            $after = $parameters->eraOf($shareKey, $minedByKey[$shareKey]);

            // The block that reached the bound of its key's era: that key's halving, or its closing.
            if ($before !== null && $after !== $before) {
                $halvings[] = ['key' => $shareKey, 'era' => $before, 'closed' => $after === null, 'at' => $at];
            }
            $last = array_key_last($days);

            if ($last !== null && $days[$last]['day'] === $day) {
                $days[$last] = ['day' => $day, 'at' => $at, 'blocks' => $days[$last]['blocks'] + 1, 'sats' => $days[$last]['sats'] + $block['reward'], 'total' => $total];
            } else {
                $days[] = ['day' => $day, 'at' => $at, 'blocks' => 1, 'sats' => $block['reward'], 'total' => $total];
            }
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
            'keys' => $keys = $this->keys($parameters, $parameters->genesis, []),
            'rewards_now' => $this->rewards($parameters, $parameters->genesis, 1),
            'schedule' => $this->schedule($parameters, $now, $keys),
            // Only games that can mine feed the forecast: casual chess wins say nothing about chess blocks while rated chess is off.
            'streams' => $streams = array_values(array_filter($this->recentWins($now->subDays($estimator->windowDays), $now, $estimator->windowDays / 7), fn (array $stream): bool => self::mines($stream['weight_key']))),
            'estimate' => $estimator->forecast($parameters, $now, $streams, 0, []),
            'rewarded_wins' => $this->rewardedWins($parameters),
            'in_force' => $parameters->genesis,
        ];
    }

    /**
     * How many wins of each game and mode an era of its share key pays, if
     * that game and mode alone mined it: floor(era size / block reward), the
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
                $row[$key] = $reward > 0 ? intdiv($season->eraSize($parameters->shareKey($game), $era), $reward) : 0;
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
     * Reward per winning player of every game and mode that mines, each in
     * the era of its own share key; 0 once the key is closed or has no share.
     * In the order of the weights, as the schedule's rows.
     *
     * @param  array<string, array{modes: array<string, array{pays: int, wins: int}>}>  $keys  keys()
     * @return array<string, int>
     */
    private function rewardsNow(ConsensusParameters $parameters, array $keys): array
    {
        $rewards = [];

        foreach (array_keys($parameters->weights) as $key) {
            $rewards[$key] = $keys[$parameters->shareKey(explode('/', $key, 2)[0])]['modes'][$key]['pays'] ?? 0;
        }

        return $rewards;
    }

    /**
     * Where every share key with a share stands (`season-chain-v2`): its pot
     * and what it mined, the era of its next block (null once it is closed),
     * the sats it still mines until the bound of that era (`to_halving`; in
     * its last era, `last`, the key closes there instead of halving), and
     * per `<game>/<mode>` of the key what a win pays each winner now and how
     * many such wins reach the bound, the one that crosses it counted
     * (`wins`: a block is a full team of winners, a score window one). `wins`
     * and `wins_key` of the key are those of its first mode that can mine,
     * the mode a page names when it has room for one figure.
     *
     * @param  array<string, int|float>  $minedByKey  share key => sats mined so far
     * @return array<string, array{key: string, share: int, pot: int, mined: int, era: int|null, eras: int, closed: bool, last: bool, to_halving: int, wins: int, wins_key: string|null, modes: array<string, array{pays: int, wins: int}>}>
     */
    public function keys(SeasonParameters $season, ConsensusParameters $parameters, array $minedByKey): array
    {
        $rows = [];

        foreach (array_keys($parameters->shares) as $shareKey) {
            $shareKey = (string) $shareKey;
            $mined = (int) floor($minedByKey[$shareKey] ?? 0);
            $era = $season->eraOf($shareKey, $mined);
            $toHalving = $era === null ? 0 : $season->bound($shareKey, $era) - $mined;
            $modes = [];
            $winsKey = null;

            foreach ($parameters->weights as $key => $milli) {
                [$game, $mode] = explode('/', $key, 2);

                if ($parameters->shareKey($game) !== $shareKey) {
                    continue;
                }

                $pays = $era === null ? 0 : $season->rewardPerPlayer($milli, $era);
                $block = $pays * ($this->games->isScore($game) ? 1 : ($this->games->mode($game, $mode)->teamSize ?? 1));
                $modes[$key] = ['pays' => $pays, 'wins' => $block > 0 ? intdiv($toHalving + $block - 1, $block) : 0];

                if (self::mines($key)) {
                    $winsKey ??= $key;
                }
            }

            $winsKey ??= array_key_first($modes);
            $rows[$shareKey] = [
                'key' => $shareKey,
                'share' => (int) $parameters->shares[$shareKey],
                'pot' => $season->pot($shareKey),
                'mined' => $mined,
                'era' => $era,
                'eras' => $season->eras(),
                'closed' => $era === null,
                'last' => $era === $season->eras(),
                'to_halving' => $toHalving,
                'wins' => $winsKey === null ? 0 : $modes[$winsKey]['wins'],
                'wins_key' => $winsKey,
                'modes' => $modes,
            ];
        }

        return $rows;
    }

    /**
     * What stands between a share key and its next halving, in one line: a
     * key row of keys(). Never a date: a key halves by what it mined.
     *
     * @param  array{pot: int, closed: bool, last: bool, to_halving: int, wins: int, wins_key: string|null}  $key
     */
    public static function halvingLine(array $key): string
    {
        $with = ['sats' => PreSeason::formatSats($key['to_halving']), 'mode' => $key['wins_key'] === null ? '' : self::keyLabel($key['wins_key'])];

        return match (true) {
            $key['pot'] === 0 => __('no share in this season'),
            $key['closed'] => __('mined out'),
            $key['wins_key'] === null || $key['wins'] === 0 => $key['last'] ? __('mined out after :sats more sats', $with) : __('next halving after :sats more sats', $with),
            $key['last'] => trans_choice('mined out after :sats more sats (about :count :mode win)|mined out after :sats more sats (about :count :mode wins)', $key['wins'], $with),
            default => trans_choice('next halving after :sats more sats (about :count :mode win)|next halving after :sats more sats (about :count :mode wins)', $key['wins'], $with),
        };
    }

    /**
     * One row per era: what it pays and what it holds per share key (`caps`:
     * the key's era size, `budget` their sum). An era has no start date:
     * each key enters it by what it mined, so `keys` lists the share keys
     * whose next block falls in this era.
     *
     * @param  array<string, array{era: int|null}>  $keys  keys()
     * @return list<array{era: int, budget: int, caps: array<string, int>, rewards: array<string, int>, keys: list<string>}>
     */
    private function schedule(SeasonParameters $season, CarbonImmutable $now, array $keys): array
    {
        $rows = [];
        $parameters = $season->inForceAt($now);

        for ($era = 1; $era <= $season->eras(); $era++) {
            $caps = [];

            foreach (array_keys($parameters->shares + $parameters->daily) as $game) {
                $caps[$game] = $season->eraSize((string) $game, $era);
            }

            $rows[] = [
                'era' => $era,
                'budget' => array_sum($caps),
                'caps' => $caps,
                'rewards' => $this->rewards($season, $parameters, $era),
                'keys' => array_keys(array_filter($keys, fn (array $key): bool => $key['era'] === $era)),
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
     * The ten players with the most sats (then blocks), from the totals per
     * winning player that SeasonChains::readout() sums over the stored blocks.
     *
     * @param  array<string, array{blocks: int, sats: int}>  $totals
     * @return list<array{name: string, blocks: int, sats: int}>
     */
    private function miners(array $totals): array
    {
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
     * Block 0, when nothing is rated yet. A score window counts by its end.
     *
     * @return list<array{weight_key: string, game: string, winners: int, per_week: float}>
     */
    private function recentWins(CarbonImmutable $from, CarbonImmutable $to, float $weeks): array
    {
        $counts = ChessGame::query()->where('status', ChessGameStatus::Finished)->whereIn('result', ['1-0', '0-1'])
            ->where('updated_at', '>=', $from)->where('updated_at', '<', $to)
            ->selectRaw('mode, count(*) as total')->groupBy('mode')->pluck('total', 'mode')
            ->mapWithKeys(fn (mixed $total, string $mode): array => ['chess/'.$mode => (int) $total])->all();

        // Board games (P6): their decisive finished games, casual included, as chess.
        $boards = BoardGameModel::query()->where('status', BoardGameStatus::Finished)->whereIn('result', ['1-0', '0-1'])
            ->where('updated_at', '>=', $from)->where('updated_at', '<', $to)
            ->selectRaw('game, mode, count(*) as total')->groupBy('game', 'mode')->get();

        foreach ($boards as $row) {
            $counts[$row->game.'/'.$row->mode] = (int) $row->getAttribute('total');
        }

        $series = SeriesMatch::query()->whereIn('status', [SeriesStatus::Confirmed, SeriesStatus::Resolved])->whereIn('winner', SeriesMatch::SIDES)
            ->where('finished_at', '>=', $from)->where('finished_at', '<', $to)
            ->selectRaw('game, mode, count(*) as total')->groupBy('game', 'mode')->get();

        foreach ($series as $row) {
            $counts[$row->game.'/'.$row->mode] = (int) $row->getAttribute('total');
        }

        // Score windows (a TMNF or Blockfill week): a window the league opened itself, closed in the range with a
        // top score, is one solo win, as SeasonChains::attestScoreWindow() would attest it. An organizer's never mines.
        $windows = Tournament::query()->where(['status' => TournamentStatus::Finished, 'format' => TournamentFormat::Leaderboard, 'opened_by_league' => true])
            ->whereIn('game', array_keys($this->games->scores()))->whereNull('created_by_id')->where('starts_at', '<', $to)->get();
        $runs = app(ScoreRuns::class);

        foreach ($windows as $window) {
            $end = ScoreWindow::of($window)->end;

            if ($end->lt($from) || $end->gte($to)) {
                continue;
            }

            $top = array_filter($runs->standings($window), fn (ScoreStanding $row): bool => $row->place !== null && $row->value !== null);

            if ($top !== []) {
                $key = $window->game.'/'.$window->mode;
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }

        $streams = [];

        foreach ($counts as $key => $total) {
            [$game, $mode] = explode('/', $key, 2);
            $streams[] = [
                'weight_key' => $key,
                'game' => $game,
                // A score window mines a solo block: one winner.
                'winners' => $this->games->isScore($game) ? 1 : ($this->games->mode($game, $mode)->teamSize ?? 1),
                'per_week' => $total / $weeks,
            ];
        }

        return $streams;
    }
}
