<?php

use App\Enums\ChessGameStatus;
use App\Enums\SeriesResolution;
use App\Enums\SeriesStatus;
use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Models\Admin;
use App\Models\ChessGame;
use App\Models\ChessInvite;
use App\Models\NostrEvent;
use App\Models\SeriesMatch;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\TournamentRound;
use App\Models\User;
use App\Support\Chess\ChessGameService;
use App\Support\Chess\ChessInvites;
use App\Support\Series\CasualMatches;
use App\Support\Settings\LeagueSettings;
use App\Support\Tournaments\CasualCups;
use App\Support\Tournaments\Engine\BracketBuilder;
use App\Support\Tournaments\Engine\Entrant;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use App\Support\Tournaments\TournamentDraws;
use App\Support\Tournaments\TournamentEditor;
use App\Support\Tournaments\TournamentRuleViolation;
use App\Support\Tournaments\TournamentRunner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\TestSigner;

/*
|--------------------------------------------------------------------------
| Automatic casual cups (P25, CasualCups)
|--------------------------------------------------------------------------
|
| One open cup per enabled game, opened by the tournament clock and numbered
| without gaps; sign-up starts it when full, at the close with enough
| players, else extends once and then calls it off. A double elimination
| bracket played in round windows; the league decides what is left at a
| deadline; chess draws go to a second game and then Armageddon.
|
*/

beforeEach(function () {
    Queue::fake();
    config(['esports.league.nsec' => (new TestSigner)->secret, 'esports.casual_cups.enabled' => ['chess']]);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00:00', 'UTC'));
});

/* ---------- Opening and numbering --------------------------------------------------------------------------- */

test('two ticks open exactly one chess cup per region, published by the league like an admin tournament', function () {
    cupTick();
    cupTick();

    $cup = openCup();

    expect(Tournament::query()->orderBy('cup_series')->pluck('cup_series')->all())->toBe(['chess-eu', 'chess-us'])
        ->and($cup->name)->toBe('Chess Casual Cup EU #1')
        ->and($cup->status)->toBe(TournamentStatus::Signup)
        ->and($cup->format)->toBe(TournamentFormat::DoubleElimination)
        ->and($cup->formatOptions()->grandFinal)->toBe('single')
        ->and($cup->capacity)->toBe(4)
        ->and($cup->ladder_address)->toBeNull()
        // Monday 10:00 UTC: the first EU slot at least 48 h away is Saturday 20:00 Berlin; sign-up closes at the start.
        ->and($cup->signup_closes_at->utc()->format('Y-m-d H:i'))->toBe('2026-10-10 18:00')
        ->and($cup->starts_at->equalTo($cup->signup_closes_at))->toBeTrue()
        ->and(NostrEvent::query()->findOrFail($cup->event_id)->kind)->toBe(Tournament::CALENDAR_EVENT);
});

test('a game whose automatic cups an admin switched off opens no new cup; its cups in sign-up and running play on', function () {
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    $running = runningCup(4);
    LeagueSettings::save($admin, ['esports.casual_cups.games.chess.auto' => 'off']);
    cupTick();

    // Off: the running cup opens its first round; no cup opens in the region without one.
    expect(CasualCups::enabledGames())->toBe([])
        ->and(TournamentRound::query()->whereNotNull('window_ends_at')->sole()->stage->tournament_id)->toBe($running->id)
        ->and(Tournament::query()->where('cup_series', 'chess-us')->exists())->toBeFalse();

    // On for one tick: the US cup opens; off again, it stays in sign-up.
    LeagueSettings::save($admin, ['esports.casual_cups.games.chess.auto' => 'on']);
    cupTick();
    $signup = Tournament::query()->where('cup_open_series', 'chess-us')->firstOrFail();
    LeagueSettings::save($admin, ['esports.casual_cups.games.chess.auto' => 'off']);
    cupTick();

    expect($signup->refresh()->status)->toBe(TournamentStatus::Signup);

    // Nobody signs up: extended once, then called off, as before; no next cup opens a day later.
    $this->travelTo($signup->signup_closes_at);
    cupTick();
    $this->travelTo($signup->refresh()->signup_closes_at);
    cupTick();
    $this->travel(25)->hours();
    cupTick();

    expect($signup->refresh()->status)->toBe(TournamentStatus::Cancelled)
        ->and(Tournament::query()->where('cup_series', 'chess-us')->count())->toBe(1)
        ->and(Tournament::query()->where('cup_open_series', 'chess-us')->exists())->toBeFalse();

    // Back on: the next tick opens the series' next cup.
    LeagueSettings::save($admin, ['esports.casual_cups.games.chess.auto' => 'on']);
    cupTick();

    expect(Tournament::query()->where('cup_open_series', 'chess-us')->value('name'))->toBe('Chess Casual Cup US #1');
});

