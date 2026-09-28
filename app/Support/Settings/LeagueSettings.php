<?php

namespace App\Support\Settings;

use App\Models\LeagueSettingChange;
use App\Models\User;
use App\Support\Board;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The league settings an admin changes on /admin/settings (P44): one
 * allow-list of operational values, each with its type, range, label, help
 * text and group. config/esports.php and config/season.php stay the
 * defaults; a change is a row in the append-only log (LeagueSettingChange),
 * and the newest row of a key is its value in force.
 *
 * Readers call get() with a config path instead of config(): the value of a
 * listed key, or the config array below a path with the listed keys in it
 * replaced. The overrides are looked up once per request or queue job
 * (forgotten before every job, AppServiceProvider, and after every change),
 * never written into config() at boot.
 *
 * Not in the list, by construction: the chain rules (signed, the board's
 * chain draft, P43, and Parameter Changes 2158), the rating values frozen
 * at Block 0 (RatingSettings), NIP constants and protocol limits
 * (`season.global_rating_min_weight`, `season.clan_rating_top`), and every
 * secret, key and relay list (`.env` only). save() refuses any key that is
 * not listed.
 *
 * `new_only`: the value is copied onto an object when it is created (a
 * casual 1v1 pins its deadlines at the pairing, a cup its start at the
 * opening), so a change reaches new objects only; the help text says so.
 * `board_only`: only a board member on the public admin list may change it.
 *
 * A stored value that no longer passes its definition (a range tightened
 * later) is ignored: the default applies.
 *
 * @phpstan-type Definition array{group: string, label: string, help: string, type: 'int'|'ints'|'time'|'weekday', min: int, max: int, count?: array{0: int, 1: int}, new_only: bool, board_only: bool}
 */
final class LeagueSettings
{
    private const MEMO = 'league-settings.overrides';

    public const WEEKDAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    /**
     * Group key => label, in page order.
     *
     * @return array<string, string>
     */
    public static function groups(): array
    {
        return [
            'casual_cups' => __('Casual cups'),
            'casual' => __('Casual 1v1'),
            'fair_play' => __('Fair play'),
            'chess' => __('Chess queue'),
            'stream_bot' => __('Stream bot: free places'),
            'estimator' => __('Season estimator'),
        ];
    }

