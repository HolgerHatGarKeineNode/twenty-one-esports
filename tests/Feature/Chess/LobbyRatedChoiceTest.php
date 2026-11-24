<?php

/*
 * The lobby's Casual/Rated choice (P7e): Rated is selectable only while
 * rated chess is open for the player (season live, ESPORTS_RATED_CHESS on,
 * trust ranks computed, a Trusted account) and they list each other with at
 * least one player; each closed state says why, and "Find opponent" refuses
 * a rated search the page would not offer. The open path runs on the real
 * trust facts: archived ranks and opponent lists, pinned with the game.
 */

use App\Models\ChessGame;
use App\Models\ChessQueueEntry;
use App\Models\NostrEvent;
use App\Models\TrustRank;
use App\Models\TrustRun;
use App\Models\User;
use App\Support\Chess\ChessQueue;
use App\Support\Chess\RatedChess;
use App\Support\Nostr\SignedEvent;
use App\Support\SeasonChain\AnchoredTrustFacts;
use App\Support\SeasonChain\NoTrustFacts;
use App\Support\SeasonChain\OpponentLists;
use App\Support\SeasonChain\Opponents;
use App\Support\SeasonChain\RatedTrustGate;
use App\Support\SeasonChain\Seasons;
use App\Support\SeasonChain\TrustFacts;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\TestSigner;

beforeEach(function () {
    Queue::fake();
    $this->freezeSecond();
    config(['esports.chess.rated_queue' => true]);
    app()->bind(TrustFacts::class, AnchoredTrustFacts::class);
});

/** @return array{0: User, 1: TestSigner} */
function lobbyPlayer(string $name): array
{
    $signer = new TestSigner;

    return [User::factory()->withPubkey($signer->pubkey)->create(['name' => $name]), $signer];
}

/** Add $opponent to $player's list, signed as the browser would. */
function listOpponent(User $player, TestSigner $signer, User $opponent): void
{
    $opponents = app(Opponents::class);
    $opponents->add($player, $opponent, $signer->signTemplates($opponents->prepareAdd($player, $opponent)));
    test()->travel(1)->seconds();
}

/**
 * A trust run of the live season with a published assertion per player
 * (rank 100 unless given), as the trust job leaves them.
 *
 * @param  array<string, int>  $ranks  pubkey => rank
 */
function trustRunWith(array $ranks): void
{
    $trust = new TestSigner;
    $anchors = NostrEvent::fromSigned(SignedEvent::fromInput($trust->sign(30000, [['d', 'esports/x/anchors'], ['alt', 'anchors']])));
    $run = TrustRun::query()->create(['season_id' => Seasons::live()?->id, 'trust_pubkey' => $trust->pubkey, 'anchor_list_nostr_event_id' => $anchors->id,
        'anchors' => 0, 'lists' => 0, 'ranked' => count($ranks), 'published' => count($ranks), 'computed_at' => now()]);

    foreach ($ranks as $pubkey => $rank) {
        $assertion = NostrEvent::fromSigned(SignedEvent::fromInput($trust->sign(30382, [['d', $pubkey], ['p', $pubkey], ['rank', (string) $rank], ['alt', 'rank']])));
        TrustRank::query()->create(['pubkey' => $pubkey, 'rank' => $rank, 'raw' => 1.0, 'anchor_list_event_id' => $anchors->event_id,
            'trust_run_id' => $run->id, 'nostr_event_id' => $assertion->id, 'event_id' => $assertion->event_id]);
    }
}

/** The lobby for this player: whether Rated is offered, and the reason shown. */
function ratedChoice(User $user): array
{
    $html = Livewire::actingAs($user)->test('pages::chess.lobby')->html();
    preg_match('/data-rated-open="(true|false)"/', $html, $open);
    preg_match('/data-test="kind-why">(.*?)<\/p>/s', $html, $why);

    return [$open[1] ?? null, trim(preg_replace('/\s+/', ' ', strip_tags($why[1] ?? '')))];
}

/** "Find opponent" with Rated chosen: the error it shows, and whether it queued. */
function searchRated(User $user): array
{
    $error = Livewire::actingAs($user)->test('pages::chess.lobby')->call('findOpponent', true)->get('error');

    return [$error, ChessQueueEntry::query()->where('user_id', $user->id)->exists()];
}

