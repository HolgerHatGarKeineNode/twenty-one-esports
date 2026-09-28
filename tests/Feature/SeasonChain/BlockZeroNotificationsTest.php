<?php

/*
 * "Notify me at Block 0" (P34): every player who asked hears once when a
 * planned Block 0 date is set, and once when the board releases Block 0,
 * over the bell and a Nostr DM (on by default for this kind). Running either
 * again sends nothing.
 */

use App\Enums\NotificationKind;
use App\Jobs\NotifyBlockZero;
use App\Jobs\PublishNostrEvent;
use App\Jobs\SendNostrDm;
use App\Jobs\SendWebPush;
use App\Models\User;
use App\Support\Nostr\NostrKeys;
use App\Support\Notifications\BlockZeroNotifications;
use App\Support\SeasonChain\SeasonRelease;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TestSigner;

beforeEach(function () {
    $this->freezeTime();
    Queue::fake([SendNostrDm::class, SendWebPush::class, PublishNostrEvent::class]);
    config(['esports.notifications.nsec' => bin2hex(random_bytes(32)), 'esports.preseason.block0_at' => null]);
});

/**
 * @return list<string> the DM texts queued for this user
 */
function blockZeroDmsTo(User $user): array
{
    return Queue::pushed(SendNostrDm::class)->filter(fn (SendNostrDm $job) => $job->user->is($user))->map(fn (SendNostrDm $job) => $job->text)->values()->all();
}

/**
 * @return list<string> the bell titles of this user, oldest first
 */
function blockZeroBell(User $user): array
{
    return $user->notifications()->oldest()->get()
        ->filter(fn ($notification) => ($notification->data['kind'] ?? null) === NotificationKind::BlockZero->value)
        ->map(fn ($notification) => (string) $notification->data['title'])->values()->all();
}

/** Release Block 0 the way the admin page does: prepare, sign, release. */
function releaseBlockZeroForTest(): void
{
    $league = new TestSigner;
    $boardSigner = new TestSigner;
    config(['esports.league.nsec' => $league->secret, 'esports.trust.nsec' => (new TestSigner)->secret]);
    $board = User::factory()->withPubkey($boardSigner->pubkey)->create();
    config(['esports.board' => [NostrKeys::hexToNpub($board->pubkey)]]);

    $release = app(SeasonRelease::class);
    $endsAt = SeasonRelease::plannedEnd(CarbonImmutable::now());
    $template = $release->prepare($board, '2100000', 'Block 0 of the test season', $endsAt);
    $release->release($board, '2100000', 'Block 0 of the test season', $endsAt, $boardSigner->signTemplates([$template]));
}

test('releasing Block 0 tells every player who asked, once, on the bell and by DM, and nobody else', function () {
    $asked = User::factory()->create(['locale' => 'en']);
    $german = User::factory()->create(['locale' => 'de']);
    $silent = User::factory()->create();
    User::query()->whereKey([$asked->id, $german->id])->update(['notify_block0_at' => now()->subDay()]);

    releaseBlockZeroForTest();

    expect(blockZeroBell($asked))->toBe(['Block 0 is released'])
        ->and(blockZeroBell($german))->toBe(['Block 0 ist freigegeben'])
        ->and(blockZeroBell($silent))->toBe([])
        ->and(blockZeroDmsTo($asked))->toHaveCount(1)
        ->and(blockZeroDmsTo($asked)[0])->toContain('rated play and mining are open')
        ->and(blockZeroDmsTo($silent))->toBe([])
        ->and($asked->fresh()->block0_notified_at)->not->toBeNull();

    // A second run (a retried job, a second dispatch) tells nobody again.
    (new NotifyBlockZero(NotifyBlockZero::RELEASED))->handle(app(BlockZeroNotifications::class));

    expect(blockZeroBell($asked))->toHaveCount(1)
        ->and(blockZeroDmsTo($asked))->toHaveCount(1);
});

