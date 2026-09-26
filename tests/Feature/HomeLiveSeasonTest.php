<?php

/*
 * Home switches from the Pre-Season countdown to the live head once a season
 * is live (Block 0 released).
 */

test('with a live season home shows the live head, not the Pre-Season countdown', function () {
    openSeason(['slug' => 'season-1']);

    $this->get(route('home'))->assertOk()
        ->assertSee('data-test="season-live"', false)
        ->assertSee(__(':season is live', ['season' => 'Season 1']))
        ->assertSee(route('mining'), false)
        ->assertDontSee(__('Pre-Season starts at Block 0'))
        ->assertDontSee('data-test="countdown"', false);
});

test('before Block 0 home shows the Pre-Season countdown', function () {
    $this->get(route('home'))->assertOk()
        ->assertSee(__('Pre-Season starts at Block 0'))
        ->assertSee('data-test="countdown"', false)
        ->assertDontSee('data-test="season-live"', false);
});