    /**
     * The allow-list, by config path, in page order.
     *
     * @return array<string, Definition>
     */
    public static function definitions(): array
    {
        $pinned = __('Pinned when a match is paired: running matches keep their deadline, new matches get the new one.');
        $cupStart = __('A cup gets its start when it opens: open cups keep theirs, the next cup of the region opens on the new slot.');

        return [
            'esports.casual_cups.regions.eu.weekday' => self::weekday('casual_cups', __('EU start day'), $cupStart, newOnly: true),
            'esports.casual_cups.regions.eu.time' => self::time('casual_cups', __('EU start time (Europe/Berlin)'), $cupStart, newOnly: true),
            'esports.casual_cups.regions.us.weekday' => self::weekday('casual_cups', __('US start day'), $cupStart, newOnly: true),
            'esports.casual_cups.regions.us.time' => self::time('casual_cups', __('US start time (America/New_York)'), $cupStart, newOnly: true),
            'esports.casual_cups.min_signup_hours' => self::int('casual_cups', __('Sign-up at least (hours)'), 1, 336, __('A new cup starts at its region’s next start time that leaves at least this much sign-up. Open cups keep their start.'), newOnly: true),
            'esports.casual_cups.sizes' => self::ints('casual_cups', __('Places a cup grows through'), 4, 64, [1, 5], __('Smallest first, separated by commas. A new cup opens with the first size; a cup in sign-up grows along the new sizes from its next step.')),
            'esports.casual_cups.evening.start' => self::time('casual_cups', __('Start of a small cup’s evening'), __('In the cup’s region zone, the day after sign-up closed. Applies to every cup whose sign-up closes after the change; a planned evening keeps its start.')),

            'esports.casual.ready_seconds' => self::int('casual', __('Press Ready (seconds)'), 15, 600, $pinned, newOnly: true),
            'esports.casual.lobby_minutes' => self::int('casual', __('Host shares the lobby (minutes)'), 1, 60, $pinned, newOnly: true),
            'esports.casual.join_minutes' => self::int('casual', __('Guest joins (minutes)'), 1, 60, $pinned, newOnly: true),
            'esports.casual.contest_minutes' => self::int('casual', __('Answer a no-show claim (minutes)'), 1, 60, $pinned, newOnly: true),
            'esports.casual.report_minutes' => self::int('casual', __('Report the result (minutes)'), 5, 1440, $pinned, newOnly: true),
            'esports.casual.confirm_minutes' => self::int('casual', __('Answer the report (minutes)'), 5, 1440, $pinned, newOnly: true),
            'esports.casual.lock.noshows' => self::int('casual', __('Missed matches before a pause'), 1, 10, __('Forfeited no-shows within the window that pause casual 1v1. Applies at once, to no-shows already recorded too.')),
            'esports.casual.lock.window_hours' => self::int('casual', __('Window for missed matches (hours)'), 1, 168, __('Applies at once.')),
            'esports.casual.lock.minutes' => self::int('casual', __('Pause from casual 1v1 (minutes)'), 1, 1440, __('Counted from the last missed match. Applies at once, to running pauses too.')),

            'esports.fair_play.false_reports' => self::int('fair_play', __('Confirmed false reports before a pause'), 1, 10, __('That many within the window bar a player from rated play. Applies at once, to reports already recorded too.'), boardOnly: true),
            'esports.fair_play.window_days' => self::int('fair_play', __('Window for false reports (days)'), 1, 365, __('Applies at once.'), boardOnly: true),
            'esports.fair_play.lock_days' => self::int('fair_play', __('Pause from rated play (days)'), 1, 90, __('Counted from the last false report. Applies at once, to running pauses too.'), boardOnly: true),

            'esports.chess.pairing_limit_per_day.rated' => self::int('chess', __('Rated games per pairing and day'), 1, 20, __('The chess queue pairs the same two players for rated games at most this often per day (UTC). Applies at once.')),

            'esports.stream_bot.free_places.special_slots_hours' => self::ints('stream_bot', __('Special tournaments: hours before sign-up closes'), 1, 720, [1, 6], __('One “places free” note per slot, separated by commas. Applies from the next run; a slot already posted is not posted again.')),
            'esports.stream_bot.free_places.cup_slots_hours' => self::ints('stream_bot', __('Casual cups: hours before sign-up closes'), 1, 720, [1, 6], __('One “places free” note per slot, separated by commas. Applies from the next run; a slot already posted is not posted again.')),
            'esports.stream_bot.free_places.stop_before_close_minutes' => self::int('stream_bot', __('No note in the last minutes before the close'), 0, 1440, __('Applies from the next run.')),
            'esports.stream_bot.free_places.per_run' => self::int('stream_bot', __('Notes per run at most'), 0, 10, __('0 pauses the notes. Applies from the next run.')),

            'season.estimator.window_days' => self::int('estimator', __('Forecast window (days)'), 7, 365, __('The estimator on the season page counts valid blocks per week over this many days. A forecast only; nothing signed changes.')),
        ];
    }

    /**
     * The value in force at a config path: the override of a listed key,
     * else the config; for a path above listed keys the config array with
     * their overrides in it.
     */
    public static function get(string $path): mixed
    {
        $value = config($path);

        foreach (self::overrides() as $key => $override) {
            if ($key === $path) {
                return $override;
            }

            if (is_array($value) && str_starts_with($key, $path.'.')) {
                data_set($value, substr($key, strlen($path) + 1), $override);
            }
        }

        return $value;
    }

    /** The default of a listed key: the config. */
    public static function default(string $key): mixed
    {
        return config($key);
    }

