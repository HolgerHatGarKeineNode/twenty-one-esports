<?php

use App\Enums\NotificationKind;
use App\Enums\TournamentStatus;
use App\Events\TournamentChanged;
use App\Models\Admin;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentParticipant;
use App\Models\TournamentSignup;
use App\Models\User;
use App\Support\Tournaments\TournamentScheduler;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TestSigner;

/*
|--------------------------------------------------------------------------
| The check before a tournament's close (P7 "Turnier-Betrieb absichern")
|--------------------------------------------------------------------------
|
| On prod (2026-10-03) tournament 1 sat in "Draw pending" for over an hour:
| an entry of a deleted account broke the draw every minute and nobody was
| told. `tournaments:preflight` checks every tournament closing within 90
| minutes or waiting for its draw, rehearses its draw and rolls it back, and
| rings the admins for each finding, once per tournament, finding and hour.
|
*/

beforeEach(function () {
    Queue::fake();
    config(['esports.league.nsec' => (new TestSigner)->secret]);
    Cache::forever(TournamentScheduler::HEARTBEAT, now()->subMinute()->getTimestamp());
    $this->admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $this->admin->pubkey]);
});

/** The block API: tip 900000 with its hash and time; `$down` answers every call with a 503. */
function preflightBlocks(bool $down = false): void
{
    $hash = hash('sha256', 'block 900000');
    Http::fake(function ($request) use ($hash, $down) {
        return match (true) {
            $down => Http::response('', 503),
            str_ends_with($request->url(), '/blocks/tip/height') => Http::response('900000'),
            str_ends_with($request->url(), '/block-height/900000') => Http::response($hash),
            str_ends_with($request->url(), '/block/'.$hash) => Http::response(['timestamp' => now()->subMinutes(5)->getTimestamp()]),
            default => Http::response('', 404),
        };
    });
}

/**
 * A solo tournament with `$n` players whose sign-up closes in 30 minutes.
 *
 * @return array{0: Tournament, 1: list<User>, 2: list<TournamentSignup>}
 */
function preflightTournament(int $n): array
{
    $tournament = openTournament(['capacity' => 8]);
    [$players, $signups] = [[], []];

    foreach (range(1, $n) as $i) {
        [$player, $signer] = keyedPlayer();
        $players[] = $player;
        $signups[] = soloSignup($tournament, $player, $signer);
    }

    $tournament->forceFill(['signup_closes_at' => now()->addMinutes(30)])->save();

    return [$tournament->refresh(), $players, $signups];
}

/** The admin's preflight bell entries. */
function preflightBell(User $admin): Collection
{
    return $admin->notifications()->where('type', NotificationKind::LeagueAlert->value)->get();
}

/** The table row of one check, as printed. */
function preflightRow(string $output, string $check): string
{
    preg_match('/^\|[^\n]*\| '.preg_quote($check, '/').' +\|[^\n]*$/m', $output, $row);

    return $row[0] ?? '';
}

function preflightRun(): array
{
    $code = Artisan::call('tournaments:preflight');

    return [$code, Artisan::output()];
}

test('a healthy tournament passes every check, and the dry draw leaves nothing behind', function () {
    preflightBlocks();
    [$tournament, , $signups] = preflightTournament(3);
    Event::fake([TournamentChanged::class]);

    [$code, $output] = preflightRun();

    expect($code)->toBe(0)
        ->and($output)->toContain('Every check passed.')
        ->and(preflightRow($output, 'dry_draw'))->toContain('ok')->toContain('3 participant(s), rolled back')
        ->and(preflightRow($output, 'format'))->toContain('ok')
        ->and(TournamentParticipant::query()->where('tournament_id', $tournament->id)->count())->toBe(0)
        ->and(TournamentMatch::query()->where('tournament_id', $tournament->id)->count())->toBe(0)
        ->and($tournament->refresh()->status)->toBe(TournamentStatus::Signup)
        ->and($tournament->seed)->toBeNull()
        ->and(preflightBell($this->admin))->toHaveCount(0);
    Event::assertNotDispatched(TournamentChanged::class);
});

test('an orphan sign-up is flagged before the close, the admins hear of it once an hour, and the rehearsal withdraws nothing', function () {
    preflightBlocks();
    [$tournament, $players, $signups] = preflightTournament(3);
    // The prod shape: the account row went, the FK nulled user_id, members still names the player.
    User::query()->whereKey($players[0]->id)->delete();

    [$code, $output] = preflightRun();
    $bell = preflightBell($this->admin);

    expect($code)->toBe(1)
        ->and(preflightRow($output, 'accounts'))->toContain('FAILED')->toContain('#'.$signups[0]->id)
        ->and($bell)->toHaveCount(1)
        ->and($bell->first()->data['title'])->toBe("Tournament {$tournament->name}: the check before the draw failed")
        ->and($bell->first()->data['body'])->toContain('Entries without an account')
        // The dry draw withdrew the orphan inside its transaction only.
        ->and($signups[0]->refresh()->withdrawn_at)->toBeNull();

    preflightRun();
    expect(preflightBell($this->admin))->toHaveCount(1);

    $this->travel(61)->minutes();
    Cache::forever(TournamentScheduler::HEARTBEAT, now()->getTimestamp());
    preflightRun();
    expect(preflightBell($this->admin))->toHaveCount(2);
});

test('a special with too few entries for its format is flagged: the close would call it off', function () {
    preflightBlocks();
    [$tournament] = preflightTournament(1);

    [$code, $output] = preflightRun();

    expect($code)->toBe(1)
        ->and(preflightRow($output, 'format'))->toContain('FAILED')->toContain('it has 1')
        ->and(preflightBell($this->admin)->first()->data['body'])->toContain('the close calls it off');
});

