<?php

use App\Enums\NotificationKind;
use App\Enums\TournamentStatus;
use App\Livewire\Actions\DeleteAccount;
use App\Models\Admin;
use App\Models\Tournament;
use App\Models\TournamentModerationEntry;
use App\Models\TournamentParticipant;
use App\Models\TournamentSignup;
use App\Models\User;
use App\Support\Tournaments\TournamentDraws;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TestSigner;

/*
|--------------------------------------------------------------------------
| Sign-ups of deleted accounts never block the draw (prod, 2026-10-03)
|--------------------------------------------------------------------------
|
| Tournament 1 sat in "Draw pending": a player had deleted their account,
| their solo sign-up stayed active with `user_id` nulled and their id still
| in `members`, and every `tournaments:tick` threw a foreign key violation
| creating the participant. A deleted account now withdraws its open
| entries, the draw leaves out any entry whose players are gone, a failing
| draw rings the admins, and a data migration withdraws the orphans left.
|
*/

beforeEach(function () {
    Queue::fake();
    config(['esports.league.nsec' => (new TestSigner)->secret]);
});

/** The block API: tip `$tip`, block 900001 mined ten minutes after now. */
function orphanBlocks(int &$tip): void
{
    $hash = hash('sha256', 'block 900001');
    Http::fake(function ($request) use (&$tip, $hash) {
        return match (true) {
            str_ends_with($request->url(), '/blocks/tip/height') => Http::response((string) $tip),
            str_ends_with($request->url(), '/block-height/900001') => Http::response($hash),
            str_ends_with($request->url(), '/block/'.$hash) => Http::response(['timestamp' => now()->addMinutes(10)->getTimestamp()]),
            default => Http::response('', 404),
        };
    });
}

/**
 * A solo tournament with `$n` keyed players signed up.
 *
 * @return array{0: Tournament, 1: list<User>, 2: list<TournamentSignup>}
 */
function orphanSoloTournament(int $n): array
{
    $tournament = openTournament(['capacity' => 8]);
    $players = [];
    $signups = [];

    foreach (range(1, $n) as $i) {
        [$player, $signer] = keyedPlayer();
        $players[] = $player;
        $signups[] = soloSignup($tournament, $player, $signer);
    }

    return [$tournament, $players, $signups];
}

test('the test database enforces foreign keys, as PostgreSQL does on prod', function () {
    expect(DB::connection()->getDriverName() !== 'sqlite' || (int) DB::selectOne('PRAGMA foreign_keys')->foreign_keys === 1)->toBeTrue();

    expect(fn () => TournamentParticipant::query()->create([
        'tournament_id' => openTournament()->id, 'user_id' => 999999, 'name' => 'ghost', 'rating' => 1000, 'members' => [999999],
    ]))->toThrow(QueryException::class);
});

test('deleting an account withdraws its open sign-ups with a log line, and the draw then succeeds', function () {
    $tip = 900000;
    orphanBlocks($tip);
    [$tournament, $players, $signups] = orphanSoloTournament(3);

    app(DeleteAccount::class)($players[0]);

    $signup = $signups[0]->refresh();
    $line = TournamentModerationEntry::query()->where('tournament_id', $tournament->id)->sole();

    expect($signup->withdrawn_at)->not->toBeNull()
        ->and($line->action)->toBe('withdrawn')
        ->and($line->user_id)->toBeNull()
        ->and($line->tournament_signup_id)->toBe($signup->id)
        ->and($line->reason)->toBe('The player deleted their account.');

    // The organizer reads it in the moderation log of the edit page, without the deleted player's name.
    $this->actingAs($tournament->creator)->get(route('admin.tournaments.edit', $tournament))->assertOk()
        ->assertSeeInOrder(['League', 'withdrew an entry whose account is gone', 'Deleted player', 'The player deleted their account.'])
        ->assertDontSee($signup->name);

    $this->travel(25)->hours();
    $draws = app(TournamentDraws::class);
    expect($draws->close($tournament->refresh()))->toBeTrue();
    $tip = 900006;

    expect($draws->resolve($tournament->refresh()))->toBeTrue()
        ->and($tournament->refresh()->status)->toBe(TournamentStatus::Running)
        ->and(TournamentParticipant::query()->where('tournament_id', $tournament->id)->pluck('user_id')->sort()->values()->all())
        ->toBe([$players[1]->id, $players[2]->id]);
});

test('deleting an account during the draw wait withdraws the entry too', function () {
    $tip = 900000;
    orphanBlocks($tip);
    [$tournament, $players, $signups] = orphanSoloTournament(3);
    $this->travel(25)->hours();
    $draws = app(TournamentDraws::class);
    expect($draws->close($tournament->refresh()))->toBeTrue();

    app(DeleteAccount::class)($players[2]);
    $tip = 900006;

    expect($signups[2]->refresh()->withdrawn_at)->not->toBeNull()
        ->and($draws->resolve($tournament->refresh()))->toBeTrue()
        ->and(TournamentParticipant::query()->where('tournament_id', $tournament->id)->count())->toBe(2);
});

