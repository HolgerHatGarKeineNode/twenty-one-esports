<?php

use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Models\ChessGame;
use App\Models\SeriesMatch;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentMatchSlot;
use App\Models\User;
use App\Support\Chess\ChessGameService;
use App\Support\Chess\ChessInvites;
use App\Support\Tournaments\CupSchedules;
use App\Support\Tournaments\TournamentRunner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TestSigner;

/*
|--------------------------------------------------------------------------
| "What to do now" at the top of a running tournament (user, 2026-10-03)
|--------------------------------------------------------------------------
|
| "Auf dieser Seite sind die Leute total lost, wenn das Turnier läuft. Oben
| müssen sie sehen, was sie machen müssen." Every participant gets one state
| with one primary action (App\Support\Tournaments\TournamentNow,
| partials/now), above everything else; a spectator gets the live boards.
|
*/

beforeEach(function () {
    config(['esports.league.nsec' => (new TestSigner)->secret]);
});

/** The page as `$viewer` sees it (a guest when null). */
function nowPage(Tournament $tournament, ?User $viewer): string
{
    $test = $viewer === null ? test() : test()->actingAs($viewer);

    return $test->get(route('tournaments.show', $tournament))->assertOk()->getContent();
}

/** The hero's markup alone, from its opening tag to the end of its section. */
function nowHero(string $html): string
{
    $start = strpos($html, 'data-test="now-hero"');
    expect($start)->not->toBeFalse();
    $open = strrpos(substr($html, 0, $start), '<section');

    return substr($html, $open, strpos($html, '</section>', $start) - $open);
}

/** The first round-1 chess game of the tournament with its White and Black. */
function nowFirstGame(Tournament $tournament): array
{
    $game = ChessGame::query()->whereIn('tournament_match_id', $tournament->matches()->select('id'))->orderBy('id')->firstOrFail();

    return [$game, $game->white, $game->black];
}

test('a player whose game is live sees "Play now" first, with the opponent, the round and "Go to your game"', function () {
    $tournament = runningChess(TournamentFormat::RoundRobin, 6, TournamentResultsMode::Players);
    [$game, $white, $black] = nowFirstGame($tournament);
    $html = nowPage($tournament, $white);
    $hero = nowHero($html);
    $opponent = $tournament->participants()->where('user_id', $black->id)->value('name');

    expect($hero)->toContain('data-state="play"')
        ->toContain('Play now')
        ->toContain('Round 1 against '.$opponent)
        ->toContain('Go to your game')
        ->toContain('href="'.route('games.show', $game).'"')
        // First on the page: before the tournament's own hero, the bracket and everything else.
        ->and(strpos($html, 'data-test="now-hero"'))->toBeLessThan(strpos($html, 'data-test="tournament-hero"'))
        ->and(substr_count($html, 'href="'.route('games.show', $game).'"'))->toBeGreaterThanOrEqual(1);
});

test('the hero stands above the organizer\'s bar', function () {
    $tournament = runningChess(TournamentFormat::SingleElimination, 4, TournamentResultsMode::Players);
    $creator = organizer();
    $tournament->forceFill(['created_by_id' => $creator->id])->save();
    $html = nowPage($tournament->refresh(), $creator);

    expect(nowHero($html))->toContain('data-state="watch"')
        ->and($html)->toContain('data-test="manage-bar"')
        ->and(strpos($html, 'data-test="now-hero"'))->toBeLessThan(strpos($html, 'data-test="manage-bar"'));
});

test('a player whose match is done waits: the round\'s live count, the strip and that the page switches by itself', function () {
    $tournament = runningChess(TournamentFormat::SingleElimination, 8, TournamentResultsMode::Players);
    [$game, $white, $black] = nowFirstGame($tournament);
    app(ChessGameService::class)->resign($game, $black);

    $hero = nowHero(nowPage($tournament, $white));

    expect($hero)->toContain('data-state="wait"')
        ->toContain('Wait for the next round')
        ->toContain('Round 1: 3 matches still playing')
        ->toContain('data-test="now-strip"')
        ->toContain('The next round starts when they finish.')
        ->toContain('This page tells you as soon as your game starts.')
        ->not->toContain('data-test="now-action"');
});

