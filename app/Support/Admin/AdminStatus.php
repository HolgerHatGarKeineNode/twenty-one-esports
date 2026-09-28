<?php

namespace App\Support\Admin;

use App\Enums\PayoutStatus;
use App\Enums\TournamentStatus;
use App\Models\Admin;
use App\Models\BotPost;
use App\Models\ChessQueueEntry;
use App\Models\RelayDelivery;
use App\Models\SeriesMatch;
use App\Models\SeriesQueueEntry;
use App\Models\Tournament;
use App\Models\TournamentOrganizer;
use App\Models\TournamentPayout;
use App\Models\TrustRun;
use App\Support\Board;
use App\Support\PreSeason;
use App\Support\SeasonChain\Seasons;
use App\Support\Tournaments\TournamentScheduler;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Throwable;

/**
 * The admin status page (P17): what the league, the tournaments, Nostr and
 * the servers are doing, read-only. Nothing here writes, sends or connects
 * to a relay; every check reads the database, the cache, the queue or the
 * log. The two that read more than a few rows (relay answers of the last
 * 24 h, errors in the log) are cached for CACHE_SECONDS.
 *
 * Keys are reported as set or not set, never with any part of their value.
 *
 * One check: `state` is `ok`, `attention` (someone should look) or `down`
 * (something that players depend on is not working); `value` is the short
 * answer, `detail` the one sentence behind it; `href`/`action` the admin
 * page where you act on it; `items` a list of named yes/no facts.
 *
 * @phpstan-type StatusItem array{label: string, ok: bool, text: string}
 * @phpstan-type StatusCheck array{key: string, name: string, sub: string, state: 'ok'|'attention'|'down', value: string, detail: string, href: string|null, action: string|null, items: list<StatusItem>}
 * @phpstan-type StatusGroup array{key: string, checks: list<StatusCheck>}
 */
final class AdminStatus
{
    public const CACHE_SECONDS = 300;

    public const RELAYS_CACHE_KEY = 'admin-status:relays';

    public const ERRORS_CACHE_KEY = 'admin-status:errors';

    /** At most this much of each log file is read, from its end. */
    public const LOG_TAIL_BYTES = 5_000_000;

    /** A trust run older than this is late (it runs every 15 minutes). */
    public const TRUST_LATE_MINUTES = 60;

    /** An open case older than this is overdue (the disputes page marks it red). */
    public const CASE_OVERDUE_HOURS = 48;

    /**
     * The secrets and connection strings the league needs, config key => label.
     * Only whether each is set is ever read.
     */
    public const KEYS = [
        'esports.league.nsec' => 'League key',
        'esports.trust.nsec' => 'Trust key',
        'esports.badges.nsec' => 'Badge key',
        'esports.notifications.nsec' => 'Notification key',
        'esports.wallet.nwc_uri' => 'League wallet (NWC)',
        'esports.wallet.nwc_receive_uri' => 'Receiving wallet (NWC)',
        'esports.wallet.lnurl_nsec' => 'Zap receipt key',
        'twentyone.nostr.nsec' => 'Stream key',
        'esports.stream_bot.nsec' => 'Stream bot key',
        'esports.webpush.private_key' => 'Web push key',
    ];

    /**
     * The four groups of the admin nav (App\Support\Navigation\AdminNavigation), each with its checks.
     *
     * @return list<StatusGroup>
     */
    public function groups(): array
    {
        return [
            ['key' => 'league', 'checks' => [$this->season(), $this->disputes()]],
            ['key' => 'tournaments', 'checks' => [$this->payouts(), $this->casualCups(), $this->casualQueue(), $this->botNotes()]],
            ['key' => 'people', 'checks' => [$this->trust(), $this->roles()]],
            ['key' => 'system', 'checks' => [$this->scheduler(), $this->queue(), $this->errors(), $this->keys(), $this->relays()]],
        ];
    }

