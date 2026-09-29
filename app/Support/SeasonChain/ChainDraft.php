<?php

namespace App\Support\SeasonChain;

use App\Games\BoardGame;
use App\Games\GameRegistry;
use App\Models\SeasonSettingChange;
use App\Models\User;
use App\Support\GameNames;
use App\Support\Rating\RatingSettings;
use Carbon\CarbonImmutable;

/**
 * The chain draft for the next Block 0 (P43, AdminSeason): every value the
 * Season Genesis (`2156`) signs, plus the planned Block 0 and length of the
 * Pre-Season, the trust minimum and the genesis message. It lives in the
 * same draft as the rating values (RatingSettings, P35): same storage and
 * audit log, board only (P39), locked while a season is live (P38).
 * config/season.php `chain` is only the default before the board saved one.
 *
 * One source of truth: the home page, the rules and the countdown read the
 * saved draft before the first season (PreSeason), a released season reads
 * its own row. The supply is the most the season pays out, after the
 * season, not money set aside now (user decision 2026-09-28): there is no
 * balance or funding check anywhere.
 *
 * Planned Block 0 and length belong to the draft for the Pre-Season; every
 * later season takes them from the board's plan (SeasonPlans, P38), which
 * announces them.
 *
 * @phpstan-type Chain array{block0_at: int|null, weeks: int, supply: int, subsidy: int, halving_days: int, claim_days: int, trust_minimum: int, message: string|null, weights: array<string, int>, groups: array<string, list<string>>, shares: array<string, int>, daily: array<string, int>, pairlimit: array{0: int, 1: int}, subtree: int, moves: int}
 */
final class ChainDraft
{
    public const MEMO = 'chain-draft.stored';

    /**
     * Validation limits per field: [minimum, maximum]. `weight` (in
     * thousandths), `share`, `daily`, `pairlimit.*`, `subtree` and `moves`
     * are the ranges a Parameter Change (`2158`) accepts, too.
     */
    public const LIMITS = [
        'supply' => [1_000, 100_000_000],
        'subsidy' => [1, 1_000_000],
        'halving_days' => [1, 182],
        'claim_days' => [1, 365],
        'trust_minimum' => [0, 100],
        'weight' => [1, 10_000],
        'share' => [1, 100],
        'daily' => [1, 100],
        'pairlimit.day' => [1, 100],
        'pairlimit.season' => [1, 1000],
        'subtree' => [1, 101],
        'moves' => [1, 200],
    ];

    /**
     * config/season.php, the Pre-Season length in weeks. The share group of
     * the board games (plan "Mühle und Dame", P6) is left out while none of
     * them is switched on: nothing of a game that is on no page is signed.
     *
     * @return Chain
     */
    public static function defaults(): array
    {
        /** @var array{supply: int, subsidy: int, weights: array<string, int>, groups: array<string, list<string>>, shares: array<string, int>, daily: array<string, int>, pairlimit: array{0: int, 1: int}, subtree: int, moves: int, weeks: int, halving_days: int, claim_days: int} $chain */
        $chain = config('season.chain');
        $registry = app(GameRegistry::class);
        $off = fn (string $game): bool => in_array($game, BoardGame::RESERVED_SLUGS, true) && ! $registry->isBoard($game);
        $chain['groups'] = array_filter($chain['groups'], fn (array $games): bool => ! array_all($games, $off));

        return [
            'block0_at' => null,
            'weeks' => (int) $chain['weeks'],
            'supply' => (int) $chain['supply'],
            'subsidy' => (int) $chain['subsidy'],
            'halving_days' => (int) $chain['halving_days'],
            'claim_days' => (int) $chain['claim_days'],
            'trust_minimum' => (int) config('season.trust_minimum'),
            'message' => null,
            'weights' => array_map(intval(...), $chain['weights']),
            'groups' => array_map(fn (array $games): array => array_map(strval(...), $games), $chain['groups']),
            'shares' => array_map(intval(...), $chain['shares']),
            'daily' => array_map(intval(...), $chain['daily']),
            'pairlimit' => [(int) $chain['pairlimit'][0], (int) $chain['pairlimit'][1]],
            'subtree' => (int) $chain['subtree'],
            'moves' => (int) $chain['moves'],
        ];
    }

