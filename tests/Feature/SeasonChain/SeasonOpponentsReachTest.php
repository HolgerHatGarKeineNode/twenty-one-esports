<?php

/*
|--------------------------------------------------------------------------
| The opponent list where people play, and how to play Season 1
| (plan "Season vs. Casual", P5 and P6)
|--------------------------------------------------------------------------
|
| After a finished game each human player sees the other with the existing
| add button: "Add :name as opponent", "Accept :name's request" when the
| other listed them already, "Mutual opponents, rated next time" once both
| did; never for a spectator, a guest or a bot seat. The lobbies and /me
| show the mutual opponents with who is online and who can play Season 1
| now; a "not mutual" line leads to the opponents page, which says the rule
| and how many list the player back. The explainer's three steps stand on
| every page that talks about the season, ticked for a player, with "Log in"
| for a guest.
*/

use App\Enums\ChessGameStatus;
use App\Enums\SeriesStatus;
use App\Games\Blockli;
use App\Models\ChessGame;
use App\Models\User;
use App\Support\Board\BoardChallenges;
use App\Support\Notifications\OnSite;
use App\Support\SeasonChain\Opponents;
use App\Support\SeasonChain\TrustFacts;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\BlockliOn;
use Tests\Support\CheckersGame;
use Tests\Support\HyperOn;
use Tests\Support\TestSigner;

beforeEach(function () {
    Queue::fake();
    $this->freezeSecond();
    // The profile standard is P4's (SeasonPathTest); here only the list and the trust rank decide.
    config(['esports.fair_play.profile_standard.enabled' => false, 'esports.chess.rated_queue' => true, 'esports.board_games.rated_queue' => true]);
    opponentsReachRanks(80);
    $this->league = new TestSigner;
    config(['esports.league.nsec' => $this->league->secret]);
    [$this->anna, $this->annaSigner] = opponentsReachPlayer('anna');
    [$this->bert, $this->bertSigner] = opponentsReachPlayer('bert');
});

/** @return array{0: User, 1: TestSigner} */
function opponentsReachPlayer(string $name): array
{
    $signer = new TestSigner;

    return [User::factory()->withPubkey($signer->pubkey)->create(['name' => $name]), $signer];
}

/** $player puts $opponent on their list, signed as the browser would. */
function opponentsReachList(User $player, TestSigner $signer, User $opponent): void
{
    $opponents = app(Opponents::class);
    $opponents->add($player, $opponent, $signer->signTemplates($opponents->prepareAdd($player, $opponent)));
    test()->travel(1)->seconds();
}

/** Trust ranks computed, every player at `$rank`; `$connected` false: the pair does not list each other (the gate's condition 1). */
function opponentsReachRanks(int $rank, bool $connected = true): void
{
    app()->bind(TrustFacts::class, fn () => new class($rank, $connected) implements TrustFacts
    {
        public function __construct(private int $rank, private bool $connected) {}

        public function available(): bool
        {
            return true;
        }

        public function at(array $players, array $gatekeepers): array
        {
            return ['trust' => array_fill_keys($players, $this->rank), 'anchors' => [], 'connected' => $this->connected];
        }
    });
}

/** The after-game prompt of a page: its opponent rows' states, empty without the prompt. */
function opponentsReachPromptStates(string $html): array
{
    $prompt = str($html)->after('data-test="after-game-opponents"');

    if (! str_contains($html, 'data-test="after-game-opponents"')) {
        return [];
    }

    preg_match_all('/data-test="opponent" data-state="([a-z-]+)"/', $prompt->toString(), $states);

    return $states[1];
}

function opponentsReachFinishedChess(User $white, User $black): ChessGame
{
    return ChessGame::factory()->finished()->create(['white_id' => $white->id, 'black_id' => $black->id, 'ply' => 12]);
}

/* ---------- P5: after a finished game ------------------------------------------------------------------ */