    /**
     * @return StatusCheck
     */
    public function season(): array
    {
        $state = Seasons::state();
        $href = route('admin.season');

        if ($state === 'live') {
            $season = Seasons::live();

            return self::check('season', __('Season'), __('rated play and mining'), 'ok', __('Live'),
                __('Season :slug runs until :end.', ['slug' => $season?->slug, 'end' => self::date($season?->ends_at)]), $href, __('Seasons'));
        }

        if ($state === 'between') {
            return self::check('season', __('Season'), __('rated play and mining'), 'attention', __('Between seasons'),
                __('The last season ended :end. Rated play and mining rest until the board releases a new Block 0.', ['end' => self::date(Seasons::latest()?->ends_at)]), $href, __('Seasons'));
        }

        $block0 = PreSeason::block0At();

        return match (true) {
            $block0 === null => self::check('season', __('Season'), __('rated play and mining'), 'attention', __('No Block 0 date'),
                __('Before Block 0, and no date is set (ESPORTS_BLOCK0_AT). Every game is casual.'), $href, __('Seasons')),
            $block0->isFuture() => self::check('season', __('Season'), __('rated play and mining'), 'ok', __('Before Block 0'),
                __('Block 0 is planned for :when.', ['when' => self::date($block0)]), $href, __('Seasons')),
            default => self::check('season', __('Season'), __('rated play and mining'), 'attention', __('Block 0 due'),
                __('Block 0 was due :when and is not released yet.', ['when' => self::date($block0)]), $href, __('Seasons')),
        };
    }

    /**
     * @return StatusCheck
     */
    public function disputes(): array
    {
        $open = SeriesMatch::query()->openCase()->count();
        $overdue = $open === 0 ? 0 : SeriesMatch::query()->openCase()->where('updated_at', '<=', now()->subHours(self::CASE_OVERDUE_HOURS))->count();

        return self::check('disputes', __('Disputes'), __('series results a captain contested'),
            match (true) {
                $overdue > 0 => 'down',
                $open > 0 => 'attention',
                default => 'ok',
            },
            trans_choice(':count open|:count open', $open),
            match (true) {
                $overdue > 0 => trans_choice(':count case is open for more than 48 hours.|:count cases are open for more than 48 hours.', $overdue),
                $open > 0 => __('Waiting for an admin decision.'),
                default => __('Every result is settled.'),
            },
            route('admin.disputes'), __('Disputes'));
    }

    /**
     * @return StatusCheck
     */
    public function trust(): array
    {
        $run = TrustRun::query()->latest('id')->first();
        $href = route('admin.trust');

        if ($run === null) {
            return self::check('trust', __('Trust job'), __('ranks for rated play, every 15 min'), 'attention', __('Never ran'),
                __('No trust run is saved yet. Rated play opens once it has run in the live season.'), $href, __('Trust'));
        }

        $late = $run->computed_at->lt(now()->subMinutes(self::TRUST_LATE_MINUTES));

        return self::check('trust', __('Trust job'), __('ranks for rated play, every 15 min'), $late ? 'attention' : 'ok',
            $late ? __('Late') : __('Ran'),
            __('Last run :ago: :ranked ranked, :published published.', ['ago' => $run->computed_at->diffForHumans(), 'ranked' => $run->ranked, 'published' => $run->published]),
            $href, __('Trust'));
    }

    /**
     * @return StatusCheck
     */
    public function roles(): array
    {
        $board = count(Board::pubkeys());
        $admins = Admin::query()->count();
        $organizers = TournamentOrganizer::query()->count();

        return self::check('roles', __('Roles'), __('who may decide and organize'), 'ok',
            trans_choice(':count admin|:count admins', $board + $admins),
            __(':board from the board, :admins added here.', ['board' => $board, 'admins' => $admins]).' '.trans_choice(':count organizer|:count organizers', $organizers).'.',
            route('admin.admins'), __('Admins'));
    }

