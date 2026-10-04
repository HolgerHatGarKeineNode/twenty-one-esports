<?php

use App\Models\User;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

/*
| The live updates of a logged-in player's shell (performance plan P3, F5):
| the match dock, the cup badge and banner, and the bell share one listener
| on the player's channel and one slow poll (resources/js/playerEvents.js),
| on top of the socket-aware poll (resources/js/livePoll.js). The client
| part is tested in node (tests/js/livePoll.test.mjs); the request counts
| in the browser (tests/Browser/LivewireTrafficTest.php).
*/

test('the poll asks only without a live socket or a hidden tab, and the shell components share one dispatcher', function () {
    $run = Process::path(base_path())->timeout(60)->run(['node', '--test', 'tests/js/livePoll.test.mjs']);

    expect($run->successful())->toBeTrue($run->output().$run->errorOutput())
        ->and($run->output())->toContain('ℹ pass 7')->toContain('ℹ fail 0');
});

test('the bell and the cup badge hand their renders to the dispatcher and survive a roundtrip', function () {
    $player = User::factory()->create();

    Livewire::actingAs($player)->test('notification-bell')
        ->assertSeeHtml('x-data="notificationBell(')->assertDontSeeHtml('x-on:esports-notification')
        ->call('$refresh')->assertOk();
    Livewire::actingAs($player)->test('cup-match', ['variant' => 'badge'])
        ->assertSeeHtml('x-data="cupMatch(')
        ->call('$refresh')->assertOk();
});
