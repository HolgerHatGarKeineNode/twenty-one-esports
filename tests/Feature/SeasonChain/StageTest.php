<?php

/*
 * The Block 0 stage (pages/admin/⚡stage): the release as one big button on a
 * projected screen. The page adds no rule and no second way to release: who
 * may open it is SeasonRelease::refusal(), arming is prepare(), the completed
 * hold is release(). A rehearsal runs the same show and never releases.
 */

use App\Models\Admin;
use App\Models\NostrEvent;
use App\Models\Season;
use App\Models\User;
use App\Support\Nostr\NostrKeys;
use App\Support\SeasonChain\ChainDraft;
use App\Support\SeasonChain\ChainOverview;
use App\Support\SeasonChain\SeasonRelease;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\BlockliOn;
use Tests\Support\BlockspaceOn;
use Tests\Support\GoOn;
use Tests\Support\HyperOn;
use Tests\Support\TestSigner;

const STAGE_MESSAGE = '20/Nov/2026 TWENTY ONE Esports: every fair win is a block';

beforeEach(function () {
    Queue::fake();
    config(['esports.league.nsec' => (new TestSigner)->secret, 'esports.trust.nsec' => (new TestSigner)->secret]);

    $this->boardSigner = new TestSigner;
    $this->board = User::factory()->withPubkey($this->boardSigner->pubkey)->create(['timezone' => 'UTC']);
    config(['esports.board' => [NostrKeys::hexToNpub($this->board->pubkey)]]);

    saveChainDraft(['message' => STAGE_MESSAGE]);
});

function stagePage(User $user): Testable
{
    return Livewire::actingAs($user)->test('pages::admin.stage');
}

/**
 * Arm the stage as the browser does: the page answers with the release label,
 * the board member's signer signs it.
 *
 * @return string the signed label as the page sends it to launch()
 */
function armStage(Testable $page, TestSigner $signer, bool $rehearsal, string $supply = '2 100 000'): string
{
    $answer = [];
    $page->call('arm', $supply, $rehearsal)->assertReturned(function (array $data) use (&$answer): bool {
        $answer = $data;

        return $data['ok'] === true;
    });

    expect($answer['rehearsal'])->toBe($rehearsal)
        ->and($answer['seconds'])->toBeLessThan(3600);

    return json_encode($signer->signTemplates([$answer['template']]));
}

test('the stage refuses everyone the release refuses: a guest, a player, and an admin who is not on the board', function () {
    $this->get(route('admin.season.stage'))->assertRedirect(route('login'));

    $this->actingAs(User::factory()->create())->get(route('admin.season.stage'))->assertForbidden();

    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    expect($admin->isAdmin())->toBeTrue()
        ->and(SeasonRelease::refusal($admin))->not->toBeNull();
    $this->actingAs($admin)->get(route('admin.season.stage'))->assertForbidden();

    $this->actingAs($this->board)->get(route('admin.season.stage'))
        ->assertOk()
        ->assertSee('data-test="stage-button"', false)
        ->assertSee(STAGE_MESSAGE);
});

test('the admin season page links to the stage only while Block 0 can be released', function () {
    $this->actingAs($this->board)->get(route('admin.season'))->assertOk()->assertSee(route('admin.season.stage'));

    config(['esports.league.nsec' => null]);
    $this->actingAs($this->board)->get(route('admin.season'))->assertOk()->assertDontSee(route('admin.season.stage'));
});

test('a real arming and a completed hold release Block 0 once, through the release itself', function () {
    $page = stagePage($this->board);
    $signed = armStage($page, $this->boardSigner, rehearsal: false);

    $page->call('launch', $signed)->assertReturned(['ok' => true, 'rehearsal' => false]);

    $season = Season::query()->sole();
    expect($season->released_by_id)->toBe($this->board->id)
        ->and($season->genesis_message)->toBe(STAGE_MESSAGE)
        ->and($season->supply)->toBe(2_100_000);

    // The arming is spent: a second launch finds nothing armed and a season that is live.
    $page->call('launch', $signed)->assertReturned(fn (array $data): bool => $data['ok'] === false && $data['live'] === true);
    expect(Season::query()->count())->toBe(1);
});

