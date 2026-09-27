<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TestSigner;

/*
|--------------------------------------------------------------------------
| P15 regression: a transient SQLite lock during sign-up
|--------------------------------------------------------------------------
|
| tests/Integration/TournamentFlowTest.php (the real stack: a real app
| server, a real queue worker, one shared SQLite file) hit this at the
| captain's "enter lineup" click, 2026-09-27: the queue worker held the
| file's write lock while TournamentSignups::store() tried to insert the
| entry — Livewire's update request answered 500, and `[data-test=my-entry]`
| never appeared. tests/Support/BrowserWait.php now surfaces a captured
| console/network error on a timeout (Tests\Support\BrowserConsole::COLLECTOR,
| wired into tests/Integration/Support/helpers.php's integrationPage()) —
| that is how this was found: "500 .../livewire-.../update" pointed at the
| app's storage/logs/laravel.log entry naming this exact line, a PDOException
| "database is locked" from App\Models\NostrEvent::fromSigned() inside
| store()'s transaction.
|
| store()'s DB::transaction() had no retry of its own. A bare
| DB::transaction($callback, $attempts) does NOT fix this, even though
| Laravel's own concurrency detector recognizes "database is locked"
| (Illuminate\Database\ConcurrencyErrorDetector): that retry loop re-runs
| the closure immediately, with no wait between attempts, and store() reads
| (lockForUpdate()'s findOrFail(), plan()'s own lookups) BEFORE it writes.
| SQLite's busy-handler (the PDO busy_timeout PRAGMA, set for exactly this
| kind of contention by Tests\Integration\Support\Stack::env()) is
| documented to not be invoked once a transaction already holds a read
| snapshot and then needs to upgrade to a write lock — confirmed here by
| isolated repro (2026-09-27): a transaction that SELECTs before it INSERTs
| against a file another connection holds under `BEGIN IMMEDIATE` fails in
| 0ms on every attempt regardless of the busy_timeout budget, while the
| same INSERT alone (no prior SELECT) waits out the full budget as
| expected. store() now retries itself with an explicit usleep() backoff
| between attempts — the same shape as Stack::retryOnLock() (test side);
| this is its app-side counterpart for a real HTTP request, and the usleep()
| is what actually gives the other writer time to finish.
|
| Reproduced here without the real stack (no app server, no Reverb, no
| queue worker, no `npm run build`): a real, file-backed SQLite database
| (this suite's own default in phpunit.xml is `:memory:`, and a `:memory:`
| database is private to its own connection — it cannot reproduce a genuine
| cross-connection SQLITE_BUSY at all) with a second, real OS process
| holding the file's write lock (`BEGIN IMMEDIATE`) throughout store()'s
| read-then-write, so the insert must survive at least one retry — the
| same shape of contention as the real stack, without its cost.
|
*/

test('a solo sign-up survives a transient SQLite lock from a concurrent writer instead of failing the request', function () {
    $dbPath = storage_path('framework/testing/lock-retry-probe-'.uniqid('', true).'.sqlite');
    $marker = storage_path('framework/testing/lock-retry-probe-'.uniqid('', true).'.marker');
    @unlink($dbPath);
    @unlink($marker);
    touch($dbPath);

    // This suite's parallel workers run many tests per PHP process
    // (Pest --parallel): a `database.default` left pointed at this probe
    // connection after this test returns corrupts every sibling test that
    // runs afterward in the SAME worker (confirmed: without the finally
    // below, `php artisan test --parallel` failed ~80 unrelated tests
    // downstream in the same workers with "table \"migrations\" already
    // exists" / "cannot VACUUM from within a transaction" — RefreshDatabase
    // preparing the NEXT test's schema against a connection this test
    // redirected and never gave back). The restore must run even if an
    // assertion below fails, so it is a finally, not a trailing line.
    $originalDefault = config('database.default');

    try {
        config([
            'database.default' => 'sqlite_lock_probe',
            'database.connections.sqlite_lock_probe' => [...config('database.connections.sqlite'), 'database' => $dbPath, 'busy_timeout' => 200],
        ]);
        DB::purge('sqlite_lock_probe');
        (new PDO('sqlite:'.$dbPath))->exec('PRAGMA journal_mode=WAL;');
        Artisan::call('migrate', ['--force' => true]);

        Queue::fake();
        config(['esports.league.nsec' => (new TestSigner)->secret]);

        $tournament = openTournament();
        [$player, $signer] = keyedPlayer();

        // A second, REAL OS process (not a mock, not another thread of this
        // same PHP process — SQLite's file lock is only meaningfully
        // contested across real connections) holds the write lock for
        // 700ms: comfortably longer than store()'s first couple of retry
        // attempts (150ms + 300ms of backoff = 450ms), so at least one
        // retry beyond the first is required for the sign-up to succeed at
        // all.
        $holder = Process::path(base_path())->start(['php', '-r', <<<'PHP'
            $pdo = new PDO('sqlite:'.$argv[1]);
            $pdo->exec('BEGIN IMMEDIATE');
            $pdo->exec("INSERT INTO cache (key, value, expiration) VALUES ('lock-probe', 'x', 9999999999)");
            file_put_contents($argv[2], '1');
            usleep(700_000);
            $pdo->exec('COMMIT');
            PHP, $dbPath, $marker]);

        // Wait for the WRITE LOCK itself (the marker is written right after
        // BEGIN IMMEDIATE succeeds), not a fixed sleep: a fixed delay would
        // either race the lock's acquisition on a slow/loaded host (proving
        // nothing) or pad the test with idle time on a fast one.
        $deadline = microtime(true) + 5;

        while (! file_exists($marker) && microtime(true) < $deadline) {
            usleep(5_000);
        }

        expect(file_exists($marker))->toBeTrue('the concurrent writer never signalled that it holds the lock');

        $signup = soloSignup($tournament, $player, $signer);

        expect($signup)->not->toBeNull()
            ->and($signup->members)->toBe([$player->id]);

        $holder->wait();
    } finally {
        config(['database.default' => $originalDefault]);
        DB::purge('sqlite_lock_probe');
        @unlink($dbPath);
        @unlink($dbPath.'-wal');
        @unlink($dbPath.'-shm');
        @unlink($marker);
    }
});
