<?php

use Illuminate\Support\Facades\Queue;
use Tests\Support\SqliteLockProbe;
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
| wired into tests/Integration's own integrationPage()) — that is how this
| was found: "500 .../livewire-.../update" pointed at a PDOException
| "database is locked" from App\Models\NostrEvent::fromSigned() inside
| store()'s transaction.
|
| First fix (superseded): a per-call retry loop inside store() with an
| explicit usleep() backoff. It worked, but only for that one call site —
| every other DB::transaction() in the app that reads before it writes
| (grep found ~40+) had the identical exposure, since production runs the
| same single SQLite file behind web + Horizon + the scheduler +
| twentyone:stream, all real, separate processes.
|
| Class-level fix (current): config/database.php's sqlite connection now
| sets `journal_mode` (was `null` — nothing ever read this key, so nothing
| could opt into WAL without editing code) and `transaction_mode` (was
| `'DEFERRED'`, SQLite's own default) to `'wal'`/`'IMMEDIATE'`.
| `BEGIN IMMEDIATE` takes the write lock at BEGIN time instead of only at
| the transaction's first write — SQLite's busy-handler (the PDO
| busy_timeout PRAGMA) is documented to NOT be invoked once a transaction
| has already read and then tries to upgrade to a write lock (the read
| snapshot would go stale), so under the OLD default (DEFERRED) the
| busy_timeout budget never got a chance to apply to a read-then-write
| transaction — confirmed by isolated repro (2026-09-27, no Laravel): a
| bare SELECT-then-INSERT transaction against a file another connection
| holds under `BEGIN IMMEDIATE` fails in 0ms on every attempt, budget or no
| budget, while the same INSERT alone (no prior SELECT) waits out the full
| PRAGMA as expected. Needs PHP >= 8.4:
| Illuminate\Database\SQLiteConnection::executeBeginTransactionStatement()
| only issues the mode-carrying BEGIN statement on 8.4+, and silently falls
| back to plain (DEFERRED) PDO::beginTransaction() below that — this app's
| composer.json requires only "^8.3", so PRODUCTION'S ACTUAL PHP VERSION
| MUST be confirmed >= 8.4 for this to protect anything there (not
| verified from this worktree — no Forge session here).
|
| Calibration of THIS test against the class fix (2026-09-27): with
| busy_timeout raised above the concurrent writer's hold time, this passes
| without any per-call retry (5/5); with busy_timeout lowered below it, this
| fails — at `SQLiteConnection.php`'s own `BEGIN {mode} TRANSACTION`
| statement, not deep inside an insert after several reads, proving
| `transaction_mode=IMMEDIATE` really does take the lock up front now.
|
| store() itself no longer retries (removed once this test stayed green
| without it) — the SAME Tests\Support\SqliteLockProbe protects
| TournamentDrawLockRetryTest.php and SeriesAnswerLockRetryTest.php, proving
| the CLASS of call site, not just this one instance.
|
*/

test('a solo sign-up survives a transient SQLite lock from a concurrent writer instead of failing the request', function () {
    $tournament = null;
    $player = null;
    $signer = null;

    $signup = SqliteLockProbe::run(
        setup: function () use (&$tournament, &$player, &$signer) {
            Queue::fake();
            config(['esports.league.nsec' => (new TestSigner)->secret]);

            $tournament = openTournament();
            [$player, $signer] = keyedPlayer();
        },
        action: function () use (&$tournament, &$player, &$signer) {
            return soloSignup($tournament, $player, $signer);
        },
    );

    expect($signup)->not->toBeNull()
        ->and($signup->members)->toBe([$signup->user_id]);
});
