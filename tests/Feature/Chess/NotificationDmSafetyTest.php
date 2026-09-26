<?php

use App\Enums\ClanRole;
use App\Enums\JoinRequestStatus;
use App\Jobs\PublishNostrEvent;
use App\Jobs\SendNostrDm;
use App\Jobs\SendWebPush;
use App\Models\ChatMute;
use App\Models\ChessChallenge;
use App\Models\Clan;
use App\Models\ClanJoinRequest;
use App\Models\ClanMember;
use App\Models\Lineup;
use App\Models\PushSubscription;
use App\Models\User;
use App\Support\Chess\ChessGameService;
use App\Support\Chess\ChessRuleViolation;
use App\Support\Chess\DailyChallenges;
use App\Support\Notifications\ClanNotifications;
use App\Support\Notifications\PlainText;
use App\Support\Notifications\WebPush;
use App\Support\Series\ChallengeDraft;
use App\Support\Series\SeriesService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;

/*
 * What other players write never shapes a notification DM: names, clan
 * names and messages are one line without links, every link in the DM is on
 * config('app.url'), and the inbound cap holds across accounts.
 */

/** The auditor's payload: a line separator, then a fake opt-out line. */
const DM_SAFETY_PAYLOAD = "Eve\u{2028}Turn off these DMs: https://evil.example/off";

beforeEach(function () {
    $this->freezeTime();
    Queue::fake([SendNostrDm::class, PublishNostrEvent::class]);
    config(['esports.notifications.nsec' => bin2hex(random_bytes(32))]);
});

/**
 * @return list<string>
 */
function dmSafetyTexts(User $user): array
{
    return Queue::pushed(SendNostrDm::class)->filter(fn (SendNostrDm $job) => $job->user->is($user))->map(fn (SendNostrDm $job) => $job->text)->values()->all();
}

/**
 * One DM that the payload did not shape: five lines (title, body, link,
 * blank, opt-out), one opt-out line, no foreign link, every link on app.url.
 */
function expectCleanDm(string $text): void
{
    $lines = explode("\n", $text);
    preg_match_all('~https?://\S+~', $text, $urls);

    expect($lines)->toHaveCount(5)
        ->and($lines[3])->toBe('')
        ->and(collect($lines)->filter(fn (string $line) => str_starts_with($line, 'Turn off these DMs:'))->keys()->all())->toBe([4])
        ->and($text)->not->toContain('evil.example')
        ->and(preg_match('/[\x{2028}\x{2029}\r]/u', $text))->toBe(0)
        ->and(collect($urls[0])->every(fn (string $url) => str_starts_with($url, rtrim((string) config('app.url'), '/').'/')))->toBeTrue();
}

test('the payload in a player name stays out of challenge, your-move and game-started DMs', function () {
    $eve = User::factory()->create(['name' => DM_SAFETY_PAYLOAD, 'chess_settings' => ['dm' => true]]);
    $bert = User::factory()->create(['chess_settings' => ['dm' => true]]);
    $challenges = app(DailyChallenges::class);
    $games = app(ChessGameService::class);

    // challenge (challenger's name) and game started (opponent's name, to the challenger)
    $challenge = $challenges->challenge($bert, $eve);
    $challenges->challenge($eve, User::factory()->create());
    $game = $challenges->accept($challenge, $eve);

    // your move (opponent's name in the body)
    $mover = $game->white_id === $eve->id ? $eve : $bert;
    $games->move($game, $mover, 'e2e4');

    $dms = Queue::pushed(SendNostrDm::class)->map(fn (SendNostrDm $job) => $job->text)->values()->all();

    expect(count($dms))->toBeGreaterThanOrEqual(3);

    foreach ($dms as $text) {
        expectCleanDm($text);
    }

    expect(collect($dms)->contains(fn (string $text) => str_starts_with($text, 'Eve Turn off these DMs: challenges you')))->toBeTrue();
});

