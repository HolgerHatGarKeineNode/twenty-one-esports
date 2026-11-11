<?php

use App\Enums\BoardGameStatus;
use App\Enums\TournamentFormat;
use App\Games\GameRegistry;
use App\Models\BoardGame;
use App\Models\Tournament;
use App\Support\Series\Ladders;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use App\Support\Tournaments\Lobbies;
use App\Support\Tournaments\TournamentGameEnd;
use App\Support\Tournaments\TournamentPublisher;
use App\Support\Tournaments\TournamentRunner;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\BlockliOn;
use Tests\Support\BlockspaceOn;
use Tests\Support\TestSigner;

/*
|--------------------------------------------------------------------------
| A combined tournament: one game of Blockli and one of Blockspace per pairing
|--------------------------------------------------------------------------
|
| DerCaddy, 2026-11-11: "kombinierte Turniere … zum Beispiel, in dem man Blockli und Blockspace gegeneinander
| spielt", as "eine Paarung das eine Spiel und dann noch eine Paarung gegen den Gegner im anderen Spiel". A Blockli
| tournament names a second board game (FormatOptions::$pairGame): leg 1 is Blockli, leg 2 the second game with the
| colours swapped, a decider after 1:1 alternates, Blockli first. The second game's legs are rated on its own ladder.
| The helpers (blockliTournament() and the rest) are tests/Support/blockli.php.
|
*/

beforeEach(function () {
    Queue::fake();
    BlockliOn::play();
    BlockspaceOn::play();
});

test('the options: a Blockli tournament takes another board game of its mode, always with two legs; nothing else does', function () {
    $blockli = GameProfile::for('blockli', 'correspondence');

    expect(FormatOptions::pairGames($blockli))->toContain('blockspace')->not->toContain('blockli')
        ->and(FormatOptions::fromArray(['pairGame' => 'blockspace', 'legs' => 1], $blockli))->pairGame->toBe('blockspace')->legs->toBe(2)
        ->and(FormatOptions::fromArray([], $blockli)->pairGame)->toBeNull()
        ->and(FormatOptions::fromArray(['pairGame' => 'chess'], $blockli)->pairGame)->toBeNull()
        ->and(FormatOptions::fromArray(['pairGame' => 'blockli'], $blockli)->pairGame)->toBeNull()
        // Chess plays no legs: nothing to combine.
        ->and(FormatOptions::pairGames(GameProfile::for('chess', 'blitz')))->toBe([])
        ->and(FormatOptions::fromArray(['pairGame' => 'blockspace'], GameProfile::for('chess', 'blitz'))->pairGame)->toBeNull();

    // Blockspace switched off: no longer offered, and stored options fall back to one game.
    config(['esports.board_games.games.blockspace.enabled' => false]);
    app()->forgetInstance(GameRegistry::class);

    expect(FormatOptions::pairGames($blockli))->not->toContain('blockspace')
        ->and(FormatOptions::fromArray(['pairGame' => 'blockspace'], $blockli)->pairGame)->toBeNull();
});

test('a pairing plays Blockli, then Blockspace with the colours swapped, and after 1:1 a Blockli decider', function () {
    $tournament = blockliTournament(TournamentFormat::SingleElimination, ['pairGame' => 'blockspace']);
    $first = blockliLive($tournament);

    expect($first->game)->toBe('blockli')
        ->and(TournamentRunner::legGame($tournament, 0))->toBe('blockli')
        ->and(TournamentRunner::legGame($tournament, 1))->toBe('blockspace')
        ->and(TournamentRunner::legGame($tournament, 2))->toBe('blockli')
        ->and(TournamentRunner::legGame($tournament, 3))->toBe('blockspace');

    // Leg 1 to the higher seed.
    blockliLose($tournament, 1);
    $second = blockliLive($tournament);

    expect($second->game)->toBe('blockspace')
        ->and((int) $second->white_id)->toBe((int) $first->black_id)
        ->and(blockliMatch($tournament)->result)->toBeNull();

    // Leg 2 to the lower seed: 1:1, a decider in Blockli.
    blockliLose($tournament, 0);
    $decider = blockliLive($tournament);

    expect($decider->game)->toBe('blockli')
        ->and(blockliMatch($tournament)->result)->toBeNull();

    blockliLose($tournament, 1);
    $result = blockliMatch($tournament)->result;

    expect($result['winner'])->toBe(0)
        ->and($result['decided'])->toBe('decider')
        ->and(BoardGame::query()->where('tournament_match_id', blockliMatch($tournament)->id)->where('status', BoardGameStatus::Finished)->orderBy('id')->pluck('game')->all())
        ->toBe(['blockli', 'blockspace', 'blockli']);
});

