<?php

namespace Tests\Integration\Support;

use Illuminate\Database\QueryException;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use swentel\nostr\Key\Key;

/**
 * P15: the real stack (NOT Pest\Browser's own in-process LaravelHttpServer
 * that every tests/Browser test uses) — an actual `php artisan serve`, a
 * real Reverb, a real queue worker and a real local relay (`nak serve`, as
 * tests/Browser/ShareTest.php already starts one), all pointed at one fresh
 * SQLite file so app server, queue worker and this test process agree on
 * the same rows. A singleton for the whole suite run: boot() is idempotent,
 * one call per `composer test:integration` run (this group never runs
 * `--parallel`), torn down by a PHP shutdown function so a fatal test
 * failure still stops every child process.
 *
 * Reproducible ("frische DB, frische Relay-Daten je Lauf"): the SQLite file
 * is deleted and migrated fresh in boot(), and `nak serve` starts with no
 * persistence flag — an empty relay every time, matching ShareTest.php.
 * Never a public relay: `ESPORTS_RELAYS`/`ESPORTS_PROFILE_RELAYS` point only
 * at the `nak serve` this class starts.
 */
final class Stack
{
    private static ?self $instance = null;

    public readonly string $dbPath;

    public readonly int $appPort;

    public readonly int $reverbPort;

    public readonly int $relayPort;

    public readonly string $relayUrl;

    public readonly string $baseUrl;

    public readonly FakeBitcoin $bitcoin;

    public readonly FakeNwc $nwc;

    public readonly string $lnurlSecret;

    public readonly string $poolSecret;

    public readonly string $reverbAppId;

    public readonly string $reverbAppKey;

    public readonly string $reverbAppSecret;

    public readonly string $leagueSecret;

    public readonly string $trustSecret;

    /** @var list<InvokedProcess> */
    private array $processes = [];

    private bool $booted = false;

    public static function instance(): self
    {
        return self::$instance ??= new self;
    }

    private function __construct()
    {
        $this->dbPath = storage_path('framework/testing/integration.sqlite');
        $this->appPort = self::freePort();
        $this->reverbPort = self::freePort();
        $this->relayPort = self::freePort();
        $this->baseUrl = 'http://127.0.0.1:'.$this->appPort;
        $this->relayUrl = 'ws://127.0.0.1:'.$this->relayPort;
        $this->bitcoin = new FakeBitcoin(self::freePort());
        $this->nwc = new FakeNwc(self::freePort());
        $this->lnurlSecret = bin2hex(random_bytes(32));
        $this->poolSecret = bin2hex(random_bytes(32));
        $this->reverbAppId = 'integration-'.bin2hex(random_bytes(4));
        $this->reverbAppKey = 'integration-key-'.bin2hex(random_bytes(6));
        $this->reverbAppSecret = bin2hex(random_bytes(16));
        $this->leagueSecret = bin2hex(random_bytes(32));
        $this->trustSecret = bin2hex(random_bytes(32));
    }

    public static function freePort(): int
    {
        return (int) Process::run(['php', '-r', '$s = stream_socket_server("tcp://127.0.0.1:0"); echo explode(":", stream_socket_get_name($s, false))[1];'])->output();
    }

