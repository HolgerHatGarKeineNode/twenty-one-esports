<?php

use App\Models\User;

test('the home page counts down to Block 0 when a date is set', function () {
    $this->freezeTime();
    config(['esports.preseason.block0_at' => now()->addDays(3)->addHours(4)->toIso8601String()]);

    $this->get('/')
        ->assertOk()
        ->assertSee('data-state="countdown"', false)
        ->assertSee('x-data="blockZeroCountdown(', false)
        ->assertSee('3 d 04:00:00')
        ->assertDontSee('date coming soon');
});

test('the home page says the date is coming soon when none is set', function () {
    config(['esports.preseason.block0_at' => null]);

    $this->get('/')
        ->assertOk()
        ->assertSee('data-state="undated"', false)
        ->assertSee('date coming soon')
        ->assertDontSee('blockZeroCountdown(', false);
});

test('the home page shows no fictional sample data', function () {
    $this->get('/')
        ->assertOk()
        ->assertDontSee('satsjäger')
        ->assertDontSee('[N]');
});

test('a player asks to be notified at Block 0', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('notify.block0'))
        ->assertRedirect(route('home').'#block0');

    expect($user->fresh()->notify_block0_at)->not->toBeNull();

    $this->actingAs($user)->get('/')->assertSee("We'll tell you at Block 0");
});

test('a guest who asks to be notified is sent to the login', function () {
    $this->post(route('notify.block0'))->assertRedirect(route('login'));
});