test('a concurrent run that opened the cup first leaves one cup, not two', function () {
    // The rival commits its cup after this run checked for an open cup and read the next number,
    // before this run inserts (outside its transaction, so the rival stays): only the unique indexes stop it.
    $rival = null;
    DB::listen(function ($query) use (&$rival) {
        if ($rival === null && str_contains($query->sql, 'max("cup_number")')) {
            $rival = Tournament::factory()->create([
                'name' => 'Chess Casual Cup EU #1', 'created_by_id' => null, 'status' => TournamentStatus::Signup,
                'cup_series' => 'chess-eu', 'cup_number' => 1, 'cup_open_series' => 'chess-eu',
            ]);
        }
    });

    expect(app(CasualCups::class)->ensure('chess', 'eu'))->toBeNull()
        ->and($rival)->not->toBeNull()
        ->and(Tournament::query()->where('cup_series', 'chess-eu')->pluck('id')->all())->toBe([$rival->id]);
});

test('the next cup opens a day after the last final, and a called-off cup gives its number back', function () {
    cupTick();
    openCup()->forceFill(['status' => TournamentStatus::Finished])->save();

    cupTick();
    expect(openCup())->toBeNull();

    $this->travel(23)->hours();
    cupTick();
    expect(openCup())->toBeNull();

    $this->travel(1)->hours();
    cupTick();
    $second = openCup();
    expect($second->name)->toBe('Chess Casual Cup EU #2');

    // #2 gets nobody: extended once (to the next week's slot), then called off; its number is free again.
    $this->travelTo($second->signup_closes_at);
    cupTick();
    expect($second->refresh()->signup_closes_at->setTimezone('Europe/Berlin')->format('D Y-m-d H:i'))->toBe('Sat 2026-10-17 20:00');
    $this->travelTo($second->signup_closes_at);
    cupTick();

    expect($second->refresh()->status)->toBe(TournamentStatus::Cancelled)
        ->and($second->cup_number)->toBeNull();

    $this->travel(24)->hours();
    cupTick();

    expect(openCup()->name)->toBe('Chess Casual Cup EU #2')
        ->and(Tournament::query()->where('cup_series', 'chess-eu')->count())->toBe(3);
});

test('an admin edit never changes what makes a cup: game, mode, format, capacity, name or rating stay; the description may change', function (array $change) {
    cupTick();
    $cup = openCup();
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    $before = $cup->only(['name', 'game', 'mode', 'format', 'capacity', 'ladder_address', 'cup_number', 'options', 'results_mode']);
    $editor = app(TournamentEditor::class);

    expect(fn () => $editor->update($cup, $admin, $change))->toThrow(fn (TournamentRuleViolation $violation) => expect($violation->reason)->toBe('casual_cup'))
        ->and($cup->refresh()->only(array_keys($before)))->toBe($before);

    $editor->update($cup, $admin, ['description' => 'Bring a clock.']);

    expect($cup->refresh()->description)->toBe('Bring a clock.')
        ->and($cup->ladder_address)->toBeNull();
})->with([
    'game and mode' => [['game' => 'rocket-league', 'mode' => '1v1']],
    'mode' => [['mode' => 'correspondence']],
    'format' => [['format' => TournamentFormat::SingleElimination]],
    'best of' => [['options' => ['bestOf' => 1, 'finalBestOf' => 1, 'grandFinal' => 'reset']]],
    'capacity' => [['capacity' => 8]],
    'name (the number)' => [['name' => 'Chess Casual Cup #7']],
    'results mode' => [['results_mode' => TournamentResultsMode::Director]],
]);

/* ---------- Sign-up ----------------------------------------------------------------------------------------- */

test('a full cup closes sign-up at once and commits its draw', function () {
    config(['esports.casual_cups.sizes' => [8]]);
    Http::fake(['*/blocks/tip/height' => Http::response('900000')]);
    cupTick();
    $cup = openCup();
    cupSignups($cup, 8);

    cupTick();

    expect($cup->refresh()->status)->toBe(TournamentStatus::Drawing)
        ->and($cup->draw_height)->toBe(900001)
        ->and($cup->signup_closes_at->lessThanOrEqualTo(now()))->toBeTrue();
});

