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
use App\Support\Cards\Canvas;
use App\Support\Cards\PageCard;
use App\Support\Matches\ScoreAttempts;
use App\Support\Scores\ScoreRuns;
use App\Support\Stacker\BlockfillWeeks;
use App\Support\Stacker\StackerReplays;
use App\Support\Stacker\StackerRuns;
use App\Support\Stacker\StackerVerdict;
use App\Support\Stacker\Verifier;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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

/*
| The replays page (`stacker.replays`, the Replays tab): your own replays, an
| ended week's first ten, and the held runs for admins, each only when
| StackerReplays::canView() would let the viewer watch it.
*/

/**
 * Week 40 (2026-09-28 to 2026-10-04) ended with eleven players on its board, Ada second; in week 41, running now
 * (Wednesday 2026-10-07), Ada and Bob have one run each.
 *
 * @return array{players: Collection<int, User>, lastWeek: Collection<int, StackerRun>, ada: User, bob: User, adaNow: StackerRun, bobNow: StackerRun}
 */
function replayShelves(): array
{
    test()->travelTo(CarbonImmutable::parse('2026-09-30 12:00:00'));
    $players = User::factory()->count(11)->sequence(fn ($sequence) => ['name' => 'Week Forty Player '.($sequence->index + 1)])->create();
    $lastWeek = $players->values()->map(fn (User $user, int $i): StackerRun => replayRun($user, 1000 + 10 * $i, now()->subHours(20 - $i)));

    test()->travelTo(CarbonImmutable::parse('2026-10-07 12:00:00'));
    $ada = $players[1];
    $bob = User::factory()->create(['name' => 'Bob This Week']);

    return [
        'players' => $players, 'lastWeek' => $lastWeek, 'ada' => $ada, 'bob' => $bob,
        'adaNow' => replayRun($ada, 990), 'bobNow' => replayRun($bob, 995),
    ];
}

test('the replays page lists your own replays newest first with time, week and place, and the ended week\'s first ten, the first one large', function () {
    ['lastWeek' => $lastWeek, 'ada' => $ada, 'bobNow' => $bobNow, 'adaNow' => $adaNow] = replayShelves();

    $html = $this->actingAs($ada)->get(route('stacker.replays'))->assertOk()
        ->assertSee('data-test="replays-mine"', false)
        // Ada's own, newest first: this week's 0:16.500 (#1 of this week so far), then week 40's 0:16.833 (#2)
        ->assertSeeInOrder([route('stacker.replay', $adaNow), 'Week 41, 2026', route('stacker.replay', $lastWeek[1]), 'Week 40, 2026'], false)
        ->assertSee('Top replays of Week 40, 2026')
        ->getContent();

    preg_match('#data-test="replays-mine".*?</section>#s', $html, $mine);
    preg_match('#data-test="replays-top".*?</section>#s', $html, $top);
    preg_match('#<a href="([^"]+)"[^>]*?data-test="replays-featured".*?</a>#s', $html, $featured);
    preg_match_all('#href="([^"]+)"\s+data-test="replays-mine-row"\s*(?:data-place="(\d+)")?#', $mine[0], $mineRows);

    expect($mineRows[1])->toBe([route('stacker.replay', $adaNow), route('stacker.replay', $lastWeek[1])])
        ->and($mineRows[2])->toBe(['1', '2'])
        // the week's winner large, then places 2 to 10, never the eleventh, never a run of the running week
        ->and($featured[1])->toBe(route('stacker.replay', $lastWeek[0]))
        ->and($featured[0])->toContain('Week Forty Player 1')->toContain('0:16.666')
        ->and(substr_count($top[0], 'data-test="replays-top-row"'))->toBe(9)
        ->and($top[0])->toContain('"'.route('stacker.replay', $lastWeek[9]).'"')
        ->and($top[0])->not->toContain('"'.route('stacker.replay', $lastWeek[10]).'"')
        ->and($html)->not->toContain('"'.route('stacker.replay', $bobNow).'"')
        ->and($html)->not->toContain('data-test="replays-held"');
});

