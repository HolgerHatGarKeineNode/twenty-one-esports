<?php

use App\Enums\Platform;
use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Models\ChessGame;
use App\Models\ChessQueueEntry;
use App\Models\SeriesMatch;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentParticipant;
use App\Models\TournamentReminder;
use App\Models\User;
use App\Support\Chess\ChessInvites;
use App\Support\Chess\ChessQueue;
use App\Support\Chess\ChessRuleViolation;
use App\Support\Chess\DailyChallenges;
use App\Support\Series\CasualQueue;
use App\Support\Series\SeriesRuleViolation;
use App\Support\Tournaments\CupMatchNow;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use App\Support\Tournaments\TournamentBrackets;
use App\Support\Tournaments\TournamentReminders;
use App\Support\Tournaments\TournamentRunner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TestSigner;

/*
|--------------------------------------------------------------------------
| Finding your cup match, everywhere (user, 2026-10-03)
|--------------------------------------------------------------------------
|
| "Du musst das total im Design verbessern und die Auffindbarkeit der
| Turniermatches unbedingt perfektionieren! ÜBERALL!!!!" — in a live cup 4 of
| 9 chess games were forfeited after the first-move window with 0 or 1
| moves, and an opponent blocked a cup match with an unrelated casual game.
|
| A participant with an open match sees it in the header (pulsing while
| live), first in the match dock and on top of home; nobody else does. In a
| running round the league refuses them every casual game ("Your cup match
| comes first"), and lets them play again once the match is decided. Two
| minutes into a cup game without the first move the player to move hears
| it once. The first-move window and the no-show waits are longer.
|
*/

beforeEach(function () {
    Queue::fake();
    config(['esports.league.nsec' => (new TestSigner)->secret]);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00:00', 'UTC'));
});

/** A running chess cup of four with round 1 open and no game yet: its first match and that match's two players (slot 0 first). */
function findableCupMatch(): array
{
    $cup = runningCup(4);
    cupTick();
    $match = openCupMatches($cup)->first();
    // A live round (a cup evening): only a round ending within casual_lock_hours locks casual play.
    $match->round->forceFill(['window_ends_at' => now()->addMinutes(30)])->save();

    return [$cup, $match, ...matchPlayers($match)];
}

/** The reason of the refusal an action throws, or null. */
function cupLockRefusal(Closure $action): ?string
{
    try {
        $action();
    } catch (ChessRuleViolation|SeriesRuleViolation $refused) {
        return $refused->reason;
    }

    return null;
}

test('the findability defaults: 10 minutes for a cup first move, 20 minutes before a no-show', function () {
    expect(config('esports.tournaments.first_move_seconds.blitz'))->toBe(600)
        ->and(config('esports.tournaments.round_clock.noshow_minutes'))->toBe(20)
        ->and(config('esports.series.noshow_minutes'))->toBe(20)
        ->and(config('esports.tournaments.first_move_nudge_seconds'))->toBe(120);
});

test('a participant waiting for their cup match sees it in the header, first in the dock and on top of the mempool; nobody else does', function () {
    [$cup, , $first, $second] = findableCupMatch();

    // Home carries it in the header, the dock (Header.dc.html "Frist läuft") and its banner before anything else (CupMatchNow, user 2026-10-03).
    $this->actingAs($first)->get(route('home'))->assertOk()
        ->assertSee('data-test="cup-badge" data-state="waiting"', false)
        ->assertSee('data-test="dock-cup" data-state="waiting"', false)
        ->assertSee('data-test="cup-banner" data-state="waiting"', false);

    $this->actingAs($first)->get(route('matches.index'))->assertOk()
        ->assertSee('data-test="cup-banner" data-state="waiting"', false)
        ->assertSeeInOrder(['data-test="cup-banner"', 'Your cup match is next', $cup->title(), 'Round 1', 'against '.$second->displayName(), 'Your cup match comes first: casual games wait until it is done.', 'Open tournament'], false)
        ->assertSee('Waiting for '.$second->displayName())
        ->assertSee(route('tournaments.show', $cup), false);

    // A player without a match sees none of it.
    $this->actingAs(User::factory()->create())->get(route('home'))->assertOk()
        ->assertDontSee('data-test="cup-badge"', false)
        ->assertDontSee('data-test="dock-cup"', false)
        ->assertDontSee('data-test="cup-banner"', false);
});

