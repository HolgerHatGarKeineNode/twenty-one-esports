<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

/**
 * A real, file-backed SQLite database (unlike this suite's own default
 * `:memory:`, which gives every connection its own isolated database and
 * cannot reproduce a genuine cross-connection SQLITE_BUSY at all) with a
 * second, real OS process holding the file's write lock under
 * `BEGIN IMMEDIATE` throughout `$body`'s call — the shape of contention
 * tests/Integration's real stack hits under host load (a queue worker,
 * the scheduler, or another web request holding the SQLite file's write
 * lock while a transaction elsewhere reads, then writes).
 *
 * Reused across P15-class regression tests (see
 * tests/Feature/Tournaments/TournamentSignupLockRetryTest.php,
 * TournamentDrawLockRetryTest.php, SeriesAnswerLockRetryTest.php) so the
 * fix these prove — config/database.php's `transaction_mode` +
 * `journal_mode` + `busy_timeout`, not a per-call-site retry — is verified
 * against the CLASS of call site, not one instance of it.
 */
final class SqliteLockProbe
{
    /**
     * @template T
     *
     * @param  callable(): mixed  $setup  Runs FIRST, before the concurrent writer starts — fixtures
     *                                    (factories, other writes) that should not themselves race the
     *                                    holder, so only `$action` is measured against the contention.
     * @param  callable(): T  $action  Runs while the concurrent writer holds the lock — the call under test.
     * @return T
     */
    public static function run(callable $setup, callable $action, int $busyTimeoutMs = 1000, int $holdMs = 700): mixed
    {
        $dbPath = storage_path('framework/testing/lock-probe-'.uniqid('', true).'.sqlite');
        $marker = storage_path('framework/testing/lock-probe-'.uniqid('', true).'.marker');
        @unlink($dbPath);
        @unlink($marker);
        touch($dbPath);

        // This suite's parallel workers run many tests per PHP process
        // (Pest --parallel): a `database.default` left pointed at this probe
        // connection after the caller returns corrupts every sibling test
        // that runs afterward in the SAME worker (confirmed 2026-09-27:
        // without restoring this in a finally, `php artisan test --parallel`
        // failed ~80 unrelated tests downstream in the same workers with
        // "table \"migrations\" already exists" / "cannot VACUUM from within
        // a transaction" — RefreshDatabase preparing the NEXT test's schema
        // against a connection this left redirected).
        $originalDefault = config('database.default');
        $holder = null;

        try {
            config([
                'database.default' => 'sqlite_lock_probe',
                'database.connections.sqlite_lock_probe' => [...config('database.connections.sqlite'), 'database' => $dbPath, 'busy_timeout' => $busyTimeoutMs],
            ]);
            DB::purge('sqlite_lock_probe');
            (new \PDO('sqlite:'.$dbPath))->exec('PRAGMA journal_mode=WAL;');
            Artisan::call('migrate', ['--force' => true]);

            $setup();

            $holdUs = $holdMs * 1000;
            $holder = Process::path(base_path())->start(['php', '-r', <<<'PHP'
                $pdo = new PDO('sqlite:'.$argv[1]);
                $pdo->exec('BEGIN IMMEDIATE');
                $pdo->exec("INSERT INTO cache (key, value, expiration) VALUES ('lock-probe', 'x', 9999999999)");
                file_put_contents($argv[2], '1');
                usleep((int) $argv[3]);
                $pdo->exec('COMMIT');
                PHP, $dbPath, $marker, (string) $holdUs]);

            // Wait for the WRITE LOCK itself (the marker is written right
            // after BEGIN IMMEDIATE succeeds), not a fixed sleep: a fixed
            // delay would either race the lock's acquisition on a slow/
            // loaded host (proving nothing) or pad the test with idle time
            // on a fast one.
            $deadline = microtime(true) + 5;

            while (! file_exists($marker) && microtime(true) < $deadline) {
                usleep(5_000);
            }

            if (! file_exists($marker)) {
                throw new \RuntimeException('SqliteLockProbe: the concurrent writer never signalled that it holds the lock.');
            }

            return $action();
        } finally {
            $holder?->wait();
            config(['database.default' => $originalDefault]);
            DB::purge('sqlite_lock_probe');
            @unlink($dbPath);
            @unlink($dbPath.'-wal');
            @unlink($dbPath.'-shm');
            @unlink($marker);
        }
    }
}
