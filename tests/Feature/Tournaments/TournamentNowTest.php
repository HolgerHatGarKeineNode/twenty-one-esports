<?php

use App\Enums\BoardGameStatus;
use App\Enums\SeriesStatus;
use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Models\BoardGame;
use App\Models\ChessGame;
use App\Models\SeriesMatch;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentMatchSlot;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\Chess\ChessGameService;
use App\Support\Chess\ChessInvites;
use App\Support\Formats;
use App\Support\Tournaments\CupSchedules;
use App\Support\Tournaments\TournamentRunner;
use App\Support\Tournaments\TournamentWaits;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Support\BlockliOn;
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
        // Right under the tournament's hero (TournamentDetail.dc.html), before the bracket and everything else.
        ->and(strpos($html, 'data-test="now-hero"'))->toBeGreaterThan(strpos($html, 'data-test="tournament-hero"'))
        ->and(strpos($html, 'data-test="now-hero"'))->toBeLessThan(strpos($html, 'id="bracket"'))
        ->and(substr_count($html, 'href="'.route('games.show', $game).'"'))->toBeGreaterThanOrEqual(1);
});

test('"What to do now" stands above the organizer\'s bar', function () {
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
        // A cup match carries the league's lobby (P5, user 2026-10-10): name and password right here, not in the chat.
        ->toContain($series->lobby_name)->toContain($series->lobby_password)
        ->not->toContain('The host shares the lobby name and password in the match room chat.');
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

/** The result of `$match` as its players report it: slot `$winner` wins (TournamentRunner::store()), its series confirmed. */
function nowDecide(TournamentMatch $match, int $winner = 0): void
{
    SeriesMatch::query()->where('tournament_match_id', $match->id)->update(['status' => SeriesStatus::Confirmed]);
    app(TournamentRunner::class)->store($match->refresh(), ['winner' => $winner, 'games_won' => $winner === 0 ? [1.0, 0.0] : [0.0, 1.0], 'points' => [], 'forfeit' => false, 'label' => '-', 'by' => 'players']);
}

test('a paired match whose round is not open yet waits: the opponent, the round it waits for and how many are still open (user, 2026-10-10: "Spiel dein Match" while round 2 still played)', function () {
    Queue::fake();
    $cup = runningCup(4, TournamentFormat::RoundRobin, ['rankBy' => 'points'], 'rocket-league', '1v1');
    cupTick();
    $roundOf = fn (int $number) => TournamentMatch::query()->where('tournament_id', $cup->id)->where('bracket', '!=', 'bye')
        ->whereHas('round', fn ($query) => $query->where('number', $number))->with(['slots.participant', 'round'])->orderBy('id')->get();

    // Round 1 done, round 2 opens after the evening's break.
    $roundOf(1)->each(fn (TournamentMatch $match) => nowDecide($match));
    cupTick();
    $this->travel(10)->minutes();
    cupTick();
    [$mine, $other] = $roundOf(2)->all();
    expect($mine->round->refresh()->window_ends_at)->not->toBeNull();

    // The viewer's round 2 match is decided, the other one of round 2 still plays: the round 3 match is paired, but its round is closed.
    $viewer = User::query()->findOrFail($mine->slots[0]->participant->user_id);
    nowDecide($mine);
    cupTick();
    $next = $roundOf(3)->first(fn (TournamentMatch $match) => $match->slots->contains(fn ($slot) => $slot->participant?->user_id === $viewer->id));
    $opponent = $next->slots->first(fn ($slot) => $slot->participant->user_id !== $viewer->id)->participant->name;
    expect($next->status)->toBe('ready')->and($next->round->window_ends_at)->toBeNull();

    $hero = nowHero(nowPage($cup, $viewer));

    expect($hero)->toContain('data-state="wait"')->toContain('data-tone="wait"')
        ->toContain('Round 3 against '.$opponent)
        ->toContain('Your opponent is set: '.$opponent.'.')
        ->toContain('Your match starts as soon as the other matches of round 2 are done: 1 still open.')
        ->not->toContain('Play your match')
        ->not->toContain('data-test="now-action"');

    // The organizers' panel gives the same reason, not "The league starts it shortly".
    expect(TournamentWaits::forMatch($cup->refresh(), $next->refresh()->load(['round', 'slots.participant', 'seriesMatch.latestReport', 'chessGame', 'boardGame']))?->consequenceText())
        ->toBe('Starts once round 2 is done: 1 still open');

    // Round 2 done: round 3 opens after the evening's break, at a time the hero names.
    nowDecide($other);
    cupTick();
    expect(nowHero(nowPage($cup, $viewer)))->toContain('data-state="wait"')->toContain('Your match starts at ');
});

test('a match the directors enter can be played now: "Play your match", the directors enter the result', function () {
    $tournament = runningChess(TournamentFormat::RoundRobin, 4);
    $match = TournamentMatch::query()->where('tournament_id', $tournament->id)->where('status', 'ready')->where('bracket', '!=', 'bye')
        ->whereHas('round', fn ($query) => $query->where('number', 1))->with('slots.participant')->orderBy('id')->firstOrFail();
    [$white] = matchPlayers($match);

    expect(nowHero(nowPage($tournament, $white)))->toContain('data-state="match"')->toContain('Play your match')
        ->toContain('The tournament directors enter the result.');
});

test('the hero says whose move it is: the opponent\'s move waits and the clock stands, the own move is "Play now" (DerCaddy, 2026-10-16)', function () {
    // "Da steht, dass meine Uhr tickt, obwohl ich nicht an der Reihe bin."
    $tournament = runningChess(TournamentFormat::SingleElimination, 2, TournamentResultsMode::Players);
    [$game, $white, $black] = nowFirstGame($tournament);
    $whiteName = $tournament->participants()->where('user_id', $white->id)->value('name');

    expect(nowHero(nowPage($tournament, $white)))->toContain('data-state="play"')->toContain('data-tone="act"')->toContain('Play now')
        ->toContain('Your move: your first move has to come by')
        ->not->toContain('Your clock stands');

    expect(nowHero(nowPage($tournament, $black)))->toContain('data-state="their-move"')->toContain('data-tone="wait"')->toContain('Your game is on')
        ->toContain($whiteName.' is to move. Your clock stands.')
        ->toContain('Go to your game')->toContain('href="'.route('games.show', $game).'"')
        ->not->toContain('Play now');

    $chess = app(ChessGameService::class);
    $chess->move($game->refresh(), $white, 'e2e4');
    $chess->move($game->refresh(), $black, 'e7e5');

    expect(nowHero(nowPage($tournament, $white)))->toContain('data-state="play"')->toContain('Your move. Your clock runs.')
        ->and(nowHero(nowPage($tournament, $black)))->toContain('data-state="their-move"');
});

/** A running Blockli tournament (correspondence, the only mode its tournaments play): every round's games run at once. */
function nowBlockli(TournamentFormat $format, int $n): Tournament
{
    Queue::fake();
    BlockliOn::play();

    return blockliTournament($format, [], $n, ['timezone' => 'Europe/Berlin']);
}

test('in a correspondence game the hero names the deadline of the side to move, in the viewer\'s time zone', function () {
    $tournament = nowBlockli(TournamentFormat::SingleElimination, 2);
    $game = BoardGame::query()->whereIn('tournament_match_id', $tournament->matches()->select('id'))->where('status', BoardGameStatus::Active)->sole();
    $until = Formats::date(Carbon::createFromTimestampMs($game->deadline_ms), true, 'Europe/Berlin');
    $whiteName = $tournament->participants()->where('user_id', $game->white_id)->value('name');

    expect(nowHero(nowPage($tournament, $game->white)))->toContain('data-state="play"')->toContain('Your move, until '.$until.'.')
        ->toContain('href="'.route('board.show', $game).'"')
        ->and(nowHero(nowPage($tournament, $game->black)))->toContain('data-state="their-move"')->toContain($whiteName.' is to move, until '.$until.'.');
});

test('with several live games at once the hero points at one where the viewer is to move', function () {
    // A Blockli group of four: all three rounds run at once, so a player waits in one game and moves in another.
    $tournament = nowBlockli(TournamentFormat::RoundRobin, 4);
    $games = BoardGame::query()->whereIn('tournament_match_id', $tournament->matches()->select('id'))->where('status', BoardGameStatus::Active)->with(['tournamentMatch.round', 'white', 'black'])->get();
    $round = fn (BoardGame $game): int => $game->tournamentMatch->round->number;

    // A player who waits in an earlier round than one where they move: the earliest round alone would point at the wait.
    $viewer = $games->flatMap(fn (BoardGame $game) => [$game->white, $game->black])->unique('id')->first(function (User $user) use ($games, $round): bool {
        $theirs = $games->filter(fn (BoardGame $game) => $game->colorOf($user) !== null);
        $moves = $theirs->filter(fn (BoardGame $game) => $game->colorOf($user) === $game->turn)->min($round);
        $waits = $theirs->filter(fn (BoardGame $game) => $game->colorOf($user) !== $game->turn)->min($round);

        return $moves !== null && $waits !== null && $waits < $moves;
    });
    expect($viewer)->not->toBeNull();

    $move = $games->filter(fn (BoardGame $game) => $game->colorOf($viewer) === $game->turn)->sortBy(fn (BoardGame $game) => [$round($game), $game->tournament_match_id])->first();

    expect(nowHero(nowPage($tournament, $viewer)))->toContain('data-state="play"')->toContain('href="'.route('board.show', $move).'"');
});

test('before the start a drawn player sees the start big, in their own zone, at the top of the page (user, 2026-10-25)', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-25 18:30:00', 'UTC'));
    // Drawn at the sign-up close the day before, so the league holds every match until the start (TournamentMatchMaker).
    Tournament::creating(fn (Tournament $tournament) => $tournament->forceFill(['starts_at' => CarbonImmutable::parse('2026-10-25 20:00:00', 'UTC'), 'draw_committed_at' => now()->subDay()]));
    $tournament = runningChess(TournamentFormat::Swiss, 4, TournamentResultsMode::Players);
    $viewer = TournamentParticipant::query()->where('tournament_id', $tournament->id)->firstOrFail()->user;
    $viewer->forceFill(['timezone' => 'Europe/Riga'])->save();

    $html = nowPage($tournament, $viewer);
    $hero = nowHero($html);

    expect($hero)->toContain('data-state="wait"')
        ->toContain('data-test="now-starts-clock">22:00</time>')
        ->toContain('Riga time')
        ->toContain('data-test="now-starts-countdown">01:30:00</b>')
        // The page's own hero names the start too, above "What to do now".
        ->and($html)->toContain('data-test="tournament-start-clock">22:00</b>')
        ->and(strpos($html, 'data-test="tournament-start"'))->toBeLessThan(strpos($html, 'data-test="now-hero"'))
        // Nothing plays yet: "Drawn" and "See the draw", not "Running" and "Watch live".
        ->and($html)->toMatch('/data-test="tournament-status">(?:(?!data-test=).)*?Drawn<\/span>/s')->toMatch('/data-test="to-bracket">(?:(?!data-test=).)*?See the draw<\/a>/s');

    // A guest gets the league's zone.
    auth()->logout();
    expect(test()->get(route('tournaments.show', $tournament))->getContent())->toContain('data-test="tournament-start-clock">21:00</b>')->toContain('Berlin time');

    $this->travelTo(CarbonImmutable::parse('2026-10-25 20:00:01', 'UTC'));

    expect(nowPage($tournament, $viewer))->not->toContain('data-test="now-starts"')->not->toContain('data-test="tournament-start"')
        ->toMatch('/data-test="tournament-status">(?:(?!data-test=).)*?Running<\/span>/s')->toMatch('/data-test="to-bracket">(?:(?!data-test=).)*?Watch live<\/a>/s')->not->toContain('See the draw');
});

test('a player with a bye sees the round\'s running games with their move and when they are over at the latest (user, 2026-10-25)', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-25 21:00:00', 'UTC'));
    $tournament = runningChess(TournamentFormat::Swiss, 3, TournamentResultsMode::Players);
    $bye = TournamentMatch::query()->where('tournament_id', $tournament->id)->where('bracket', 'bye')->with('slots.participant.user')->firstOrFail();
    $viewer = $bye->slots[0]->participant->user;
    $game = ChessGame::query()->with(['white', 'black'])->whereIn('tournament_match_id', $tournament->matches()->select('id'))->firstOrFail();
    // Both clocks full (5+3 blitz): the game is over in ten minutes at the latest.
    $game->forceFill(['white_ms' => 300_000, 'black_ms' => 300_000, 'turn_started_ms' => (int) (microtime(true) * 1000)])->save();

    $hero = nowHero(nowPage($tournament, $viewer));

    expect($hero)->toContain('data-state="bye"')
        ->toContain('data-test="now-board"')
        ->toContain($game->white->displayName().' against '.$game->black->displayName().', move 1')
        ->toContain('data-test="now-eta"')
        ->toMatch('/data-test="now-eta-countdown">00:(09|10):\d\d<\/b>/');
});
