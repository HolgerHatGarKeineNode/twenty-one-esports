<?php

/*
| Nine men's morris and checkers in the /matches table (plan
| "Mempool-Streifen", P2): their rows next to series and chess, a filter chip
| per board game switched on, the live/done filters and counts. Switched
| off, a board game has no chip, no row and no link to its board route.
*/

use App\Enums\BoardGameStatus;
use App\Games\Checkers;
use App\Games\GameRegistry;
use App\Games\NineMensMorris;
use App\Models\ChessGame;
use App\Models\SeriesMatch;
use Livewire\Livewire;
use Tests\Support\CheckersGame;
use Tests\Support\NineMensMorrisOn;

test('the table lists the board games next to series and chess, filtered by game and status, with their counts', function () {
    NineMensMorrisOn::play();
    CheckersGame::play();
    $series = SeriesMatch::factory()->accepted()->create();
    $chess = ChessGame::factory()->finished('1-0')->create();
    $rated = mempoolBoard(NineMensMorris::SLUG, ['status' => BoardGameStatus::Finished, 'result' => '1-0', 'ended_at' => now(), 'rated' => true]);
    $casual = mempoolBoard(Checkers::SLUG, ['ply' => 6]);
    $row = fn (string $key) => 'wire:key="'.$key.'"';

    $page = Livewire::test('pages::matches.index')
        ->assertSeeHtml($row('m-'.$series->id))->assertSeeHtml($row('c-'.$chess->id))
        ->assertSeeHtml($row('b-'.$rated->id))->assertSeeHtml($row('b-'.$casual->id))
        ->assertSeeHtml('data-test="game-'.NineMensMorris::SLUG.'"')->assertSeeHtml('data-test="game-'.Checkers::SLUG.'"')
        // A rated board game carries its match number, a casual one says so.
        ->assertSeeHtml('<span class="font-bold text-btc">#'.$rated->number.'</span>')
        ->assertSeeHtml('href="'.route('board.show', $casual).'"');

    expect($rated->number)->toBeInt()->and($casual->number)->toBeNull()
        ->and($page->get('counts'))->toBe(['waiting' => 0, 'scheduled' => 0, 'live' => 2, 'to_confirm' => 0, 'disputed' => 0, 'done' => 2]);

    $page->call('pickGame', NineMensMorris::SLUG)->assertSet('game', NineMensMorris::SLUG)
        ->assertSeeHtml($row('b-'.$rated->id))->assertDontSeeHtml($row('b-'.$casual->id))
        ->assertDontSeeHtml('data-test="chess-row"')->assertDontSeeHtml('data-test="match-row"');
    expect($page->get('counts'))->toBe(['waiting' => 0, 'scheduled' => 0, 'live' => 0, 'to_confirm' => 0, 'disputed' => 0, 'done' => 1]);

    $page->call('pickGame', Checkers::SLUG)->call('pickStatus', 'live')->assertSeeHtml($row('b-'.$casual->id))
        ->call('pickStatus', 'done')->assertDontSeeHtml('data-test="board-row"')
        ->assertSee('No games yet')->assertSeeHtml('href="'.route('board.lobby', Checkers::SLUG).'"');

    // Chess keeps its own rows only: no board game under it.
    $page->call('pickStatus', 'all')->call('pickGame', 'chess')->assertSeeHtml($row('c-'.$chess->id))->assertDontSeeHtml('data-test="board-row"');

    Livewire::withQueryParams(['game' => Checkers::SLUG])->test('pages::matches.index')->assertSet('game', Checkers::SLUG);
});

test('switched off, a board game has no chip, no row and no filter; all off, none has', function () {
    NineMensMorrisOn::play();
    CheckersGame::play();
    $morris = mempoolBoard(NineMensMorris::SLUG, ['status' => BoardGameStatus::Finished, 'result' => '1-0', 'ended_at' => now()]);
    $checkers = mempoolBoard(Checkers::SLUG);

    config(['esports.board_games.games.'.NineMensMorris::SLUG.'.enabled' => false]);
    app()->forgetInstance(GameRegistry::class);

    Livewire::withQueryParams(['game' => NineMensMorris::SLUG])->test('pages::matches.index')
        ->assertSet('game', 'all')
        ->assertDontSeeHtml('data-test="game-'.NineMensMorris::SLUG.'"')->assertDontSeeHtml('wire:key="b-'.$morris->id.'"')
        ->assertSeeHtml('data-test="game-'.Checkers::SLUG.'"')->assertSeeHtml('wire:key="b-'.$checkers->id.'"')
        ->call('pickGame', NineMensMorris::SLUG)->assertSet('game', 'all');

    config(['esports.board_games.enabled' => false]);
    app()->forgetInstance(GameRegistry::class);

    $html = $this->get(route('matches.index', ['game' => Checkers::SLUG]))->assertOk()->getContent();

    expect($html)->not->toContain('data-test="board-row"')
        ->not->toContain('data-test="game-'.Checkers::SLUG.'"')
        ->not->toContain('/board/')
        ->not->toContain('value="'.Checkers::SLUG.'"');
});