    /**
     * The chain draft the board saved, or null before the first save.
     *
     * @return Chain|null
     */
    public static function stored(): ?array
    {
        $attributes = request()->attributes;

        // Kept once per request or queue job, forgotten with RatingSettings::forget() on every save.
        if (! $attributes->has(self::MEMO)) {
            $attributes->set(self::MEMO, SeasonSettingChange::query()->latest('id')->first()?->values['chain'] ?? null);
        }

        $chain = $attributes->get(self::MEMO);
        $defaults = self::defaults();

        // The share groups are config's, never the board's: fromInput() always writes them, so a
        // draft saved before a group existed (the board games, P6) gets it too, without a weight.
        /** @var Chain|null */
        return is_array($chain) ? [...$defaults, ...$chain, 'groups' => $defaults['groups']] : null;
    }

    /**
     * The draft for the next Block 0: the saved one, else config.
     *
     * @return Chain
     */
    public static function current(): array
    {
        return self::stored() ?? self::defaults();
    }

    /**
     * The consensus rules players read now: the live season's in force,
     * else the draft of the next Block 0.
     */
    public static function inForce(): ConsensusParameters
    {
        $live = Seasons::live();

        return $live !== null
            ? $live->chainParameters()->inForceAt(CarbonImmutable::now())
            : SeasonParameters::fromDraft(self::current(), SeasonRelease::SLUG, CarbonImmutable::now())->genesis;
    }

