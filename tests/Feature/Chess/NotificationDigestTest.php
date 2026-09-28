<?php

use App\Jobs\PublishNostrEvent;
use App\Jobs\SendNostrDm;
use App\Models\NotificationDigestItem;
use App\Models\User;
use App\Support\Chess\DailyChallenges;
use App\Support\Notifications\DmDigest;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/*
 * P45: per notification kind, the Nostr DM goes out at once (default) or
 * waits for the daily digest, one DM a day that lists everything collected.
 */

beforeEach(function () {
    Queue::fake([SendNostrDm::class, PublishNostrEvent::class]);
    config(['esports.notifications.nsec' => bin2hex(random_bytes(32))]);
});

function digestDmsTo(User $user): array
{
    return Queue::pushed(SendNostrDm::class)->filter(fn (SendNostrDm $job) => $job->user->is($user))->map(fn (SendNostrDm $job) => $job->text)->values()->all();
}

test('by default a challenge DM goes out at once and nothing waits', function () {
    $bert = User::factory()->create();

    app(DailyChallenges::class)->challenge(User::factory()->create(), $bert);

    expect(digestDmsTo($bert))->toHaveCount(1)
        ->and(NotificationDigestItem::query()->count())->toBe(0);
});

test('switched to daily in the settings, challenges wait, and the digest sends them as one DM with the opt-out last', function () {
    $bert = User::factory()->create(['locale' => 'en']);

    Livewire::actingAs($bert)->test('pages::settings.notifications')
        ->assertSeeHtml('data-test="dm-timing-challenge"')
        ->call('setDigest', 'challenge', 'daily')
        ->assertHasNoErrors();
    expect($bert->refresh()->chessSettings()->digestFor('challenge'))->toBeTrue();

    $challenges = app(DailyChallenges::class);
    $challenges->challenge(User::factory()->create(['name' => 'anna']), $bert);
    $challenges->challenge(User::factory()->create(['name' => 'carl']), $bert);

    expect(digestDmsTo($bert))->toBe([])
        ->and(NotificationDigestItem::query()->where('user_id', $bert->id)->count())->toBe(2);

    $this->artisan('notifications:dm-digest')->assertSuccessful();

    [$text] = digestDmsTo($bert);
    $lines = explode("\n", $text);

    expect(digestDmsTo($bert))->toHaveCount(1)
        ->and($lines[0])->toBe('Your TWENTY ONE esports digest: 2 notifications')
        ->and($text)->toContain('anna')->toContain('carl')
        ->and(end($lines))->toStartWith('Turn off these DMs: ')
        ->and(NotificationDigestItem::query()->count())->toBe(0);

    // Nothing left: the next run sends nothing.
    Queue::fake([SendNostrDm::class]);
    $this->artisan('notifications:dm-digest')->assertSuccessful();
    expect(digestDmsTo($bert))->toBe([]);
});

test('an item whose kind was switched off, or whose DMs were switched off, is dropped at digest time', function () {
    $off = User::factory()->create(['chess_settings' => ['digest' => ['challenge' => true]]]);
    $noDm = User::factory()->create(['chess_settings' => ['digest' => ['challenge' => true]]]);
    $challenges = app(DailyChallenges::class);
    $challenges->challenge(User::factory()->create(), $off);
    $challenges->challenge(User::factory()->create(), $noDm);

    $off->forceFill(['chess_settings' => [...$off->chessSettings()->toArray(), 'triggers' => ['challenge' => false]]])->save();
    $noDm->forceFill(['chess_settings' => [...$noDm->chessSettings()->toArray(), 'dm' => false]])->save();

    expect(app(DmDigest::class)->run())->toBe(0)
        ->and(digestDmsTo($off))->toBe([])->and(digestDmsTo($noDm))->toBe([])
        ->and(NotificationDigestItem::query()->count())->toBe(0);
});

test('switched back to at once, what already waits still goes in the last digest', function () {
    $bert = User::factory()->create(['chess_settings' => ['digest' => ['challenge' => true]]]);
    app(DailyChallenges::class)->challenge(User::factory()->create(), $bert);

    Livewire::actingAs($bert)->test('pages::settings.notifications')->call('setDigest', 'challenge', 'instant');
    expect($bert->refresh()->chessSettings()->digestFor('challenge'))->toBeFalse();

    expect(app(DmDigest::class)->run())->toBe(1)->and(digestDmsTo($bert))->toHaveCount(1);
});

