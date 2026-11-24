<?php

/*
|--------------------------------------------------------------------------
| Season vs. Casual: the design-lead review of P1–P6
|--------------------------------------------------------------------------
|
| The trust rank comes only from anchors, opponent lists and confirmed
| reports (TrustJob): no copy says games raise it, and the opponents page's
| #trust section names the members a player has played, each with the add
| button. The end of a game says "rated next time" only to a player who
| could play rated; /mining leads a player who cannot with their first open
| step; and a profile shows no chess chip without a chess result.
*/

use App\Games\Blockli;
use App\Models\ChessGame;
use App\Models\Rating;
use App\Models\User;
use App\Support\Rating\Ratings;
use App\Support\SeasonChain\Opponents;
use App\Support\SeasonChain\SeasonPath;
use App\Support\SeasonChain\TrustFacts;
use Illuminate\Support\Facades\Queue;
use Tests\Support\BlockliOn;
use Tests\Support\TestSigner;

beforeEach(function () {
    Queue::fake();
    $this->freezeSecond();
    // The profile standard has its own tests (SeasonPathTest); here only the trust rank and the lists decide.
    config(['esports.fair_play.profile_standard.enabled' => false, 'esports.chess.rated_queue' => true, 'esports.board_games.rated_queue' => true]);
    openSeason();
});

/** @return array{0: User, 1: TestSigner} */
function reviewPlayer(string $name, array $attributes = []): array
{
    $signer = new TestSigner;

    return [User::factory()->withPubkey($signer->pubkey)->create(['name' => $name, ...$attributes]), $signer];
}

/** Both add each other, signed as the browser would. */
function reviewListEachOther(array $a, array $b): void
{
    $opponents = app(Opponents::class);

    foreach ([[$a, $b], [$b, $a]] as [[$player, $signer], [$opponent]]) {
        $opponents->add($player, $opponent, $signer->signTemplates($opponents->prepareAdd($player, $opponent)));
        test()->travel(1)->seconds();
    }
}

/** Trust ranks computed, every player at `$rank`, every pair connected. */
function reviewRanks(int $rank): void
{
    app()->bind(TrustFacts::class, fn () => new class($rank) implements TrustFacts
    {
        public function __construct(private int $rank) {}

        public function available(): bool
        {
            return true;
        }

        public function at(array $players, array $gatekeepers): array
        {
            return ['trust' => array_fill_keys($players, $this->rank), 'anchors' => [], 'connected' => true];
        }
    });
}

test('no copy says that games raise the trust rank, in English or German', function () {
    // Mutation: the old trust step "Casual games with members, and players who add you back, raise it." turns this red, and so
    // does a German value "Casual-Partien bauen zuerst dein Vertrauen auf.".
    reviewRanks(12);
    [$anna] = reviewPlayer('anna');
    $step = collect(app(SeasonPath::class)->for($anna)['steps'])->firstWhere('key', 'trust');

    expect($step['text'])->toBe('Trust rank 12, needs 50. It rises when members or Trusted players list you as an opponent.');

    $german = json_decode((string) file_get_contents(lang_path('de.json')), true);
    $claims = collect($german)->filter(fn (string $value, string $key): bool => preg_match('/(casual|games)[^.]*\b(raise|build)s?\b[^.]*trust/i', $key) === 1
        || preg_match('/(Casual|Partien)[^.]*(heben|erhöhen|bauen)[^.]*Vertrauen/u', $value) === 1);

    expect($claims->keys()->all())->toBe([]);
});