test('at the close six players start the cup, a lone player extends sign-up once and is then called off with a notice (S2: two to five play an evening)', function () {
    Http::fake(['*/blocks/tip/height' => Http::response('900000')]);
    cupTick();
    $six = openCup();
    cupSignups($six, 6);
    $six->forceFill(['cup_open_series' => null])->save();
    cupTick();
    $lone = openCup();
    cupSignups($lone, 1);

    $this->travelTo($lone->signup_closes_at->max($six->signup_closes_at));
    cupTick();

    // The extension is the region's next slot, a week later on the same clock.
    expect($six->refresh()->status)->toBe(TournamentStatus::Drawing)
        ->and($lone->refresh()->status)->toBe(TournamentStatus::Signup)
        ->and($lone->cup_extended_at)->not->toBeNull()
        ->and($lone->signup_closes_at->equalTo(now()->addWeek()))->toBeTrue()
        ->and($lone->starts_at->equalTo($lone->signup_closes_at))->toBeTrue();

    $this->travelTo($lone->signup_closes_at);
    cupTick();

    $player = User::query()->findOrFail($lone->signups()->firstOrFail()->members[0]);

    expect($lone->refresh()->status)->toBe(TournamentStatus::Cancelled)
        ->and($lone->cup_open_series)->toBeNull()
        ->and(NostrEvent::query()->findOrFail($lone->event_id)->payload()['tags'][1][1])->toStartWith('Called off: ')
        ->and($player->notifications()->count())->toBe(1);
});

test('the draw alone never closes a cup short of players: that is the cups\' own decision', function () {
    Http::fake(['*/blocks/tip/height' => Http::response('900000')]);
    cupTick();
    $cup = openCup();
    cupSignups($cup, 5);
    $this->travelTo($cup->signup_closes_at);

    app(TournamentDraws::class)->advanceDue();

    expect($cup->refresh()->status)->toBe(TournamentStatus::Signup)
        ->and($cup->draw_height)->toBeNull();
});

/* ---------- The bracket ------------------------------------------------------------------------------------- */

test('six players fill an 8-slot double elimination with byes for the top seeds, twelve a 16-slot one; seeding ignores Elo', function (int $n, int $byes, int $upperFirst) {
    $entrants = array_map(fn (int $id) => new Entrant($id), range(1, $n));
    $options = FormatOptions::fromArray(['grandFinal' => 'single'], GameProfile::for('chess', 'blitz'));
    $bracket = BracketBuilder::build(TournamentFormat::DoubleElimination, $entrants, $options, str_repeat('ab', 32));
    $keys = array_map(fn ($match) => $match->key, $bracket->matches);
    $firstRound = array_values(array_filter($bracket->matches, fn ($match) => $match->bracket === 'upper' && str_starts_with($match->key, 'u1-')));
    $playing = array_merge(...array_map(fn ($match) => array_map(fn ($slot) => $slot->entrant, $match->slots), $firstRound));
    $lowerFirst = collect($bracket->matches)->first(fn ($match) => $match->bracket === 'lower');

    expect($firstRound)->toHaveCount($upperFirst)
        // The byes: the top seeds play no first-round match.
        ->and(array_values(array_intersect(array_slice($bracket->seeds, 0, $byes), $playing)))->toBe([])
        ->and($keys)->toContain('gf')
        ->and($keys)->not->toContain('gf2')
        // The lower bracket starts from upper-bracket losers.
        ->and($lowerFirst->slots[0]->take)->toBe('loser');
})->with([
    '6 players' => [6, 2, 2],
    '12 players' => [12, 4, 4],
]);

test('a cup seeds at random, not by rating', function () {
    $cup = runningCup(8);
    $bySeed = TournamentParticipant::query()->where('tournament_id', $cup->id)->orderBy('seed')->pluck('rating')->all();

    expect($bySeed)->not->toBe(collect($bySeed)->sortDesc()->values()->all());
});

/* ---------- Round windows ----------------------------------------------------------------------------------- */

