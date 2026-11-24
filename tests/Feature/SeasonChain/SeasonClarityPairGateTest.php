<?php

/*
|--------------------------------------------------------------------------
| Season vs. Casual: the third review of P1–P6
|--------------------------------------------------------------------------
|
| "Rated next time" and "eligible" follow the pair gate, so an opponent below
| the trust minimum keeps them away (named without their rank). The mutual
| opponents card reads the gate's facts once for the rows it shows, not once
| per opponent. A guest on a board lobby is asked to log in, also while the
| rated queue is off.
*/

use App\Games\Blockli;
use App\Models\ChessGame;
use App\Models\NostrEvent;
use App\Models\TrustRank;
use App\Models\TrustRun;
use App\Models\User;
use App\Support\Nostr\SignedEvent;
use App\Support\SeasonChain\AnchoredTrustFacts;
use App\Support\SeasonChain\Opponents;
use App\Support\SeasonChain\SeasonPath;
use App\Support\SeasonChain\Seasons;
use App\Support\SeasonChain\TrustFacts;
use App\Support\Series\Ladders;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\BlockliOn;
use Tests\Support\TestSigner;

beforeEach(function () {
    Queue::fake();
    $this->freezeSecond();
    // The profile standard has its own tests (SeasonPathTest); here only the trust ranks and the lists decide.
    config(['esports.fair_play.profile_standard.enabled' => false, 'esports.chess.rated_queue' => true, 'esports.board_games.rated_queue' => true]);
    // Blockli on before the season opens, so its ladders are in the season (the board lobby and the mutual card's challenge).
    BlockliOn::play();
    openSeason();
});

/** @return array{0: User, 1: TestSigner} */
function pairGatePlayer(string $name): array
{
    $signer = new TestSigner;

    return [User::factory()->withPubkey($signer->pubkey)->create(['name' => $name]), $signer];
}

/** Both add each other, signed as the browser would. */
function pairGateListEachOther(array $a, array $b): void
{
    $opponents = app(Opponents::class);

    foreach ([[$a, $b], [$b, $a]] as [[$player, $signer], [$opponent]]) {
        $opponents->add($player, $opponent, $signer->signTemplates($opponents->prepareAdd($player, $opponent)));
        test()->travel(1)->seconds();
    }
}

/**
 * Trust ranks computed, each player at their own rank (0 when absent), every pair connected.
 *
 * @param  array<string, int>  $ranks  pubkey => rank
 */
function pairGateRanks(array $ranks): void
{
    app()->bind(TrustFacts::class, fn () => new class($ranks) implements TrustFacts
    {
        /** @param array<string, int> $ranks */
        public function __construct(private array $ranks) {}

        public function available(): bool
        {
            return true;
        }

        public function at(array $players, array $gatekeepers): array
        {
            return ['trust' => array_intersect_key($this->ranks, array_flip([...$players, ...$gatekeepers])), 'anchors' => [], 'connected' => true];
        }
    });
}

test('an opponent below the trust minimum keeps "rated next time" and "eligible" away and is named without a rank; at the minimum both show', function () {
    $anna = pairGatePlayer('anna');
    $bert = pairGatePlayer('bert');
    pairGateListEachOther($anna, $bert);
    $game = ChessGame::factory()->finished()->create(['white_id' => $anna[0]->id, 'black_id' => $bert[0]->id, 'ply' => 12]);
    $page = fn (): string => html_entity_decode((string) $this->actingAs($anna[0])->get(route('games.show', $game))->assertOk()->getContent());
    $link = fn (): string => html_entity_decode(Blade::render('<x-opponents.after-game-link :opponent="$opponent" :viewer="$viewer" />', ['opponent' => $bert[0], 'viewer' => $anna[0]]));
    $path = fn (): array => app(SeasonPath::class)->for($anna[0], 'chess', 'blitz', $bert[0]);

    // Mutation M2: the opponent step checking only the mutual listing (no pair gate) turns the path assertions red;
    // mutation M3: x-opponents.after-game ignoring the opponent (no opponentMissing()) turns the page assertions red.
    pairGateRanks([$anna[0]->pubkey => 80, $bert[0]->pubkey => 10]);
    $opponentStep = collect($path()['steps'])->firstWhere('key', 'opponent');

    expect($path()['state'])->not->toBe('eligible')
        ->and([$opponentStep['done'], $opponentStep['text']])->toBe([false, 'bert is not Trusted yet'])
        ->and($page())->not->toContain('rated next time')->not->toContain('Rated next time?')
        ->toContain('data-rated-next="false"')
        ->toContain('data-test="after-game-missing" data-key="opponent"')
        ->toContain('bert is not Trusted yet')
        ->not->toContain('Trust rank 10')
        ->and($link())->toContain('data-state="missing"')->toContain('bert is not Trusted yet')->not->toContain('rated next time');

    pairGateRanks([$anna[0]->pubkey => 80, $bert[0]->pubkey => 80]);

    expect($path()['state'])->toBe('eligible')
        ->and($page())->toContain('Rated next time?')->toContain('Mutual opponents, rated next time')
        ->toContain('data-rated-next="true"')
        ->not->toContain('data-test="after-game-missing"')
        ->and($link())->toContain('data-state="mutual"')->toContain('Mutual opponents, rated next time');
});