test('a guest sees the ended week\'s first ten and a way in, never a replay of the running week or the eleventh place', function () {
    ['lastWeek' => $lastWeek, 'adaNow' => $adaNow, 'bobNow' => $bobNow] = replayShelves();

    $html = $this->get(route('stacker.replays'))->assertOk()
        ->assertSee('data-test="replays-mine-guest"', false)
        ->assertSee(route('login'), false)
        ->getContent();
    preg_match_all('#/blockfill/replays/(\d+)#', $html, $linked);

    expect(array_map('intval', array_values(array_unique($linked[1]))))->toBe($lastWeek->take(10)->pluck('id')->all())
        ->and($html)->not->toContain('"'.route('stacker.replay', $adaNow).'"')
        ->and($html)->not->toContain('"'.route('stacker.replay', $bobNow).'"');
});

test('without an ended week the replays page invites to play, for players and guests', function () {
    $ada = User::factory()->create();

    foreach ([null, $ada] as $viewer) {
        $viewer ? $this->actingAs($viewer) : auth()->logout();
        $this->get(route('stacker.replays'))->assertOk()
            ->assertSee('data-test="replays-top-empty"', false)
            ->assertSee('No week has ended yet')
            ->assertSee('data-test="replays-play"', false)
            ->assertSee(route('stacker.play'), false);
    }

    $this->actingAs($ada)->get(route('stacker.replays'))->assertSee('data-test="replays-mine-empty"', false);
});

test('the week chips show an older ended week\'s first ten', function () {
    ['lastWeek' => $lastWeek] = replayShelves();
    // Two weeks on, week 40 is the older of two ended weeks; week 41's only place is Ada's.
    $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00:00'));
    $week40 = Tournament::query()->where('slug', 'blockfill-2026-09-28')->sole();

    $this->get(route('stacker.replays'))->assertOk()
        ->assertSee('Top replays of Week 41, 2026')
        ->assertSee(route('stacker.replays', ['week' => $week40->slug]), false)
        ->assertDontSee('href="'.route('stacker.replay', $lastWeek[0]).'"', false);

    $this->get(route('stacker.replays', ['week' => $week40->slug]))->assertOk()
        ->assertSee('Top replays of Week 40, 2026')
        ->assertSee('href="'.route('stacker.replay', $lastWeek[0]).'"', false);
});

test('admins see the held runs with their number and the way to the review list; players never do', function () {
    $admin = adminUser();
    $cy = User::factory()->create(['name' => 'Cy Held']);
    $held = replayRun($cy, 958, hints: ['pps', 'timing']);

    $this->actingAs($admin)->get(route('stacker.replays'))->assertOk()
        ->assertSee('data-test="replays-held"', false)
        ->assertSee('data-test="replays-held-count">1<', false)
        ->assertSee('href="'.route('stacker.replay', $held).'"', false)
        ->assertSee('2 hints')
        ->assertSee(route('admin.blockfill'), false);

    // Cy sees the held run as their own, never the admin shelf
    $this->actingAs($cy)->get(route('stacker.replays'))->assertOk()
        ->assertDontSee('data-test="replays-held"', false)
        ->assertSee('href="'.route('stacker.replay', $held).'"', false)
        ->assertSee('data-test="replay-row-held"', false);
    $this->actingAs(User::factory()->create())->get(route('stacker.replays'))->assertOk()
        ->assertDontSee('data-test="replays-held"', false)
        ->assertDontSee('href="'.route('stacker.replay', $held).'"', false);
});

test('?player= lists that player\'s replays the viewer may watch: the public ones for others, all for the player', function () {
    ['lastWeek' => $lastWeek, 'ada' => $ada, 'adaNow' => $adaNow] = replayShelves();
    $url = route('stacker.replays', ['player' => $ada->npub]);

    $this->get($url)->assertOk()
        ->assertSee('Replays of '.$ada->displayName())
        ->assertSee('href="'.route('stacker.replay', $lastWeek[1]).'"', false)
        ->assertDontSee('href="'.route('stacker.replay', $adaNow).'"', false)
        ->assertDontSee('href="'.route('stacker.replay', $lastWeek[0]).'"', false);

    $this->actingAs($ada)->get($url)->assertOk()
        ->assertSee('href="'.route('stacker.replay', $lastWeek[1]).'"', false)
        ->assertSee('href="'.route('stacker.replay', $adaNow).'"', false);

    // a player without a public replay: the empty state with Play
    $this->get(route('stacker.replays', ['player' => $lastWeek[10]->user->npub]))->assertOk()
        ->assertSee('data-test="replays-player-empty"', false)
        ->assertDontSee('href="'.route('stacker.replay', $lastWeek[10]).'"', false);
});

