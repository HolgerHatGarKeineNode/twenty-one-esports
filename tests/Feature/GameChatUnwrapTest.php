<?php

/*
 * The game chat and a signer that fails for a moment (resources/js/gameChat.js; DerCaddy, 2026-11-07: "Your chats"
 * showed StackingJones' message, the game's chat was empty). A wrap the signer could not open is retried and never
 * cached as null, the chat reads what the match room and the desk opened on this device, and the game cache's
 * null entries of before are dropped once. The rules run under Node (tests/js/gameChatUnwrap.test.mjs).
 */

test('the game chat retries a wrap its signer could not open and shows what the desk opened', function () {
    nodeTest('tests/js/gameChatUnwrap.test.mjs', 5);
});
