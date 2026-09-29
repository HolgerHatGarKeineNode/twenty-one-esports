<?php

/*
| A player's name shaped like a domain ("octavia.dickens") stays in the
| notification DM, defused (its dots become middle dots, so no client reads
| a link), instead of vanishing while the bell shows it (P8 review). Links
| of every other form, and a domain in text a player wrote, stay cut.
*/

use App\Jobs\SendNostrDm;
use App\Models\User;
use App\Support\Chess\DailyChallenges;
use App\Support\Notifications\Notice;
use App\Support\Notifications\PlainText;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    Bus::fake([SendNostrDm::class]);
    config(['esports.notifications.nsec' => bin2hex(random_bytes(32))]);
});

test('a dotted name stays in the DM of a daily chess challenge, and a domain in the message stays cut', function () {
    $octavia = User::factory()->create(['name' => 'octavia.dickens', 'chess_settings' => ['dm' => true]]);
    $bert = User::factory()->create(['name' => 'bert', 'chess_settings' => ['dm' => true]]);
    $challenges = app(DailyChallenges::class);

    $challenge = $challenges->challenge($octavia, $bert, 'white', 'see evil.example now');
    $challenges->accept($challenge, $bert);
    $bert->refresh();
    $challenges->accept($challenges->challenge($bert, $octavia), $octavia);

    $dms = Bus::dispatched(SendNostrDm::class)->map(fn (SendNostrDm $job) => [$job->user->name, strtok($job->text, "\n")."\n".explode("\n", $job->text)[1]])->values()->all();

    expect($dms[0][0])->toBe('bert')
        ->and($dms[0][1])->toContain("octavia\u{00B7}dickens challenges you to daily chess")
        ->and($dms[0][1])->toContain('"see now"')
        ->and(collect($dms)->firstWhere(0, 'bert')[1])->not->toContain('evil')
        // The accept is news, not a task: the bell only, no DM (NotificationKind::dmAllowed()).
        ->and(collect($dms)->contains(fn (array $dm) => str_contains($dm[1], 'accepted your daily challenge')))->toBeFalse()
        ->and($bert->notifications()->where('type', 'game_started')->sole()->data['title'])->toBe('octavia.dickens accepted your daily challenge')
        // The bell keeps the name as it is.
        ->and($bert->notifications()->where('type', 'challenge')->sole()->data['title'])->toBe('octavia.dickens challenges you to daily chess');
});

test('the notice text defuses a bare domain and still cuts every other link form', function () {
    $text = (new Notice('octavia.dickens and www.evil.example', 'https://evil.example/x nostr:npub1xyz 203.0.113.7 1.e4 2.Nf3', 'https://esports.test/board/1'))->toDmText();

    expect(explode("\n", $text)[0])->toBe("octavia\u{00B7}dickens and")
        ->and(explode("\n", $text)[1])->toBe('1.e4 2.Nf3')
        // Without `names` (a player's own message) a domain is cut as before.
        ->and(PlainText::line('candido.hintz challenges you'))->toBe('challenges you')
        ->and(PlainText::line('candido.hintz challenges you', names: true))->toBe("candido\u{00B7}hintz challenges you");
});
