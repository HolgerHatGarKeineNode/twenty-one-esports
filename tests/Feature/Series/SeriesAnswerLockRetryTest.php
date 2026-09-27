<?php

use App\Enums\SeriesStatus;
use App\Models\Clan;
use App\Models\Lineup;
use App\Models\User;
use App\Support\Series\ChallengeDraft;
use App\Support\Series\SeriesService;
use Illuminate\Support\Facades\Queue;
use Tests\Support\SqliteLockProbe;
use Tests\Support\TestSigner;

/*
|--------------------------------------------------------------------------
| P15 class regression: SeriesService::persist() under a transient SQLite lock
|--------------------------------------------------------------------------
|
| Same class of bug as TournamentSignupLockRetryTest.php and
| TournamentDrawLockRetryTest.php, a THIRD independent instance: the shared
| private SeriesService::persist() helper (App\Support\Series\SeriesService,
| used by challenge()/answer()/report()/respond() alike — every kind
| 2150-2154 write in the app) stores each signed event and then applies the
| caller's own SeriesMatch update, all inside one DB::transaction() — read
| (a query the closure runs, e.g. resolving `answered_by_id`/roster state)
| before write, the same shape the other two had.
|
| SeriesAnswerLockRetryTest specifically exercises answer() (kind 2151, the
| symptom tests/Integration/TeamMatchReadyTest.php's flake showed under
| extreme host load: "expected at least 2 events of kind 2151... only 1").
|
| This does not retry per call site — it proves the CLASS fix
| (config/database.php's `transaction_mode`/`journal_mode`, see
| TournamentSignupLockRetryTest.php's own docblock) already covers this
| third, independent call site, going through a completely different
| service class than the other two.
|
*/

test('answering a challenge survives a transient SQLite lock from a concurrent writer instead of failing', function () {
    $match = null;
    $captainB = null;
    $signerB = null;
    $start = null;

    $status = SqliteLockProbe::run(
        setup: function () use (&$match, &$captainB, &$signerB, &$start) {
            Queue::fake();
            $service = app(SeriesService::class);

            $signerA = new TestSigner;
            $captainA = User::factory()->withPubkey($signerA->pubkey)->create();
            $lineupA = Lineup::factory()->game('rocket-league', '3v3')->ready()->create(['clan_id' => Clan::factory()->create(['owner_id' => $captainA->id])->id]);

            $signerB = new TestSigner;
            $captainB = User::factory()->withPubkey($signerB->pubkey)->create();
            $lineupB = Lineup::factory()->game('rocket-league', '3v3')->ready()->create(['clan_id' => Clan::factory()->create(['owner_id' => $captainB->id])->id]);

            $start = now()->addHour()->startOfMinute()->getTimestamp();
            $draft = new ChallengeDraft($lineupA->id, $lineupB->id, 3, false, [$start, $start + 3600], $start - 600, 'gl hf');

            $match = $service->challenge($captainA, $draft, $signerA->signTemplates($service->prepareChallenge($captainA, $draft)['templates']));
        },
        action: function () use (&$match, &$captainB, &$signerB, &$start) {
            $service = app(SeriesService::class);
            $service->answer($match, $captainB, 'accepted', $start, $signerB->signTemplates($service->prepareAnswer($match, $captainB, 'accepted', $start)));

            return $match->refresh()->status;
        },
    );

    expect($status)->toBe(SeriesStatus::Accepted);
});