test('nothing is armed with a wrongly retyped supply, and an unarmed stage launches nothing', function () {
    $page = stagePage($this->board);

    $page->call('arm', '2 100 001', false)->assertReturned(fn (array $data): bool => $data['ok'] === false && str_contains($data['reason'], 'Type the supply'));
    $page->assertSet('armed', false);

    $page->call('launch', '[]')->assertReturned(fn (array $data): bool => $data['ok'] === false && str_contains($data['reason'], 'not armed'));
    $page->call('label')->assertReturned(fn (array $data): bool => $data['ok'] === false);

    expect(Season::query()->count())->toBe(0)
        ->and(NostrEvent::query()->count())->toBe(0);
});

test('an arming that is an hour old cannot release: the release says it has expired', function () {
    $page = stagePage($this->board);
    $signed = armStage($page, $this->boardSigner, rehearsal: false);

    $this->travel(61)->minutes();

    $page->call('launch', $signed)->assertReturned(fn (array $data): bool => $data['ok'] === false && str_contains($data['reason'], 'expired') && $data['live'] === false);
    expect(Season::query()->count())->toBe(0)
        ->and(NostrEvent::query()->count())->toBe(0);
});

test('a draft that changed after arming cannot be released from the stage', function () {
    $page = stagePage($this->board);
    $signed = armStage($page, $this->boardSigner, rehearsal: false);

    saveChainDraft(['message' => STAGE_MESSAGE, 'supply' => 4_200_000]);

    $page->call('launch', $signed)->assertReturned(fn (array $data): bool => $data['ok'] === false && str_contains($data['reason'], 'The draft changed'));
    expect(Season::query()->count())->toBe(0)
        ->and(NostrEvent::query()->count())->toBe(0);
});

test('the label signed at arming is the one the hold sends: good nine minutes later, refused after eleven', function () {
    $page = stagePage($this->board);
    $signed = armStage($page, $this->boardSigner, rehearsal: false);

    // The label asked again (a new signature on stage) carries the same digest: the end is fixed at arming.
    $again = [];
    $page->call('label')->assertReturned(function (array $data) use (&$again): bool {
        $again = $data;

        return $data['ok'] === true;
    });
    expect($again['template']['tags'])->toBe(json_decode($signed, true)[0]['tags']);

    $this->travel(11)->minutes();
    $page->call('launch', $signed)->assertReturned(fn (array $data): bool => $data['ok'] === false && str_contains($data['reason'], 'signed release was refused'));
    expect(Season::query()->count())->toBe(0);

    $this->travelBack();
    $page = stagePage($this->board);
    $signed = armStage($page, $this->boardSigner, rehearsal: false);
    $this->travel(9)->minutes();
    $page->call('launch', $signed)->assertReturned(['ok' => true, 'rehearsal' => false]);
    expect(Season::query()->count())->toBe(1);
});

test('a rehearsal runs the whole show and never releases, even with a validly signed label', function () {
    $page = stagePage($this->board);
    $signed = armStage($page, $this->boardSigner, rehearsal: true);

    $page->assertSet('armed', true)->assertSet('rehearsal', true);
    $page->call('launch', $signed)->assertReturned(['ok' => true, 'rehearsal' => true]);

    expect(Season::query()->count())->toBe(0)
        ->and(NostrEvent::query()->count())->toBe(0);
    Queue::assertNothingPushed();

    // The rehearsal is spent too: the real mode is reached only through a new arming.
    $page->assertSet('armed', false);
    $page->call('launch', $signed)->assertReturned(fn (array $data): bool => $data['ok'] === false);
    expect(Season::query()->count())->toBe(0);
});

