<?php

/*
 * TMNF in the stream chat (kind 1311, StreamBotBuilders): the running week
 * with its track, our server and how to join; a new best time of the week
 * with its driver tagged; the finished week's podium, every driver tagged.
 * Drivers are named by their Nostr key or league name, never by a TMNF
 * login; no `#` (the favourite link `tmtp://#addfavourite=…` would render as
 * a hashtag in zap.stream), the week page carries the join steps instead.
 */

use App\Games\GameRegistry;
use App\Models\User;
use App\Support\Nostr\NostrKeys;
use App\Support\Scores\ScoreLeaderboards;
use App\Support\StreamBot\StreamBotBuilders;
use App\Support\StreamBot\StreamBotCopy;
use App\Support\StreamBot\StreamBotMessage;
use App\Support\Tmnf\TmnfWeeks;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TestSigner;

beforeEach(function () {
    Queue::fake();
    Cache::flush();
    tmnfOn();
    config([
        'esports.league.nsec' => (new TestSigner)->secret,
        'esports.tmnf.server.address' => 'tmnf.example.org:2350',
        'esports.tmnf.server.login' => 'twentyone_srv',
        'esports.tmnf.server.name' => 'TWENTY ONE',
    ]);
    // A Wednesday noon of week 41 (Monday 2026-10-05 to Monday 2026-10-12, Berlin).
    $this->travelTo(CarbonImmutable::parse('2026-10-07 12:00:00'));
    $this->builders = app(StreamBotBuilders::class);
    $this->builders->pickVariantsWith(fn (int $variants): int => 0);
});

/**
 * @return list<StreamBotMessage>
 */
function tmnfChat(string $builder): array
{
    return test()->builders->build($builder, CarbonImmutable::now());
}

function tmnfChatNpub(User $user): string
{
    return 'nostr:'.NostrKeys::hexToNpub($user->pubkey);
}

/** Every chat rule at once: the copy rules, no login, no fee wording. */
function tmnfChatClean(StreamBotMessage $message): void
{
    $words = mb_strtolower((string) preg_replace('~nostr:\S+~', '', $message->content));

    expect(StreamBotCopy::violations($message->content, $message->tags))->toBe([])
        // The link ends its line: a client would take trailing characters into the URL.
        ->and($message->content)->toMatch('~https?://\S+(\n|$)~')
        ->and(collect(explode("\n", $message->content))->every(fn (string $line): bool => preg_match('/^[^\p{L}\p{N}\s]/u', $line) === 1))->toBeTrue()
        ->and($message->content)->not->toContain('_drives')->not->toContain('twentyone_srv')->not->toContain('tmtp')
        ->and($words)->not->toContain('fee')->not->toContain('face');
}

test('the running week: its track, our server, the end and the week page with the join steps', function () {
    $week = app(TmnfWeeks::class)->open();

    [$message] = tmnfChat('tmnf_week');
    tmnfChatClean($message);

    expect($message->builder)->toBe('tmnf_week')
        ->and($message->factKey)->toBe('tmnf-week:'.$week->id)
        ->and($message->content)->toContain('A01-Race')->toContain('TWENTY ONE')->toContain('Mon, 12 Oct 2026')
        ->and($message->content)->toEndWith(route('tournaments.show', $week));
});

test('a new best time of the week tags its driver, with the time, the track and how much faster it is', function () {
    $ada = tmnfPlayer('ada_drives', linked: true, attributes: ['name' => 'Ada']);
    $ben = tmnfPlayer('ben_drives', linked: true, attributes: ['name' => 'Ben']);
    tmnfFinish('ada_drives', 25_400);
    $this->travel(10)->minutes();
    $run = tmnfFinish('ben_drives', 25_100);

    [$message] = tmnfChat('tmnf_top');
    tmnfChatClean($message);

    expect($message->factKey)->toBe('tmnf-top:'.$run->id)
        ->and($message->content)->toContain(tmnfChatNpub($ben))->toContain('0:25.100')->toContain('A01-Race')->toContain('0.300 s faster')
        ->and($message->content)->not->toContain(tmnfChatNpub($ada))
        ->and($message->tags)->toBe([['p', $ben->pubkey]]);

    // Old news: a best time from more than a few hours ago is not told again.
    $this->travel(7)->hours();
    expect(tmnfChat('tmnf_top'))->toBe([]);
});

test('the finished week\'s podium tags its three drivers in their places, for a day after the end', function () {
    $users = [];

    foreach (['Ada' => 25_100, 'Ben' => 25_400, 'Cy' => 25_900, 'Dee' => 26_000] as $name => $ms) {
        $users[$name] = tmnfPlayer(strtolower($name).'_drives', linked: true, attributes: ['name' => $name]);
        tmnfFinish(strtolower($name).'_drives', $ms);
    }

    $week = app(TmnfWeeks::class)->current();
    expect(tmnfChat('tmnf_podium'))->toBe([]);

    // The score kind ends the week after its review time.
    $this->travelTo(CarbonImmutable::parse('2026-10-12 23:30:00'));
    app(ScoreLeaderboards::class)->tick();

    [$message] = tmnfChat('tmnf_podium');
    tmnfChatClean($message);

    expect($message->factKey)->toBe('tmnf-podium:'.$week->id)
        ->and($message->content)->toContain('TMNF Week 41, 2026')
        ->and($message->content)->toMatch('/🥇 '.preg_quote(tmnfChatNpub($users['Ada']), '/').' 0:25\.100 · 🥈 '.preg_quote(tmnfChatNpub($users['Ben']), '/').' 0:25\.400 · 🥉 '.preg_quote(tmnfChatNpub($users['Cy']), '/').' 0:25\.900/')
        ->and($message->content)->not->toContain(tmnfChatNpub($users['Dee']))
        ->and($message->tags)->toBe([['p', $users['Ada']->pubkey], ['p', $users['Ben']->pubkey], ['p', $users['Cy']->pubkey]]);

    $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00:00'));
    expect(tmnfChat('tmnf_podium'))->toBe([]);
});

test('every TMNF chat message keeps the copy rules in each wording', function (int $variant) {
    $this->builders->pickVariantsWith(fn (int $variants): int => $variant % $variants);
    tmnfPlayer('ada_drives', linked: true, attributes: ['name' => 'Ada']);
    tmnfFinish('ada_drives', 25_100);

    $messages = [...tmnfChat('tmnf_week'), ...tmnfChat('tmnf_top')];

    $this->travelTo(CarbonImmutable::parse('2026-10-12 23:30:00'));
    app(ScoreLeaderboards::class)->tick();
    $messages = [...$messages, ...tmnfChat('tmnf_podium')];

    expect($messages)->toHaveCount(3);

    foreach ($messages as $message) {
        tmnfChatClean($message);
        expect(count(explode("\n", $message->content)))->toBeLessThanOrEqual(StreamBotCopy::MAX_LINES);
    }
})->with([0, 1]);

test('with TMNF switched off the chat says nothing about it', function () {
    tmnfPlayer('ada_drives', linked: true, attributes: ['name' => 'Ada']);
    tmnfFinish('ada_drives', 25_100);
    config(['esports.tmnf.enabled' => false]);
    app()->forgetInstance(GameRegistry::class);
    app()->forgetInstance(StreamBotBuilders::class);
    $this->builders = app(StreamBotBuilders::class);

    expect(tmnfChat('tmnf_week'))->toBe([])
        ->and(tmnfChat('tmnf_top'))->toBe([])
        ->and(tmnfChat('tmnf_podium'))->toBe([]);
});
