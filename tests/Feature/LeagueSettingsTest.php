<?php

/*
 * League settings (P44): one allow-list of operational values, an
 * append-only log of overrides and one grouped admin form. The config stays
 * the default; the values in force are read per request and per queue job.
 */

use App\Models\Admin;
use App\Models\LeagueSettingChange;
use App\Models\User;
use App\Support\FairPlay\FairPlay;
use App\Support\Nostr\NostrKeys;
use App\Support\Series\CasualMatches;
use App\Support\Settings\LeagueSettingRefused;
use App\Support\Settings\LeagueSettings;
use App\Support\Tournaments\CasualCups;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->create(['name' => 'satsjaeger']);
    Admin::query()->create(['pubkey' => $this->admin->pubkey]);
    config(['esports.board' => [NostrKeys::hexToNpub($this->admin->pubkey)]]);
});

/** The refusal messages of a save, by key; [] when it saved. */
function leagueSettingsRefusal(User $admin, array $values): array
{
    try {
        LeagueSettings::save($admin, $values);
    } catch (LeagueSettingRefused $refused) {
        return $refused->errors;
    }

    return [];
}

test('every listed key has a default in the config that passes its own definition', function () {
    foreach (LeagueSettings::definitions() as $key => $definition) {
        // A game's automatic cups (user 2026-10-03) have no config path: their default is the env list's.
        $default = LeagueSettings::default($key);

        expect($default)->not->toBeNull($key)
            ->and(LeagueSettings::normalize($definition, is_array($default) ? implode(', ', $default) : (string) $default))->toBe($default, $key)
            ->and(LeagueSettings::groups())->toHaveKey($definition['group']);
    }
});

test('a key that is not on the list is refused: chain rules, rating values, NIP constants, secrets and unknown keys', function (string $key, mixed $value) {
    expect(leagueSettingsRefusal($this->admin, [$key => $value]))->toBe([$key => 'This setting cannot be changed here.'])
        ->and(LeagueSettingChange::query()->count())->toBe(0)
        ->and(LeagueSettings::definitions())->not->toHaveKey($key);
})->with([
    'chain supply' => ['season.chain.supply', '1000'],
    'chain shares' => ['season.chain.shares.chess', '50'],
    'rating k' => ['season.rating.k', '24'],
    'NIP global rating weight' => ['season.global_rating_min_weight', '3'],
    'NIP clan rating top' => ['season.clan_rating_top', '5'],
    'league key' => ['esports.league.nsec', 'nsec1abc'],
    'NWC wallet' => ['esports.wallet.nwc_uri', 'nostr+walletconnect://x'],
    'relays' => ['esports.relays', 'wss://evil.example'],
    'unknown' => ['esports.fair_play.bogus', '1'],
]);

test('no listed key lies under the chain, the rating values, the NIP constants or a secret', function () {
    foreach (array_keys(LeagueSettings::definitions()) as $key) {
        expect($key)->not->toStartWith('season.chain')
            ->not->toStartWith('season.rating')
            ->not->toStartWith('season.tiers')
            ->not->toStartWith('season.hashrate')
            ->not->toContain('global_rating_min_weight')
            ->not->toContain('clan_rating_top')
            ->not->toMatch('/nsec|nwc|relays|secret|token|key$/');
    }
});

test('a value of the wrong type or out of range is refused, and nothing of the batch is saved', function (string $key, mixed $value, string $message) {
    $errors = leagueSettingsRefusal($this->admin, ['esports.fair_play.lock_days' => '10', $key => $value]);

    expect($errors)->toBe([$key => $message])
        ->and(LeagueSettingChange::query()->count())->toBe(0)
        ->and(FairPlay::lockDays())->toBe(7);
})->with([
    'below min' => ['esports.fair_play.false_reports', '0', 'A whole number from 1 to 10.'],
    'above max' => ['esports.casual.ready_seconds', '601', 'A whole number from 15 to 600.'],
    'not a number' => ['esports.casual.lobby_minutes', 'five', 'A whole number from 1 to 60.'],
    'a fraction' => ['esports.casual.join_minutes', '2.5', 'A whole number from 1 to 60.'],
    'an array for an int' => ['esports.casual.join_minutes', [5], 'A whole number from 1 to 60.'],
    'hour 24' => ['esports.casual_cups.games.chess.slot.time', '24:00', 'A time as HH:MM, from 00:00 to 23:59.'],
    'no weekday' => ['esports.casual_cups.games.rocket-league.slot.weekday', 'someday', 'A day of the week.'],
    'size too small' => ['esports.casual_cups.sizes', '2, 8', '1 to 5 whole numbers from 4 to 64, separated by commas.'],
    'size twice' => ['esports.casual_cups.sizes', '4, 4, 8', '1 to 5 whole numbers from 4 to 64, separated by commas.'],
    'too many slots' => ['esports.stream_bot.free_places.cup_slots_hours', '1, 2, 3, 4, 5, 6, 7', '1 to 6 whole numbers from 1 to 720, separated by commas.'],
]);