test('the chess end screen shows each player the other with add, accept or mutual, and nothing to a spectator or a guest', function () {
    $game = opponentsReachFinishedChess($this->anna, $this->bert);
    $page = fn (?User $viewer): string => (string) ($viewer ? $this->actingAs($viewer) : $this)->get(route('games.show', $game))->assertOk()->getContent();

    // Neither lists the other: both get "Add".
    expect(opponentsReachPromptStates($page($this->anna)))->toBe(['none'])
        ->and($page($this->anna))->toContain(__('Add :name as opponent', ['name' => 'bert']))
        ->and(opponentsReachPromptStates($page($this->bert)))->toBe(['none']);

    // Bert lists Anna: Anna accepts a request, Bert waits for her.
    opponentsReachList($this->bert, $this->bertSigner, $this->anna);
    expect(opponentsReachPromptStates($page($this->anna)))->toBe(['lists-you'])
        ->and($page($this->anna))->toContain(e(__('Accept :name\'s request', ['name' => 'bert'])))
        ->and(opponentsReachPromptStates($page($this->bert)))->toBe(['listed']);

    // Both: rated next time, said on both sides.
    opponentsReachList($this->anna, $this->annaSigner, $this->bert);
    expect(opponentsReachPromptStates($page($this->anna)))->toBe(['mutual'])
        ->and($page($this->anna))->toContain(__('Mutual opponents, rated next time'))
        ->and(opponentsReachPromptStates($page($this->bert)))->toBe(['mutual']);

    [$carl] = opponentsReachPlayer('carl');
    expect(opponentsReachPromptStates($page($carl)))->toBe([])
        ->and(opponentsReachPromptStates($page(null)))->toBe([]);
});

test('an aborted game and a league without opponent lists show no prompt', function () {
    $aborted = ChessGame::factory()->create(['white_id' => $this->anna->id, 'black_id' => $this->bert->id, 'status' => ChessGameStatus::Aborted, 'ended_at' => now()]);
    expect((string) $this->actingAs($this->anna)->get(route('games.show', $aborted))->getContent())->not->toContain('data-test="after-game-opponents"');

    $game = opponentsReachFinishedChess($this->anna, $this->bert);
    config(['esports.league.nsec' => null]);
    expect((string) $this->actingAs($this->anna)->get(route('games.show', $game))->getContent())->not->toContain('data-test="after-game-opponents"');
});

test('add and accept from the end screen: the button there writes the signed list, keeping the profile-standard confirm', function () {
    $button = fn (User $me, User $player) => Livewire::actingAs($me)->test('opponent-button', ['player' => $player, 'inline' => true, 'afterGame' => true]);

    // Add: Anna's list now names Bert.
    $page = $button($this->anna, $this->bert);
    $page->call('add', json_encode($this->annaSigner->signTemplates($page->instance()->prepareAdd(app(Opponents::class)))))->assertHasNoErrors();
    $this->travel(1)->seconds();
    expect(app(Opponents::class)->relation($this->anna, $this->bert))->toBe('listed');

    // Accept: Bert adds Anna back from his end screen, the two are mutual and the chip says what it means here.
    $page = $button($this->bert, $this->anna);
    expect($page->html())->toContain('data-state="lists-you"');
    $page->call('add', json_encode($this->bertSigner->signTemplates($page->instance()->prepareAdd(app(Opponents::class)))))->assertHasNoErrors();
    expect(app(Opponents::class)->relation($this->bert, $this->anna))->toBe('mutual')
        ->and($button($this->bert, $this->anna)->html())->toContain(__('Mutual opponents, rated next time'));

    // D17: below the profile standard the plain add still refuses and asks for the confirm.
    config(['esports.fair_play.profile_standard.enabled' => true, 'esports.fair_play.profile_standard.since' => null]);
    [$dora] = opponentsReachPlayer('dora');
    $this->travel(1)->seconds();
    $button($this->anna, $dora)->call('prepareAdd')->assertHasErrors('opponent');
});

test('a board game shows its players the prompt once it is over; a spectator gets none', function () {
    CheckersGame::play();
    $challenges = app(BoardChallenges::class);
    $game = $challenges->accept($challenges->challenge($this->anna, $this->bert, 'checkers', 'white'), $this->bert);
    $active = (string) $this->actingAs($this->anna)->get(route('board.show', $game))->assertOk()->getContent();
    $game->forceFill(['status' => 'finished', 'result' => '1-0', 'ended_at' => now()])->save();
    opponentsReachList($this->bert, $this->bertSigner, $this->anna);

    // While the game runs the prompt waits hidden for its end (the page's state shows it then, without a reload).
    expect($active)->toMatch('/x-show="state.status === \'finished\'"\s+x-cloak\s*>[\s\S]{0,120}<section[^>]+data-test="after-game-opponents"/')
        ->and(opponentsReachPromptStates((string) $this->actingAs($this->anna)->get(route('board.show', $game))->assertOk()->getContent()))->toBe(['lists-you'])
        ->and(opponentsReachPromptStates((string) $this->actingAs($this->bert)->get(route('board.show', $game))->getContent()))->toBe(['listed']);

    [$carl] = opponentsReachPlayer('carl');
    expect(opponentsReachPromptStates((string) $this->actingAs($carl)->get(route('board.show', $game))->getContent()))->toBe([]);
});

