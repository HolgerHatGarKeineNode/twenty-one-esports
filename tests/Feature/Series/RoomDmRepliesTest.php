<?php

use App\Models\ChessGame;
use App\Models\Lineup;
use App\Models\SeriesMatch;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

/*
 * Replies from other NIP-17 clients (Amethyst, 0xchat, Coracle …) in the
 * match room and the game chat: read on the player's own DM relays (10050),
 * wraps to each recipient's DM relays, and an untagged reply shown only
 * when it belongs to the room. The rules run under Node
 * (tests/js/dmReplies.test.mjs); the end-to-end path over two relays is in
 * tests/Browser/ChatAndDailyTest.php.
 */

test('replies from other NIP-17 clients reach the room and the game chat, and unrelated DMs stay out', function () {
    $run = Process::path(base_path())->timeout(60)->run(['node', '--test', 'tests/js/dmReplies.test.mjs']);

    expect($run->successful())->toBeTrue($run->output().$run->errorOutput())
        ->and($run->output())->toContain('ℹ pass 11')->toContain('ℹ skipped 0');
});

test('the room and the game chat get the window\'s end and the relays to look up DM relay lists on', function () {
    config(['esports.chat.relays' => ['wss://chat.example'], 'esports.profile_relays' => ['wss://index.example']]);

    $match = SeriesMatch::factory()->accepted()->create([
        'challenger_lineup_id' => Lineup::factory()->mode('2v2')->ready()->create()->id,
        'challenged_lineup_id' => Lineup::factory()->mode('2v2')->ready()->create()->id,
        'finished_at' => now()->subHour(),
    ]);
    $room = Livewire::actingAs($match->challengerLineup->clan->owner)->test('pages::matches.room', ['match' => $match]);

    expect($room->instance()->chatConfig())->toMatchArray([
        'since' => $match->created_at->getTimestamp(),
        'settled' => $match->finished_at->getTimestamp(),
        'lookupRelays' => ['wss://index.example', 'wss://chat.example'],
    ]);

    $game = ChessGame::factory()->create(['ended_at' => now()->subMinutes(5)]);
    $board = Livewire::actingAs($game->white)->test('pages::games.show', ['game' => $game]);

    expect($board->instance()->chatConfig())->toMatchArray([
        'settled' => $game->ended_at->getTimestamp(),
        'lookupRelays' => ['wss://index.example', 'wss://chat.example'],
    ]);
});