    /**
     * @return StatusCheck
     */
    public function payouts(): array
    {
        /** @var array<string, int> $counts */
        $counts = TournamentPayout::query()->whereIn('status', [PayoutStatus::Open, PayoutStatus::Pending, PayoutStatus::Paying, PayoutStatus::Failed])
            ->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status')->map(fn ($count): int => (int) $count)->all();

        $failed = $counts[PayoutStatus::Failed->value] ?? 0;
        $pending = $counts[PayoutStatus::Pending->value] ?? 0;
        $paying = $counts[PayoutStatus::Paying->value] ?? 0;
        $open = $counts[PayoutStatus::Open->value] ?? 0;

        $parts = array_filter([
            $pending > 0 ? trans_choice(':count ready to pay|:count ready to pay', $pending) : null,
            $failed > 0 ? trans_choice(':count failed|:count failed', $failed) : null,
            $paying > 0 ? trans_choice(':count paying|:count paying', $paying) : null,
            $open > 0 ? trans_choice(':count without a Lightning address|:count without a Lightning address', $open) : null,
        ]);

        return self::check('payouts', __('Payouts'), __('prize money of ended tournaments'),
            $failed > 0 || $pending > 0 ? 'attention' : 'ok',
            $failed + $pending > 0 ? trans_choice(':count to send|:count to send', $failed + $pending) : __('Nothing to send'),
            $parts === [] ? __('No payout is waiting.') : ucfirst(implode(', ', $parts)).'.',
            route('admin.payouts'), __('Payouts'));
    }

    /**
     * @return StatusCheck
     */
    public function casualCups(): array
    {
        /** @var array<string, int> $counts */
        $counts = Tournament::query()->casualCup()->whereIn('status', [TournamentStatus::Signup, TournamentStatus::Drawing, TournamentStatus::Running])
            ->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status')->map(fn ($count): int => (int) $count)->all();

        $signup = $counts[TournamentStatus::Signup->value] ?? 0;
        $running = ($counts[TournamentStatus::Running->value] ?? 0) + ($counts[TournamentStatus::Drawing->value] ?? 0);

        return self::check('casual-cups', __('Casual cups'), __('the league’s automatic cups'), 'ok',
            trans_choice(':count active|:count active', $signup + $running),
            __(':signup open for sign-up, :running drawing or running.', ['signup' => $signup, 'running' => $running]),
            route('admin.tournaments'), __('Tournaments'));
    }

    /**
     * @return StatusCheck
     */
    public function casualQueue(): array
    {
        $series = SeriesQueueEntry::query()->count();
        $chess = ChessQueueEntry::query()->count();

        return self::check('casual-queue', __('Casual queue'), __('players searching a game now'), 'ok',
            trans_choice(':count searching|:count searching', $series + $chess),
            __(':series searching a casual 1v1, :chess in the chess queue.', ['series' => $series, 'chess' => $chess]),
            null, null);
    }

    /**
     * @return StatusCheck
     */
    public function botNotes(): array
    {
        $enabled = (bool) config('esports.stream_bot.enabled') && filled(config('esports.stream_bot.nsec'));
        // A free-places note (P49) is not retried once its slot passed, so an unsent one counts for a day only.
        $unsent = BotPost::query()->whereNull('published_at')
            ->where(fn ($query) => $query->where('subject_type', '!=', BotPost::SUBJECT_FREE_PLACES)->orWhere('created_at', '>=', now()->subDay()));
        $failed = (clone $unsent)->where('attempts', '>', 0)->count();
        $waiting = (clone $unsent)->where('attempts', 0)->count();
        $sent = BotPost::query()->whereNotNull('published_at')->where('published_at', '>=', now()->subDay())->count();

        return self::check('bot-notes', __('Tournament notes'), __('the stream bot’s notes on its profile'),
            $failed > 0 ? 'attention' : 'ok',
            match (true) {
                $failed > 0 => trans_choice(':count failed|:count failed', $failed),
                ! $enabled => __('Bot off'),
                default => trans_choice(':count sent in 24 h|:count sent in 24 h', $sent),
            },
            __(':failed not accepted by any relay yet, :waiting waiting, :sent sent in the last 24 h.', ['failed' => $failed, 'waiting' => $waiting, 'sent' => $sent])
                .($enabled ? '' : ' '.__('The stream bot is off (ESPORTS_STREAM_BOT_ENABLED and its key).')),
            null, null);
    }

