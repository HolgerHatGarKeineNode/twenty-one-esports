<?php

use App\Enums\BoardGameStatus;
use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Games\Blockli;
use App\Games\GameRegistry;
use App\Models\Admin;
use App\Models\BoardGame;
use App\Models\BoardQueueEntry;
use App\Models\Rating;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\TournamentSignup;
use App\Models\User;
use App\Support\Board\BoardGameService;
use App\Support\Board\BoardQueue;
use App\Support\Board\BoardRuleViolation;
use App\Support\Invites\InviteGames;
use App\Support\Navigation\ShellNavigation;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use App\Support\Tournaments\NightPause;
use App\Support\Tournaments\TournamentBrackets;
use App\Support\Tournaments\TournamentGames;
use App\Support\Tournaments\TournamentModeSwitch;
use App\Support\Tournaments\TournamentRuleViolation;
use App\Support\Tournaments\TournamentRunner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\BlockliOn;

/*
|--------------------------------------------------------------------------
| Blockli blitz and rapid, as chess has them (user, 2026-10-11)
|--------------------------------------------------------------------------
|
| "Blockli braucht unbedingt einen Rapid Modus, wie Schach ihn auch hat
| (sollte vorbereitet sein) und Turniere bei Blockli sollten auf Rapid Modus
| umstellbar sein, auch schon existierende." and "Oder am besten noch Blitz
| auch anschalten." Blockli plays blitz 5+3, rapid 10+5 and correspondence,
| in chess's order, each rated on its own; the lobby queues and invites in
| both live modes; a Blockli tournament switches its time control in every
| state until the end, a running one for the games not started yet, and its
| players are told once.
|
*/

beforeEach(function () {
    Queue::fake();
    BlockliOn::play();
});

function blockliLiveAdmin(): User
{
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    return $admin;
}

/** A running two-player Blockli knockout in `$mode`, its first game started. */
function blockliLiveTournament(string $mode): Tournament
{
    $tournament = Tournament::factory()->create([
        'game' => 'blockli', 'mode' => $mode, 'format' => TournamentFormat::SingleElimination,
        'options' => FormatOptions::fromArray([], GameProfile::for('blockli', $mode))->toArray(),
        'capacity' => 2, 'results_mode' => TournamentResultsMode::Players, 'status' => TournamentStatus::Running,
        'slug' => 'blockli-live-'.fake()->unique()->numberBetween(1, 1_000_000), 'published_at' => now()->subDay(), 'night_pause' => false,
    ]);

    foreach (range(1, 2) as $index) {
        $user = User::factory()->create();
        TournamentParticipant::query()->create(['tournament_id' => $tournament->id, 'user_id' => $user->id, 'name' => "Live {$index}", 'rating' => 1500 - $index, 'members' => [$user->id]]);
    }

    app(TournamentBrackets::class)->generate($tournament, str_repeat('12', 32));
    app(TournamentRunner::class)->sync($tournament);

    return $tournament->refresh();
}

function liveGameOf(Tournament $tournament): BoardGame
{
    return BoardGame::query()->whereIn('tournament_match_id', $tournament->matches()->select('id'))->where('status', BoardGameStatus::Active)->sole();
}

test('Blockli offers blitz 5+3, rapid 10+5 and correspondence in chess\'s order, each a tournament game and an invite mode', function () {
    $blockli = app(GameRegistry::class)->get(Blockli::SLUG);

    expect(array_keys($blockli->modes()))->toBe(['blitz', 'rapid', 'correspondence'])
        ->and($blockli->mode('blitz')?->timeControl)->toBe('300+3')
        ->and($blockli->mode('rapid')?->timeControl)->toBe('600+5')
        ->and($blockli->liveModes())->toBe(['blitz', 'rapid'])
        ->and(TournamentGames::keyOf(Blockli::SLUG, 'blitz'))->not->toBeNull()
        ->and(TournamentGames::keyOf(Blockli::SLUG, 'rapid'))->not->toBeNull()
        ->and(GameProfile::for(Blockli::SLUG, 'rapid')->isDaily())->toBeFalse()
        ->and(app(InviteGames::class)->find(Blockli::SLUG)['modes'])->toBe(['correspondence', 'rapid', 'blitz']);

    // The context bar plays live on the lobby and keeps correspondence one link away.
    $this->actingAs(User::factory()->create());
    $actions = collect(ShellNavigation::current()->games())->firstWhere('slug', Blockli::SLUG)['actions'];
    expect(array_column($actions, 'label'))->toContain('Play live', 'Correspondence');
});