test('round 1 opens with a 48 h window; nothing starts before the auto slot, the league starts it at 20:00 Berlin on the last evening', function () {
    $cup = runningCup(4);
    cupTick();

    $round = TournamentRound::query()->whereNotNull('window_ends_at')->sole();
    $slot = CasualCups::autoSlot($round->window_ends_at);

    expect($round->window_ends_at->equalTo(now()->addHours(48)))->toBeTrue()
        ->and($slot->setTimezone('Europe/Berlin')->format('Y-m-d H:i'))->toBe('2026-10-06 20:00')
        ->and($cup->refresh()->starts_at->equalTo(now()))->toBeTrue()
        ->and(ChessGame::query()->count())->toBe(0);

    // Each of the four players hears once that their match is open: whom, until when, and the auto slot.
    foreach (openCupMatches($cup) as $match) {
        foreach ([0, 1] as $side) {
            $player = matchPlayers($match)[$side];
            $opponent = $match->slots[1 - $side]->participant->name;
            $notices = $player->notifications()->get()->pluck('data');

            expect($notices)->toHaveCount(1)
                ->and($notices[0]['title'])->toBe('Chess Casual Cup EU #1: your match is open')
                ->and($notices[0]['body'])->toContain($opponent)
                ->and($notices[0]['body'])->toContain('Tue 6 Oct, 20:00')
                ->and($notices[0]['url'])->toBe(route('tournaments.show', $cup));
        }
    }

    $this->travelTo($slot->subMinute());
    cupTick();
    expect(ChessGame::query()->count())->toBe(0);

    $this->travelTo($slot);
    cupTick();
    expect(ChessGame::query()->whereNotNull('tournament_match_id')->count())->toBe(2);

    // Started while the players may be away: both of every game are told, with their colour and the game link.
    foreach (ChessGame::query()->whereNotNull('tournament_match_id')->get() as $game) {
        foreach (['White' => $game->white, 'Black' => $game->black] as $color => $player) {
            $started = $player->notifications()->get()->pluck('data')->firstWhere('title', 'Chess Casual Cup EU #1: your game is on');

            expect($started)->not->toBeNull()
                ->and($started['body'])->toContain("You play {$color} against {$game->opponentOf($player)->displayName()}")
                ->and($started['url'])->toBe(route('games.show', $game))
                ->and($started['match'])->toBe($game->id);
        }
    }
});

test('"Play your cup match": the opponent accepts and the match game starts with the bracket colours', function () {
    $cup = runningCup(4);
    cupTick();
    $match = openCupMatches($cup)->first();
    [$white, $black] = matchPlayers($match);
    $invites = app(ChessInvites::class);

    $invite = $invites->inviteToCupMatch($black, $match);
    $game = $invites->accept($invite, $white);

    expect($game->tournament_match_id)->toBe($match->id)
        ->and($game->white_id)->toBe($white->id)
        ->and($invite->refresh()->chess_game_id)->toBe($game->id)
        ->and($game->status)->toBe(ChessGameStatus::Active);

    // The inviter hears the game is on; the one who accepted is taken to the board and gets no second notice.
    $started = fn (User $player) => $player->notifications()->get()->pluck('data')->where('title', 'Chess Casual Cup EU #1: your game is on')->count();

    expect($started($black))->toBe(1)
        ->and($started($white))->toBe(0);
});

test('the cup page marks the cup casual and lets a player invite their opponent', function () {
    $cup = runningCup(4);
    cupTick();
    $match = openCupMatches($cup)->first();
    [$white, $black] = matchPlayers($match);

    Livewire::actingAs($white)->test('pages::tournaments.show', ['tournament' => $cup])
        ->assertSeeHtml('data-test="casual-marker"')
        // "What to do now" comes first, before the tournament's own hero: start your game.
        ->assertSeeHtmlInOrder(['data-test="now-hero" data-state="start"', 'wire:click="playCupMatch"', 'data-test="tournament-hero"'])
        ->call('playCupMatch')
        ->assertHasNoErrors()
        ->assertSeeHtml('data-test="now-hero" data-state="invited"');

    Livewire::actingAs($black)->test('pages::tournaments.show', ['tournament' => $cup])
        ->assertSeeHtml('data-test="now-hero" data-state="ready"')
        ->call('acceptCupInvite', ChessInvite::query()->sole()->id)
        ->assertRedirect(route('games.show', ChessGame::query()->sole()));
});