    /**
     * @return StatusCheck
     */
    public function keys(): array
    {
        $items = [];

        foreach (self::KEYS as $config => $label) {
            $set = filled(config($config));
            $items[] = ['label' => __($label), 'ok' => $set, 'text' => $set ? __('set') : __('not set')];
        }

        $missing = array_values(array_filter($items, fn (array $item): bool => ! $item['ok']));
        $leagueMissing = ! filled(config('esports.league.nsec'));

        return self::check('keys', __('Keys'), __('secrets in .env, never shown'),
            match (true) {
                $leagueMissing => 'down',
                $missing !== [] => 'attention',
                default => 'ok',
            },
            __(':set of :total set', ['set' => count($items) - count($missing), 'total' => count($items)]),
            $missing === [] ? __('Every key is set.') : __('Not set: :keys.', ['keys' => implode(', ', array_column($missing, 'label'))]),
            null, null, $items);
    }

    /**
     * Relays: the configured sets, and for the relays the server publishes to
     * (league and stream) whether one accepted an event in the last 24 h,
     * from the delivery records the publisher keeps. No relay is contacted.
     *
     * @return StatusCheck
     */
    public function relays(): array
    {
        $sets = [
            'league' => [__('League'), config('esports.relays', [])],
            'stream' => [__('Stream'), config('twentyone.stream.relays', [])],
            'chat' => [__('Chat'), config('esports.chat.relays', [])],
            'profile' => [__('Profiles'), config('esports.profile_relays', [])],
        ];

        /** @var array<string, array{accepted: int, failed: int}> $answers */
        $answers = self::cached(self::RELAYS_CACHE_KEY, fn (): array => RelayDelivery::query()
            ->where('attempted_at', '>=', now()->subDay())
            ->selectRaw('relay, sum(case when accepted then 1 else 0 end) as accepted, sum(case when accepted then 0 else 1 end) as failed')
            ->groupBy('relay')->get()
            ->mapWithKeys(fn (RelayDelivery $row): array => [(string) $row->relay => ['accepted' => (int) $row->getAttribute('accepted'), 'failed' => (int) $row->getAttribute('failed')]])
            ->all(), []);

        $items = [];
        $published = [...(array) $sets['league'][1], ...(array) $sets['stream'][1]];
        $published = array_values(array_unique(array_map(strval(...), $published)));

        foreach ($sets as $key => [$label, $relays]) {
            $relays = array_values(array_map(strval(...), (array) $relays));
            $checked = in_array($key, ['league', 'stream'], true);
            $reached = count(array_filter($relays, fn (string $relay): bool => ($answers[$relay]['accepted'] ?? 0) > 0));
            $items[] = [
                'label' => $label,
                'ok' => $relays !== [] && (! $checked || $reached > 0 || ! self::attempted($relays, $answers)),
                'text' => match (true) {
                    $relays === [] => __('none configured'),
                    ! $checked => trans_choice(':count relay, read by the browser|:count relays, read by the browser', count($relays)),
                    ! self::attempted($relays, $answers) => trans_choice(':count relay, nothing sent in 24 h|:count relays, nothing sent in 24 h', count($relays)),
                    default => __(':reached of :count accepted an event in 24 h', ['reached' => $reached, 'count' => count($relays)]),
                },
            ];
        }

        $refusing = array_values(array_filter($published, fn (string $relay): bool => isset($answers[$relay]) && $answers[$relay]['accepted'] === 0));
        $reachedAny = array_filter($published, fn (string $relay): bool => ($answers[$relay]['accepted'] ?? 0) > 0) !== [];
        $leagueEmpty = (array) $sets['league'][1] === [];

        $state = match (true) {
            $leagueEmpty => 'attention',
            self::attempted($published, $answers) && ! $reachedAny => 'down',
            $refusing !== [] => 'attention',
            default => 'ok',
        };

        return self::check('relays', __('Relays'), __('where league events are published'), $state,
            match (true) {
                $leagueEmpty => __('No league relays'),
                ! self::attempted($published, $answers) => __('Nothing sent in 24 h'),
                default => __(':reached of :count reached', ['reached' => count(array_filter($published, fn (string $relay): bool => ($answers[$relay]['accepted'] ?? 0) > 0)), 'count' => count($published)]),
            },
            $refusing === []
                ? __('From the publisher’s delivery records of the last 24 h; no relay is contacted from this page.')
                : __('No event accepted in 24 h by: :relays.', ['relays' => implode(', ', array_map(fn (string $relay): string => (string) preg_replace('#^wss?://#', '', $relay), $refusing))]),
            null, null, $items);
    }