test('a scheduler that has not ticked for over three minutes is flagged', function () {
    preflightBlocks();
    preflightTournament(2);
    Cache::forever(TournamentScheduler::HEARTBEAT, now()->subMinutes(4)->getTimestamp());

    [$code, $output] = preflightRun();

    expect($code)->toBe(1)
        ->and(preflightRow($output, 'scheduler'))->toContain('FAILED')->toContain('240 s ago')
        ->and(preflightBell($this->admin)->first()->data['body'])->toContain('tournaments:tick last ran');

    Cache::forget(TournamentScheduler::HEARTBEAT);
    [, $output] = preflightRun();
    expect(preflightRow($output, 'scheduler'))->toContain('no heartbeat');
});

test('a Bitcoin API outage is flagged, the dry draw says it could not run, and the admins hear of it from the second run in a row', function () {
    preflightBlocks(down: true);
    preflightTournament(2);

    [$code, $output] = preflightRun();

    expect($code)->toBe(1)
        ->and(preflightRow($output, 'bitcoin'))->toContain('FAILED')->toContain('no tip height')
        ->and(preflightRow($output, 'dry_draw'))->toContain('FAILED')->toContain('no block hash')
        // One miss is a hiccup: no bell yet (prod 2026-10-09).
        ->and(preflightBell($this->admin))->toHaveCount(0);

    preflightRun();

    // Still down ten minutes later: one bell, for the Bitcoin check only (the dry draw is its consequence).
    expect(preflightBell($this->admin))->toHaveCount(1)
        ->and(preflightBell($this->admin)->first()->data['body'] ?? json_encode(preflightBell($this->admin)->first()->data))->toContain('no tip height');
});

test('a block found seconds ago without its details yet: the check uses the block before it and stays green, nobody is rung', function () {
    // Prod 2026-10-09: "The Bitcoin API gives no time for block 970662: no draw runs." right after the block was found.
    [$tipHash, $prevHash] = [hash('sha256', 'block 900001'), hash('sha256', 'block 900000')];
    Http::fake(fn ($request) => match (true) {
        str_ends_with($request->url(), '/blocks/tip/height') => Http::response('900001'),
        str_ends_with($request->url(), '/block-height/900001') => Http::response($tipHash),
        str_ends_with($request->url(), '/block/'.$tipHash) => Http::response('', 404),
        str_ends_with($request->url(), '/block-height/900000') => Http::response($prevHash),
        str_ends_with($request->url(), '/block/'.$prevHash) => Http::response(['timestamp' => now()->subMinutes(9)->getTimestamp()]),
        default => Http::response('', 404),
    });
    preflightTournament(2);

    [$code, $output] = preflightRun();

    expect($code)->toBe(0)
        ->and(preflightRow($output, 'bitcoin'))->toContain('block 900000 (block 900001 not indexed yet)')
        ->and(preflightRow($output, 'dry_draw'))->not->toContain('FAILED')
        ->and(preflightBell($this->admin))->toHaveCount(0);
});

test('a Bitcoin API back after one miss starts the count anew', function () {
    $hash = hash('sha256', 'block 900000');
    $down = true;
    // One fake with a switch: a second Http::fake would stack behind the first.
    Http::fake(function ($request) use ($hash, &$down) {
        return match (true) {
            $down => Http::response('', 503),
            str_ends_with($request->url(), '/blocks/tip/height') => Http::response('900000'),
            str_ends_with($request->url(), '/block-height/900000') => Http::response($hash),
            str_ends_with($request->url(), '/block/'.$hash) => Http::response(['timestamp' => now()->subMinutes(5)->getTimestamp()]),
            default => Http::response('', 404),
        };
    });
    preflightTournament(2);

    preflightRun();
    $down = false;
    preflightRun();
    $down = true;
    preflightRun();

    expect(preflightBell($this->admin))->toHaveCount(0);
});

test('a tournament waiting for its draw whose draw would throw is flagged by the dry draw', function () {
    preflightBlocks();
    [$tournament] = preflightTournament(2);
    $tournament->forceFill(['status' => TournamentStatus::Drawing, 'signup_closes_at' => now()->subHour(), 'draw_height' => 900000])->save();
    DB::statement("CREATE TRIGGER refuse_participants BEFORE INSERT ON tournament_participants BEGIN SELECT RAISE(ABORT, 'participant store down'); END");

    [$code, $output] = preflightRun();

    expect($code)->toBe(1)
        ->and(preflightRow($output, 'dry_draw'))->toContain('FAILED')->toContain('participant store down')
        ->and($tournament->refresh()->status)->toBe(TournamentStatus::Drawing);
});

test('a missing league key is flagged, and a tournament closing later than 90 minutes is not checked', function () {
    preflightBlocks();
    [$later] = preflightTournament(2);
    $later->forceFill(['signup_closes_at' => now()->addHours(2)])->save();

    [$code, $output] = preflightRun();
    expect($code)->toBe(0)->and($output)->toContain('No tournament closes within 90 minutes');

    $later->forceFill(['signup_closes_at' => now()->addMinutes(80)])->save();
    config(['esports.league.nsec' => null]);
    [$code, $output] = preflightRun();

    expect($code)->toBe(1)->and(preflightRow($output, 'league_key'))->toContain('FAILED');
});

test('the preflight runs every ten minutes', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains((string) $event->command, 'tournaments:preflight'));

    expect($event)->not->toBeNull()
        ->and($event->getExpression())->toBe('*/10 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});