test('each change is one log row with who, before and after; an unchanged value writes none; the default comes back as null', function () {
    $saved = LeagueSettings::save($this->admin, [
        'esports.fair_play.false_reports' => ' 3 ',
        'esports.casual_cups.sizes' => '4, 8, 16, 32',
        'esports.casual_cups.games.chess.slot.time' => '19:30',
        // Unchanged: no row.
        'esports.casual.lock.minutes' => '30',
    ]);

    expect(collect($saved)->map->only(['key', 'before', 'after', 'changed_by_id', 'changed_by_pubkey'])->all())->toBe([
        ['key' => 'esports.fair_play.false_reports', 'before' => 2, 'after' => 3, 'changed_by_id' => $this->admin->id, 'changed_by_pubkey' => $this->admin->pubkey],
        ['key' => 'esports.casual_cups.sizes', 'before' => [4, 8, 16], 'after' => [4, 8, 16, 32], 'changed_by_id' => $this->admin->id, 'changed_by_pubkey' => $this->admin->pubkey],
        ['key' => 'esports.casual_cups.games.chess.slot.time', 'before' => '20:00', 'after' => '19:30', 'changed_by_id' => $this->admin->id, 'changed_by_pubkey' => $this->admin->pubkey],
    ])->and(LeagueSettings::save($this->admin, ['esports.fair_play.false_reports' => '3']))->toBe([]);

    $reset = LeagueSettings::save($this->admin, ['esports.fair_play.false_reports' => null]);

    expect($reset)->toHaveCount(1)
        ->and($reset[0]->only(['before', 'after']))->toBe(['before' => 3, 'after' => null])
        ->and(FairPlay::threshold())->toBe(2)
        // Back at the default already: a second reset writes nothing.
        ->and(LeagueSettings::save($this->admin, ['esports.fair_play.false_reports' => null]))->toBe([])
        ->and(LeagueSettingChange::query()->count())->toBe(4)
        ->and(LeagueSettings::get('esports.casual_cups.sizes'))->toBe([4, 8, 16, 32]);
});

test('the log is append-only: a row is never changed or deleted', function () {
    [$row] = LeagueSettings::save($this->admin, ['esports.fair_play.lock_days' => '10']);

    expect(fn () => $row->update(['after' => 20]))->toThrow(LogicException::class, 'append-only')
        ->and(fn () => $row->delete())->toThrow(LogicException::class, 'append-only')
        ->and($row->refresh()->after)->toBe(10);
});

test('the readers use the value in force: fair play, the casual 1v1 lock and pins, a cup slot, the cup sizes', function () {
    LeagueSettings::save($this->admin, [
        'esports.fair_play.false_reports' => '4',
        'esports.fair_play.window_days' => '60',
        'esports.fair_play.lock_days' => '14',
        'esports.casual.ready_seconds' => '90',
        'esports.casual_cups.games.chess.slot.weekday' => 'friday',
        'esports.casual_cups.games.chess.slot.time' => '19:30',
        'esports.casual_cups.min_signup_hours' => '24',
        'esports.casual_cups.sizes' => '8, 16',
    ]);

    // Monday 2026-10-05 12:00 UTC + 24 h: the next Friday 19:30 in Berlin (CEST, UTC+2) and in New York (EDT, UTC-4).
    expect([FairPlay::threshold(), FairPlay::windowDays(), FairPlay::lockDays()])->toBe([4, 60, 14])
        ->and(CasualMatches::pinned()['ready_seconds'])->toBe(90)
        ->and(CasualCups::startFor('chess', 'eu', CarbonImmutable::parse('2026-10-05 12:00', 'UTC'))->toIso8601String())->toBe('2026-10-09T17:30:00+00:00')
        ->and(CasualCups::startFor('chess', 'us', CarbonImmutable::parse('2026-10-05 12:00', 'UTC'))->toIso8601String())->toBe('2026-10-09T23:30:00+00:00')
        ->and(CasualCups::slotOf('checkers'))->toBe(['weekday' => 'sunday', 'hour' => 15, 'minute' => 0])
        ->and(CasualCups::sizes())->toBe([8, 16]);
});