test('the replays page reads the same number of queries for 2 and 8 own replays in two weeks', function () {
    ['ada' => $ada] = replayShelves();
    $count = function () use ($ada): int {
        // Cold caches both times: a verified run drops the shell's and the card's cached counts.
        Cache::flush();
        DB::flushQueryLog();
        DB::enableQueryLog();
        // A fresh model each time: relations loaded by an earlier request would hide their query.
        $this->actingAs($ada->fresh())->get(route('stacker.replays'))->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };
    $two = $count();

    foreach (range(1, 6) as $i) {
        replayRun($ada, 1100 + $i, now()->subMinutes(10 * $i));
    }

    expect(app(StackerReplays::class)->ofPlayer($ada, $ada))->toHaveCount(8)
        ->and($count())->toBe($two);
});

test('the replay viewer and the replays page mark Replays in the context bar and the tab bar', function () {
    ['ada' => $ada, 'adaNow' => $adaNow] = replayShelves();

    foreach ([route('stacker.replays'), route('stacker.replays', ['week' => 'blockfill-2026-09-28']), route('stacker.replay', $adaNow)] as $url) {
        $html = $this->actingAs($ada)->get($url)->assertOk()->getContent();

        expect($html)->toContain('data-test="context-bar" data-game="blockfill"')
            ->and($html)->toMatch('#href="'.preg_quote(route('stacker.replays'), '#').'"\s+aria-current="page"\s+class="ctx-link"\s+data-test="ctx-replays"#')
            ->and($html)->toMatch('#href="'.preg_quote(route('stacker.replays'), '#').'"\s+class="tab"\s+aria-current="page"\s+data-test="tab-replays"#')
            ->and(preg_match_all('#aria-current="page"\s+class="ctx-link"#', $html))->toBe(1);
    }
});

test('the board hero, every row the viewer may watch and the player page lead to the replays', function () {
    ['lastWeek' => $lastWeek, 'ada' => $ada] = replayShelves();
    $week40 = Tournament::query()->where('slug', 'blockfill-2026-09-28')->sole();

    // the hero of scores/blockfill and of an ended week's board
    $this->get(route('scores.show', 'blockfill'))->assertOk()
        ->assertSee('data-test="hero-replays"', false)
        ->assertSee('href="'.route('stacker.replays').'"', false);
    $board = $this->get(route('tournaments.scores', $week40))->assertOk()
        ->assertSee('href="'.e(route('stacker.replays', ['week' => $week40->slug])).'"', false)
        ->getContent();

    // a play square per public row, named for its player, never the word under the time
    preg_match_all('#<a href="([^"]+)" aria-label="Watch the replay of ([^"]+)" title="Watch replay"\s+class="inline-flex size-11#', $board, $squares);
    expect($squares[1])->toBe($lastWeek->take(10)->map(fn (StackerRun $run): string => route('stacker.replay', $run))->all())
        ->and($squares[2][0])->toBe('Week Forty Player 1');

    // the player page's Blockfill card: that player's replays
    $this->get(route('players.show', $ada->npub))->assertOk()
        ->assertSee('data-test="player-score-replays"', false)
        ->assertSee('href="'.e(route('stacker.replays', ['player' => $ada->npub])).'"', false);
});

test('the replays page has a card of its own: last ended week\'s first three, in both languages', function () {
    replayShelves();
    Storage::fake('local');
    $this->app['env'] = 'production';

    $html = $this->get(route('stacker.replays'))->assertOk()->getContent();
    preg_match('~<meta property="og:image" content="([^"]+)"~', $html, $image);
    $card = PageCard::resolve('page', 'blockfill-replays');

    expect($image[1] ?? '')->toContain('blockfill-replays')
        ->and($card)->not->toBeNull()
        ->and($card->facts['week'])->toBe([40, 2026])
        ->and(array_column($card->facts['board']['top'], 'name'))->toBe(['Week Forty Player 1', 'Week Forty Player 2', 'Week Forty Player 3']);

    // Drawn in both languages with the week and the podium, nothing cut.
    foreach (['en', 'de'] as $locale) {
        app()->setLocale($locale);
        Canvas::$cuts = [];
        expect(substr(PageCard::page('blockfill-replays')->png(), 1, 3))->toBe('PNG')
            ->and(Canvas::$cuts)->toBe([]);
    }
    Canvas::$cuts = null;
});