    /**
     * Fresh SQLite file + migrations, `npm run build` (the real server has
     * no Vite dev server behind it), the fake Bitcoin API, `nak serve`,
     * Reverb, the queue worker and the app server itself — in that order,
     * each waited for before the next depends on it.
     */
    public function boot(): self
    {
        if ($this->booted) {
            return $this;
        }

        $this->booted = true;

        @mkdir(dirname($this->dbPath), 0755, true);
        @unlink($this->dbPath);
        touch($this->dbPath);

        // Two separate OS processes (this one and the real app server, plus
        // the queue worker) open their OWN connections to one SQLite file.
        // journal_mode is stored IN THE FILE, not per connection (unlike
        // busy_timeout, which config/database.php leaves unset/0 and this
        // class cannot safely change without editing that shared config): set
        // once here, WAL keeps a writer from blocking a reader (or the
        // reverse) for the life of this file. Measured 2026-09-27: without
        // it, a captain's page landed back on /login often enough to flake
        // (root cause consistent with SQLITE_BUSY on a concurrent read, not
        // reproducible over plain HTTP with the exact same session/route).
        (new \PDO('sqlite:'.$this->dbPath))->exec('PRAGMA journal_mode=WAL;');

        $env = $this->env();

        Process::path(base_path())->env($env)->timeout(120)->run(['php', 'artisan', 'migrate', '--force', '--no-interaction'])->throw();

        // Assets the app server will serve as static files (public/build/*):
        // built once per boot, same reason scripts/test-browser.sh builds first.
        Process::path(base_path())->timeout(180)->run(['npm', 'run', 'build'])->throw();

        $this->bitcoin->start();

        $this->processes[] = Process::path(base_path())
            ->start(['nak', 'serve', '--hostname', '127.0.0.1', '--port', (string) $this->relayPort]);
        $this->waitForPort($this->relayPort, 'nak serve (relay)');

        $this->processes[] = Process::path(base_path())->env($env)
            ->start(['php', 'artisan', 'reverb:start', '--host=127.0.0.1', '--port='.$this->reverbPort, '--no-interaction']);
        $this->waitForPort($this->reverbPort, 'reverb:start');

        $this->processes[] = Process::path(base_path())->env($env)
            ->start(['php', 'artisan', 'queue:work', '--tries=1', '--sleep=1']);

        $this->processes[] = Process::path(base_path())->env($env)
            ->start(['php', 'artisan', 'serve', '--host=127.0.0.1', '--port='.$this->appPort]);
        $this->waitForPort($this->appPort, 'artisan serve (app server)');

        register_shutdown_function($this->stop(...));

        return $this;
    }

    /**
     * P9: the fake wallet on the relay and the players' fake Lightning
     * addresses, started by the first test that needs them (the payout
     * flow), so every other test runs against the stack it ran against
     * before P9. The app server and queue worker know the wallet's
     * connection URIs from the boot on; nothing calls them until then.
     */
    public function wallet(): FakeNwc
    {
        $this->boot();
        $this->nwc->start($this->relayUrl);

        return $this->nwc;
    }

