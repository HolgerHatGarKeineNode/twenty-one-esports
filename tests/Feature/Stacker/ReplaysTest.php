<?php

/*
| Blockfill replays and the admin check (plan "Blockfill", P5).
|
| Who watches which replay (StackerReplays): the player their own, admins the
| runs with cheat hints, everybody an ended week's first ten; 403 for anyone
| else, 404 for a run without a replay to show. A verified run with cheat
| hints is held (`review`): it counts nowhere until an admin approves it on
| /admin/blockfill. The boards, /matches and the game page link the replays
| the viewer may watch. The verifier is a fake (FakeStackerVerifier); the
| verdict goes through the real job.
*/

use App\Enums\StackerRunStatus;
use App\Jobs\VerifyStackerRun;
use App\Models\Admin;
use App\Models\ScoreRun;
use App\Models\StackerRun;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Matches\ScoreAttempts;
use App\Support\Scores\ScoreRuns;
use App\Support\Stacker\BlockfillWeeks;
use App\Support\Stacker\StackerReplays;
use App\Support\Stacker\StackerRuns;
use App\Support\Stacker\StackerVerdict;
use App\Support\Stacker\Verifier;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Livewire\Livewire;
use Tests\Support\BlockfillOn;
use Tests\Support\FakeStackerVerifier;

beforeEach(function () {
    $this->withoutVite();
    $this->verifier = new FakeStackerVerifier;
    $this->app->instance(Verifier::class, $this->verifier);
    // A Wednesday: this week runs from Monday 2026-10-05 00:00 Berlin to Monday 2026-10-12 00:00 Berlin.
    $this->travelTo(CarbonImmutable::parse('2026-10-07 12:00:00'));
    BlockfillOn::play();
    $this->replay = BlockfillOn::fixture('forty-lines')['replay'];
});

/**
 * A run of `$user` handed in at `$at` with `$ticks`, decided by the verifier job (verified, or held with `$hints`).
 *
 * @param  list<string>  $hints
 */
function replayRun(User $user, int $ticks, ?CarbonInterface $at = null, array $hints = []): StackerRun
{
    $at ??= now()->subHour();
    $replay = BlockfillOn::fixture('forty-lines')['replay'];
    $run = StackerRun::factory()->for($user)->create([
        'status' => StackerRunStatus::Verifying, 'issued_at' => $at, 'started_at' => $at, 'submitted_at' => $at,
        'ticks' => $ticks, 'state_hash' => '6102773e', 'replay' => $replay,
    ]);
    test()->verifier->verdict = StackerVerdict::verified(['das' => 8, 'arr' => 1, 'sdf' => 20], $replay,
        ['flags' => $hints, 'pps' => 6.45, 'maxPressesPerTick' => 1, 'timingCv' => 0.78, 'finesse' => ['perfect' => 39, 'of' => 69]]);
    VerifyStackerRun::dispatchSync($run->id);

    return $run->refresh();
}

function adminUser(): User
{
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    return $admin;
}

test('a player watches their own replay; a guest and another player get 403 while the week runs', function () {
    [$ada, $bob] = User::factory()->count(2)->create();
    $run = replayRun($ada, 958);

    $this->actingAs($ada)->get(route('stacker.replay', $run))->assertOk()
        ->assertSee('data-test="replay-chain"', false)
        ->assertSee('Replay of 0:15.966')
        ->assertSee($ada->displayName())
        ->assertSee($run->replay)
        ->assertDontSee($ada->npub)
        ->assertDontSee('data-test="replay-hints"', false);

    $this->actingAs($bob)->get(route('stacker.replay', $run))->assertForbidden();
    auth()->logout();
    $this->get(route('stacker.replay', $run))->assertForbidden();

    Livewire::actingAs($ada)->test('pages::stacker.replay', ['run' => $run])->assertOk()->call('$refresh')->assertOk();
});

test('a run without a replay to show answers 404 to everybody, its player included', function () {
    $ada = User::factory()->create();
    $admin = adminUser();

    $dropped = replayRun($ada, 1200);
    $dropped->forceFill(['replay' => null])->save();
    $rejected = StackerRun::factory()->for($ada)->create(['status' => StackerRunStatus::Rejected, 'ticks' => 900, 'reason' => 'mismatch']);
    $practice = StackerRun::factory()->for($ada)->create(['status' => StackerRunStatus::Practice, 'ticks' => 1300]);

    foreach ([$dropped, $rejected, $practice] as $run) {
        $this->actingAs($ada)->get(route('stacker.replay', $run))->assertNotFound();
        $this->actingAs($admin)->get(route('stacker.replay', $run))->assertNotFound();
    }
    $this->actingAs($ada)->get('/blockfill/replays/999999')->assertNotFound();
});

