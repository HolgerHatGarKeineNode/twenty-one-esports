<?php

use App\Enums\SeriesStatus;
use App\Models\Admin;
use App\Models\NostrEvent;
use App\Models\RelayDelivery;
use App\Models\SeriesMatch;
use App\Models\Tournament;
use App\Models\TournamentOrganizer;
use App\Models\User;
use App\Support\Admin\AdminStatus;
use App\Support\Tournaments\TournamentScheduler;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| The admin area (P17): one frame, one map, a real status page
|--------------------------------------------------------------------------
|
| Every page under admin/ renders the admin nav with all its groups and the
| breadcrumbs (the user's finding: /admin/admins had lost its tabs). The
| status page reads and never writes, and shows whether a key is set,
| never any part of it.
|
*/

function adminAreaAdmin(): User
{
    $admin = User::factory()->create(['name' => 'satsjaeger']);
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    return $admin;
}

test('every admin page renders the admin nav with every group and the breadcrumbs', function () {
    $admin = adminAreaAdmin();
    $match = SeriesMatch::factory()->create(['status' => SeriesStatus::Disputed]);
    $tournament = Tournament::factory()->create();
    $bindings = ['match' => $match->getRouteKey(), 'tournament' => $tournament->id];
    // Files and actions, no pages.
    $skip = ['admin.disputes.evidence'];

    $checked = [];

    foreach (Route::getRoutes() as $route) {
        $name = (string) $route->getName();

        if (! str_starts_with($route->uri(), 'admin/') || ! in_array('GET', $route->methods(), true) || in_array($name, $skip, true)) {
            continue;
        }

        $url = route($name, array_intersect_key($bindings, array_flip($route->parameterNames())));

        $this->actingAs($admin)->get($url)->assertOk()
            ->assertSee('data-test="admin-nav"', false)
            ->assertSee('data-test="admin-crumbs"', false)
            ->assertSee('href="'.route('admin.status').'"', false)
            ->assertSee('href="'.route('admin.organizers').'"', false)
            ->assertSee('href="'.route('admin.payouts').'"', false)
            ->assertSeeInOrder([__('League'), __('Tournaments'), __('Players & roles'), __('System')]);

        $checked[] = $name;
    }

    // The loop must have met every admin page; a filter that matches nothing proves nothing.
    expect($checked)->toContain('admin.status', 'admin.admins', 'admin.organizers', 'admin.disputes', 'admin.disputes.show', 'admin.season', 'admin.trust',
        'admin.events', 'admin.payouts', 'admin.tournaments', 'admin.tournaments.create', 'admin.tournaments.edit')
        ->and(count($checked))->toBe(12);
});

test('the prize pool page of a tournament carries the admin frame for an admin', function () {
    $tournament = Tournament::factory()->create();

    $this->actingAs(adminAreaAdmin())->get(route('tournaments.pool', $tournament))->assertOk()
        ->assertSee('data-test="admin-nav"', false)
        ->assertSee('data-test="admin-crumbs"', false);
});

test('an organizer gets the breadcrumbs but no admin map and no admin-only link', function () {
    $organizer = User::factory()->create();
    TournamentOrganizer::query()->create(['pubkey' => $organizer->pubkey]);

    $this->actingAs($organizer)->get(route('admin.tournaments'))->assertOk()
        ->assertSee('data-test="admin-crumbs"', false)
        ->assertDontSee('data-test="admin-nav"', false)
        ->assertDontSee('href="'.route('admin.status').'"', false)
        ->assertDontSee('href="'.route('admin.organizers').'"', false);

    $this->actingAs($organizer)->get(route('admin.status'))->assertForbidden();
});

test('the status page says whether a key is set and never shows any part of it', function () {
    $secret = str_repeat('ab12', 16);
    $nwc = 'nostr+walletconnect://'.str_repeat('cd34', 16).'?relay=wss://relay.example&secret='.str_repeat('ef56', 16);
    config(['esports.league.nsec' => $secret, 'esports.wallet.nwc_uri' => $nwc, 'esports.trust.nsec' => null]);

    $response = $this->actingAs(adminAreaAdmin())->get(route('admin.status'))->assertOk()
        ->assertSee(__('League key'))
        ->assertSee(__('not set'));

    $items = collect(app(AdminStatus::class)->keys()['items'])->keyBy('label');
    expect($items[__('League key')]['ok'])->toBeTrue()
        ->and($items[__('League wallet (NWC)')]['ok'])->toBeTrue()
        ->and($items[__('Trust key')]['ok'])->toBeFalse();

    foreach ([$secret, substr($secret, 0, 12), substr($secret, -12), $nwc, str_repeat('cd34', 4), str_repeat('ef56', 4), 'relay.example'] as $fragment) {
        $response->assertDontSee($fragment, false);
    }

    // The control: the same assertion sees a value that is on the page.
    $response->assertSee(__('Keys'));
});

test('the status page only reads', function () {
    $admin = adminAreaAdmin();
    SeriesMatch::factory()->create(['status' => SeriesStatus::Disputed]);
    $writes = [];
    DB::listen(function ($query) use (&$writes): void {
        if (preg_match('/^\s*(insert|update|delete|replace|create|drop|alter)\b/i', $query->sql) === 1) {
            $writes[] = $query->sql;
        }
    });

    // The first request of a session stamps the membership check; that is the login's, not the page's.
    $this->actingAs($admin)->get(route('admin.status'))->assertOk();
    $writes = [];

    $this->actingAs($admin)->get(route('admin.status'))->assertOk();
    Livewire::actingAs($admin)->test('pages::admin.status')->call('$refresh')->assertOk();

    expect($writes)->toBe([]);

    // Control: the listener does see a write.
    Admin::query()->create(['pubkey' => str_repeat('9', 64)]);
    expect($writes)->not->toBe([]);
});

test('the status checks turn on what they watch', function () {
    $status = app(AdminStatus::class);

    // Nothing open, clock ticking: disputes and scheduler are fine.
    Cache::forever(TournamentScheduler::HEARTBEAT, now()->getTimestamp());
    expect($status->disputes()['state'])->toBe('ok')
        ->and($status->scheduler()['state'])->toBe('ok');

    // An open case needs an admin, one older than 48 h is overdue.
    $case = SeriesMatch::factory()->create(['status' => SeriesStatus::Disputed]);
    expect($status->disputes()['state'])->toBe('attention');
    SeriesMatch::query()->whereKey($case->id)->update(['updated_at' => now()->subHours(49)]);
    expect($status->disputes()['state'])->toBe('down');

    // A heartbeat older than the limit stops the clock.
    Cache::forever(TournamentScheduler::HEARTBEAT, now()->subHour()->getTimestamp());
    expect($status->scheduler()['state'])->toBe('down');

    // Relays: nothing sent is fine; a league relay that refused every event in 24 h needs a look.
    config(['esports.relays' => ['wss://good.example', 'wss://bad.example'], 'twentyone.stream.relays' => []]);
    Cache::forget(AdminStatus::RELAYS_CACHE_KEY);
    expect($status->relays()['state'])->toBe('ok');

    $event = NostrEvent::query()->create(['event_id' => str_repeat('e', 64), 'pubkey' => str_repeat('f', 64), 'kind' => 1, 'signed_at' => now()->getTimestamp(), 'raw' => '{}']);
    RelayDelivery::query()->create(['nostr_event_id' => $event->id, 'relay' => 'wss://good.example', 'accepted' => true, 'attempted_at' => now()]);
    RelayDelivery::query()->create(['nostr_event_id' => $event->id, 'relay' => 'wss://bad.example', 'accepted' => false, 'attempted_at' => now()]);
    Cache::forget(AdminStatus::RELAYS_CACHE_KEY);
    $relays = $status->relays();
    expect($relays['state'])->toBe('attention')
        ->and($relays['detail'])->toContain('bad.example')
        ->and($relays['value'])->toBe(__(':reached of :count reached', ['reached' => 1, 'count' => 2]));

    // No relay accepted anything: down.
    RelayDelivery::query()->where('relay', 'wss://good.example')->update(['accepted' => false]);
    Cache::forget(AdminStatus::RELAYS_CACHE_KEY);
    expect($status->relays()['state'])->toBe('down');
});

test('the log error count takes ERROR and worse of the last 24 hours only', function () {
    $directory = storage_path('framework/testing/admin-status-logs-'.getmypid());
    @mkdir($directory, 0755, true);
    $now = now();
    file_put_contents($directory.'/laravel.log', implode("\n", [
        '['.$now->copy()->subHours(30)->format('Y-m-d H:i:s').'] testing.ERROR: too old',
        '['.$now->copy()->subHours(2)->format('Y-m-d H:i:s').'] testing.ERROR: counted {"exception":"x"}',
        '#0 stack line [2026-01-01 00:00:00] testing.ERROR: not a log line start',
        '['.$now->copy()->subHour()->format('Y-m-d H:i:s').'] testing.WARNING: not an error',
        '['.$now->copy()->subMinutes(5)->format('Y-m-d H:i:s').'] production.CRITICAL: counted as critical',
        '['.$now->copy()->subMinutes(4)->format('Y-m-d\TH:i:s.uP').'] local.ERROR: ISO stamp counted',
    ])."\n");

    try {
        expect(AdminStatus::countLogErrors($directory))->toBe(['errors' => 2, 'critical' => 1, 'truncated' => false, 'files' => 1]);
    } finally {
        @unlink($directory.'/laravel.log');
        @rmdir($directory);
    }
});