test('the opponents page\'s #trust section shows the rank and lists the members the player has played, each with the add button', function () {
    reviewRanks(12);
    [$anna] = reviewPlayer('anna');
    [$bert] = reviewPlayer('bert', ['is_member' => true]);
    [$carl] = reviewPlayer('carl');
    [$dora] = reviewPlayer('dora', ['is_member' => true]);
    ChessGame::factory()->finished()->create(['white_id' => $anna->id, 'black_id' => $bert->id]);
    ChessGame::factory()->finished()->create(['white_id' => $carl->id, 'black_id' => $anna->id]);

    $html = (string) $this->actingAs($anna)->get(route('settings.opponents'))->assertOk()->getContent();
    $section = str($html)->after('data-test="trust-section"')->before('</section>')->toString();
    preg_match_all('/data-test="trust-member" data-id="(\d+)"/', $section, $members);

    // Mutation: dropping `->where('is_member', true)` from SeasonPath::playedMembers() lists carl and turns this red.
    expect($html)->toContain('id="trust"')
        ->and($section)->toContain('Trust rank 12 of 50')->toContain('A member who knows you adds you as an opponent.')
        ->and($members[1])->toBe([(string) $bert->id])
        ->and(str($section)->after('data-id="'.$bert->id.'"')->toString())->toContain('data-test="opponent-add"')
        ->and($section)->not->toContain('dora');
});

test('the end of a game says "rated next time" only to a viewer who could play rated; below the minimum it names the trust rank', function () {
    $anna = reviewPlayer('anna');
    $bert = reviewPlayer('bert');
    reviewListEachOther($anna, $bert);
    $game = ChessGame::factory()->finished()->create(['white_id' => $anna[0]->id, 'black_id' => $bert[0]->id, 'ply' => 12]);
    $page = fn (): string => html_entity_decode((string) $this->actingAs($anna[0])->get(route('games.show', $game))->assertOk()->getContent());

    // Mutation: `$missing = null` in x-opponents.after-game (no SeasonPath::missing() check) turns this red.
    reviewRanks(12);
    expect($page())->not->toContain('rated next time')->not->toContain('Rated next time?')
        ->toContain('data-test="after-game-missing" data-key="trust"')
        ->toContain('You still need: Trust rank 12/50')
        ->toContain('href="'.route('settings.opponents').'#trust"');

    reviewRanks(80);
    expect($page())->toContain('Mutual opponents, rated next time')
        ->not->toContain('data-test="after-game-missing"');
});

test('on /mining a player who cannot play rated yet gets the first open step as the primary action and the game casual beside it', function () {
    BlockliOn::play();
    reviewRanks(12);
    $anna = reviewPlayer('anna');
    reviewListEachOther($anna, reviewPlayer('bert'));

    $html = html_entity_decode((string) $this->actingAs($anna[0])->get(route('mining'))->assertOk()->getContent());
    preg_match('/<a href="([^"]+)" class="rv-b h-\[52px\] px-6" data-test="mining-primary" data-variant="([a-z]+)"[^>]*>([^<]+)</', $html, $primary);

    // Mutation: `$firstStep = null` in the /mining hero (the game rated as primary for everyone) turns this red.
    expect(array_slice($primary, 1))->toBe([route('settings.opponents').'#trust', 'step', 'Raise your trust rank'])
        ->and($html)->toMatch('/data-test="mining-secondary" data-game="[a-z-]+">Play [^<]+ casual</');
});

test('profile chips: no chess chip without a chess result, and a chip for the board game the player played most', function () {
    BlockliOn::play();
    [$anna] = reviewPlayer('anna');

    // Mutation: the forced default mode (`$mode === ChessModes::DEFAULT ||`) turns this red.
    expect(Ratings::chipsFor($anna))->toBe([]);

    foreach ([['blitz', 7], ['rapid', 2]] as [$mode, $results]) {
        Rating::query()->create(['pool' => Rating::CASUAL, 'season' => '', 'game' => Blockli::SLUG, 'mode' => $mode, 'subject' => 'user:'.$anna->id, 'user_id' => $anna->id,
            'rating' => 1100, 'results' => $results, 'wins' => $results, 'draws' => 0, 'losses' => 0]);
    }

    $chips = Ratings::chipsFor($anna);

    expect($chips)->toHaveCount(1)
        ->and($chips[0]['game'])->toBe(Blockli::SLUG)
        ->and($chips[0]['label'])->toBe('Blockli Blitz')
        ->and($chips[0]['rating']['rating'])->toBe(1100)
        ->and($this->get(route('players.show', $anna->npub))->assertOk()->getContent())->not->toContain('data-chess-mode=');
});
