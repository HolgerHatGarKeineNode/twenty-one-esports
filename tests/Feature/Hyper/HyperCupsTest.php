<?php

use App\Enums\TournamentFormat;
use App\Enums\TournamentStatus;
use App\Games\Hyperbitcoinization;
use App\Models\Admin;
use App\Models\HyperMatch;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Hyper\HyperCups;
use App\Support\LeagueTime;
use App\Support\Settings\LeagueSettings;
use App\Support\Tournaments\TournamentScheduler;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\HyperOn;
use Tests\Support\TestSigner;

/*
| Hyperbitcoinization's weekend cup (plan "Hyperbitcoinization", P5): the tournament clock opens one at a time behind
| the game's automatic-cups toggle on /admin/settings (P5c, off by default) and the league key; its tables are unrated and bots fill them up to the table size; the final's winner
| wears the cup badge on the ladder and the profile.
*/

beforeEach(function () {
    $this->withoutVite();
    HyperOn::play();
});

test('switched on with the game\'s automatic-cups toggle on /admin/settings (off by default) and the league key set, the tournament clock opens one weekend cup at a time; off, none', function () {
    Queue::fake();
    config(['esports.league.nsec' => (new TestSigner)->secret]);
    $this->travelTo(CarbonImmutable::parse('2026-10-07 12:00', LeagueTime::zone()));
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    // Off by default: no env switch of its own any more.
    expect(config('esports.hyper.cups'))->not->toHaveKey('enabled')
        ->and(LeagueSettings::get(HyperCups::SETTING))->toBe('off')
        ->and(HyperCups::enabled())->toBeFalse();

    app(TournamentScheduler::class)->tick();
    expect(Tournament::query()->count())->toBe(0);

    Livewire::actingAs($admin)->test('pages::admin.settings')
        ->assertSee('Automatic cups: Hyperbitcoinization')
        ->assertSet('form.esports-hyper-cups-auto', 'off')
        ->set('form.esports-hyper-cups-auto', 'on')
        ->call('save')
        ->assertHasNoErrors();

    expect(HyperCups::enabled())->toBeTrue();
    app(TournamentScheduler::class)->tick();
    app(TournamentScheduler::class)->tick();
    $cup = Tournament::query()->sole();

    expect($cup->name)->toBe('Hyperbitcoinization Weekend Cup #1')
        ->and(HyperCups::isCup($cup))->toBeTrue()
        ->and($cup->game)->toBe(Hyperbitcoinization::SLUG)
        ->and($cup->format)->toBe(TournamentFormat::FreeForAll)
        ->and($cup->status)->toBe(TournamentStatus::Signup)
        ->and($cup->event_id)->not->toBeNull()
        ->and($cup->formatOptions()->heatSize)->toBe(4)
        ->and($cup->formatOptions()->heatAdvance)->toBe(1)
        // Sign-up until Saturday 18:00 in the league's zone, when it starts.
        ->and($cup->signup_closes_at->setTimezone(LeagueTime::zone())->format('D Y-m-d H:i'))->toBe('Sat 2026-10-10 18:00')
        ->and($cup->starts_at->equalTo($cup->signup_closes_at))->toBeTrue();

    // The start page and the ladder link it.
    $this->get(route('hyper.index'))->assertOk()->assertSee('data-test="hyper-index-cup"', false);
    $this->get(route('hyper.ladder'))->assertOk()->assertSee('data-test="hyper-ladder-cup"', false);
});

test('a cup table is never rated, bots fill it up to the table size, and the final\'s winner wears the cup badge', function () {
    openSeason(ladders: false);
    $cup = HyperOn::tournament(2, TournamentFormat::FreeForAll, ['heatSize' => 4, 'heatAdvance' => 1, HyperCups::OPTION => true]);
    $table = HyperMatch::query()->with('seats.user')->sole();

    expect($table->rated)->toBeFalse()
        ->and($table->season)->toBeNull()
        ->and($table->seats->pluck('bot')->all())->toBe([false, false, true, true]);

    [$runnerUp, $winner] = [$table->seats[0]->user, $table->seats[1]->user];
    HyperOn::finishTable($table, [1, 0, 2, 3]);

    expect($cup->refresh()->status)->toBe(TournamentStatus::Finished)
        ->and(HyperCups::winsOf($winner))->toBe(1)
        ->and(HyperCups::winsOf($runnerUp))->toBe(0);

    $this->get(route('players.show', $winner->npub))->assertOk()->assertSee('data-test="player-hyper-cups"', false);
    $this->get(route('players.show', $runnerUp->npub))->assertOk()->assertDontSee('data-test="player-hyper-cups"', false);
});

test('the next start is the coming Saturday at least a day away, in the league\'s zone', function () {
    $at = fn (string $local): string => HyperCups::nextStart(CarbonImmutable::parse($local, LeagueTime::zone()))->setTimezone(LeagueTime::zone())->format('Y-m-d H:i');

    expect($at('2026-10-07 12:00'))->toBe('2026-10-10 18:00')
        ->and($at('2026-10-09 19:00'))->toBe('2026-10-17 18:00')
        ->and($at('2026-10-10 17:00'))->toBe('2026-10-17 18:00')
        ->and($at('2026-10-10 19:00'))->toBe('2026-10-17 18:00');
});
