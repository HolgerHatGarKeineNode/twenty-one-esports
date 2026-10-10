<?php

use App\Models\ChessGame;
use App\Models\Clan;
use App\Models\SeriesMatch;
use App\Models\User;

/*
 * The site search (P16, SearchController): players, clans and a match
 * number, for guests too, noindex, throttled per IP.
 */

test('a guest finds players by name, NIP-05, full npub and npub prefix, linking the public player page', function () {
    $max = User::factory()->create(['name' => 'Mempool Max', 'nip05' => 'max@mempool.example']);
    User::factory()->create(['name' => 'Someone Else']);

    foreach (['mempool', 'max@mempool', $max->npub, substr($max->npub, 0, 13)] as $term) {
        $this->get(route('search', ['q' => $term]))->assertOk()
            ->assertSee('data-test="search-players"', false)
            ->assertSee('href="'.route('players.show', $max->npub).'"', false)
            ->assertSee('Mempool Max')
            ->assertDontSee('Someone Else');
    }
});

test('clans are found by name and by tag', function () {
    $clan = Clan::factory()->create(['name' => 'Laser Eyes', 'clantag' => 'LSR1']);

    foreach (['laser', 'lsr'] as $term) {
        $this->get(route('search', ['q' => $term]))->assertOk()
            ->assertSee('data-test="search-clans"', false)
            ->assertSee('href="'.route('clans.show', $clan).'"', false);
    }
});

test('an existing match number, with or without #, opens the match; a chess number its game; an unknown one says so', function () {
    $series = SeriesMatch::factory()->create();
    $game = ChessGame::factory()->create();

    $this->get(route('search', ['q' => '#'.$series->number]))->assertRedirect(route('matches.show', $series->number));
    $this->get(route('search', ['q' => (string) $series->number]))->assertRedirect(route('matches.show', $series->number));
    $this->get(route('search', ['q' => (string) $game->number]))->assertRedirect(route('matches.show', $game->number));
    $this->get(route('matches.show', $game->number))->assertRedirect(route('games.show', $game));

    $this->get(route('search', ['q' => '#987654']))->assertOk()
        ->assertSee('data-test="search-matches"', false)
        ->assertSee(__('There is no match #:number yet.', ['number' => 987654]));
});

test('each group is capped at 8 rows', function () {
    User::factory()->count(10)->sequence(fn ($sequence) => ['name' => 'Hodler '.$sequence->index])->create();

    expect(substr_count($this->get(route('search', ['q' => 'hodler']))->assertOk()->getContent(), 'data-test="search-player"'))->toBe(8);
});

test('LIKE wildcards only match themselves', function () {
    User::factory()->create(['name' => 'Plain Name']);
    Clan::factory()->create(['name' => 'Plain Clan']);

    foreach (['%%', '__', '%a%'] as $term) {
        $this->get(route('search', ['q' => $term]))->assertOk()
            ->assertSee('data-test="search-empty"', false)
            ->assertDontSee('data-test="search-player"', false)
            ->assertDontSee('data-test="search-clan"', false);
    }
});

test('the empty query shows what can be searched, and the page is noindex', function () {
    $this->get(route('search'))->assertOk()
        ->assertSee('data-test="search-start"', false)
        ->assertSee('<meta name="robots" content="noindex, nofollow">', false);
});

test('the header search field submits to the search, and keeps the term on the results page', function () {
    // From lg a field in row 1 (Header.dc.html), on phones the field in the search row the search button opens.
    $html = $this->get(route('rules'))->assertOk()
        ->assertSee('action="'.route('search').'"', false)
        ->assertSee('id="site-search-inline" name="q"', false)
        ->assertSee('id="site-search" name="q"', false)
        ->assertSee('aria-controls="mobile-search"', false)
        ->getContent();
    expect(substr_count($html, 'name="q"'))->toBe(2);

    $this->get(route('search', ['q' => 'satoshi']))->assertOk()->assertSee('value="satoshi"', false);
});

test('the search is throttled per IP: the 31st request in a minute gets 429', function () {
    foreach (range(1, 30) as $request) {
        $this->get(route('search', ['q' => 'x'.$request]))->assertOk();
    }

    $this->get(route('search', ['q' => 'one more']))->assertStatus(429);
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])->get(route('search', ['q' => 'other ip']))->assertOk();
});