test('a lineup that loses a player keeps its entry while it still fields a team, else it is withdrawn', function () {
    $tournament = openTournament(['capacity' => 4], rocketLeague: true);
    [$full, $captainA, $signerA] = keyedLineup(subs: 1);
    [$short, $captainB, $signerB] = keyedLineup();
    $withSub = lineupSignup($tournament, $full, $captainA, $signerA);
    $exact = lineupSignup($tournament, $short, $captainB, $signerB);
    $leaverA = User::query()->findOrFail(collect($withSub->members)->first(fn (int $id) => $id !== $captainA->id));
    $leaverB = User::query()->findOrFail(collect($exact->members)->first(fn (int $id) => $id !== $captainB->id));

    app(DeleteAccount::class)($leaverA);
    app(DeleteAccount::class)($leaverB);

    expect($withSub->refresh()->withdrawn_at)->toBeNull()
        ->and($withSub->members)->toHaveCount(3)->not->toContain($leaverA->id)
        ->and($exact->refresh()->withdrawn_at)->not->toBeNull()
        ->and(TournamentModerationEntry::query()->where('tournament_id', $tournament->id)->orderBy('id')->pluck('action')->all())->toBe(['left', 'withdrawn']);
});

test('a sign-up orphaned before the fix (user_id null, its member gone) is left out of the draw, which succeeds', function () {
    $tip = 900000;
    orphanBlocks($tip);
    [$tournament, $players, $signups] = orphanSoloTournament(3);
    $this->travel(25)->hours();
    $draws = app(TournamentDraws::class);
    expect($draws->close($tournament->refresh()))->toBeTrue();

    // The prod shape: the account row went, the FK nulled user_id, members still names the player.
    $ghost = $players[0]->id;
    User::query()->whereKey($ghost)->delete();
    $orphan = $signups[0]->refresh();
    expect($orphan->user_id)->toBeNull()->and($orphan->members)->toBe([$ghost])->and($orphan->withdrawn_at)->toBeNull();

    $tip = 900006;

    expect($draws->resolve($tournament->refresh()))->toBeTrue()
        ->and($tournament->refresh()->status)->toBe(TournamentStatus::Running)
        ->and(TournamentParticipant::query()->where('tournament_id', $tournament->id)->pluck('tournament_signup_id')->sort()->values()->all())->toBe([$signups[1]->id, $signups[2]->id])
        ->and($orphan->refresh()->withdrawn_at)->not->toBeNull()
        ->and(TournamentModerationEntry::query()->where('tournament_signup_id', $orphan->id)->value('action'))->toBe('withdrawn');
});

test('a draw that throws rings every admin in the bell, once per tournament and hour, and is still reported', function () {
    $tip = 900000;
    orphanBlocks($tip);
    [$tournament] = orphanSoloTournament(2);
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    $this->travel(25)->hours();
    expect(app(TournamentDraws::class)->close($tournament->refresh()))->toBeTrue();
    $tip = 900006;

    // Any failure inside the draw: here the database refuses every participant.
    DB::statement("CREATE TRIGGER refuse_participants BEFORE INSERT ON tournament_participants BEGIN SELECT RAISE(ABORT, 'participant store down'); END");
    $reported = 0;
    $this->app->make(ExceptionHandler::class)->reportable(function (QueryException $e) use (&$reported) {
        $reported++;
    });
    $bell = fn () => $admin->notifications()->where('type', NotificationKind::LeagueAlert->value)->get();

    app(TournamentDraws::class)->advanceDue();
    app(TournamentDraws::class)->advanceDue();

    expect($bell())->toHaveCount(1)
        ->and($bell()->first()->data['title'])->toBe("Tournament {$tournament->name}: the draw failed")
        ->and($bell()->first()->data['body'])->toContain('participant store down')
        ->and($reported)->toBe(2)
        ->and($tournament->refresh()->status)->toBe(TournamentStatus::Drawing);

    $this->travel(61)->minutes();
    app(TournamentDraws::class)->advanceDue();

    expect($bell())->toHaveCount(2);
});

test('the data migration withdraws the orphans of tournaments before their draw, and only those', function () {
    [$open, $openPlayers, $openSignups] = orphanSoloTournament(2);
    [$drawing, $drawingPlayers, $drawingSignups] = orphanSoloTournament(2);
    [$done, $donePlayers, $doneSignups] = orphanSoloTournament(2);
    $drawing->forceFill(['status' => TournamentStatus::Drawing])->save();
    $done->forceFill(['status' => TournamentStatus::Cancelled])->save();

    User::query()->whereKey([$openPlayers[0]->id, $drawingPlayers[0]->id, $donePlayers[0]->id])->delete();

    (require database_path('migrations/2026_10_03_120000_withdraw_tournament_signups_of_deleted_accounts.php'))->up();

    expect($openSignups[0]->refresh()->withdrawn_at)->not->toBeNull()
        ->and($drawingSignups[0]->refresh()->withdrawn_at)->not->toBeNull()
        ->and($openSignups[1]->refresh()->withdrawn_at)->toBeNull()
        ->and($doneSignups[0]->refresh()->withdrawn_at)->toBeNull()
        ->and(TournamentModerationEntry::query()->where('action', 'withdrawn')->pluck('tournament_id')->sort()->values()->all())->toBe([$open->id, $drawing->id]);
});