test('the browser cannot turn an armed rehearsal into a release', function () {
    $page = stagePage($this->board);
    armStage($page, $this->boardSigner, rehearsal: true);

    expect(fn () => $page->set('rehearsal', false))->toThrow(CannotUpdateLockedPropertyException::class)
        ->and(fn () => $page->set('armed', true))->toThrow(CannotUpdateLockedPropertyException::class);
});

test('a rehearsal tells what the release would answer: a draft that changed is named, nothing is released', function () {
    $page = stagePage($this->board);
    $signed = armStage($page, $this->boardSigner, rehearsal: true);

    saveChainDraft(['message' => STAGE_MESSAGE, 'supply' => 4_200_000]);

    $page->call('launch', $signed)->assertReturned(fn (array $data): bool => $data['ok'] === false && str_contains($data['reason'], 'The draft changed'));
    expect(Season::query()->count())->toBe(0);
});

/**
 * A draft the board saved while the league's defaults were still chess 60 / board games 40 (config/season.php at
 * 4382fc1f), before Hyperbitcoinization, Blockspace and Go joined them: a saved draft overrides the defaults wholesale.
 */
function saveDraftOfOldDefaults(): void
{
    saveChainDraft([
        'message' => STAGE_MESSAGE,
        'block0_at' => CarbonImmutable::parse('2026-11-20T19:00:00Z')->getTimestamp(),
        'weights' => ['chess/blitz' => 1000, 'chess/correspondence' => 2000, 'chess/rapid' => 1500, 'blockli/blitz' => 1000, 'blockli/rapid' => 1500, 'blockli/correspondence' => 2000],
        'shares' => ['chess' => 60, 'board-games' => 40],
        'daily' => ['chess' => 5, 'board-games' => 5],
    ]);
}

test('the arming step shows the shares and games Block 0 signs: a draft saved before the new defaults reads 60/40 without Hyperbitcoinization, and after a reset and a save 50/30/20 with every game', function () {
    HyperOn::play();
    BlockliOn::play();
    BlockspaceOn::play();
    GoOn::play();
    saveDraftOfOldDefaults();

    expect(ChainDraft::mining(ChainDraft::current()))->toBe([
        ['key' => 'chess', 'label' => 'Chess', 'share' => 60, 'games' => ['Chess blitz', 'Chess daily', 'Chess rapid']],
        ['key' => 'board-games', 'label' => 'Board games', 'share' => 40, 'games' => ['Blockli blitz', 'Blockli rapid', 'Blockli correspondence']],
    ]);

    stagePage($this->board)
        ->assertSeeInOrder([
            'data-test="stage-arm"',
            'Shares and games that mine',
            'data-test="stage-share-chess"', 'Chess', "60\u{00A0}%", 'Chess blitz, Chess daily, Chess rapid',
            'data-test="stage-share-board-games"', 'Board games', "40\u{00A0}%", 'Blockli blitz, Blockli rapid, Blockli correspondence',
            'data-test="stage-warning"', 'No weight, so these do not mine: Hyperbitcoinization live',
            'data-test="stage-message"',
            'data-test="stage-arm-button"',
        ], false)
        ->assertDontSee('data-test="stage-share-hyperbitcoinization"', false)
        ->assertDontSee("50\u{00A0}%", false);

    // The way back: the reset fills the form and saves nothing; the save button saves.
    $season = Livewire::actingAs($this->board)->test('pages::admin.season')
        ->assertSee('data-test="reset-draft"', false)
        ->assertSet('draftShares.chess', '60')
        ->call('resetDraft')
        // The series games and Age of Empires II are in the table and stay empty: they do not mine by default.
        ->assertSet('draftShares', ['chess' => '50', 'board-games' => '30', 'hyperbitcoinization' => '20', 'rocket-league' => '', 'ea-sports-fc' => '', 'age-of-empires-2' => ''])
        ->assertSet('draftWeights.hyperbitcoinization/live', '1')
        ->assertSet('draft.message', STAGE_MESSAGE)
        ->assertSet('draft.block0_at', '2026-11-20T19:00');

    expect(ChainDraft::stored()['shares'])->toBe(['chess' => 60, 'board-games' => 40]);

    $season->call('saveDraft')->assertSet('draftError', '');
    $defaults = ChainDraft::defaults();

    // The form saves in the order of its table (the registry's), so the values are compared, not their order.
    expect(ChainDraft::stored())->toEqual([...$defaults, 'block0_at' => CarbonImmutable::parse('2026-11-20T19:00:00Z')->getTimestamp(), 'message' => STAGE_MESSAGE])
        ->and(array_column(ChainDraft::mining(ChainDraft::current()), 'share', 'key'))->toEqual(['chess' => 50, 'board-games' => 30, 'hyperbitcoinization' => 20]);

    stagePage($this->board)
        ->assertSeeInOrder([
            'data-test="stage-share-hyperbitcoinization"', "20\u{00A0}%", 'Hyperbitcoinization live, Hyperbitcoinization correspondence',
            'data-test="stage-share-chess"', "50\u{00A0}%",
            'data-test="stage-share-board-games"', "30\u{00A0}%", 'Blockspace blitz', 'Go 9×9 blitz',
            STAGE_MESSAGE,
        ], false)
        ->assertDontSee('No weight, so these do not mine: Hyperbitcoinization', false);
});