test('before Block 0 Rated is closed and the lobby says when it opens; a rated search is refused', function () {
    [$anna] = lobbyPlayer('anna');

    [$open, $why] = ratedChoice($anna);

    expect($open)->toBe('false')
        ->and($why)->toContain('Casual until Block 0')
        ->and(searchRated($anna))->toBe([$why, false]);
});

test('with the season live, Rated stays closed while rated chess is off, before trust ranks, for an untrusted player and without a mutual listing, each with its reason', function () {
    openSeason();
    [$anna, $annaSigner] = lobbyPlayer('anna');
    [$bert, $bertSigner] = lobbyPlayer('bert');

    // ESPORTS_RATED_CHESS off.
    config(['esports.chess.rated_queue' => false]);
    expect(ratedChoice($anna))->toBe(['false', 'Rated chess is not open yet. Rapid and blitz games are casual for now.'])
        ->and(searchRated($anna)[1])->toBeFalse();
    config(['esports.chess.rated_queue' => true]);

    // No trust run this season.
    app()->instance(TrustFacts::class, new NoTrustFacts);
    expect(ratedChoice($anna))->toBe(['false', 'Rated play opens once trust ranks are computed. Until then every match is casual.'])
        ->and(searchRated($anna)[1])->toBeFalse();
    app()->bind(TrustFacts::class, AnchoredTrustFacts::class);

    // Ranks computed, anna below the minimum.
    trustRunWith([$anna->pubkey => 20, $bert->pubkey => 100]);
    expect(ratedChoice($anna)[0])->toBe('false')
        ->and(ratedChoice($anna)[1])->toContain('Rated chess needs a Trusted account')
        ->and(searchRated($anna)[1])->toBeFalse();

    // bert is Trusted but lists nobody back yet.
    listOpponent($anna, $annaSigner, $bert);
    expect(ratedChoice($bert))->toBe(['false', 'Rated play needs a player you list each other with. Add opponents on their player pages; they add you back.'])
        ->and(searchRated($bert))->toBe(['Rated play needs a player you list each other with. Add opponents on their player pages; they add you back.', false]);

    // A casual search is untouched by all of it.
    Livewire::actingAs($bert)->test('pages::chess.lobby')->call('findOpponent')->assertSet('error', '');
    expect(ChessQueueEntry::query()->where('user_id', $bert->id)->value('rated'))->toBeFalse();
});

test('open: two Trusted players who list each other search Rated and get a rated game with the gate pinned from their lists and ranks', function () {
    openSeason();
    [$anna, $annaSigner] = lobbyPlayer('anna');
    [$bert, $bertSigner] = lobbyPlayer('bert');
    trustRunWith([$anna->pubkey => 100, $bert->pubkey => 100]);
    listOpponent($anna, $annaSigner, $bert);
    listOpponent($bert, $bertSigner, $anna);

    expect(ratedChoice($anna))->toBe(['true', 'Casual pairs you with anyone online and moves only your casual Elo. Rated pairs you only with a Trusted player you list each other with (you have 1). Your opponents']);

    Livewire::actingAs($anna)->test('pages::chess.lobby')->call('findOpponent', true, 'blitz')->assertSet('error', '')->assertSee('Blitz · rated');
    expect(ChessQueueEntry::query()->where('user_id', $anna->id)->value('rated'))->toBeTrue();

    $page = Livewire::actingAs($bert)->test('pages::chess.lobby')->call('findOpponent', true, 'blitz')->assertSet('error', '');
    $game = ChessGame::query()->sole();
    $lists = app(Opponents::class);

    $page->assertRedirect(route('games.show', $game));
    expect($game->rated)->toBeTrue()
        ->and(array_keys($game->gate_at_accept['players']))->toEqualCanonicalizing([$anna->pubkey, $bert->pubkey])
        ->and($game->gate_at_accept['players'][$anna->pubkey]['rank'])->toBe(100)
        ->and($game->gate_at_accept['players'][$anna->pubkey]['assertion'])->toBe(TrustRank::query()->where('pubkey', $anna->pubkey)->value('event_id'))
        ->and($game->gate_at_accept['players'][$anna->pubkey]['list'])->toBe(OpponentLists::forLeague()->newest($anna->pubkey)->event_id)
        ->and($lists->listEachOther($anna, $bert))->toBeTrue();
});

