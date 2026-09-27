<?php

use App\Enums\TournamentStatus;
use App\Support\Tournaments\TournamentDraws;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\SqliteLockProbe;
use Tests\Support\TestSigner;

/*
|--------------------------------------------------------------------------
| P15 class regression: TournamentDraws under a transient SQLite lock
|--------------------------------------------------------------------------
|
| Same class of bug as TournamentSignupLockRetryTest.php, a different
| instance of it: tests/Integration full-suite runs (2026-09-27, under real
| host load) hit "database is locked" from
| App\Support\Tournaments\TournamentDraws::createParticipants() (called via
| resolve() -> advanceDue(), the scheduler's own path in production) —
| storage/logs/laravel.log named the exact line. resolve() also reads
| (Tournament::query()->lockForUpdate()->findOrFail(), the signup/solo-pool
| queries) before it writes the participants and the bracket, the same
| shape TournamentSignups::store() had.
|
| This does not retry per call site — it proves the CLASS fix
| (config/database.php's `transaction_mode`/`journal_mode`, see
| TournamentSignupLockRetryTest.php's own docblock) already covers this
| second, independent call site.
|
*/

test('a tournament draw resolves past a transient SQLite lock from a concurrent writer instead of failing', function () {
    $hash = hash('sha256', 'block 900101');
    $tip = 900100;
    Http::fake(function ($request) use (&$tip, $hash) {
        return match (true) {
            str_ends_with($request->url(), '/blocks/tip/height') => Http::response((string) $tip),
            str_ends_with($request->url(), '/block-height/900101') => Http::response($hash),
            str_ends_with($request->url(), '/block/'.$hash) => Http::response(['timestamp' => now()->addMinutes(10)->getTimestamp()]),
            default => Http::response('', 404),
        };
    });

    $tournament = null;
    $draws = null;

    // The Tournament instance any query builds while SqliteLockProbe's
    // connection is the default stays PINNED to it (Eloquent's
    // newFromBuilder() sets the connection name at hydration time) — a
    // ->refresh() on it after the probe has torn that connection down
    // would hit a deleted file, not the real 'sqlite' database. So the
    // status/seed this test cares about is read and returned from INSIDE
    // the probe, not by touching $tournament again afterward.
    [$resolved, $status, $seed] = SqliteLockProbe::run(
        setup: function () use (&$tournament, &$draws, &$tip) {
            Queue::fake();
            config(['esports.league.nsec' => (new TestSigner)->secret]);

            $tournament = openTournament(['capacity' => 5], rocketLeague: true);
            [$lineupA, $captainA, $signerA] = keyedLineup();
            [$lineupB, $captainB, $signerB] = keyedLineup();
            lineupSignup($tournament, $lineupA, $captainA, $signerA);
            lineupSignup($tournament, $lineupB, $captainB, $signerB);

            foreach (range(1, 7) as $i) {
                [$player, $signer] = keyedPlayer();
                soloSignup($tournament, $player, $signer);
            }

            $this->travel(25)->hours();
            $draws = app(TournamentDraws::class);

            expect($draws->close($tournament->refresh()))->toBeTrue();
            expect($tournament->refresh()->status)->toBe(TournamentStatus::Drawing);

            $tip = 900106;
        },
        action: function () use (&$tournament, &$draws) {
            $resolved = $draws->resolve($tournament);
            $tournament->refresh();

            return [$resolved, $tournament->status, $tournament->seed];
        },
    );

    expect($resolved)->toBeTrue()
        ->and($status)->toBe(TournamentStatus::Running)
        ->and($seed)->toBe($hash);
});