test('a live cup game turns the badge, the dock entry and the banner to "play now", on every page but its own board', function () {
    [, $match, $white, $black] = findableCupMatch();
    $invites = app(ChessInvites::class);
    $game = $invites->accept($invites->inviteToCupMatch($black, $match), $white);

    $this->actingAs($white)->get(route('home'))->assertOk()
        ->assertSee('data-test="cup-badge" data-state="live"', false)
        ->assertSee('data-test="dock-cup" data-state="live"', false);

    foreach ([route('chess.lobby'), route('matches.index')] as $url) {
        $this->actingAs($white)->get($url)->assertOk()
            ->assertSee('data-test="cup-badge" data-state="live"', false)
            ->assertSee('data-test="dock-cup" data-state="live"', false)
            ->assertSee('data-test="cup-banner" data-state="live"', false)
            ->assertSee('Your cup game is live — play now')
            ->assertSee('You have a cup game live')
            ->assertSee(route('games.show', $game), false);
    }

    // On its own board the dock does not point at the page the player is on.
    $this->actingAs($white)->get(route('games.show', $game))->assertOk()
        ->assertSee('data-test="cup-badge" data-state="live"', false)
        ->assertDontSee('data-test="dock-cup"', false);

    expect(app(CupMatchNow::class)->for(User::factory()->create()))->toBeNull();
});

test('during an open cup match the casual queues, invites and challenges are refused, the cup match itself is not; after it they are allowed', function () {
    [, $match, $white, $black] = findableCupMatch();
    $friend = User::factory()->create(['looking_to_play' => 'chess/blitz']);

    expect(cupLockRefusal(fn () => app(ChessQueue::class)->join($white)))->toBe('cup_match_first')
        ->and(cupLockRefusal(fn () => app(ChessInvites::class)->invite($white, $friend)))->toBe('cup_match_first')
        ->and(cupLockRefusal(fn () => app(ChessInvites::class)->invite($friend, $white)))->toBe('opponent_in_cup')
        ->and(cupLockRefusal(fn () => app(DailyChallenges::class)->challenge($white, $friend)))->toBe('cup_match_first')
        ->and(cupLockRefusal(fn () => app(CasualQueue::class)->join($white, 'rocket-league', Platform::Pc)))->toBe('cup_match_first')
        ->and(ChessQueueEntry::query()->where('user_id', $white->id)->exists())->toBeFalse();

    // The refusal says why, in the player's words.
    try {
        app(ChessQueue::class)->join($white);
    } catch (ChessRuleViolation $refused) {
        expect($refused->getMessage())->toBe('Your cup match comes first.');
    }

    // The cup match itself is what the lock is for.
    $invite = app(ChessInvites::class)->inviteToCupMatch($black, $match);
    expect($invite->tournament_match_id)->toBe($match->id);

    // Decided: the player is free again.
    TournamentMatch::query()->whereKey($match->id)->update(['status' => 'done', 'result' => json_encode(['winner' => 0, 'games_won' => [1, 0], 'points' => []])]);

    expect(cupLockRefusal(fn () => app(ChessQueue::class)->join($white)))->toBeNull()
        ->and(ChessQueueEntry::query()->where('user_id', $white->id)->exists())->toBeTrue()
        ->and(CupMatchNow::lockOf($white))->toBeNull();
});

test('the chess queue does not pair a searching player whose cup round opened meanwhile', function () {
    [, , $white] = findableCupMatch();
    $other = User::factory()->create();

    // White searched before the round opened (written directly: the lock refuses a search now).
    ChessQueueEntry::query()->create(['user_id' => $white->id, 'mode' => 'blitz', 'rated' => false, 'rating' => 1500, 'joined_at' => now()->subMinute()]);

    expect(app(ChessQueue::class)->join($other))->toBeNull()
        ->and(ChessQueueEntry::query()->where('user_id', $white->id)->exists())->toBeFalse();
});

