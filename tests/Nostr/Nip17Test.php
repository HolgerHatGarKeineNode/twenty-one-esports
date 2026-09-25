<?php

use App\Support\Nostr\NostrKeys;
use App\Support\Notifications\NotificationDm;
use Illuminate\Support\Facades\Process;
use swentel\nostr\Key\Key;

/*
 * The game chat's NIP-17 code lives in the browser (resources/js/nostrChat.js),
 * so it is tested where it runs: tests/js/nip17.test.mjs under Node, with
 * throwaway keys. This test feeds it what PHP makes (a notification DM from
 * the notification key, an nevent), so each side checks the other, and fails
 * if any Node test fails or is skipped.
 */
test('the chat module wraps, unwraps, checks the sender, hides muted senders, and opens the PHP notification DM', function () {
    $recipientSecret = bin2hex(random_bytes(32));
    $recipient = (new Key)->getPublicKey($recipientSecret);
    $dm = new NotificationDm(bin2hex(random_bytes(32)));
    $text = "Your move in daily chess #12\nanna played 1. e4.\nhttps://esports.test/games/12";
    $eventId = hash('sha256', 'probe');

    $php = [
        'recipientSecret' => $recipientSecret,
        'recipient' => $recipient,
        'notificationPubkey' => $dm->pubkey(),
        'text' => $text,
        'match' => 12,
        'wrap' => $dm->build($recipient, $text, 12)['wrap'],
        'nevent' => NostrKeys::nevent($eventId, $recipient, 64),
        'neventParts' => ['id' => $eventId, 'author' => $recipient, 'kind' => 64],
    ];

    $run = Process::path(base_path())
        ->env(['NIP17_PHP' => json_encode($php, JSON_UNESCAPED_SLASHES)])
        ->timeout(60)
        ->run(['node', '--test', 'tests/js/nip17.test.mjs']);

    expect($run->successful())->toBeTrue($run->output().$run->errorOutput())
        ->and($run->output())->toContain('ℹ pass 6')->toContain('ℹ skipped 0');
});