test('the series room shows each player the other side once the series has a result, and a spectator nothing', function () {
    [$match, $host, $guest] = casualStarted('rocket-league');
    $states = fn (User $viewer): array => opponentsReachPromptStates(Livewire::actingAs($viewer)->test('pages::matches.room', ['match' => $match->refresh()])->html());

    expect($states($host))->toBe([]);

    $match->forceFill(['status' => SeriesStatus::Confirmed, 'winner' => 'challenger', 'finished_at' => now()])->save();
    [$carl] = opponentsReachPlayer('carl');

    expect($states($host))->toBe(['none'])
        ->and($states($guest))->toBe(['none'])
        ->and($states($carl))->toBe([]);
});

test('the Hyperbitcoinization end lists the other human players, never a bot seat, and nothing for a spectator', function () {
    $this->withoutVite();
    HyperOn::play();
    $match = HyperOn::versus($this->anna, $this->bert, bots: 1);
    opponentsReachList($this->bert, $this->bertSigner, $this->anna);

    $html = (string) $this->actingAs($this->anna)->get(route('hyper.match', $match))->assertOk()->getContent();
    preg_match_all('/data-test="after-game-link" data-state="([a-z-]+)"/', $html, $links);

    expect($links[1])->toBe(['lists-you'])
        ->and($html)->toContain(route('settings.opponents').'#requests');

    [$carl] = opponentsReachPlayer('carl');
    expect((string) $this->actingAs($carl)->get(route('hyper.match', $match))->getContent())->not->toContain('data-test="hyper-end-opponents"');
});

/* ---------- P5: the mutual opponents in the lobbies and on /me --------------------------------------------- */

test('the chess lobby, a board lobby and /me show the mutual opponents, who is online and who can play Season 1 now', function () {
    CheckersGame::play();
    openSeason();
    [$carl, $carlSigner] = opponentsReachPlayer('carl');
    // Anna and Bert list each other; Carl only lists Anna (a request, not mutual).
    opponentsReachList($this->anna, $this->annaSigner, $this->bert);
    opponentsReachList($this->bert, $this->bertSigner, $this->anna);
    opponentsReachList($carl, $carlSigner, $this->anna);
    app(OnSite::class)->seen($this->bert);

    foreach ([route('chess.lobby'), route('board.lobby', 'checkers'), route('dashboard')] as $url) {
        $html = (string) $this->actingAs($this->anna)->get($url)->assertOk()->getContent();
        preg_match_all('/data-test="mutual-opponent" data-online="(\w+)" data-ready="(\w+)"/', $html, $rows);

        expect($rows[0])->toHaveCount(1, $url)
            ->and([$rows[1][0], $rows[2][0]])->toBe(['true', 'true'])
            ->and(str($html)->after('data-test="mutual-opponents"')->before('</section>')->toString())->toContain('bert')->not->toContain('carl');
    }

    // A board game has a direct rated challenge: its correspondence form with Bert picked.
    expect((string) $this->actingAs($this->anna)->get(route('board.lobby', 'checkers'))->getContent())
        ->toContain('href="'.e(route('board.correspondence', ['checkers', 'to' => $this->bert->npub])).'"');

    // Below the trust minimum the pair is listed but casual only; without a mutual opponent there is no block.
    opponentsReachRanks(10);
    expect((string) $this->actingAs($this->anna)->get(route('chess.lobby'))->getContent())->toContain('data-ready="false"')
        ->and((string) $this->actingAs($carl)->get(route('chess.lobby'))->getContent())->not->toContain('data-test="mutual-opponents"');
});

/* ---------- P5: refusals lead to the fix, the opponents page says the rule ----------------------------------- */