test('a casual 1v1 keeps the deadline pinned at its pairing when the setting changes later', function () {
    [$match] = casualPairing();

    LeagueSettings::save($this->admin, ['esports.casual.ready_seconds' => '120']);
    [$later] = casualPairing();

    expect($match->refresh()->casualSetting('ready_seconds'))->toBe(60)
        ->and($later->casualSetting('ready_seconds'))->toBe(120);
});

test('the values in force are read once per request and forgotten before every queue job', function () {
    expect(FairPlay::lockDays())->toBe(7);

    // Written by another process: a raw row, so no model event of this one forgets the memo.
    DB::table('league_setting_changes')->insert(['key' => 'esports.fair_play.lock_days', 'before' => '7', 'after' => '21', 'changed_by_id' => $this->admin->id, 'changed_by_pubkey' => $this->admin->pubkey, 'created_at' => now(), 'updated_at' => now()]);

    expect(FairPlay::lockDays())->toBe(7);

    event(new JobProcessing('database', Mockery::mock(Job::class)->shouldIgnoreMissing()));

    expect(FairPlay::lockDays())->toBe(21)
        ->and(config('esports.fair_play.lock_days'))->toBe(7);
});

test('a stored value that no longer passes its definition, or a key no longer on the list, falls back to the default', function () {
    DB::table('league_setting_changes')->insert([
        ['key' => 'esports.fair_play.lock_days', 'before' => '7', 'after' => '9999', 'changed_by_id' => $this->admin->id, 'changed_by_pubkey' => $this->admin->pubkey, 'created_at' => now(), 'updated_at' => now()],
        ['key' => 'season.chain.supply', 'before' => '2100000', 'after' => '1', 'changed_by_id' => $this->admin->id, 'changed_by_pubkey' => $this->admin->pubkey, 'created_at' => now(), 'updated_at' => now()],
    ]);
    LeagueSettings::forget();

    expect(FairPlay::lockDays())->toBe(7)
        ->and(LeagueSettings::get('season.chain.supply'))->toBe(2_100_000)
        ->and(LeagueSettings::overrides())->toBe([]);
});

test('/rules states the values in force after an admin change', function () {
    config(['esports.casual_cups.enabled' => ['chess']]);
    LeagueSettings::save($this->admin, [
        'esports.fair_play.false_reports' => '3',
        'esports.fair_play.window_days' => '45',
        'esports.fair_play.lock_days' => '10',
        'esports.casual.lock.noshows' => '4',
        'esports.casual.lock.window_hours' => '12',
        'esports.casual.lock.minutes' => '90',
        'esports.casual.ready_seconds' => '75',
        'esports.casual_cups.sizes' => '4, 8, 16, 32',
        'esports.casual_cups.games.chess.slot.time' => '19:30',
        'esports.casual_cups.evening.start' => '21:15',
    ]);

    $this->get(route('rules'))->assertOk()
        ->assertSee('3 confirmed false reports within 45 days mean no rated play for 10 days from the last one.')
        ->assertSee('4 forfeited no-shows within 12 hours pause casual 1v1 for 90 minutes.')
        ->assertSee('75 seconds')
        ->assertSee('4 → 8 → 16 → 32')
        ->assertSee('Saturday 19:30')
        ->assertSee('at 21:15 in the cup’s region', false);
});