    /**
     * @return StatusCheck
     */
    public function scheduler(): array
    {
        $health = TournamentScheduler::health();
        $last = $health['last_run_at'];

        return self::check('scheduler', __('Scheduler'), __('heartbeat of tournaments:tick, every minute'), $health['stale'] ? 'down' : 'ok',
            $health['stale'] ? __('Stopped') : __('Running'),
            $last === null
                ? __('No heartbeat yet: sign-ups do not close, draws and deadlines wait until `schedule:run` runs every minute.')
                : __('Last tick :ago.', ['ago' => $last->diffForHumans()]),
            route('admin.tournaments'), __('Tournaments'));
    }

    /**
     * @return StatusCheck
     */
    public function queue(): array
    {
        $connection = (string) config('queue.default');
        $size = null;
        $horizon = null;

        if ($connection !== 'sync') {
            try {
                $size = Queue::connection($connection)->size();
            } catch (Throwable $e) {
                report($e);
            }
        }

        if ($connection === 'redis') {
            try {
                $masters = app(MasterSupervisorRepository::class)->all();
                $horizon = $masters === [] ? 'inactive' : (collect($masters)->contains(fn ($master): bool => ($master->status ?? null) === 'paused') ? 'paused' : 'running');
            } catch (Throwable $e) {
                report($e);
                $horizon = 'unknown';
            }
        }

        $failed = 0;

        try {
            $failed = DB::table((string) config('queue.failed.table', 'failed_jobs'))->where('failed_at', '>=', now()->subDay())->count();
        } catch (Throwable) {
            // No failed-jobs table (a driver without one): nothing to count.
        }

        $state = match (true) {
            in_array($horizon, ['inactive', 'paused', 'unknown'], true) => 'down',
            $failed > 0 => 'attention',
            default => 'ok',
        };

        return self::check('queue', __('Queue'), __('jobs: relays, payouts, notifications'), $state,
            match ($horizon) {
                'running' => __('Horizon running'),
                'paused' => __('Horizon paused'),
                'inactive' => __('Horizon stopped'),
                'unknown' => __('Horizon unknown'),
                default => $connection === 'sync' ? __('Runs inline') : __('Connection :name', ['name' => $connection]),
            },
            trim(($size === null ? '' : trans_choice(':count job waiting.|:count jobs waiting.', $size).' ')
                .trans_choice(':count job failed in the last 24 h.|:count jobs failed in the last 24 h.', $failed)),
            $connection === 'redis' ? url('horizon') : null, $connection === 'redis' ? (string) __('Open Horizon') : null);
    }