test('in a Swiss stage\'s last round a player whose match is done is told the matches are done, not to wait for a next round', function () {
    // Two rounds planned: after round 1 a next round comes.
    $two = runningChess(TournamentFormat::Swiss, 4, TournamentResultsMode::Players, ['swissRounds' => 2]);
    [$game, $white, $black] = nowFirstGame($two);
    app(ChessGameService::class)->resign($game, $black);

    expect(nowHero(nowPage($two, $white)))->toContain('data-state="wait"')->toContain('Wait for the next round');

    // One round planned: round 1 is the last, and the other match is still playing.
    $one = runningChess(TournamentFormat::Swiss, 4, TournamentResultsMode::Players, ['swissRounds' => 1]);
    [$game, $white, $black] = nowFirstGame($one);
    app(ChessGameService::class)->resign($game, $black);
    $hero = nowHero(nowPage($one, $white));

    expect($one->refresh()->status)->toBe(TournamentStatus::Running)
        ->and($hero)->toContain('data-state="done"')
        ->toContain('Your matches are done — waiting for the others')
        ->toContain('Round 1: 1 match still playing')
        ->toContain('The final standings come when the last games end.')
        ->toContain('Watch the rest')
        ->not->toContain('Wait for the next round')
        ->not->toContain('The next round starts');
});

test('a knocked-out player is out, with "Watch the rest"; the winner of a final is the champion', function () {
    $tournament = runningChess(TournamentFormat::SingleElimination, 4, TournamentResultsMode::Players);
    [$game, $white, $black] = nowFirstGame($tournament);
    app(ChessGameService::class)->resign($game, $black);

    expect(nowHero(nowPage($tournament, $black)))->toContain('data-state="out"')->toContain('You are out')->toContain('Thanks for playing!')->toContain('Watch the rest');

    $final = runningChess(TournamentFormat::SingleElimination, 2, TournamentResultsMode::Players);
    [$game, $white, $black] = nowFirstGame($final);
    app(ChessGameService::class)->resign($game, $black);

    // Finished, the page opens on the champion moment (ChampionMomentTest), with the viewer's own line in it.
    expect($final->refresh()->status)->toBe(TournamentStatus::Finished)
        ->and(nowPage($final, $white))->toContain('data-viewer="won"')->toContain('You won!')->not->toContain('data-test="now-hero"')
        ->and(nowPage($final, $black))->toContain('data-viewer="placed"')->toContain('You finished in place 2')->not->toContain('data-test="now-hero"');
});

test('a player without a game this round has a bye', function () {
    // A knockout of three: the best seed has no opponent in round 1 and waits in round 2.
    $tournament = runningChess(TournamentFormat::SingleElimination, 3, TournamentResultsMode::Players);
    $round = TournamentRunner::currentRound($tournament);
    $playing = TournamentMatchSlot::query()->whereIn('tournament_match_id', TournamentMatch::query()->where('tournament_round_id', $round->id)->select('id'))->pluck('tournament_participant_id');
    $player = User::query()->findOrFail($tournament->participants()->whereNotIn('id', $playing)->sole()->user_id);

    expect(nowHero(nowPage($tournament, $player)))->toContain('data-state="bye"')->toContain('Bye this round')->toContain('No game for you this round: you move on.');
});