test('the page lists every setting grouped, with its default, and no excluded key', function () {
    $html = $this->actingAs($this->admin)->get(route('admin.settings'))->assertOk()
        ->assertSee('League settings')
        ->assertSee('data-test="settings-group-fair_play"', false)
        ->assertSee('data-test="settings-group-casual_cups"', false)
        ->assertSee('Default: 2')
        ->assertSee('No change yet: the configuration holds every value.')
        ->getContent();

    foreach (array_keys(LeagueSettings::definitions()) as $key) {
        expect($html)->toContain('data-test="setting-'.str_replace('.', '-', $key).'"');
    }

    expect($html)->not->toContain('season-chain')->not->toContain('global_rating_min_weight')->not->toContain('nsec');
});

test('an admin saves the form; a refused field shows its message; "Use the default" goes back to the config; the log lists who changed what', function () {
    $page = Livewire::actingAs($this->admin)->test('pages::admin.settings')
        ->assertSet('form.esports-casual-lock-minutes', '30')
        ->set('form.esports-casual-lock-minutes', '45')
        ->set('form.esports-casual_cups-min_signup_hours', '999')
        ->call('save')
        ->assertHasErrors(['form.esports-casual_cups-min_signup_hours'])
        ->assertSee('A whole number from 1 to 336.');

    expect(LeagueSettingChange::query()->count())->toBe(0);

    $page->set('form.esports-casual_cups-min_signup_hours', '36')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('notice', 'Saved: 2 values changed.')
        ->assertSee('data-test="setting-use-default"', false)
        ->assertSee('Sign-up at least (hours)')
        ->assertSee('48 → 36');

    expect(LeagueSettings::get('esports.casual.lock.minutes'))->toBe(45);

    $page->call('useDefault', 'esports.casual.lock.minutes')
        ->assertSet('form.esports-casual-lock-minutes', '30')
        ->assertSee('45 → default (30)');

    expect(LeagueSettings::get('esports.casual.lock.minutes'))->toBe(30)
        ->and(LeagueSettingChange::query()->count())->toBe(3);
});

test('a player who is not an admin can neither open the page nor save; an admin who lost the role is refused at the action', function () {
    $player = User::factory()->create();
    config(['esports.board' => []]);

    $this->actingAs($player)->get(route('admin.settings'))->assertForbidden();
    auth()->logout();
    $this->get(route('admin.settings'))->assertRedirect();

    $page = Livewire::actingAs($this->admin)->test('pages::admin.settings');
    Admin::query()->where('pubkey', $this->admin->pubkey)->delete();

    $page->set('form.esports-casual-lock-minutes', '45')->call('save')->assertForbidden();

    expect(LeagueSettingChange::query()->count())->toBe(0);
});

test('each cup game has an "Automatic cups" switch; the env list is its default, the switch overrides it either way', function () {
    config(['esports.casual_cups.enabled' => ['chess', 'checkers']]);

    expect(LeagueSettings::get('esports.casual_cups.games.chess.auto'))->toBe('on')
        ->and(LeagueSettings::get('esports.casual_cups.games.rocket-league.auto'))->toBe('off')
        ->and(CasualCups::enabledGames())->toBe(['chess']);

    LeagueSettings::save($this->admin, ['esports.casual_cups.games.chess.auto' => 'off', 'esports.casual_cups.games.rocket-league.auto' => 'on']);

    expect(CasualCups::enabledGames())->toBe(['rocket-league'])
        ->and(leagueSettingsRefusal($this->admin, ['esports.casual_cups.games.chess.auto' => 'maybe']))->toBe(['esports.casual_cups.games.chess.auto' => 'On or off.']);
});

test('only an admin switches a game\'s automatic cups: a player is refused at the page, an admin who lost the role at the action', function () {
    $field = 'form.esports-casual_cups-games-checkers-auto';
    // An admin by the admin list only, not by the board: removing the row takes the role.
    config(['esports.casual_cups.enabled' => ['checkers'], 'esports.board' => []]);

    $this->actingAs(User::factory()->create())->get(route('admin.settings'))->assertForbidden();

    $page = Livewire::actingAs($this->admin)->test('pages::admin.settings')
        ->assertSet($field, 'on')
        ->assertSee('Automatic cups: Checkers')
        ->set($field, 'off')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Automatic cups: Checkers</b>:', false)
        ->assertSee('On → Off');

    expect(LeagueSettings::get('esports.casual_cups.games.checkers.auto'))->toBe('off');

    Admin::query()->where('pubkey', $this->admin->pubkey)->delete();
    $page->set($field, 'on')->call('save')->assertForbidden();

    expect(LeagueSettings::get('esports.casual_cups.games.checkers.auto'))->toBe('off')
        ->and(LeagueSettingChange::query()->count())->toBe(1);
});