test('the lobbies\' rated line and the correspondence form\'s "not mutual" line link to the opponents page', function () {
    CheckersGame::play();
    BlockliOn::play();
    openSeason();
    $link = 'href="'.route('settings.opponents').'"';
    // The rated line explains the queue once rated is open for the player (a refusal has its own link, kind-reason).
    opponentsReachList($this->anna, $this->annaSigner, $this->bert);
    opponentsReachList($this->bert, $this->bertSigner, $this->anna);
    [$carl] = opponentsReachPlayer('carl');

    expect((string) $this->actingAs($this->anna)->get(route('chess.lobby'))->getContent())->toContain('data-test="kind-why-opponents"')
        ->and((string) $this->actingAs($this->anna)->get(route('board.lobby', Blockli::SLUG))->getContent())->toContain('data-test="kind-why-opponents"');

    opponentsReachRanks(80, connected: false);
    $form = (string) $this->actingAs($this->anna)->get(route('board.correspondence', ['checkers', 'to' => $carl->npub]))->assertOk()->getContent();
    expect(str($form)->after('data-test="rated-why"')->before('</span>')->toString())->toContain('data-test="rated-why-opponents"')
        ->and($form)->toContain($link);
});

test('the opponents page says Season 1 needs mutual listing and how many of the player\'s opponents list them back', function () {
    [$carl] = opponentsReachPlayer('carl');
    opponentsReachList($this->anna, $this->annaSigner, $this->bert);
    opponentsReachList($this->anna, $this->annaSigner, $carl);
    opponentsReachList($this->bert, $this->bertSigner, $this->anna);

    $line = str((string) $this->actingAs($this->anna)->get(route('settings.opponents'))->assertOk()->getContent())->after('data-test="opponent-season-line"')->before('</p>')->toString();

    expect($line)->toContain(__(':season rated needs you both to list each other.', ['season' => 'Season 1']))
        ->and($line)->toContain(trans_choice(':mutual of your :count opponent lists you back.|:mutual of your :count opponents list you back.', 2, ['mutual' => 1, 'count' => 2]));
});

/* ---------- P6: "How to play Season 1" --------------------------------------------------------------------- */

test('the explainer stands on home, /play, /matches, /mining, a game page and the rules, with "Log in" for a guest', function (string $route) {
    openSeason();
    $url = route($route);

    $guest = (string) $this->get($url)->assertOk()->getContent();
    expect($guest)->toContain('data-test="season-how" data-viewer="guest"')
        ->and(substr_count($guest, 'data-test="season-how-step"'))->toBe(3)
        ->and($guest)->toContain('data-test="season-how-login"');

    $player = (string) $this->actingAs($this->anna)->get($url)->assertOk()->getContent();
    expect($player)->toContain('data-test="season-how" data-viewer="player"')
        ->and($player)->not->toContain('data-test="season-how-login"');
})->with(['home', 'play', 'matches.index', 'mining', 'games.rocket-league', 'rules']);

test('a player sees their own state: Trusted and mutual steps ticked as the gate has them, the last once they can play', function () {
    openSeason();
    $steps = function (User $viewer): array {
        preg_match_all('/data-test="season-how-step" data-step="(\w+)" data-done="(\w+)"/', (string) $this->actingAs($viewer)->get(route('play'))->getContent(), $found);

        return array_combine($found[1], $found[2]);
    };

    opponentsReachRanks(10);
    expect($steps($this->anna))->toBe(['trusted' => 'false', 'opponents' => 'false', 'play' => 'false']);

    opponentsReachRanks(80);
    expect($steps($this->anna))->toBe(['trusted' => 'true', 'opponents' => 'false', 'play' => 'false']);

    opponentsReachList($this->anna, $this->annaSigner, $this->bert);
    opponentsReachList($this->bert, $this->bertSigner, $this->anna);
    expect($steps($this->anna))->toBe(['trusted' => 'true', 'opponents' => 'true', 'play' => 'true']);

    // Mutual but below the minimum again: the last step waits for the first.
    opponentsReachRanks(10);
    expect($steps($this->anna))->toBe(['trusted' => 'false', 'opponents' => 'true', 'play' => 'false']);
});

test('the season band on home and /matches says what rated needs and leads to the explainer', function () {
    openSeason();

    foreach ([['home', 'home-season-band'], ['matches.index', 'matches-season']] as [$route, $test]) {
        $html = (string) $this->get(route($route))->assertOk()->getContent();
        expect($html)->toContain('data-test="'.$test.'-needs"')
            ->and($html)->toContain('href="#season-how" class="rv-lk whitespace-nowrap" data-test="'.$test.'-how"')
            ->and($html)->toContain('id="season-how"');
    }
});
