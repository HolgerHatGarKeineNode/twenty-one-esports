<?php

/*
| The Blockfill page and its controls (plan "Blockfill", P3): the page for
| guests and players, its Livewire roundtrip, the run status the result
| screen asks for, and the controls on the gaming settings page. The game
| itself runs in the browser (tests/Browser/StackerTest.php).
*/

use App\Models\StackerRun;
use App\Models\User;
use App\Support\Stacker\StackerSettings;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\Support\BlockfillOn;

beforeEach(function () {
    $this->withoutVite();
});

test('switched off, there is no Blockfill page and no controls card', function () {
    expect(Route::has('stacker.play'))->toBeFalse();
    $this->get('/blockfill')->assertNotFound();

    $user = User::factory()->create();
    $this->actingAs($user)->get(route('gaming.edit'))->assertOk()->assertDontSee('data-test="stacker-settings"', false);
    Livewire::actingAs($user)->test('pages::settings.gaming')->call('saveStacker')->assertNotFound();
});

test('a guest gets the page to practise, a player gets their controls and best time, and a roundtrip answers 200', function () {
    BlockfillOn::play();

    $this->get(route('stacker.play'))->assertOk()
        ->assertSee('data-test="stacker"', false)
        ->assertSee('Practice needs no login. Log in for ranked runs.')
        ->assertDontSee('data-test="controls-link"', false);

    $keys = array_replace(StackerSettings::DEFAULT_KEYS, ['hard' => ['KeyJ']]);
    $user = User::factory()->create(['stacker_settings' => ['das' => 7, 'arr' => 0, 'sdf' => 41, 'keys' => $keys]]);
    StackerRun::factory()->for($user)->verified(1234)->create();

    $config = Livewire::actingAs($user)->test('pages::stacker.play')->assertOk()->call('$refresh')->assertOk()->instance()->config();
    expect($config['signedIn'])->toBeTrue()
        ->and($config['controls'])->toBe(['das' => 7, 'arr' => 0, 'sdf' => 41, 'keys' => $keys])
        ->and($config['best'])->toBe(1234)
        ->and($config['testing'])->toBeTrue()
        ->and($config['urls']['submit'])->toEndWith('/stacker/runs/__TOKEN__');

    $this->actingAs($user)->get(route('stacker.play'))->assertOk()->assertSee('data-test="controls-link"', false);
});

test('the result screen reads its own run\'s status, nobody else\'s', function () {
    BlockfillOn::play();
    config(['esports.blockfill.testing_seed' => str_repeat('ab', 16)]);
    $user = User::factory()->create();

    $issued = $this->actingAs($user)->postJson(route('stacker.runs.issue'))->assertCreated()->json();
    expect($issued['seed'])->toBe(str_repeat('ab', 16));

    $this->getJson(route('stacker.runs.show', $issued['token']))->assertOk()->assertExactJson(['status' => 'issued', 'reason' => null, 'ticks' => null, 'best' => null]);
    $this->actingAs(User::factory()->create())->getJson(route('stacker.runs.show', $issued['token']))->assertNotFound();
});

test('a player saves handling and keys on the gaming page; a key used twice is refused', function () {
    BlockfillOn::play();
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('gaming.edit'))->assertOk()->assertSee('data-test="stacker-settings"', false);

    $page = Livewire::actingAs($user)->test('pages::settings.gaming')
        ->set('stacker.das', 6)->set('stacker.arr', 0)->set('stacker.sdf', 41)
        ->set('stacker.keys.hard.0', 'KeyJ')
        ->call('saveStacker')->assertHasNoErrors()->assertDispatched('stacker-saved');
    expect($user->refresh()->stacker_settings)->toBe(['das' => 6, 'arr' => 0, 'sdf' => 41, 'keys' => array_replace(StackerSettings::DEFAULT_KEYS, ['hard' => ['KeyJ']])]);

    // the slots read like the keys: "J", "←", "Space"
    $page->assertSeeHtml('data-test="stacker-slot-hard-0">J</button>')->assertSeeHtml('data-test="stacker-slot-left-0">←</button>');

    // a refused binding goes back to what is saved, the form never shows it
    $page->set('stacker.keys.hold.1', 'KeyJ')->call('saveStacker')->assertHasErrors('stacker.keys')->assertSet('stacker.keys.hold.1', 'ShiftLeft');
    $page->set('stacker.das', 21)->call('saveStacker')->assertHasErrors('stacker.das');
    expect($user->refresh()->stacker_settings['keys']['hard'])->toBe(['KeyJ']);

    $page->call('resetStacker');
    expect($user->refresh()->stacker_settings)->toBeNull();
});