test('the data migration switches the automatic cups of Nine Men\'s Morris and Checkers off, once, and keeps an admin\'s choice', function () {
    $migration = require database_path('migrations/2026_10_02_213535_switch_off_automatic_board_game_cups.php');
    config(['esports.casual_cups.enabled' => ['chess', 'nine-mens-morris', 'checkers']]);

    // The test database was migrated without a user: a fresh install keeps the env list.
    expect(LeagueSettingChange::query()->count())->toBe(0);

    $migration->up();
    $migration->up();

    expect(LeagueSettingChange::query()->orderBy('id')->get(['key', 'before', 'after'])->map(fn (LeagueSettingChange $row): array => [$row->key, $row->before, $row->after])->all())->toBe([
        ['esports.casual_cups.games.nine-mens-morris.auto', 'on', 'off'],
        ['esports.casual_cups.games.checkers.auto', 'on', 'off'],
    ])
        ->and(LeagueSettings::get('esports.casual_cups.games.nine-mens-morris.auto'))->toBe('off')
        ->and(LeagueSettings::get('esports.casual_cups.games.checkers.auto'))->toBe('off')
        ->and(LeagueSettings::get('esports.casual_cups.games.chess.auto'))->toBe('on');

    // An admin switched one back on before a rerun: it stays on.
    LeagueSettings::save($this->admin, ['esports.casual_cups.games.checkers.auto' => 'on']);
    $migration->up();

    expect(LeagueSettings::get('esports.casual_cups.games.checkers.auto'))->toBe('on');
});

test('a board-only value: an admin off the board sees it read-only and is refused; a board member changes it', function () {
    $other = User::factory()->create();
    Admin::query()->create(['pubkey' => $other->pubkey]);

    Livewire::actingAs($other)->test('pages::admin.settings')
        ->assertSeeHtml('id="setting-esports-fair_play-false_reports" type="text" inputmode="numeric" wire:model="form.esports-fair_play-false_reports" class="h-11 w-full rounded-md border border-edge bg-ground px-3 text-[13px] text-ink disabled:opacity-60" disabled')
        // An unchanged board-only value in the same form does not block the admin's own change.
        ->set('form.esports-casual-lock-minutes', '40')
        ->call('save')
        ->assertHasNoErrors()
        ->set('form.esports-fair_play-false_reports', '5')
        ->call('save')
        ->assertHasErrors(['form.esports-fair_play-false_reports']);

    expect(leagueSettingsRefusal($other, ['esports.fair_play.false_reports' => '5']))->toBe(['esports.fair_play.false_reports' => 'Only a board member on the public admin list can change this value.'])
        ->and(FairPlay::threshold())->toBe(2)
        ->and(leagueSettingsRefusal($this->admin, ['esports.fair_play.false_reports' => '5']))->toBe([])
        ->and(FairPlay::threshold())->toBe(5);
});

test('a cold lookup is one query, with no process state to warm it', function () {
    LeagueSettings::save($this->admin, ['esports.fair_play.lock_days' => '10']);
    LeagueSettings::forget();
    DB::flushQueryLog();
    DB::enableQueryLog();

    $overrides = LeagueSettings::overrides();
    LeagueSettings::overrides();
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($overrides)->toBe(['esports.fair_play.lock_days' => 10])
        ->and($queries)->toHaveCount(1)
        ->and((new ReflectionClass(LeagueSettings::class))->getStaticProperties())->toBe([]);
});

test('without the log table the defaults apply, and the surrounding transaction stays usable', function () {
    Schema::drop('league_setting_changes');
    LeagueSettings::forget();

    expect(LeagueSettings::overrides())->toBe([])
        ->and(FairPlay::lockDays())->toBe(7)
        ->and(CasualCups::sizes())->toBe([4, 8, 16])
        ->and(DB::transactionLevel())->toBeGreaterThan(0)
        ->and(User::query()->whereKey($this->admin->id)->exists())->toBeTrue();
});