    /**
     * What the form and the release are bound to: a change of any value in
     * between changes it (SeasonRelease refuses the release then).
     *
     * @param  Chain  $chain
     */
    public static function hash(array $chain): string
    {
        return hash('sha256', (string) json_encode([$chain, RatingSettings::draft()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /**
     * The planned Block 0: the draft's for the Pre-Season, the plan's for
     * every later season (null while it is not planned).
     *
     * @param  Chain  $chain
     */
    public static function block0At(array $chain): ?CarbonImmutable
    {
        if (Seasons::latest() !== null) {
            $plan = SeasonPlans::current();

            return $plan === null ? null : CarbonImmutable::instance($plan->starts_at);
        }

        return $chain['block0_at'] === null ? null : CarbonImmutable::createFromTimestamp($chain['block0_at']);
    }

    /**
     * The length in weeks: the draft's for the Pre-Season, the plan's for
     * every later season.
     *
     * @param  Chain  $chain
     */
    public static function weeks(array $chain): int
    {
        return Seasons::latest() !== null && SeasonPlans::current() !== null ? SeasonPlans::current()->weeks : $chain['weeks'];
    }

    /**
     * Every share key of the registry with its games and their modes: a
     * share group (both EA Sports FC editions, the board games) is one row
     * with every game it counts, every other game its own row. The draft's
     * table follows this.
     *
     * @param  Chain  $chain
     * @return array<string, list<string>> share key => `<game>/<mode>` keys
     */
    public static function table(array $chain): array
    {
        $parameters = new ConsensusParameters([], groups: $chain['groups']);
        $rows = [];

        // Every game of the registry, the board games that are switched on included (plan "Mühle und Dame", P6).
        foreach (app(GameRegistry::class)->all() as $game) {
            foreach (array_keys($game->modes()) as $mode) {
                $rows[$parameters->shareKey($game->slug())][] = $game->slug().'/'.$mode;
            }
        }

        return $rows;
    }

    /**
     * "Chess", "EA Sports FC" for the share group of both editions, "Board
     * games" for the share group of nine men's morris and checkers.
     */
    public static function shareLabel(string $shareKey): string
    {
        /** @var array<string, list<string>> $groups */
        $groups = config('season.chain.groups', []);

        if (! isset($groups[$shareKey])) {
            return GameNames::game($shareKey);
        }

        // A group whose games share no name (plan "Mühle und Dame", P6) is named for what they are.
        if ($shareKey === 'board-games') {
            return __('Board games');
        }

        // The words the games of the group share: "EA Sports FC 26" and "EA Sports FC 27" are "EA Sports FC".
        $names = array_map(fn (string $game): array => explode(' ', GameNames::game($game)), $groups[$shareKey]);
        $common = [];

        foreach ($names[0] ?? [] as $index => $word) {
            if (array_any($names, fn (array $name): bool => ($name[$index] ?? null) !== $word)) {
                break;
            }

            $common[] = $word;
        }

        return $common === [] ? $shareKey : implode(' ', $common);
    }

    /**
     * What the admin season page proposes for the board games (plan "Mühle
     * und Dame", P6) on top of this draft, or null while no board game is
     * switched on or they mine already: the weight of every board game and
     * mode, the share and daily limit of their share key
     * (`season.chain.board_games_proposal`), and the shares of the other
     * keys shrunk in proportion so that all of them add up to at most 100 %
     * (largest remainder; a tie goes to the larger share). Nothing is saved:
     * the board fills it into the form and saves it, or not.
     *
     * @param  Chain  $chain
     * @return array{weights: array<string, int>, shares: array<string, int>, daily: array<string, int>}|null
     */
    public static function boardGamesProposal(array $chain): ?array
    {
        /** @var array{weights: array<string, int>, share: int, daily: int} $proposal */
        $proposal = config('season.chain.board_games_proposal');
        $registry = app(GameRegistry::class);
        $keys = [];

        foreach (self::table($chain) as $shareKey => $weightKeys) {
            foreach ($weightKeys as $key) {
                if ($registry->isBoard(explode('/', $key, 2)[0])) {
                    $keys[$shareKey][] = $key;
                }
            }
        }

        if ($keys === [] || array_any(array_merge(...array_values($keys)), fn (string $key): bool => isset($chain['weights'][$key]))) {
            return null;
        }

        $weights = [];

        foreach (array_merge(...array_values($keys)) as $key) {
            $weights[$key] = (int) ($proposal['weights'][$key] ?? 1000);
        }

        $boardShares = array_fill_keys(array_keys($keys), (int) $proposal['share']);
        $others = array_diff_key($chain['shares'], $boardShares);
        $room = 100 - array_sum($boardShares);
        $shares = $others;

        if (array_sum($others) > $room) {
            // Largest remainder: each share scaled to the room, the seats left over to the largest remainders.
            $total = array_sum($others);
            $exact = array_map(fn (int $share): float => $share * $room / $total, $others);
            $shares = array_map(fn (float $share): int => (int) floor($share), $exact);
            $order = array_keys($exact);
            usort($order, fn (string $a, string $b): int => [($exact[$b] - floor($exact[$b])), $others[$b]] <=> [($exact[$a] - floor($exact[$a])), $others[$a]]);

            foreach (array_slice($order, 0, $room - array_sum($shares)) as $key) {
                $shares[$key]++;
            }
        }

        return [
            'weights' => $weights,
            'shares' => $shares + $boardShares,
            'daily' => array_fill_keys(array_keys($keys), (int) $proposal['daily']),
        ];
    }

    /**
     * The draft from the form. Refuses (SeasonReleaseRefused) what Block 0
     * must never sign: a value out of range, a game that mines without a
     * share and a daily limit, shares above 100 % in total, eras longer than
     * the season. Keys the form did not come from are ignored.
     *
     * @param  array{block0_at: CarbonImmutable|null, weeks: string, supply: string, subsidy: string, halving_days: string, claim_days: string, trust_minimum: string, message: string, weights: array<string, string>, shares: array<string, string>, daily: array<string, string>, pair_day: string, pair_season: string, subtree: string, moves: string}  $input
     * @return Chain
     *
     * @throws SeasonReleaseRefused
     */
    public static function fromInput(array $input): array
    {
        $defaults = self::defaults();
        $table = self::table($defaults);
        $number = function (mixed $value, string $limit, string $field, bool $grouped = false): int {
            [$min, $max] = self::LIMITS[$limit];
            $value = is_scalar($value) ? trim((string) $value) : '';

            // Sats may carry thousands separators ("2 100 000", "2.100.000"); nothing else may.
            if ($grouped) {
                $value = (string) preg_replace('/[\s\x{00A0}\x{202F}.,_]/u', '', $value);
            }

            if (preg_match('/^\d{1,9}$/', (string) $value) !== 1 || (int) $value < $min || (int) $value > $max) {
                throw new SeasonReleaseRefused(__(':field must be a whole number from :min to :max.', ['field' => $field, 'min' => $min, 'max' => $max]));
            }

            return (int) $value;
        };

        $weeks = trim($input['weeks']);

        if (preg_match('/^\d{1,3}$/', $weeks) !== 1 || (int) $weeks < SeasonPlans::minWeeks() || (int) $weeks > SeasonPlans::maxWeeks()) {
            throw new SeasonReleaseRefused(__('The length is a whole number of weeks from :min to :max.', ['min' => SeasonPlans::minWeeks(), 'max' => SeasonPlans::maxWeeks()]));
        }

        $message = trim($input['message']);

        if (mb_strlen($message) > SeasonRelease::MESSAGE_MAX) {
            throw new SeasonReleaseRefused(__('Write the genesis message, up to :max characters.', ['max' => SeasonRelease::MESSAGE_MAX]));
        }

        $weights = [];

        foreach (array_merge(...array_values($table)) as $key) {
            $factor = str_replace(',', '.', trim($input['weights'][$key] ?? ''));

            if ($factor === '' || preg_match('/^0+(\.0+)?$/', $factor) === 1) {
                continue; // empty or 0: this game and mode does not mine
            }

            [$min, $max] = self::LIMITS['weight'];
            $milli = preg_match('/^\d{1,2}(\.\d{1,3})?$/', $factor) === 1 ? (int) round((float) $factor * 1000) : -1;

            if ($milli < $min || $milli > $max) {
                throw new SeasonReleaseRefused(__('The weight of :key must be a number from 0.001 to 10 with at most three decimals, or empty.', ['key' => ChainOverview::keyLabel($key)]));
            }

            $weights[$key] = $milli;
        }

        $shares = [];
        $daily = [];

        foreach ($table as $shareKey => $keys) {
            $label = self::shareLabel($shareKey);
            $mines = array_intersect($keys, array_keys($weights)) !== [];
            $share = trim($input['shares'][$shareKey] ?? '');
            $limit = trim($input['daily'][$shareKey] ?? '');

            if ($mines && ($share === '' || $limit === '')) {
                // The board games' share group is plural: its own sentence.
                throw new SeasonReleaseRefused($shareKey === 'board-games'
                    ? __('Board games mine, so they need a share and a daily limit.')
                    : __(':game mines, so it needs a share and a daily limit.', ['game' => $label]));
            }

            if ($share !== '') {
                $shares[$shareKey] = $number($share, 'share', __(':game share %', ['game' => $label]));
            }

            if ($limit !== '') {
                $daily[$shareKey] = $number($limit, 'daily', __(':game a day', ['game' => $label]));
            }
        }

        if (array_sum($shares) > 100) {
            throw new SeasonReleaseRefused(__('The shares add up to :sum %. Together they can be at most 100 %.', ['sum' => array_sum($shares)]));
        }

        $chain = [
            'block0_at' => $input['block0_at']?->getTimestamp(),
            'weeks' => (int) $weeks,
            'supply' => $number($input['supply'], 'supply', __('Supply'), true),
            'subsidy' => $number($input['subsidy'], 'subsidy', __('Base subsidy'), true),
            'halving_days' => $number($input['halving_days'], 'halving_days', __('Days per era')),
            'claim_days' => $number($input['claim_days'], 'claim_days', __('Claim window in days')),
            'trust_minimum' => $number($input['trust_minimum'], 'trust_minimum', __('Trust rank for rated play')),
            'message' => $message === '' ? null : $message,
            'weights' => $weights,
            'groups' => $defaults['groups'],
            'shares' => $shares,
            'daily' => $daily,
            'pairlimit' => [$number($input['pair_day'], 'pairlimit.day', __('Blocks per pairing a day')), $number($input['pair_season'], 'pairlimit.season', __('Blocks per pairing a season'))],
            'subtree' => $number($input['subtree'], 'subtree', __('Same trust circle from % (101 = off)')),
            'moves' => $number($input['moves'], 'moves', __('Minimum moves (chess, board games)')),
        ];

        if ($chain['halving_days'] > self::weeks($chain) * 7) {
            throw new SeasonReleaseRefused(__('An era of :days days is longer than the season of :weeks weeks.', ['days' => $chain['halving_days'], 'weeks' => self::weeks($chain)]));
        }

        return $chain;
    }

    /**
     * What the board should look at, but may release: shares below 100 %,
     * a game that mines while its rated play is off, a game and mode of the
     * registry without a weight, and the estimator's warnings.
     *
     * @param  Chain  $chain
     * @param  list<string>  $estimatorWarnings
     * @return list<string>
     */
    public static function warnings(array $chain, array $estimatorWarnings = []): array
    {
        $warnings = [];
        $sum = array_sum($chain['shares']);

        if ($sum < 100) {
            $warnings[] = __('The shares add up to :sum %: :rest % of every era budget cannot be mined.', ['sum' => $sum, 'rest' => 100 - $sum]);
        }

        foreach (array_keys($chain['weights']) as $key) {
            if (! ChainOverview::mines($key)) {
                $warnings[] = __(':key has a weight, but its rated play is off, so its wins do not mine until it opens.', ['key' => ChainOverview::keyLabel($key)]);
            }
        }

        $unweighted = array_values(array_diff(array_merge(...array_values(self::table($chain))), array_keys($chain['weights'])));

        if ($unweighted !== []) {
            $warnings[] = __('No weight, so these do not mine: :keys.', ['keys' => implode(', ', array_map(ChainOverview::keyLabel(...), $unweighted))]);
        }

        foreach ($estimatorWarnings as $warning) {
            $warnings[] = self::estimatorWarning($warning);
        }

        return $warnings;
    }

    public static function estimatorWarning(string $warning): string
    {
        return match ($warning) {
            'season-shorter-than-min-weeks' => __('The season is shorter than :weeks weeks.', ['weeks' => (int) config('season.estimator.min_weeks')]),
            'season-longer-than-max-weeks' => __('The season is longer than :weeks weeks.', ['weeks' => (int) config('season.estimator.max_weeks')]),
            'supply-mined-before-min-weeks' => __('At this rate the supply is mined out in under :weeks weeks.', ['weeks' => (int) config('season.estimator.min_weeks')]),
            'most-of-the-supply-stays-unmined' => __('At this rate most of the supply stays unmined.'),
            default => $warning,
        };
    }

    /**
     * Save the chain draft with its audit row (RatingSettings::saveDraft():
     * board only, refused while a season is live); null when nothing changed.
     *
     * @param  Chain  $chain  from fromInput()
     *
     * @throws SeasonReleaseRefused
     */
    public static function save(User $admin, array $chain): ?SeasonSettingChange
    {
        return RatingSettings::saveDraft($admin, RatingSettings::draft(), $chain);
    }

    /**
     * Dot path => [before, after] for every value that differs.
     *
     * @param  Chain  $before
     * @param  Chain  $after
     * @return array<string, array{0: int|string|null, 1: int|string|null}>
     */
    public static function diff(array $before, array $after): array
    {
        $flat = function (array $chain): array {
            $values = [];

            foreach ($chain as $field => $value) {
                if (in_array($field, ['weights', 'shares', 'daily'], true)) {
                    foreach ($value as $key => $number) {
                        $values['chain.'.$field.'.'.$key] = $number;
                    }
                } elseif ($field === 'groups') {
                    foreach ($value as $key => $games) {
                        $values['chain.groups.'.$key] = implode(' + ', $games);
                    }
                } elseif ($field === 'pairlimit') {
                    $values['chain.pairlimit'] = $value[0].' / '.$value[1];
                } else {
                    $values['chain.'.$field] = $value;
                }
            }

            return $values;
        };

        $old = $flat($before);
        $new = $flat($after);
        $changes = [];

        foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $path) {
            if (($old[$path] ?? null) !== ($new[$path] ?? null)) {
                $changes[$path] = [$old[$path] ?? null, $new[$path] ?? null];
            }
        }

        return $changes;
    }
}