test('a player in a running casual 1v1 starts no cup game: invites are refused and the auto slot waits until it is over', function () {
    $cup = runningCup(4);
    cupTick();
    [$busyMatch, $freeMatch] = openCupMatches($cup)->all();
    [$busy, $opponent] = matchPlayers($busyMatch);
    $casual = app(CasualMatches::class)->create($busy, User::factory()->create(), 'rocket-league', SeriesMatch::ORIGIN_QUEUE, []);
    $invites = app(ChessInvites::class);

    expect(casualChessRefusal(fn () => $invites->inviteToCupMatch($busy, $busyMatch)))->toBe('casual_playing')
        ->and(casualChessRefusal(fn () => $invites->accept($invites->inviteToCupMatch($opponent, $busyMatch), $busy)))->toBe('casual_playing');

    $this->travelTo(CasualCups::autoSlot($busyMatch->round->window_ends_at));
    cupTick();

    // The other match starts at the slot; this one is tried again on the next run.
    expect(ChessGame::query()->where('tournament_match_id', $freeMatch->id)->count())->toBe(1)
        ->and(ChessGame::query()->where('tournament_match_id', $busyMatch->id)->count())->toBe(0);

    $casual->forceFill(['status' => SeriesStatus::Resolved, 'resolution' => SeriesResolution::Void, 'winner' => 'none', 'finished_at' => now()])->save();
    cupTick();

    expect(ChessGame::query()->where('tournament_match_id', $busyMatch->id)->sole()->status)->toBe(ChessGameStatus::Active);
});

test('at the deadline the one who tried to play advances, else a draw of lots decides', function () {
    $cup = runningCup(4);
    cupTick();
    [$first, $second] = openCupMatches($cup)->all();
    [, $keen] = matchPlayers($first);
    app(ChessInvites::class)->inviteToCupMatch($keen, $first);
    // Nobody plays by the deadline (it comes before the auto slot here).
    TournamentRound::query()->whereNotNull('window_ends_at')->update(['window_ends_at' => now()->subMinute()]);

    cupTick();

    $first->refresh();
    $second->refresh();

    expect($first->result['winner'])->toBe(1)
        ->and($first->result['decided'])->toBe('acted')
        ->and($first->result['by'])->toBe('league')
        ->and($second->result['decided'])->toBe('lot')
        ->and($second->result['label'])->toBe('advanced by draw')
        ->and(ChessGame::query()->count())->toBe(0);
});

test('a round opened near the hard cap ends at the cap, 14 days after the start', function () {
    $cup = runningCup(4);
    cupTick();
    $cup->refresh()->forceFill(['starts_at' => now()->subDays(13)])->save();

    foreach (openCupMatches($cup) as $match) {
        app(TournamentRunner::class)->store($match, ['winner' => 0, 'games_won' => [1.0, 0.0], 'points' => [], 'forfeit' => false, 'label' => '1–0', 'by' => 'players']);
    }

    app(TournamentRunner::class)->sync($cup->refresh());
    cupTick();

    $second = TournamentRound::query()->whereNotNull('window_ends_at')->orderByDesc('id')->firstOrFail();

    expect($second->number)->toBe(2)
        ->and($second->window_ends_at->equalTo(now()->addDay()))->toBeTrue();
});

/* ---------- Chess draws ------------------------------------------------------------------------------------- */

test('a drawn cup game is played again with the colours swapped, then Armageddon, where a draw advances Black', function () {
    $cup = runningCup(2);
    cupTick();
    $match = openCupMatches($cup)->sole();
    [$slotZero] = matchPlayers($match);
    $service = app(ChessGameService::class);
    $invite = app(ChessInvites::class)->inviteToCupMatch($slotZero, $match);
    app(ChessInvites::class)->accept($invite, matchPlayers($match)[1]);
    $whites = [];

    foreach (range(1, 3) as $number) {
        $game = ChessGame::query()->where('tournament_match_id', $match->id)->latest('id')->firstOrFail();
        expect($game->status)->toBe(ChessGameStatus::Active);
        $whites[] = $game->white_id;
        $service->offerDraw($game, $game->white);
        $service->acceptDraw($game->refresh(), $game->black);
    }

    $match->refresh();
    $armageddon = ChessGame::query()->where('tournament_match_id', $match->id)->latest('id')->firstOrFail();

    expect(ChessGame::query()->count())->toBe(3)
        ->and($whites[1])->not->toBe($whites[0])
        ->and($match->result['decided'])->toBe('armageddon')
        ->and($match->slots[$match->result['winner']]->participant->user_id)->toBe($armageddon->black_id)
        ->and($cup->refresh()->status)->toBe(TournamentStatus::Finished);
});