test('a round robin with Blockspace plays both games per pairing and counts them like two legs', function () {
    $tournament = blockliTournament(TournamentFormat::RoundRobin, ['pairGame' => 'blockspace', 'legs' => 1, 'legPoints' => true]);

    expect($tournament->formatOptions()->legs)->toBe(2);

    blockliLose($tournament, 1);
    expect(blockliLive($tournament)->game)->toBe('blockspace');

    blockliLose($tournament, 1);
    [$rows, $twoLegs, $perLeg] = blockliTable($tournament);

    expect($twoLegs)->toBeTrue()->and($perLeg)->toBeTrue()
        ->and($rows['Player 1'][0])->toBe('2');
});

test('the Blockspace games are rated on the open Blockspace ladder of the season, the Blockli games on the tournament\'s', function () {
    $tournament = Tournament::factory()->create(['game' => 'blockli', 'mode' => 'correspondence',
        'options' => FormatOptions::fromArray(['pairGame' => 'blockspace'], GameProfile::for('blockli', 'correspondence'))->toArray(),
        'ladder_address' => Ladders::address('blockli', 'correspondence')]);

    expect($tournament->openLadderFor('blockli'))->toBe(Ladders::address('blockli', 'correspondence'))
        ->and($tournament->openLadderFor('blockspace'))->toBe(Ladders::address('blockspace', 'correspondence'));

    // The tournament's ladder is closed (another season): both casual.
    $tournament->forceFill(['ladder_address' => 'an-old-season'])->save();

    expect($tournament->openLadderFor('blockli'))->toBeNull()
        ->and($tournament->openLadderFor('blockspace'))->toBeNull();
});

test('the tournament names both games, and the board\'s game switch names each game\'s board game', function () {
    $tournament = blockliTournament(TournamentFormat::SingleElimination, ['pairGame' => 'blockspace']);

    expect(Lobbies::gameLine($tournament))->toStartWith('Blockli + Blockspace');

    blockliLose($tournament, 1);
    $banner = TournamentGameEnd::banner(blockliLive($tournament));

    expect(collect($banner['games'])->pluck('text')->all())->toBe([
        __('Game :n · won by :name', ['n' => '1 (Blockli)', 'name' => 'Player 1']),
        __('Game :n · under way', ['n' => '2 (Blockspace)']),
    ]);
});

test('the organizer picks the second game on the create page; it is stored and shown again on the edit page', function () {
    $organizer = organizer();

    // The chooser's game island is drawn by the browser test of the page (the action's response carries it apart).
    Livewire::actingAs($organizer)->test('pages::admin.tournament-create')
        ->set('name', 'Blockli und Blockspace')
        ->call('pickGame', 'blockli/blitz')
        ->call('pickPairGame', 'blockspace')
        ->assertSet('options.pairGame', 'blockspace')
        ->call('create')
        ->assertHasNoErrors();

    $tournament = Tournament::query()->sole();

    expect($tournament->formatOptions()->pairGame)->toBe('blockspace');

    Livewire::actingAs($organizer)->test('pages::admin.tournament-edit', ['tournament' => $tournament])
        ->assertSeeHtml('data-test="pair-game-option"')
        ->assertSet('options.pairGame', 'blockspace')
        ->call('pickPairGame', '')
        ->assertSet('options.pairGame', null);

    // Chess offers no combination: the pick does nothing.
    Livewire::actingAs($organizer)->test('pages::admin.tournament-create')
        ->call('pickGame', 'blitz')
        ->call('pickPairGame', 'blockspace')
        ->assertSet('options.pairGame', null);
});

test('the published tournament says in words that it is combined', function () {
    $league = new TestSigner;
    config(['esports.league.nsec' => $league->secret]);

    $tournament = Tournament::factory()->create(['created_by_id' => organizer()->id, 'game' => 'blockli', 'mode' => 'correspondence',
        'options' => FormatOptions::fromArray(['pairGame' => 'blockspace'], GameProfile::for('blockli', 'correspondence'))->toArray()]);

    app(TournamentPublisher::class)->publish($tournament, $tournament->creator, $tournament->starts_at->copy()->subHour());

    expect($tournament->refresh()->event->payload()['content'])
        ->toContain('A combined tournament: each pairing plays one game of Blockli and one of Blockspace, with the colours swapped');
});
