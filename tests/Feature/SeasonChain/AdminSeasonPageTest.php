<?php

/*
 * AdminSeason (P7c): every admin sees the chain and the estimator; only the
 * board releases Block 0 (retyped supply, signed label) and changes rules.
 */

use App\Models\Admin;
use App\Models\Season;
use App\Models\SeasonParameterChange;
use App\Models\User;
use App\Support\Nostr\NostrKeys;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\TestSigner;

beforeEach(function () {
    Queue::fake();
    $this->boardSigner = new TestSigner;
    $this->board = User::factory()->withPubkey($this->boardSigner->pubkey)->create();
    config(['esports.board' => [NostrKeys::hexToNpub($this->board->pubkey)], 'esports.league.nsec' => (new TestSigner)->secret, 'esports.trust.nsec' => (new TestSigner)->secret]);
});

test('the page is for admins only, and only the board may release Block 0', function () {
    $this->actingAs(User::factory()->create())->get(route('admin.season'))->assertForbidden();

    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    $this->actingAs($admin)->get(route('admin.season'))
        ->assertOk()
        ->assertSee('data-state="pre-launch"', false)
        ->assertSee('Release Block 0')
        ->assertSee('Only a board member on the public admin list can release Block 0.')
        ->assertSee('forecast from the finished wins of the last 4 weeks');

    $this->actingAs($this->board)->get(route('admin.season'))
        ->assertOk()
        ->assertDontSee('data-test="release-refusal"', false);
});

test('a board admin releases Block 0 from the page: retype the supply, sign the label', function () {
    $page = Livewire::actingAs($this->board)->test('pages::admin.season')
        ->set('message', 'Pre-Season: every fair win is a block')
        ->set('supply', '2 100 001');

    expect($page->instance()->prepareRelease())->toBeNull()
        ->and($page->instance()->releaseError)->toContain('Type the supply exactly as shown');

    $page->set('supply', '2 100 000');
    $templates = $page->instance()->prepareRelease();

    $page->call('release', json_encode($this->boardSigner->signTemplates($templates)))
        ->assertSet('releaseError', '')
        ->assertSee('Block 0 is released. The Pre-Season chain runs.')
        ->assertSee('data-state="live"', false)
        ->assertSee('Change the chain rules');

    expect(Season::query()->sole()->genesis_message)->toBe('Pre-Season: every fair win is a block');
});

test('in a live season a board admin changes a weight; the change is logged and shown', function () {
    openSeason();

    Livewire::actingAs($this->board)->test('pages::admin.season')
        ->assertSet('weights.rocket-league/3v3', '1')
        ->set('weights.rocket-league/3v3', '1.5')
        ->set('reason', 'More reward for Rocket League.')
        ->call('saveChange')
        ->assertSet('changeError', '')
        ->assertSee('More reward for Rocket League.');

    expect(SeasonParameterChange::query()->sole()->parameters)->toBe(['weights' => ['rocket-league/3v3' => 1500]]);
});

test('a malformed value is refused on the page and nothing is logged', function () {
    openSeason();

    Livewire::actingAs($this->board)->test('pages::admin.season')
        ->set('weights.rocket-league/3v3', 'lots')
        ->set('reason', 'x')
        ->call('saveChange')
        ->assertSet('changeError', 'A value is out of range. Check the limits next to each field.');

    expect(SeasonParameterChange::query()->count())->toBe(0);
});