test('a verified run with cheat hints is held: on no board, no best, and only its player and admins watch it', function () {
    [$ada, $bob] = User::factory()->count(2)->create();
    $admin = adminUser();

    $clean = replayRun($bob, 1000);
    $held = replayRun($ada, 958, hints: ['pps']);

    expect($held->status)->toBe(StackerRunStatus::Review)
        ->and($held->verified_at)->toBeNull()
        ->and($held->replay)->not->toBeNull()
        ->and($held->flags['hints']['flags'])->toBe(['pps'])
        ->and(app(StackerRuns::class)->best($ada))->toBeNull()
        ->and(ScoreRun::query()->where('user_id', $ada->id)->exists())->toBeFalse()
        ->and($clean->status)->toBe(StackerRunStatus::Verified)
        ->and($clean->flags['hints']['flags'])->toBe([]);

    $this->actingAs($ada)->get(route('stacker.replay', $held))->assertOk()->assertSee('data-test="replay-held"', false);
    $this->actingAs($admin)->get(route('stacker.replay', $held))->assertOk()
        ->assertSee('data-test="replay-hints"', false)
        ->assertSee('More than 5 pieces per second (6.45)');
    $this->actingAs($bob)->get(route('stacker.replay', $held))->assertForbidden();

    // an admin sees runs with hints, not every run
    $this->actingAs($admin)->get(route('stacker.replay', $clean))->assertForbidden();
});

test('a player keeps one held run per week, the fastest: a slower one held later becomes practice without its replay', function () {
    $ada = User::factory()->create();
    $first = replayRun($ada, 958, now()->subHours(3), ['pps']);
    $slower = replayRun($ada, 990, now()->subHours(2), ['timing']);
    $faster = replayRun($ada, 940, now()->subHour(), ['pps']);

    expect($slower->refresh())->status->toBe(StackerRunStatus::Practice)->replay->toBeNull()->reason->toBe('review-superseded')
        ->and($first->refresh())->status->toBe(StackerRunStatus::Practice)->replay->toBeNull()
        ->and($faster->refresh())->status->toBe(StackerRunStatus::Review)->replay->not->toBeNull();
});

test('the week\'s first ten replays are public once the week has ended, and only those', function () {
    $players = User::factory()->count(11)->create();
    $runs = $players->values()->map(fn (User $user, int $i): StackerRun => replayRun($user, 1000 + 10 * $i, now()->subHours(20 - $i)));
    $tenth = $runs[9];
    $eleventh = $runs[10];

    // while the week runs: nobody but the player
    $this->get(route('stacker.replay', $runs[0]))->assertForbidden();
    expect(app(StackerReplays::class)->publicIn(app(BlockfillWeeks::class)->current()))->toBe([]);

    // Monday 2026-10-12 00:00 Berlin has passed
    $this->travelTo(CarbonImmutable::parse('2026-10-12 06:00:00'));
    $week = Tournament::query()->where('slug', 'blockfill-2026-10-05')->sole();
    expect(collect(app(ScoreRuns::class)->standings($week))->pluck('place')->all())->toBe(range(1, 11));

    $this->get(route('stacker.replay', $runs[0]))->assertOk();
    $this->get(route('stacker.replay', $tenth))->assertOk();
    $this->get(route('stacker.replay', $eleventh))->assertForbidden();
    $this->actingAs($players[10])->get(route('stacker.replay', $eleventh))->assertOk();
    expect(app(StackerReplays::class)->publicIn($week))->toEqualCanonicalizing($runs->take(10)->pluck('id')->all());
});

