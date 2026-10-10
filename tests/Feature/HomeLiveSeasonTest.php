<?php

/*
 * Home switches from the Pre-Season countdown to the live head once a season
 * is live (Block 0 released).
 */

test('with a live season home shows the live head, not the Pre-Season countdown', function () {
    openSeason(['slug' => 'season-1']);

    $this->get(route('home'))->assertOk()
        ->assertSee('data-test="season-live"', false)
        ->assertSee(route('mining'), false)
        ->assertSee('data-state="live"', false)
        ->assertDontSee('data-test="countdown-segments"', false);
});

test('before Block 0 home shows the Pre-Season with the countdown to Block 0', function () {
    $this->get(route('home'))->assertOk()
        ->assertSee(__('The chain starts at Block 0'))
        ->assertSee('data-test="countdown-segments"', false)
        ->assertDontSee('data-test="season-live"', false);
});

test('the Season page names the live season in its rules, and the Pre-Season only before and during it (P16)', function (string $slug, string $heading) {
    openSeason(['slug' => $slug]);

    $this->get(route('mining'))->assertOk()
        ->assertSee('data-test="how-season">'.e($heading).'</h2>', false);
})->with([
    'a later season' => ['season-2', 'How Season 2 works'],
    'the Pre-Season' => ['pre-season', 'How the Pre-Season works'],
]);