    /**
     * Errors of the last 24 h, counted in the application log, never shown.
     *
     * @return StatusCheck
     */
    public function errors(): array
    {
        /** @var array{errors: int, critical: int, truncated: bool, files: int} $count */
        $count = self::cached(self::ERRORS_CACHE_KEY, fn (): array => self::countLogErrors(), ['errors' => 0, 'critical' => 0, 'truncated' => false, 'files' => 0]);
        $total = $count['errors'] + $count['critical'];

        return self::check('errors', __('Errors'), __('application log, last 24 h'),
            match (true) {
                $count['critical'] > 0 => 'down',
                $total > 0 => 'attention',
                default => 'ok',
            },
            trans_choice(':count error|:count errors', $total),
            ($count['files'] === 0
                ? __('No log file was written in the last 24 h.')
                : __(':errors errors and :critical critical or worse; counts only, the messages stay in the log.', ['errors' => $count['errors'], 'critical' => $count['critical']]))
                .($count['truncated'] ? ' '.__('Counted in the newest 5 MB of each file.') : ''),
            null, null);
    }

    /**
     * Counts ERROR and worse lines stamped within the last 24 h in the log
     * files written in that time (single and daily channels).
     *
     * @return array{errors: int, critical: int, truncated: bool, files: int}
     */
    public static function countLogErrors(?string $directory = null): array
    {
        $directory ??= storage_path('logs');
        $since = CarbonImmutable::now()->subDay();
        $result = ['errors' => 0, 'critical' => 0, 'truncated' => false, 'files' => 0];

        foreach (glob($directory.'/laravel*.log') ?: [] as $file) {
            $modified = @filemtime($file);

            if ($modified === false || $modified < $since->getTimestamp()) {
                continue;
            }

            $handle = @fopen($file, 'rb');

            if ($handle === false) {
                continue;
            }

            $result['files']++;
            $size = (int) @filesize($file);

            if ($size > self::LOG_TAIL_BYTES) {
                fseek($handle, -self::LOG_TAIL_BYTES, SEEK_END);
                fgets($handle); // the cut, partial line
                $result['truncated'] = true;
            }

            while (($line = fgets($handle)) !== false) {
                if ($line === '' || $line[0] !== '[') {
                    continue;
                }

                if (preg_match('/^\[(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2})[^\]]*\] [\w-]+\.(ERROR|CRITICAL|ALERT|EMERGENCY):/', $line, $match) !== 1) {
                    continue;
                }

                try {
                    $at = CarbonImmutable::parse($match[1]);
                } catch (Throwable) {
                    continue;
                }

                if ($at->lt($since)) {
                    continue;
                }

                $match[2] === 'ERROR' ? $result['errors']++ : $result['critical']++;
            }

            fclose($handle);
        }

        return $result;
    }

    /**
     * @param  list<string>  $relays
     * @param  array<string, array{accepted: int, failed: int}>  $answers
     */
    private static function attempted(array $relays, array $answers): bool
    {
        foreach ($relays as $relay) {
            if (isset($answers[$relay])) {
                return true;
            }
        }

        return false;
    }

    /**
     * A cached read that never fails the page: a cache that cannot be read or
     * written falls back to reading directly, a failing read to `$fallback`.
     *
     * @template T
     *
     * @param  Closure(): T  $read
     * @param  T  $fallback
     * @return T
     */
    private static function cached(string $key, Closure $read, mixed $fallback): mixed
    {
        try {
            return Cache::remember($key, self::CACHE_SECONDS, $read);
        } catch (Throwable $e) {
            report($e);
        }

        try {
            return $read();
        } catch (Throwable $e) {
            report($e);

            return $fallback;
        }
    }

    private static function date(?\DateTimeInterface $at): string
    {
        if ($at === null) {
            return '–';
        }

        return CarbonImmutable::instance($at)->setTimezone((string) config('esports.preseason.display_timezone', 'Europe/Berlin'))
            ->settings(['locale' => app()->getLocale()])->translatedFormat('D j M Y, H:i');
    }

    /**
     * @param  'ok'|'attention'|'down'  $state
     * @param  list<StatusItem>  $items
     * @return StatusCheck
     */
    private static function check(string $key, string $name, string $sub, string $state, string $value, string $detail, ?string $href, ?string $action, array $items = []): array
    {
        return ['key' => $key, 'name' => $name, 'sub' => $sub, 'state' => $state, 'value' => $value, 'detail' => $detail, 'href' => $href, 'action' => $action, 'items' => $items];
    }
}