test('admins list the held runs with their hints, approve one so it counts and reject another for good', function () {
    [$ada, $bob, $cy] = User::factory()->count(3)->create();
    $admin = adminUser();
    $approve = replayRun($ada, 958, hints: ['pps']);
    $reject = replayRun($bob, 970, hints: ['same-tick', 'finesse']);
    replayRun($cy, 1000);

    $this->actingAs($cy)->get(route('admin.blockfill'))->assertForbidden();
    $this->actingAs($admin)->get(route('admin.blockfill'))->assertOk()
        ->assertSee('More than 5 pieces per second (6.45)')
        ->assertSee('More than 3 key presses in one tick (1)')
        ->assertSee('Every piece placed with the fewest presses (39 of 69)')
        ->assertSee(route('stacker.replay', $approve), false)
        ->assertSeeInOrder([$ada->displayName(), $bob->displayName()])
        ->assertDontSee($cy->displayName());

    Livewire::actingAs($admin)->test('pages::admin.blockfill')
        ->call('approve', $approve->id)->assertSet('error', '')
        ->call('reject', $reject->id)->assertSet('error', '')
        ->call('approve', $reject->id)->assertSet('error', 'This run was decided already.')
        ->assertSee('Approved by '.$admin->displayName())
        ->assertSee('Rejected by '.$admin->displayName());

    expect($approve->refresh())->status->toBe(StackerRunStatus::Verified)->verified_at->not->toBeNull()->replay->not->toBeNull()
        ->and($approve->flags['review'])->toMatchArray(['decision' => 'approved', 'by' => $admin->id])
        ->and(app(StackerRuns::class)->best($ada))->toBe(958)
        ->and(ScoreRun::query()->where(['user_id' => $ada->id, 'external_id' => (string) $approve->id])->exists())->toBeTrue()
        ->and($reject->refresh())->status->toBe(StackerRunStatus::Rejected)->reason->toBe('review')->replay->toBeNull()
        ->and(ScoreRun::query()->where('user_id', $bob->id)->exists())->toBeFalse();

    $standings = app(ScoreRuns::class)->standings(app(BlockfillWeeks::class)->current());
    expect(collect($standings)->map(fn ($row) => [$row->participant->user_id, $row->place])->all())->toBe([[$ada->id, 1], [$cy->id, 2]]);
});

test('the admin nav counts the held runs', function () {
    $admin = adminUser();
    replayRun(User::factory()->create(), 958, hints: ['pps']);

    $this->actingAs($admin)->get(route('admin.status'))->assertOk()
        ->assertSee('data-test="admin-nav-blockfill-menu"', false)
        ->assertSee(route('admin.blockfill'), false);
});

test('boards, /matches and the game page link the replays the viewer may watch', function () {
    [$ada, $bob] = User::factory()->count(2)->create();
    $mine = replayRun($ada, 958);
    $theirs = replayRun($bob, 1000);
    $week = app(BlockfillWeeks::class)->current();

    // the week's board: only the own row while the week runs
    $this->actingAs($ada)->get(route('tournaments.scores', $week))->assertOk()
        ->assertSee(route('stacker.replay', $mine), false)
        ->assertDontSee(route('stacker.replay', $theirs), false);
    $this->actingAs($ada)->get(route('stacker.play'))->assertOk()
        ->assertSee('data-test="stacker-week-replay"', false)
        ->assertSee(route('stacker.replay', $mine), false);

    // /matches: the own attempt opens its replay, the other one its week's board
    $this->actingAs($ada);
    $links = ScoreAttempts::links([$mine, $theirs]);
    expect($links['run-'.$mine->id])->toBe(route('stacker.replay', $mine))
        ->and($links['run-'.$theirs->id])->toBe(route('tournaments.scores', $week));
    $this->get(route('matches.index', ['status' => 'finished']))->assertOk()
        ->assertSee(route('stacker.replay', $mine), false)
        ->assertDontSee(route('stacker.replay', $theirs), false);

    // the week over: both are public on the board, for guests too
    $this->travelTo(CarbonImmutable::parse('2026-10-12 06:00:00'));
    auth()->logout();
    $this->get(route('tournaments.scores', $week))->assertOk()
        ->assertSee(route('stacker.replay', $mine), false)
        ->assertSee(route('stacker.replay', $theirs), false);
});

test('the run status a player polls names the replay page once the league keeps it', function () {
    $ada = User::factory()->create();
    [$run, $token] = app(StackerRuns::class)->issue($ada, now());
    $run->forceFill(['status' => StackerRunStatus::Verified, 'ticks' => 958, 'replay' => $this->replay, 'submitted_at' => now(), 'week' => StackerRuns::weekOf(now())])->save();

    $this->actingAs($ada)->getJson(route('stacker.runs.show', $token))->assertOk()
        ->assertJsonPath('replay', route('stacker.replay', $run));

    $run->forceFill(['replay' => null])->save();
    $this->actingAs($ada)->getJson(route('stacker.runs.show', $token))->assertOk()->assertJsonPath('replay', null);
});