test('the release reaches every player across chunks, and nothing goes out before a season exists', function () {
    $players = User::factory()->count(BlockZeroNotifications::CHUNK + 5)->create(['notify_block0_at' => now()->subHour()]);

    expect(app(BlockZeroNotifications::class)->released())->toBe(0)
        ->and(User::query()->whereNotNull('block0_notified_at')->count())->toBe(0);

    releaseBlockZeroForTest();

    expect(User::query()->whereKey($players->modelKeys())->whereNotNull('block0_notified_at')->count())->toBe($players->count())
        ->and(Queue::pushed(SendNostrDm::class)->count())->toBe($players->count());
});

test('a planned Block 0 date is told once to each player who asked, in their zone, and again only when it moves', function () {
    $at = CarbonImmutable::parse('2026-10-03 16:00:00', 'UTC');
    config(['esports.preseason.block0_at' => $at->toIso8601String()]);
    $this->travelTo($at->subDays(4));

    $berlin = User::factory()->create(['locale' => 'en', 'timezone' => null, 'notify_block0_at' => now()]);
    $newYork = User::factory()->create(['locale' => 'en', 'timezone' => 'America/New_York', 'notify_block0_at' => now()]);
    $silent = User::factory()->create();

    $this->artisan('esports:block0-heads-up')->expectsOutput('Queued the Block 0 date heads-up.')->assertSuccessful();

    expect(blockZeroBell($berlin))->toBe(['Block 0 is on Sat 3 Oct, 18:00 CEST'])
        ->and(blockZeroBell($newYork))->toBe(['Block 0 is on Sat 3 Oct, 12:00 EDT'])
        ->and(blockZeroBell($silent))->toBe([])
        ->and(blockZeroDmsTo($berlin))->toHaveCount(1);

    // Nothing new on the next run.
    $this->artisan('esports:block0-heads-up')->expectsOutput('Nobody waits for a Block 0 date.')->assertSuccessful();
    expect(blockZeroBell($berlin))->toHaveCount(1);

    // The board moves the date: a new heads-up.
    config(['esports.preseason.block0_at' => $at->addDay()->toIso8601String()]);
    $this->artisan('esports:block0-heads-up')->assertSuccessful();

    expect(blockZeroBell($berlin))->toBe(['Block 0 is on Sat 3 Oct, 18:00 CEST', 'Block 0 is on Sun 4 Oct, 18:00 CEST']);
});

test('a player who asks while the date is on the page is not told that date again', function () {
    config(['esports.preseason.block0_at' => now()->addDays(2)->toIso8601String()]);
    $player = User::factory()->create();

    $this->actingAs($player)->post(route('notify.block0'))->assertRedirect();

    expect(app(BlockZeroNotifications::class)->datedPending())->toBeFalse()
        ->and(app(BlockZeroNotifications::class)->dated())->toBe(0)
        ->and(blockZeroBell($player))->toBe([]);
});

test('no date heads-up without a date, once the date has passed, or after the release', function () {
    $player = User::factory()->create(['notify_block0_at' => now()]);
    $notifications = app(BlockZeroNotifications::class);

    expect($notifications->dated())->toBe(0);

    config(['esports.preseason.block0_at' => now()->subMinute()->toIso8601String()]);
    expect($notifications->datedPending())->toBeFalse()
        ->and($notifications->dated())->toBe(0);

    releaseBlockZeroForTest();
    config(['esports.preseason.block0_at' => now()->addDay()->toIso8601String()]);

    expect($notifications->datedPending())->toBeFalse()
        ->and($notifications->dated())->toBe(0)
        ->and(blockZeroBell($player))->toBe(['Block 0 is released']);
});

test('a player a concurrent run claimed between the read and the claim is not told twice', function () {
    releaseBlockZeroForTest();
    $player = User::factory()->create(['notify_block0_at' => now()]);

    // The rival run claims the player right after this run read the id.
    User::retrieved(function (User $user) use ($player): void {
        if ($user->getKey() === $player->id && $user->block0_notified_at === null) {
            User::query()->whereKey($player->id)->update(['block0_notified_at' => now()]);
        }
    });

    expect(app(BlockZeroNotifications::class)->released())->toBe(0)
        ->and(blockZeroBell($player))->toBe([]);
});
