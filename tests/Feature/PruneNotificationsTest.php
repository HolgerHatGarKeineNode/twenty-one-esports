<?php

use App\Models\BellNotification;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Pruning the bell and the failed jobs (performance plan P2, S11)
|--------------------------------------------------------------------------
|
| A notification goes once it is read, older than 90 days and not among the
| player's newest 20 (the bell's list); unread ones stay at any age. Failed
| jobs go after 90 days. nostr_events and relay_deliveries are not pruned
| (routes/console.php says why).
|
*/

/** A bell notification of `$user`, `$days` old, read or not. */
function bellNotice(User $user, int $days, bool $read): string
{
    $id = (string) Str::uuid();
    DB::table('notifications')->insert([
        'id' => $id, 'type' => 'App\\Notifications\\LeagueNotice', 'notifiable_type' => User::class, 'notifiable_id' => $user->id,
        'data' => json_encode(['title' => 'x']), 'read_at' => $read ? now()->subDays($days) : null,
        'created_at' => now()->subDays($days), 'updated_at' => now()->subDays($days),
    ]);

    return $id;
}

test('model:prune removes read notifications older than 90 days beyond each player\'s newest 20, and nothing else', function () {
    [$busy, $quiet] = User::factory()->count(2)->create();
    // The busy player: 20 recent notices fill the bell; behind them old read, old unread and a read one of 89 days.
    foreach (range(1, 20) as $day) {
        bellNotice($busy, $day, true);
    }
    $oldRead = [bellNotice($busy, 91, true), bellNotice($busy, 200, true)];
    $oldUnread = bellNotice($busy, 300, false);
    $youngRead = bellNotice($busy, 89, true);
    // The quiet player: three old read notices, all still in the bell's list.
    $quietOnes = [bellNotice($quiet, 120, true), bellNotice($quiet, 150, true), bellNotice($quiet, 400, true)];

    $this->artisan('model:prune', ['--model' => [BellNotification::class]])->assertSuccessful();

    $left = DB::table('notifications')->pluck('id')->all();
    expect($left)->toHaveCount(20 + 2 + 3)
        ->not->toContain($oldRead[0])->not->toContain($oldRead[1])
        ->toContain($oldUnread)->toContain($youngRead)
        ->and(array_intersect($quietOnes, $left))->toHaveCount(3);
});

test('the scheduler prunes the bell and the failed jobs daily, and leaves the Nostr events and relay answers alone', function () {
    $commands = collect(app(Schedule::class)->events())->map(fn ($event): string => (string) $event->command);

    expect($commands->contains(fn (string $command): bool => str_contains($command, 'model:prune') && str_contains($command, 'BellNotification')))->toBeTrue()
        ->and($commands->contains(fn (string $command): bool => str_contains($command, 'queue:prune-failed --hours=2160')))->toBeTrue()
        ->and($commands->contains(fn (string $command): bool => str_contains($command, 'NostrEvent') || str_contains($command, 'RelayDelivery')))->toBeFalse();
});