test('two minutes into a cup game without the first move, the player to move is reminded once: bell and toast', function () {
    [$cup, $match, $white, $black] = findableCupMatch();
    $invites = app(ChessInvites::class);
    $game = $invites->accept($invites->inviteToCupMatch($black, $match), $white);
    // Black has the board open: only White, to move, is waited on.
    $game->forceFill(['black_seen_at' => now()])->save();
    $nudges = fn (User $player) => $player->notifications()->get()->pluck('data')->where('title', $black->displayName().' is waiting — your cup game is live');
    $reminders = app(TournamentReminders::class);

    $this->travel(100)->seconds();
    $reminders->tick();
    expect($nudges($white))->toHaveCount(0);

    $this->travel(30)->seconds();
    $reminders->tick();
    $reminders->tick();
    $this->travel(1)->minutes();
    $reminders->tick();

    expect($nudges($white))->toHaveCount(1)
        ->and($nudges($white)->first()['action'])->toBe('Play now')
        ->and($nudges($white)->first()['body'])->toContain('Make your first move within')
        ->and($nudges($white)->first()['match'])->toBeNull()
        ->and($black->notifications()->get()->pluck('data')->where('title', $white->displayName().' is waiting — your cup game is live'))->toHaveCount(0)
        ->and(TournamentReminder::query()->where('state', 'first_move_nudge')->where('user_id', $white->id)->where('tournament_id', $cup->id)->count())->toBe(1);
});

test('the league opening a tournament series or starting a tournament game tells both players with a sound and a toast', function () {
    $tournament = Tournament::factory()->create([
        'game' => 'rocket-league', 'mode' => '1v1', 'format' => TournamentFormat::SingleElimination, 'capacity' => 4,
        'options' => FormatOptions::fromArray(['thirdPlace' => false], GameProfile::for('rocket-league', '1v1'))->toArray(),
        'results_mode' => TournamentResultsMode::Players, 'status' => TournamentStatus::Running,
        'slug' => 'series-open-'.fake()->unique()->numberBetween(1, 1_000_000), 'created_by_id' => organizer()->id,
    ]);

    foreach (range(1, 4) as $index) {
        $player = User::factory()->create(['name' => "Player {$index}"]);
        TournamentParticipant::query()->create(['tournament_id' => $tournament->id, 'user_id' => $player->id, 'name' => "Player {$index}", 'rating' => 1200 - $index, 'members' => [$player->id]]);
    }

    app(TournamentBrackets::class)->generate($tournament, str_repeat('ab', 32));
    app(TournamentRunner::class)->sync($tournament);
    $series = SeriesMatch::query()->whereIn('tournament_match_id', TournamentMatch::query()->where('tournament_id', $tournament->id)->select('id'))->orderBy('id')->firstOrFail();

    foreach (['challenger' => 'challenged', 'challenged' => 'challenger'] as $side => $other) {
        $player = User::query()->findOrFail($series->rosterSide($side)[0]);
        $opened = $player->notifications()->get()->pluck('data')->firstWhere('title', $tournament->name.': your match is on');

        expect($opened)->not->toBeNull()
            ->and($opened['url'])->toBe(route('matches.room', $series))
            ->and($opened['sound'])->toBe('matchFound')
            ->and($opened['body'])->toContain('Against '.$series->sideName($other));
    }

    // A players-mode chess tournament (no cup): its games start with the round, and both players hear it.
    $chess = runningChess(TournamentFormat::SingleElimination, 2, TournamentResultsMode::Players);
    $game = ChessGame::query()->whereIn('tournament_match_id', $chess->matches()->select('id'))->sole();

    foreach ([$game->white, $game->black] as $player) {
        expect($player->notifications()->get()->pluck('data')->where('title', $chess->name.': your game is on'))->toHaveCount(1);
    }
});

test('a cup round over days does not keep its players out of casual games', function () {
    [, $match, $a] = findableCupMatch();
    $match->round->forceFill(['window_ends_at' => now()->addHours(36)])->save();

    expect(CupMatchNow::lockOf($a))->toBeNull();
});
