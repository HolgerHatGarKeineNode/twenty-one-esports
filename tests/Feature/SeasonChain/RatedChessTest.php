<?php

/*
 * Rated blitz through the same trust gate and season chain as a rated series
 * (P7d): the rated queue pairs only Trusted players who list each other, the
 * pairing pins the gate and the clans, the result moves the rated Elo and is
 * attested (2154) with a `board` row, `elo`, `trust`, `gate`, `clan` and a
 * block. Nothing after the pairing undoes the gate.
 */

use App\Enums\ChessGameStatus;
use App\Livewire\Actions\DeleteAccount;
use App\Models\ChessGame;
use App\Models\Clan;
use App\Models\NostrEvent;
use App\Models\Rating;
use App\Models\RatingChange;
use App\Models\SeasonAttestation;
use App\Models\User;
use App\Support\Chess\ChessGameService;
use App\Support\Chess\ChessQueue;
use App\Support\Chess\ChessRuleViolation;
use App\Support\Rating\Ratings;
use App\Support\SeasonChain\NoTrustFacts;
use App\Support\SeasonChain\TrustFacts;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/** Trust facts with fixed answers, naming a trust key and assertion ids. */
function chessFacts(bool $connected = true, array $untrusted = []): TrustFacts
{
    return new class($connected, $untrusted) implements TrustFacts
    {
        /** @param list<string> $untrusted */
        public function __construct(private bool $connected, private array $untrusted) {}

        public function available(): bool
        {
            return true;
        }

        public function at(array $players, array $gatekeepers): array
        {
            $facts = ['trust' => [], 'anchors' => [], 'assertions' => [], 'lists' => [], 'connected' => $this->connected, 'key' => str_repeat('7', 64)];

            foreach (array_unique([...$players, ...$gatekeepers]) as $player) {
                $facts['trust'][$player] = in_array($player, $this->untrusted, true) ? 0 : 100;
                $facts['assertions'][$player] = hash('sha256', 'assertion '.$player);
                $facts['lists'][$player] = $this->connected ? hash('sha256', 'list '.$player) : null;
            }

            $facts['lists'] = array_filter($facts['lists']);

            return $facts;
        }
    };
}

/** A player who owns a clan (so the attestation has `clan` rows). */
function clanPlayer(): User
{
    $user = User::factory()->create();
    Clan::factory()->create(['owner_id' => $user->id]);

    return $user;
}

/** 20 full moves of a closed Ruy Lopez (Breyer), enough for consensus rule 2. */
function playTwentyMoves(ChessGame $game): ChessGame
{
    $moves = [
        'e2e4', 'e7e5', 'g1f3', 'b8c6', 'f1b5', 'a7a6', 'b5a4', 'g8f6', 'e1g1', 'f8e7',
        'f1e1', 'b7b5', 'a4b3', 'd7d6', 'c2c3', 'e8g8', 'h2h3', 'c6b8', 'd2d4', 'b8d7',
        'b1d2', 'c8b7', 'b3c2', 'f8e8', 'd2f1', 'e7f8', 'f1g3', 'g7g6', 'a2a4', 'c7c5',
        'd4d5', 'c5c4', 'c1g5', 'h7h6', 'g5e3', 'd7c5', 'd1d2', 'h6h5', 'e3g5', 'f8e7',
    ];
    $service = app(ChessGameService::class);

    foreach ($moves as $index => $uci) {
        $game = $service->move($game->refresh(), $index % 2 === 0 ? $game->white : $game->black, $uci);
    }

    return $game;
}

beforeEach(function () {
    $this->season = openSeason();
    config(['esports.chess.rated_queue' => true]);
});

