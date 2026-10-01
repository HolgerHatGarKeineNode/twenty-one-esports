<?php

use App\Models\Admin;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\TestSigner;

beforeEach(function () {
    Queue::fake();
    config(['esports.league.nsec' => (new TestSigner)->secret]);
});

test('the admin tournament list hides the casual cups by default and shows them, or all, on request', function () {
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    openTournament(['name' => 'Halving Cup']);
    openTournament(['name' => 'Chess Casual Cup EU #1', 'cup_series' => 'chess-eu', 'cup_number' => 1]);
    $this->actingAs($admin);

    Livewire::test('pages::admin.tournaments')
        ->assertSee('Halving Cup')->assertDontSee('Chess Casual Cup EU #1')
        ->assertSeeHtml('data-test="kind-manual"')
        ->set('kind', 'casual')
        ->assertSee('Chess Casual Cup EU #1')->assertDontSee('Halving Cup')
        ->set('kind', 'all')
        ->assertSee('Chess Casual Cup EU #1')->assertSee('Halving Cup');

    $this->get(route('admin.tournaments', ['kind' => 'casual']))->assertOk()->assertSee('Chess Casual Cup EU #1')->assertDontSee('Halving Cup');
});