test('"Find next opponent" after a rated game searches rated again while rated is open, and casual with the reason once it is not', function () {
    openSeason();
    [$anna, $annaSigner] = lobbyPlayer('anna');
    [$bert, $bertSigner] = lobbyPlayer('bert');
    trustRunWith([$anna->pubkey => 100, $bert->pubkey => 100]);
    listOpponent($anna, $annaSigner, $bert);
    listOpponent($bert, $bertSigner, $anna);

    // The game page's end-of-game links carry the choice: rated only for a rated game.
    $rated = ChessGame::factory()->rated()->create(['white_id' => $anna->id, 'black_id' => $bert->id]);
    $casual = ChessGame::factory()->create(['white_id' => $anna->id, 'black_id' => $bert->id]);
    // And the mode (plan "Schach Rapid und Clan", P2): after a blitz game the next search is blitz, after rapid rapid.
    $this->actingAs($anna)->get(route('games.show', $rated))->assertOk()->assertSee(route('chess.lobby', ['search' => 1, 'rated' => 1, 'mode' => 'blitz']));
    $this->actingAs($anna)->get(route('games.show', $casual))->assertOk()->assertSee(e(route('chess.lobby', ['search' => 1, 'mode' => 'blitz'])).'"', false)->assertDontSee(route('chess.lobby', ['search' => 1, 'rated' => 1]));
    ChessGame::query()->delete();

    // Rated still open: the search is rated.
    Livewire::withQueryParams(['search' => 1, 'rated' => 1, 'mode' => 'blitz'])->actingAs($anna)->test('pages::chess.lobby')->assertSet('notice', '')->assertSee('Blitz · rated');
    expect(ChessQueueEntry::query()->where('user_id', $anna->id)->value('rated'))->toBeTrue();
    app(ChessQueue::class)->leave($anna);

    // bert took anna off his list: rated is closed for her, the search is casual and says why.
    app(Opponents::class)->remove($bert, $anna->pubkey, $bertSigner->signTemplates(app(Opponents::class)->prepareRemove($bert, $anna->pubkey)));
    Livewire::withQueryParams(['search' => 1, 'rated' => 1, 'mode' => 'blitz'])->actingAs($anna)->test('pages::chess.lobby')
        ->assertSet('error', '')
        ->assertSet('notice', 'Rated is closed for you right now, so this search is casual. Rated play needs a player you list each other with. Add opponents on their player pages; they add you back.')
        ->assertSee('Blitz · casual');
    expect(ChessQueueEntry::query()->where('user_id', $anna->id)->value('rated'))->toBeFalse();
});

/**
 * The quick-play panel as rendered for this viewer (null: a guest): which kind it opens on, the chip under the
 * choice, the word on the Rated option with its reason class, and the line under the choice with its link.
 *
 * @return array{rated: string|null, checked: string|null, chip: string|null, chipText: string, kind: string|null, badge: string, line: string, href: string|null}
 */
function ratedPanel(?User $user): array
{
    if ($user === null) {
        // An earlier actingAs() in the same test stays on the guard.
        auth()->forgetUser();
    }

    $html = ($user === null ? Livewire::test('pages::chess.lobby') : Livewire::actingAs($user)->test('pages::chess.lobby'))->html();
    $text = fn (string $pattern): string => preg_match($pattern, $html, $match) === 1 ? trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($match[1])))) : '';
    $attribute = fn (string $pattern): ?string => preg_match($pattern, $html, $match) === 1 ? html_entity_decode($match[1]) : null;

    return [
        'rated' => $attribute('/rated: (true|false), chose: null/'),
        'checked' => $attribute('/<button[^>]*aria-checked="(true|false)"[^>]*data-test="kind-rated"/'),
        'chip' => $attribute('/data-test="kind-chip"[^>]*data-kind="([a-z]*)"/'),
        'chipText' => $text('/data-test="kind-chip"[^>]*>(.*?)<\/span>\s*(?:<p|<\/div>)/s'),
        'kind' => $attribute('/data-kind="([a-z]*)" data-test="kind-rated-badge"/'),
        'badge' => $text('/data-test="kind-rated-badge">(.*?)<\/span>/s'),
        'line' => $text('/data-test="kind-reason"[^>]*>\s*<span[^>]*>(.*?)<\/span>/s'),
        'href' => $attribute('/<a[^>]*\shref="([^"]*)"[^>]*data-test="kind-reason-link"/'),
    ];
}