    /**
     * The overrides in force, config path => value, looked up once per
     * request or queue job.
     *
     * @return array<string, int|string|list<int>>
     */
    public static function overrides(): array
    {
        $attributes = request()->attributes;

        if (! $attributes->has(self::MEMO)) {
            $definitions = self::definitions();
            $overrides = [];

            foreach (self::newestRows() as $row) {
                if ($row->after === null || ! isset($definitions[$row->key])) {
                    continue;
                }

                $value = self::normalize($definitions[$row->key], $row->after);

                if ($value !== null) {
                    $overrides[$row->key] = $value;
                }
            }

            $attributes->set(self::MEMO, $overrides);
        }

        /** @var array<string, int|string|list<int>> */
        return $attributes->get(self::MEMO);
    }

    /**
     * The newest log row of every key, in one query. Without the log table
     * there are none, so the defaults apply: the data migration
     * 2026_09_28_163510 (CasualCups::splitIntoRegions()) reads the cup
     * settings before the table is created, and a deploy serves requests
     * before it migrates. Inside a transaction the query runs in a
     * savepoint, so the failed statement does not abort the transaction
     * (PostgreSQL). Every other database error is thrown.
     *
     * @return EloquentCollection<int, LeagueSettingChange>
     */
    private static function newestRows(): EloquentCollection
    {
        $query = fn (): EloquentCollection => LeagueSettingChange::query()
            ->whereIn('id', LeagueSettingChange::query()->selectRaw('max(id)')->groupBy('key'))
            ->get(['key', 'after']);

        try {
            return DB::transactionLevel() > 0 ? DB::transaction($query) : $query();
        } catch (QueryException $exception) {
            if (self::isMissingLog($exception)) {
                return new EloquentCollection;
            }

            throw $exception;
        }
    }