test('a rapid game runs on a 10+5 clock and is rated on its own pool; blitz stays untouched', function () {
    [$anna, $bert] = User::factory()->count(2)->create();
    $service = app(BoardGameService::class);
    $game = $service->start(Blockli::SLUG, $anna, $bert, 'rapid');

    expect($game->mode)->toBe('rapid')
        ->and($game->isCorrespondence())->toBeFalse()
        ->and($service->snapshot($game)['clock']['w'] ?? $service->snapshot($game)['clock']['white'] ?? null)->not->toBeNull();

    $service->resign($game->refresh(), $bert);

    expect(Rating::query()->where(['game' => Blockli::SLUG, 'mode' => 'rapid'])->count())->toBe(2)
        ->and(Rating::query()->where(['game' => Blockli::SLUG, 'mode' => 'blitz'])->count())->toBe(0)
        ->and(Rating::query()->where(['game' => Blockli::SLUG, 'mode' => 'correspondence'])->count())->toBe(0);
});

test('one live game at a time: a player in a rapid game cannot start a blitz one, a correspondence game still can', function () {
    [$anna, $bert, $carl] = User::factory()->count(3)->create();
    $service = app(BoardGameService::class);
    $service->start(Blockli::SLUG, $anna, $bert, 'rapid');

    expect(fn () => $service->start(Blockli::SLUG, $anna, $carl, 'blitz'))->toThrow(BoardRuleViolation::class)
        ->and($service->start(Blockli::SLUG, $anna, $carl)->mode)->toBe('correspondence');
});

test('the lobby has a tile per live mode and queues in the one asked for; two rapid searchers get a rapid game', function () {
    [$anna, $bert] = User::factory()->count(2)->create();

    Livewire::actingAs($anna)->test('pages::board.lobby', ['board' => Blockli::SLUG])
        ->assertSeeHtml('data-test="play-blitz"')->assertSeeHtml('data-test="play-rapid"')->assertSeeHtml('data-test="play-correspondence"')
        ->assertSeeHtml('data-test="lobby-ladder-mode-rapid"')
        ->call('findOpponent', false, 'rapid')
        ->assertSeeHtml('data-test="lobby-searching"');

    expect(BoardQueueEntry::query()->sole()->mode)->toBe('rapid');

    $game = app(BoardQueue::class)->join($bert, Blockli::SLUG, 'rapid');

    expect($game?->mode)->toBe('rapid')
        ->and(BoardQueueEntry::query()->count())->toBe(0);

    // The ladder card's chip shows the rapid ladder.
    Livewire::actingAs($bert)->test('pages::board.lobby', ['board' => Blockli::SLUG])->call('showLadder', 'rapid')->assertSet('ladderMode', 'rapid')
        ->assertSeeHtml('data-mode="rapid"');
});

test('"Live now" in the lobby lists the live games first, then the running cup games marked as such; a casual correspondence game stays out (DerCaddy 2026-10-15)', function () {
    $cup = liveGameOf(blockliLiveTournament('correspondence'));
    $casual = app(BoardGameService::class)->start(Blockli::SLUG, ...User::factory()->count(2)->create()->all(), mode: 'correspondence');
    $blitz = app(BoardGameService::class)->start(Blockli::SLUG, ...User::factory()->count(2)->create()->all(), mode: 'blitz');

    $lobby = Livewire::test('pages::board.lobby', ['board' => Blockli::SLUG]);

    expect($lobby->instance()->liveGames->pluck('id')->all())->toBe([$blitz->id, $cup->id])
        ->and($lobby->instance()->liveCount)->toBe(2)
        ->and($casual->tournament_match_id)->toBeNull();
    $lobby->assertSee(route('board.show', $cup), false)->assertDontSee(route('board.show', $casual), false)
        ->assertSeeHtml('data-test="live-game-cup"');
});

test('the lobby hero counts the games running as "Live now" does, not the viewer\'s own correspondence games', function () {
    // PR #24 review, 2026-11-10: the hero said "0 games running" while "Live now" listed 2 running Blockli games.
    [$me, $friend] = User::factory()->count(2)->create()->all();
    app(BoardGameService::class)->start(Blockli::SLUG, $me, $friend, mode: 'correspondence');
    app(BoardGameService::class)->start(Blockli::SLUG, ...User::factory()->count(2)->create()->all(), mode: 'blitz');
    app(BoardGameService::class)->start(Blockli::SLUG, ...User::factory()->count(2)->create()->all(), mode: 'rapid');

    $stat = fn (string $html): string => trim(strip_tags(str((string) $html)->after('data-test="game-stat"')->before('</div>')->toString()));

    expect($stat($this->get(route('board.lobby', Blockli::SLUG))->assertOk()->getContent()))->toContain('2')->toContain('games running')
        ->and($stat($this->actingAs($me)->get(route('board.lobby', Blockli::SLUG))->assertOk()->getContent()))->toContain('2')->not->toContain('3');
});