test('rated blitz end to end: paired with a pinned gate, rated, attested and mined, even after the loser unfollows', function () {
    app()->instance(TrustFacts::class, chessFacts());
    [$a, $b] = [clanPlayer(), clanPlayer()];
    $queue = app(ChessQueue::class);

    expect($queue->join($a, 'blitz', rated: true))->toBeNull();
    $game = $queue->join($b, 'blitz', rated: true);

    expect($game)->toBeInstanceOf(ChessGame::class)
        ->and($game->rated)->toBeTrue()
        ->and($game->gate_at_accept['players'])->toHaveKeys([$a->pubkey, $b->pubkey])
        ->and($game->clans_at_accept)->toHaveKeys([$a->pubkey, $b->pubkey]);

    $game = playTwentyMoves($game);
    [$white, $black] = [$game->white, $game->black];

    // After the pairing Black drops out of trust and unlists White: nothing undoes the gate.
    app()->instance(TrustFacts::class, chessFacts(connected: false, untrusted: [$black->pubkey]));
    app(ChessGameService::class)->resign($game->refresh(), $black);

    $attestation = SeasonAttestation::query()->sole();
    $tags = NostrEvent::query()->findOrFail($attestation->nostr_event_id)->payload()['tags'];
    $gate = $game->refresh()->gate_at_accept['players'];

    expect(Rating::query()->where('pool', Rating::RATED)->where('user_id', $white->id)->value('rating'))->toBe(1020)
        ->and(Rating::query()->where('pool', Rating::RATED)->where('user_id', $black->id)->value('rating'))->toBe(980)
        ->and($attestation->only(['source', 'source_id', 'height', 'rule', 'game', 'mode']))->toBe(['source' => 'chess', 'source_id' => $game->id, 'height' => 1, 'rule' => null, 'game' => 'chess', 'mode' => 'blitz'])
        ->and($attestation->reward)->toBeGreaterThan(0)
        ->and($tags)->toContain(
            ['board', '1', $white->pubkey, $black->pubkey, '1-0'],
            ['resolution', 'admin'],
            ['winner', 'challenger'],
            ['elo', $white->pubkey, '1000', '1020'],
            ['elo', $black->pubkey, '1000', '980'],
            ['match', (string) $game->number],
            ['trust', str_repeat('7', 64), '50'],
            ['gate', $black->pubkey, '100', $gate[$black->pubkey]['assertion'], $gate[$black->pubkey]['list']],
            ['clan', $white->pubkey, $game->clans_at_accept[$white->pubkey]],
        )
        ->and(collect($tags)->firstWhere(0, 'block'))->toBe(['block', '1', $this->season->genesisId()]);
});

test('the rated queue refuses while rated chess is off, without trust ranks or for an untrusted player, and never pairs two players who do not list each other', function () {
    [$a, $b] = [clanPlayer(), clanPlayer()];
    $queue = app(ChessQueue::class);
    $reason = function (User $user) use ($queue): ?string {
        try {
            $queue->join($user, 'blitz', rated: true);
        } catch (ChessRuleViolation $violation) {
            return $violation->getMessage();
        }

        return null;
    };

    config(['esports.chess.rated_queue' => false]);
    app()->instance(TrustFacts::class, chessFacts());
    expect($reason($a))->toBe('Rated chess is not open yet. Blitz games are casual for now.');

    config(['esports.chess.rated_queue' => true]);
    app()->instance(TrustFacts::class, new NoTrustFacts);
    expect($reason($a))->toContain('Rated play opens once trust ranks are computed');

    app()->instance(TrustFacts::class, chessFacts(untrusted: [$a->pubkey]));
    expect($reason($a))->toContain('Rated chess needs a Trusted account');

    app()->instance(TrustFacts::class, chessFacts(connected: false));

    expect($queue->join($a, 'blitz', rated: true))->toBeNull()
        ->and($queue->join($b, 'blitz', rated: true))->toBeNull()
        ->and(ChessGame::query()->count())->toBe(0)
        // A casual search next to it still pairs as before.
        ->and($queue->join(User::factory()->create()))->toBeNull();
});

test('regression (security gate F3): an account cannot be deleted during a rated game, so the pinned game is not cascaded away', function () {
    app()->instance(TrustFacts::class, chessFacts());
    [$a, $b] = [clanPlayer(), clanPlayer()];
    app(ChessQueue::class)->join($a, 'blitz', rated: true);
    $game = app(ChessQueue::class)->join($b, 'blitz', rated: true);

    expect(fn () => app(DeleteAccount::class)($b))->toThrow(ValidationException::class, 'rated game')
        ->and(User::query()->whereKey($b->id)->exists())->toBeTrue()
        ->and(ChessGame::query()->whereKey($game->id)->exists())->toBeTrue();

    // The settings page says why instead of failing.
    Livewire::actingAs($b)->test('pages::settings.gaming')
        ->set('confirmDeletion', true)
        ->call('deleteAccount')
        ->assertHasErrors('confirmDeletion')
        ->assertSee('Finish your rated game first');

    // Casual games do not hold an account back.
    $casual = User::factory()->create();
    $running = ChessGame::factory()->create(['white_id' => $casual->id]);
    app(DeleteAccount::class)($casual);

    // The running casual game ends (aborted, no clock ran yet) and stays, its side anonymised.
    expect(User::query()->whereKey($casual->id)->exists())->toBeFalse()
        ->and($running->refresh()->only(['white_id', 'status']))->toBe(['white_id' => null, 'status' => ChessGameStatus::Aborted]);
});