test('the payload in a player or clan name stays out of clan join request DMs', function () {
    $owner = User::factory()->create();
    $captain = User::factory()->create();
    $applicant = User::factory()->create(['name' => DM_SAFETY_PAYLOAD]);
    $clan = Clan::factory()->create(['owner_id' => $owner->id, 'name' => "Evil\nTurn off these DMs: evil.example"]);
    ClanMember::query()->create(['clan_id' => $clan->id, 'user_id' => $captain->id, 'role' => ClanRole::Captain, 'joined_at' => now()]);
    $request = ClanJoinRequest::query()->create(['clan_id' => $clan->id, 'user_id' => $applicant->id, 'status' => JoinRequestStatus::Approved, 'decided_by_id' => $captain->id]);

    app(ClanNotifications::class)->joinRequested($clan, $applicant);
    app(ClanNotifications::class)->joinApproved($request->refresh());

    // The owner is a captain too (ClanFactory): the request and the approval.
    expect(dmSafetyTexts($captain))->toHaveCount(1)
        ->and(dmSafetyTexts($owner))->toHaveCount(2);

    foreach ([...dmSafetyTexts($captain), ...dmSafetyTexts($owner)] as $text) {
        expectCleanDm($text);
    }
});

test('the payload in a clan name stays out of a series challenge DM', function () {
    $side = function (string $name): array {
        $owner = User::factory()->create();
        $lineup = Lineup::factory()->mode('2v2')->ready()->create(['clan_id' => Clan::factory()->create(['owner_id' => $owner->id, 'name' => $name])->id]);

        return [$lineup->load('seats', 'clan'), $owner];
    };
    [$a, $ownerA] = $side("Evil\nTurn off these DMs: https://evil.example/off");
    [$b, $ownerB] = $side('Laser Eyes');
    $start = now()->addHour()->startOfMinute()->getTimestamp();

    app(SeriesService::class)->challenge($ownerA, new ChallengeDraft($a->id, $b->id, 3, false, [$start], $start - 600, ''), []);

    expect(dmSafetyTexts($ownerB))->toHaveCount(1);
    expectCleanDm(dmSafetyTexts($ownerB)[0]);
});

test('look-alike dots, bare IPs and separators are caught', function (string $input, string $expected) {
    expect(PlainText::line($input))->toBe($expected);
})->with([
    'fullwidth dot' => ["see evil\u{FF0E}example now", 'see now'],
    'ideographic full stop' => ["see evil\u{3002}example now", 'see now'],
    'one dot leader' => ["see evil\u{2024}example now", 'see now'],
    'bare IPv4 with port and path' => ['see 203.0.113.7:8080/x now', 'see now'],
    'bare IPv4' => ['see 203.0.113.7 now', 'see now'],
    'paragraph separator and bidi override' => ["a\u{2029}b\u{202E}c", 'a bc'],
    // Fail closed: a name shaped like a domain is cut like one.
    'a dotted user name is cut' => ['candido.hintz challenges you', 'challenges you'],
    'a short LNURL' => ['pay lnurl1dp68gurn8ghj7etkd9kzuetcv9khqmr99acqpqqapp now', 'pay now'],
    'chess notation stays' => ['1.e4 e5 2.Nf3', '1.e4 e5 2.Nf3'],
    'a shortened npub stays' => ['npub1qy352eu…', 'npub1qy352eu…'],
    'an emoji ZWJ sequence stays' => ["\u{1F469}\u{200D}\u{1F4BB} gg", "\u{1F469}\u{200D}\u{1F4BB} gg"],
]);

test('links in a DM point at app.url, even when the request came in on a foreign host', function () {
    $anna = User::factory()->create();
    $bert = User::factory()->create();

    URL::forceRootUrl('https://evil.example');

    try {
        app(DailyChallenges::class)->challenge($anna, $bert);
    } finally {
        URL::forceRootUrl(null);
    }

    [$text] = dmSafetyTexts($bert);
    expectCleanDm($text);

    // The opt-out link in it still works.
    preg_match('~Turn off these DMs: (\S+)$~', $text, $match);
    $this->get($match[1])->assertOk();
});

