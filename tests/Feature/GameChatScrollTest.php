<?php

/*
 * The game chat's history scrolls up (2026-11-27, Pixel 6a: it did not). The message list is a plain scroll box
 * now: no justify-end, which left every line that did not fit out of reach above the top. A margin before the
 * first line keeps a short chat at the bottom, and resources/js/chatFollow.js keeps a long one at its newest
 * line (tests/js/chatFollow.test.mjs).
 */
test('the game chat keeps to its newest line and every older line stays in reach', function () {
    nodeTest('tests/js/chatFollow.test.mjs', 4);
});

test('the game chat\'s message list is a scroll box that does not push lines out of reach', function () {
    $body = file_get_contents(resource_path('views/pages/games/partials/chat-body.blade.php'));

    expect(preg_match('/<ol[^>]*data-test="chat-messages"[^>]*>/', $body, $list))->toBe(1)
        ->and($list[0])->toContain('overflow-y-auto')
        ->and($list[0])->toContain('before:mt-auto')
        ->and($list[0])->toContain('x-init="follow($el)"')
        ->and($list[0])->not->toContain('justify-end');
});
