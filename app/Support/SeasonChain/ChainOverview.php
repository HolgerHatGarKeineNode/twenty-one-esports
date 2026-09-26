<?php

namespace App\Support\SeasonChain;

use App\Enums\ChessGameStatus;
use App\Enums\SeriesStatus;
use App\Games\GameRegistry;
use App\Models\ChessGame;
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

    /** "Chess blitz", "Chess daily", "Rocket League 3v3". */
    public static function keyLabel(string $key): string
    {
        return match ($key) {
            'chess/blitz' => __('Chess blitz'),
            'chess/correspondence' => __('Chess daily'),
            default => str_starts_with($key, 'rocket-league/') ? 'Rocket League '.substr($key, strlen('rocket-league/')) : $key,
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

    public static function gameLabel(string $game): string
    {
        return $game === 'chess' ? __('Chess') : ($game === 'rocket-league' ? 'Rocket League' : $game);
    }

    /** Why a win did not mine, in plain words (the reason codes of ConsensusRules). */
    public static function reasonLabel(string $reason): string
    {
        return match ($reason) {
            'outside-season' => __('outside the season'),
            'game-does-not-mine' => __('this game and mode do not mine'),
            'supply-exhausted' => __('the pot is mined out'),
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
            'rejected' => $season->attestations()->whereNotNull('candidate')->whereNull('height')->selectRaw('reason, count(*) as total')->groupBy('reason')->pluck('total', 'reason')->map(fn (mixed $n): int => (int) $n)->all(),
            'changes' => $season->parameterChanges()->with('changedBy')->orderByDesc('effective_at')->orderByDesc('id')->get(),
            'estimate' => Estimator::fromConfig()->fromChain($chain, $now),
            'in_force' => $parameters->inForceAt($now),
        ];
    }

    /**
     * The Pre-Season draft of config/season.php as if Block 0 were now, with
     * the forecast from the finished wins of the last window.
     *
     * @return array<string, mixed>
     */
    public function draft(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::createFromTimestamp(now()->getTimestamp());
        $parameters = SeasonParameters::fromConfig(SeasonRelease::SLUG, $now);
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
            'in_force' => $parameters->genesis,
        ];
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