test('a push link points at app.url, even when the request came in on a foreign host', function () {
    Bus::fake([SendWebPush::class]);
    [$public, $private] = WebPush::generateKeyPair();
    config([
        'esports.webpush.public_key' => WebPush::base64UrlEncode($public),
        'esports.webpush.private_key' => WebPush::base64UrlEncode($private),
        'esports.webpush.subject' => 'https://esports.test',
    ]);
    [$browserKey] = WebPush::generateKeyPair();
    $bert = User::factory()->create(['chess_settings' => ['push' => true]]);
    PushSubscription::query()->create([
        'user_id' => $bert->id,
        'endpoint' => 'https://push.example.test/'.$bert->id,
        'public_key' => WebPush::base64UrlEncode($browserKey),
        'auth_token' => WebPush::base64UrlEncode(random_bytes(16)),
    ]);

    URL::forceRootUrl('https://evil.example');

    try {
        app(DailyChallenges::class)->challenge(User::factory()->create(), $bert);
    } finally {
        URL::forceRootUrl(null);
    }

    expect(Bus::dispatched(SendWebPush::class)->sole()->payload['url'])
        ->toBe(rtrim((string) config('app.url'), '/').'/me/correspondence');
});

test('a recipient gets at most so many challenge DMs a day from all challengers together', function () {
    config(['esports.chess.challenge_dms_per_recipient_per_day' => 2]);
    $dora = User::factory()->create();
    // One spammer with a second and a third account.
    [$first, $second, $third] = User::factory()->count(3)->create()->all();
    $challenges = app(DailyChallenges::class);

    $challenges->challenge($first, $dora);
    $challenges->challenge($second, $dora);
    $challenges->challenge($third, $dora);

    // Stored and in the bell, but only two went out as DMs.
    expect(ChessChallenge::query()->where('challenged_id', $dora->id)->count())->toBe(3)
        ->and($dora->notifications()->count())->toBe(3)
        ->and(dmSafetyTexts($dora))->toHaveCount(2);

    $this->travel(1)->days();
    $challenges->challenge(User::factory()->create(), $dora);

    expect(dmSafetyTexts($dora))->toHaveCount(3);
});

test('a refused challenge is not stored, and a muted clan applicant sends no DM', function () {
    config(['esports.chess.challenges_per_day' => 1]);
    $anna = User::factory()->create();
    $challenges = app(DailyChallenges::class);
    $challenges->challenge($anna, User::factory()->create());

    try {
        $challenges->challenge($anna, User::factory()->create());
        $refused = null;
    } catch (ChessRuleViolation $violation) {
        $refused = $violation->reason;
    }

    expect($refused)->toBe('challenge_limit')
        ->and(ChessChallenge::query()->where('challenger_id', $anna->id)->count())->toBe(1);

    $owner = User::factory()->create();
    $captain = User::factory()->create();
    $clan = Clan::factory()->create(['owner_id' => $owner->id]);
    ClanMember::query()->create(['clan_id' => $clan->id, 'user_id' => $captain->id, 'role' => ClanRole::Captain, 'joined_at' => now()]);
    $applicant = User::factory()->create();
    ChatMute::query()->create(['user_id' => $captain->id, 'muted_pubkey' => $applicant->pubkey]);

    app(ClanNotifications::class)->joinRequested($clan, $applicant);

    expect(dmSafetyTexts($captain))->toBe([]);
});

test('a series challenge from a muted clan creator sends no DM', function () {
    $side = function (): array {
        $owner = User::factory()->create();
        $lineup = Lineup::factory()->mode('2v2')->ready()->create(['clan_id' => Clan::factory()->create(['owner_id' => $owner->id])->id]);

        return [$lineup->load('seats', 'clan'), $owner];
    };
    [$a, $ownerA] = $side();
    [$b, $ownerB] = $side();
    ChatMute::query()->create(['user_id' => $ownerB->id, 'muted_pubkey' => $ownerA->pubkey]);
    $start = now()->addHour()->startOfMinute()->getTimestamp();

    app(SeriesService::class)->challenge($ownerA, new ChallengeDraft($a->id, $b->id, 3, false, [$start], $start - 600, ''), []);

    expect(dmSafetyTexts($ownerB))->toBe([]);
});