test('more than fifteen: the newest fifteen, oldest of them first, and a count of the rest; only fifteen rows are loaded', function () {
    $bert = User::factory()->create(['locale' => 'en']);

    foreach (range(1, 18) as $n) {
        NotificationDigestItem::query()->create(['user_id' => $bert->id, 'kind' => 'challenge', 'title' => 'Challenge '.$n, 'body' => 'from someone', 'url' => '/games/'.$n]);
    }

    $loaded = 0;
    NotificationDigestItem::retrieved(function () use (&$loaded): void {
        $loaded++;
    });

    app(DmDigest::class)->run();
    [$text] = digestDmsTo($bert);

    expect(substr_count($text, '• '))->toBe(15)
        ->and(explode("\n", $text)[0])->toBe('Your TWENTY ONE esports digest: 18 notifications')
        ->and($text)->toContain('Challenge 18: from someone')->toContain('Challenge 4: from someone')->not->toContain('Challenge 3:')
        ->and(strpos($text, 'Challenge 4:'))->toBeLessThan(strpos($text, 'Challenge 18:'))
        ->and($text)->toContain('… and 3 more in the bell on the site.')
        ->and($loaded)->toBe(15)
        ->and(NotificationDigestItem::query()->count())->toBe(0);
});

test('one player whose digest fails does not stop the others; their items wait for the next run', function () {
    Exceptions::fake();
    $bad = User::factory()->create();
    $good = User::factory()->create();

    foreach ([$bad, $good] as $user) {
        NotificationDigestItem::query()->create(['user_id' => $user->id, 'kind' => 'challenge', 'title' => 'x', 'body' => 'y', 'url' => '/']);
    }

    User::retrieved(function (User $user) use ($bad): void {
        if ($user->id === $bad->id) {
            throw new RuntimeException('probe: this player breaks');
        }
    });

    expect(app(DmDigest::class)->run())->toBe(1)
        ->and(digestDmsTo($good))->toHaveCount(1)
        ->and(NotificationDigestItem::query()->where('user_id', $bad->id)->count())->toBe(1)
        ->and(NotificationDigestItem::query()->where('user_id', $good->id)->count())->toBe(0);
    Exceptions::assertReported(RuntimeException::class);
});

test('the digest is in the player\'s language', function () {
    $bert = User::factory()->create(['locale' => 'de']);
    NotificationDigestItem::query()->create(['user_id' => $bert->id, 'kind' => 'challenge', 'title' => 'x', 'body' => 'y', 'url' => '/']);

    app(DmDigest::class)->run();

    expect(explode("\n", digestDmsTo($bert)[0])[0])->toBe('Deine TWENTY ONE esports-Zusammenfassung: 1 Benachrichtigung');
});

test('without a notification key nothing is sent, and items older than two days are dropped', function () {
    config(['esports.notifications.nsec' => null]);
    $bert = User::factory()->create();
    $old = NotificationDigestItem::query()->create(['user_id' => $bert->id, 'kind' => 'challenge', 'title' => 'x', 'body' => 'y', 'url' => '/']);
    $old->forceFill(['created_at' => now()->subHours(49)])->save();
    NotificationDigestItem::query()->create(['user_id' => $bert->id, 'kind' => 'challenge', 'title' => 'x', 'body' => 'y', 'url' => '/']);

    expect(app(DmDigest::class)->run())->toBe(0)
        ->and(digestDmsTo($bert))->toBe([])
        ->and(NotificationDigestItem::query()->count())->toBe(1);
});

test('a bad timing or kind is refused', function () {
    $bert = User::factory()->create();

    Livewire::actingAs($bert)->test('pages::settings.notifications')->call('setDigest', 'challenge', 'weekly')->assertStatus(422);
    Livewire::actingAs($bert)->test('pages::settings.notifications')->call('setDigest', 'nope', 'daily')->assertStatus(422);
});