test('a tournament switches its time control in a draft, in sign-up and with the draw pending; once players are in, each is told once', function (TournamentStatus $status, int $told) {
    $admin = blockliLiveAdmin();
    $tournament = Tournament::factory()->create(['status' => $status, 'game' => 'blockli', 'mode' => 'correspondence', 'starts_at' => now()->addDays(3),
        'signup_closes_at' => $status === TournamentStatus::Signup ? now()->addDay() : now()->subHour(), 'published_at' => $status === TournamentStatus::Draft ? null : now()->subDay()]);
    foreach (User::factory()->count(2)->create() as $player) {
        TournamentSignup::query()->create(['tournament_id' => $tournament->id, 'user_id' => $player->id, 'name' => $player->displayName(), 'members' => [$player->id]]);
    }
    $switch = app(TournamentModeSwitch::class);

    expect($switch->canSwitch($tournament))->toBeTrue()
        ->and($switch->switch($tournament, $admin, 'rapid'))->toBeTrue()
        ->and($tournament->refresh()->mode)->toBe('rapid')
        ->and($switch->switch($tournament, $admin, 'rapid'))->toBeFalse()
        ->and(DB::table('notifications')->count())->toBe($told);
})->with([
    'draft' => [TournamentStatus::Draft, 0],
    'sign-up' => [TournamentStatus::Signup, 2],
    'draw pending' => [TournamentStatus::Drawing, 2],
]);

test('a running tournament switches for the games not started yet: the game under way keeps its mode, the next one plays the new mode, the players are told once', function () {
    $admin = blockliLiveAdmin();
    $tournament = blockliLiveTournament('correspondence');
    $first = liveGameOf($tournament);
    $switch = app(TournamentModeSwitch::class);
    $news = fn (): int => DB::table('notifications')->where('data', 'like', '%now plays%')->count();

    expect($first->mode)->toBe('correspondence')
        ->and($switch->switch($tournament, $admin, 'rapid'))->toBeTrue()
        ->and($first->refresh()->mode)->toBe('correspondence')
        ->and($news())->toBe(2);

    // The first leg ends: the second leg starts in rapid.
    $loser = $first->white;
    app(BoardGameService::class)->resign($first, $loser);
    $second = liveGameOf($tournament->refresh());

    expect($second->tournament_game)->toBe(2)
        ->and($second->mode)->toBe('rapid')
        ->and($switch->lastSwitch($tournament))->toMatchArray(['from' => 'correspondence', 'to' => 'rapid']);

    // The tournament page says so in one line.
    $this->get(route('tournaments.show', $tournament))->assertOk()->assertSee('data-test="mode-switched"', false)
        ->assertSee('Switched from Correspondence to Rapid 10+5');

    // Telling again for the same switch sends nothing.
    (fn () => $this->tell($tournament->refresh()))->call($switch);
    expect($news())->toBe(2);
});

test('the night pause gates a rapid tournament\'s starts but not a correspondence one\'s', function () {
    $tournament = blockliLiveTournament('correspondence');
    $tournament->forceFill(['night_pause' => true])->save();
    $night = CarbonImmutable::parse('2026-10-12 03:00', 'Europe/Berlin');

    expect(NightPause::activeFor($tournament->refresh(), $night))->toBeNull();

    $tournament->forceFill(['mode' => 'rapid'])->save();
    expect(NightPause::activeFor($tournament->refresh(), $night))->not->toBeNull();
});

test('a chess tournament, a finished one and a casual cup cannot switch; only an organizer or an admin can', function () {
    $switch = app(TournamentModeSwitch::class);
    $chess = Tournament::factory()->create(['status' => TournamentStatus::Draft, 'game' => 'chess', 'mode' => 'blitz']);
    $finished = Tournament::factory()->create(['status' => TournamentStatus::Finished, 'game' => 'blockli', 'mode' => 'correspondence']);
    $draft = Tournament::factory()->create(['status' => TournamentStatus::Draft, 'game' => 'blockli', 'mode' => 'correspondence']);

    expect($switch->canSwitch($chess))->toBeFalse()
        ->and($switch->canSwitch($finished))->toBeFalse()
        ->and(fn () => $switch->switch($draft, User::factory()->create(), 'rapid'))->toThrow(TournamentRuleViolation::class)
        ->and(fn () => $switch->switch($finished, blockliLiveAdmin(), 'rapid'))->toThrow(TournamentRuleViolation::class);
});

test('the edit page offers the switch with the board\'s choice list; it saves only with "Save changes", also for a running tournament', function () {
    $admin = blockliLiveAdmin();
    $tournament = blockliLiveTournament('correspondence');

    $page = Livewire::actingAs($admin)->test('pages::admin.tournament-edit', ['tournament' => $tournament])
        ->assertSeeHtml('data-test="mode-switch-blitz"')->assertSeeHtml('data-test="mode-switch-rapid"')
        ->set('mode', 'blitz');

    // User, 2026-10-12: no edit of the form saves on its own.
    expect($tournament->refresh()->mode)->toBe('correspondence');

    $page->call('save')->assertSet('error', '')->assertSet('mode', 'blitz');

    expect($tournament->refresh()->mode)->toBe('blitz');
});