test('a player whose opponent is in another game is told why the game waits (user, 2026-10-03: aHeck13 played a casual game)', function () {
    $cup = runningCup(4);
    cupTick();
    $match = openCupMatches($cup)->first();
    [$white, $black] = matchPlayers($match);
    app(ChessInvites::class)->inviteToCupMatch($white, $match);
    // The opponent is in an unrelated casual game.
    ChessGame::factory()->create(['white_id' => $black->id, 'black_id' => User::factory()->create()->id]);
    $name = $match->slots->firstWhere('slot', 1)->participant->name;

    expect(nowHero(nowPage($cup, $white)))->toContain('data-state="busy"')
        ->toContain('Your opponent is still playing')
        ->toContain(e($name).' is in another game right now. Your invite waits; this page tells you once they accept.')
        ->toContain('This page tells you as soon as your game starts.')
        ->not->toContain('data-test="now-action"');

    // Their round robin's next opponent still plays round 1: the next game starts within a minute after it ends.
    $tournament = runningChess(TournamentFormat::RoundRobin, 6, TournamentResultsMode::Players);
    [$game, $first, $second] = nowFirstGame($tournament);
    $other = ChessGame::query()->whereIn('tournament_match_id', $tournament->matches()->select('id'))->whereKeyNot($game->id)->orderBy('id')->firstOrFail();
    $busy = collect([$first, $second])->first(function (User $player) use ($tournament, $other): bool {
        $next = TournamentMatch::query()->where('tournament_id', $tournament->id)->whereNull('result')->whereDoesntHave('chessGame')
            ->whereHas('slots.participant', fn ($query) => $query->where('user_id', $player->id))->with('round', 'slots.participant')->get()->sortBy('round.number')->first();

        return $next !== null && $next->slots->contains(fn ($slot) => in_array($slot->participant?->user_id, [$other->white_id, $other->black_id], true));
    });
    app(ChessGameService::class)->resign($game, $second);

    expect($busy)->not->toBeNull();

    if ($busy !== null) {
        expect(nowHero(nowPage($tournament, $busy)))->toContain('data-state="busy"')->toContain('is in another game right now. Yours starts within a minute after it ends.');
    }
});

test('a spectator gets the live boards and the TV view, a guest too', function () {
    $tournament = runningChess(TournamentFormat::RoundRobin, 6, TournamentResultsMode::Players);
    [$game] = nowFirstGame($tournament);

    foreach ([User::factory()->create(), null] as $viewer) {
        $hero = nowHero(nowPage($tournament, $viewer));

        expect($hero)->toContain('data-state="watch"')
            ->toContain('Watch live')
            ->toContain('3 games running')
            ->toContain('href="'.route('games.show', $game).'"')
            ->toContain('Open the TV view')
            ->toContain('href="'.route('tournaments.tv', $tournament).'"')
            ->and(substr_count($hero, 'data-test="now-board"'))->toBe(3);
    }
});

test('a Rocket League cup match the league started: "Join your lobby" with the room, then the lobby name and password', function () {
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00:00', 'UTC'));
    $cup = runningCup(4, TournamentFormat::DoubleElimination, ['grandFinal' => 'single', 'bestOf' => 3, 'finalBestOf' => 3], 'rocket-league', '1v1');
    cupTick();
    $match = TournamentMatch::query()->where('tournament_id', $cup->id)->where('status', 'ready')->where('bracket', '!=', 'bye')->with('slots.participant')->orderBy('id')->firstOrFail();
    [$a, $b] = [User::query()->findOrFail($match->slots[0]->participant->user_id), User::query()->findOrFail($match->slots[1]->participant->user_id)];

    // Before an agreed time: agree on one, the times form is in the hero.
    expect(nowHero(nowPage($cup, $a)))->toContain('data-state="schedule"')->toContain('Agree on a time')->toContain('data-test="cup-schedule-form"');

    $at = now()->addHours(2)->getTimestamp();
    app(CupSchedules::class)->propose($match, $a, [$at]);
    app(CupSchedules::class)->accept($match, $b, $at);
    $this->travelTo(CarbonImmutable::createFromTimestamp($at));
    cupTick();
    $series = SeriesMatch::query()->where('tournament_match_id', $match->id)->firstOrFail();

    $hero = nowHero(nowPage($cup, $a));
    expect($hero)->toContain('data-state="room"')->toContain('Join your lobby')->toContain('Open your match room')
        ->toContain('href="'.route('matches.room', $series).'"')
        // A cup series is casual: the host shares the lobby in the room's chat.
        ->toContain('The host shares the lobby name and password in the match room chat.');
});

test('the old cup match card is gone: one action, shown once', function () {
    $cup = runningCup(4);
    cupTick();
    $match = openCupMatches($cup)->first();
    [$white] = matchPlayers($match);
    $html = nowPage($cup, $white);

    expect($html)->not->toContain('data-test="cup-match"')
        ->and(substr_count($html, 'wire:click="playCupMatch"'))->toBe(1)
        ->and(nowHero($html))->toContain('data-state="start"')->toContain('Invite your opponent');
});