test('the mutual opponents card reads the gate\'s facts once for the rows it shows: no query per opponent', function () {
    $trust = new TestSigner;
    $anchors = NostrEvent::fromSigned(SignedEvent::fromInput($trust->sign(30000, [['d', 'esports/x/anchors'], ['alt', 'anchors']])));
    $run = TrustRun::query()->create(['season_id' => Seasons::live()?->id, 'trust_pubkey' => $trust->pubkey, 'anchor_list_nostr_event_id' => $anchors->id,
        'anchors' => 0, 'lists' => 0, 'ranked' => 11, 'published' => 11, 'computed_at' => now()]);
    $rank = function (User $player) use ($trust, $anchors, $run): void {
        $assertion = NostrEvent::fromSigned(SignedEvent::fromInput($trust->sign(30382, [['d', $player->pubkey], ['p', $player->pubkey], ['rank', '80'], ['alt', 'rank']])));
        TrustRank::query()->create(['pubkey' => $player->pubkey, 'rank' => 80, 'raw' => 1.0, 'anchor_list_event_id' => $anchors->event_id,
            'trust_run_id' => $run->id, 'nostr_event_id' => $assertion->id, 'event_id' => $assertion->event_id]);
    };
    // The real facts, recording how many players each read asks for.
    $asked = new ArrayObject;
    app()->bind(TrustFacts::class, fn () => new class(app(AnchoredTrustFacts::class), $asked) implements TrustFacts
    {
        public function __construct(private TrustFacts $facts, private ArrayObject $asked) {}

        public function available(): bool
        {
            return $this->facts->available();
        }

        public function at(array $players, array $gatekeepers): array
        {
            $this->asked->append(count(array_unique([...$players, ...$gatekeepers])));

            return $this->facts->at($players, $gatekeepers);
        }
    });

    $anna = pairGatePlayer('anna');
    $rank($anna[0]);
    $measure = function () use ($anna, $asked): array {
        $render = fn (): string => Blade::render('<x-opponents.mutual :game="$game" :viewer="$viewer" />', ['game' => Blockli::SLUG, 'viewer' => $anna[0]]);
        $render();
        $asked->exchangeArray([]);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $html = $render();
        DB::disableQueryLog();

        return ['queries' => count(DB::getQueryLog()), 'rows' => substr_count($html, 'data-test="mutual-opponent"'), 'ready' => substr_count($html, 'data-ready="true"'), 'asked' => max([0, ...$asked->getArrayCopy()])];
    };

    $first = pairGatePlayer('opponent 1');
    $rank($first[0]);
    pairGateListEachOther($anna, $first);
    $one = $measure();

    foreach (range(2, 10) as $n) {
        $opponent = pairGatePlayer('opponent '.$n);
        $rank($opponent[0]);
        pairGateListEachOther($anna, $opponent);
    }

    $ten = $measure();

    // Before the fix: 1 → 22, 10 → 193 queries (third review, finding 3). Mutation M4: `take($limit)` after reading the
    // facts (all ten opponents read) turns the `asked` assertion red.
    expect([$one['rows'], $one['ready'], $ten['rows'], $ten['ready']])->toBe([1, 1, 6, 6])
        ->and($ten['queries'])->toBeLessThanOrEqual($one['queries'] + 2)
        ->and($ten['asked'])->toBeLessThanOrEqual(7);
});

test('a guest on a board lobby is asked to log in, also while the rated queue is off', function () {
    config(['esports.board_games.rated_queue' => false]);

    expect(Ladders::isOpen(Blockli::SLUG, 'blitz'))->toBeTrue();

    $html = Livewire::test('pages::board.lobby', ['board' => Blockli::SLUG])->html();
    preg_match('/data-kind="([a-z]*)" data-test="kind-rated-badge">([^<]*)</', $html, $badge);

    // Mutation M5: the "not open yet" branch first again (for guests too) turns this red.
    expect([$badge[1] ?? null, $badge[2] ?? null])->toBe(['login', 'log in first'])
        ->and($html)->not->toContain('not open yet');
});