    /** "no such table" (SQLite), 42P01 (PostgreSQL), 42S02 (MySQL), for the log table. */
    private static function isMissingLog(QueryException $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, 'league_setting_changes')
            && (str_contains($message, 'no such table') || in_array((string) $exception->getCode(), ['42P01', '42S02'], true));
    }

    public static function forget(): void
    {
        request()->attributes->remove(self::MEMO);
    }

    /** Whether this admin may change this key. */
    public static function mayChange(User $admin, string $key): bool
    {
        $definition = self::definitions()[$key] ?? null;

        return $definition !== null && (! $definition['board_only'] || Board::contains($admin->pubkey));
    }

    /**
     * Save changed values, one log row per key whose value in force changes;
     * all or nothing. `$values`: config path => the input, null = back to
     * the default. The caller has checked the admin role.
     *
     * @param  array<string, mixed>  $values
     * @return list<LeagueSettingChange>
     *
     * @throws LeagueSettingRefused with a message per refused key
     */
    public static function save(User $admin, array $values): array
    {
        $definitions = self::definitions();
        $errors = [];
        $changes = [];

        foreach ($values as $key => $input) {
            $definition = $definitions[$key] ?? null;

            if ($definition === null) {
                $errors[$key] = __('This setting cannot be changed here.');

                continue;
            }

            $after = $input === null ? null : self::normalize($definition, $input);

            if ($input !== null && $after === null) {
                $errors[$key] = self::rule($definition);

                continue;
            }

            $overridden = array_key_exists($key, self::overrides());
            $before = self::get($key);

            if ($after === null ? ! $overridden : $after === $before) {
                continue;
            }

            if (! self::mayChange($admin, $key)) {
                $errors[$key] = __('Only a board member on the public admin list can change this value.');

                continue;
            }

            $changes[$key] = [$before, $after];
        }

        if ($errors !== []) {
            throw new LeagueSettingRefused($errors);
        }

        return DB::transaction(fn (): array => array_map(fn (string $key): LeagueSettingChange => LeagueSettingChange::query()->create([
            'key' => $key,
            'before' => $changes[$key][0],
            'after' => $changes[$key][1],
            'changed_by_id' => $admin->id,
            'changed_by_pubkey' => $admin->pubkey,
        ]), array_keys($changes)));
    }

    /**
     * The value as its definition stores it, or null when it does not pass:
     * an int in range, a list of ints in range ("4, 8, 16" or an array),
     * HH:MM, or an English weekday.
     *
     * @param  Definition  $definition
     * @return int|string|list<int>|null
     */
    public static function normalize(array $definition, mixed $input): int|string|array|null
    {
        return match ($definition['type']) {
            'int' => self::intIn($input, $definition['min'], $definition['max']),
            'ints' => self::intsIn($input, $definition),
            'time' => is_string($input) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', trim($input)) === 1 ? trim($input) : null,
            'weekday' => is_string($input) && in_array(strtolower(trim($input)), self::WEEKDAYS, true) ? strtolower(trim($input)) : null,
        };
    }

    /**
     * What a value must be, as shown under the field and as the error.
     *
     * @param  Definition  $definition
     */
    public static function rule(array $definition): string
    {
        return match ($definition['type']) {
            'int' => __('A whole number from :min to :max.', ['min' => $definition['min'], 'max' => $definition['max']]),
            'ints' => __(':least to :most whole numbers from :min to :max, separated by commas.', ['least' => $definition['count'][0] ?? 1, 'most' => $definition['count'][1] ?? 1, 'min' => $definition['min'], 'max' => $definition['max']]),
            'time' => __('A time as HH:MM, from 00:00 to 23:59.'),
            'weekday' => __('A day of the week.'),
        };
    }

    /** A value as the form and the log show it. */
    public static function display(mixed $value): string
    {
        return match (true) {
            $value === null => '–',
            is_array($value) => implode(', ', array_map(strval(...), $value)),
            is_string($value) && in_array($value, self::WEEKDAYS, true) => __(ucfirst($value)),
            is_scalar($value) => (string) $value,
            default => '–',
        };
    }

    private static function intIn(mixed $input, int $min, int $max): ?int
    {
        if (is_string($input)) {
            $input = trim($input);
        }

        if (! is_int($input) && ! (is_string($input) && preg_match('/^-?\d{1,9}$/', $input) === 1)) {
            return null;
        }

        $value = (int) $input;

        return $value >= $min && $value <= $max ? $value : null;
    }

    /**
     * @param  Definition  $definition
     * @return list<int>|null
     */
    private static function intsIn(mixed $input, array $definition): ?array
    {
        $parts = is_string($input) ? array_map(trim(...), explode(',', $input)) : $input;

        if (! is_array($parts)) {
            return null;
        }

        $values = [];

        foreach ($parts as $part) {
            $value = self::intIn($part, $definition['min'], $definition['max']);

            if ($value === null) {
                return null;
            }

            $values[] = $value;
        }

        [$least, $most] = $definition['count'] ?? [1, 1];

        return count($values) >= $least && count($values) <= $most && count(array_unique($values)) === count($values) ? $values : null;
    }

    /** @return Definition */
    private static function int(string $group, string $label, int $min, int $max, string $help, bool $newOnly = false, bool $boardOnly = false): array
    {
        return ['group' => $group, 'label' => $label, 'help' => $help, 'type' => 'int', 'min' => $min, 'max' => $max, 'new_only' => $newOnly, 'board_only' => $boardOnly];
    }

    /**
     * @param  array{0: int, 1: int}  $count
     * @return Definition
     */
    private static function ints(string $group, string $label, int $min, int $max, array $count, string $help): array
    {
        return ['group' => $group, 'label' => $label, 'help' => $help, 'type' => 'ints', 'min' => $min, 'max' => $max, 'count' => $count, 'new_only' => false, 'board_only' => false];
    }

    /** @return Definition */
    private static function time(string $group, string $label, string $help, bool $newOnly = false): array
    {
        return ['group' => $group, 'label' => $label, 'help' => $help, 'type' => 'time', 'min' => 0, 'max' => 0, 'new_only' => $newOnly, 'board_only' => false];
    }

    /** @return Definition */
    private static function weekday(string $group, string $label, string $help, bool $newOnly = false): array
    {
        return ['group' => $group, 'label' => $label, 'help' => $help, 'type' => 'weekday', 'min' => 0, 'max' => 0, 'new_only' => $newOnly, 'board_only' => false];
    }
}