test('the quick-play panel opens on Rated where rated is open for the player, with what a win mines now; closed, it opens on Casual', function () {
    openSeason();
    [$anna, $annaSigner] = lobbyPlayer('anna');
    [$bert, $bertSigner] = lobbyPlayer('bert');
    [$carl] = lobbyPlayer('carl');
    trustRunWith([$anna->pubkey => 100, $bert->pubkey => 100, $carl->pubkey => 100]);
    listOpponent($anna, $annaSigner, $bert);
    listOpponent($bert, $bertSigner, $anna);

    $reward = RatedChess::rewardsNow()['rapid'];
    $open = ratedPanel($anna);

    // Mutation: `rated: false` in the panel's x-data (the state before 2026-11-18) turns `rated` and `checked` red.
    expect($reward)->toBeGreaterThan(0)
        ->and($open['rated'])->toBe('true')
        ->and($open['checked'])->toBe('true')
        ->and($open['chip'])->toBe('rated')
        ->and($open['chipText'])->toContain('A win mines '.number_format($reward).' sats')->toContain('paid out after the season review')->toContain('Casual mines no block')
        ->and($open['kind'])->toBe('')
        ->and($open['line'])->toBe('');

    // carl is Trusted but lists nobody back: closed, so the panel opens on Casual.
    $closed = ratedPanel($carl);

    expect($closed['rated'])->toBe('false')
        ->and($closed['checked'])->toBe('false')
        ->and($closed['chip'])->toBe('casual');
});

test('closed, the word on Rated names the kind of reason and the line under the choice says what to do, with a link where a page helps', function () {
    // Before Block 0: the season is the reason, and no chip promises anything.
    $before = ratedPanel(lobbyPlayer('early')[0]);

    expect([$before['kind'], $before['badge'], $before['href'], $before['chip']])->toBe(['season', 'from Block 0', null, null])
        ->and($before['line'])->toContain('Casual until Block 0');

    openSeason();
    [$anna, $annaSigner] = lobbyPlayer('anna');
    [$bert] = lobbyPlayer('bert');

    // A guest: log in. Mutation: one badge for every kind ("not open yet", the state before 2026-11-18) turns each `badge` below red.
    expect(ratedPanel(null))->toMatchArray(['kind' => 'login', 'badge' => 'log in first', 'line' => 'Log in to play rated games.', 'href' => route('login'), 'chip' => 'casual']);

    // Rated chess switched off: the one case that really is "not open yet", and no page helps.
    config(['esports.chess.rated_queue' => false]);
    expect(ratedPanel($anna))->toMatchArray(['kind' => 'closed', 'badge' => 'not open yet', 'line' => 'Rated chess is not open yet. Rapid and blitz games are casual for now.', 'href' => null]);
    config(['esports.chess.rated_queue' => true]);

    // Below the trust minimum: the line names the minimum and how it rises, never a figure about the player.
    trustRunWith([$anna->pubkey => 20, $bert->pubkey => 100]);
    expect(ratedPanel($anna))->toMatchArray(['kind' => 'trust', 'badge' => 'Trusted needed', 'href' => route('settings.opponents'),
        'line' => 'Rated from trust rank '.RatedTrustGate::minimum().'. It rises when members add you as an opponent.']);

    // Trusted, but nobody listed back: the opponent is missing.
    listOpponent($anna, $annaSigner, $bert);
    expect(ratedPanel($bert))->toMatchArray(['kind' => 'opponent', 'badge' => 'opponent missing', 'line' => 'Rated needs an opponent who adds you back.', 'href' => route('settings.opponents')]);
});