test('the shares on the stage and the reset on the season page read in German', function () {
    HyperOn::play();

    $this->actingAs($this->board)->get(route('admin.season.stage', ['lang' => 'de']))->assertOk()
        ->assertSeeInOrder(['Anteile und Spiele, die schürfen', 'data-test="stage-share-chess"', 'Schach', "50\u{00A0}%", 'Schach Blitz, Fernschach, Schach Rapid'], false);

    $this->actingAs($this->board)->get(route('admin.season', ['lang' => 'de']))->assertOk()
        ->assertSee('Entwurf auf die Liga-Standards zurücksetzen')
        ->assertSee('Das Zurücksetzen behält die Genesis-Botschaft und den geplanten Block 0 und füllt nur das Formular: Gespeichert wird erst, wenn du den Entwurf speicherst.');
});

test('a forecast that fails does not take the stage down: the shares stay, the failure is reported and said', function () {
    Exceptions::fake();
    app()->bind(ChainOverview::class, fn () => throw new RuntimeException('forecast down'));

    $this->actingAs($this->board)->get(route('admin.season.stage'))->assertOk()
        ->assertSee('data-test="stage-share-chess"', false)
        ->assertSee('The forecast could not be computed, so its warnings are missing here. Check the season page before you arm.')
        ->assertSee('data-test="stage-arm-button"', false);

    Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'forecast down');
});

test('the reset of the draft is offered only before Block 0 and only to the board, and does nothing in a live season', function () {
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    Livewire::actingAs($admin)->test('pages::admin.season')->assertDontSee('data-test="reset-draft"', false);

    Season::factory()->create();

    Livewire::actingAs($this->board)->test('pages::admin.season')
        ->assertDontSee('data-test="reset-draft"', false)
        ->set('draft.supply', '1')
        ->call('resetDraft')
        ->assertSet('draft.supply', '1')
        ->assertSet('notice', '');
});

test('with a season live the stage says so, offers the live page and never shows a button', function () {
    Season::factory()->create();

    $this->actingAs($this->board)->get(route('admin.season.stage'))
        ->assertOk()
        ->assertSee('data-test="stage-live-link"', false)
        ->assertSee('data-state="live"', false)
        ->assertDontSee('data-test="stage-button"', false)
        ->assertDontSee('data-test="stage-arm-button"', false);

    stagePage($this->board)
        ->call('arm', '2 100 000', false)
        ->assertReturned(fn (array $data): bool => $data['ok'] === false && str_contains($data['reason'], 'A season is live'));
    expect(Season::query()->count())->toBe(1);
});