test('regression (security re-check, item 4): deleting an account anonymises its finished games instead of deleting them', function () {
    app()->instance(TrustFacts::class, chessFacts());
    [$a, $b] = [clanPlayer(), clanPlayer()];
    app(ChessQueue::class)->join($a, 'blitz', rated: true);
    $game = playTwentyMoves(app(ChessQueue::class)->join($b, 'blitz', rated: true));
    $black = $game->black;
    app(ChessGameService::class)->resign($game->refresh(), $black);
    $moves = $game->moves()->count();

    app(DeleteAccount::class)($black);

    $game = ChessGame::query()->find($game->id);

    expect($game)->not->toBeNull()
        ->and($game->black_id)->toBeNull()
        ->and($game->moves()->count())->toBe($moves)
        ->and(RatingChange::query()->where('source', RatingChange::CHESS)->where('source_id', $game->id)->count())->toBe(2)
        ->and($game->black->exists)->toBeFalse()
        ->and($game->black->displayName())->toBe('Deleted player');

    $this->actingAs($game->white)->get(route('games.show', $game))->assertOk()->assertSee('Deleted player')
        // A name without a link: there is no player page to open.
        ->assertSee('data-test="deleted-player"', false)
        ->assertDontSee(route('players.show', $game->black->npub), false);

    // The loser's rating change is still shown on the anonymised side.
    expect(Ratings::forChessGame($game)['b']['delta'])->toBe(-20);

    // Every page that lists the game or the winner's history still renders.
    foreach ([route('home'), route('matches.index'), route('matches.index', ['game' => 'chess']), route('chess.lobby'), route('ladder.show', ['chess', 'blitz']),
        route('players.show', $game->white->npub), route('dashboard'), route('me.correspondence'), route('mining')] as $url) {
        $this->get($url)->assertOk();
    }
});

test('a rated draw moves the rated Elo and is attested, but is no block candidate', function () {
    app()->instance(TrustFacts::class, chessFacts());
    [$a, $b] = [clanPlayer(), clanPlayer()];
    app(ChessQueue::class)->join($a, 'blitz', rated: true);
    $game = app(ChessQueue::class)->join($b, 'blitz', rated: true);
    $service = app(ChessGameService::class);

    $service->move($game, $game->white, 'e2e4');
    $service->offerDraw($game->refresh(), $game->black);
    $service->acceptDraw($game->refresh(), $game->white);

    $attestation = SeasonAttestation::query()->sole();

    expect(Rating::query()->where('pool', Rating::RATED)->count())->toBe(2)
        ->and($attestation->candidate)->toBeNull()
        ->and($attestation->height)->toBeNull()
        ->and(NostrEvent::query()->findOrFail($attestation->nostr_event_id)->payload()['tags'])->toContain(['winner', 'draw']);
});

test('the end-of-game panel says what a rated game mined: a block, or no block with the rule, or pending', function () {
    app()->instance(TrustFacts::class, chessFacts());
    $queue = app(ChessQueue::class);
    $service = app(ChessGameService::class);
    $mining = fn (ChessGame $game) => $service->snapshot($game->refresh(), withMoves: false)['mining'];

    // Twenty moves, then a resignation: block 1.
    [$a, $b] = [clanPlayer(), clanPlayer()];
    $queue->join($a, 'blitz', rated: true);
    $long = playTwentyMoves($queue->join($b, 'blitz', rated: true));
    $service->resign($long->refresh(), $long->black);

    expect($mining($long))->toBe(['status' => 'block', 'text' => 'mined block 1, '.SeasonAttestation::query()->sole()->reward_per_player.' sats per winner, pending season review']);

    // Resigned after one move: no block, rule 2.
    [$c, $d] = [clanPlayer(), clanPlayer()];
    $queue->join($c, 'blitz', rated: true);
    $short = $queue->join($d, 'blitz', rated: true);
    $service->move($short, $short->white, 'e2e4');
    $service->resign($short->refresh(), $short->black);

    expect($mining($short))->toBe(['status' => 'none', 'text' => 'no block: rule 2, too short to count as a real game']);

    // Rated and finished, but no attestation yet: pending. A casual game has no mining line.
    $pending = ChessGame::factory()->rated()->finished()->create();
    $casual = ChessGame::factory()->finished()->create();

    expect($mining($pending))->toBe(['status' => 'pending', 'text' => 'pending: the league attests the result'])
        ->and($mining($casual))->toBeNull();

    // The live page's end-of-game panel shows that line in the Hashrate row instead of the casual text.
    $live = ChessGame::factory()->rated()->create();
    $this->actingAs($live->white)->get(route('games.show', $live))->assertOk()->assertSee('data-test="game-over-mining"', false)->assertSee('state.mining ? state.mining.text', false);
});
