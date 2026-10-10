<?php

use App\Models\User;

test('the home page counts down to Block 0 when a date is set', function () {
    $this->freezeTime();
    planBlock0(now()->addDays(3)->addHours(4)->toIso8601String());

    // The season card's segmented countdown (Main.dc.html): days, hours, minutes.
    $this->get('/')
        ->assertOk()
        ->assertSee('data-state="countdown"', false)
        ->assertSee('x-data="segmentCountdown(', false)
        ->assertSeeInOrder(['data-test="countdown-segments"', '03', '04', '00'], false)
        ->assertDontSee(__('Date to follow; the countdown starts once it is set.'));
});

test('the home page says the date is coming soon when none is set', function () {
    planBlock0(null);

    $this->get('/')
        ->assertOk()
        ->assertSee('data-state="undated"', false)
        ->assertSee(__('Date to follow; the countdown starts once it is set.'))
        ->assertDontSee('segmentCountdown(', false);
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