    /**
     * Points THIS PHP process's own DB/cache connections at the same SQLite
     * file and the same cache table the real app server and queue worker
     * use, and its own `app.url`/esports config to match — so a throwaway
     * user, an opponent list or a cached signer key this test process
     * writes with plain Eloquent/Cache calls is exactly what the real
     * server reads on the next request. Call once per test (each Pest test
     * boots a fresh app container; the file underneath does not change).
     */
    public function attach(): self
    {
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => $this->dbPath,
            // config/database.php now reads this from DB_BUSY_TIMEOUT (env),
            // defaulting to null/0 (SQLite's own default: fail immediately on
            // SQLITE_BUSY) everywhere that var is unset — Stack::env() sets it
            // for the real server/queue worker, and this sets it for THIS
            // process's own connection, before DB::purge() below forces a
            // fresh one to pick it up. Both sides need it: a "database is
            // locked" from a plain TournamentPublisher::publish() call in
            // THIS process can come from either direction (this process vs.
            // the queue worker/app server as the other writer), and WAL
            // journal mode does not help here — it removes reader/writer
            // blocking, not writer/writer serialization. Measured 2026-09-27.
            'database.connections.sqlite.busy_timeout' => 15000,
            'cache.default' => 'database',
            'app.url' => $this->baseUrl,
            'esports.relays' => [$this->relayUrl],
            'esports.profile_relays' => [$this->relayUrl],
            'esports.chat.relays' => [],
            'esports.league.nsec' => $this->leagueSecret,
            'esports.trust.nsec' => $this->trustSecret,
            'esports.bitcoin.api' => $this->bitcoin->baseUrl(),
            'esports.bitcoin.confirmations' => 1,
            ...$this->walletConfig(),
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => $this->reverbAppKey,
            'broadcasting.connections.reverb.secret' => $this->reverbAppSecret,
            'broadcasting.connections.reverb.app_id' => $this->reverbAppId,
            'broadcasting.connections.reverb.options.host' => '127.0.0.1',
            'broadcasting.connections.reverb.options.port' => $this->reverbPort,
            'broadcasting.connections.reverb.options.scheme' => 'http',
            'queue.default' => 'database',
        ]);

        DB::purge('sqlite');
        Cache::purge('database');

        return $this;
    }

    /**
     * The wallet settings of the app server, for this test process too.
     *
     * @return array<string, mixed>
     */
    private function walletConfig(): array
    {
        return [
            'esports.wallet.nwc_uri' => $this->nwc->uri('pay', $this->relayUrl),
            'esports.wallet.nwc_receive_uri' => $this->nwc->uri('receive', $this->relayUrl),
            'esports.wallet.lnurl_nsec' => $this->lnurlSecret,
            'esports.wallet.pool_npub' => (new Key)->getPublicKey($this->poolSecret),
            'esports.wallet.invoice_networks' => ['bcrt'],
            'esports.wallet.lnurl_insecure_hosts' => [$this->nwc->lnurlHost()],
            // The local relay is ws:// on 127.0.0.1: allowed here only (RelayGuard, not in production).
            'esports.wallet.nwc_insecure_relays' => ['127.0.0.1:'.$this->relayPort],
        ];
    }

    /** @return array<string, string> */
    private function env(): array
    {
        return [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $this->dbPath,
            // Real multi-writer setup (app server + queue worker + this test
            // process, one SQLite file): config/database.php now wires this
            // through to the PRAGMA busy_timeout Laravel's SQLiteConnector
            // sets at connect time, so a writer waits instead of failing
            // immediately with "database is locked" (SQLite's own default is
            // 0). Kept out of config/database.php's own default so every
            // OTHER suite (:memory:, single-writer) is unaffected.
            'DB_BUSY_TIMEOUT' => '15000',
            'APP_URL' => $this->baseUrl,
            'SESSION_DRIVER' => 'database',
            'CACHE_STORE' => 'database',
            'QUEUE_CONNECTION' => 'database',
            'MAIL_MAILER' => 'array',
            'BROADCAST_CONNECTION' => 'reverb',
            'REVERB_APP_ID' => $this->reverbAppId,
            'REVERB_APP_KEY' => $this->reverbAppKey,
            'REVERB_APP_SECRET' => $this->reverbAppSecret,
            'REVERB_HOST' => '127.0.0.1',
            'REVERB_PORT' => (string) $this->reverbPort,
            'REVERB_SCHEME' => 'http',
            'ESPORTS_RELAYS' => $this->relayUrl,
            'ESPORTS_PROFILE_RELAYS' => $this->relayUrl,
            'ESPORTS_CHAT_RELAYS' => '',
            'ESPORTS_LEAGUE_NSEC' => $this->leagueSecret,
            'ESPORTS_TRUST_NSEC' => $this->trustSecret,
            'ESPORTS_BITCOIN_API' => $this->bitcoin->baseUrl(),
            'ESPORTS_BITCOIN_CONFIRMATIONS' => '1',
            'ESPORTS_NWC_URI' => $this->nwc->uri('pay', $this->relayUrl),
            'ESPORTS_NWC_RECEIVE_URI' => $this->nwc->uri('receive', $this->relayUrl),
            'ESPORTS_LNURL_NSEC' => $this->lnurlSecret,
            'ESPORTS_POOL_NPUB' => (new Key)->getPublicKey($this->poolSecret),
            'ESPORTS_INVOICE_NETWORKS' => 'bcrt',
            'ESPORTS_LNURL_INSECURE_HOSTS' => $this->nwc->lnurlHost(),
            'ESPORTS_NWC_INSECURE_RELAYS' => '127.0.0.1:'.$this->relayPort,
            'ESPORTS_RATED_CHESS' => 'true',
            'WEBPUSH_VAPID_PUBLIC_KEY' => '',
            'WEBPUSH_VAPID_PRIVATE_KEY' => '',
            'PULSE_ENABLED' => 'false',
            'TELESCOPE_ENABLED' => 'false',
            'NIGHTWATCH_ENABLED' => 'false',
        ];
    }

    private function waitForPort(int $port, string $what): void
    {
        for ($i = 0; $i < 100 && ! @fsockopen('127.0.0.1', $port); $i++) {
            usleep(100_000);
        }

        if (! @fsockopen('127.0.0.1', $port)) {
            throw new \RuntimeException("Integration stack: {$what} did not open port {$port} within 10s.");
        }
    }

    /** Run an artisan command against the real stack's own DB/env, e.g. `tournaments:advance`. */
    public function artisan(string $command): int
    {
        return Process::path(base_path())->env($this->env())->timeout(30)
            ->run(['php', 'artisan', ...explode(' ', $command), '--no-interaction'])
            ->throw()->exitCode() ?? 0;
    }

    /**
     * Start an artisan command in its own OS process against the stack and
     * return at once, so two of them can race (P9: two payment attempts).
     */
    public function artisanInBackground(string $command): InvokedProcess
    {
        return Process::path(base_path())->env($this->env())->timeout(120)
            ->start(['php', 'artisan', ...explode(' ', $command), '--no-interaction']);
    }

    /**
     * A write this test process makes directly (not through the real app
     * server/queue worker, which SQLite's own `busy_timeout` PRAGMA already
     * covers — this class sets it on both sides, see attach()/env()) can
     * still collide with one of those two other real writers: SQLite's own
     * busy_timeout retries the SAME acquisition attempt at the driver level,
     * but under real host contention (confirmed present 2026-09-27: other
     * agents' processes on this same host, load average above 2 on this
     * run's box) the 15s budget can still run out before either side's own
     * transaction clears — measured directly: `TournamentPublisher::publish()`
     * failed with "database is locked" from this process on two separate
     * `composer test:integration` runs after busy_timeout was confirmed wired
     * on both connections. Retrying the WHOLE call (not just raising the
     * PRAGMA further) is the documented mitigation for exactly this — a
     * transient, expected condition of multiple real processes sharing one
     * SQLite file, not a bug to patch away by waiting longer inside a single
     * PRAGMA wait.
     *
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    public static function retryOnLock(callable $fn, int $attempts = 6): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $fn();
            } catch (QueryException $exception) {
                if ($attempt >= $attempts || ! str_contains($exception->getMessage(), 'database is locked')) {
                    throw $exception;
                }

                usleep(300_000 * $attempt);
            }
        }
    }

    public function stop(): void
    {
        foreach ($this->processes as $process) {
            try {
                $process->stop(3);
            } catch (\Throwable) {
                // best-effort: a shutdown function must not throw
            }
        }

        $this->bitcoin->stop();
        $this->nwc->stop();

        // `php artisan serve` re-execs the actual `php -S ... server.php` as
        // a detached grandchild in some setups (measured 2026-09-26): stop()
        // on the wrapper's own pid leaves that grandchild listening. `fuser
        // -k` reaches whatever is actually bound to the port, wrapper or not.
        foreach ([$this->appPort, $this->reverbPort, $this->relayPort] as $port) {
            try {
                Process::run(['fuser', '-k', '-TERM', $port.'/tcp']);
            } catch (\Throwable) {
                // best-effort
            }
        }
    }
}
